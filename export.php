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

audit('Export', strtoupper($type), count($lignes) . ' ligne(s) exportée(s)');

$nom = 'ccad-' . $type . '-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nom . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
if ($lignes) fputcsv($out, array_keys($lignes[0]), ';');
foreach ($lignes as $l) fputcsv($out, $l, ';');
fclose($out);
