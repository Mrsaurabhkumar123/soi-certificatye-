<?php
declare(strict_types=1);

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$tenant = $this->plugin->tenantContext->getTenant();
?>
<header class="app-header">
  <div class="brand-section">
    <a href="<?= htmlspecialchars($router->url('/console')) ?>" class="brand-title">
      SOI Certificate Platform
    </a>
    <?php if ($tenant): ?>
      <span class="tenant-badge"><?= htmlspecialchars($tenant->displayName) ?></span>
    <?php endif; ?>
  </div>

  <nav class="nav-links" aria-label="Portal navigation">
    <a href="<?= htmlspecialchars($router->url('/console')) ?>" class="nav-link <?= str_contains($currentUri, '/console') ? 'active' : '' ?>">Console</a>
    <a href="<?= htmlspecialchars($router->url('/manage')) ?>" class="nav-link <?= str_contains($currentUri, '/manage') ? 'active' : '' ?>">Manage</a>
    <a href="<?= htmlspecialchars($router->url('/super-admin')) ?>" class="nav-link <?= str_contains($currentUri, '/super-admin') ? 'active' : '' ?>">Super Admin</a>
    <a href="<?= htmlspecialchars($router->url('/docs')) ?>" class="nav-link <?= str_contains($currentUri, '/docs') ? 'active' : '' ?>">Docs</a>
  </nav>
</header>
