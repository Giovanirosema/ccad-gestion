<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('parametres');

$q = get('q');
$type = get('type');
$userId = (int)get('user');
$du = parse_date(get('du')) ?: date('Y-m-d', strtotime('-30 days'));
$au = parse_date(get('au')) ?: date('Y-m-d');
$page = max(1, (int)get('page', 1));
$parPage = 50;

$where = 'WHERE au.created_at >= ? AND au.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
$params = [$du, $au];
if ($q !== '') { $where .= ' AND (au.cible LIKE ? OR au.detail LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
if ($type !== '') { $where .= ' AND au.type = ?'; $params[] = $type; }
if ($userId) { $where .= ' AND au.user_id = ?'; $params[] = $userId; }

$total = (int)scalar("SELECT COUNT(*) FROM audit au $where", $params);
$pages = max(1, (int)ceil($total / $parPage));
$offset = ($page - 1) * $parPage;
$liste = rows("SELECT au.* FROM audit au $where ORDER BY au.id DESC LIMIT $parPage OFFSET $offset", $params);
$types = array_column(rows('SELECT DISTINCT type FROM audit ORDER BY type'), 'type');
$users = array_column(rows('SELECT id, nom FROM users ORDER BY nom'), 'nom', 'id');
$tones = ['Connexion' => 'info', 'Déconnexion' => 'neutral', 'Encaissement' => 'success', 'Validation' => 'success', 'Versement' => 'success',
          'Adhésion' => 'brand', 'Réclamation' => 'warning', 'Annulation' => 'danger', 'Rejet' => 'danger', 'Échec connexion' => 'danger'];
$filtres = ['q' => $q, 'type' => $type, 'user' => $userId ?: '', 'du' => fdate($du), 'au' => fdate($au)];

$page_title = 'Journal d’audit';
$active = 'audit';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Administration › Journal d’audit</div>
    <h1>Journal d’audit</h1>
    <div class="meta">Trace de toutes les opérations · non modifiable · <?= $total ?> événement(s) sur la période</div>
  </div>
  <div class="actions"><a class="btn btn-secondary" href="export.php?<?= e(http_build_query(['type' => 'audit', 'du' => $du, 'au' => $au])) ?>"><?= icon('download', 16) ?> Exporter CSV</a></div>
</div>

<section class="card card-flush">
  <div class="card-head">
    <form class="row" method="get" style="flex-wrap:wrap">
      <input class="input" style="width:200px;height:32px" name="q" value="<?= e($q) ?>" placeholder="Police, reçu, détail…">
      <select class="input" style="width:150px;height:32px" name="type"><option value="">Tous les types</option><?= options($types, $type, false) ?></select>
      <select class="input" style="width:170px;height:32px" name="user"><option value="">Tous les utilisateurs</option><?= options($users, $userId ?: '') ?></select>
      <input class="input mono" style="width:120px;height:32px" name="du" value="<?= fdate($du) ?>" title="Du">
      <input class="input mono" style="width:120px;height:32px" name="au" value="<?= fdate($au) ?>" title="Au">
      <button class="btn btn-secondary btn-sm">Filtrer</button>
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Date et heure</th><th>Utilisateur</th><th>Zone</th><th>Action</th><th>Cible</th><th>Détail</th><th>Adresse IP</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $l): ?>
        <tr>
          <td class="mono nowrap"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
          <td><?= person($l['user_nom']) ?></td>
          <td class="small"><?= e($l['zone'] ?: '—') ?></td>
          <td><span class="badge badge-<?= $tones[$l['type']] ?? 'neutral' ?>"><?= e($l['type']) ?></span></td>
          <td class="mono"><?= e($l['cible']) ?></td>
          <td class="small"><?= e($l['detail']) ?></td>
          <td class="mono small muted"><?= e($l['ip'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$liste): ?><tr><td colspan="7"><div class="empty"><h3>Aucun événement</h3><p>Rien à afficher pour ces filtres.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($pages > 1): ?>
  <div class="row">
    <?php for ($i = max(1, $page - 5); $i <= min($pages, $page + 5); $i++): ?>
      <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= e(http_build_query($filtres + ['page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
