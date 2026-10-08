<?php
declare(strict_types=1);

/**
 * Manage Operational Dashboard Partial
 * Displays tenant analytics widgets, issuance volume metrics, pending approvals,
 * and failure/warning indicators for tenant administrators.
 *
 * @var array $dashboardMetrics
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<section class="grid-4" aria-label="Tenant operations dashboard" id="manage-analytics-dashboard">
  <article class="card stat-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <h2 class="card-title">Certificates Issued</h2>
        <p class="stat-value"><?= (int)($dashboardMetrics['issued_total'] ?? 0) ?></p>
      </div>
      <span style="font-size: 1.5rem; opacity: 0.6;">📜</span>
    </div>
    <small style="color: var(--text-muted); font-size: 0.8rem;">All-time tenant volume</small>
  </article>

  <article class="card stat-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <h2 class="card-title">Issued (Last 30 Days)</h2>
        <p class="stat-value"><?= (int)($dashboardMetrics['issued_30d'] ?? 0) ?></p>
      </div>
      <span style="font-size: 1.5rem; opacity: 0.6;">📈</span>
    </div>
    <small style="color: var(--text-muted); font-size: 0.8rem;">Rolling 30-day velocity</small>
  </article>

  <article class="card stat-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <h2 class="card-title">Pending form approvals</h2>
        <p class="stat-value"><?= (int)($dashboardMetrics['pending_forms'] ?? 0) ?></p>
      </div>
      <span style="font-size: 1.5rem; opacity: 0.6;">⏳</span>
    </div>
    <small style="color: <?= ((int)($dashboardMetrics['pending_forms'] ?? 0) > 0) ? 'var(--warning, #d97706)' : 'var(--text-muted)'; ?>; font-size: 0.8rem;">
      <?= ((int)($dashboardMetrics['pending_forms'] ?? 0) > 0) ? 'Requires operator review' : 'Queue clear' ?>
    </small>
  </article>

  <article class="card stat-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <h2 class="card-title">Failed Schedules</h2>
        <p class="stat-value" style="color: <?= ((int)($dashboardMetrics['failed_schedules'] ?? 0) > 0) ? 'var(--danger, #ef4444)' : 'inherit'; ?>;">
          <?= (int)($dashboardMetrics['failed_schedules'] ?? 0) ?>
        </p>
      </div>
      <span style="font-size: 1.5rem; opacity: 0.6;">⚠️</span>
    </div>
    <small style="color: <?= ((int)($dashboardMetrics['failed_schedules'] ?? 0) > 0) ? 'var(--danger, #ef4444)' : 'var(--text-muted)'; ?>; font-size: 0.8rem;">
      <?= ((int)($dashboardMetrics['failed_schedules'] ?? 0) > 0) ? 'Check automation logs' : 'All runs nominal' ?>
    </small>
  </article>
</section>
