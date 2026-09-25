<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('assures');

[$sc, $sp] = scope_dept('a');
$q = get('q');
$statut = get('statut');
$type = get('type');
$page = max(1, (int)get('page', 1));
$parPage = 25;
$elig = (int)setting('regle_eligibilite', 24);

$where = "WHERE 1 $sc";
$params = $sp;
if ($q !== '') {
    $where .= " AND (CONCAT(a.prenom, ' ', a.nom) LIKE ? OR a.police LIKE ? OR a.reference LIKE ? OR a.commune LIKE ? OR a.telephone LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($statut !== '') { $where .= ' AND a.statut = ?'; $params[] = $statut; }
if (in_array($type, ['individuel', 'famille'], true)) { $where .= ' AND a.type_police = ?'; $params[] = $type; }

$total = (int)scalar("SELECT COUNT(*) FROM assures a $where", $params);
$pages = max(1, (int)ceil($total / $parPage));
$offset = ($page - 1) * $parPage;
$liste = rows("SELECT a.*, p.nom plan_nom, p.prime FROM assures a LEFT JOIN plans p ON p.id = a.plan_id
    $where ORDER BY a.nom, a.prenom LIMIT $parPage OFFSET $offset", $params);

$page_title = 'Assurés';
$active = 'assures';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Assurés</div>
    <h1>Assurés</h1>
    <div class="meta"><?= $total ?> dossier(s) correspondant(s)<?= $q !== '' ? ' à « ' . e($q) . ' »' : '' ?></div>
  </div>
  <div class="actions">
    <a class="btn btn-secondary" href="export.php?type=assures"><?= icon('download', 16) ?> Exporter PDF</a>
    <a class="btn btn-ghost btn-sm" href="export.php?type=assures&format=csv" title="Pour Excel">CSV</a>
    <a class="btn btn-secondary" href="adhesion.php?type=famille"><?= icon('users', 16) ?> Plan familial</a>
    <a class="btn btn-primary" href="adhesion.php?type=individuel"><?= icon('user-plus', 16) ?> Plan individuel</a>
  </div>
</div>

<section class="card card-flush">
  <div class="card-head">
    <div><h2>Liste des assurés</h2><div class="sub">Cliquer une ligne pour ouvrir la fiche</div></div>
    <form class="row" method="get">
      <input class="input" style="width:220px;height:32px" name="q" value="<?= e($q) ?>" placeholder="Nom, police, commune…">
      <select class="input" style="width:160px;height:32px" name="statut" onchange="this.form.submit()">
        <option value="">Tous les statuts</option>
        <?= options(['Active', 'En retard', 'En attente', 'Suspendue', 'Résiliée', 'Décédé'], $statut, false) ?>
      </select>
      <select class="input" style="width:150px;height:32px" name="type" onchange="this.form.submit()">
        <option value="">Tous les types</option>
        <?= options(['individuel' => 'Individuel', 'famille' => 'Familial'], $type) ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Filtrer</button>
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Assuré</th><th>Commune</th><th>Plan</th><th class="num">Prime</th><th class="num">Mois</th><th>Éligibilité</th><th>Fiches</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $a): $m = mois_ecoules($a['adhesion']); ?>
        <tr data-href="assure.php?id=<?= (int)$a['id'] ?>">
          <td><?= person($a['prenom'] . ' ' . $a['nom'], $a['police']) ?></td>
          <td><?= e($a['commune']) ?></td>
          <td><?= e($a['plan_nom']) ?></td>
          <td class="num"><?= money($a['prime']) ?></td>
          <td class="num"><?= $m ?></td>
          <td><?= $m >= $elig ? '<span class="badge badge-success">Éligible</span>' : '<span class="badge badge-neutral">' . ($elig - $m) . ' mois</span>' ?></td>
          <td class="nowrap">
            <a class="icon-btn" title="Fiche d’inscription" href="imprimer.php?doc=inscription&id=<?= (int)$a['id'] ?>" target="_blank"><?= icon('printer', 16) ?></a>
            <a class="icon-btn" title="Fiche de paiement" href="imprimer.php?doc=paiement&id=<?= (int)$a['id'] ?>" target="_blank"><?= icon('card', 16) ?></a>
          </td>
          <td><?= badge($a['statut']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$liste): ?>
        <tr><td colspan="8"><div class="empty"><h3>Aucun résultat</h3><p>Aucun dossier ne correspond à cette recherche.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($pages > 1): ?>
  <div class="row">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"
         href="?<?= e(http_build_query(['q' => $q, 'statut' => $statut, 'type' => $type, 'page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
