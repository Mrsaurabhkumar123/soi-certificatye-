<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Manual Certificate Issuance Form Partial
 * Presents template selection, dynamic variable entry, recipient details, and one-click issuance.
 *
 * @var array<\SOI\Certificates\Templates\Template> $templates
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="console-issue-card">
  <div class="card-header">
    <h2 class="card-title">Issue New Certificate</h2>
  </div>

  <?php if (empty($templates)): ?>
    <p style="color: var(--danger); font-size: 0.9rem;">
      No published templates available for issuance. Go to <a href="<?= htmlspecialchars($this->plugin->router->url('/manage')) ?>">Manage</a> to publish a template first.
    </p>
  <?php else: ?>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/console/issue')) ?>">
      <?= Session::csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="issue_template_id">Select Published Template</label>
        <select id="issue_template_id" name="template_id" class="form-control" required>
          <?php foreach ($templates as $tpl): ?>
            <option value="<?= (int)$tpl->id ?>"><?= htmlspecialchars($tpl->name, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="issue_recipient_name">Recipient Full Name</label>
        <input id="issue_recipient_name" type="text" name="recipient_name" class="form-control" placeholder="e.g. Rahul Sharma" required maxlength="255">
      </div>

      <div class="form-group">
        <label class="form-label" for="issue_recipient_email">Recipient Email (Optional)</label>
        <input id="issue_recipient_email" type="email" name="recipient_email" class="form-control" placeholder="rahul@example.com" maxlength="255">
      </div>

      <div class="form-group">
        <label class="form-label" for="issue_course_name">Course / Program / Title</label>
        <input id="issue_course_name" type="text" name="course_name" class="form-control" placeholder="e.g. Full Stack Web Development Internship" required maxlength="255">
      </div>

      <div class="form-group">
        <label class="form-label" for="issue_date">Date of Issue</label>
        <input id="issue_date" type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>

      <button type="submit" class="btn btn-primary" style="width: 100%;">
        Generate &amp; Issue Official Certificate
      </button>
    </form>
  <?php endif; ?>
</div>
