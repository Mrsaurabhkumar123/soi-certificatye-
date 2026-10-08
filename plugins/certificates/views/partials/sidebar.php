<?php
declare(strict_types=1);

use SOI\Certificates\Authorization\Permissions;

/** @var \SOI\Certificates\Core\Plugin $this->plugin */
$router = $this->plugin->router;
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$tenant = $this->plugin->tenantContext->getTenant();
$authorizer = $this->plugin->authorizer;
$userRole = $this->plugin->tenantContext->getRoleKey();
$userId = $this->plugin->tenantContext->getCurrentUserId();

$canViewTemplates = $authorizer->can(Permissions::TEMPLATES_READ);
$canViewCertificates = $authorizer->can(Permissions::CERTIFICATES_READ);
$canManageUsers = $authorizer->can(Permissions::USERS_MANAGE) || $authorizer->can(Permissions::ROLES_MANAGE);
$canViewUsers = $authorizer->can(Permissions::USERS_READ) || $authorizer->can(Permissions::ROLES_READ) || $canManageUsers;
$canManageRoles = $authorizer->can(Permissions::ROLES_READ) || $authorizer->can(Permissions::ROLES_MANAGE);
$canManageApi = $authorizer->can(Permissions::API_CLIENTS_MANAGE) || $authorizer->can(Permissions::WEBHOOKS_MANAGE);
$canViewAuditReports = $authorizer->can(Permissions::AUDIT_READ) || $authorizer->can(Permissions::REPORTS_EXPORT);
$canManageSettings = $authorizer->can(Permissions::SETTINGS_MANAGE) || $authorizer->can(Permissions::VERIFICATION_MANAGE);
$isSuperAdmin = $authorizer->can(Permissions::PLATFORM_READ);

$isActive = function(string $path) use ($currentUri): bool {
    return str_starts_with($currentUri, $path);
};
?>
<aside class="app-sidebar" id="app-sidebar" aria-label="Main Navigation">
  <div class="sidebar-header">
    <a href="<?= htmlspecialchars($router->url('/manage')) ?>" class="sidebar-brand">
      <div class="sidebar-logo-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="8" r="7"></circle>
          <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>
        </svg>
      </div>
      <div class="sidebar-brand-text">
        <span class="brand-name">SOI Certificate</span>
        <span class="brand-tag">Platform</span>
      </div>
    </a>
  </div>

  <?php if ($tenant): ?>
    <div class="sidebar-workspace">
      <div class="workspace-label">Current Workspace</div>
      <div class="workspace-card" title="<?= htmlspecialchars($tenant->displayName) ?>">
        <div class="workspace-avatar"><?= htmlspecialchars(strtoupper(substr($tenant->displayName, 0, 2))) ?></div>
        <div class="workspace-info">
          <div class="workspace-name"><?= htmlspecialchars($tenant->displayName) ?></div>
          <div class="workspace-slug">ID: <?= htmlspecialchars($tenant->slug) ?></div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <nav class="sidebar-nav" aria-label="Side menu navigation">
    <div class="nav-group-title">Main Surfaces</div>
    
    <a href="<?= htmlspecialchars($router->url('/console')) ?>" 
       class="sidebar-nav-item <?= $isActive('/console') ? 'active' : '' ?>"
       title="Operational issuance and lookup">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
        <line x1="8" y1="21" x2="16" y2="21"></line>
        <line x1="12" y1="17" x2="12" y2="21"></line>
      </svg>
      <span>Dashboard / Console</span>
    </a>

    <?php if ($canViewTemplates): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#templates-section')) ?>" 
         class="sidebar-nav-item <?= ($isActive('/manage') && !str_contains($currentUri, 'settings')) ? 'active' : '' ?>"
         title="Reusable Template Library & Designer">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <span>Templates Library</span>
        <span class="nav-pill">Reusable</span>
      </a>
    <?php endif; ?>

    <?php if ($canViewCertificates): ?>
      <a href="<?= htmlspecialchars($router->url('/console#registry-table')) ?>" 
         class="sidebar-nav-item"
         title="Certificates registry & issuance">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
          <line x1="16" y1="2" x2="16" y2="6"></line>
          <line x1="8" y1="2" x2="8" y2="6"></line>
          <line x1="3" y1="10" x2="21" y2="10"></line>
        </svg>
        <span>Certificates Registry</span>
      </a>
    <?php endif; ?>

    <div class="nav-group-title">Administration & Access</div>

    <?php if ($canViewUsers): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#members-section')) ?>" 
         class="sidebar-nav-item"
         title="Manage tenant members and managers">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
          <circle cx="9" cy="7" r="4"></circle>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
        </svg>
        <span>Users & Managers</span>
      </a>
    <?php endif; ?>

    <?php if ($canManageRoles): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#roles-section')) ?>" 
         class="sidebar-nav-item"
         title="Roles and granular permissions">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        <span>Roles & Permissions</span>
      </a>
    <?php endif; ?>

    <?php if ($canManageApi): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#api-section')) ?>" 
         class="sidebar-nav-item"
         title="API Clients & Webhooks">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="16 18 22 12 16 6"></polyline>
          <polyline points="8 6 2 12 8 18"></polyline>
        </svg>
        <span>API & Integrations</span>
      </a>
    <?php endif; ?>

    <?php if ($canViewAuditReports): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#audit-section')) ?>" 
         class="sidebar-nav-item"
         title="Audit trails and exportable reports">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
        </svg>
        <span>Reports & Audit</span>
      </a>
    <?php endif; ?>

    <?php if ($canManageSettings): ?>
      <a href="<?= htmlspecialchars($router->url('/manage#settings-section')) ?>" 
         class="sidebar-nav-item"
         title="Tenant branding, CSS, and verification privacy">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        <span>Settings & Branding</span>
      </a>
    <?php endif; ?>

    <div class="nav-group-title">Platform & Resources</div>

    <?php if ($isSuperAdmin): ?>
      <a href="<?= htmlspecialchars($router->url('/super-admin')) ?>" 
         class="sidebar-nav-item <?= $isActive('/super-admin') ? 'active' : '' ?>"
         title="Platform administration and tenant management">
        <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
          <path d="M2 17l10 5 10-5"></path>
          <path d="M2 12l10 5 10-5"></path>
        </svg>
        <span>Platform Super Admin</span>
        <span class="nav-pill nav-pill-admin">Root</span>
      </a>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($router->url('/docs')) ?>" 
       class="sidebar-nav-item <?= $isActive('/docs') ? 'active' : '' ?>"
       title="API & integration documentation">
      <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
      </svg>
      <span>API & Docs</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-badge">
      <div class="user-avatar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>
      </div>
      <div class="user-details">
        <span class="user-name">User #<?= htmlspecialchars((string)($userId ?? 'Guest')) ?></span>
        <span class="user-role"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($userRole ?? 'Viewer')))) ?></span>
      </div>
    </div>
  </div>
</aside>
