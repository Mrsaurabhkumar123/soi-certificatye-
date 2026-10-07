<?php
declare(strict_types=1);
/** @var \SOI\Certificates\Verification\VerificationResult $result */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Certificate Verification - <?= htmlspecialchars($result->certificateNumber ?? 'Official Registry') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($this->plugin->router->url('/assets/css/style.css')) ?>">
</head>
<body style="background-color: #f1f5f9;">

<div class="verify-container">
  <div class="verify-card">
    <div class="verify-header">
      <?php if ($result->status === 'valid'): ?>
        <div class="verify-badge-icon" style="background-color: #dcfce7; color: #166534; font-size: 28px;">✓</div>
        <h1 style="font-size: 1.4rem; color: #166534; margin-bottom: 0.25rem;">Authentic Certificate</h1>
      <?php elseif ($result->status === 'revoked'): ?>
        <div class="verify-badge-icon" style="background-color: #fee2e2; color: #991b1b; font-size: 28px;">✕</div>
        <h1 style="font-size: 1.4rem; color: #991b1b; margin-bottom: 0.25rem;">Certificate Revoked</h1>
      <?php elseif ($result->status === 'expired'): ?>
        <div class="verify-badge-icon" style="background-color: #fef3c7; color: #92400e; font-size: 28px;">!</div>
        <h1 style="font-size: 1.4rem; color: #92400e; margin-bottom: 0.25rem;">Certificate Expired</h1>
      <?php else: ?>
        <div class="verify-badge-icon" style="background-color: #f1f5f9; color: #64748b; font-size: 28px;">?</div>
        <h1 style="font-size: 1.4rem; color: #64748b; margin-bottom: 0.25rem;">Record Not Found</h1>
      <?php endif; ?>

      <p style="font-size: 0.9rem; color: var(--text-muted);">
        <?= htmlspecialchars($result->message) ?>
      </p>
    </div>

    <div class="verify-body">
      <?php if ($result->found && $result->status !== 'disabled'): ?>
        <div class="verify-row">
          <span class="verify-label">Certificate Number</span>
          <span class="verify-val"><?= htmlspecialchars($result->certificateNumber) ?></span>
        </div>
        <div class="verify-row">
          <span class="verify-label">Recipient</span>
          <span class="verify-val"><?= htmlspecialchars($result->recipientName) ?></span>
        </div>
        <div class="verify-row">
          <span class="verify-label">Issuing Organization</span>
          <span class="verify-val"><?= htmlspecialchars($result->organizationName) ?></span>
        </div>
        <div class="verify-row">
          <span class="verify-label">Issued On</span>
          <span class="verify-val"><?= htmlspecialchars(date('F j, Y', strtotime($result->issueDate))) ?></span>
        </div>
        <div class="verify-row">
          <span class="verify-label">Registry Status</span>
          <span class="verify-val">
            <span class="badge <?= $result->status === 'valid' ? 'badge-success' : 'badge-danger' ?>">
              <?= strtoupper($result->status) ?>
            </span>
          </span>
        </div>
      <?php endif; ?>

      <div style="text-align: center; margin-top: 2rem;">
        <p style="font-size: 0.75rem; color: var(--text-muted);">
          Cryptographically recorded in SOI Certificate Platform Official Registry.
        </p>
      </div>
    </div>
  </div>
</div>

</body>
</html>
