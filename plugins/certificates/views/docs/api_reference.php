<?php
declare(strict_types=1);

/**
 * Interactive API Reference Partial
 * Displays REST endpoints, cURL examples, authentication headers, error codes, and webhook schemas.
 *
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div id="docs-api-reference" class="docs-section">
  <!-- Authentication & Security -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">1. Authentication &amp; Security Headers</h3>
    <p style="font-size: 0.9rem; margin-bottom: 0.75rem; color: var(--text-main); line-height: 1.6;">
      External API calls require a tenant-scoped Bearer token. Generate machine client credentials in 
      <a href="<?= htmlspecialchars($this->plugin->router->url('/manage')) ?>">Manage &rarr; API Clients</a>.
      Secrets are displayed once upon creation and encrypted at rest with AES-256-GCM.
    </p>

    <div style="background: #0f172a; color: #f8fafc; padding: 1.25rem; border-radius: var(--radius-sm); font-family: monospace; font-size: 0.85rem; overflow-x: auto; margin-bottom: 1.5rem; position: relative;">
      <div style="color: #94a3b8; margin-bottom: 0.5rem;">// Standard HTTP Request Headers</div>
      <div><span style="color: #38bdf8;">Authorization</span>: Bearer sec_a1b2c3d4e5f6...</div>
      <div><span style="color: #38bdf8;">Content-Type</span>: application/json</div>
      <div><span style="color: #38bdf8;">Idempotency-Key</span>: req_unique_id_or_uuid <span style="color: #64748b;">(Required on POST /api/v1/certificates)</span></div>
      <div><span style="color: #38bdf8;">Accept</span>: application/json</div>
    </div>
  </section>

  <!-- REST Endpoints Catalog -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">2. Core REST Endpoints</h3>
    <div class="table-responsive">
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
            <td><span class="badge badge-info" style="font-weight:700;">GET</span></td>
            <td><code>/api/v1/health</code></td>
            <td><code>platform.read</code></td>
            <td>System diagnostic health, database latency, and schema status.</td>
          </tr>
          <tr>
            <td><span class="badge badge-info" style="font-weight:700;">GET</span></td>
            <td><code>/api/v1/templates</code></td>
            <td><code>templates.read</code></td>
            <td>List published certificate templates for client's tenant.</td>
          </tr>
          <tr>
            <td><span class="badge badge-success" style="font-weight:700;">POST</span></td>
            <td><code>/api/v1/certificates</code></td>
            <td><code>certificates.issue</code></td>
            <td>Issue an authentic certificate through the unified issuance pipeline.</td>
          </tr>
          <tr>
            <td><span class="badge badge-warning" style="font-weight:700;">GET</span></td>
            <td><code>/verify/{token}</code></td>
            <td><em>Public</em></td>
            <td>Authoritative tamper-proof verification page and cryptographic badge.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- cURL Integration Examples -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">3. Programmatic Issuance Example</h3>
    <p style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--text-muted);">
      Execute transactional certificate issuance using <code>cURL</code>:
    </p>

    <pre style="background: #0f172a; color: #f8fafc; padding: 1.25rem; border-radius: var(--radius-sm); font-size: 0.85rem; overflow-x: auto; line-height: 1.5;">curl -X POST https://your-domain.com/api/v1/certificates \
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
    "issue_date": "<?= date('Y-m-d') ?>"
  }'</pre>

    <p style="font-size: 0.9rem; margin-top: 1rem; margin-bottom: 0.5rem; color: var(--text-muted);">
      Sample JSON Response (HTTP <code>201 Created</code>):
    </p>

    <pre style="background: #0f172a; color: #f8fafc; padding: 1.25rem; border-radius: var(--radius-sm); font-size: 0.85rem; overflow-x: auto; line-height: 1.5;">{
  "status": "success",
  "data": {
    "certificate_id": 42,
    "certificate_number": "SOI-2026-00042",
    "status": "issued",
    "verification_token": "a9f8b7c6d5e4...",
    "verification_url": "https://your-domain.com/verify/a9f8b7c6d5e4...",
    "sha256": "3a7b9c1d2e...",
    "issued_at": "<?= date('c') ?>"
  },
  "request_id": "req_64f1a2b3c4d5"
}</pre>
  </section>

  <!-- Response & Error Codes -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">4. Response and Error Codes</h3>
    <p style="font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.75rem;">
      Errors return standard JSON payloads containing an <code>error.code</code> and sanitized human-readable <code>error.message</code>:
    </p>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>HTTP Status</th>
            <th>Error Code</th>
            <th>Description &amp; Corrective Action</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><code>400</code></td>
            <td><code>INVALID_JSON</code></td>
            <td>Request body must be a well-formed JSON object.</td>
          </tr>
          <tr>
            <td><code>400</code></td>
            <td><code>IDEMPOTENCY_REQUIRED</code></td>
            <td>Missing <code>Idempotency-Key</code> header for certificate issuance.</td>
          </tr>
          <tr>
            <td><code>400</code></td>
            <td><code>IDEMPOTENCY_INVALID</code></td>
            <td>The Idempotency-Key must not exceed 255 characters.</td>
          </tr>
          <tr>
            <td><code>401</code></td>
            <td><code>UNAUTHORIZED</code></td>
            <td>Missing, expired, or revoked API Bearer token.</td>
          </tr>
          <tr>
            <td><code>403</code></td>
            <td><code>FORBIDDEN</code></td>
            <td>The API client does not have the delegated scope for this endpoint.</td>
          </tr>
          <tr>
            <td><code>409</code></td>
            <td><code>REQUEST_IN_PROGRESS</code></td>
            <td>A concurrent request with the same Idempotency-Key is actively executing. Retry in a few seconds.</td>
          </tr>
          <tr>
            <td><code>422</code></td>
            <td><code>ISSUANCE_FAILED</code></td>
            <td>Schema validation failed on variables or recipient details; internal server traces are redacted.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Webhooks -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">5. Outbound Webhook Delivery &amp; Verification</h3>
    <p style="font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.5rem; line-height: 1.6;">
      Outbound webhook subscribers receive real-time POST payloads on lifecycle events 
      (<code>certificate.issued</code>, <code>certificate.revoked</code>, <code>certificate.replaced</code>, <code>certificate.expired</code>).
      Each payload is cryptographically signed using HMAC-SHA256 with the pre-shared secret.
    </p>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
      The sender includes <code>X-SOI-Timestamp</code> and <code>X-SOI-Signature: v1=&lt;hex-digest&gt;</code>. Verify using raw bytes:
    </p>

    <pre style="background: #0f172a; color: #f8fafc; padding: 1.25rem; border-radius: var(--radius-sm); font-size: 0.85rem; overflow-x: auto; line-height: 1.5;">{
  "id": "evt_opaque-event-id",
  "type": "certificate.issued",
  "created_at": "<?= date('c') ?>",
  "data": {
    "certificate_id": 42,
    "certificate_number": "SOI-2026-000042",
    "status": "issued"
  }
}</pre>

    <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; margin-top: 1rem;">
      <h4 style="font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">PHP Webhook Verification Code Sample:</h4>
      <pre style="background: #1e293b; color: #f8fafc; padding: 1rem; border-radius: 4px; font-size: 0.8rem; overflow-x: auto;">
$timestamp = $_SERVER['HTTP_X_SOI_TIMESTAMP'] ?? '';
$signatureHeader = $_SERVER['HTTP_X_SOI_SIGNATURE'] ?? '';
$rawBody = file_get_contents('php://input');

$expectedSignature = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
if (!hash_equals($expectedSignature, $signatureHeader)) {
    http_response_code(401);
    exit('Invalid webhook signature');
}</pre>
    </div>
  </section>
</div>
