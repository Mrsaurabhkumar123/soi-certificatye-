<?php
declare(strict_types=1);
/**
 * Safe neutral verification placeholder template.
 * Prevents user enumeration and information leakage on unknown, disabled, or pending verification tokens.
 *
 * @var string|null $verificationToken
 * @var string|null $placeholderTitle
 * @var string|null $placeholderMessage
 */

$title = $placeholderTitle ?? 'Registry Verification Record';
$message = $placeholderMessage ?? 'No public record is currently available for this verification identifier, or verification is restricted by the issuing organization.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Certificate Verification - Official Registry</title>
  <style>
    :root {
      --bg-color: #f8fafc;
      --card-bg: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --neutral-icon-bg: #f1f5f9;
      --neutral-icon-color: #475569;
    }
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background-color: var(--bg-color);
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
    }
    .verify-container {
      width: 100%;
      max-width: 480px;
      padding: 1.5rem;
      box-sizing: border-box;
    }
    .verify-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 2.5rem 2rem;
      text-align: center;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    }
    .verify-icon-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--neutral-icon-bg);
      color: var(--neutral-icon-color);
      margin-bottom: 1.5rem;
      font-size: 28px;
      font-weight: bold;
    }
    h1 {
      font-size: 1.35rem;
      font-weight: 600;
      color: var(--text-main);
      margin: 0 0 0.75rem 0;
    }
    p {
      font-size: 0.925rem;
      line-height: 1.5;
      color: var(--text-muted);
      margin: 0 0 1.5rem 0;
    }
    .token-pill {
      display: inline-block;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.8rem;
      color: var(--text-muted);
      background: #f1f5f9;
      border: 1px solid var(--border-color);
      padding: 0.25rem 0.75rem;
      border-radius: 6px;
      word-break: break-all;
      margin-bottom: 1.5rem;
    }
    .verify-footer {
      border-top: 1px solid var(--border-color);
      padding-top: 1.25rem;
      margin-top: 1rem;
      font-size: 0.775rem;
      color: var(--text-muted);
    }
  </style>
</head>
<body>

<div class="verify-container">
  <div class="verify-card">
    <div class="verify-icon-wrap">
      <span>&#9671;</span>
    </div>

    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>

    <?php if (!empty($verificationToken)): ?>
      <div class="token-pill">
        ID: <?= htmlspecialchars($verificationToken) ?>
      </div>
    <?php endif; ?>

    <div class="verify-footer">
      Official Registry &bull; Self-Hosted Verification Portal
    </div>
  </div>
</div>

</body>
</html>
