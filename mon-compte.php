<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
$obligatoire = (bool)$u['doit_changer_mdp'];

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $actuel = (string)post('actuel');
    $nouveau = (string)post('nouveau');
    $hash = (string)scalar('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);

    if ($minutes = connexion_bloquee($u['login'])) {
        $erreur = "Trop de tentatives échouées. Réessayez dans $minutes minute(s).";
    } elseif (!password_verify($actuel, $hash)) {
        noter_echec($u['login']);
        audit('Échec connexion', $u['login'], 'Mot de passe actuel incorrect (changement de mot de passe)');
        $erreur = 'Le mot de passe actuel est incorrect.';
    } elseif ($nouveau !== (string)post('confirmation')) {
        $erreur = 'La confirmation ne correspond pas au nouveau mot de passe.';
    } elseif (password_verify($nouveau, $hash)) {
        $erreur = 'Le nouveau mot de passe doit être différent de l’ancien.';
    } elseif ($msg = erreur_mdp($nouveau, $u['login'])) {
        $erreur = $msg;
    } else {
        $nouveauHash = password_hash($nouveau, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ?, doit_changer_mdp = 0, mdp_change_le = NOW() WHERE id = ?')
            ->execute([$nouveauHash, $u['id']]);
        effacer_echecs($u['login']);
        // Nouvelle session : les autres appareils connectés avec l'ancien mot de passe sont déconnectés
        ouvrir_session(array_merge($u, ['doit_changer_mdp' => 0]), $nouveauHash);
        audit('Utilisateur', $u['login'], 'Mot de passe changé par l’utilisateur');
        flash('success', 'Mot de passe changé. Les autres sessions ouvertes avec l’ancien mot de passe sont fermées.');
        redirect($obligatoire ? 'index.php' : 'mon-compte.php');
    }
}

$connexions = rows("SELECT created_at, ip, detail, type FROM audit WHERE cible = ? AND type IN ('Connexion','Échec connexion')
    ORDER BY id DESC LIMIT 8", [$u['login']]);

$page_title = 'Mon compte';
$active = 'compte';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <div class="crumbs">CCAD › Mon compte</div>
    <h1>Mon compte</h1>
    <div class="meta"><?= e($u['role']) ?><?= $u['departement'] ? ' · ' . e($u['departement']) : '' ?><?= $u['zone'] ? ' · ' . e($u['zone']) : '' ?></div>
  </div>
</div>

<?php if ($obligatoire): ?>
  <div class="alert alert-warning"><strong>Mot de passe provisoire.</strong> Choisissez votre propre mot de passe pour accéder à l’application.</div>
<?php endif; ?>

<div class="cols">
  <section class="card col-main">
    <div class="card-head"><div><h2>Changer mon mot de passe</h2><div class="sub"><?= $u['mdp_change_le'] ? 'Dernier changement le ' . date('d/m/Y', strtotime($u['mdp_change_le'])) : 'Jamais changé' ?></div></div><?= icon('lock', 18) ?></div>
    <form method="post" class="card-body stack" autocomplete="off">
      <?= csrf_field() ?>
      <?php if ($erreur): ?><div class="alert alert-danger" role="alert"><?= e($erreur) ?></div><?php endif; ?>
      <div class="field"><label for="actuel">Mot de passe actuel</label>
        <div class="input-icon"><?= icon('lock', 16) ?><input class="input" type="password" id="actuel" name="actuel" required autocomplete="current-password">
          <button type="button" class="pw-toggle" data-pw-toggle="actuel">Afficher</button></div></div>
      <div class="field"><label for="nouveau">Nouveau mot de passe</label>
        <div class="input-icon"><?= icon('lock', 16) ?><input class="input" type="password" id="nouveau" name="nouveau" required minlength="10" autocomplete="new-password" data-pw-strength="pw-meter">
          <button type="button" class="pw-toggle" data-pw-toggle="nouveau">Afficher</button></div>
        <div class="pw-meter" id="pw-meter"><span></span></div>
        <ul class="pw-rules" data-pw-rules="nouveau">
          <li data-rule="len">10 caractères minimum</li>
          <li data-rule="case">Majuscules et minuscules</li>
          <li data-rule="digit">Au moins un chiffre</li>
        </ul>
      </div>
      <div class="field"><label for="confirmation">Confirmer le nouveau mot de passe</label>
        <div class="input-icon"><?= icon('lock', 16) ?><input class="input" type="password" id="confirmation" name="confirmation" required autocomplete="new-password"></div></div>
      <div><button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Enregistrer le mot de passe</button></div>
    </form>
  </section>

  <div class="col-side">
    <section class="card">
      <div class="card-head"><h2>Mon profil</h2></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Nom</dt><dd><?= e($u['nom']) ?></dd>
          <dt>Identifiant</dt><dd><?= e($u['login']) ?></dd>
          <dt>Rôle</dt><dd><?= e($u['role']) ?></dd>
          <dt>Téléphone</dt><dd><?= e($u['telephone'] ?: '—') ?></dd>
          <dt>Courriel</dt><dd><?= e($u['email'] ?: '—') ?></dd>
        </dl>
        <p class="muted small" style="margin:12px 0 0">Pour modifier ces informations, contactez l’administrateur.</p>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><div><h2>Activité récente</h2><div class="sub">Vérifiez qu’aucune connexion ne vous est inconnue</div></div></div>
      <div class="card-body stack">
        <?php foreach ($connexions as $c): $echec = $c['type'] === 'Échec connexion'; ?>
          <div class="list-item">
            <span class="badge badge-<?= $echec ? 'danger' : 'success' ?>"><?= $echec ? 'Échec' : 'OK' ?></span>
            <div style="flex:1"><div class="t"><?= e($c['detail']) ?></div><div class="d mono"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?> · <?= e($c['ip'] ?: '—') ?></div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$connexions): ?><div class="muted small">Aucune activité enregistrée.</div><?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
