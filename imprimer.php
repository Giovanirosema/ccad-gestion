<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
[$sc, $sp] = scope_dept('a');

$doc = get('doc');
$id = (int)get('id');
$format = get('format', setting('imp_format', 'a4')) === 'thermal' ? 'thermal' : 'a4';
$thermal = $format === 'thermal';
$devise = devise();

function charger_assure(int $id): array
{
    [$sc, $sp] = scope_dept('a');
    $st = db()->prepare("SELECT a.*, pl.nom plan_nom, pl.prime, pl.capital, f.nom formule_nom FROM assures a
        LEFT JOIN plans pl ON pl.id = a.plan_id LEFT JOIN formules f ON f.id = a.formule_id WHERE a.id = ? $sc");
    $st->execute(array_merge([$id], $sp));
    $a = $st->fetch();
    if (!$a) { http_response_code(404); exit('Document introuvable.'); }
    return $a;
}

// Contrôle des droits par type de document
$perm = ['inscription' => 'assures', 'paiement' => 'assures', 'recu' => 'paiements', 'reclamation' => 'reclamations', 'rapport' => 'rapports'][$doc] ?? null;
if (!$perm) { http_response_code(404); exit('Type de document inconnu.'); }
if (!can($perm)) { http_response_code(403); exit('Accès non autorisé.'); }

$titre = '';
$ref = '';
switch ($doc) {
    case 'inscription':
        $a = charger_assure($id);
        $benefs = rows('SELECT * FROM beneficiaires WHERE assure_id = ? ORDER BY part DESC', [$id]);
        $membres = rows('SELECT * FROM membres WHERE assure_id = ? ORDER BY id', [$id]);
        $pieces = json_decode($a['pieces'] ?: '[]', true) ?: [];
        $titre = 'Fiche d’inscription';
        $ref = $a['reference'];
        break;
    case 'paiement':
        $a = charger_assure($id);
        $annee = (int)get('annee', date('Y'));
        $pay = rows("SELECT * FROM paiements WHERE assure_id = ? AND statut = 'Encaissé' AND objet = 'Cotisation' ORDER BY date_paiement", [$id]);
        // Chaque reçu couvre « mois » mois consécutifs à partir du mois suivant la fin de couverture précédente
        $couverture = [];
        $curseur = new DateTime(date('Y-m-01', strtotime($a['adhesion'])));
        foreach ($pay as $p) {
            for ($i = 0; $i < (int)$p['mois']; $i++) {
                $couverture[$curseur->format('Y-m')] = $p;
                $curseur->modify('+1 month');
            }
        }
        $titre = 'Fiche de paiement ' . $annee;
        $ref = $a['police'];
        break;
    case 'recu':
        $st = db()->prepare("SELECT p.*, a.prenom, a.nom, a.police, a.reference aref, a.photo, a.telephone, a.id aid, a.adhesion, pl.nom plan_nom, pl.prime, us.nom agent
            FROM paiements p JOIN assures a ON a.id = p.assure_id LEFT JOIN plans pl ON pl.id = a.plan_id LEFT JOIN users us ON us.id = p.created_by
            WHERE p.id = ? $sc");
        $st->execute(array_merge([$id], $sp));
        $p = $st->fetch();
        if (!$p) { http_response_code(404); exit('Reçu introuvable.'); }
        $couvert = couvert_jusqua(['id' => $p['aid'], 'adhesion' => $p['adhesion']]);
        $titre = 'Reçu de paiement';
        $ref = $p['reference'];
        break;
    case 'reclamation':
        $st = db()->prepare("SELECT r.*, a.prenom, a.nom, a.police, a.naissance, a.adhesion, a.commune, a.departement, a.devise, a.id aid, pl.nom plan_nom
            FROM reclamations r JOIN assures a ON a.id = r.assure_id LEFT JOIN plans pl ON pl.id = a.plan_id WHERE r.id = ? $sc");
        $st->execute(array_merge([$id], $sp));
        $r = $st->fetch();
        if (!$r) { http_response_code(404); exit('Dossier introuvable.'); }
        $benefs = rows('SELECT * FROM beneficiaires WHERE assure_id = ? ORDER BY part DESC', [$r['aid']]);
        $net = max(0, $r['capital'] - $r['arrieres'] - $r['frais_dossier']);
        $pieces = json_decode($r['pieces'] ?: '[]', true) ?: [];
        $services = json_decode($r['services'] ?: '[]', true) ?: [];
        $titre = 'Dossier de réclamation';
        $ref = $r['reference'];
        break;
    case 'rapport':
        $annee = (int)get('annee', date('Y'));
        $filtre = $sc; $fp = $sp;
        if ((int)get('plan')) { $filtre .= ' AND a.plan_id = ?'; $fp[] = (int)get('plan'); }
        if (get('departement') !== '' && isset(DEPARTEMENTS[get('departement')])) { $filtre .= ' AND a.departement = ?'; $fp[] = get('departement'); }
        $mensuel = rows("SELECT MONTH(p.date_paiement) m, COUNT(*) n, SUM(p.montant) total FROM paiements p JOIN assures a ON a.id = p.assure_id
            WHERE p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? $filtre GROUP BY m ORDER BY m", array_merge([$annee], $fp));
        $parPlan = rows("SELECT pl.nom, COUNT(DISTINCT a.id) n, COALESCE(SUM(CASE WHEN p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? THEN p.montant END),0) total
            FROM plans pl JOIN assures a ON a.plan_id = pl.id LEFT JOIN paiements p ON p.assure_id = a.id WHERE 1 $filtre GROUP BY pl.id ORDER BY pl.prime", array_merge([$annee], $fp));
        $statuts = rows("SELECT a.statut, COUNT(*) n FROM assures a WHERE 1 $filtre GROUP BY a.statut", $fp);
        $totalAn = array_sum(array_column($mensuel, 'total'));
        $titre = 'Rapport d’activité ' . $annee;
        $ref = 'RAP-' . $annee;
        $thermal = false;
        break;
}

$moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$qs = fn($f) => e(http_build_query(array_merge($_GET, ['format' => $f])));
if ($doc !== 'rapport') audit('Impression', $ref, $titre . ' · ' . ($thermal ? 'ticket 80 mm' : 'A4'));
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titre . ' ' . $ref) ?> · CCAD</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lora:wght@500..700&family=Public+Sans:wght@300..800&display=swap">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body { padding:24px 16px; }
    <?php if ($thermal): ?>
    @media print { @page { size:80mm auto; margin:3mm; } .sheet.thermal { width:100%; } }
    .sheet.thermal .sheet-head { flex-direction:column; align-items:center; text-align:center; }
    .sheet.thermal .sheet-head .ref { text-align:center; }
    .sheet.thermal .signatures { grid-template-columns:1fr 1fr; }
    <?php endif; ?>
    .sheet .photo { width:96px; height:120px; object-fit:cover; }
    .sheet .check-list { columns:2; font-size:11px; }
    .sheet .check-list div::before { content:'☐ '; }
    .sheet .check-list div.ok::before { content:'☑ '; }
    .month.paid { background:var(--navy-050); border-color:var(--navy-300); }
  </style>
</head>
<body>
<div class="doc-toolbar no-print">
  <span class="title"><?= e($titre) ?> · <span class="mono"><?= e($ref) ?></span></span>
  <?php if ($doc !== 'rapport'): ?>
    <a class="btn btn-sm <?= $thermal ? 'btn-secondary' : 'btn-primary' ?>" href="?<?= $qs('a4') ?>">A4</a>
    <a class="btn btn-sm <?= $thermal ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= $qs('thermal') ?>">Ticket 80 mm</a>
  <?php endif; ?>
  <button class="btn btn-accent btn-sm" onclick="window.print()"><?= icon('printer', 14) ?> Imprimer</button>
  <button class="btn btn-ghost btn-sm" onclick="history.length > 1 ? history.back() : window.close()">Fermer</button>
</div>

<article class="sheet<?= $thermal ? ' thermal' : '' ?>">
  <header class="sheet-head">
    <?= seal($thermal ? 44 : 64) ?>
    <div class="org">
      <div class="org-name"><?= e(setting('org_nom')) ?></div>
      <div class="small muted"><?= e(setting('org_adresse')) ?></div>
      <div class="small muted mono"><?= e(setting('org_tel')) ?> · <?= e(setting('org_mail')) ?></div>
      <?php if (!$thermal): ?><div class="small muted">NIF <?= e(setting('org_nif')) ?> · <?= e(setting('org_site')) ?></div><?php endif; ?>
    </div>
    <div class="ref">
      <div class="eyebrow"><?= e($titre) ?></div>
      <div class="mono" style="font-size:16px;font-weight:600;color:var(--navy-700)"><?= e($ref) ?></div>
      <div class="small muted mono">Édité le <?= date('d/m/Y H:i') ?></div>
    </div>
  </header>

<?php if ($doc === 'inscription'): ?>
  <h2 class="doc-title"><?= $a['type_police'] === 'famille' ? 'Adhésion — plan familial' : 'Adhésion — police individuelle' ?></h2>
  <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">
    <?= photo_frame($a['photo']) ?>
    <dl class="dl" style="flex:1;min-width:220px">
      <div><dt>Nom et prénom</dt><dd><?= e($a['nom'] . ' ' . $a['prenom']) ?></dd></div>
      <div><dt>N° de police</dt><dd><?= e($a['police']) ?></dd></div>
      <div><dt>Né(e) le</dt><dd><?= fdate($a['naissance']) ?><?= $a['lieu_naissance'] ? ' à ' . e($a['lieu_naissance']) : '' ?></dd></div>
      <div><dt>Sexe · état civil</dt><dd><?= e(($a['sexe'] ?: '—') . ' · ' . ($a['etat_civil'] ?: '—')) ?></dd></div>
      <div><dt>NIF / CIN</dt><dd><?= e($a['nif'] ?: '—') ?></dd></div>
      <div><dt>Profession</dt><dd><?= e($a['profession'] ?: '—') ?></dd></div>
      <div><dt>Téléphone</dt><dd><?= e($a['telephone']) ?><?= $a['telephone2'] ? ' / ' . e($a['telephone2']) : '' ?></dd></div>
      <div><dt>Personnes à charge</dt><dd><?= (int)$a['personnes_charge'] ?></dd></div>
    </dl>
  </div>
  <dl class="dl" style="margin-top:14px">
    <div style="grid-column:1/-1"><dt>Adresse</dt><dd><?= e(trim(($a['adresse'] ?: '') . ', ' . ($a['section'] ? $a['section'] . ', ' : '') . $a['commune'] . ', ' . $a['departement'], ', ')) ?></dd></div>
  </dl>

  <h3 class="section-title">Police souscrite</h3>
  <dl class="dl">
    <div><dt>Formule</dt><dd><?= e($a['formule_nom'] ?: '—') ?></dd></div>
    <div><dt>Plan</dt><dd><?= e($a['plan_nom']) ?></dd></div>
    <div><dt>Cotisation mensuelle</dt><dd><?= money($a['prime']) ?> <?= e($a['devise']) ?></dd></div>
    <div><dt>Couverture funéraire</dt><dd><?= money($a['capital']) ?> <?= e($a['devise']) ?></dd></div>
    <div><dt>Date d’adhésion</dt><dd><?= fdate($a['adhesion']) ?></dd></div>
    <div><dt>Éligible à partir du</dt><dd><?= fdate(plus_mois($a['adhesion'], (int)setting('regle_eligibilite', 24))) ?></dd></div>
    <div><dt>Mode de paiement</dt><dd><?= e(nom_mode($a['mode_paiement'])) ?><?= $a['numero_mobile'] ? ' · ' . e($a['numero_mobile']) : '' ?></dd></div>
    <div><dt>Inhumation souhaitée</dt><dd><?= e(($a['inhumation'] ?: '—') . ($a['caveau'] ? ' · ' . $a['caveau'] : '')) ?></dd></div>
  </dl>

  <?php if ($membres): ?>
    <h3 class="section-title">Membres couverts</h3>
    <table><thead><tr><th>Nom</th><th>Lien</th><th>Naissance</th><th style="text-align:right">Part</th></tr></thead><tbody>
      <?php foreach ($membres as $m): ?><tr><td><?= e($m['nom']) ?></td><td><?= e($m['lien']) ?></td><td class="mono"><?= fdate($m['naissance']) ?></td><td class="mono" style="text-align:right"><?= (float)$m['part'] ?> %</td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <h3 class="section-title">Bénéficiaires</h3>
  <table><thead><tr><th>Nom</th><th>Lien</th><?php if (!$thermal): ?><th>Téléphone</th><?php endif; ?><th style="text-align:right">Part</th></tr></thead><tbody>
    <?php foreach ($benefs as $b): ?><tr><td><?= e($b['nom']) ?></td><td><?= e($b['lien']) ?></td><?php if (!$thermal): ?><td class="mono"><?= e($b['telephone'] ?: '—') ?></td><?php endif; ?><td class="mono" style="text-align:right"><?= (float)$b['part'] ?> %</td></tr><?php endforeach; ?>
    <?php if (!$benefs): ?><tr><td colspan="4">—</td></tr><?php endif; ?>
  </tbody></table>

  <h3 class="section-title">Témoins et contact d’urgence</h3>
  <dl class="dl">
    <div><dt>Témoin 1</dt><dd><?= e(($a['temoin1_nom'] ?: '—') . ($a['temoin1_tel'] ? ' · ' . $a['temoin1_tel'] : '')) ?></dd></div>
    <div><dt>Témoin 2</dt><dd><?= e(($a['temoin2_nom'] ?: '—') . ($a['temoin2_tel'] ? ' · ' . $a['temoin2_tel'] : '')) ?></dd></div>
    <div><dt>Contact d’urgence</dt><dd><?= e(($a['contact_urgence'] ?: '—') . ($a['contact_tel'] ? ' · ' . $a['contact_tel'] : '')) ?></dd></div>
    <div><dt>Chef de famille</dt><dd><?= e($a['chef_famille'] ?: '—') ?></dd></div>
  </dl>

  <?php if (!$thermal): ?>
    <h3 class="section-title">Pièces fournies</h3>
    <div class="check-list"><?php foreach (PIECES_ADHESION as $pc): ?><div class="<?= in_array($pc, $pieces, true) ? 'ok' : '' ?>"><?= e($pc) ?></div><?php endforeach; ?></div>
    <p class="small muted" style="margin-top:14px">Je soussigné(e) certifie l’exactitude des renseignements ci-dessus et accepte les conditions générales de la CCAD, notamment le délai d’éligibilité de <?= (int)setting('regle_eligibilite', 24) ?> mois de cotisations régulières.</p>
  <?php endif; ?>
  <div class="signatures"><div>Signature de l’assuré(e)</div><div>Agent CCAD</div><?php if (!$thermal): ?><div>Cachet de la compagnie</div><?php endif; ?></div>

<?php elseif ($doc === 'paiement'): ?>
  <h2 class="doc-title">Carte de cotisation <?= $annee ?></h2>
  <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px">
    <?php if (!$thermal): ?><?= photo_frame($a['photo']) ?><?php endif; ?>
    <dl class="dl" style="flex:1;min-width:200px">
      <div><dt>Assuré(e)</dt><dd><?= e($a['prenom'] . ' ' . $a['nom']) ?></dd></div>
      <div><dt>Police</dt><dd><?= e($a['police']) ?></dd></div>
      <div><dt>Plan</dt><dd><?= e($a['plan_nom']) ?> · <?= money($a['capital']) ?> <?= e($a['devise']) ?></dd></div>
      <div><dt>Cotisation</dt><dd><?= money($a['prime']) ?> <?= e($a['devise']) ?> / mois</dd></div>
      <div><dt>Échéance</dt><dd>Le <?= (int)setting('regle_echeance', 1) ?> de chaque mois</dd></div>
      <div><dt>Paiement</dt><dd><?= e(nom_mode($a['mode_paiement'])) ?></dd></div>
    </dl>
  </div>
  <div class="months">
    <?php for ($m = 1; $m <= 12; $m++): $key = sprintf('%04d-%02d', $annee, $m); $p = $couverture[$key] ?? null; ?>
      <div class="month<?= $p ? ' paid' : '' ?>">
        <div class="m"><?= $moisNoms[$m - 1] ?></div>
        <div class="line mono small"><?= $p ? e($p['reference']) . ' · ' . fdate($p['date_paiement']) : '' ?></div>
        <div class="v"><?= $p ? 'Payé · ' . money($p['montant'] / max(1, $p['mois'])) : 'Visa agent' ?></div>
      </div>
    <?php endfor; ?>
  </div>
  <p class="small muted" style="margin-top:14px">Retard après <?= (int)setting('regle_retard', 30) ?> jours · suspension après <?= (int)setting('regle_suspension', 60) ?> jours. Conservez chaque reçu.</p>
  <div class="signatures"><div>Signature de l’assuré(e)</div><div>Agent CCAD</div><?php if (!$thermal): ?><div>Cachet</div><?php endif; ?></div>

<?php elseif ($doc === 'recu'): ?>
  <h2 class="doc-title">Reçu <?= e($p['reference']) ?><?= $p['statut'] !== 'Encaissé' ? ' — ' . e(mb_strtoupper($p['statut'])) : '' ?></h2>
  <dl class="dl">
    <div><dt>Reçu de</dt><dd><?= e($p['prenom'] . ' ' . $p['nom']) ?></dd></div>
    <div><dt>Police</dt><dd><?= e($p['police']) ?></dd></div>
    <div><dt>Date</dt><dd><?= fdate($p['date_paiement']) ?></dd></div>
    <div><dt>Objet</dt><dd><?= e($p['objet']) ?><?= $p['mois'] > 1 ? ' · ' . (int)$p['mois'] . ' mois' : '' ?></dd></div>
    <div><dt>Plan</dt><dd><?= e($p['plan_nom']) ?></dd></div>
    <div><dt>Mode</dt><dd><?= e($p['mode']) ?></dd></div>
    <div><dt>Couvert jusqu’au</dt><dd><?= fdate($couvert) ?></dd></div>
    <div><dt>Encaissé par</dt><dd><?= e($p['agent'] ?: '—') ?></dd></div>
  </dl>
  <div class="total-box"><span class="eyebrow">Montant reçu</span><span class="amount"><?= money($p['montant']) ?> <?= e($p['devise']) ?></span></div>
  <?php if ($p['statut'] === 'À valider'): ?><p class="small" style="margin-top:10px"><strong>Provisoire</strong> — reçu terrain à valider au bureau CCAD.</p><?php endif; ?>
  <p class="small muted" style="margin-top:12px;text-align:center;font-style:italic"><?= e(setting('org_slogan')) ?></p>
  <div class="signatures"><div>Caissier / agent</div><div>Assuré(e)</div><?php if (!$thermal): ?><div>Cachet</div><?php endif; ?></div>

<?php elseif ($doc === 'reclamation'): ?>
  <h2 class="doc-title">Déclaration de décès et demande de prestation</h2>
  <dl class="dl">
    <div><dt>Défunt(e)</dt><dd><?= e($r['prenom'] . ' ' . $r['nom']) ?></dd></div>
    <div><dt>Police</dt><dd><?= e($r['police']) ?> · <?= e($r['plan_nom']) ?></dd></div>
    <div><dt>Né(e) le</dt><dd><?= fdate($r['naissance']) ?></dd></div>
    <div><dt>Adhésion</dt><dd><?= fdate($r['adhesion']) ?></dd></div>
    <div><dt>Date du décès</dt><dd><?= fdate($r['date_deces']) ?></dd></div>
    <div><dt>Lieu</dt><dd><?= e($r['lieu_deces'] ?: '—') ?></dd></div>
    <div><dt>Cause</dt><dd><?= e($r['cause'] ?: '—') ?></dd></div>
    <div><dt>Acte de décès</dt><dd><?= e($r['acte_deces'] ?: '—') ?></dd></div>
    <div><dt>Déclarant</dt><dd><?= e($r['declarant_nom']) ?> (<?= e($r['declarant_lien'] ?: '—') ?>)</dd></div>
    <div><dt>Téléphone</dt><dd><?= e($r['declarant_tel']) ?></dd></div>
    <div><dt>Témoins</dt><dd><?= e(implode(' · ', array_filter([$r['temoin1'], $r['temoin2']])) ?: '—') ?></dd></div>
    <div><dt>Services</dt><dd><?= e($services ? implode(', ', $services) : '—') ?></dd></div>
  </dl>
  <h3 class="section-title">Décompte</h3>
  <table><tbody>
    <tr><td>Capital funéraire</td><td class="mono" style="text-align:right"><?= money($r['capital']) ?></td></tr>
    <tr><td>Arriérés de cotisation</td><td class="mono" style="text-align:right">− <?= money($r['arrieres']) ?></td></tr>
    <tr><td>Frais de dossier</td><td class="mono" style="text-align:right">− <?= money($r['frais_dossier']) ?></td></tr>
  </tbody></table>
  <div class="total-box"><span class="eyebrow">Net à verser · <?= e($r['statut']) ?></span><span class="amount"><?= money($net) ?> <?= e($r['devise']) ?></span></div>
  <h3 class="section-title">Répartition</h3>
  <table><thead><tr><th>Bénéficiaire</th><th>Lien</th><th style="text-align:right">Part</th><th style="text-align:right">Montant</th></tr></thead><tbody>
    <?php foreach ($benefs as $b): ?><tr><td><?= e($b['nom']) ?></td><td><?= e($b['lien']) ?></td><td class="mono" style="text-align:right"><?= (float)$b['part'] ?> %</td><td class="mono" style="text-align:right"><?= money($net * $b['part'] / 100) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if (!$thermal): ?>
    <h3 class="section-title">Pièces du dossier</h3>
    <div class="check-list"><?php foreach (PIECES_RECLAMATION as $pc): ?><div class="<?= in_array($pc, $pieces, true) ? 'ok' : '' ?>"><?= e($pc) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if ($r['statut'] === 'Rejeté'): ?><p class="small"><strong>Rejeté :</strong> <?= e($r['motif_rejet']) ?></p><?php endif; ?>
  <div class="signatures"><div>Déclarant</div><div>Agent de gestion</div><?php if (!$thermal): ?><div>Direction CCAD</div><?php endif; ?></div>

<?php elseif ($doc === 'rapport'): ?>
  <h2 class="doc-title"><?= e($titre) ?></h2>
  <div class="total-box" style="margin-top:0"><span class="eyebrow">Total encaissé</span><span class="amount"><?= money($totalAn) ?> <?= e($devise) ?></span></div>
  <h3 class="section-title">Encaissements mensuels</h3>
  <table><thead><tr><th>Mois</th><th style="text-align:right">Reçus</th><th style="text-align:right">Montant</th></tr></thead><tbody>
    <?php foreach ($mensuel as $m): ?><tr><td><?= $moisNoms[$m['m'] - 1] ?></td><td class="mono" style="text-align:right"><?= (int)$m['n'] ?></td><td class="mono" style="text-align:right"><?= money($m['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h3 class="section-title">Par plan</h3>
  <table><thead><tr><th>Plan</th><th style="text-align:right">Assurés</th><th style="text-align:right">Encaissé</th></tr></thead><tbody>
    <?php foreach ($parPlan as $pl): ?><tr><td><?= e($pl['nom']) ?></td><td class="mono" style="text-align:right"><?= (int)$pl['n'] ?></td><td class="mono" style="text-align:right"><?= money($pl['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h3 class="section-title">Portefeuille par statut</h3>
  <table><tbody><?php foreach ($statuts as $s): ?><tr><td><?= e($s['statut']) ?></td><td class="mono" style="text-align:right"><?= (int)$s['n'] ?></td></tr><?php endforeach; ?></tbody></table>
  <div class="signatures"><div>Préparé par <?= e($u['nom']) ?></div><div>Direction financière</div><div>Direction générale</div></div>
<?php endif; ?>

  <footer class="doc-footer"><?= e(setting('org_nom')) ?> · <?= e($ref) ?> · imprimé par <?= e($u['nom']) ?> le <?= date('d/m/Y à H:i') ?> · CCAD Gestion v<?= e(APP_VERSION) ?></footer>
</article>
<?php if (get('auto') === '1'): ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>
</body>
</html>
