<?php
// Top bar shared by the operator pages. Set $page_title and $active first.
$active = $active ?? '';
$base   = app_url('');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'Smart Bin') ?></title>
<link rel="stylesheet" href="<?= e($base) ?>assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<?= $extra_head ?? '' ?>
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="logo">♻</span> Smart Bin</div>
  <nav>
    <a href="<?= e($base) ?>index.php" class="<?= $active === 'dash' ? 'active' : '' ?>">Dashboard</a>
    <a href="<?= e($base) ?>settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Settings</a>
    <a href="<?= e($base) ?>tools/simulator.php" class="<?= $active === 'sim' ? 'active' : '' ?>">Simulator</a>
  </nav>
  <div class="who">Signed in as <b><?= e(current_user()) ?></b> · <a href="<?= e($base) ?>logout.php">Log out</a></div>
</header>
