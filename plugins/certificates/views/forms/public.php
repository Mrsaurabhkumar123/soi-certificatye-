<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($form['tenant_name'], ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <main class="container" style="max-width:760px;margin:2rem auto;">
    <section class="card">
      <header class="card-header">
        <div>
          <h1 class="card-title"><?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p><?= htmlspecialchars($form['tenant_name'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </header>
      <form method="post" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>">
        <?= $csrfField ?>
        <?php foreach ($fields as $field): ?>
          <?php
            $name = (string)($field['name'] ?? '');
            $label = (string)($field['label'] ?? $name);
            $type = in_array(($field['type'] ?? ''), ['text', 'email', 'date', 'enum'], true) ? $field['type'] : 'text';
            $required = !empty($field['required']);
          ?>
          <div class="form-group">
            <label class="form-label" for="field_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </label>
            <?php if ($type === 'enum' && is_array($field['options'] ?? null)): ?>
              <select class="form-control" id="field_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                name="fields[<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>]"
                <?= $required ? 'required' : '' ?>>
                <option value="">Select an option</option>
                <?php foreach ($field['options'] as $option): ?>
                  <?php if (is_string($option)): ?>
                    <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" id="field_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                name="fields[<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>]"
                type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                <?= $required ? 'required' : '' ?> maxlength="2000">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Submit request</button>
      </form>
      <p class="text-muted" style="margin-top:1rem">
        <?= $form['issue_mode'] === 'immediate'
          ? 'A certificate will be issued after a valid submission.'
          : 'The organization will review your request before issuing a certificate.' ?>
      </p>
    </section>
  </main>
</body>
</html>
