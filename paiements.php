<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('paiements');
$u = current_user();
[$sc, $sp] = scope_dept('a');
$devise = devise();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');

    if ($action === 'encaisser') {
        $assureId = (int)post('assure_id');
        $mois = max(1, min(12, (int)post('mois', 1)));
        $montant = (float)str_replace([' ', ','], ['', '.'], post('montant'));
        $date = parse_date(post('date_paiement')) ?: date('Y-m-d');
        $mode = post('mode');
        $objet = post('objet') === 'Inscription' ? 'Inscription' : 'Cotisation';

        $st = db()->prepare("SELECT a.* FROM assures a WHERE a.id = ? $sc");
        $st->execute(array_merge([$assureId], $sp));
        $a = $st->fetch();
        $modes = array_column(modes_paiement(), 'nom');

        if (!$a) {
            flash('danger', 'Sélectionnez un assuré valide.');
        } elseif (in_array($a['statut'], ['Résiliée', 'Décédé'], true)) {
            flash('danger', "La police {$a['police']} est {$a['statut']} : aucun encaissement possible.");
        } elseif ($montant <= 0) {
            flash('danger', 'Le montant doit être supérieur à zéro.');
        } elseif (!in_array($mode, $modes, true)) {
            flash('danger', 'Mode de paiement invalide.');
        } elseif ($date > date('Y-m-d')) {
            flash('danger', 'La date de paiement ne peut pas être dans le futur.');
        } else {
            // Les paiements saisis par un agent de collecte doivent être validés au bureau
            $statut = $u['role'] === 'Agent de collecte' ? 'À valider' : 'Encaissé';
            $ref = next_reference('R', 'paiements');
            db()->prepare('INSERT INTO paiements (reference, assure_id, montant, devise, mode, date_paiement, objet, mois, statut, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $a['id'], $montant, $a['devise'], $mode, $date, $objet, $objet === 'Cotisation' ? $mois : 1, $statut, $u['id']]);
            $pid = (int)db()->lastInsertId();
            if ($statut === 'Encaissé' && in_array($a['statut'], ['En attente', 'En retard', 'Suspendue'], true)) {
                recalculer_statut_assure($a);
            }
            audit('Encaissement', $ref, money($montant) . " {$a['devise']} · {$a['prenom']} {$a['nom']} · $mode" . ($mois > 1 ? " · $mois mois" : ''));
            flash('success', "Reçu $ref enregistré" . ($statut === 'À valider' ? ' — en attente de validation au bureau.' : '.'));
            redirect('imprimer.php?doc=recu&id=' . $pid);
        }
        redirect('paiements.php?assure_id=' . $assureId . '#encaisser');
    }

    if (in_array($action, ['valider', 'annuler'], true)) {
        if ($u['role'] === 'Agent de collecte') {
            flash('danger', 'Action réservée au bureau.');
            redirect('paiements.php');
        }
        $st = db()->prepare("SELECT p.*, a.statut a_statut, a.adhesion, a.police FROM paiements p JOIN assures a ON a.id = p.assure_id WHERE p.id = ? $sc");
        $st->execute(array_merge([(int)post('paiement_id')], $sp));
        $p = $st->fetch();
        if ($p && $p['statut'] !== 'Annulé') {
            $nouveau = $action === 'valider' ? 'Encaissé' : 'Annulé';
            db()->prepare('UPDATE paiements SET statut = ? WHERE id = ?')->execute([$nouveau, $p['id']]);
            recalculer_statut_assure(['id' => $p['assure_id'], 'statut' => $p['a_statut'], 'adhesion' => $p['adhesion'], 'police' => $p['police']]);
            audit($action === 'valider' ? 'Validation' : 'Annulation', $p['reference'], 'Paiement ' . strtolower($nouveau) . ' · ' . money($p['montant']) . ' ' . $p['devise']);
            flash($action === 'valider' ? 'success' : 'warning', "Paiement {$p['reference']} : $nouveau.");
        }
        redirect('paiements.php?' . http_build_query(['statut' => get('statut'), 'du' => get('du'), 'au' => get('au')]));
    }
}

/* ---------- Filtres du journal ---------- */
$du = parse_date(get('du')) ?: date('Y-m-01');
$au = parse_date(get('au')) ?: date('Y-m-d');
$fStatut = get('statut');
$fMode = get('mode');
$where = "WHERE p.date_paiement BETWEEN ? AND ? $sc";
$params = array_merge([$du, $au], $sp);
if ($fStatut !== '') { $where .= ' AND p.statut = ?'; $params[] = $fStatut; }
if ($fMode !== '') { $where .= ' AND p.mode = ?'; $params[] = $fMode; }
if ($u['role'] === 'Agent de collecte') { $where .= ' AND p.created_by = ?'; $params[] = $u['id']; }

$journal = rows("SELECT p.*, a.prenom, a.nom, a.police, a.id aid, us.nom agent FROM paiements p
    JOIN assures a ON a.id = p.assure_id LEFT JOIN users us ON us.id = p.created_by
    $where ORDER BY p.date_paiement DESC, p.id DESC LIMIT 300", $params);
$totalPeriode = array_sum(array_map(fn($p) => $p['statut'] === 'Encaissé' ? $p['montant'] : 0, $journal));

/* ---------- Indicateurs ---------- */
$jour = (float)scalar("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND p.date_paiement = CURDATE() $sc", $sp);
$nbJour = (int)scalar("SELECT COUNT(*) FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut <> 'Annulé' AND p.date_paiement = CURDATE() $sc", $sp);
$mois = (float)scalar("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN assures a ON a.id = p.assure_id
    WHERE p.statut = 'Encaissé' AND p.date_paiement >= DATE_FORMAT(CURDATE(), '%Y-%m-01') $sc", $sp);
$aValider = (int)scalar("SELECT COUNT(*) FROM paiements p JOIN assures a ON a.id = p.assure_id WHERE p.statut = 'À valider' $sc", $sp);
$attendu = (float)scalar("SELECT COALESCE(SUM(pl.prime),0) FROM assures a JOIN plans pl ON pl.id = a.plan_id
    WHERE a.statut IN ('Active','En retard','Suspendue','En attente') $sc", $sp);

/* ---------- Collecte du jour par agent ---------- */
$collecte = rows("SELECT COALESCE(us.nom, 'Système') agent, COALESCE(us.zone, '—') zone, COUNT(*) n,
        SUM(CASE WHEN p.statut = 'Encaissé' THEN p.montant ELSE 0 END) encaisse,
        SUM(CASE WHEN p.statut = 'À valider' THEN p.montant ELSE 0 END) attente
    FROM paiements p JOIN assures a ON a.id = p.assure_id LEFT JOIN users us ON us.id = p.created_by
    WHERE p.date_paiement = CURDATE() AND p.statut <> 'Annulé' $sc GROUP BY us.id, us.nom, us.zone ORDER BY encaisse DESC", $sp);

/* ---------- Formulaire ---------- */
$assures = rows("SELECT a.id, a.prenom, a.nom, a.police, a.statut, a.devise, a.mode_paiement, pl.prime FROM assures a
    LEFT JOIN plans pl ON pl.id = a.plan_id WHERE a.statut NOT IN ('Résiliée','Décédé') $sc ORDER BY a.nom, a.prenom", $sp);
$selId = (int)get('assure_id');
$modes = modes_paiement();
$modeDefaut = setting('mode_defaut', 'moncash');
$nomModeDefaut = '';
foreach ($modes as $m) if ($m['code'] === $modeDefaut) $nomModeDefaut = $m['nom'];

$page_title = 'Paiements';
$active = 'paiements';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Paiements</div>
    <h1>Paiements</h1>
    <div class="meta">Encaissements, reçus et collecte du jour · prochaine échéance le <?= prochaine_echeance() ?></div>
  </div>
  <div class="actions">
    <?php if (can('rapports')): ?><a class="btn btn-secondary" href="export.php?<?= e(http_build_query(['type' => 'paiements', 'du' => $du, 'au' => $au])) ?>"><?= icon('download', 16) ?> Exporter CSV</a><?php endif; ?>
    <a class="btn btn-primary" href="#encaisser"><?= icon('card', 16) ?> Nouvel encaissement</a>
  </div>
</div>

<div class="grid grid-stats">
  <?= stat_card('Encaissé aujourd’hui', money($jour), $devise, $nbJour . ' reçu(s) émis', 'brand', 'card') ?>
  <?= stat_card('Encaissé ce mois', money($mois), $devise, $attendu > 0 ? round($mois / $attendu * 100) . ' % des primes attendues' : '', '', 'chart') ?>
  <?= stat_card('Primes attendues / mois', money($attendu), $devise, 'Portefeuille en vigueur', '', 'file') ?>
  <?= stat_card('À valider', (string)$aValider, 'paiement(s)', 'Saisis sur le terrain', '', 'alert') ?>
</div>

<div class="cols">
  <div class="col-main">
    <section class="card card-flush">
      <div class="card-head">
        <div><h2>Journal des encaissements</h2><div class="sub"><?= count($journal) ?> opération(s) · <?= money($totalPeriode) ?> <?= e($devise) ?> encaissés du <?= fdate($du) ?> au <?= fdate($au) ?></div></div>
        <form class="row" method="get">
          <input class="input mono" style="width:120px;height:32px" name="du" value="<?= fdate($du) ?>" placeholder="jj/mm/aaaa" title="Du">
          <input class="input mono" style="width:120px;height:32px" name="au" value="<?= fdate($au) ?>" placeholder="jj/mm/aaaa" title="Au">
          <select class="input" style="width:130px;height:32px" name="statut"><option value="">Tous statuts</option><?= options(['Encaissé', 'À valider', 'Annulé'], $fStatut, false) ?></select>
          <select class="input" style="width:130px;height:32px" name="mode"><option value="">Tous modes</option><?= options(array_column(modes_paiement(false), 'nom'), $fMode, false) ?></select>
          <button class="btn btn-secondary btn-sm">Filtrer</button>
        </form>
      </div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Reçu</th><th>Date</th><th>Assuré</th><th>Objet</th><th class="num">Montant</th><th>Mode</th><th>Agent</th><th>Statut</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($journal as $p): ?>
            <tr>
              <td class="mono"><?= e($p['reference']) ?></td>
              <td class="mono"><?= fdate($p['date_paiement']) ?></td>
              <td><a href="assure.php?id=<?= (int)$p['aid'] ?>&tab=paiements"><?= person($p['prenom'] . ' ' . $p['nom'], $p['police']) ?></a></td>
              <td><?= e($p['objet']) ?><?= $p['mois'] > 1 ? ' <span class="muted small">× ' . (int)$p['mois'] . ' mois</span>' : '' ?></td>
              <td class="num"><?= money($p['montant']) ?> <span class="muted small"><?= e($p['devise']) ?></span></td>
              <td><?= e($p['mode']) ?></td>
              <td class="small"><?= e($p['agent'] ?: '—') ?></td>
              <td><?= badge($p['statut']) ?></td>
              <td class="nowrap">
                <a class="icon-btn" target="_blank" title="Imprimer le reçu" href="imprimer.php?doc=recu&id=<?= (int)$p['id'] ?>"><?= icon('printer', 16) ?></a>
                <?php if ($u['role'] !== 'Agent de collecte' && $p['statut'] !== 'Annulé'): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="paiement_id" value="<?= (int)$p['id'] ?>">
                    <?php if ($p['statut'] === 'À valider'): ?>
                      <button class="icon-btn" name="action" value="valider" title="Valider"><?= icon('shield', 16) ?></button>
                    <?php endif; ?>
                    <button class="icon-btn danger" name="action" value="annuler" title="Annuler" data-confirm="Annuler le reçu <?= e($p['reference']) ?> ? L’opération reste visible au journal d’audit."><?= icon('trash', 16) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$journal): ?><tr><td colspan="9"><div class="empty"><h3>Aucun encaissement</h3><p>Aucune opération sur cette période.</p></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card card-flush">
      <div class="card-head"><div><h2>Collecte du jour</h2><div class="sub"><?= date('d/m/Y') ?> · par agent</div></div></div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Agent</th><th>Zone</th><th class="num">Reçus</th><th class="num">Encaissé</th><th class="num">À valider</th></tr></thead>
          <tbody>
          <?php foreach ($collecte as $c): ?>
            <tr><td><?= person($c['agent']) ?></td><td><?= e($c['zone']) ?></td><td class="num"><?= (int)$c['n'] ?></td>
              <td class="num"><?= money($c['encaisse']) ?></td><td class="num"><?= money($c['attente']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$collecte): ?><tr><td colspan="5" class="muted">Aucune collecte enregistrée aujourd’hui.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="col-side">
    <section class="card card-brand" id="encaisser">
      <div class="card-head"><div><h2>Enregistrer un paiement</h2><div class="sub">Le reçu s’ouvre pour impression</div></div></div>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="encaisser">
        <div class="field"><label>Assuré <span class="req">*</span></label>
          <select class="input" name="assure_id" required data-prime-select>
            <option value="">Sélectionner…</option>
            <?php foreach ($assures as $a): ?>
              <option value="<?= (int)$a['id'] ?>" data-prime="<?= (float)$a['prime'] ?>" data-devise="<?= e($a['devise']) ?>" data-mode="<?= e($a['mode_paiement']) ?>" <?= $selId === (int)$a['id'] ? 'selected' : '' ?>>
                <?= e($a['nom'] . ' ' . $a['prenom'] . ' · ' . $a['police'] . ($a['statut'] !== 'Active' ? ' · ' . $a['statut'] : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Objet</label>
          <select class="input" name="objet"><option>Cotisation</option><option>Inscription</option></select></div>
        <div class="form-row">
          <div class="field" style="flex:0 1 100px"><label>Mois</label><input class="input mono" type="number" min="1" max="12" name="mois" value="1" data-mois></div>
          <div class="field"><label>Montant <span class="req">*</span> <span class="muted small" data-devise-label><?= e($devise) ?></span></label><input class="input mono" name="montant" required data-montant></div>
        </div>
        <div class="field"><label>Mode de paiement</label>
          <select class="input" name="mode" data-mode-select>
            <?php foreach ($modes as $m): ?><option value="<?= e($m['nom']) ?>" data-code="<?= e($m['code']) ?>" <?= $m['nom'] === $nomModeDefaut ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Date du paiement</label><input class="input mono" name="date_paiement" value="<?= date('d/m/Y') ?>" placeholder="jj/mm/aaaa"></div>
        <?php if ($u['role'] === 'Agent de collecte'): ?>
          <div class="alert alert-info small">Vos encaissements sont marqués « À valider » jusqu’à leur contrôle au bureau.</div>
        <?php endif; ?>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('printer', 16) ?> Encaisser et imprimer le reçu</button>
      </form>
    </section>

    <section class="card">
      <div class="card-head"><h2>Moyens de paiement</h2></div>
      <div class="card-body">
        <?php foreach ($modes as $m): ?>
          <div class="list-item"><div><strong><?= e($m['nom']) ?></strong><div class="muted small"><?= e($m['type']) ?><?= $m['numero'] ? ' · <span class="mono">' . e($m['numero']) . '</span>' : '' ?></div></div>
            <?= $m['frais'] > 0 ? '<span class="mono small">' . money($m['frais']) . ' ' . e($devise) . '</span>' : '<span class="badge badge-success">Sans frais</span>' ?></div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
