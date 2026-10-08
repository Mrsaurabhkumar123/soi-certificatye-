<?php
declare(strict_types=1);

/**
 * Console Operational Dashboard Partial
 * Displays metrics for total issued, 30-day volume, currently valid, and recent failure counts.
 *
 * @var array $dashboardMetrics
 */
?>
<section class="grid-4" aria-label="Issuance analytics" id="console-analytics-section">
  <?php foreach ([
      'Certificates issued' => $dashboardMetrics['issued_total'] ?? 0,
      'Issued in last 30 days' => $dashboardMetrics['issued_30d'] ?? 0,
      'Currently valid' => $dashboardMetrics['active'] ?? 0,
      'Failures in last 30 days' => $dashboardMetrics['failures_30d'] ?? 0,
  ] as $metricLabel => $metricValue): ?>
    <article class="card stat-card">
      <h2 class="card-title"><?= htmlspecialchars((string)$metricLabel, ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="stat-value"><?= (int)$metricValue ?></p>
    </article>
  <?php endforeach; ?>
</section>
