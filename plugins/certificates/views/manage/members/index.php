<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Tenant Membership Management Partial
 * Lists active tenant members, role editor, add member form, and deactivation safeguards.
 *
 * @var array $members
 * @var bool $canManageMembers
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div class="card" id="tenant-members-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Tenant Members</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
        Assign CMS accounts tenant-scoped roles. The host CMS integration validates account IDs.
      </p>
    </div>
  </div>
  <?php if ($canManageMembers): ?>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/add')) ?>">
      <?= Session::csrfField() ?>
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label" for="member_user_id">CMS User ID</label>
          <input id="member_user_id" name="user_id" class="form-control" type="number" min="1" step="1" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="member_role_key">Tenant Role</label>
          <select id="member_role_key" name="role_key" class="form-control" required>
            <?php foreach ([
                'tenant_owner' => 'Owner',
                'tenant_admin' => 'Admin',
                'template_designer' => 'Template Designer',
                'issuer' => 'Issuer',
                'viewer' => 'Viewer'
            ] as $roleKey => $roleLabel): ?>
              <option value="<?= htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="align-self:end;">
          <button type="submit" class="btn btn-primary btn-sm">Add or restore member</button>
        </div>
      </div>
    </form>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>CMS User ID</th>
            <th>Role</th>
            <th>Membership Since</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($members)): ?>
            <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No active members.</td></tr>
          <?php else: ?>
            <?php foreach ($members as $member): ?>
              <tr>
                <td><strong>User #<?= (int)$member['user_id'] ?></strong></td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/role')) ?>" style="display:flex; gap:0.5rem; align-items:center;">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                    <select name="role_key" class="form-control" aria-label="Role for CMS user <?= (int)$member['user_id'] ?>">
                      <?php foreach ([
                          'tenant_owner' => 'Owner',
                          'tenant_admin' => 'Admin',
                          'template_designer' => 'Template Designer',
                          'issuer' => 'Issuer',
                          'viewer' => 'Viewer'
                      ] as $roleKey => $roleLabel): ?>
                        <option value="<?= htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8') ?>" <?= $member['role_key'] === $roleKey ? 'selected' : '' ?>>
                          <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Update</button>
                  </form>
                </td>
                <td><?= htmlspecialchars($member['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/members/deactivate')) ?>" onsubmit="return confirm('Deactivate this membership?');">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Deactivate</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p style="color: var(--text-muted); font-size: 0.9rem;">Your role does not permit managing tenant memberships.</p>
  <?php endif; ?>
</div>
