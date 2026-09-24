<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('assures');
$u = current_user();

[$sc, $sp] = scope_dept('a');
$id = (int)get('id', post('id'));
$st = db()->prepare("SELECT a.*, p.nom plan_nom, p.prime, p.capital, f.nom formule_nom FROM assures a
    LEFT JOIN plans p ON p.id = a.plan_id LEFT JOIN formules f ON f.id = a.formule_id WHERE a.id = ? $sc");
$st->execute(array_merge([$id], $sp));
$a = $st->fetch();
if (!$a) {
    flash('danger', 'Dossier introuvable ou hors de votre territoire.');
    redirect('assures.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    try {
        switch ($action) {
            case 'photo':
                $photo = upload_photo('photo');
                if ($photo) {
                    if ($a['photo']) @unlink(UPLOAD_DIR . $a['photo']);
                    db()->prepare('UPDATE assures SET photo = ? WHERE id = ?')->execute([$photo, $id]);
                    audit('Modification', $a['reference'], 'Photo d’identité mise à jour');
                    flash('success', 'Photo enregistrée — reprise sur les fiches imprimées.');
                }
                break;
            case 'add_benef':
                if (post('nom') === '') throw new RuntimeException('Le nom du bénéficiaire est obligatoire.');
                $part = (float)str_replace(',', '.', post('part'));
                $deja = (float)scalar('SELECT COALESCE(SUM(part),0) FROM beneficiaires WHERE assure_id = ?', [$id]);
                if ($part <= 0 || $deja + $part > 100.001) throw new RuntimeException('La répartition dépasserait 100 % (déjà ' . $deja . ' %).');
                db()->prepare('INSERT INTO beneficiaires (assure_id, nom, lien, naissance, part, telephone) VALUES (?,?,?,?,?,?)')
                    ->execute([$id, post('nom'), post('lien') ?: 'Autre parent', parse_date(post('naissance')), $part, post('telephone') ?: null]);
                audit('Modification', $a['reference'], 'Bénéficiaire ajouté : ' . post('nom'));
                flash('success', 'Bénéficiaire ajouté.');
                break;
            case 'edit_benef':
                $bid = (int)post('benef_id');
                $ancien = rows('SELECT * FROM beneficiaires WHERE id = ? AND assure_id = ?', [$bid, $id])[0] ?? null;
                if (!$ancien) throw new RuntimeException('Bénéficiaire introuvable.');
                if (post('nom') === '') throw new RuntimeException('Le nom du bénéficiaire est obligatoire.');
                if (post('lien') !== '' && !in_array(post('lien'), LIENS, true)) throw new RuntimeException('Lien de parenté invalide.');
                if (post('naissance') !== '' && !parse_date(post('naissance'))) throw new RuntimeException('Date de naissance invalide (jj/mm/aaaa).');
                $part = (float)str_replace(',', '.', post('part'));
                $autres = (float)scalar('SELECT COALESCE(SUM(part),0) FROM beneficiaires WHERE assure_id = ? AND id <> ?', [$id, $bid]);
                if ($part <= 0 || $autres + $part > 100.001) throw new RuntimeException('La répartition dépasserait 100 % (autres bénéficiaires : ' . $autres . ' %).');
                db()->prepare('UPDATE beneficiaires SET nom = ?, lien = ?, naissance = ?, part = ?, telephone = ? WHERE id = ? AND assure_id = ?')
                    ->execute([post('nom'), post('lien') ?: 'Autre parent', parse_date(post('naissance')), $part, post('telephone') ?: null, $bid, $id]);
                audit('Modification', $a['reference'], "Bénéficiaire modifié : {$ancien['nom']} ({$ancien['part']} %) → " . post('nom') . " ($part %)");
                flash('success', 'Bénéficiaire modifié.');
                break;
            case 'del_benef':
                $ancien = rows('SELECT nom FROM beneficiaires WHERE id = ? AND assure_id = ?', [(int)post('benef_id'), $id])[0] ?? null;
                db()->prepare('DELETE FROM beneficiaires WHERE id = ? AND assure_id = ?')->execute([(int)post('benef_id'), $id]);
                audit('Modification', $a['reference'], 'Bénéficiaire retiré : ' . ($ancien['nom'] ?? '?'));
                flash('warning', 'Bénéficiaire retiré. Vérifiez que la répartition totalise 100 %.');
                break;

            /* ----- Membres couverts (plan familial) ----- */
            case 'add_membre':
            case 'edit_membre':
                if ($a['type_police'] !== 'famille') throw new RuntimeException('Les membres ne concernent que les plans familiaux.');
                $mid = (int)post('membre_id');
                if ($action === 'add_membre' && (int)scalar('SELECT COUNT(*) FROM membres WHERE assure_id = ?', [$id]) >= 8) {
                    throw new RuntimeException('Huit membres au maximum par police familiale.');
                }
                if (post('nom') === '') throw new RuntimeException('Le nom du membre est obligatoire.');
                if (post('lien') !== '' && !in_array(post('lien'), LIENS, true)) throw new RuntimeException('Lien de parenté invalide.');
                if (post('naissance') !== '' && !parse_date(post('naissance'))) throw new RuntimeException('Date de naissance invalide (jj/mm/aaaa).');
                $part = (float)str_replace(',', '.', post('part'));
                $autres = (float)scalar('SELECT COALESCE(SUM(part),0) FROM membres WHERE assure_id = ? AND id <> ?', [$id, $mid]);
                if ($part < 0 || $autres + $part > 100.001) throw new RuntimeException('La répartition de la couverture dépasserait 100 % (autres membres : ' . $autres . ' %).');
                if (mb_strlen(post('motif')) < 5) throw new RuntimeException('Indiquez le motif (nouvelle entente, erreur de saisie…).');
                if ($action === 'add_membre') {
                    db()->prepare('INSERT INTO membres (assure_id, nom, lien, naissance, part) VALUES (?,?,?,?,?)')
                        ->execute([$id, post('nom'), post('lien') ?: 'Enfant', parse_date(post('naissance')), $part]);
                    audit('Modification', $a['reference'], 'Membre ajouté : ' . post('nom') . " ($part %) · motif : " . post('motif'));
                    flash('success', 'Membre ajouté au plan familial.');
                } else {
                    $ancien = rows('SELECT * FROM membres WHERE id = ? AND assure_id = ?', [$mid, $id])[0] ?? null;
                    if (!$ancien) throw new RuntimeException('Membre introuvable.');
                    db()->prepare('UPDATE membres SET nom = ?, lien = ?, naissance = ?, part = ? WHERE id = ? AND assure_id = ?')
                        ->execute([post('nom'), post('lien') ?: 'Enfant', parse_date(post('naissance')), $part, $mid, $id]);
                    audit('Modification', $a['reference'], "Membre modifié : {$ancien['nom']} ({$ancien['part']} %) → " . post('nom') . " ($part %) · motif : " . post('motif'));
                    flash('success', 'Membre modifié.');
                }
                break;
            case 'del_membre':
                if ((int)scalar('SELECT COUNT(*) FROM membres WHERE assure_id = ?', [$id]) <= 1) throw new RuntimeException('Un plan familial doit garder au moins un membre.');
                $ancien = rows('SELECT nom FROM membres WHERE id = ? AND assure_id = ?', [(int)post('membre_id'), $id])[0] ?? null;
                if (!$ancien) throw new RuntimeException('Membre introuvable.');
                db()->prepare('DELETE FROM membres WHERE id = ? AND assure_id = ?')->execute([(int)post('membre_id'), $id]);
                audit('Modification', $a['reference'], 'Membre retiré : ' . $ancien['nom']);
                flash('warning', 'Membre retiré. Vérifiez la répartition de la couverture.');
                break;

            /* ----- Changement de plan tarifaire ----- */
            case 'changer_plan':
                if (!can('polices')) throw new RuntimeException('Action non autorisée.');
                if (in_array($a['statut'], ['Décédé', 'Résiliée'], true)) throw new RuntimeException("Police {$a['statut']} : changement de plan impossible.");
                $nouveau = rows('SELECT * FROM plans WHERE id = ? AND actif = 1', [(int)post('plan_id')])[0] ?? null;
                if (!$nouveau) throw new RuntimeException('Plan indisponible.');
                if ((int)$nouveau['id'] === (int)$a['plan_id']) throw new RuntimeException('C’est déjà le plan de cette police.');
                $effet = parse_date(post('date_effet'));
                if (!$effet) throw new RuntimeException('Date d’effet invalide (jj/mm/aaaa).');
                if ($effet < $a['adhesion']) throw new RuntimeException('La date d’effet précède l’adhésion.');
                if (mb_strlen(post('motif')) < 5) throw new RuntimeException('Indiquez le motif du changement de plan.');
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO historique_plans (assure_id, ancien_plan_id, nouveau_plan_id, ancienne_prime, nouvelle_prime, date_effet, motif, created_by)
                    VALUES (?,?,?,?,?,?,?,?)')->execute([$id, $a['plan_id'], $nouveau['id'], (float)$a['prime'], (float)$nouveau['prime'], $effet, post('motif'), $u['id']]);
                $pdo->prepare('UPDATE assures SET plan_id = ? WHERE id = ?')->execute([$nouveau['id'], $id]);
                audit('Changement de plan', $a['police'], "{$a['plan_nom']} (" . money($a['prime']) . ") → {$nouveau['nom']} (" . money($nouveau['prime'])
                    . ') au ' . fdate($effet) . ' · motif : ' . post('motif'));
                $pdo->commit();
                flash('success', "Plan changé : {$nouveau['nom']} · nouvelle cotisation " . money($nouveau['prime']) . " {$a['devise']} / mois.");
                break;

            /* ----- Pièces numérisées ----- */
            case 'doc_up':
                if (!in_array(post('piece'), PIECES_ADHESION, true)) throw new RuntimeException('Choisissez le type de pièce.');
                enregistrer_document('document', $id, post('piece'));
                // La pièce reçue est cochée automatiquement
                $pieces = json_decode($a['pieces'] ?: '[]', true) ?: [];
                if (!in_array(post('piece'), $pieces, true)) {
                    $pieces[] = post('piece');
                    db()->prepare('UPDATE assures SET pieces = ? WHERE id = ?')->execute([json_encode(array_values($pieces), JSON_UNESCAPED_UNICODE), $id]);
                }
                audit('Pièce ajoutée', $a['reference'], post('piece') . ' · ' . mb_substr((string)($_FILES['document']['name'] ?? ''), 0, 80));
                flash('success', 'Fichier ajouté au dossier : ' . post('piece') . '.');
                break;
            case 'doc_del':
                if (!peut_supprimer_document()) throw new RuntimeException('Seul un administrateur peut retirer une pièce.');
                $existe = scalar('SELECT COUNT(*) FROM documents WHERE id = ? AND assure_id = ? AND reclamation_id IS NULL', [(int)post('doc_id'), $id]);
                if (!$existe) throw new RuntimeException('Fichier introuvable.');
                $d = supprimer_document((int)post('doc_id'));
                audit('Pièce retirée', $a['reference'], $d['piece'] . ' · ' . $d['nom_original']);
                flash('warning', 'Fichier retiré du dossier.');
                break;
            case 'pieces':
                $pieces = array_values(array_intersect((array)($_POST['pieces'] ?? []), PIECES_ADHESION));
                db()->prepare('UPDATE assures SET pieces = ? WHERE id = ?')->execute([json_encode($pieces, JSON_UNESCAPED_UNICODE), $id]);
                flash('success', 'Pièces du dossier mises à jour.');
                break;
            case 'statut':
                if (!can('polices')) throw new RuntimeException('Action non autorisée.');
                $nouveau = post('statut');
                if (!in_array($nouveau, ['Active', 'Suspendue', 'Résiliée'], true)) throw new RuntimeException('Statut invalide.');
                db()->prepare('UPDATE assures SET statut = ? WHERE id = ?')->execute([$nouveau, $id]);
                audit('Modification', $a['police'], "Statut changé : {$a['statut']} → $nouveau");
                flash($nouveau === 'Résiliée' ? 'danger' : 'success', "Police {$a['police']} : $nouveau.");
                break;
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('assure.php?id=' . $id . '&tab=' . urlencode(post('tab', 'police')));
}

$tab = get('tab', 'police');
$devise = devise();
$elig = (int)setting('regle_eligibilite', 24);
$mois = mois_ecoules($a['adhesion']);
$dateElig = plus_mois($a['adhesion'], $elig);
$eligible = $mois >= $elig;
$paiements = rows('SELECT * FROM paiements WHERE assure_id = ? ORDER BY date_paiement DESC, id DESC', [$id]);
$benefs = rows('SELECT * FROM beneficiaires WHERE assure_id = ? ORDER BY part DESC', [$id]);
$membres = rows('SELECT * FROM membres WHERE assure_id = ? ORDER BY id', [$id]);
$recls = rows('SELECT * FROM reclamations WHERE assure_id = ? ORDER BY id DESC', [$id]);
$docs = rows('SELECT * FROM documents WHERE assure_id = ? AND reclamation_id IS NULL ORDER BY id DESC', [$id]);
$histoPlans = rows('SELECT h.*, pa.nom ancien_nom, pn.nom nouveau_nom, us.nom agent FROM historique_plans h
    LEFT JOIN plans pa ON pa.id = h.ancien_plan_id LEFT JOIN plans pn ON pn.id = h.nouveau_plan_id LEFT JOIN users us ON us.id = h.created_by
    WHERE h.assure_id = ? ORDER BY h.id DESC', [$id]);
$fermee = in_array($a['statut'], ['Décédé', 'Résiliée'], true);
$pieces = json_decode($a['pieces'] ?: '[]', true) ?: [];
$totalParts = array_sum(array_column($benefs, 'part'));
$totalPaye = array_sum(array_map(fn($p) => $p['statut'] === 'Encaissé' ? $p['montant'] : 0, $paiements));
$nom = $a['prenom'] . ' ' . $a['nom'];

$tabs = ['police' => ['Police', 'file', null], 'paiements' => ['Paiements', 'card', count($paiements)],
         'benef' => ['Bénéficiaires', 'users', count($benefs)]];
if ($a['type_police'] === 'famille') $tabs['membres'] = ['Membres', 'users', count($membres)];
$tabs['recl'] = ['Réclamations', 'folder', count($recls)];
if (!isset($tabs[$tab])) $tab = 'police';

$page_title = $nom;
$active = 'assures';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="assures.php">Assurés</a> › <?= e($a['reference']) ?></div>
    <h1><?= e($nom) ?></h1>
    <div class="meta">Police <span class="mono"><?= e($a['police']) ?></span> · adhésion <?= fdate($a['adhesion']) ?> · <?= e($a['commune']) ?> · <?= badge($a['statut']) ?></div>
  </div>
  <div class="actions">
    <?php if (!$fermee || $u['role'] === 'Administrateur'): ?><a class="btn btn-secondary" href="modifier.php?id=<?= $id ?>"><?= icon('edit', 16) ?> Modifier</a><?php endif; ?>
    <a class="btn btn-secondary" target="_blank" href="imprimer.php?doc=inscription&id=<?= $id ?>"><?= icon('printer', 16) ?> Fiche d’inscription</a>
    <a class="btn btn-secondary" target="_blank" href="imprimer.php?doc=paiement&id=<?= $id ?>"><?= icon('printer', 16) ?> Fiche de paiement</a>
    <?php if (can('paiements')): ?><a class="btn btn-primary" href="paiements.php?assure_id=<?= $id ?>#encaisser"><?= icon('card', 16) ?> Enregistrer un paiement</a><?php endif; ?>
  </div>
</div>

<div class="cols">
  <div class="col-main">
    <nav class="tabs">
      <?php foreach ($tabs as $k => [$label, $ic, $count]): ?>
        <a class="tab<?= $tab === $k ? ' is-active' : '' ?>" href="?id=<?= $id ?>&tab=<?= $k ?>"><?= icon($ic, 16) ?> <?= e($label) ?><?php if ($count !== null): ?> <span class="count"><?= $count ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'police'): ?>
      <section class="card">
        <div class="card-head"><h2>Identité</h2></div>
        <div class="card-body">
          <dl class="dl">
            <div><dt>Nom complet</dt><dd><?= e($nom) ?></dd></div>
            <div><dt>Référence</dt><dd><?= e($a['reference']) ?></dd></div>
            <div><dt>Date de naissance</dt><dd><?= fdate($a['naissance']) ?></dd></div>
            <div><dt>Âge</dt><dd><?= age($a['naissance']) ?></dd></div>
            <div><dt>Sexe / état civil</dt><dd><?= e(($a['sexe'] === 'F' ? 'Féminin' : ($a['sexe'] === 'M' ? 'Masculin' : '—')) . ' · ' . ($a['etat_civil'] ?: '—')) ?></dd></div>
            <div><dt>NIF</dt><dd><?= e($a['nif'] ?: '—') ?></dd></div>
            <div><dt>Téléphone</dt><dd><?= e($a['telephone']) ?><?= $a['telephone2'] ? ' / ' . e($a['telephone2']) : '' ?></dd></div>
            <div><dt>Profession</dt><dd><?= e($a['profession'] ?: '—') ?></dd></div>
            <div><dt>Adresse</dt><dd><?= e(trim(($a['adresse'] ?: '') . ', ' . $a['commune'] . ', ' . $a['departement'], ', ')) ?></dd></div>
            <div><dt>Section communale</dt><dd><?= e($a['section'] ?: '—') ?></dd></div>
            <div><dt>Témoin 1</dt><dd><?= e(($a['temoin1_nom'] ?: '—') . ($a['temoin1_tel'] ? ' · ' . $a['temoin1_tel'] : '')) ?></dd></div>
            <div><dt>Témoin 2</dt><dd><?= e(($a['temoin2_nom'] ?: '—') . ($a['temoin2_tel'] ? ' · ' . $a['temoin2_tel'] : '')) ?></dd></div>
          </dl>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><div><h2>Police</h2><div class="sub"><?= e($a['formule_nom'] ?: '—') ?> · <?= $a['type_police'] === 'famille' ? 'plan familial' : 'plan individuel' ?></div></div></div>
        <div class="card-body">
          <dl class="dl">
            <div><dt>Numéro de police</dt><dd><?= e($a['police']) ?></dd></div>
            <div><dt>Plan tarifaire</dt><dd><?= e($a['plan_nom']) ?></dd></div>
            <div><dt>Cotisation mensuelle</dt><dd><?= money($a['prime']) ?> <?= e($a['devise']) ?></dd></div>
            <div><dt>Couverture funéraire</dt><dd><?= money($a['capital']) ?> <?= e($a['devise']) ?></dd></div>
            <div><dt>Date d’adhésion</dt><dd><?= fdate($a['adhesion']) ?></dd></div>
            <div><dt>Éligibilité (<?= $elig ?> mois)</dt><dd><?= fdate($dateElig) ?></dd></div>
            <div><dt>Mode de paiement</dt><dd><?= e(nom_mode($a['mode_paiement'])) ?><?= $a['numero_mobile'] ? ' · ' . e($a['numero_mobile']) : '' ?></dd></div>
            <div><dt>Zone de collecte</dt><dd><?= e($a['zone'] ?: '—') ?></dd></div>
            <div><dt>Couvert jusqu’au</dt><dd><?= fdate(couvert_jusqua($a)) ?></dd></div>
            <div><dt>Total encaissé</dt><dd><?= money($totalPaye) ?> <?= e($a['devise']) ?></dd></div>
            <?php if ($a['sante']): ?><div><dt>Déclaration de santé</dt><dd><?= e($a['sante']) ?></dd></div><?php endif; ?>
          </dl>
        </div>
      </section>
      <?php if ($histoPlans): ?>
      <section class="card card-flush">
        <div class="card-head"><div><h2>Historique des plans</h2><div class="sub"><?= count($histoPlans) ?> changement(s)</div></div></div>
        <div class="card-body table-wrap">
          <table class="table">
            <thead><tr><th>Date d’effet</th><th>Ancien plan</th><th>Nouveau plan</th><th>Motif</th><th>Par</th></tr></thead>
            <tbody>
            <?php foreach ($histoPlans as $h): ?>
              <tr>
                <td class="mono"><?= fdate($h['date_effet']) ?></td>
                <td><?= e($h['ancien_nom'] ?: '—') ?> <span class="muted small mono"><?= money($h['ancienne_prime']) ?></span></td>
                <td><strong><?= e($h['nouveau_nom']) ?></strong> <span class="muted small mono"><?= money($h['nouvelle_prime']) ?></span></td>
                <td class="small"><?= e($h['motif']) ?></td>
                <td class="small"><?= e($h['agent'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

    <?php elseif ($tab === 'paiements'): ?>
      <section class="card card-flush">
        <div class="card-head"><div><h2>Historique des paiements</h2><div class="sub"><?= count($paiements) ?> versement(s) · <?= money($totalPaye) ?> <?= e($a['devise']) ?> encaissés</div></div></div>
        <div class="card-body table-wrap">
          <table class="table">
            <thead><tr><th>Reçu</th><th>Date</th><th>Objet</th><th class="num">Montant</th><th>Mode</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($paiements as $p): ?>
              <tr>
                <td class="mono"><?= e($p['reference']) ?></td>
                <td class="mono"><?= fdate($p['date_paiement']) ?></td>
                <td><?= e($p['objet']) ?></td>
                <td class="num"><?= money($p['montant']) ?></td>
                <td><?= e($p['mode']) ?></td>
                <td><?= badge($p['statut']) ?></td>
                <td><a class="icon-btn" target="_blank" title="Imprimer le reçu" href="imprimer.php?doc=recu&id=<?= (int)$p['id'] ?>"><?= icon('printer', 16) ?></a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$paiements): ?><tr><td colspan="7" class="muted">Aucun paiement enregistré.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    <?php elseif ($tab === 'benef'): ?>
      <section class="card card-flush">
        <div class="card-head"><div><h2>Bénéficiaires</h2><div class="sub">Répartition du capital : <?= badge(abs($totalParts - 100) < 0.01 ? 'Active' : 'En attente') ?> <span class="mono"><?= $totalParts ?> %</span></div></div></div>
        <div class="card-body table-wrap">
          <table class="table">
            <thead><tr><th>Bénéficiaire</th><th>Naissance</th><th class="num">Part</th><th>Téléphone</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($benefs as $b): ?>
              <tr>
                <td><?= person($b['nom'], $b['lien']) ?></td>
                <td class="mono"><?= fdate($b['naissance']) ?></td>
                <td class="num"><?= rtrim(rtrim((string)$b['part'], '0'), '.') ?> %</td>
                <td class="mono"><?= e($b['telephone'] ?: '—') ?></td>
                <td class="nowrap">
                  <button class="icon-btn" type="button" title="Modifier" data-toggle-row="eb-<?= (int)$b['id'] ?>"><?= icon('edit', 16) ?></button>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_benef"><input type="hidden" name="tab" value="benef">
                    <input type="hidden" name="benef_id" value="<?= (int)$b['id'] ?>">
                    <button class="icon-btn danger" data-confirm="Retirer ce bénéficiaire ?" title="Retirer"><?= icon('trash', 16) ?></button></form>
                </td>
              </tr>
              <tr class="edit-row" id="eb-<?= (int)$b['id'] ?>" hidden><td colspan="5">
                <form method="post" class="form-row" style="border:0;padding:0">
                  <?= csrf_field() ?><input type="hidden" name="action" value="edit_benef"><input type="hidden" name="tab" value="benef"><input type="hidden" name="benef_id" value="<?= (int)$b['id'] ?>">
                  <div class="field" style="flex:2 1 180px"><label>Nom</label><input class="input" name="nom" required value="<?= e($b['nom']) ?>"></div>
                  <div class="field"><label>Lien</label><select class="input" name="lien"><?= options(LIENS, $b['lien'], false) ?></select></div>
                  <div class="field"><label>Naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa" value="<?= $b['naissance'] ? fdate($b['naissance']) : '' ?>"></div>
                  <div class="field"><label>Téléphone</label><input class="input mono" name="telephone" value="<?= e($b['telephone']) ?>"></div>
                  <div class="field" style="flex:0 1 90px"><label>Part (%)</label><input class="input mono" name="part" value="<?= (float)$b['part'] ?>"></div>
                  <button class="btn btn-primary btn-sm" type="submit">Enregistrer</button>
                </form>
              </td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Ajouter un bénéficiaire</h2></div>
        <form method="post" class="card-body form-row" style="border:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_benef"><input type="hidden" name="tab" value="benef">
          <div class="field" style="flex:2 1 180px"><label>Nom <span class="req">*</span></label><input class="input" name="nom" required></div>
          <div class="field"><label>Lien</label><select class="input" name="lien"><?= options(LIENS, 'Enfant', false) ?></select></div>
          <div class="field"><label>Naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa"></div>
          <div class="field"><label>Téléphone</label><input class="input mono" name="telephone"></div>
          <div class="field" style="flex:0 1 90px"><label>Part (%)</label><input class="input mono" name="part" value="<?= max(0, 100 - $totalParts) ?>"></div>
          <button class="btn btn-primary" type="submit">Ajouter</button>
        </form>
      </section>

    <?php elseif ($tab === 'membres'): ?>
      <section class="card card-flush">
<?php $totalMembres = array_sum(array_column($membres, 'part')); ?>
        <div class="card-head"><div><h2>Membres couverts</h2><div class="sub">Plan familial · <?= count($membres) ?>/8 membre(s) · répartition
          <?= badge(abs($totalMembres - 100) < 0.01 ? 'Active' : 'En attente') ?> <span class="mono"><?= $totalMembres ?> %</span></div></div></div>
        <div class="card-body table-wrap">
          <table class="table">
            <thead><tr><th>Membre</th><th>Naissance</th><th class="num">Âge</th><th class="num">Part</th><th class="num">Capital</th><?php if (!$fermee): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($membres as $m): ?>
              <tr>
                <td><?= person($m['nom'], $m['lien']) ?></td>
                <td class="mono"><?= fdate($m['naissance']) ?></td>
                <td class="num"><?= age($m['naissance']) ?></td>
                <td class="num"><?= (float)$m['part'] ?> %</td>
                <td class="num"><?= money($a['capital'] * $m['part'] / 100) ?></td>
                <?php if (!$fermee): ?>
                <td class="nowrap">
                  <button class="icon-btn" type="button" title="Modifier" data-toggle-row="em-<?= (int)$m['id'] ?>"><?= icon('edit', 16) ?></button>
                  <?php if (count($membres) > 1): ?>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_membre"><input type="hidden" name="tab" value="membres">
                    <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                    <button class="icon-btn danger" data-confirm="Retirer <?= e($m['nom']) ?> de la couverture ?" title="Retirer"><?= icon('trash', 16) ?></button></form>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php if (!$fermee): ?>
              <tr class="edit-row" id="em-<?= (int)$m['id'] ?>" hidden><td colspan="6">
                <form method="post" class="form-row" style="border:0;padding:0">
                  <?= csrf_field() ?><input type="hidden" name="action" value="edit_membre"><input type="hidden" name="tab" value="membres"><input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                  <div class="field" style="flex:2 1 180px"><label>Nom</label><input class="input" name="nom" required value="<?= e($m['nom']) ?>"></div>
                  <div class="field"><label>Lien</label><select class="input" name="lien"><?= options(LIENS, $m['lien'], false) ?></select></div>
                  <div class="field"><label>Naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa" value="<?= $m['naissance'] ? fdate($m['naissance']) : '' ?>"></div>
                  <div class="field" style="flex:0 1 90px"><label>Part (%)</label><input class="input mono" name="part" value="<?= (float)$m['part'] ?>"></div>
                  <div class="field" style="flex:2 1 200px"><label>Motif</label><input class="input" name="motif" required minlength="5" placeholder="Erreur de saisie, nouvelle entente…"></div>
                  <button class="btn btn-primary btn-sm" type="submit">Enregistrer</button>
                </form>
              </td></tr>
              <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php if (!$fermee && count($membres) < 8): ?>
      <section class="card">
        <div class="card-head"><div><h2>Ajouter un membre</h2><div class="sub">Tout membre ajouté après la souscription exige une nouvelle entente avec la compagnie</div></div></div>
        <form method="post" class="card-body form-row" style="border:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_membre"><input type="hidden" name="tab" value="membres">
          <div class="field" style="flex:2 1 180px"><label>Nom <span class="req">*</span></label><input class="input" name="nom" required></div>
          <div class="field"><label>Lien</label><select class="input" name="lien"><?= options(LIENS, 'Enfant', false) ?></select></div>
          <div class="field"><label>Naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa"></div>
          <div class="field" style="flex:0 1 90px"><label>Part (%)</label><input class="input mono" name="part" value="<?= max(0, 100 - $totalMembres) ?>"></div>
          <div class="field" style="flex:2 1 200px"><label>Motif <span class="req">*</span></label><input class="input" name="motif" required minlength="5" placeholder="Entente du jj/mm/aaaa…"></div>
          <button class="btn btn-primary" type="submit">Ajouter</button>
        </form>
      </section>
      <?php endif; ?>

    <?php else: ?>
      <section class="card card-flush">
        <div class="card-head"><h2>Réclamations</h2>
          <?php if (can('reclamations')): ?><a class="btn btn-secondary btn-sm" href="reclamations.php?action=nouveau&assure_id=<?= $id ?>"><?= icon('folder', 14) ?> Ouvrir un dossier</a><?php endif; ?></div>
        <div class="card-body table-wrap">
          <?php if ($recls): ?>
            <table class="table"><thead><tr><th>Dossier</th><th>Décès</th><th class="num">Capital</th><th>Statut</th></tr></thead><tbody>
              <?php foreach ($recls as $r): ?>
                <tr data-href="reclamations.php?id=<?= (int)$r['id'] ?>"><td class="mono"><?= e($r['reference']) ?></td><td class="mono"><?= fdate($r['date_deces']) ?></td><td class="num"><?= money($r['capital']) ?></td><td><?= badge($r['statut']) ?></td></tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php else: ?>
            <div class="empty"><h3>Aucune réclamation</h3><p>Aucun dossier ouvert pour cet assuré.</p></div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <div class="col-side">
    <section class="card">
      <div class="card-head"><div><h2>Photo d’identité</h2><div class="sub mono">Dossier <?= e($a['reference']) ?></div></div></div>
      <form method="post" enctype="multipart/form-data" class="card-body row" style="align-items:flex-start">
        <?= csrf_field() ?><input type="hidden" name="action" value="photo"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?= photo_frame($a['photo']) ?>
        <div class="stack" style="gap:8px">
          <div class="muted small">Reprise sur la fiche d’inscription, la fiche de paiement et les reçus.</div>
          <label class="file-label"><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()"> Téléverser une photo</label>
        </div>
      </form>
    </section>

    <section class="card card-brand">
      <div class="card-head"><h2>Éligibilité (<?= $elig ?> mois)</h2></div>
      <div class="card-body stack">
        <div><span class="mono" style="font-size:28px;font-weight:600;color:var(--navy-700)"><?= $mois ?></span> <span class="muted small">mois écoulés sur <?= $elig ?></span></div>
        <div class="progress"><span style="width:<?= min(100, round($mois / $elig * 100)) ?>%"></span></div>
        <div><?= $eligible ? '<span class="badge badge-success">Éligible</span>' : '<span class="badge badge-warning">En cours</span>' ?></div>
        <div class="muted small"><?= $eligible ? 'Délai atteint le ' . fdate($dateElig) . ' — couverture funéraire mobilisable.' : 'Éligibilité au ' . fdate($dateElig) . ' — encore ' . ($elig - $mois) . ' mois de cotisation.' ?></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Pièces au dossier</h2></div>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="pieces"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php foreach (PIECES_ADHESION as $pc): ?>
          <label class="check"><input type="checkbox" name="pieces[]" value="<?= e($pc) ?>" <?= in_array($pc, $pieces, true) ? 'checked' : '' ?>> <?= e($pc) ?></label>
        <?php endforeach; ?>
        <button class="btn btn-secondary btn-sm" type="submit">Mettre à jour</button>
      </form>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Fichiers du dossier</h2><div class="sub"><?= count($docs) ?> pièce(s) numérisée(s)</div></div><?= icon('file', 18) ?></div>
      <div class="card-body stack"><?= bloc_documents($docs, PIECES_ADHESION, 'doc_up', 'doc_del', ['tab' => $tab]) ?></div>
    </section>

    <?php if (can('polices')): ?>
    <section class="card">
      <div class="card-head"><h2>Actions sur la police</h2></div>
      <div class="card-body stack">
        <?php if (!$fermee): ?>
          <button class="btn btn-secondary btn-block" type="button" data-toggle-row="changer-plan"><?= icon('swap', 16) ?> Changer de plan</button>
          <form method="post" id="changer-plan" class="stack plan-change" hidden>
            <?= csrf_field() ?><input type="hidden" name="action" value="changer_plan"><input type="hidden" name="tab" value="police">
            <div class="field"><label>Nouveau plan</label>
              <select class="input" name="plan_id" required>
                <?php foreach (plans() as $p): if ((int)$p['id'] === (int)$a['plan_id']) continue; ?>
                  <option value="<?= (int)$p['id'] ?>"><?= e($p['nom'] . ' · ' . money($p['prime']) . ' / mois · capital ' . money($p['capital'])) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint">Actuel : <?= e($a['plan_nom']) ?> · <?= money($a['prime']) ?> / mois · capital <?= money($a['capital']) ?></span></div>
            <div class="field"><label>Date d’effet</label><input class="input mono" name="date_effet" required value="<?= date('d/m/Y') ?>"></div>
            <div class="field"><label>Motif</label><input class="input" name="motif" required minlength="5" placeholder="Demande de l’assuré du jj/mm/aaaa…"></div>
            <button class="btn btn-primary btn-block" data-confirm="Changer le plan de cette police ? La nouvelle cotisation s’applique aux prochains paiements.">Confirmer le changement</button>
          </form>
        <?php endif; ?>
        <?php if ($a['statut'] !== 'Active'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="statut"><input type="hidden" name="statut" value="Active">
            <button class="btn btn-secondary btn-block" data-confirm="Réactiver cette police ?"><?= icon('shield', 16) ?> Réactiver la police</button></form>
        <?php endif; ?>
        <?php if ($a['statut'] !== 'Suspendue' && $a['statut'] !== 'Résiliée'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="statut"><input type="hidden" name="statut" value="Suspendue">
            <button class="btn btn-secondary btn-block" data-confirm="Suspendre cette police ?"><?= icon('calendar', 16) ?> Suspendre</button></form>
        <?php endif; ?>
        <?php if ($a['statut'] !== 'Résiliée'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="statut"><input type="hidden" name="statut" value="Résiliée">
            <button class="btn btn-danger btn-block" data-confirm="Résilier cette police ? La couverture prendra fin immédiatement. Les versements encaissés restent au dossier."><?= icon('alert', 16) ?> Résilier la police</button></form>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
