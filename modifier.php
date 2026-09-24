<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('assures');
$u = current_user();
$estAdmin = $u['role'] === 'Administrateur';

[$sc, $sp] = scope_dept('a');
$id = (int)get('id', post('id'));
$st = db()->prepare("SELECT a.* FROM assures a WHERE a.id = ? $sc");
$st->execute(array_merge([$id], $sp));
$a = $st->fetch();
if (!$a) {
    flash('danger', 'Dossier introuvable ou hors de votre territoire.');
    redirect('assures.php');
}
if (in_array($a['statut'], ['Décédé', 'Résiliée'], true) && !$estAdmin) {
    flash('warning', "La police est {$a['statut']} : seul l’administrateur peut encore corriger le dossier.");
    redirect('assure.php?id=' . $id);
}
$isFam = $a['type_police'] === 'famille';
$formules = rows('SELECT * FROM formules ORDER BY id');
$modes = modes_paiement(false);

// Champs modifiables : clé => libellé
$champs = [
    'prenom' => 'Prénom', 'nom' => 'Nom', 'naissance' => 'Date de naissance', 'sexe' => 'Sexe', 'etat_civil' => 'État civil',
    'lieu_naissance' => 'Lieu de naissance', 'nif' => 'NIF', 'profession' => 'Profession', 'personnes_charge' => 'Personnes à charge',
    'telephone' => 'Téléphone', 'telephone2' => 'Deuxième téléphone', 'email' => 'Courriel',
    'departement' => 'Département', 'commune' => 'Commune', 'section' => 'Section communale', 'adresse' => 'Adresse',
    'temoin1_nom' => 'Témoin 1', 'temoin1_tel' => 'Téléphone témoin 1', 'temoin2_nom' => 'Témoin 2', 'temoin2_tel' => 'Téléphone témoin 2',
    'formule_id' => 'Formule', 'mode_paiement' => 'Mode de paiement', 'numero_mobile' => 'Numéro MonCash / NatCash', 'zone' => 'Zone de collecte',
];
if ($isFam) {
    $champs += ['chef_famille' => 'Chef de famille', 'contact_urgence' => 'Personne à contacter', 'contact_tel' => 'Téléphone du contact',
                'inhumation' => 'Lieu d’inhumation', 'caveau' => 'Caveau familial'];
} else {
    $champs['sante'] = 'Déclaration de santé';
}
if ($estAdmin) $champs['adhesion'] = 'Date d’adhésion';

$erreurs = [];
$old = $_POST ?: $a;
if ($old === $a) {
    $old['naissance'] = $a['naissance'] ? fdate($a['naissance']) : '';
    $old['adhesion'] = fdate($a['adhesion']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $motif = post('motif');
    foreach (['prenom' => 'Prénom', 'nom' => 'Nom', 'telephone' => 'Téléphone', 'departement' => 'Département',
              'commune' => 'Commune', 'temoin1_nom' => 'Témoin 1', 'temoin2_nom' => 'Témoin 2'] as $k => $label) {
        if (post($k) === '') $erreurs[] = "Le champ « $label » est obligatoire.";
    }
    if (mb_strlen($motif) < 5) $erreurs[] = 'Indiquez le motif de la correction (5 caractères minimum).';

    $nouveau = [];
    foreach (array_keys($champs) as $k) $nouveau[$k] = post($k) === '' ? null : post($k);

    $nouveau['naissance'] = post('naissance') !== '' ? parse_date(post('naissance')) : null;
    if (post('naissance') !== '' && !$nouveau['naissance']) $erreurs[] = 'Date de naissance invalide (jj/mm/aaaa).';
    if ($nouveau['naissance'] && $nouveau['naissance'] > date('Y-m-d')) $erreurs[] = 'La date de naissance est dans le futur.';
    if ($estAdmin) {
        $nouveau['adhesion'] = parse_date(post('adhesion'));
        if (!$nouveau['adhesion']) $erreurs[] = 'Date d’adhésion invalide (jj/mm/aaaa).';
        elseif ($nouveau['adhesion'] > date('Y-m-d')) $erreurs[] = 'La date d’adhésion est dans le futur.';
    }
    if (!in_array($nouveau['sexe'], ['F', 'M', null], true)) $nouveau['sexe'] = null;
    if ($nouveau['etat_civil'] !== null && !in_array($nouveau['etat_civil'], ETATS_CIVILS, true)) $erreurs[] = 'État civil invalide.';
    if ($nouveau['nif'] !== null && strlen(preg_replace('/\D/', '', $nouveau['nif'])) < 10) $erreurs[] = 'Le NIF doit comporter au moins 10 chiffres.';
    if ($nouveau['email'] !== null && !filter_var($nouveau['email'], FILTER_VALIDATE_EMAIL)) $erreurs[] = 'Adresse courriel invalide.';
    if ($nouveau['personnes_charge'] !== null) $nouveau['personnes_charge'] = max(0, min(30, (int)$nouveau['personnes_charge']));
    if (!isset(DEPARTEMENTS[(string)$nouveau['departement']])) $erreurs[] = 'Département inconnu.';
    elseif (!in_array($nouveau['commune'], explode(',', DEPARTEMENTS[$nouveau['departement']]), true)) $erreurs[] = 'La commune ne correspond pas au département.';
    // Un utilisateur limité à son département ne peut pas « sortir » un dossier de son territoire
    if ($sp && $nouveau['departement'] !== $u['departement']) $erreurs[] = 'Vous ne pouvez pas transférer ce dossier vers un autre département.';
    if ($nouveau['formule_id'] !== null && !in_array((int)$nouveau['formule_id'], array_map('intval', array_column($formules, 'id')), true)) $erreurs[] = 'Formule invalide.';
    if ($nouveau['mode_paiement'] !== null && !in_array($nouveau['mode_paiement'], array_column($modes, 'code'), true)) $erreurs[] = 'Mode de paiement invalide.';
    if (!$isFam && $nouveau['sante'] === null) $nouveau['sante'] = $a['sante'];
    foreach ($nouveau as $k => $v) if (is_string($v) && mb_strlen($v) > 160) $erreurs[] = "Le champ « {$champs[$k]} » est trop long.";

    // Seuls les champs réellement modifiés sont enregistrés
    $diff = [];
    foreach ($nouveau as $k => $v) {
        if ((string)($a[$k] ?? '') !== (string)($v ?? '')) $diff[$k] = $v;
    }
    if (!$erreurs && !$diff) $erreurs[] = 'Aucune modification à enregistrer.';

    if (!$erreurs) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($diff)));
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE assures SET $set WHERE id = ?")->execute(array_merge(array_values($diff), [$id]));
        $affiche = function ($k, $v) use ($formules, $modes) {
            if ($v === null || $v === '') return '(vide)';
            if (in_array($k, ['naissance', 'adhesion'], true)) return fdate($v);
            if ($k === 'formule_id') foreach ($formules as $f) if ((int)$f['id'] === (int)$v) return $f['nom'];
            if ($k === 'mode_paiement') foreach ($modes as $m) if ($m['code'] === $v) return $m['nom'];
            if ($k === 'sexe') return $v === 'F' ? 'Féminin' : 'Masculin';
            return (string)$v;
        };
        foreach ($diff as $k => $v) {
            audit('Modification', $a['reference'], "{$champs[$k]} : " . mb_substr($affiche($k, $a[$k] ?? null), 0, 60) . ' → '
                . mb_substr($affiche($k, $v), 0, 60) . " · motif : $motif");
        }
        $pdo->commit();
        flash('success', count($diff) . ' champ(s) corrigé(s). Chaque changement est inscrit au journal d’audit.');
        redirect('assure.php?id=' . $id);
    }
}

$val = fn(string $k) => e($old[$k] ?? '');
$dept = $old['departement'] ?? $a['departement'];
$communes = explode(',', DEPARTEMENTS[$dept] ?? DEPARTEMENTS['Sud']);
$depts = $sp ? [$u['departement']] : array_keys(DEPARTEMENTS);

$page_title = 'Modifier ' . $a['prenom'] . ' ' . $a['nom'];
$active = 'assures';
require __DIR__ . '/includes/header.php';
?>
<form method="post" class="stack" style="gap:24px">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">

<div class="page-head">
  <div>
    <div class="crumbs"><a href="assures.php">Assurés</a> › <a href="assure.php?id=<?= $id ?>"><?= e($a['reference']) ?></a> › Modifier</div>
    <h1>Corriger le dossier</h1>
    <div class="meta"><?= e($a['prenom'] . ' ' . $a['nom']) ?> · police <span class="mono"><?= e($a['police']) ?></span> · chaque changement est tracé au journal d’audit</div>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="assure.php?id=<?= $id ?>">Annuler</a>
    <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Enregistrer les corrections</button>
  </div>
</div>

<?php if ($erreurs): ?>
  <div class="alert alert-danger"><strong>Rien n’a été modifié :</strong><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($erreurs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="cols">
  <div class="col-main">
    <section class="card">
      <div class="card-head"><h2>Identité</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Prénom <span class="req">*</span></label><input class="input" name="prenom" required maxlength="160" value="<?= $val('prenom') ?>"></div>
        <div class="field"><label>Nom <span class="req">*</span></label><input class="input" name="nom" required maxlength="160" value="<?= $val('nom') ?>"></div>
        <div class="field"><label>Date de naissance</label><input class="input mono" name="naissance" placeholder="jj/mm/aaaa" value="<?= $val('naissance') ?>"></div>
        <div class="field"><label>Sexe</label><select class="input" name="sexe"><option value="">—</option><?= options(['F' => 'Féminin', 'M' => 'Masculin'], $old['sexe'] ?? '') ?></select></div>
        <div class="field"><label>État civil</label><select class="input" name="etat_civil"><option value="">—</option><?= options(ETATS_CIVILS, $old['etat_civil'] ?? '', false) ?></select></div>
        <div class="field"><label>Lieu de naissance</label><input class="input" name="lieu_naissance" value="<?= $val('lieu_naissance') ?>"></div>
        <div class="field"><label>NIF</label><input class="input mono" name="nif" value="<?= $val('nif') ?>"></div>
        <div class="field"><label>Profession</label><input class="input" name="profession" value="<?= $val('profession') ?>"></div>
        <div class="field"><label>Personnes à charge</label><input class="input mono" type="number" min="0" max="30" name="personnes_charge" value="<?= $val('personnes_charge') ?>"></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Coordonnées et adresse</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Téléphone <span class="req">*</span></label><input class="input mono" name="telephone" required value="<?= $val('telephone') ?>"></div>
        <div class="field"><label>Deuxième téléphone</label><input class="input mono" name="telephone2" value="<?= $val('telephone2') ?>"></div>
        <div class="field"><label>Courriel</label><input class="input" type="email" name="email" value="<?= $val('email') ?>"></div>
        <div class="field"><label>Département <span class="req">*</span></label>
          <select class="input" name="departement" data-communes="commune" data-map="<?= e(json_encode(DEPARTEMENTS, JSON_UNESCAPED_UNICODE)) ?>"><?= options($depts, $dept, false) ?></select></div>
        <div class="field"><label>Commune <span class="req">*</span></label><select class="input" id="commune" name="commune"><?= options($communes, $old['commune'] ?? '', false) ?></select></div>
        <div class="field"><label>Section communale</label><input class="input" name="section" value="<?= $val('section') ?>"></div>
        <div class="field" style="grid-column:1/-1"><label>Adresse</label><input class="input" name="adresse" value="<?= $val('adresse') ?>"></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Témoins</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Témoin 1 — nom <span class="req">*</span></label><input class="input" name="temoin1_nom" required value="<?= $val('temoin1_nom') ?>"></div>
        <div class="field"><label>Témoin 1 — téléphone</label><input class="input mono" name="temoin1_tel" value="<?= $val('temoin1_tel') ?>"></div>
        <div class="field"><label>Témoin 2 — nom <span class="req">*</span></label><input class="input" name="temoin2_nom" required value="<?= $val('temoin2_nom') ?>"></div>
        <div class="field"><label>Témoin 2 — téléphone</label><input class="input mono" name="temoin2_tel" value="<?= $val('temoin2_tel') ?>"></div>
      </div>
    </section>

    <?php if ($isFam): ?>
    <section class="card">
      <div class="card-head"><h2>Situation familiale</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Chef de famille</label><input class="input" name="chef_famille" value="<?= $val('chef_famille') ?>"></div>
        <div class="field"><label>Personne à contacter</label><input class="input" name="contact_urgence" value="<?= $val('contact_urgence') ?>"></div>
        <div class="field"><label>Téléphone du contact</label><input class="input mono" name="contact_tel" value="<?= $val('contact_tel') ?>"></div>
        <div class="field"><label>Lieu d’inhumation prévu</label><input class="input" name="inhumation" value="<?= $val('inhumation') ?>"></div>
        <div class="field"><label>Caveau familial</label><input class="input" name="caveau" value="<?= $val('caveau') ?>"></div>
      </div>
    </section>
    <?php else: ?>
    <section class="card">
      <div class="card-head"><h2>Déclaration de santé</h2></div>
      <div class="card-body stack">
        <?php foreach (['Aucune affection déclarée', 'Affection déclarée — examen médical requis'] as $s): ?>
          <label class="check"><input type="radio" name="sante" value="<?= e($s) ?>" <?= ($old['sante'] ?? '') === $s ? 'checked' : '' ?>> <?= e($s) ?></label>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div class="col-side">
    <section class="card card-brand">
      <div class="card-head"><div><h2>Motif de la correction</h2><div class="sub">Obligatoire · visible au journal d’audit</div></div></div>
      <div class="card-body stack">
        <textarea class="input" name="motif" rows="3" required minlength="5" maxlength="120" placeholder="Ex. : erreur de saisie du téléphone, nouvelle adresse déclarée par l’assuré…"><?= e(post('motif')) ?></textarea>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Gestion de la police</h2></div>
      <div class="card-body stack">
        <div class="field"><label>Formule</label><select class="input" name="formule_id"><?= options(array_column($formules, 'nom', 'id'), $old['formule_id'] ?? '') ?></select></div>
        <div class="field"><label>Mode de paiement habituel</label><select class="input" name="mode_paiement"><?= options(array_column($modes, 'nom', 'code'), $old['mode_paiement'] ?? '') ?></select></div>
        <div class="field"><label>Numéro MonCash / NatCash</label><input class="input mono" name="numero_mobile" value="<?= $val('numero_mobile') ?>"></div>
        <div class="field"><label>Zone de collecte</label><input class="input" name="zone" value="<?= $val('zone') ?>"></div>
        <?php if ($estAdmin): ?>
          <div class="field"><label>Date d’adhésion</label><input class="input mono" name="adhesion" required value="<?= $val('adhesion') ?>">
            <span class="hint">Attention : modifie la date d’éligibilité (<?= (int)setting('regle_eligibilite', 24) ?> mois).</span></div>
        <?php endif; ?>
        <div class="muted small">Pour changer de plan tarifaire, utilisez « Changer de plan » sur la fiche de l’assuré.</div>
      </div>
    </section>
  </div>
</div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
