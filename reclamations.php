<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('reclamations');
$u = current_user();
[$sc, $sp] = scope_dept('a');
$devise = devise();
$elig = (int)setting('regle_eligibilite', 24);
$peutValider = in_array($u['role'], ['Administrateur', 'Administrateur départemental'], true);
$action = get('action');
$id = (int)get('id');

/** Charge un assuré du territoire de l'utilisateur. */
function assure_scope(int $id): ?array
{
    [$sc, $sp] = scope_dept('a');
    $st = db()->prepare("SELECT a.*, pl.nom plan_nom, pl.prime, pl.capital FROM assures a LEFT JOIN plans pl ON pl.id = a.plan_id WHERE a.id = ? $sc");
    $st->execute(array_merge([$id], $sp));
    return $st->fetch() ?: null;
}

/** Arriérés = mois non couverts entre la fin de couverture et la date du décès × prime. */
function arrieres(array $a, string $dateDeces): float
{
    $fin = new DateTime(couvert_jusqua($a));
    $deces = new DateTime($dateDeces);
    if ($deces <= $fin) return 0;
    $d = $fin->diff($deces);
    return (float)(($d->y * 12 + $d->m + ($d->d > 0 ? 1 : 0)) * $a['prime']);
}

/* ---------- Enregistrement d'un nouveau dossier ---------- */
$erreurs = [];
$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'creer') {
    check_csrf();
    $old = $_POST;
    $a = assure_scope((int)post('assure_id'));
    $dateDeces = parse_date(post('date_deces'));
    if (!$a) $erreurs[] = 'Sélectionnez l’assuré décédé.';
    elseif (in_array($a['statut'], ['Décédé', 'Résiliée'], true)) $erreurs[] = "La police {$a['police']} est déjà {$a['statut']}.";
    elseif ((int)scalar("SELECT COUNT(*) FROM reclamations WHERE assure_id = ? AND statut <> 'Rejeté'", [$a['id']])) $erreurs[] = 'Un dossier est déjà ouvert pour cet assuré.';
    if (!$dateDeces) $erreurs[] = 'Date du décès invalide (jj/mm/aaaa).';
    elseif ($dateDeces > date('Y-m-d')) $erreurs[] = 'La date du décès ne peut pas être dans le futur.';
    elseif ($a && $dateDeces < $a['adhesion']) $erreurs[] = 'Le décès est antérieur à l’adhésion.';
    if (post('declarant_nom') === '') $erreurs[] = 'Le nom du déclarant est obligatoire.';
    if (post('declarant_tel') === '') $erreurs[] = 'Le téléphone du déclarant est obligatoire.';

    if (!$erreurs) {
        $pieces = array_values(array_intersect((array)($_POST['pieces'] ?? []), PIECES_RECLAMATION));
        $services = array_values(array_intersect((array)($_POST['services'] ?? []), array_column(rows('SELECT nom FROM services WHERE actif = 1'), 'nom')));
        // Éligible si le délai de carence était atteint au jour du décès
        $ecart = (new DateTime($a['adhesion']))->diff(new DateTime($dateDeces));
        $eligible = $ecart->y * 12 + $ecart->m >= $elig;
        $complet = count($pieces) === count(PIECES_RECLAMATION);
        $ref = next_reference('D', 'reclamations');
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO reclamations (reference, assure_id, date_deces, lieu_deces, cause, acte_deces, medecin, declarant_nom, declarant_lien,
                declarant_tel, temoin1, temoin2, services, capital, arrieres, frais_dossier, pieces, statut, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$ref, $a['id'], $dateDeces, post('lieu_deces') ?: null, post('cause') ?: null, post('acte_deces') ?: null,
                post('medecin') ?: null, post('declarant_nom'), post('declarant_lien') ?: null, post('declarant_tel'),
                post('temoin1') ?: null, post('temoin2') ?: null, json_encode($services, JSON_UNESCAPED_UNICODE),
                $eligible ? $a['capital'] : 0, arrieres($a, $dateDeces), 1500,
                json_encode($pieces, JSON_UNESCAPED_UNICODE), $complet ? 'À valider' : 'En attente', $u['id']]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE assures SET statut = 'Décédé' WHERE id = ?")->execute([$a['id']]);
        audit('Réclamation', $ref, "Dossier ouvert · {$a['prenom']} {$a['nom']} · décès le " . fdate($dateDeces) . ($eligible ? '' : ' · non éligible'));
        $pdo->commit();
        flash($eligible ? 'success' : 'warning', "Dossier $ref ouvert." . ($eligible ? '' : " Attention : délai de carence de $elig mois non atteint au jour du décès."));
        redirect('reclamations.php?id=' . $rid);
    }
    $action = 'nouveau';
}

/* ---------- Actions sur un dossier ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    check_csrf();
    $st = db()->prepare("SELECT r.*, a.police FROM reclamations r JOIN assures a ON a.id = r.assure_id WHERE r.id = ? $sc");
    $st->execute(array_merge([$id], $sp));
    $r = $st->fetch();
    if ($r) {
        $act = post('action');
        if ($act === 'doc_up') {
            try {
                if (!in_array(post('piece'), PIECES_RECLAMATION, true)) throw new RuntimeException('Choisissez le type de pièce.');
                if (in_array($r['statut'], ['Versé', 'Rejeté'], true) && !$peutValider) throw new RuntimeException('Dossier clos : ajout réservé aux administrateurs.');
                enregistrer_document('document', (int)$r['assure_id'], post('piece'), $id);
                audit('Pièce ajoutée', $r['reference'], post('piece') . ' · ' . mb_substr((string)($_FILES['document']['name'] ?? ''), 0, 80));
                // La pièce reçue est cochée ; dossier complet → prêt pour validation
                $pieces = json_decode($r['pieces'] ?: '[]', true) ?: [];
                if (!in_array(post('piece'), $pieces, true)) $pieces[] = post('piece');
                $statut = $r['statut'];
                if (in_array($statut, ['En attente', 'À valider'], true)) $statut = count($pieces) >= count(PIECES_RECLAMATION) ? 'À valider' : 'En attente';
                db()->prepare('UPDATE reclamations SET pieces = ?, statut = ? WHERE id = ?')->execute([json_encode(array_values($pieces), JSON_UNESCAPED_UNICODE), $statut, $id]);
                flash('success', 'Fichier ajouté : ' . post('piece') . '.' . ($statut === 'À valider' && $r['statut'] !== 'À valider' ? ' Dossier complet : prêt pour validation.' : ''));
            } catch (RuntimeException $ex) {
                flash('danger', $ex->getMessage());
            }
        } elseif ($act === 'doc_del') {
            if (!peut_supprimer_document()) {
                flash('danger', 'Seul un administrateur peut retirer une pièce.');
            } elseif (scalar('SELECT COUNT(*) FROM documents WHERE id = ? AND reclamation_id = ?', [(int)post('doc_id'), $id])) {
                $d = supprimer_document((int)post('doc_id'));
                audit('Pièce retirée', $r['reference'], $d['piece'] . ' · ' . $d['nom_original']);
                flash('warning', 'Fichier retiré du dossier. Mettez à jour la liste des pièces si nécessaire.');
            }
        } elseif ($act === 'pieces' && in_array($r['statut'], ['En attente', 'À valider'], true)) {
            $pieces = array_values(array_intersect((array)($_POST['pieces'] ?? []), PIECES_RECLAMATION));
            $statut = count($pieces) === count(PIECES_RECLAMATION) ? 'À valider' : 'En attente';
            db()->prepare('UPDATE reclamations SET pieces = ?, statut = ? WHERE id = ?')->execute([json_encode($pieces, JSON_UNESCAPED_UNICODE), $statut, $id]);
            audit('Réclamation', $r['reference'], 'Pièces mises à jour (' . count($pieces) . '/' . count(PIECES_RECLAMATION) . ')');
            flash('success', 'Pièces enregistrées.' . ($statut === 'À valider' ? ' Dossier complet : prêt pour validation.' : ''));
        } elseif ($act === 'valider' && $peutValider && $r['statut'] === 'À valider') {
            if ($r['capital'] <= 0) {
                flash('danger', 'Dossier non éligible : il ne peut qu’être rejeté.');
            } else {
                db()->prepare("UPDATE reclamations SET statut = 'Validé' WHERE id = ?")->execute([$id]);
                audit('Validation', $r['reference'], 'Dossier validé · net ' . money($r['capital'] - $r['arrieres'] - $r['frais_dossier']) . ' ' . $devise);
                flash('success', "Dossier {$r['reference']} validé.");
            }
        } elseif ($act === 'verser' && $peutValider && $r['statut'] === 'Validé') {
            db()->prepare("UPDATE reclamations SET statut = 'Versé' WHERE id = ?")->execute([$id]);
            audit('Versement', $r['reference'], 'Capital versé aux bénéficiaires');
            flash('success', "Versement du dossier {$r['reference']} enregistré.");
        } elseif ($act === 'rejeter' && $peutValider && in_array($r['statut'], ['En attente', 'À valider'], true)) {
            $motif = post('motif');
            if ($motif === '') {
                flash('danger', 'Indiquez le motif du rejet.');
            } else {
                db()->prepare("UPDATE reclamations SET statut = 'Rejeté', motif_rejet = ? WHERE id = ?")->execute([mb_substr($motif, 0, 255), $id]);
                audit('Rejet', $r['reference'], 'Dossier rejeté : ' . $motif);
                flash('warning', "Dossier {$r['reference']} rejeté.");
            }
        }
    }
    redirect('reclamations.php?id=' . $id);
}

$page_title = 'Réclamations';
$active = 'reclamations';

/* =====================================================================
   Nouveau dossier
   ===================================================================== */
if ($action === 'nouveau') {
    $assures = rows("SELECT a.id, a.prenom, a.nom, a.police, a.adhesion FROM assures a
        WHERE a.statut NOT IN ('Décédé','Résiliée') $sc ORDER BY a.nom, a.prenom", $sp);
    $selId = (int)($old['assure_id'] ?? get('assure_id'));
    $sel = $selId ? assure_scope($selId) : null;
    $services = rows('SELECT * FROM services WHERE actif = 1 ORDER BY id');
    $val = fn($k) => e($old[$k] ?? '');
    require __DIR__ . '/includes/header.php';
    ?>
    <form method="post" class="stack" style="gap:24px">
      <?= csrf_field() ?><input type="hidden" name="action" value="creer">
      <div class="page-head">
        <div>
          <div class="crumbs"><a href="reclamations.php">Réclamations</a> › Nouveau dossier</div>
          <h1>Déclaration de décès</h1>
          <div class="meta">Ouverture d’un dossier de réclamation · la police passe au statut « Décédé »</div>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="reclamations.php">Annuler</a>
          <button class="btn btn-primary" type="submit"><?= icon('folder', 16) ?> Ouvrir le dossier</button>
        </div>
      </div>
      <?php if ($erreurs): ?>
        <div class="alert alert-danger"><strong>Le dossier n’a pas été ouvert :</strong><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($erreurs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <div class="cols">
        <div class="col-main">
          <section class="card">
            <div class="card-head"><h2>Assuré décédé</h2></div>
            <div class="card-body grid grid-form">
              <div class="field" style="grid-column:1/-1"><label>Assuré <span class="req">*</span></label>
                <select class="input" name="assure_id" required onchange="location.href='reclamations.php?action=nouveau&assure_id='+this.value">
                  <option value="">Sélectionner…</option>
                  <?php foreach ($assures as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $selId === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['nom'] . ' ' . $a['prenom'] . ' · ' . $a['police'] . ' · adhésion ' . fdate($a['adhesion'])) ?></option><?php endforeach; ?>
                </select></div>
              <div class="field"><label>Date du décès <span class="req">*</span></label><input class="input mono" name="date_deces" required placeholder="jj/mm/aaaa" value="<?= $val('date_deces') ?>"></div>
              <div class="field"><label>Lieu du décès</label><input class="input" name="lieu_deces" value="<?= $val('lieu_deces') ?>"></div>
              <div class="field"><label>Cause</label><select class="input" name="cause"><?= options(['Maladie', 'Mort naturelle', 'Accident', 'Autre'], $old['cause'] ?? '', false) ?></select></div>
              <div class="field"><label>N° acte de décès</label><input class="input mono" name="acte_deces" value="<?= $val('acte_deces') ?>"></div>
              <div class="field"><label>Médecin / officier d’état civil</label><input class="input" name="medecin" value="<?= $val('medecin') ?>"></div>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2>Déclarant et témoins</h2></div>
            <div class="card-body grid grid-form">
              <div class="field"><label>Nom du déclarant <span class="req">*</span></label><input class="input" name="declarant_nom" required value="<?= $val('declarant_nom') ?>"></div>
              <div class="field"><label>Lien avec le défunt</label><select class="input" name="declarant_lien"><?= options(LIENS, $old['declarant_lien'] ?? 'Enfant', false) ?></select></div>
              <div class="field"><label>Téléphone <span class="req">*</span></label><input class="input mono" name="declarant_tel" required value="<?= $val('declarant_tel') ?>"></div>
              <div class="field"><label>Témoin 1</label><input class="input" name="temoin1" placeholder="Nom · téléphone" value="<?= $val('temoin1') ?>"></div>
              <div class="field"><label>Témoin 2</label><input class="input" name="temoin2" placeholder="Nom · téléphone" value="<?= $val('temoin2') ?>"></div>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2>Services funéraires demandés</h2></div>
            <div class="card-body row" style="flex-wrap:wrap;gap:16px">
              <?php foreach ($services as $s): ?>
                <label class="check"><input type="checkbox" name="services[]" value="<?= e($s['nom']) ?>" <?= in_array($s['nom'], $old['services'] ?? [], true) ? 'checked' : '' ?>> <?= e($s['nom']) ?> <span class="muted small">· <?= e($s['mode']) ?></span></label>
              <?php endforeach; ?>
            </div>
          </section>
        </div>
        <div class="col-side">
          <?php if ($sel): $m = mois_ecoules($sel['adhesion']); ?>
            <section class="card card-brand">
              <div class="card-head"><h2>Police <?= e($sel['police']) ?></h2></div>
              <div class="card-body">
                <dl class="kv">
                  <dt>Plan</dt><dd><?= e($sel['plan_nom']) ?></dd>
                  <dt>Capital</dt><dd class="mono"><?= money($sel['capital']) ?> <?= e($sel['devise']) ?></dd>
                  <dt>Adhésion</dt><dd class="mono"><?= fdate($sel['adhesion']) ?> (<?= $m ?> mois)</dd>
                  <dt>Couvert jusqu’au</dt><dd class="mono"><?= fdate(couvert_jusqua($sel)) ?></dd>
                  <dt>Statut</dt><dd><?= badge($sel['statut']) ?></dd>
                </dl>
                <?php if ($m < $elig): ?><div class="alert alert-warning small" style="margin-top:12px">Délai de carence de <?= $elig ?> mois non atteint : le capital ne sera pas dû.</div><?php endif; ?>
              </div>
            </section>
          <?php endif; ?>
          <section class="card">
            <div class="card-head"><h2>Pièces reçues</h2></div>
            <div class="card-body stack">
              <?php foreach (PIECES_RECLAMATION as $pc): ?>
                <label class="check"><input type="checkbox" name="pieces[]" value="<?= e($pc) ?>" <?= in_array($pc, $old['pieces'] ?? [], true) ? 'checked' : '' ?>> <?= e($pc) ?></label>
              <?php endforeach; ?>
              <div class="muted small">Dossier complet → statut « À valider ». Sinon « En attente ».</div>
            </div>
          </section>
        </div>
      </div>
    </form>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* =====================================================================
   Détail d'un dossier
   ===================================================================== */
if ($id) {
    $st = db()->prepare("SELECT r.*, a.prenom, a.nom, a.police, a.adhesion, a.naissance, a.commune, a.devise, a.id aid, pl.nom plan_nom, us.nom agent
        FROM reclamations r JOIN assures a ON a.id = r.assure_id LEFT JOIN plans pl ON pl.id = a.plan_id LEFT JOIN users us ON us.id = r.created_by
        WHERE r.id = ? $sc");
    $st->execute(array_merge([$id], $sp));
    $r = $st->fetch();
    if (!$r) { flash('danger', 'Dossier introuvable.'); redirect('reclamations.php'); }
    $pieces = json_decode($r['pieces'] ?: '[]', true) ?: [];
    $services = json_decode($r['services'] ?: '[]', true) ?: [];
    $benefs = rows('SELECT * FROM beneficiaires WHERE assure_id = ? ORDER BY part DESC', [$r['aid']]);
    $net = max(0, $r['capital'] - $r['arrieres'] - $r['frais_dossier']);
    $historique = rows("SELECT * FROM audit WHERE cible = ? ORDER BY id DESC", [$r['reference']]);
    $page_title = 'Dossier ' . $r['reference'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
      <div>
        <div class="crumbs"><a href="reclamations.php">Réclamations</a> › <?= e($r['reference']) ?></div>
        <h1>Dossier <?= e($r['reference']) ?> · <?= e($r['prenom'] . ' ' . $r['nom']) ?></h1>
        <div class="meta">Police <span class="mono"><?= e($r['police']) ?></span> · décès le <?= fdate($r['date_deces']) ?> · ouvert par <?= e($r['agent'] ?: '—') ?> · <?= badge($r['statut']) ?></div>
      </div>
      <div class="actions">
        <a class="btn btn-secondary" target="_blank" href="imprimer.php?doc=reclamation&id=<?= $id ?>"><?= icon('printer', 16) ?> Imprimer le dossier</a>
        <a class="btn btn-secondary" href="assure.php?id=<?= (int)$r['aid'] ?>">Fiche de l’assuré</a>
      </div>
    </div>

    <?php if ($r['statut'] === 'Rejeté'): ?><div class="alert alert-danger"><strong>Dossier rejeté :</strong> <?= e($r['motif_rejet']) ?></div><?php endif; ?>
    <?php if ($r['capital'] <= 0 && $r['statut'] !== 'Rejeté'): ?><div class="alert alert-warning">Délai de carence non atteint au jour du décès : aucun capital n’est dû sur ce dossier.</div><?php endif; ?>

    <div class="cols">
      <div class="col-main">
        <section class="card">
          <div class="card-head"><h2>Décès</h2></div>
          <div class="card-body"><dl class="dl">
            <div><dt>Défunt</dt><dd><?= e($r['prenom'] . ' ' . $r['nom']) ?></dd></div>
            <div><dt>Date de naissance</dt><dd><?= fdate($r['naissance']) ?></dd></div>
            <div><dt>Date du décès</dt><dd><?= fdate($r['date_deces']) ?></dd></div>
            <div><dt>Lieu</dt><dd><?= e($r['lieu_deces'] ?: '—') ?></dd></div>
            <div><dt>Cause</dt><dd><?= e($r['cause'] ?: '—') ?></dd></div>
            <div><dt>Acte de décès</dt><dd class="mono"><?= e($r['acte_deces'] ?: '—') ?></dd></div>
            <div><dt>Médecin / officier</dt><dd><?= e($r['medecin'] ?: '—') ?></dd></div>
            <div><dt>Déclarant</dt><dd><?= e($r['declarant_nom']) ?> (<?= e($r['declarant_lien'] ?: '—') ?>) · <span class="mono"><?= e($r['declarant_tel']) ?></span></dd></div>
            <div><dt>Témoins</dt><dd><?= e(implode(' · ', array_filter([$r['temoin1'], $r['temoin2']])) ?: '—') ?></dd></div>
            <div><dt>Services demandés</dt><dd><?= e($services ? implode(', ', $services) : '—') ?></dd></div>
          </dl></div>
        </section>

        <section class="card card-flush">
          <div class="card-head"><div><h2>Versement aux bénéficiaires</h2><div class="sub">Répartition du net à verser selon les parts déclarées</div></div></div>
          <div class="card-body table-wrap">
            <table class="table">
              <thead><tr><th>Bénéficiaire</th><th>Téléphone</th><th class="num">Part</th><th class="num">Montant</th></tr></thead>
              <tbody>
              <?php foreach ($benefs as $b): ?>
                <tr><td><?= person($b['nom'], $b['lien']) ?></td><td class="mono"><?= e($b['telephone'] ?: '—') ?></td>
                  <td class="num"><?= (float)$b['part'] ?> %</td><td class="num"><?= money($net * $b['part'] / 100) ?> <?= e($r['devise']) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$benefs): ?><tr><td colspan="4" class="muted">Aucun bénéficiaire déclaré sur la police.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="card">
          <div class="card-head"><h2>Historique du dossier</h2></div>
          <div class="card-body">
            <?php foreach ($historique as $h): ?>
              <div class="list-item"><div><strong><?= e($h['type']) ?></strong> · <?= e($h['detail']) ?><div class="muted small"><?= e($h['user_nom']) ?></div></div>
                <span class="mono small muted"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></span></div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <div class="col-side">
        <section class="card card-brand">
          <div class="card-head"><h2>Décompte</h2></div>
          <div class="card-body">
            <dl class="kv">
              <dt>Capital (<?= e($r['plan_nom']) ?>)</dt><dd class="mono"><?= money($r['capital']) ?></dd>
              <dt>Arriérés de cotisation</dt><dd class="mono">− <?= money($r['arrieres']) ?></dd>
              <dt>Frais de dossier</dt><dd class="mono">− <?= money($r['frais_dossier']) ?></dd>
              <dt><strong>Net à verser</strong></dt><dd class="mono"><strong><?= money($net) ?> <?= e($r['devise']) ?></strong></dd>
            </dl>
          </div>
        </section>

        <section class="card">
          <div class="card-head"><div><h2>Pièces du dossier</h2><div class="sub"><?= count($pieces) ?> / <?= count(PIECES_RECLAMATION) ?> reçues</div></div></div>
          <form method="post" class="card-body stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="pieces">
            <?php $modifiable = in_array($r['statut'], ['En attente', 'À valider'], true); ?>
            <?php foreach (PIECES_RECLAMATION as $pc): ?>
              <label class="check"><input type="checkbox" name="pieces[]" value="<?= e($pc) ?>" <?= in_array($pc, $pieces, true) ? 'checked' : '' ?> <?= $modifiable ? '' : 'disabled' ?>> <?= e($pc) ?></label>
            <?php endforeach; ?>
            <?php if ($modifiable): ?><button class="btn btn-secondary btn-sm">Mettre à jour</button><?php endif; ?>
          </form>
        </section>

        <?php $docsRecl = rows('SELECT * FROM documents WHERE reclamation_id = ? ORDER BY id DESC', [$id]); ?>
        <section class="card">
          <div class="card-head"><div><h2>Fichiers du dossier</h2><div class="sub"><?= count($docsRecl) ?> pièce(s) numérisée(s)</div></div><?= icon('file', 18) ?></div>
          <div class="card-body stack"><?= bloc_documents($docsRecl, PIECES_RECLAMATION, 'doc_up', 'doc_del') ?></div>
        </section>

        <?php if ($peutValider && in_array($r['statut'], ['En attente', 'À valider', 'Validé'], true)): ?>
          <section class="card">
            <div class="card-head"><h2>Décision</h2></div>
            <div class="card-body stack">
              <?php if ($r['statut'] === 'À valider' && $r['capital'] > 0): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="valider">
                  <button class="btn btn-primary btn-block" data-confirm="Valider le dossier et le net de <?= money($net) ?> <?= e($r['devise']) ?> ?"><?= icon('shield', 16) ?> Valider le dossier</button></form>
              <?php elseif ($r['statut'] === 'En attente'): ?>
                <div class="muted small">Validation possible une fois toutes les pièces reçues.</div>
              <?php endif; ?>
              <?php if ($r['statut'] === 'Validé'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verser">
                  <button class="btn btn-accent btn-block" data-confirm="Confirmer le versement de <?= money($net) ?> <?= e($r['devise']) ?> aux bénéficiaires ?"><?= icon('card', 16) ?> Marquer comme versé</button></form>
              <?php else: ?>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="rejeter">
                  <div class="field"><label>Motif du rejet</label><input class="input" name="motif" required></div>
                  <button class="btn btn-danger btn-block" data-confirm="Rejeter ce dossier ?">Rejeter le dossier</button></form>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* =====================================================================
   Liste
   ===================================================================== */
$fStatut = get('statut');
$where = "WHERE 1 $sc";
$params = $sp;
if ($fStatut !== '') { $where .= ' AND r.statut = ?'; $params[] = $fStatut; }
$liste = rows("SELECT r.*, a.prenom, a.nom, a.police, a.commune, a.devise FROM reclamations r JOIN assures a ON a.id = r.assure_id
    $where ORDER BY FIELD(r.statut, 'À valider', 'En attente', 'Validé', 'Versé', 'Rejeté'), r.id DESC", $params);
$compte = [];
foreach (rows("SELECT r.statut, COUNT(*) n, SUM(GREATEST(r.capital - r.arrieres - r.frais_dossier, 0)) net
    FROM reclamations r JOIN assures a ON a.id = r.assure_id WHERE 1 $sc GROUP BY r.statut", $sp) as $c) $compte[$c['statut']] = $c;
$verseAnnee = (float)scalar("SELECT COALESCE(SUM(GREATEST(r.capital - r.arrieres - r.frais_dossier, 0)),0) FROM reclamations r JOIN assures a ON a.id = r.assure_id
    WHERE r.statut = 'Versé' AND YEAR(r.date_deces) = YEAR(CURDATE()) $sc", $sp);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Réclamations</div>
    <h1>Réclamations</h1>
    <div class="meta">Dossiers de décès · validation par un administrateur</div>
  </div>
  <div class="actions"><a class="btn btn-primary" href="?action=nouveau"><?= icon('folder', 16) ?> Nouveau dossier</a></div>
</div>

<div class="grid grid-stats">
  <?= stat_card('À valider', (string)($compte['À valider']['n'] ?? 0), 'dossier(s)', money($compte['À valider']['net'] ?? 0) . ' ' . $devise . ' en jeu', 'brand', 'alert') ?>
  <?= stat_card('Pièces manquantes', (string)($compte['En attente']['n'] ?? 0), 'dossier(s)', 'En attente de documents', '', 'folder') ?>
  <?= stat_card('Validés à verser', (string)($compte['Validé']['n'] ?? 0), 'dossier(s)', money($compte['Validé']['net'] ?? 0) . ' ' . $devise, '', 'card') ?>
  <?= stat_card('Versé cette année', money($verseAnnee), $devise, ($compte['Versé']['n'] ?? 0) . ' dossier(s) au total', '', 'shield') ?>
</div>

<section class="card card-flush">
  <div class="card-head">
    <div><h2>Dossiers</h2><div class="sub"><?= count($liste) ?> dossier(s)</div></div>
    <form class="row" method="get">
      <select class="input" style="width:160px;height:32px" name="statut" onchange="this.form.submit()"><option value="">Tous statuts</option>
        <?= options(['En attente', 'À valider', 'Validé', 'Versé', 'Rejeté'], $fStatut, false) ?></select>
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table">
      <thead><tr><th>Dossier</th><th>Défunt</th><th>Décès</th><th>Déclarant</th><th class="num">Pièces</th><th class="num">Net à verser</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $r): $pc = count(json_decode($r['pieces'] ?: '[]', true) ?: []); ?>
        <tr data-href="?id=<?= (int)$r['id'] ?>">
          <td class="mono"><?= e($r['reference']) ?></td>
          <td><?= person($r['prenom'] . ' ' . $r['nom'], $r['police']) ?></td>
          <td class="mono"><?= fdate($r['date_deces']) ?></td>
          <td><?= e($r['declarant_nom']) ?> <span class="muted small">· <?= e($r['declarant_lien']) ?></span></td>
          <td class="num"><?= $pc ?>/<?= count(PIECES_RECLAMATION) ?></td>
          <td class="num"><?= money(max(0, $r['capital'] - $r['arrieres'] - $r['frais_dossier'])) ?> <span class="muted small"><?= e($r['devise']) ?></span></td>
          <td><?= badge($r['statut']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$liste): ?><tr><td colspan="7"><div class="empty"><h3>Aucun dossier</h3><p>Aucune réclamation enregistrée.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
