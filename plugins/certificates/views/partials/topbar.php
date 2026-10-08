<?php
declare(strict_types=1);

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$tenant = $this->plugin->tenantContext->getTenant();
?>
<header class="app-topbar">
  <div class="topbar-left">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle navigation menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    
    <div class="topbar-breadcrumbs">
      <span class="crumb-root">Platform</span>
      <span class="crumb-separator">/</span>
      <span class="crumb-current"><?= htmlspecialchars($pageTitle ?? 'Portal') ?></span>
    </div>
  </div>

  <div class="topbar-right">
    <?php if ($tenant): ?>
      <span class="tenant-pill">
        <span class="tenant-dot"></span>
        <?= htmlspecialchars($tenant->displayName) ?>
      </span>
    <?php endif; ?>
    
    <div class="quick-links">
      <a href="<?= htmlspecialchars($router->url('/console')) ?>" class="top-nav-link <?= str_starts_with($currentUri, '/console') ? 'active' : '' ?>">Console</a>
      <a href="<?= htmlspecialchars($router->url('/manage')) ?>" class="top-nav-link <?= str_starts_with($currentUri, '/manage') ? 'active' : '' ?>">Manage</a>
      <?php if ($this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::PLATFORM_READ)): ?>
        <a href="<?= htmlspecialchars($router->url('/super-admin')) ?>" class="top-nav-link <?= str_starts_with($currentUri, '/super-admin') ? 'active' : '' ?>">Super Admin</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($router->url('/docs')) ?>" class="top-nav-link <?= str_starts_with($currentUri, '/docs') ? 'active' : '' ?>">Docs</a>
    </div>
  </div>
</header>
