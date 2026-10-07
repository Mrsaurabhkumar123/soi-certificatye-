<?php
declare(strict_types=1);

$pageTitle = "API & Integration Documentation - /docs";
ob_start();
?>

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">SOI Certificate Platform Documentation</h2>
      <p style="font-size: 0.85rem; color: var(--text-muted);">Developer, API, and Administrator Reference</p>
    </div>
    <span class="badge badge-info">v1.0.0</span>
  </div>

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">1. Authentication & Security Model</h3>
  <p style="font-size: 0.9rem; margin-bottom: 1rem; color: var(--text-main);">
    External applications interact with the platform using <strong>Tenant-Scoped API Clients</strong>. Every request must present a Bearer token in the <code>Authorization</code> HTTP header:
  </p>
  <pre style="background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 6px; font-size: 0.85rem; overflow-x: auto; margin-bottom: 1.5rem;">
Authorization: Bearer sec_a1b2c3d4e5f6...
Content-Type: application/json
Idempotency-Key: optional-unique-client-event-key
  </pre>

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">2. Core REST Endpoints</h3>
  <div class="table-responsive" style="margin-bottom: 1.5rem;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Method</th>
          <th>Endpoint</th>
          <th>Scope Required</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><code>GET</code></td>
          <td><code>/api/v1/health</code></td>
          <td>None</td>
          <td>System diagnostic health and schema status.</td>
        </tr>
        <tr>
          <td><code>GET</code></td>
          <td><code>/api/v1/templates</code></td>
          <td><code>templates.read</code></td>
          <td>List published certificate templates for client's tenant.</td>
        </tr>
        <tr>
          <td><code>POST</code></td>
          <td><code>/api/v1/certificates</code></td>
          <td><code>certificates.issue</code></td>
          <td>Issue a certificate through unified issuance service.</td>
        </tr>
        <tr>
          <td><code>GET</code></td>
          <td><code>/verify/{token}</code></td>
          <td>Public</td>
          <td>Authoritative tamper-proof verification page.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">3. Programmatic Issuance Example</h3>
  <p style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--text-muted);">Example JSON request payload for <code>POST /api/v1/certificates</code>:</p>
  <pre style="background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 6px; font-size: 0.85rem; overflow-x: auto; margin-bottom: 1.5rem;">
curl -X POST https://your-domain.com/api/v1/certificates \
  -H "Authorization: Bearer sec_your_api_key_here" \
  -H "Content-Type: application/json" \
  -d '{
    "template_id": 1,
    "recipient_name": "Arunima Rai",
    "recipient_email": "arunima@example.com",
    "variables": {
      "course_name": "Senior Leadership Program"
    },
    "issue_date": "2026-10-07"
  }'
  </pre>

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">4. Outbound Webhooks</h3>
  <p style="font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.5rem;">
    When configured, outbound webhooks transmit signed JSON payloads on lifecycle events (<code>certificate.issued</code>, <code>certificate.revoked</code>, <code>certificate.expired</code>).
  </p>
  <p style="font-size: 0.85rem; color: var(--text-muted);">
    Payloads are signed using HMAC-SHA256: <code>X-Signature: hash_hmac('sha256', timestamp . '.' . rawBody, secret)</code>.
  </p>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
