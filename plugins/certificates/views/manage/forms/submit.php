<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

$plugin = isset($this) && isset($this->plugin) ? $this->plugin : \SOI\Certificates\Core\Plugin::getInstance();
$assetCssUrl = $plugin ? $plugin->assetUrl('/css/style.css') : '/assets/css/style.css';
$baseDir = $plugin ? $plugin->baseDir : dirname(__DIR__, 3);
$cssFile = $baseDir . '/assets/css/style.css';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($form['title'] ?? 'Certificate Request', ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($form['tenant_name'] ?? 'SOI Platform', ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetCssUrl) ?>">
  <?php if (file_exists($cssFile)): ?>
    <style><?= file_get_contents($cssFile) ?></style>
  <?php endif; ?>
  <style>
    body {
      background: var(--bg-main, #f8fafc);
      color: var(--text-main, #1e293b);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      padding: 2rem 1rem;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .form-container {
      width: 100%;
      max-width: 640px;
      background: #ffffff;
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 12px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
      overflow: hidden;
    }
    .form-header {
      background: var(--bg-card, #ffffff);
      padding: 2rem;
      border-bottom: 1px solid var(--border-color, #e2e8f0);
    }
    .form-header h1 {
      font-size: 1.5rem;
      font-weight: 700;
      margin: 0 0 0.5rem 0;
      color: var(--text-main, #0f172a);
    }
    .form-header p {
      font-size: 0.9rem;
      color: var(--text-muted, #64748b);
      margin: 0;
    }
    .form-body {
      padding: 2rem;
    }
    .form-group {
      margin-bottom: 1.25rem;
    }
    .form-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 500;
      margin-bottom: 0.375rem;
      color: var(--text-main, #334155);
    }
    .form-control {
      width: 100%;
      padding: 0.625rem 0.875rem;
      font-size: 0.95rem;
      border: 1px solid var(--border-color, #cbd5e1);
      border-radius: 6px;
      box-sizing: border-box;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--primary, #2563eb);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .btn-submit {
      width: 100%;
      padding: 0.75rem 1.25rem;
      font-size: 1rem;
      font-weight: 600;
      color: #ffffff;
      background: var(--primary, #2563eb);
      border: none;
      border-radius: 6px;
      cursor: pointer;
      transition: background-color 0.15s ease;
    }
    .btn-submit:hover {
      background: var(--primary-hover, #1d4ed8);
    }
    .form-footer-note {
      font-size: 0.85rem;
      color: var(--text-muted, #64748b);
      text-align: center;
      margin-top: 1.25rem;
      line-height: 1.4;
    }
  </style>
</head>
<body>
  <div class="form-container">
    <header class="form-header">
      <h1><?= htmlspecialchars($form['title'] ?? 'Certificate Application', ENT_QUOTES, 'UTF-8') ?></h1>
      <p><?= htmlspecialchars($form['tenant_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <div class="form-body">
      <form method="POST" action="<?= htmlspecialchars($actionUrl ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <?= $csrfField ?? (class_exists(Session::class) ? Session::csrfField() : '') ?>

        <?php if (!empty($fields) && is_array($fields)): ?>
          <?php foreach ($fields as $field): ?>
            <?php
              $fieldName = (string)($field['name'] ?? '');
              $fieldLabel = (string)($field['label'] ?? $fieldName);
              $fieldType = in_array(($field['type'] ?? ''), ['text', 'email', 'date', 'enum'], true) ? $field['type'] : 'text';
              $isRequired = !empty($field['required']);
            ?>
            <div class="form-group">
              <label class="form-label" for="input_<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isRequired): ?><span style="color: var(--danger, #ef4444);">*</span><?php endif; ?>
              </label>

              <?php if ($fieldType === 'enum' && !empty($field['options']) && is_array($field['options'])): ?>
                <select id="input_<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>"
                        name="fields[<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>]"
                        class="form-control"
                        <?= $isRequired ? 'required' : '' ?>>
                  <option value="">-- Select an option --</option>
                  <?php foreach ($field['options'] as $opt): ?>
                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars((string)$opt, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="input_<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>"
                       type="<?= htmlspecialchars($fieldType, ENT_QUOTES, 'UTF-8') ?>"
                       name="fields[<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>]"
                       class="form-control"
                       <?= $isRequired ? 'required' : '' ?>
                       maxlength="2000"
                       placeholder="Enter <?= htmlspecialchars(strtolower($fieldLabel), ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <button type="submit" class="btn-submit">Submit Certificate Request</button>

        <p class="form-footer-note">
          <?php if (($form['issue_mode'] ?? '') === 'immediate'): ?>
            An official certificate will be generated and issued immediately upon submission.
          <?php else: ?>
            This request will be placed in the administrative approval queue for review before issuance.
          <?php endif; ?>
        </p>
      </form>
    </div>
  </div>
</body>
</html>
