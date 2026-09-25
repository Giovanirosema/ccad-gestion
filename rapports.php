<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('rapports');
[$sc, $sp] = scope_dept('a');
$devise = devise();

$annee = (int)get('annee', date('Y'));
$plan = (int)get('plan');
$dept = get('departement');
$filtre = $sc;
$fp = $sp;
if ($plan) { $filtre .= ' AND a.plan_id = ?'; $fp[] = $plan; }
if ($dept !== '' && isset(DEPARTEMENTS[$dept])) { $filtre .= ' AND a.departement = ?'; $fp[] = $dept; }

$annees = array_column(rows('SELECT DISTINCT YEAR(date_paiement) y FROM paiements ORDER BY y DESC'), 'y') ?: [date('Y')];

// Mensuel : encaissements, adhésions, réclamations
$enc = rows("SELECT MONTH(p.date_paiement) m, SUM(p.montant) total, COUNT(*) n FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? $filtre GROUP BY m", array_merge([$annee], $fp));
$adh = rows("SELECT MONTH(a.adhesion) m, COUNT(*) n FROM assures a WHERE YEAR(a.adhesion) = ? $filtre GROUP BY m", array_merge([$annee], $fp));
$rec = rows("SELECT MONTH(r.date_deces) m, COUNT(*) n, SUM(CASE WHEN r.statut = 'Versé' THEN GREATEST(r.capital - r.arrieres - r.frais_dossier, 0) ELSE 0 END) verse
    FROM reclamations r JOIN assures a ON a.id = r.assure_id WHERE YEAR(r.date_deces) = ? $filtre GROUP BY m", array_merge([$annee], $fp));
$moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$mensuel = [];
for ($m = 1; $m <= 12; $m++) $mensuel[$m] = ['enc' => 0, 'nb' => 0, 'adh' => 0, 'rec' => 0, 'verse' => 0];
foreach ($enc as $r) { $mensuel[$r['m']]['enc'] = (float)$r['total']; $mensuel[$r['m']]['nb'] = (int)$r['n']; }
foreach ($adh as $r) $mensuel[$r['m']]['adh'] = (int)$r['n'];
foreach ($rec as $r) { $mensuel[$r['m']]['rec'] = (int)$r['n']; $mensuel[$r['m']]['verse'] = (float)$r['verse']; }
$tot = ['enc' => 0, 'nb' => 0, 'adh' => 0, 'rec' => 0, 'verse' => 0];
foreach ($mensuel as $v) foreach ($tot as $k => $_) $tot[$k] += $v[$k];
$maxEnc = max(array_column($mensuel, 'enc')) ?: 1;

$parPlan = rows("SELECT pl.nom, pl.prime, pl.capital, COUNT(DISTINCT a.id) assures,
        COALESCE(SUM(CASE WHEN p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? THEN p.montant END),0) total
    FROM plans pl LEFT JOIN assures a ON a.plan_id = pl.id $filtre LEFT JOIN paiements p ON p.assure_id = a.id
    GROUP BY pl.id ORDER BY pl.prime", array_merge([$annee], $fp));
$parDept = rows("SELECT a.departement, COUNT(DISTINCT a.id) assures,
        COUNT(DISTINCT CASE WHEN a.statut IN ('En retard','Suspendue') THEN a.id END) retard,
        COALESCE(SUM(CASE WHEN p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? THEN p.montant END),0) total
    FROM assures a LEFT JOIN paiements p ON p.assure_id = a.id
    WHERE 1 $filtre GROUP BY a.departement ORDER BY total DESC", array_merge([$annee], $fp));
$parMode = rows("SELECT p.mode, COUNT(*) n, SUM(p.montant) total FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND YEAR(p.date_paiement) = ? $filtre GROUP BY p.mode ORDER BY total DESC", array_merge([$annee], $fp));

$page_title = 'Rapports';
$active = 'rapports';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Rapports</div>
    <h1>Rapport d’activité <?= $annee ?></h1>
    <div class="meta"><?= $plan ? 'Plan filtré · ' : '' ?><?= $dept !== '' ? e($dept) . ' · ' : 'Tous départements · ' ?>montants en <?= e($devise) ?></div>
  </div>
  <div class="actions no-print">
    <a class="btn btn-secondary" href="export.php?<?= e(http_build_query(['type' => 'paiements', 'du' => "$annee-01-01", 'au' => "$annee-12-31"])) ?>"><?= icon('download', 16) ?> Exporter PDF</a><a class="btn btn-ghost btn-sm" href="export.php?<?= e(http_build_query(['type' => 'paiements', 'du' => "$annee-01-01", 'au' => "$annee-12-31"] + ['format' => 'csv'])) ?>" title="Pour Excel">CSV</a>
    <a class="btn btn-primary" target="_blank" href="imprimer.php?<?= e(http_build_query(['doc' => 'rapport', 'annee' => $annee, 'plan' => $plan ?: '', 'departement' => $dept])) ?>"><?= icon('printer', 16) ?> Imprimer le rapport</a>
  </div>
</div>

<form class="card no-print" method="get">
  <div class="card-body form-row" style="align-items:flex-end">
    <div class="field" style="flex:0 1 120px"><label>Année</label><select class="input" name="annee"><?= options($annees, $annee, false) ?></select></div>
    <div class="field"><label>Plan</label><select class="input" name="plan"><option value="">Tous les plans</option><?= options(array_column(plans(false), 'nom', 'id'), $plan ?: '') ?></select></div>
    <div class="field"><label>Département</label><select class="input" name="departement"><option value="">Tous</option><?= options(array_keys(DEPARTEMENTS), $dept, false) ?></select></div>
    <button class="btn btn-secondary">Appliquer</button>
  </div>
</form>

<div class="grid grid-stats">
  <?= stat_card('Encaissements', money($tot['enc']), $devise, $tot['nb'] . ' reçu(s)', 'brand', 'card') ?>
  <?= stat_card('Nouvelles adhésions', (string)$tot['adh'], 'police(s)', 'Sur l’année', '', 'user-plus') ?>
  <?= stat_card('Décès déclarés', (string)$tot['rec'], 'dossier(s)', money($tot['verse']) . ' ' . $devise . ' versés', '', 'folder') ?>
  <?= stat_card('Solde technique', money($tot['enc'] - $tot['verse']), $devise, 'Encaissé − versé', '', 'chart') ?>
</div>

<section class="card card-flush">
  <div class="card-head"><h2>Évolution mensuelle</h2></div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Mois</th><th class="num">Encaissé</th><th style="width:30%"></th><th class="num">Reçus</th><th class="num">Adhésions</th><th class="num">Décès</th><th class="num">Versé</th></tr></thead>
      <tbody>
      <?php foreach ($mensuel as $m => $v): ?>
        <tr>
          <td><?= $moisNoms[$m - 1] ?></td>
          <td class="num"><?= money($v['enc']) ?></td>
          <td><div class="progress"><span style="width:<?= round($v['enc'] / $maxEnc * 100) ?>%"></span></div></td>
          <td class="num"><?= $v['nb'] ?></td><td class="num"><?= $v['adh'] ?></td><td class="num"><?= $v['rec'] ?></td><td class="num"><?= money($v['verse']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr style="font-weight:600"><td>Total</td><td class="num"><?= money($tot['enc']) ?></td><td></td><td class="num"><?= $tot['nb'] ?></td><td class="num"><?= $tot['adh'] ?></td><td class="num"><?= $tot['rec'] ?></td><td class="num"><?= money($tot['verse']) ?></td></tr>
      </tbody>
    </table>
  </div>
</section>

<div class="cols">
  <section class="card card-flush" style="flex:1 1 380px">
    <div class="card-head"><h2>Par plan tarifaire</h2></div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Plan</th><th class="num">Prime</th><th class="num">Capital</th><th class="num">Assurés</th><th class="num">Encaissé</th></tr></thead>
        <tbody><?php foreach ($parPlan as $p): ?>
          <tr><td><?= e($p['nom']) ?></td><td class="num"><?= money($p['prime']) ?></td><td class="num"><?= money($p['capital']) ?></td><td class="num"><?= (int)$p['assures'] ?></td><td class="num"><?= money($p['total']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
  <section class="card card-flush" style="flex:1 1 380px">
    <div class="card-head"><h2>Par département</h2></div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Département</th><th class="num">Assurés</th><th class="num">En retard</th><th class="num">Encaissé</th></tr></thead>
        <tbody><?php foreach ($parDept as $d): ?>
          <tr><td><?= e($d['departement']) ?></td><td class="num"><?= (int)$d['assures'] ?></td><td class="num"><?= (int)$d['retard'] ?></td><td class="num"><?= money($d['total']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$parDept): ?><tr><td colspan="4" class="muted">Aucune donnée.</td></tr><?php endif; ?></tbody>
      </table>
    </div>
  </section>
  <section class="card card-flush" style="flex:1 1 280px">
    <div class="card-head"><h2>Par moyen de paiement</h2></div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Mode</th><th class="num">Reçus</th><th class="num">Montant</th><th class="num">Part</th></tr></thead>
        <tbody><?php foreach ($parMode as $m): ?>
          <tr><td><?= e($m['mode']) ?></td><td class="num"><?= (int)$m['n'] ?></td><td class="num"><?= money($m['total']) ?></td><td class="num"><?= $tot['enc'] > 0 ? round($m['total'] / $tot['enc'] * 100) : 0 ?> %</td></tr>
        <?php endforeach; ?>
        <?php if (!$parMode): ?><tr><td colspan="4" class="muted">Aucun encaissement.</td></tr><?php endif; ?></tbody>
      </table>
    </div>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
