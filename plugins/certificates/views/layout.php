<?php
declare(strict_types=1);
use SOI\Certificates\Core\Session;

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$successMsg = Session::flash('success');
$errorMsg = Session::flash('error');
$tenant = $this->plugin->tenantContext->getTenant();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'SOI Certificate Platform') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($router->url('/assets/css/style.css')) ?>">
</head>
<body>

<header class="app-header">
  <div class="brand-section">
    <a href="<?= htmlspecialchars($router->url('/console')) ?>" class="brand-title">
      SOI Certificate Platform
    </a>
    <?php if ($tenant): ?>
      <span class="tenant-badge"><?= htmlspecialchars($tenant->displayName) ?></span>
    <?php endif; ?>
  </div>

  <nav class="nav-links">
    <a href="<?= htmlspecialchars($router->url('/console')) ?>" class="nav-link <?= str_contains($currentUri, '/console') ? 'active' : '' ?>">Console</a>
    <a href="<?= htmlspecialchars($router->url('/manage')) ?>" class="nav-link <?= str_contains($currentUri, '/manage') ? 'active' : '' ?>">Manage</a>
    <a href="<?= htmlspecialchars($router->url('/super-admin')) ?>" class="nav-link <?= str_contains($currentUri, '/super-admin') ? 'active' : '' ?>">Super Admin</a>
    <a href="<?= htmlspecialchars($router->url('/docs')) ?>" class="nav-link <?= str_contains($currentUri, '/docs') ? 'active' : '' ?>">Docs</a>
  </nav>
</header>

<main class="main-content">
  <?php if ($successMsg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
    <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
  <?php endif; ?>

  <?= $content ?? '' ?>
</main>

</body>
</html>
