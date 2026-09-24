<?php
/**
 * Installation : crée la base, les tables et le compte administrateur.
 * À exécuter une seule fois (http://localhost/ccad-gestion/install.php),
 * puis SUPPRIMER ce fichier.
 */
require_once __DIR__ . '/config.php';

$log = [];
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    // En local on crée la base ; chez un hébergeur elle existe déjà (droit CREATE souvent refusé)
    try { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch (PDOException $e) {}
    $pdo->exec('USE `' . DB_NAME . '`');

    // 1. Schéma
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    $log[] = 'Base « ' . DB_NAME . ' » et tables créées.';

    $pdo->exec('USE `' . DB_NAME . '`');

    // 2. Compte administrateur (les autres comptes se créent dans Paramètres › Utilisateurs)
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO users (nom, login, password_hash, role, zone) VALUES (?,?,?,?,?)')
            ->execute(['Administrateur', 'admin', password_hash('ccad2026', PASSWORD_DEFAULT), 'Administrateur', 'Siège']);
        $log[] = 'Compte « admin » créé (mot de passe provisoire : ccad2026 — à changer dès la première connexion).';
    }

    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $log[] = 'Erreur : ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Installation CCAD</title>
<link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-body">
<div class="card" style="max-width:560px;margin:60px auto">
  <div class="card-head"><h2>Installation de CCAD</h2></div>
  <div class="card-body">
    <?php foreach ($log as $l): ?><p><?= htmlspecialchars($l) ?></p><?php endforeach; ?>
    <?php if ($ok): ?>
      <div class="alert alert-warning">Supprimez maintenant le fichier <code>install.php</code> du serveur.</div>
      <a class="btn btn-primary" href="login.php">Aller à la connexion</a>
    <?php endif; ?>
  </div>
</div>
</body></html>
