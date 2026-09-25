<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
[$sc, $sp] = scope_dept('a');

$type = get('type');
$du = parse_date(get('du'));
$au = parse_date(get('au'));
$periode = '';
$pp = [];
$exports = [
    'assures' => ['assures', "SELECT a.reference Référence, a.police Police, a.prenom Prénom, a.nom Nom, a.sexe Sexe, a.naissance Naissance, a.nif NIF,
            a.telephone Téléphone, a.departement Département, a.commune Commune, a.adresse Adresse, pl.nom Plan, pl.prime Prime, a.devise Devise,
            a.type_police Type, a.adhesion Adhésion, a.statut Statut
        FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id WHERE 1 $sc ORDER BY a.nom, a.prenom"],
    'polices' => ['polices', "SELECT a.police Police, CONCAT(a.prenom, ' ', a.nom) Souscripteur, f.nom Formule, pl.nom Plan, pl.prime Prime, pl.capital Capital,
            a.devise Devise, a.adhesion Adhésion, a.statut Statut,
            (SELECT MAX(DATE_ADD(p.date_paiement, INTERVAL p.mois MONTH)) FROM paiements p WHERE p.assure_id = a.id AND p.statut = 'Encaissé' AND p.objet = 'Cotisation') `Couvert jusqu'au`
        FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id LEFT JOIN formules f ON f.id = a.formule_id WHERE 1 $sc ORDER BY a.police"],
    'beneficiaires' => ['assures', "SELECT a.police Police, CONCAT(a.prenom, ' ', a.nom) Assuré, b.nom Bénéficiaire, b.lien Lien, b.naissance Naissance, b.part `Part (%)`, b.telephone Téléphone
        FROM beneficiaires b JOIN assures a ON a.id = b.assure_id WHERE 1 $sc ORDER BY a.police"],
    'paiements' => ['paiements', "SELECT p.reference Reçu, p.date_paiement Date, a.police Police, CONCAT(a.prenom, ' ', a.nom) Assuré, p.objet Objet, p.mois Mois,
            p.montant Montant, p.devise Devise, p.mode Mode, p.statut Statut, us.nom Agent
        FROM paiements p JOIN assures a ON a.id = p.assure_id LEFT JOIN users us ON us.id = p.created_by WHERE 1 $sc %PERIODE% ORDER BY p.date_paiement, p.id"],
    'reclamations' => ['reclamations', "SELECT r.reference Dossier, a.police Police, CONCAT(a.prenom, ' ', a.nom) Défunt, r.date_deces Décès, r.declarant_nom Déclarant,
            r.capital Capital, r.arrieres Arriérés, r.frais_dossier Frais, GREATEST(r.capital - r.arrieres - r.frais_dossier, 0) Net, r.statut Statut
        FROM reclamations r JOIN assures a ON a.id = r.assure_id WHERE 1 $sc ORDER BY r.id"],
    'audit' => ['parametres', "SELECT au.created_at Date, au.user_nom Utilisateur, au.zone Zone, au.type Action, au.cible Cible, au.detail Détail, au.ip IP
        FROM audit au WHERE 1 %PERIODE% ORDER BY au.id"],
];
if (!isset($exports[$type])) { http_response_code(404); exit('Export inconnu.'); }
[$perm, $sql] = $exports[$type];
if (!can($perm)) { http_response_code(403); exit('Accès non autorisé.'); }

if ($du && $au && !get('tout')) {
    $col = $type === 'audit' ? 'au.created_at' : 'p.date_paiement';
    $periode = " AND $col >= ? AND $col < DATE_ADD(?, INTERVAL 1 DAY)";
    $pp = [$du, $au];
}
$sql = str_replace('%PERIODE%', $periode, $sql);
// Les paramètres du territoire précèdent ceux de la période dans la requête
$params = $type === 'audit' ? $pp : array_merge($sp, $pp);
$lignes = rows($sql, $params);

$format = get('format', 'pdf') === 'csv' ? 'csv' : 'pdf';
audit('Export', strtoupper($type), count($lignes) . ' ligne(s) exportée(s) · ' . strtoupper($format));

if ($format === 'pdf') {
    require_once __DIR__ . '/includes/pdf_export.php';
    $titres = ['assures' => 'Registre des assurés', 'polices' => 'Portefeuille de polices', 'beneficiaires' => 'Bénéficiaires',
               'paiements' => 'Journal des paiements', 'reclamations' => 'Dossiers de réclamation', 'audit' => 'Journal d’audit'];
    $dates = ['Naissance', 'Adhésion', 'Décès', "Couvert jusqu'au"];
    $montants = ['Prime', 'Capital', 'Montant', 'Arriérés', 'Frais', 'Net'];
    $nombres = ['Mois', 'Part (%)'];
    // Colonnes secondaires retirées du PDF pour garder un tableau lisible sur une page A4 (elles restent dans l'export CSV)
    $masquer = ['assures' => ['Référence', 'Sexe', 'NIF', 'Adresse', 'Devise'], 'polices' => ['Devise'], 'paiements' => ['Devise'], 'audit' => ['Zone']];

    $colonnes = $lignes ? array_values(array_diff(array_keys($lignes[0]), $masquer[$type] ?? [])) : [];
    $totaux = [];
    $sommes = array_fill_keys(array_intersect($colonnes, $montants), 0.0);
    $valeurs = [];
    foreach ($lignes as $l) {
        $row = [];
        foreach ($colonnes as $c) {
            $v = $l[$c];
            // Paiements annulés ou à valider : listés, mais hors du total
            if (isset($sommes[$c]) && !($type === 'paiements' && $l['Statut'] !== 'Encaissé')) $sommes[$c] += (float)$v;
            if ($v === null || $v === '') $v = '—';
            elseif (in_array($c, $dates, true)) $v = fdate($v);
            elseif ($c === 'Date') $v = strlen((string)$v) > 10 ? date('d/m/Y H:i', strtotime($v)) : fdate($v);
            elseif (in_array($c, $montants, true)) $v = money($v);
            elseif ($c === 'Part (%)') $v = rtrim(rtrim((string)$v, '0'), '.') . ' %';
            elseif ($c === 'Sexe') $v = $v === 'F' ? 'F' : 'M';
            elseif ($c === 'Type') $v = $v === 'famille' ? 'Familial' : 'Individuel';
            $row[] = (string)$v;
        }
        $valeurs[] = $row;
    }
    foreach ($sommes as $c => $s) $totaux[$c] = money($s);

    $pdf = new CcadPdf('L', 'mm', 'A4');
    $pdf->SetTitle(CcadPdf::t($titres[$type] . ' · CCAD'));
    $pdf->SetAuthor(CcadPdf::t(current_user()['nom']));
    $pdf->SetAutoPageBreak(false);
    $pdf->titre = $titres[$type];
    $pdf->sousTitre = ($du && $au && !get('tout') ? 'Du ' . fdate($du) . ' au ' . fdate($au) . ' · ' : '')
        . count($lignes) . ' ligne(s)' . (in_array($type, ['assures', 'polices', 'paiements', 'reclamations'], true) ? ' · montants en ' . devise() : '') . ($type === 'paiements' ? ' · total : encaissés seulement' : '');
    $pdf->auteur = current_user()['nom'];
    $pdf->tableau($colonnes ?: ['Aucune donnée'], $valeurs, array_merge($montants, $nombres), $totaux);

    $nom = 'ccad-' . $type . '-' . date('Ymd-His') . '.pdf';
    header('Cache-Control: private, no-store');
    $pdf->Output('D', $nom);
    exit;
}

$nom = 'ccad-' . $type . '-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nom . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
if ($lignes) fputcsv($out, array_keys($lignes[0]), ';');
// Neutralise les formules (=, +, -, @) pour éviter l'injection dans Excel
$sur = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;
foreach ($lignes as $l) fputcsv($out, array_map($sur, $l), ';');
fclose($out);
