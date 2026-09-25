<?php
require_once __DIR__ . '/includes/functions.php';
require_perm('parametres');
$u = current_user();
$tab = get('tab', 'plans');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $tab = post('tab', $tab);
    $num = fn($k) => (float)str_replace([' ', ','], ['', '.'], post($k));
    try {
        switch ($action) {
            /* ----- Plans ----- */
            case 'plan_save':
                $pid = (int)post('id');
                if (post('nom') === '' || $num('prime') <= 0 || $num('capital') <= 0) throw new RuntimeException('Nom, prime et capital sont obligatoires.');
                if ($pid) {
                    db()->prepare('UPDATE plans SET nom = ?, prime = ?, capital = ?, actif = ? WHERE id = ?')
                        ->execute([post('nom'), $num('prime'), $num('capital'), post('actif') ? 1 : 0, $pid]);
                } else {
                    db()->prepare('INSERT INTO plans (nom, prime, capital, actif) VALUES (?,?,?,1)')->execute([post('nom'), $num('prime'), $num('capital')]);
                }
                audit('Paramètres', post('nom'), 'Plan ' . ($pid ? 'modifié' : 'créé') . ' · prime ' . money($num('prime')) . ' · capital ' . money($num('capital')));
                flash('success', 'Plan enregistré. Les polices existantes conservent leur plan.');
                break;

            /* ----- Formules et services ----- */
            case 'formule_toggle':
                db()->prepare('UPDATE formules SET actif = 1 - actif WHERE id = ?')->execute([(int)post('id')]);
                audit('Paramètres', 'Formule', 'Activation modifiée');
                break;
            case 'formule_save':
                db()->prepare('UPDATE formules SET nom = ?, description = ? WHERE id = ?')->execute([post('nom'), post('description'), (int)post('id')]);
                audit('Paramètres', 'Formule', 'Formule modifiée : ' . post('nom'));
                flash('success', 'Formule enregistrée.');
                break;
            case 'service_toggle':
                db()->prepare('UPDATE services SET actif = 1 - actif WHERE id = ?')->execute([(int)post('id')]);
                break;
            case 'service_add':
                if (post('nom') === '') throw new RuntimeException('Nom du service obligatoire.');
                db()->prepare('INSERT INTO services (nom, mode, actif) VALUES (?,?,1)')->execute([post('nom'), post('mode') ?: 'Vente sur commande']);
                audit('Paramètres', 'Service', 'Service ajouté : ' . post('nom'));
                flash('success', 'Service ajouté.');
                break;

            /* ----- Réglages clé / valeur ----- */
            case 'settings':
                $cles = [
                    'organisation' => ['org_nom', 'org_nif', 'org_adresse', 'org_tel', 'org_mail', 'org_site', 'org_slogan', 'org_devise', 'org_inscription'],
                    'regles'       => ['regle_eligibilite', 'regle_retard', 'regle_suspension', 'regle_echeance'],
                    'paiement'     => ['mode_defaut'],
                    'imprimante'   => ['imp_nom', 'imp_ip', 'imp_format', 'imp_copies'],
                ][$tab] ?? [];
                foreach ($cles as $k) set_setting($k, post($k));
                if ($tab === 'regles') {
                    foreach (['notif_adhesion', 'notif_retard', 'notif_eligibilite', 'notif_reclamation'] as $k) set_setting($k, post($k) ? '1' : '0');
                    if ((int)post('regle_suspension') <= (int)post('regle_retard')) flash('warning', 'La suspension devrait intervenir après le retard.');
                }
                audit('Paramètres', ucfirst($tab), 'Réglages mis à jour');
                flash('success', 'Réglages enregistrés.');
                break;

            /* ----- Utilisateurs ----- */
            case 'user_save':
                $uid = (int)post('id');
                $role = post('role');
                if (post('nom') === '' || post('login') === '') throw new RuntimeException('Nom et identifiant obligatoires.');
                if (!isset(ROLES[$role])) throw new RuntimeException('Rôle invalide.');
                if (post('departement') !== '' && !isset(DEPARTEMENTS[post('departement')])) throw new RuntimeException('Département invalide.');
                if ($role !== 'Administrateur' && post('departement') === '') throw new RuntimeException('Un département est requis pour ce rôle.');
                $mdp = (string)post('password');
                if (!$uid && $mdp === '') throw new RuntimeException('Un mot de passe provisoire est obligatoire.');
                if ($mdp !== '' && ($msgMdp = erreur_mdp($mdp, post('login')))) throw new RuntimeException($msgMdp);
                if (!preg_match('/^[a-z0-9._-]{3,60}$/i', post('login'))) throw new RuntimeException('Identifiant : 3 à 60 caractères (lettres, chiffres, point, tiret).');
                if ((int)scalar('SELECT COUNT(*) FROM users WHERE login = ? AND id <> ?', [post('login'), $uid])) throw new RuntimeException('Cet identifiant est déjà utilisé.');
                if ($uid === (int)$u['id'] && (!post('actif') || $role !== 'Administrateur')) throw new RuntimeException('Vous ne pouvez pas désactiver ni rétrograder votre propre compte.');
                $vals = [post('nom'), post('login'), $role, post('telephone') ?: null, post('email') ?: null, post('departement') ?: null,
                         post('commune') ?: null, post('zone') ?: null, post('actif') || !$uid ? 1 : 0];
                if ($uid) {
                    db()->prepare('UPDATE users SET nom=?, login=?, role=?, telephone=?, email=?, departement=?, commune=?, zone=?, actif=? WHERE id=?')
                        ->execute(array_merge($vals, [$uid]));
                    if ($mdp !== '') {
                        $nouveauHash = password_hash($mdp, PASSWORD_DEFAULT);
                        $soiMeme = $uid === (int)$u['id'];
                        db()->prepare('UPDATE users SET password_hash = ?, doit_changer_mdp = ?, mdp_change_le = NOW() WHERE id = ?')
                            ->execute([$nouveauHash, $soiMeme ? 0 : 1, $uid]);
                        // Garde sa propre session ouverte ; celles de l'utilisateur modifié sont fermées
                        if ($soiMeme) $_SESSION['empreinte'] = hash('sha256', $nouveauHash);
                    }
                } else {
                    db()->prepare('INSERT INTO users (nom, login, role, telephone, email, departement, commune, zone, actif, password_hash, doit_changer_mdp) VALUES (?,?,?,?,?,?,?,?,?,?,1)')
                        ->execute(array_merge($vals, [password_hash($mdp, PASSWORD_DEFAULT)]));
                }
                audit('Utilisateur', post('login'), ($uid ? 'Compte modifié' : 'Compte créé') . ' · ' . $role . ($mdp !== '' && $uid ? ' · mot de passe réinitialisé' : ''));
                flash('success', 'Compte enregistré.');
                break;

            /* ----- Moyens de paiement ----- */
            case 'mode_save':
                $mid = (int)post('id');
                if (post('nom') === '') throw new RuntimeException('Nom obligatoire.');
                if ($mid) {
                    db()->prepare('UPDATE modes_paiement SET nom=?, type=?, numero=?, frais=?, actif=? WHERE id=?')
                        ->execute([post('nom'), post('type'), post('numero') ?: null, $num('frais'), post('actif') ? 1 : 0, $mid]);
                } else {
                    $code = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT', post('nom')))), '-') ?: 'mode';
                    if ((int)scalar('SELECT COUNT(*) FROM modes_paiement WHERE code = ?', [$code])) $code .= '-' . bin2hex(random_bytes(2));
                    db()->prepare('INSERT INTO modes_paiement (code, nom, type, numero, frais, actif) VALUES (?,?,?,?,?,1)')
                        ->execute([$code, post('nom'), post('type'), post('numero') ?: null, $num('frais')]);
                }
                audit('Paramètres', post('nom'), 'Moyen de paiement ' . ($mid ? 'modifié' : 'ajouté'));
                flash('success', 'Moyen de paiement enregistré.');
                break;
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('parametres.php?tab=' . urlencode($tab));
}

$tabs = [
    'plans' => ['Plans tarifaires', 'card'], 'formules' => ['Formules et services', 'file'], 'organisation' => ['Informations admin', 'shield'],
    'regles' => ['Règles et alertes', 'bell'], 'utilisateurs' => ['Utilisateurs', 'users'], 'paiement' => ['Moyens de paiement', 'card'],
    'imprimante' => ['Imprimante', 'printer'], 'donnees' => ['Données', 'download'],
];
if (!isset($tabs[$tab])) $tab = 'plans';
$s = fn($k) => e(setting($k));
$tabField = '<input type="hidden" name="tab" value="' . e($tab) . '">';

$page_title = 'Paramètres';
$active = 'parametres';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Administration › Paramètres</div>
    <h1>Paramètres</h1>
    <div class="meta">Réservé à l’administrateur · chaque modification est inscrite au journal d’audit</div>
  </div>
</div>

<nav class="tabs">
  <?php foreach ($tabs as $k => [$label, $ic]): ?>
    <a class="tab<?= $tab === $k ? ' is-active' : '' ?>" href="?tab=<?= $k ?>"><?= icon($ic, 16) ?> <?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'plans'): $plans = rows('SELECT pl.*, (SELECT COUNT(*) FROM assures a WHERE a.plan_id = pl.id) nb FROM plans pl ORDER BY pl.prime'); ?>
  <section class="card card-flush">
    <div class="card-head"><div><h2>Plans tarifaires</h2><div class="sub">Prime mensuelle et capital funéraire en <?= e(devise()) ?></div></div></div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Plan</th><th class="num">Prime</th><th class="num">Capital</th><th class="num">Assurés</th><th>Actif</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p): ?>
          <tr>
            <td><form method="post" id="plan-<?= (int)$p['id'] ?>"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="plan_save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"></form><input form="plan-<?= (int)$p['id'] ?>" class="input" name="nom" value="<?= e($p['nom']) ?>" style="height:32px"></td>
            <td class="num"><input form="plan-<?= (int)$p['id'] ?>" class="input mono" name="prime" value="<?= (float)$p['prime'] ?>" style="height:32px;width:110px;text-align:right"></td>
            <td class="num"><input form="plan-<?= (int)$p['id'] ?>" class="input mono" name="capital" value="<?= (float)$p['capital'] ?>" style="height:32px;width:130px;text-align:right"></td>
            <td class="num"><?= (int)$p['nb'] ?></td>
            <td><label class="check"><input form="plan-<?= (int)$p['id'] ?>" type="checkbox" name="actif" value="1" <?= $p['actif'] ? 'checked' : '' ?>> Proposé</label></td>
            <td><button form="plan-<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">Enregistrer</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h2>Nouveau plan</h2></div>
    <form method="post" class="card-body form-row" style="align-items:flex-end">
      <?= csrf_field() . $tabField ?><input type="hidden" name="action" value="plan_save">
      <div class="field"><label>Nom</label><input class="input" name="nom" placeholder="Plan 8" required></div>
      <div class="field"><label>Prime mensuelle</label><input class="input mono" name="prime" required></div>
      <div class="field"><label>Capital</label><input class="input mono" name="capital" required></div>
      <button class="btn btn-primary">Ajouter le plan</button>
    </form>
  </section>

<?php elseif ($tab === 'formules'): ?>
  <div class="cols">
    <section class="card col-main">
      <div class="card-head"><h2>Formules de police</h2></div>
      <div class="card-body stack">
        <?php foreach (rows('SELECT * FROM formules ORDER BY id') as $f): ?>
          <div class="list-item" style="align-items:flex-start">
            <form method="post" class="stack" style="flex:1;gap:6px"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="formule_save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <input class="input" name="nom" value="<?= e($f['nom']) ?>" style="font-weight:600">
              <textarea class="input" name="description" rows="2"><?= e($f['description']) ?></textarea>
              <div><button class="btn btn-ghost btn-sm">Enregistrer</button></div>
            </form>
            <form method="post"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="formule_toggle"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn btn-sm <?= $f['actif'] ? 'btn-secondary' : 'btn-accent' ?>"><?= $f['actif'] ? 'Désactiver' : 'Activer' ?></button></form>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card col-side">
      <div class="card-head"><h2>Services funéraires</h2></div>
      <div class="card-body">
        <?php foreach (rows('SELECT * FROM services ORDER BY id') as $sv): ?>
          <div class="list-item"><div><strong><?= e($sv['nom']) ?></strong><div class="muted small"><?= e($sv['mode']) ?></div></div>
            <form method="post"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="service_toggle"><input type="hidden" name="id" value="<?= (int)$sv['id'] ?>">
              <button class="btn btn-sm btn-ghost"><?= $sv['actif'] ? '<span class="badge badge-success">Actif</span>' : '<span class="badge badge-neutral">Inactif</span>' ?></button></form></div>
        <?php endforeach; ?>
        <form method="post" class="stack" style="margin-top:12px"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="service_add">
          <input class="input" name="nom" placeholder="Nouveau service" required>
          <select class="input" name="mode"><option>Vente sur commande</option><option>Location</option><option>Prestation</option></select>
          <button class="btn btn-secondary btn-sm">Ajouter</button>
        </form>
      </div>
    </section>
  </div>

<?php elseif ($tab === 'organisation'): ?>
  <form method="post" class="card"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="settings">
    <div class="card-head"><div><h2>Informations de la compagnie</h2><div class="sub">Reprises en en-tête des fiches, reçus et rapports imprimés</div></div></div>
    <div class="card-body grid grid-form">
      <div class="field" style="grid-column:1/-1"><label>Raison sociale</label><input class="input" name="org_nom" value="<?= $s('org_nom') ?>"></div>
      <div class="field"><label>NIF</label><input class="input mono" name="org_nif" value="<?= $s('org_nif') ?>"></div>
      <div class="field"><label>Téléphone</label><input class="input mono" name="org_tel" value="<?= $s('org_tel') ?>"></div>
      <div class="field"><label>Courriel</label><input class="input" name="org_mail" value="<?= $s('org_mail') ?>"></div>
      <div class="field"><label>Site web</label><input class="input" name="org_site" value="<?= $s('org_site') ?>"></div>
      <div class="field" style="grid-column:1/-1"><label>Adresse du siège</label><input class="input" name="org_adresse" value="<?= $s('org_adresse') ?>"></div>
      <div class="field" style="grid-column:1/-1"><label>Slogan</label><input class="input" name="org_slogan" value="<?= $s('org_slogan') ?>"></div>
      <div class="field"><label>Devise par défaut</label><select class="input" name="org_devise"><?= options(['HTG' => 'Gourde (HTG)', 'USD' => 'Dollar US (USD)'], setting('org_devise')) ?></select></div>
      <div class="field"><label>Frais d’inscription</label><input class="input mono" name="org_inscription" value="<?= $s('org_inscription') ?>"></div>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border)"><button class="btn btn-primary">Enregistrer</button></div>
  </form>

<?php elseif ($tab === 'regles'): ?>
  <form method="post" class="cols"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="settings">
    <section class="card col-main">
      <div class="card-head"><h2>Règles de gestion</h2></div>
      <div class="card-body grid grid-form">
        <div class="field"><label>Délai d’éligibilité (mois)</label><input class="input mono" type="number" min="0" max="120" name="regle_eligibilite" value="<?= $s('regle_eligibilite') ?>"><span class="hint">Carence avant versement du capital</span></div>
        <div class="field"><label>Retard après (jours)</label><input class="input mono" type="number" min="1" name="regle_retard" value="<?= $s('regle_retard') ?>"></div>
        <div class="field"><label>Suspension après (jours)</label><input class="input mono" type="number" min="1" name="regle_suspension" value="<?= $s('regle_suspension') ?>"></div>
        <div class="field"><label>Jour d’échéance mensuelle</label><input class="input mono" type="number" min="1" max="28" name="regle_echeance" value="<?= $s('regle_echeance') ?>"></div>
      </div>
    </section>
    <section class="card col-side">
      <div class="card-head"><h2>Alertes</h2></div>
      <div class="card-body stack">
        <?php foreach (['notif_adhesion' => 'Nouvelle adhésion', 'notif_retard' => 'Police en retard', 'notif_eligibilite' => 'Assuré devenu éligible', 'notif_reclamation' => 'Nouvelle réclamation'] as $k => $l): ?>
          <label class="check"><input type="checkbox" name="<?= $k ?>" value="1" <?= setting($k) === '1' ? 'checked' : '' ?>> <?= e($l) ?></label>
        <?php endforeach; ?>
        <button class="btn btn-primary">Enregistrer</button>
      </div>
    </section>
  </form>

<?php elseif ($tab === 'utilisateurs'):
    $users = rows('SELECT * FROM users ORDER BY actif DESC, nom');
    $edit = null;
    foreach ($users as $x) if ((int)$x['id'] === (int)get('edit')) $edit = $x;
    $ev = fn($k) => e($edit[$k] ?? '');
    $commEdit = $edit && $edit['departement'] ? explode(',', DEPARTEMENTS[$edit['departement']] ?? '') : [];
?>
  <div class="cols">
    <section class="card card-flush col-main">
      <div class="card-head"><div><h2>Comptes du personnel</h2><div class="sub"><?= count($users) ?> compte(s)</div></div></div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Département</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
          <tbody><?php foreach ($users as $x): ?>
            <tr>
              <td><?= person($x['nom'], $x['login']) ?></td>
              <td class="small"><?= e($x['role']) ?></td>
              <td class="small"><?= e($x['departement'] ?: 'Tous') ?><?= $x['zone'] ? ' · ' . e($x['zone']) : '' ?></td>
              <td class="mono small"><?= $x['derniere_connexion'] ? date('d/m/Y H:i', strtotime($x['derniere_connexion'])) : '—' ?></td>
              <td><?= $x['actif'] ? '<span class="badge badge-success">Actif</span>' : '<span class="badge badge-neutral">Désactivé</span>' ?></td>
              <td><a class="btn btn-ghost btn-sm" href="?tab=utilisateurs&edit=<?= (int)$x['id'] ?>">Modifier</a></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
    </section>
    <section class="card col-side">
      <div class="card-head"><h2><?= $edit ? 'Modifier ' . e($edit['nom']) : 'Nouveau compte' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="?tab=utilisateurs">Nouveau</a><?php endif; ?></div>
      <form method="post" class="card-body stack"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="user_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="field"><label>Nom complet</label><input class="input" name="nom" required value="<?= $ev('nom') ?>"></div>
        <div class="field"><label>Identifiant</label><input class="input mono" name="login" required value="<?= $ev('login') ?>"></div>
        <div class="field"><label><?= $edit ? 'Réinitialiser le mot de passe (laisser vide)' : 'Mot de passe provisoire' ?></label><input class="input" type="password" name="password" minlength="10" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></div>
        <div class="hint">10 caractères min., majuscules, minuscules et chiffres. L’utilisateur devra le changer à sa première connexion.</div>
        <div class="field"><label>Rôle</label><select class="input" name="role"><?= options(array_keys(ROLES), $edit['role'] ?? 'Agent de gestion', false) ?></select></div>
        <div class="field"><label>Département</label><select class="input" name="departement" data-communes="u-commune" data-map="<?= e(json_encode(DEPARTEMENTS, JSON_UNESCAPED_UNICODE)) ?>"><option value="">Tous (administrateur)</option><?= options(array_keys(DEPARTEMENTS), $edit['departement'] ?? '', false) ?></select></div>
        <div class="field"><label>Commune</label><select class="input" id="u-commune" name="commune"><option value=""></option><?= options($commEdit, $edit['commune'] ?? '', false) ?></select></div>
        <div class="field"><label>Zone de collecte</label><input class="input" name="zone" value="<?= $ev('zone') ?>"></div>
        <div class="field"><label>Téléphone</label><input class="input mono" name="telephone" value="<?= $ev('telephone') ?>"></div>
        <div class="field"><label>Courriel</label><input class="input" type="email" name="email" value="<?= $ev('email') ?>"></div>
        <label class="check"><input type="checkbox" name="actif" value="1" <?= !$edit || $edit['actif'] ? 'checked' : '' ?>> Compte actif</label>
        <?php if (!$edit): ?><div class="muted small"><?php foreach (ROLES as $r => $d): ?><div><strong><?= e($r) ?></strong> — <?= e($d) ?></div><?php endforeach; ?></div><?php endif; ?>
        <button class="btn btn-primary btn-block">Enregistrer le compte</button>
      </form>
    </section>
  </div>

<?php elseif ($tab === 'paiement'): $modes = modes_paiement(false); ?>
  <section class="card card-flush">
    <div class="card-head"><h2>Moyens de paiement</h2></div>
    <div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Nom</th><th>Type</th><th>Numéro marchand</th><th class="num">Frais</th><th>Actif</th><th></th></tr></thead>
        <tbody><?php foreach ($modes as $m): ?>
          <tr>
            <td><form method="post" id="mode-<?= (int)$m['id'] ?>"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="mode_save"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"></form><input form="mode-<?= (int)$m['id'] ?>" class="input" name="nom" value="<?= e($m['nom']) ?>" style="height:32px"></td>
            <td><select form="mode-<?= (int)$m['id'] ?>" class="input" name="type" style="height:32px"><?= options(['Mobile', 'Guichet', 'Banque'], $m['type'], false) ?></select></td>
            <td><input form="mode-<?= (int)$m['id'] ?>" class="input mono" name="numero" value="<?= e($m['numero']) ?>" style="height:32px"></td>
            <td class="num"><input form="mode-<?= (int)$m['id'] ?>" class="input mono" name="frais" value="<?= (float)$m['frais'] ?>" style="height:32px;width:90px;text-align:right"></td>
            <td><input form="mode-<?= (int)$m['id'] ?>" type="checkbox" name="actif" value="1" <?= $m['actif'] ? 'checked' : '' ?>></td>
            <td><button form="mode-<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">Enregistrer</button></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
  <div class="cols">
    <form method="post" class="card col-main"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="mode_save">
      <div class="card-head"><h2>Ajouter un moyen de paiement</h2></div>
      <div class="card-body form-row" style="align-items:flex-end">
        <div class="field"><label>Nom</label><input class="input" name="nom" required></div>
        <div class="field"><label>Type</label><select class="input" name="type"><option>Mobile</option><option>Guichet</option><option>Banque</option></select></div>
        <div class="field"><label>Numéro</label><input class="input mono" name="numero"></div>
        <div class="field" style="flex:0 1 100px"><label>Frais</label><input class="input mono" name="frais" value="0"></div>
        <button class="btn btn-primary">Ajouter</button>
      </div>
    </form>
    <form method="post" class="card col-side"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="settings">
      <div class="card-head"><h2>Mode par défaut</h2></div>
      <div class="card-body stack">
        <select class="input" name="mode_defaut"><?= options(array_column($modes, 'nom', 'code'), setting('mode_defaut')) ?></select>
        <button class="btn btn-secondary btn-sm">Enregistrer</button>
      </div>
    </form>
  </div>

<?php elseif ($tab === 'imprimante'): ?>
  <form method="post" class="card"><?= csrf_field() . $tabField ?><input type="hidden" name="action" value="settings">
    <div class="card-head"><div><h2>Imprimante</h2><div class="sub">Format proposé par défaut à l’impression des reçus et fiches</div></div></div>
    <div class="card-body grid grid-form">
      <div class="field"><label>Nom de l’imprimante</label><input class="input" name="imp_nom" value="<?= $s('imp_nom') ?>"></div>
      <div class="field"><label>Adresse IP</label><input class="input mono" name="imp_ip" value="<?= $s('imp_ip') ?>"></div>
      <div class="field"><label>Format par défaut</label><select class="input" name="imp_format"><?= options(['a4' => 'A4 (bureau)', 'thermal' => 'Ticket thermique 80 mm'], setting('imp_format')) ?></select></div>
      <div class="field"><label>Nombre de copies</label><input class="input mono" type="number" min="1" max="5" name="imp_copies" value="<?= $s('imp_copies') ?>"></div>
    </div>
    <div class="card-body row" style="border-top:1px solid var(--border)">
      <button class="btn btn-primary">Enregistrer</button>
      <?php $dernier = scalar('SELECT id FROM paiements ORDER BY id DESC LIMIT 1'); if ($dernier): ?>
        <a class="btn btn-secondary" target="_blank" href="imprimer.php?doc=recu&id=<?= (int)$dernier ?>"><?= icon('printer', 16) ?> Page de test (dernier reçu)</a>
      <?php endif; ?>
    </div>
  </form>

<?php else:
    $stats = [
        'Assurés' => scalar('SELECT COUNT(*) FROM assures'), 'Bénéficiaires' => scalar('SELECT COUNT(*) FROM beneficiaires'),
        'Paiements' => scalar('SELECT COUNT(*) FROM paiements'), 'Réclamations' => scalar('SELECT COUNT(*) FROM reclamations'),
        'Événements d’audit' => scalar('SELECT COUNT(*) FROM audit'), 'Utilisateurs' => scalar('SELECT COUNT(*) FROM users'),
    ];
?>
  <div class="cols">
    <section class="card col-main">
      <div class="card-head"><h2>Exports</h2></div>
      <div class="card-body stack">
        <?php foreach (['assures' => 'Registre des assurés', 'polices' => 'Portefeuille de polices', 'paiements' => 'Tous les paiements', 'beneficiaires' => 'Bénéficiaires', 'reclamations' => 'Réclamations', 'audit' => 'Journal d’audit'] as $k => $l): ?>
          <div class="list-item"><div><strong><?= e($l) ?></strong><div class="muted small">PDF à imprimer ou archiver · CSV pour Excel</div></div>
            <div class="row" style="gap:6px"><a class="btn btn-secondary btn-sm" href="export.php?type=<?= $k ?>&tout=1"><?= icon('download', 14) ?> PDF</a><a class="btn btn-ghost btn-sm" href="export.php?type=<?= $k ?>&tout=1&format=csv">CSV</a></div></div>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card col-side">
      <div class="card-head"><h2>Volume de la base</h2></div>
      <div class="card-body">
        <dl class="kv"><?php foreach ($stats as $k => $v): ?><dt><?= e($k) ?></dt><dd class="mono"><?= money($v) ?></dd><?php endforeach; ?></dl>
        <div class="alert alert-info small" style="margin-top:12px">Sauvegarde complète : utilisez phpMyAdmin (Exporter) ou <span class="mono">mysqldump ccad &gt; ccad.sql</span> chaque jour.</div>
        <div class="muted small">Version <?= e(APP_VERSION) ?></div>
      </div>
    </section>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
