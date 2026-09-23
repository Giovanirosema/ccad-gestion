<?php
/** @var string $page_title  @var string $active */
$u = current_user();
$nav = [
    ['section' => 'Gestion'],
    ['k' => 'dashboard',    'url' => 'index.php',        'label' => 'Tableau de bord', 'icon' => 'chart',    'perm' => null],
    ['k' => 'assures',      'url' => 'assures.php',      'label' => 'Assurés',         'icon' => 'users',    'perm' => 'assures'],
    ['k' => 'polices',      'url' => 'polices.php',      'label' => 'Polices',         'icon' => 'file',     'perm' => 'polices'],
    ['k' => 'paiements',    'url' => 'paiements.php',    'label' => 'Paiements',       'icon' => 'card',     'perm' => 'paiements'],
    ['k' => 'relances',     'url' => 'relances.php',     'label' => 'Relances',        'icon' => 'bell',     'perm' => 'paiements'],
    ['k' => 'reclamations', 'url' => 'reclamations.php', 'label' => 'Réclamations',    'icon' => 'folder',   'perm' => 'reclamations'],
    ['section' => 'Administration'],
    ['k' => 'rapports',     'url' => 'rapports.php',     'label' => 'Rapports',        'icon' => 'chart',    'perm' => 'rapports'],
    ['k' => 'supervision',  'url' => 'supervision.php',  'label' => 'Supervision',     'icon' => 'users',    'perm' => 'rapports'],
    ['k' => 'audit',       'url' => 'audit.php',        'label' => 'Journal d’audit', 'icon' => 'shield',   'perm' => 'parametres'],
    ['k' => 'parametres',   'url' => 'parametres.php',   'label' => 'Paramètres',      'icon' => 'settings', 'perm' => 'parametres'],
];
// Retire les entrées non autorisées et les sections vides
$visible = [];
foreach ($nav as $i => $it) {
    if (isset($it['section'])) {
        $next = array_slice($nav, $i + 1);
        foreach ($next as $n) {
            if (isset($n['section'])) break;
            if (!$n['perm'] || can($n['perm'])) { $visible[] = $it; break; }
        }
    } elseif (!$it['perm'] || can($it['perm'])) {
        $visible[] = $it;
    }
}
$initials = '';
foreach (array_slice(explode(' ', $u['nom']), 0, 2) as $w) $initials .= mb_substr($w, 0, 1);
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?> · CCAD Gestion interne</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lora:ital,wght@0,500..700;1,500&family=Public+Sans:wght@300..800&display=swap">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <?= seal(48) ?>
      <div><div class="brand-name">CCAD</div><div class="brand-sub">Gestion interne</div></div>
    </div>
    <nav>
      <?php foreach ($visible as $it): ?>
        <?php if (isset($it['section'])): ?>
          <div class="nav-section"><?= e($it['section']) ?></div>
        <?php else: ?>
          <a href="<?= e($it['url']) ?>" class="nav-item<?= $active === $it['k'] ? ' is-active' : '' ?>">
            <?= icon($it['icon']) ?><span><?= e($it['label']) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <a class="nav-logout" href="logout.php"><?= icon('logout', 16) ?> Se déconnecter</a>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu">☰</button>
      <form class="search" action="assures.php" method="get">
        <?= icon('search', 16) ?>
        <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Rechercher un assuré, une police, un reçu">
      </form>
      <div class="spacer"></div>
      <div class="user-chip">
        <div class="avatar avatar-brand"><?= e(mb_strtoupper($initials)) ?></div>
        <div class="user-meta"><div class="user-name"><?= e($u['nom']) ?></div><div class="user-role"><?= e($u['role']) ?></div></div>
      </div>
    </header>

    <main class="content">
      <?php foreach (take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
      <?php endforeach; ?>
