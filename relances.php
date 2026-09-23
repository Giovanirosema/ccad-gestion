<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('paiements');
recalculer_statuts();
[$sc, $sp] = scope_dept('a');
$devise = devise();
$retard = (int)setting('regle_retard', 30);
$susp = (int)setting('regle_suspension', 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $canal = post('canal') === 'appel' ? 'Appel' : 'SMS';
    $n = 0;
    foreach ($ids as $id) {
        $st = db()->prepare("SELECT a.police, a.prenom, a.nom, a.telephone FROM assures a WHERE a.id = ? $sc");
        $st->execute(array_merge([$id], $sp));
        if ($a = $st->fetch()) {
            audit('Relance', $a['police'], "$canal · {$a['prenom']} {$a['nom']} · {$a['telephone']}");
            $n++;
        }
    }
    flash($n ? 'success' : 'warning', $n ? "$n relance(s) $canal enregistrée(s) au journal." : 'Aucun assuré sélectionné.');
    redirect('relances.php');
}

$liste = rows("SELECT a.*, pl.nom plan_nom, pl.prime,
        (SELECT MAX(DATE_ADD(p.date_paiement, INTERVAL p.mois MONTH)) FROM paiements p WHERE p.assure_id = a.id AND p.statut = 'Encaissé' AND p.objet = 'Cotisation') couvert,
        (SELECT MAX(au.created_at) FROM audit au WHERE au.type = 'Relance' AND au.cible = a.police) derniere_relance
    FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id
    WHERE a.statut IN ('Active','En retard','Suspendue') $sc", $sp);

// Échéances : on garde les dossiers dont la couverture se termine dans les 7 jours ou est dépassée
$today = new DateTime('today');
$relances = [];
foreach ($liste as $a) {
    $fin = new DateTime($a['couvert'] ?: plus_mois($a['adhesion'], 1));
    $jours = (int)$fin->diff($today)->format('%r%a');
    if ($jours < -7) continue;
    $a['fin'] = $fin->format('Y-m-d');
    $a['jours'] = $jours;
    $a['du'] = $jours > 0 ? max(1, (int)ceil($jours / 30)) * $a['prime'] : $a['prime'];
    $relances[] = $a;
}
usort($relances, fn($x, $y) => $y['jours'] <=> $x['jours']);
$groupes = [
    'Suspendues (plus de ' . $susp . ' j)' => array_filter($relances, fn($a) => $a['jours'] > $susp),
    'En retard (' . $retard . ' à ' . $susp . ' j)' => array_filter($relances, fn($a) => $a['jours'] > $retard && $a['jours'] <= $susp),
    'Échéance dépassée (moins de ' . $retard . ' j)' => array_filter($relances, fn($a) => $a['jours'] > 0 && $a['jours'] <= $retard),
    'À échoir dans les 7 jours' => array_filter($relances, fn($a) => $a['jours'] <= 0),
];
$totalDu = array_sum(array_map(fn($a) => $a['jours'] > 0 ? $a['du'] : 0, $relances));

$page_title = 'Relances';
$active = 'relances';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Relances</div>
    <h1>Relances</h1>
    <div class="meta">Retard après <?= $retard ?> jours · suspension après <?= $susp ?> jours · règles modifiables dans Paramètres</div>
  </div>
</div>

<div class="grid grid-stats">
  <?php $i = 0; foreach ($groupes as $label => $g): ?>
    <?= stat_card($label, (string)count($g), 'police(s)', '', $i++ === 0 ? 'brand' : '', $i === 1 ? 'alert' : 'bell') ?>
  <?php endforeach; ?>
</div>

<form method="post" class="stack">
  <?= csrf_field() ?>
  <section class="card card-flush">
    <div class="card-head">
      <div><h2>Polices à relancer</h2><div class="sub"><?= count($relances) ?> dossier(s) · arriérés estimés <?= money($totalDu) ?> <?= e($devise) ?></div></div>
      <div class="row">
        <select class="input" style="width:140px;height:32px" name="canal"><option value="sms">SMS</option><option value="appel">Appel</option></select>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('bell', 14) ?> Enregistrer la relance</button>
      </div>
    </div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.rel-cb').forEach(c => c.checked = this.checked)"></th>
          <th>Assuré</th><th>Téléphone</th><th>Plan</th><th>Couvert jusqu’au</th><th class="num">Jours</th><th class="num">Dû estimé</th><th>Dernière relance</th><th>Statut</th><th></th></tr></thead>
        <?php foreach ($groupes as $label => $g): if (!$g) continue; ?>
          <tbody>
            <tr><td colspan="10" class="eyebrow" style="background:var(--grey-050)"><?= e($label) ?> · <?= count($g) ?></td></tr>
            <?php foreach ($g as $a): ?>
              <tr>
                <td><input type="checkbox" class="rel-cb" name="ids[]" value="<?= (int)$a['id'] ?>"></td>
                <td><a href="assure.php?id=<?= (int)$a['id'] ?>"><?= person($a['prenom'] . ' ' . $a['nom'], $a['police']) ?></a></td>
                <td class="mono nowrap"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $a['telephone'])) ?>"><?= e($a['telephone']) ?></a></td>
                <td><?= e($a['plan_nom']) ?></td>
                <td class="mono"><?= fdate($a['fin']) ?></td>
                <td class="num"><?= $a['jours'] > 0 ? '+' . $a['jours'] : $a['jours'] ?></td>
                <td class="num"><?= money($a['du']) ?></td>
                <td class="small muted"><?= $a['derniere_relance'] ? date('d/m/Y H:i', strtotime($a['derniere_relance'])) : 'Jamais' ?></td>
                <td><?= badge($a['statut']) ?></td>
                <td><a class="btn btn-secondary btn-sm" href="paiements.php?assure_id=<?= (int)$a['id'] ?>#encaisser">Encaisser</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        <?php endforeach; ?>
        <?php if (!$relances): ?><tbody><tr><td colspan="10"><div class="empty"><h3>Aucune relance</h3><p>Toutes les polices sont à jour.</p></div></td></tr></tbody><?php endif; ?>
      </table>
    </div>
  </section>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
