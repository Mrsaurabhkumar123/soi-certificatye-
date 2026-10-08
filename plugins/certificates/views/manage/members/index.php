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
<div class="card" id="members-section">
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

<!-- Section 8: Roles & Granular Permissions Matrix -->
<div class="card" id="roles-section">
  <div class="card-header">
    <div>
      <h2 class="card-title">Roles & Granular Permissions Matrix</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">
        Decoupled access model: users are accounts, roles define organizational responsibilities, and granular permissions enforce server-side operations.
      </p>
    </div>
  </div>

  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>Baseline Role</th>
          <th>Responsibility / Scope</th>
          <th>Key Granular Permissions</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Tenant Owner</strong></td>
          <td>Full operational & administrative authority over workspace</td>
          <td><code>tenants.*</code>, <code>users.*</code>, <code>roles.*</code>, <code>templates.*</code>, <code>certificates.*</code>, <code>settings.*</code></td>
          <td><span class="badge badge-success">System Baseline</span></td>
        </tr>
        <tr>
          <td><strong>Tenant Administrator</strong></td>
          <td>Administrative oversight and template/issuance operations</td>
          <td><code>templates.*</code>, <code>certificates.*</code>, <code>users.*</code>, <code>reports.*</code>, <code>api_clients.*</code></td>
          <td><span class="badge badge-success">System Baseline</span></td>
        </tr>
        <tr>
          <td><strong>Template Designer</strong></td>
          <td>Creating, drafting, and version publishing of reusable templates</td>
          <td><code>templates.read</code>, <code>templates.create</code>, <code>templates.update</code>, <code>templates.publish</code>, <code>templates.archive</code></td>
          <td><span class="badge badge-success">System Baseline</span></td>
        </tr>
        <tr>
          <td><strong>Issuer / Operator</strong></td>
          <td>Operational day-to-day issuance, batch imports, replacement & revocation</td>
          <td><code>certificates.read</code>, <code>certificates.issue</code>, <code>certificates.download</code>, <code>certificates.revoke</code>, <code>certificates.replace</code></td>
          <td><span class="badge badge-success">System Baseline</span></td>
        </tr>
        <tr>
          <td><strong>Viewer / Auditor</strong></td>
          <td>Read-only inspection and compliance audit access</td>
          <td><code>templates.read</code>, <code>certificates.read</code>, <code>certificates.download</code>, <code>audit.read</code></td>
          <td><span class="badge badge-success">System Baseline</span></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
