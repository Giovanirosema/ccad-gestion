<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
recalculer_statuts();

[$sc, $sp] = scope_dept('a');
$devise = devise();
$elig = (int)setting('regle_eligibilite', 24);

$total   = (int)scalar("SELECT COUNT(*) FROM assures a WHERE 1 $sc", $sp);
$actifs  = (int)scalar("SELECT COUNT(*) FROM assures a WHERE a.statut = 'Active' $sc", $sp);
$retard  = (int)scalar("SELECT COUNT(*) FROM assures a WHERE a.statut IN ('En retard','Suspendue') $sc", $sp);
$eligibles = (int)scalar("SELECT COUNT(*) FROM assures a WHERE a.statut NOT IN ('Résiliée','Décédé')
    AND a.adhesion <= DATE_SUB(CURDATE(), INTERVAL $elig MONTH) $sc", $sp);
$moisEnc = (float)scalar("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND YEAR(p.date_paiement) = YEAR(CURDATE()) AND MONTH(p.date_paiement) = MONTH(CURDATE()) $sc", $sp);
$moisPrec = (float)scalar("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND p.date_paiement >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
    AND p.date_paiement < DATE_FORMAT(CURDATE(), '%Y-%m-01') $sc", $sp);
$aValider = (int)scalar("SELECT COUNT(*) FROM paiements p JOIN assures a ON a.id = p.assure_id WHERE p.statut = 'À valider' $sc", $sp);

// Tendance : 9 derniers mois
$st = db()->prepare("SELECT DATE_FORMAT(p.date_paiement, '%Y-%m') ym, SUM(p.montant) total FROM paiements p
    JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND p.date_paiement >= DATE_FORMAT(CURDATE() - INTERVAL 8 MONTH, '%Y-%m-01') $sc
    GROUP BY ym ORDER BY ym");
$st->execute($sp);
$parMois = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$moisNoms = ['Janv', 'Févr', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];
$barres = [];
for ($i = 8; $i >= 0; $i--) {
    $d = (new DateTime('first day of this month'))->modify("-$i month");
    $barres[] = ['label' => $moisNoms[(int)$d->format('n') - 1], 'val' => (float)($parMois[$d->format('Y-m')] ?? 0)];
}
$max = max(array_column($barres, 'val')) ?: 1;

// Échéances / retards
$st = db()->prepare("SELECT a.*, p.nom plan_nom, p.prime FROM assures a LEFT JOIN plans p ON p.id = a.plan_id
    WHERE a.statut IN ('En retard','Suspendue') $sc ORDER BY a.statut, a.nom LIMIT 6");
$st->execute($sp);
$enRetard = $st->fetchAll();

// Adhésions récentes
$st = db()->prepare("SELECT a.*, p.nom plan_nom, p.prime FROM assures a LEFT JOIN plans p ON p.id = a.plan_id
    WHERE 1 $sc ORDER BY a.created_at DESC, a.id DESC LIMIT 7");
$st->execute($sp);
$recents = $st->fetchAll();

// Répartition par statut
$st = db()->prepare("SELECT a.statut, COUNT(*) n FROM assures a WHERE 1 $sc GROUP BY a.statut");
$st->execute($sp);
$parStatut = $st->fetchAll(PDO::FETCH_KEY_PAIR);

// Encaissements par plan (mois en cours)
$st = db()->prepare("SELECT pl.nom, pl.prime, COALESCE(SUM(p.montant),0) total FROM plans pl
    LEFT JOIN assures a ON a.plan_id = pl.id
    LEFT JOIN paiements p ON p.assure_id = a.id AND p.statut = 'Encaissé'
        AND YEAR(p.date_paiement) = YEAR(CURDATE()) AND MONTH(p.date_paiement) = MONTH(CURDATE())
    WHERE pl.actif = 1 GROUP BY pl.id ORDER BY total DESC LIMIT 5");
$st->execute();
$parPlan = $st->fetchAll();
$maxPlan = (float)max(array_column($parPlan, 'total') ?: [0]) ?: 1;

$journal = db()->query('SELECT * FROM audit ORDER BY id DESC LIMIT 6')->fetchAll();

$variation = $moisPrec > 0 ? round(($moisEnc - $moisPrec) / $moisPrec * 100, 1) : 0;

$page_title = 'Tableau de bord';
$active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="eyebrow">Vue d’ensemble</div>
    <h1>Tableau de bord</h1>
    <div class="meta">Données au <?= date('d/m/Y') ?> · échéance mensuelle le <?= e(setting('regle_echeance')) ?> · prochaine échéance <?= prochaine_echeance() ?></div>
  </div>
  <div class="actions">
    <?php if (can('rapports')): ?><a class="btn btn-secondary" href="rapports.php"><?= icon('chart', 16) ?> Voir les rapports</a><?php endif; ?>
  </div>
</div>

<div class="banner">
  <?= icon('shield') ?>
  <div style="flex:1 1 240px;font-size:13px"><?= e($u['nom']) ?> · <?= e($u['role']) ?> · <?= e($u['departement'] ?: '—') ?> / <?= e($u['commune'] ?: '—') ?> · <?= e($u['zone'] ?: '—') ?></div>
  <div class="actions">
    <?php if (can('assures')): ?><a class="btn btn-secondary btn-sm" href="adhesion.php"><?= icon('user-plus', 14) ?> Nouvelle adhésion</a><?php endif; ?>
    <?php if (can('paiements')): ?><a class="btn btn-secondary btn-sm" href="paiements.php#encaisser"><?= icon('card', 14) ?> Encaisser</a><?php endif; ?>
    <?php if (can('reclamations')): ?><a class="btn btn-secondary btn-sm" href="reclamations.php?action=nouveau"><?= icon('folder', 14) ?> Ouvrir un dossier</a><?php endif; ?>
  </div>
</div>

<div class="grid grid-stats">
  <?= stat_card('Assurés actifs', (string)$actifs, 'assurés', "$total dossiers au total", 'brand', 'users') ?>
  <?= stat_card('Encaissé ce mois', money($moisEnc), $devise, ($variation >= 0 ? '+' : '') . str_replace('.', ',', (string)$variation) . ' % vs mois précédent', '', 'card') ?>
  <?= stat_card('En retard (+' . setting('regle_retard') . ' jours)', (string)$retard, 'polices', "$aValider reçu(s) à valider", '', 'alert') ?>
  <?= stat_card("Éligibles ($elig mois atteints)", (string)$eligibles, 'assurés', 'Couverture mobilisable', '', 'shield') ?>
</div>

<div class="cols">
  <div class="col-main">
    <section class="card">
      <div class="card-head"><div><h2>Tendance des encaissements</h2><div class="sub">Neuf derniers mois · <?= e($devise) ?> · maximum <?= money($max) ?></div></div></div>
      <div class="card-body">
        <div class="bars">
          <?php foreach ($barres as $b): ?>
            <div class="bar-col" title="<?= e($b['label'] . ' — ' . money($b['val']) . ' ' . $devise) ?>">
              <div class="bar-area"><div class="bar<?= $b['val'] == $max ? ' max' : '' ?>" style="height:<?= max(3, round($b['val'] / $max * 100)) ?>%"></div></div>
              <div class="bar-label"><?= e($b['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card card-flush">
      <div class="card-head"><div><h2>Polices en retard</h2><div class="sub"><?= count($enRetard) ?> à relancer en priorité</div></div>
        <?php if (can('paiements')): ?><a class="btn btn-ghost btn-sm" href="relances.php">Relances</a><?php endif; ?></div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Assuré</th><th class="num">Cotisation</th><th class="num">Retard</th><th>Statut</th></tr></thead>
          <tbody>
          <?php foreach ($enRetard as $a): ?>
            <tr data-href="assure.php?id=<?= (int)$a['id'] ?>">
              <td><?= person($a['prenom'] . ' ' . $a['nom'], $a['police']) ?></td>
              <td class="num"><?= money($a['prime']) ?></td>
              <td class="num"><?= jours_retard($a) ?> j</td>
              <td><?= badge($a['statut']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$enRetard): ?><tr><td colspan="4" class="muted">Aucune police en retard.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card card-flush">
      <div class="card-head"><div><h2>Adhésions récentes</h2><div class="sub">Sept derniers dossiers enregistrés</div></div>
        <?php if (can('assures')): ?><a class="btn btn-ghost btn-sm" href="assures.php">Voir tous</a><?php endif; ?></div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Assuré</th><th>Plan</th><th class="num">Prime</th><th>Adhésion</th><th>Statut</th></tr></thead>
          <tbody>
          <?php foreach ($recents as $a): ?>
            <tr data-href="assure.php?id=<?= (int)$a['id'] ?>">
              <td><?= person($a['prenom'] . ' ' . $a['nom'], $a['police']) ?></td>
              <td><?= e($a['plan_nom']) ?></td>
              <td class="num"><?= money($a['prime']) ?></td>
              <td class="mono"><?= fdate($a['adhesion']) ?></td>
              <td><?= badge($a['statut']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="col-side">
    <section class="card card-brand">
      <div class="card-head"><div><h2>Encaissements par plan</h2><div class="sub">Mois en cours · <?= e($devise) ?></div></div></div>
      <div class="card-body stack">
        <?php foreach ($parPlan as $p): ?>
          <div>
            <div class="row" style="justify-content:space-between;font-size:12px;margin-bottom:5px">
              <span style="font-weight:500"><?= e($p['nom']) ?> · <?= money($p['prime']) ?></span><span class="mono"><?= money($p['total']) ?></span>
            </div>
            <div class="progress"><span style="width:<?= round($p['total'] / $maxPlan * 100) ?>%"></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Portefeuille par statut</h2><div class="sub"><?= $total ?> polices enregistrées</div></div></div>
      <div class="card-body stack">
        <?php foreach (['Active' => 'var(--green-600)', 'En retard' => 'var(--red-600)', 'En attente' => 'var(--amber-600)', 'Suspendue' => 'var(--grey-500)', 'Résiliée' => 'var(--grey-400)'] as $s => $c):
            $n = (int)($parStatut[$s] ?? 0); ?>
          <div class="row" style="gap:10px">
            <span style="width:8px;height:8px;border-radius:999px;background:<?= $c ?>"></span>
            <span style="flex:1;font-size:12px"><?= e($s) ?></span>
            <span class="mono small"><?= $n ?></span>
            <span class="mono small muted" style="width:42px;text-align:right"><?= $total ? round($n / $total * 100) : 0 ?> %</span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Journal d’activité</h2><div class="sub">Dernières opérations</div></div></div>
      <div class="card-body stack">
        <?php foreach ($journal as $j): ?>
          <div class="list-item">
            <div class="mono small muted" style="flex:0 0 42px"><?= date('H:i', strtotime($j['created_at'])) ?></div>
            <div><div class="t" style="font-size:12px"><?= e($j['type'] . ' — ' . $j['cible'] . ' · ' . $j['detail']) ?></div><div class="d"><?= e($j['user_nom']) ?></div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$journal): ?><div class="muted small">Aucune activité enregistrée.</div><?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
