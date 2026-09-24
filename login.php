<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) redirect('index.php');

$erreur = '';
$info = get('expire') === '1' ? 'Votre session a expiré. Reconnectez-vous.' : (get('sortie') === '1' ? 'Vous êtes déconnecté.' : '');
$login = mb_substr((string)post('login'), 0, 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($minutes = connexion_bloquee($login)) {
        $erreur = "Trop de tentatives échouées. Réessayez dans $minutes minute(s).";
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE login = ?');
        $st->execute([$login]);
        $user = $st->fetch();
        // Vérification même si le compte n'existe pas : même durée de réponse, pas d'indice sur les identifiants valides
        $hash = $user['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
        $ok = password_verify((string)post('password'), $hash);
        if ($ok && $user && $user['actif']) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $hash = password_hash((string)post('password'), PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
            }
            effacer_echecs($login);
            ouvrir_session($user, $hash);
            db()->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);
            audit('Connexion', $user['login'], 'Ouverture de session');
            redirect($user['doit_changer_mdp'] ? 'mon-compte.php?obligatoire=1' : 'index.php');
        }
        noter_echec($login);
        audit('Échec connexion', $login !== '' ? $login : '—', $user && !$user['actif'] ? 'Compte désactivé' : 'Identifiant ou mot de passe incorrect');
        $erreur = 'Identifiant ou mot de passe incorrect.';
        if ($user && $ok && !$user['actif']) $erreur = 'Ce compte est désactivé. Contactez l’administrateur.';
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Connexion · CCAD Gestion interne</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Lora:ital,wght@0,500..700;1,500&family=Public+Sans:wght@300..800&display=swap">
  <link rel="icon" href="assets/img/logo-ccad.jpg">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth">
  <div class="auth-left">
    <img class="auth-watermark" src="assets/img/logo-ccad.jpg" alt="">
    <div class="row">
      <?= seal(64) ?>
      <div>
        <div class="auth-brand">CCAD</div>
        <div class="auth-brand-sub">Confiance, Compagnie d’Assurance de Décès</div>
      </div>
    </div>
    <div class="auth-hero">
      <div class="auth-rule"></div>
      <h1>Application de gestion interne</h1>
      <p>Adhésions, cotisations, relances et réclamations, réunies en un seul espace sécurisé.</p>
      <blockquote>« <?= e(setting('org_slogan')) ?> »</blockquote>
    </div>
    <div class="auth-foot mono"><?= e(setting('org_adresse')) ?> · <?= e(setting('org_tel')) ?></div>
  </div>
  <div class="auth-right">
    <form class="auth-card" method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="auth-card-logo"><?= seal(72) ?></div>
      <div>
        <h2>Bon retour</h2>
        <div class="muted">Connectez-vous avec les identifiants fournis par l’administrateur.</div>
      </div>
      <?php if ($erreur): ?><div class="alert alert-danger" role="alert"><?= e($erreur) ?></div><?php endif; ?>
      <?php if ($info && !$erreur): ?><div class="alert alert-info"><?= e($info) ?></div><?php endif; ?>
      <div class="field">
        <label for="login">Identifiant</label>
        <div class="input-icon"><?= icon('user', 16) ?><input class="input" id="login" name="login" required maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus value="<?= e($login) ?>"></div>
      </div>
      <div class="field">
        <label for="password">Mot de passe</label>
        <div class="input-icon"><?= icon('lock', 16) ?><input class="input" id="password" name="password" type="password" required maxlength="200" autocomplete="current-password">
          <button type="button" class="pw-toggle" data-pw-toggle="password" aria-label="Afficher le mot de passe">Afficher</button></div>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Se connecter</button>
      <div class="auth-note"><?= icon('shield', 14) ?> Accès réservé au personnel · session fermée après <?= (int)(SESSION_TIMEOUT / 60) ?> min d’inactivité</div>
    </form>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
