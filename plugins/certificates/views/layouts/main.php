<?php
declare(strict_types=1);

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$tenant = $this->plugin->tenantContext->getTenant();
$themeCss = ($tenant && isset($this->plugin->themeManager))
    ? $this->plugin->themeManager->getThemeCss($tenant)
    : '';
$cssPath = $this->plugin->baseDir . '/assets/css/style.css';
$inlinedCss = file_exists($cssPath) ? (string)file_get_contents($cssPath) : '';
$assetCssUrl = $this->plugin->assetUrl('/css/style.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'SOI Certificate Platform') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetCssUrl) ?>">
  <?php if ($inlinedCss !== ''): ?>
    <style id="soi-cert-core-css"><?= $inlinedCss ?></style>
  <?php endif; ?>
  <?php if ($themeCss !== ''): ?>
    <style id="soi-cert-tenant-css"><?= $themeCss ?></style>
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
