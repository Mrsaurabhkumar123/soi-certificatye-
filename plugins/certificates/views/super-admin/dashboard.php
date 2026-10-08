<?php
declare(strict_types=1);

/**
 * Super-Admin Operational Dashboard Partial
 * Displays global platform statistics across all tenants, issuance volume, and platform health.
 *
 * @var int $activeTenantCount
 * @var int $totalCerts
 * @var int $suspendedTenantCount
 * @var array $health
 */
?>
<section class="grid-4" aria-label="Super-admin operational metrics" id="super-admin-analytics" style="margin-bottom: 2rem;">
  <article class="stat-box">
    <span class="stat-label">Active Tenants</span>
    <span class="stat-value"><?= (int)$activeTenantCount ?></span>
  </article>

  <article class="stat-box">
    <span class="stat-label">Total Certificates Issued</span>
    <span class="stat-value"><?= (int)$totalCerts ?></span>
  </article>

  <article class="stat-box">
    <span class="stat-label">Suspended Tenants</span>
    <span class="stat-value"><?= (int)$suspendedTenantCount ?></span>
  </article>

  <article class="stat-box">
    <span class="stat-label">Platform Health</span>
    <span class="stat-value" style="color: <?= ($health['status'] ?? '') === 'healthy' ? 'var(--success, #16a34a)' : 'var(--danger, #ef4444)' ?>;">
      <?= strtoupper(htmlspecialchars((string)($health['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8')) ?>
    </span>
  </article>
</section>
