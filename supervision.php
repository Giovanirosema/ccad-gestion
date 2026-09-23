<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('rapports');
recalculer_statuts();
[$sc, $sp] = scope_dept('a');
$devise = devise();

// Mois analysé (aaaa-mm)
$mois = preg_match('/^\d{4}-\d{2}$/', get('mois')) ? get('mois') : date('Y-m');
$debut = $mois . '-01';
$fin = date('Y-m-t', strtotime($debut));

$depts = rows("SELECT a.departement dept, COUNT(*) assures,
        SUM(a.statut = 'Active') actives, SUM(a.statut IN ('En retard','Suspendue')) retard,
        SUM(CASE WHEN a.statut IN ('Active','En retard','Suspendue') THEN pl.prime ELSE 0 END) attendu,
        (SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN assures a2 ON a2.id = p.assure_id
            WHERE a2.departement = a.departement AND p.statut = 'Encaissé' AND p.objet = 'Cotisation' AND p.date_paiement BETWEEN ? AND ?) encaisse,
        SUM(a.adhesion BETWEEN ? AND ?) nouvelles
    FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id WHERE 1 $sc GROUP BY a.departement ORDER BY encaisse DESC",
    array_merge([$debut, $fin, $debut, $fin], $sp));
$tot = ['assures' => 0, 'actives' => 0, 'retard' => 0, 'attendu' => 0, 'encaisse' => 0, 'nouvelles' => 0];
foreach ($depts as $d) foreach ($tot as $k => $_) $tot[$k] += $d[$k];
$taux = fn($enc, $att) => $att > 0 ? min(100, round($enc / $att * 100)) : 0;

$agents = rows("SELECT us.nom, us.role, us.departement, us.zone, us.derniere_connexion,
        COUNT(p.id) recus, COALESCE(SUM(CASE WHEN p.statut = 'Encaissé' THEN p.montant END),0) encaisse,
        COALESCE(SUM(CASE WHEN p.statut = 'À valider' THEN p.montant END),0) attente,
        (SELECT COUNT(*) FROM assures a3 WHERE a3.created_by = us.id AND a3.adhesion BETWEEN ? AND ?) adhesions
    FROM users us LEFT JOIN paiements p ON p.created_by = us.id AND p.date_paiement BETWEEN ? AND ? AND p.statut <> 'Annulé'
    WHERE us.actif = 1" . ($sp ? ' AND us.departement = ?' : '') . "
    GROUP BY us.id ORDER BY encaisse DESC", array_merge([$debut, $fin, $debut, $fin], $sp));

$moisNoms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$libelle = $moisNoms[(int)substr($mois, 5, 2) - 1] . ' ' . substr($mois, 0, 4);
$choix = [];
for ($i = 0; $i < 12; $i++) {
    $d = (new DateTime('first day of this month'))->modify("-$i month");
    $choix[$d->format('Y-m')] = ucfirst($moisNoms[(int)$d->format('n') - 1]) . ' ' . $d->format('Y');
}

$page_title = 'Supervision';
$active = 'supervision';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Administration › Supervision</div>
    <h1>Supervision par département</h1>
    <div class="meta">Recouvrement des cotisations et activité des agents · <?= e($libelle) ?></div>
  </div>
  <form class="actions" method="get">
    <select class="input" name="mois" style="width:180px" onchange="this.form.submit()"><?= options($choix, $mois) ?></select>
  </form>
</div>

<div class="grid grid-stats">
  <?= stat_card('Taux de recouvrement', (string)$taux($tot['encaisse'], $tot['attendu']), '%', money($tot['encaisse']) . ' / ' . money($tot['attendu']) . ' ' . $devise, 'brand', 'chart') ?>
  <?= stat_card('Polices actives', (string)$tot['actives'], 'sur ' . $tot['assures'], '', '', 'shield') ?>
  <?= stat_card('En retard ou suspendues', (string)$tot['retard'], 'police(s)', 'À relancer', '', 'alert') ?>
  <?= stat_card('Nouvelles adhésions', (string)$tot['nouvelles'], 'ce mois', '', '', 'user-plus') ?>
</div>

<section class="card card-flush">
  <div class="card-head"><div><h2>Départements</h2><div class="sub">Cotisations encaissées rapportées aux primes attendues du portefeuille en vigueur</div></div></div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Département</th><th class="num">Assurés</th><th class="num">Actives</th><th class="num">En retard</th><th class="num">Attendu</th><th class="num">Encaissé</th><th style="width:22%">Recouvrement</th><th class="num">Adhésions</th></tr></thead>
      <tbody>
      <?php foreach ($depts as $d): $t = $taux($d['encaisse'], $d['attendu']); ?>
        <tr>
          <td><strong><?= e($d['dept']) ?></strong></td>
          <td class="num"><?= (int)$d['assures'] ?></td>
          <td class="num"><?= (int)$d['actives'] ?></td>
          <td class="num"><?= (int)$d['retard'] ?></td>
          <td class="num"><?= money($d['attendu']) ?></td>
          <td class="num"><?= money($d['encaisse']) ?></td>
          <td><div class="row" style="gap:8px"><div class="progress" style="flex:1"><span style="width:<?= $t ?>%"></span></div><span class="mono small"><?= $t ?> %</span></div></td>
          <td class="num"><?= (int)$d['nouvelles'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$depts): ?><tr><td colspan="8" class="muted">Aucune donnée.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card card-flush">
  <div class="card-head"><div><h2>Activité des agents</h2><div class="sub"><?= e($libelle) ?></div></div></div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Agent</th><th>Département · zone</th><th class="num">Reçus</th><th class="num">Encaissé</th><th class="num">À valider</th><th class="num">Adhésions</th><th>Dernière connexion</th></tr></thead>
      <tbody>
      <?php foreach ($agents as $g): ?>
        <tr>
          <td><?= person($g['nom'], $g['role']) ?></td>
          <td class="small"><?= e(($g['departement'] ?: 'Tous') . ($g['zone'] ? ' · ' . $g['zone'] : '')) ?></td>
          <td class="num"><?= (int)$g['recus'] ?></td>
          <td class="num"><?= money($g['encaisse']) ?></td>
          <td class="num"><?= money($g['attente']) ?></td>
          <td class="num"><?= (int)$g['adhesions'] ?></td>
          <td class="mono small"><?= $g['derniere_connexion'] ? date('d/m/Y H:i', strtotime($g['derniere_connexion'])) : 'Jamais' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
