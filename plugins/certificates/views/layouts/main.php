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
<body>

<?php require __DIR__ . '/../partials/navbar.php'; ?>

<main class="main-content" id="main-content">
  <?php require __DIR__ . '/../partials/flash.php'; ?>
  <?= $content ?? '' ?>
</main>

</body>
</html>
