<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('assures');
$u = current_user();

$type = get('type', post('type_police', 'individuel')) === 'famille' ? 'famille' : 'individuel';
$isFam = $type === 'famille';
$plans = plans();
$formules = rows('SELECT * FROM formules WHERE actif = 1 ORDER BY id');
$modes = modes_paiement();
$devise = devise();
$elig = (int)setting('regle_eligibilite', 24);
$erreurs = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $requis = ['prenom' => 'Prénom', 'nom' => 'Nom', 'telephone' => 'Téléphone', 'departement' => 'Département',
               'commune' => 'Commune', 'plan_id' => 'Plan tarifaire', 'temoin1_nom' => 'Témoin 1', 'temoin2_nom' => 'Témoin 2'];
    foreach ($requis as $k => $label) {
        if (post($k) === '') $erreurs[] = "Le champ « $label » est obligatoire.";
    }
    $naissance = post('naissance') !== '' ? parse_date(post('naissance')) : null;
    if (post('naissance') !== '' && !$naissance) $erreurs[] = 'Date de naissance invalide (jj/mm/aaaa).';
    $adhesion = parse_date(post('adhesion', date('d/m/Y'))) ?: date('Y-m-d');
    if (post('nif') !== '' && strlen(preg_replace('/\D/', '', post('nif'))) < 10) $erreurs[] = 'Le NIF doit comporter au moins 10 chiffres.';
    if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $erreurs[] = 'Adresse courriel invalide.';
    if (!array_key_exists(post('departement'), DEPARTEMENTS)) $erreurs[] = 'Département inconnu.';

    $plan = null;
    foreach ($plans as $p) if ((int)$p['id'] === (int)post('plan_id')) $plan = $p;
    if (!$plan) $erreurs[] = 'Plan tarifaire indisponible.';

    // Lignes dynamiques
    $collect = function (string $prefix): array {
        $out = [];
        $noms = (array)($_POST[$prefix . '_nom'] ?? []);
        foreach ($noms as $i => $nom) {
            $nom = trim((string)$nom);
            if ($nom === '') continue;
            $out[] = [
                'nom' => $nom,
                'lien' => trim((string)($_POST[$prefix . '_lien'][$i] ?? '')),
                'naissance' => parse_date((string)($_POST[$prefix . '_naissance'][$i] ?? '')),
                'part' => (float)str_replace(',', '.', (string)($_POST[$prefix . '_part'][$i] ?? 0)),
                'tel' => trim((string)($_POST[$prefix . '_tel'][$i] ?? '')),
            ];
        }
        return $out;
    };
    $benefs = $collect('benef');
    $membres = $isFam ? $collect('membre') : [];

    if ($benefs && abs(array_sum(array_column($benefs, 'part')) - 100) > 0.01) {
        $erreurs[] = 'La répartition des bénéficiaires doit totaliser 100 %.';
    }
    if ($isFam) {
        if (!$membres) $erreurs[] = 'Ajoutez au moins un membre couvert.';
        if (count($membres) > 8) $erreurs[] = 'Huit membres au maximum par police familiale.';
    }

    $photo = null;
    if (!$erreurs) {
        try {
            $photo = upload_photo('photo');
        } catch (RuntimeException $ex) {
            $erreurs[] = $ex->getMessage();
        }
    }

    if (!$erreurs) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ref = next_reference('A', 'assures');
            $num = substr($ref, 2);
            $police = 'P-' . date('Y', strtotime($adhesion)) . '-' . $num;
            $pieces = array_values(array_intersect((array)($_POST['pieces'] ?? []), PIECES_ADHESION));
            if ($photo && !in_array('Photo d’identité', $pieces, true)) $pieces[] = 'Photo d’identité';

            $st = $pdo->prepare('INSERT INTO assures (reference, police, prenom, nom, sexe, etat_civil, naissance, lieu_naissance, nif,
                profession, personnes_charge, telephone, telephone2, email, departement, commune, section, adresse,
                temoin1_nom, temoin1_tel, temoin2_nom, temoin2_tel, sante, chef_famille, contact_urgence, contact_tel,
                inhumation, caveau, formule_id, plan_id, type_police, devise, mode_paiement, numero_mobile, zone,
                adhesion, statut, pieces, photo, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $ref, $police, post('prenom'), post('nom'), in_array(post('sexe'), ['F', 'M'], true) ? post('sexe') : null,
                post('etat_civil') ?: null, $naissance, post('lieu_naissance') ?: null, post('nif') ?: null,
                post('profession') ?: null, post('personnes_charge') !== '' ? (int)post('personnes_charge') : null,
                post('telephone'), post('telephone2') ?: null, post('email') ?: null, post('departement'), post('commune'),
                post('section') ?: null, post('adresse') ?: null, post('temoin1_nom'), post('temoin1_tel') ?: null,
                post('temoin2_nom'), post('temoin2_tel') ?: null, $isFam ? null : (post('sante') ?: 'Aucune affection déclarée'),
                post('chef_famille') ?: null, post('contact_urgence') ?: null, post('contact_tel') ?: null,
                post('inhumation') ?: null, post('caveau') ?: null, (int)post('formule_id') ?: null, $plan['id'], $type,
                post('devise') === 'USD' ? 'USD' : 'HTG', post('mode_paiement') ?: null, post('numero_mobile') ?: null,
                post('zone') ?: $u['zone'], $adhesion, 'En attente', json_encode($pieces, JSON_UNESCAPED_UNICODE), $photo, $u['id'],
            ]);
            $id = (int)$pdo->lastInsertId();

            $sb = $pdo->prepare('INSERT INTO beneficiaires (assure_id, nom, lien, naissance, part, telephone) VALUES (?,?,?,?,?,?)');
            foreach ($benefs as $b) $sb->execute([$id, $b['nom'], $b['lien'] ?: 'Autre parent', $b['naissance'], $b['part'], $b['tel'] ?: null]);
            $sm = $pdo->prepare('INSERT INTO membres (assure_id, nom, lien, naissance, part) VALUES (?,?,?,?,?)');
            foreach ($membres as $m) $sm->execute([$id, $m['nom'], $m['lien'] ?: 'Enfant', $m['naissance'], $m['part']]);

            if (post('frais_payes')) {
                $pdo->prepare('INSERT INTO paiements (reference, assure_id, montant, devise, mode, date_paiement, objet, statut, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([next_reference('R', 'paiements'), $id, (float)setting('org_inscription', 1000), $devise,
                        'Espèces', $adhesion, 'Inscription', 'Encaissé', $u['id']]);
            }
            $pdo->commit();
            audit('Adhésion', $ref, ($isFam ? 'Plan familial · ' . count($membres) . ' membres' : 'Police individuelle') . ' · ' . $plan['nom']);
            flash('success', "Assuré enregistré : $ref · police $police. Les fiches sont prêtes à imprimer.");
            redirect('assure.php?id=' . $id);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            if ($photo) @unlink(UPLOAD_DIR . $photo);
            $erreurs[] = 'Enregistrement impossible : ' . $ex->getMessage();
        }
    }
}

$val = fn(string $k, string $d = '') => e($old[$k] ?? $d);
$dept = $old['departement'] ?? ($u['departement'] ?: 'Sud');
$communes = explode(',', DEPARTEMENTS[$dept] ?? DEPARTEMENTS['Sud']);
$planSel = $old['plan_id'] ?? ($plans[$isFam ? min(5, count($plans) - 1) : min(2, count($plans) - 1)]['id'] ?? '');

$page_title = $isFam ? 'Adhésion — plan familial' : 'Adhésion — plan individuel';
$active = 'assures';
require __DIR__ . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" class="stack" style="gap:24px">
<?= csrf_field() ?>
<input type="hidden" name="type_police" value="<?= $type ?>">

<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Assurés › <?= $isFam ? 'Plan familial' : 'Plan individuel' ?></div>
    <h1><?= e($page_title) ?></h1>
    <div class="meta"><?= $isFam ? 'Une police, plusieurs membres couverts · prime unique' : 'Une police, un seul assuré · déclaration de santé requise' ?></div>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="assures.php">Annuler</a>
    <a class="btn btn-secondary" href="adhesion.php?type=<?= $isFam ? 'individuel' : 'famille' ?>"><?= $isFam ? 'Passer en individuel' : 'Passer en familial' ?></a>
    <button class="btn btn-primary" type="submit"><?= icon('user-plus', 16) ?> Enregistrer l’adhésion</button>
  </div>
</div>

<?php if ($erreurs): ?>
  <div class="alert alert-danger"><strong>Le dossier n’a pas été enregistré :</strong><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($erreurs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="cols">
  <div class="col-main">
    <section class="card">
      <div class="card-head"><h2>Identité de l’assuré</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Prénom <span class="req">*</span></label><input class="input" name="prenom" required value="<?= $val('prenom') ?>"></div>
        <div class="field"><label>Nom <span class="req">*</span></label><input class="input" name="nom" required value="<?= $val('nom') ?>"></div>
        <div class="field"><label>Date de naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa" value="<?= $val('naissance') ?>"><span class="hint">L’âge est calculé automatiquement</span></div>
        <div class="field"><label>Sexe</label><select class="input" name="sexe"><option value="">Sélectionner</option><?= options(['F' => 'Féminin', 'M' => 'Masculin'], $old['sexe'] ?? '') ?></select></div>
        <div class="field"><label>État civil</label><select class="input" name="etat_civil"><option value="">Sélectionner</option><?= options(ETATS_CIVILS, $old['etat_civil'] ?? '', false) ?></select></div>
        <div class="field"><label>Lieu de naissance</label><input class="input" name="lieu_naissance" value="<?= $val('lieu_naissance') ?>"></div>
        <div class="field"><label>NIF</label><input class="input mono" name="nif" placeholder="000-000-000-0" value="<?= $val('nif') ?>"></div>
        <div class="field"><label>Profession</label><input class="input" name="profession" value="<?= $val('profession') ?>"></div>
        <div class="field"><label>Personnes à charge</label><input class="input mono" type="number" min="0" max="30" name="personnes_charge" value="<?= $val('personnes_charge') ?>"></div>
        <div class="field"><label>Téléphone <span class="req">*</span></label><input class="input mono" name="telephone" required placeholder="+509 0000 0000" value="<?= $val('telephone') ?>"></div>
        <div class="field"><label>Deuxième téléphone</label><input class="input mono" name="telephone2" value="<?= $val('telephone2') ?>"></div>
        <div class="field"><label>Courriel</label><input class="input" type="email" name="email" value="<?= $val('email') ?>"></div>
        <div class="field"><label>Département <span class="req">*</span></label>
          <select class="input" name="departement" data-communes="commune" data-map="<?= e(json_encode(DEPARTEMENTS, JSON_UNESCAPED_UNICODE)) ?>">
            <?= options(array_combine(array_keys(DEPARTEMENTS), array_keys(DEPARTEMENTS)), $dept) ?>
          </select></div>
        <div class="field"><label>Commune <span class="req">*</span></label><select class="input" id="commune" name="commune"><?= options($communes, $old['commune'] ?? ($u['commune'] ?? ''), false) ?></select></div>
        <div class="field"><label>Section communale</label><input class="input" name="section" value="<?= $val('section') ?>"></div>
        <div class="field"><label>Adresse</label><input class="input" name="adresse" value="<?= $val('adresse') ?>"></div>
        <div class="field"><label>Témoin 1 — nom <span class="req">*</span></label><input class="input" name="temoin1_nom" required value="<?= $val('temoin1_nom') ?>"></div>
        <div class="field"><label>Témoin 1 — téléphone</label><input class="input mono" name="temoin1_tel" value="<?= $val('temoin1_tel') ?>"></div>
        <div class="field"><label>Témoin 2 — nom <span class="req">*</span></label><input class="input" name="temoin2_nom" required value="<?= $val('temoin2_nom') ?>"></div>
        <div class="field"><label>Témoin 2 — téléphone</label><input class="input mono" name="temoin2_tel" value="<?= $val('temoin2_tel') ?>"></div>
      </div>
    </section>

    <?php if ($isFam): ?>
    <section class="card">
      <div class="card-head"><div><h2>Situation familiale</h2><div class="sub">Renseignements propres au plan collectif familial</div></div></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Chef de famille</label><input class="input" name="chef_famille" value="<?= $val('chef_famille') ?>"></div>
        <div class="field"><label>Personne à contacter</label><input class="input" name="contact_urgence" value="<?= $val('contact_urgence') ?>"></div>
        <div class="field"><label>Téléphone du contact</label><input class="input mono" name="contact_tel" value="<?= $val('contact_tel') ?>"></div>
        <div class="field"><label>Lieu d’inhumation prévu</label><input class="input" name="inhumation" value="<?= $val('inhumation') ?>"></div>
        <div class="field"><label>Caveau familial</label><input class="input" name="caveau" value="<?= $val('caveau') ?>"></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div><h2>Membres couverts</h2><div class="sub">Huit membres au maximum · la couverture est répartie entre eux</div></div>
        <button class="btn btn-secondary btn-sm" type="button" data-add-row="membres" data-template="tpl-membre"><?= icon('user-plus', 14) ?> Ajouter un membre</button>
      </div>
      <div class="card-body stack">
        <div id="membres" class="stack" data-max="8">
          <?php $mNoms = $old['membre_nom'] ?? ['', '']; foreach ($mNoms as $i => $n): ?>
            <div class="form-row">
              <div class="field" style="flex:2 1 180px"><label>Nom du membre</label><input class="input" name="membre_nom[]" value="<?= e($n) ?>"></div>
              <div class="field"><label>Lien de parenté</label><select class="input" name="membre_lien[]"><?= options(LIENS, $old['membre_lien'][$i] ?? ($i === 0 ? 'Souscripteur' : 'Conjoint'), false) ?></select></div>
              <div class="field"><label>Naissance</label><input class="input mono" name="membre_naissance[]" placeholder="jj/mm/aaaa" value="<?= e($old['membre_naissance'][$i] ?? '') ?>"></div>
              <div class="field" style="flex:0 1 100px"><label>Part (%)</label><input class="input mono" name="membre_part[]" value="<?= e($old['membre_part'][$i] ?? '50') ?>"></div>
              <button class="icon-btn danger" type="button" data-remove-row title="Retirer"><?= icon('trash', 16) ?></button>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="muted small">Tout membre ajouté après la souscription exige une nouvelle entente avec la compagnie.</div>
      </div>
    </section>
    <template id="tpl-membre">
      <div class="form-row">
        <div class="field" style="flex:2 1 180px"><label>Nom du membre</label><input class="input" name="membre_nom[]"></div>
        <div class="field"><label>Lien de parenté</label><select class="input" name="membre_lien[]"><?= options(LIENS, 'Enfant', false) ?></select></div>
        <div class="field"><label>Naissance</label><input class="input mono" name="membre_naissance[]" placeholder="jj/mm/aaaa"></div>
        <div class="field" style="flex:0 1 100px"><label>Part (%)</label><input class="input mono" name="membre_part[]" value="0"></div>
        <button class="icon-btn danger" type="button" data-remove-row title="Retirer"><?= icon('trash', 16) ?></button>
      </div>
    </template>
    <?php else: ?>
    <section class="card">
      <div class="card-head"><div><h2>Déclaration de santé</h2><div class="sub">Obligatoire sur une police individuelle</div></div></div>
      <div class="card-body stack">
        <label class="check"><input type="radio" name="sante" value="Aucune affection déclarée" <?= ($old['sante'] ?? 'Aucune affection déclarée') === 'Aucune affection déclarée' ? 'checked' : '' ?>> Aucune affection déclarée</label>
        <label class="check"><input type="radio" name="sante" value="Affection déclarée — examen médical requis" <?= ($old['sante'] ?? '') === 'Affection déclarée — examen médical requis' ? 'checked' : '' ?>> Affection déclarée — examen médical requis</label>
      </div>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Police souscrite</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Formule de police</label>
          <select class="input" name="formule_id">
            <?php foreach ($formules as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= (string)($old['formule_id'] ?? '') === (string)$f['id'] || (!isset($old['formule_id']) && $f['code'] === ($isFam ? 'collectif' : 'individuel')) ? 'selected' : '' ?>><?= e($f['nom']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Plan tarifaire <span class="req">*</span></label>
          <select class="input" name="plan_id" data-plan>
            <?php foreach ($plans as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-prime="<?= money($p['prime']) ?>" data-capital="<?= money($p['capital']) ?>" <?= (string)$planSel === (string)$p['id'] ? 'selected' : '' ?>>
                <?= e($p['nom'] . ' · ' . money($p['prime']) . " $devise / mois · couverture " . money($p['capital'])) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Cotisation mensuelle (<?= e($devise) ?>)</label><input class="input mono" id="prime" readonly></div>
        <div class="field"><label>Couverture funéraire (<?= e($devise) ?>)</label><input class="input mono" id="capital" readonly></div>
        <div class="field"><label>Date d’adhésion</label><input class="input mono" name="adhesion" value="<?= $val('adhesion', date('d/m/Y')) ?>"><span class="hint">Éligibilité après <?= $elig ?> mois de cotisation</span></div>
        <div class="field"><label>Mode de paiement habituel</label><select class="input" name="mode_paiement"><?php foreach ($modes as $m): ?><option value="<?= e($m['code']) ?>" <?= ($old['mode_paiement'] ?? setting('mode_defaut')) === $m['code'] ? 'selected' : '' ?>><?= e($m['nom']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Numéro MonCash / NatCash</label><input class="input mono" name="numero_mobile" value="<?= $val('numero_mobile') ?>"></div>
        <div class="field"><label>Devise choisie</label><select class="input" name="devise"><?= options(['HTG' => 'Gourde (HTG)', 'USD' => 'Dollar (USD)'], $old['devise'] ?? 'HTG') ?></select></div>
        <div class="field"><label>Zone de collecte</label><input class="input" name="zone" value="<?= $val('zone', $u['zone'] ?? '') ?>"></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div><h2>Bénéficiaires</h2><div class="sub">Répartition du capital — total <span data-parts-total="benefs" class="badge badge-warning">0 %</span></div></div>
        <button class="btn btn-secondary btn-sm" type="button" data-add-row="benefs" data-template="tpl-benef"><?= icon('user-plus', 14) ?> Ajouter</button>
      </div>
      <div class="card-body">
        <div id="benefs" class="stack" data-max="10">
          <?php $bNoms = $old['benef_nom'] ?? ['']; foreach ($bNoms as $i => $n): ?>
            <div class="form-row">
              <div class="field" style="flex:2 1 180px"><label>Nom</label><input class="input" name="benef_nom[]" value="<?= e($n) ?>"></div>
              <div class="field"><label>Lien</label><select class="input" name="benef_lien[]"><?= options(LIENS, $old['benef_lien'][$i] ?? 'Enfant', false) ?></select></div>
              <div class="field"><label>Téléphone</label><input class="input mono" name="benef_tel[]" value="<?= e($old['benef_tel'][$i] ?? '') ?>"></div>
              <div class="field" style="flex:0 1 100px"><label>Part (%)</label><input class="input mono part-input" name="benef_part[]" value="<?= e($old['benef_part'][$i] ?? '100') ?>"></div>
              <button class="icon-btn danger" type="button" data-remove-row title="Retirer"><?= icon('trash', 16) ?></button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <template id="tpl-benef">
      <div class="form-row">
        <div class="field" style="flex:2 1 180px"><label>Nom</label><input class="input" name="benef_nom[]"></div>
        <div class="field"><label>Lien</label><select class="input" name="benef_lien[]"><?= options(LIENS, 'Enfant', false) ?></select></div>
        <div class="field"><label>Téléphone</label><input class="input mono" name="benef_tel[]"></div>
        <div class="field" style="flex:0 1 100px"><label>Part (%)</label><input class="input mono part-input" name="benef_part[]" value="0"></div>
        <button class="icon-btn danger" type="button" data-remove-row title="Retirer"><?= icon('trash', 16) ?></button>
      </div>
    </template>
  </div>

  <div class="col-side">
    <section class="card">
      <div class="card-head"><div><h2>Photo d’identité</h2><div class="sub">Portrait neutre, fond clair · 3 Mo max</div></div></div>
      <div class="card-body row" style="align-items:flex-start">
        <div id="photo-preview" class="photo photo-empty">Photo<br>96×120</div>
        <label class="file-label"><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-preview="photo-preview"> Téléverser une photo</label>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Pièces exigées</h2><div class="sub">Cocher les pièces reçues au dossier</div></div></div>
      <div class="card-body stack">
        <?php foreach (PIECES_ADHESION as $pc): ?>
          <label class="check"><input type="checkbox" name="pieces[]" value="<?= e($pc) ?>" <?= in_array($pc, (array)($old['pieces'] ?? []), true) ? 'checked' : '' ?>> <?= e($pc) ?></label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card card-brand">
      <div class="card-head"><h2>Frais et contrôles</h2></div>
      <div class="card-body stack">
        <div class="kv"><span>Frais d’inscription</span><span><?= money(setting('org_inscription')) ?> <?= e($devise) ?></span></div>
        <div class="kv"><span>Éligibilité</span><span>Après <?= $elig ?> mois</span></div>
        <label class="check"><input type="checkbox" name="frais_payes" value="1" <?= !empty($old['frais_payes']) || !$old ? 'checked' : '' ?>> Frais d’inscription encaissés maintenant</label>
      </div>
    </section>
  </div>
</div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
