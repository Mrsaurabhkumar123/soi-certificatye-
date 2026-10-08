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
    External applications use tenant-scoped API clients. Send the one-time client secret as a Bearer token; the client ID is for administration and is not the token. Store secrets in a server-side secret manager and never expose them in browser code:
  </p>
  <pre style="background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 6px; font-size: 0.85rem; overflow-x: auto; margin-bottom: 1.5rem;">
Authorization: Bearer sec_a1b2c3d4e5f6...
Content-Type: application/json
Idempotency-Key: required-unique-client-event-key (issuance only)
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
          <td><code>platform.read</code></td>
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
  -H "Idempotency-Key: enrollment-2026-0042" \
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

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">4. Response and Error Codes</h3>
  <p style="font-size: 0.9rem; color: var(--text-main);">Successful issuance returns HTTP <code>201</code> with a certificate number, status, artifact SHA-256, and request ID. Reusing an idempotency key with the same payload replays the original response.</p>
  <div class="table-responsive" style="margin-bottom: 1.5rem;">
    <table class="data-table">
      <thead><tr><th>HTTP status</th><th>Error code</th><th>Meaning / action</th></tr></thead>
      <tbody>
        <tr><td>400</td><td><code>INVALID_JSON</code></td><td>Send a valid JSON object.</td></tr>
        <tr><td>400</td><td><code>IDEMPOTENCY_REQUIRED</code> / <code>IDEMPOTENCY_INVALID</code></td><td>Provide a valid unique Idempotency-Key for issuance.</td></tr>
        <tr><td>401</td><td><code>UNAUTHORIZED</code></td><td>Check the API secret and client status.</td></tr>
        <tr><td>403</td><td><code>FORBIDDEN</code></td><td>Ask an administrator to grant the required scope.</td></tr>
        <tr><td>409</td><td><code>REQUEST_IN_PROGRESS</code></td><td>Retry using the same key after a short delay.</td></tr>
        <tr><td>422</td><td><code>ISSUANCE_FAILED</code></td><td>Check template, recipient, and variable values; internal details are not leaked.</td></tr>
      </tbody>
    </table>
  </div>

  <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; color: var(--primary);">5. Outbound Webhooks</h3>
  <p style="font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.5rem;">
    When configured, outbound webhooks transmit signed JSON payloads on lifecycle events (<code>certificate.issued</code>, <code>certificate.revoked</code>, <code>certificate.expired</code>).
  </p>
  <p style="font-size: 0.85rem; color: var(--text-muted);">
    Payloads use HMAC-SHA256 over <code>timestamp . "." . rawBody</code>. Receivers should compare signatures in constant time, validate timestamps against a short replay window, and make handlers idempotent. Delivery uses bounded retries; configure HTTPS public endpoints only.
  </p>
  <p style="font-size: 0.9rem; color: var(--text-main);">The sender includes <code>X-SOI-Timestamp</code> and <code>X-SOI-Signature: v1=&lt;hex-digest&gt;</code>. Verify with the exact raw request bytes before parsing JSON:</p>
  <pre style="background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 6px; font-size: 0.85rem; overflow-x: auto; margin-bottom: 1.5rem;">{
  "id": "evt_opaque-event-id",
  "type": "certificate.issued",
  "created_at": "2026-10-07T12:00:00+00:00",
  "data": {
    "certificate_id": 42,
    "certificate_number": "SOI-2026-000042",
    "status": "issued"
  }
}</pre>
  <h3 style="font-size: 1.05rem; margin: 1.25rem 0 0.5rem; color: var(--primary);">Operational guidance</h3>
  <ul>
    <li>Use separate API clients and least-privilege scopes for each integration.</li>
    <li>Rotate compromised credentials by revoking the old client and creating a replacement.</li>
    <li>After a timeout, retry issuance with the same idempotency key, not a new key.</li>
    <li>Verification modes may mask personal information or require PIN/authentication; keep recipient PII out of webhook URLs and logs.</li>
  </ul>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>
