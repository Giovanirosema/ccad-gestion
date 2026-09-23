<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('polices');
recalculer_statuts();
[$sc, $sp] = scope_dept('a');
$devise = devise();
$elig = (int)setting('regle_eligibilite', 24);

$q = get('q');
$plan = (int)get('plan');
$statut = get('statut');
$where = "WHERE 1 $sc";
$params = $sp;
if ($q !== '') { $where .= " AND (a.police LIKE ? OR CONCAT(a.prenom,' ',a.nom) LIKE ?)"; array_push($params, "%$q%", "%$q%"); }
if ($plan) { $where .= ' AND a.plan_id = ?'; $params[] = $plan; }
if ($statut !== '') { $where .= ' AND a.statut = ?'; $params[] = $statut; }

$liste = rows("SELECT a.*, pl.nom plan_nom, pl.prime, pl.capital, f.nom formule_nom,
        (SELECT COUNT(*) FROM beneficiaires b WHERE b.assure_id = a.id) nb_benef,
        (SELECT COUNT(*) FROM membres m WHERE m.assure_id = a.id) nb_membres
    FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id LEFT JOIN formules f ON f.id = a.formule_id
    $where ORDER BY a.adhesion DESC LIMIT 500", $params);

$enVigueur = rows("SELECT COUNT(*) n, COALESCE(SUM(pl.capital),0) capital, COALESCE(SUM(pl.prime),0) primes FROM assures a
    JOIN plans pl ON pl.id = a.plan_id WHERE a.statut IN ('Active','En retard') $sc", $sp)[0];
$nbEligibles = (int)scalar("SELECT COUNT(*) FROM assures a WHERE a.statut IN ('Active','En retard')
    AND a.adhesion <= DATE_SUB(CURDATE(), INTERVAL $elig MONTH) $sc", $sp);
$familiales = (int)scalar("SELECT COUNT(*) FROM assures a WHERE a.type_police = 'famille' AND a.statut NOT IN ('Résiliée','Décédé') $sc", $sp);

$page_title = 'Polices';
$active = 'polices';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Polices</div>
    <h1>Polices</h1>
    <div class="meta">Contrats en portefeuille · éligibilité après <?= $elig ?> mois de cotisation</div>
  </div>
  <div class="actions">
    <a class="btn btn-secondary" href="export.php?type=polices"><?= icon('download', 16) ?> Exporter CSV</a>
    <a class="btn btn-primary" href="adhesion.php"><?= icon('user-plus', 16) ?> Nouvelle adhésion</a>
  </div>
</div>

<div class="grid grid-stats">
  <?= stat_card('Polices en vigueur', (string)$enVigueur['n'], 'contrats', $familiales . ' plan(s) familial(aux)', 'brand', 'file') ?>
  <?= stat_card('Capital couvert', money($enVigueur['capital']), $devise, 'Engagement total', '', 'shield') ?>
  <?= stat_card('Primes mensuelles', money($enVigueur['primes']), $devise, 'Attendues chaque mois', '', 'card') ?>
  <?= stat_card('Polices éligibles', (string)$nbEligibles, 'contrats', "Plus de $elig mois", '', 'calendar') ?>
</div>

<section class="card card-flush">
  <div class="card-head">
    <div><h2>Portefeuille de polices</h2><div class="sub"><?= count($liste) ?> police(s)</div></div>
    <form class="row" method="get">
      <input class="input" style="width:200px;height:32px" name="q" value="<?= e($q) ?>" placeholder="N° de police, nom…">
      <select class="input" style="width:130px;height:32px" name="plan" onchange="this.form.submit()"><option value="">Tous les plans</option>
        <?= options(array_column(plans(false), 'nom', 'id'), $plan ?: '') ?></select>
      <select class="input" style="width:140px;height:32px" name="statut" onchange="this.form.submit()"><option value="">Tous statuts</option>
        <?= options(['Active', 'En retard', 'En attente', 'Suspendue', 'Résiliée', 'Décédé'], $statut, false) ?></select>
      <button class="btn btn-secondary btn-sm">Filtrer</button>
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Police</th><th>Souscripteur</th><th>Formule</th><th>Plan</th><th class="num">Prime</th><th class="num">Capital</th><th class="num">Couverts</th><th>Adhésion</th><th>Éligibilité</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $a): $m = mois_ecoules($a['adhesion']); ?>
        <tr data-href="assure.php?id=<?= (int)$a['id'] ?>">
          <td class="mono"><?= e($a['police']) ?></td>
          <td><?= person($a['prenom'] . ' ' . $a['nom'], $a['commune']) ?></td>
          <td class="small"><?= e($a['formule_nom'] ?: ($a['type_police'] === 'famille' ? 'Plan familial' : 'Individuel')) ?></td>
          <td><?= e($a['plan_nom']) ?></td>
          <td class="num"><?= money($a['prime']) ?></td>
          <td class="num"><?= money($a['capital']) ?></td>
          <td class="num"><?= $a['type_police'] === 'famille' ? (int)$a['nb_membres'] : 1 ?> <span class="muted small">/ <?= (int)$a['nb_benef'] ?> bén.</span></td>
          <td class="mono"><?= fdate($a['adhesion']) ?></td>
          <td>
            <?php if ($m >= $elig): ?><span class="badge badge-success">Éligible</span>
            <?php else: ?><div class="progress" style="width:90px" title="<?= $m ?> / <?= $elig ?> mois"><span style="width:<?= round($m / $elig * 100) ?>%"></span></div><?php endif; ?>
          </td>
          <td><?= badge($a['statut']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$liste): ?><tr><td colspan="10"><div class="empty"><h3>Aucune police</h3><p>Aucun contrat ne correspond aux filtres.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
