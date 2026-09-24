<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) redirect('index.php');

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    // Limitation simple des tentatives par session
    $_SESSION['tentatives'] = ($_SESSION['tentatives'] ?? 0) + 1;
    if ($_SESSION['tentatives'] > 8) {
        $erreur = 'Trop de tentatives. Réessayez plus tard ou contactez l’administrateur.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE login = ? AND actif = 1');
        $st->execute([post('login')]);
        $user = $st->fetch();
        if ($user && password_verify((string)post('password'), $user['password_hash'])) {
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            $_SESSION['tentatives'] = 0;
            db()->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);
            audit('Connexion', $user['login'], 'Ouverture de session');
            redirect('index.php');
        }
        $erreur = 'Identifiant ou mot de passe incorrect.';
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion · CCAD Gestion interne</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Lora:ital,wght@0,500..700;1,500&family=Public+Sans:wght@300..800&display=swap">
  <link rel="icon" href="assets/img/logo-ccad.jpg">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth">
  <div class="auth-left">
    <div class="row">
      <?= seal(92) ?>
      <div>
        <div style="font-size:20px;font-weight:700;letter-spacing:.04em">CCAD</div>
        <div style="font-size:12px;color:rgba(255,255,255,.72)">Confiance, Compagnie d’Assurance de Décès · Camp-Perrin</div>
      </div>
    </div>
    <div style="max-width:420px">
      <div class="auth-rule"></div>
      <h1>Application de gestion interne</h1>
      <p style="margin-top:14px;color:rgba(255,255,255,.78)">Accès réservé au personnel autorisé. Les données des assurés ne quittent pas cet espace.</p>
      <p style="font-style:italic;color:var(--gold-300)">« <?= e(setting('org_slogan')) ?> »</p>
    </div>
    <div class="mono" style="font-size:11px;color:rgba(255,255,255,.55)"><?= e(setting('org_adresse')) ?> · <?= e(setting('org_tel')) ?> · v<?= APP_VERSION ?></div>
  </div>
  <div class="auth-right">
    <form class="auth-form" method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div>
        <h2 style="font-size:22px">Connexion</h2>
        <div class="muted" style="font-size:13px;margin-top:4px">Identifiants fournis par l’administrateur.</div>
      </div>
      <?php if ($erreur): ?><div class="alert alert-danger"><?= e($erreur) ?></div><?php endif; ?>
      <div class="field">
        <label for="login">Identifiant <span class="req">*</span></label>
        <input class="input" id="login" name="login" required autofocus value="<?= e(post('login')) ?>">
      </div>
      <div class="field">
        <label for="password">Mot de passe <span class="req">*</span></label>
        <input class="input" id="password" name="password" type="password" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Se connecter</button>
      <div class="muted small">Mot de passe oublié : contacter l’administrateur système.</div>
    </form>
  </div>
</div>
</body>
</html>
