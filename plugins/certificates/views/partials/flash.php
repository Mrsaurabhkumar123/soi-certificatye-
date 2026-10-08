<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

$successMsg = Session::flash('success');
$errorMsg = Session::flash('error');
?>
<?php if ($successMsg): ?>
  <div class="alert alert-success" role="alert" aria-live="polite">
    <?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
  <div class="alert alert-error" role="alert" aria-live="assertive">
    <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>
