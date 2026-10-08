<?php
declare(strict_types=1);

/**
 * Developer & Administrator Architectural Guide Partial
 * Explains tenancy boundaries, issuance pipeline architecture, idempotency, and security best practices.
 *
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
?>
<div id="docs-guide" class="docs-section">
  <!-- Architecture Overview -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">1. Platform Architecture &amp; Tenancy Isolation</h3>
    <p style="font-size: 0.9rem; line-height: 1.6; color: var(--text-main); margin-bottom: 1rem;">
      The SOI Certificate Management Platform is engineered around strict multi-tenant boundary isolation.
      Every database entity (templates, certificates, members, audit logs, sequences, webhooks, and forms)
      is permanently anchored to a validated <code>tenant_id</code>.
    </p>

    <div class="grid-2">
      <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.25rem;">
        <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-main);">🛡️ Multi-Tenant Guardrails</h4>
        <ul style="font-size: 0.85rem; padding-left: 1.25rem; line-height: 1.8; color: var(--text-main);">
          <li>All SQL queries enforce <code>tenant_id = :tenant_id</code> binding.</li>
          <li>Cross-tenant resource traversal attempts are logged as security audit anomalies.</li>
          <li>Artifact storage maintains segregated directory partitions per tenant: <code>storage/{tenant_id}/...</code></li>
        </ul>
      </div>

      <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.25rem;">
        <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-main);">🔑 Role-Based Access Control (RBAC)</h4>
        <ul style="font-size: 0.85rem; padding-left: 1.25rem; line-height: 1.8; color: var(--text-main);">
          <li><strong>Tenant Owner:</strong> Full tenancy control, membership management, last-owner demotion protection.</li>
          <li><strong>Tenant Admin:</strong> Template creation, issuance, revocations, and API credentials.</li>
          <li><strong>Template Designer:</strong> Visual layout editing, variable schema binding, draft management.</li>
          <li><strong>Issuer:</strong> Certificate manual generation, replacements, and revocations.</li>
          <li><strong>Viewer:</strong> Authoritative read-only registry inspection and report exports.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- Issuance Pipeline Lifecycle -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">2. Authoritative Issuance Pipeline</h3>
    <p style="font-size: 0.9rem; line-height: 1.6; color: var(--text-main); margin-bottom: 0.75rem;">
      All certificate creation paths (Manual Console, Public Application Forms, REST API, Bulk Import, and Cron Schedules)
      converge on a single unified transaction: <code>CertificateIssuanceService</code>:
    </p>

    <ol style="font-size: 0.85rem; padding-left: 1.5rem; line-height: 2; color: var(--text-main); margin-bottom: 1.5rem;">
      <li><strong>Permission Check:</strong> Verifies the caller possesses <code>certificates.issue</code> capability in the active tenant.</li>
      <li><strong>Template &amp; Variable Validation:</strong> Loads immutable published template version and validates recipient &amp; custom variables against defined types.</li>
      <li><strong>Sequence Allocation:</strong> Acquires transactional database sequence lock to generate contiguous, gap-free certificate numbers (e.g. <code>SOI-2026-00042</code>).</li>
      <li><strong>Cryptographic Token Generation:</strong> Generates a high-entropy 128-bit random, non-guessable verification token. Raw tokens are never stored plaintext.</li>
      <li><strong>Vector PDF &amp; QR Rendering:</strong> The self-hosted pure-PHP renderer draws print-accurate A4 landscape vector graphics and high-density vector QR code.</li>
      <li><strong>Artifact Integrity Checksum:</strong> Computes binary SHA-256 hash upon writing to local tenant storage.</li>
      <li><strong>Event Dispatch &amp; Audit Logging:</strong> Enqueues signed webhook delivery and records append-only security audit log entry.</li>
    </ol>
  </section>

  <!-- Idempotency & Resiliency Guide -->
  <section style="margin-bottom: 2rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">3. Idempotency &amp; Network Resiliency</h3>
    <p style="font-size: 0.9rem; line-height: 1.6; color: var(--text-main); margin-bottom: 0.75rem;">
      To prevent double-issuance during transient network timeouts or client retries, the platform enforces mandatory idempotency reservations:
    </p>
    <ul style="font-size: 0.85rem; padding-left: 1.25rem; line-height: 1.8; color: var(--text-main);">
      <li>Send an <code>Idempotency-Key</code> header with a client-generated UUID or event identifier.</li>
      <li>If a network timeout occurs, re-transmit the request using the <strong>exact same key</strong>.</li>
      <li>The platform checks <code>cert_idempotency</code>; completed responses are replayed instantly without re-running the issuance pipeline.</li>
      <li>Reusing the same key with an altered payload results in a <code>400 Bad Request (IDEMPOTENCY_PAYLOAD_MISMATCH)</code> error.</li>
    </ul>
  </section>

  <!-- Security Checklist -->
  <section style="margin-bottom: 1.5rem;">
    <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem; color: var(--primary);">4. Operational Security Checklist</h3>
    <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.25rem;">
      <ul style="font-size: 0.85rem; padding-left: 1.25rem; line-height: 1.8; color: var(--text-main);">
        <li>🔒 <strong>Secret Storage:</strong> Store API Bearer tokens and webhook secrets in secure environment variables or vault systems.</li>
        <li>🛡️ <strong>Least Privilege:</strong> Create separate API clients for each microservice with minimal required scopes.</li>
        <li>🌐 <strong>HTTPS Only:</strong> Enforce TLS 1.3 for all portal and webhook communications.</li>
        <li>👁️ <strong>Verification Privacy:</strong> Choose between Public, Masked Name, or PIN-protected verification modes under Settings &rarr; Verification Privacy.</li>
      </ul>
    </div>
  </section>
</div>
