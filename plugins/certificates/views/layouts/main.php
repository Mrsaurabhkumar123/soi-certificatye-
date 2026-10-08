<?php
declare(strict_types=1);

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$tenant = $this->plugin->tenantContext->getTenant();
$themeCss = ($tenant && isset($this->plugin->themeManager))
    ? $this->plugin->themeManager->getThemeCss($tenant)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'SOI Certificate Platform') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($router->url('/assets/css/style.css')) ?>">
  <?php if ($themeCss !== ''): ?>
    <style><?= $themeCss ?></style>
  <?php endif; ?>
</head>
<body class="has-sidebar">

<div class="app-layout">
  <?php require __DIR__ . '/../partials/sidebar.php'; ?>
  
  <div class="app-main-wrapper">
    <?php require __DIR__ . '/../partials/topbar.php'; ?>
    
    <main class="main-content" id="main-content">
      <?php require __DIR__ . '/../partials/flash.php'; ?>
      <?= $content ?? '' ?>
    </main>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var toggle = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('app-sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function() {
      sidebar.classList.toggle('open');
    });
  }
});
</script>
</body>
</html>
