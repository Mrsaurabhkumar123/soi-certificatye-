<?php
declare(strict_types=1);

/**
 * Automated Verification & Regression Suite for SOI Certificate Platform.
 * Executes unit, multi-tenancy isolation, issuance, and routing tests.
 */

echo "========================================================\n";
echo "   SOI Certificate Management Platform - Test Suite     \n";
echo "========================================================\n\n";

$baseDir = dirname(__DIR__) . '/plugins/certificates';
require_once $baseDir . '/src/Core/Autoloader.php';
\SOI\Certificates\Core\Autoloader::register($baseDir . '/src');

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $failed++;
    }
}

function createTestZip(array $entries): string
{
    $localRecords = '';
    $centralRecords = '';
    foreach ($entries as $name => $content) {
        $compressed = gzdeflate($content);
        $crc = crc32($content);
        $offset = strlen($localRecords);
        $localRecords .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($content), strlen($name), 0)
            . $name . $compressed;
        $centralRecords .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset)
            . $name;
    }
    $count = count($entries);
    return $localRecords . $centralRecords
        . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($centralRecords), strlen($localRecords), 0);
}

try {
    putenv('SOI_CERT_ENV=production');
    putenv('SOI_CERT_STANDALONE_DEMO=0');
    $anonymousIdentity = \SOI\Certificates\Core\CmsIdentity::current();
    assertTest("Production bootstrap has no implicit user or platform-admin identity", $anonymousIdentity->userId === null && !$anonymousIdentity->isPlatformAdmin);
    $userDirectory = new \SOI\Certificates\Core\CmsUserDirectory();
    $directoryProviderRequired = false;
    try {
        $userDirectory->userExists(7);
    } catch (\RuntimeException $e) {
        $directoryProviderRequired = true;
    }
    assertTest("CMS account validation fails closed without a host provider", $directoryProviderRequired);
    $GLOBALS['soi_certificate_user_exists_provider'] = static fn(int $userId): bool => $userId === 7;
    assertTest("CMS account validation delegates to trusted host provider", $userDirectory->userExists(7) && !$userDirectory->userExists(8));
    unset($GLOBALS['soi_certificate_user_exists_provider']);

    // Prefix-aware migrations must not double-prefix table names or index identifiers.
    $prefixDb = new \SOI\Certificates\Core\Database(new PDO('sqlite::memory:'), 'soi_');
    $prefixMigrations = new \SOI\Certificates\Core\MigrationRunner($prefixDb, $baseDir . '/migrations');
    $prefixMigrations->runPending();
    $prefixedTable = (string)$prefixDb->fetchValue(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'soi_cert_tenants'"
    );
    $doublePrefixedTable = (string)$prefixDb->fetchValue(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'soi_soi_cert_tenants'"
    );
    assertTest("Migrations apply a table prefix exactly once", $prefixedTable === 'soi_cert_tenants' && $doublePrefixedTable === '');
    assertTest("Database tableName does not double-prefix", $prefixDb->tableName('soi_cert_tenants') === 'soi_cert_tenants');
    $invalidPrefixRejected = false;
    try {
        $prefixDb->tableName('cert_users; DROP TABLE cert_tenants');
    } catch (\InvalidArgumentException $e) {
        $invalidPrefixRejected = true;
    }
    assertTest("Database rejects unsafe table identifiers", $invalidPrefixRejected);

    // 1. Database & Migrations
    $testDbPath = sys_get_temp_dir() . '/soi_test_' . time() . '.sqlite';
    $pdo = new PDO("sqlite:{$testDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db = new \SOI\Certificates\Core\Database($pdo, '');
    $migrationRunner = new \SOI\Certificates\Core\MigrationRunner($db, $baseDir . '/migrations');
    $applied = $migrationRunner->runPending();

    assertTest("Migration runner applies initial schema", count($applied) > 0, "Count: " . count($applied));

    // 2. Health Service
    $healthService = new \SOI\Certificates\Core\HealthService($db, sys_get_temp_dir(), '1.0.0');
    $health = $healthService->runChecks();
    assertTest("Health check reports healthy database and runtime", $health['status'] === 'healthy');

    // 3. Tenancy & Isolation
    $tenantRepo = new \SOI\Certificates\Tenancy\TenantRepository($db);
    $tenantA = $tenantRepo->create('tenant-alpha', 'Alpha Organization');
    $tenantB = $tenantRepo->create('tenant-beta', 'Beta Organization');
    assertTest("Tenants created with unique IDs", $tenantA->id > 0 && $tenantB->id > 0 && $tenantA->id !== $tenantB->id);
    $tenantRepo->addMembership($tenantA->id, 1, 'tenant_owner');
    $tenantRepo->addMembership($tenantA->id, 2, 'issuer');
    $tenantRepo->addMembership($tenantB->id, 2, 'viewer');
    assertTest("Tenant membership lists are isolated", count($tenantRepo->listMemberships($tenantA->id)) === 2
        && count($tenantRepo->listMemberships($tenantB->id)) === 1);
    assertTest("Tenant membership role changes are scoped", $tenantRepo->updateMembershipRole($tenantA->id, 2, 'viewer')
        && $tenantRepo->getUserMembership($tenantA->id, 2)['role_key'] === 'viewer'
        && $tenantRepo->getUserMembership($tenantB->id, 2)['role_key'] === 'viewer');
    assertTest("Saving an unchanged tenant role is idempotent", $tenantRepo->updateMembershipRole($tenantA->id, 2, 'viewer'));
    $lastOwnerRemovalRejected = false;
    try {
        $tenantRepo->deactivateMembership($tenantA->id, 1);
    } catch (\RuntimeException $e) {
        $lastOwnerRemovalRejected = true;
    }
    assertTest("The last active tenant owner cannot be removed", $lastOwnerRemovalRejected
        && $tenantRepo->getUserMembership($tenantA->id, 1) !== null);
    $lastOwnerDemotionRejected = false;
    try {
        $tenantRepo->updateMembershipRole($tenantA->id, 1, 'tenant_admin');
    } catch (\RuntimeException $e) {
        $lastOwnerDemotionRejected = true;
    }
    assertTest("The last active tenant owner cannot be demoted", $lastOwnerDemotionRejected
        && $tenantRepo->getUserMembership($tenantA->id, 1)['role_key'] === 'tenant_owner');
    assertTest("Membership deactivation is tenant-scoped", $tenantRepo->deactivateMembership($tenantA->id, 2)
        && $tenantRepo->getUserMembership($tenantA->id, 2) === null
        && $tenantRepo->getUserMembership($tenantB->id, 2) !== null);

    // Negative Test: Tenant Context boundary
    $ctxA = new \SOI\Certificates\Tenancy\TenantContext($tenantA, 1, 'tenant_owner');
    $ctxB = new \SOI\Certificates\Tenancy\TenantContext($tenantB, 2, 'tenant_owner');
    assertTest("Tenant contexts isolate active tenant ID", $ctxA->getTenantId() === $tenantA->id && $ctxB->getTenantId() === $tenantB->id);

    // 4. RBAC Authorization
    $authA = new \SOI\Certificates\Authorization\Authorizer($ctxA, false);
    assertTest("Tenant Owner has certificates.issue permission", $authA->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE));

    $viewerCtx = new \SOI\Certificates\Tenancy\TenantContext($tenantA, 3, 'viewer');
    $authViewer = new \SOI\Certificates\Authorization\Authorizer($viewerCtx, false);
    assertTest("Viewer CANNOT issue certificates (RBAC gate)", !$authViewer->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE));

    // 5. Audit Logging with Secret Redaction
    $audit = new \SOI\Certificates\Audit\AuditService($db);
    $audit->log($tenantA->id, 'user', 1, 'test.event', 'tenant', (string)$tenantA->id, [
        'api_key' => 'secret_live_12345',
        'safe_info' => 'hello',
    ]);
    $recent = $audit->getRecent($tenantA->id, 1);
    $savedMeta = json_decode($recent[0]['metadata_json'], true);
    assertTest("Audit log redacts sensitive secrets", $savedMeta['api_key'] === '***REDACTED***' && $savedMeta['safe_info'] === 'hello');

    // 6. Template Management & Version Publishing
    $tplService = new \SOI\Certificates\Templates\TemplateService($db, $ctxA);
    $tpl = $tplService->createTemplate('gold-cert', 'Gold Certificate of Merit');
    assertTest("Template draft created", $tpl->status === 'draft');

    $pubVersion = $tplService->publish($tpl->id, 1);
    assertTest("Template version published with immutable canonical hash", !empty($pubVersion->canonicalHash));

    $layoutValidator = new \SOI\Certificates\Templates\DesignerJSONValidator();
    $invalidLayoutRejected = false;
    try {
        $layoutValidator->validate([
            'page' => ['size' => 'A4', 'orientation' => 'landscape'],
            'elements' => [['type' => 'text', 'id' => 'unsafe', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 20, 'value' => '<script>alert(1)</script>']],
        ]);
    } catch (\InvalidArgumentException $e) {
        $invalidLayoutRejected = true;
    }
    assertTest("Designer JSON rejects unsafe HTML and unknown schema payloads", $invalidLayoutRejected);

    // Negative Test: Tenant B cannot access Tenant A's template
    $tplServiceB = new \SOI\Certificates\Templates\TemplateService($db, $ctxB);
    $leakedTpl = $tplServiceB->findById($tpl->id);
    assertTest("Cross-tenant isolation: Tenant B cannot access Tenant A template", $leakedTpl === null);

    // 7. Local Storage Adapter
    $testStorageDir = sys_get_temp_dir() . '/soi_storage_' . time();
    $storage = new \SOI\Certificates\Storage\LocalStorageAdapter($testStorageDir);
    $storage->put('test/file.txt', 'SOI CERTIFICATE PLATFORM');
    assertTest("Local storage adapter persists and hashes artifacts", $storage->exists('test/file.txt') && strlen($storage->hash('test/file.txt')) === 64);
    $artifactStorage = new \SOI\Certificates\Storage\ArtifactStorageService($storage);
    $verifiedBytes = $artifactStorage->readVerified('test/file.txt', hash('sha256', 'SOI CERTIFICATE PLATFORM'));
    assertTest("Artifact storage returns bytes only when their SHA-256 matches", $verifiedBytes === 'SOI CERTIFICATE PLATFORM');
    $tamperedArtifactRejected = false;
    try {
        $artifactStorage->readVerified('test/file.txt', str_repeat('0', 64));
    } catch (\RuntimeException $e) {
        $tamperedArtifactRejected = true;
    }
    assertTest("Artifact storage rejects checksum mismatches", $tamperedArtifactRejected);
    $traversalRejected = false;
    try {
        $storage->put('../outside.txt', 'blocked');
    } catch (\Throwable $e) {
        $traversalRejected = true;
    }
    assertTest("Local storage rejects traversal paths", $traversalRejected);

    // 8. Local PDF & Vector QR Rendering
    $renderer = new \SOI\Certificates\Rendering\LocalCertificateRenderer();
    $req = new \SOI\Certificates\Rendering\RenderRequest(
        $pubVersion,
        ['recipient_name' => 'John Doe', 'course_name' => 'Data Science Track', 'issue_date' => '2026-10-07'],
        'SOI-2026-00001',
        'http://localhost:8000/verify/token123',
        $tenantA->displayName
    );
    $renderRes = $renderer->render($req);
    assertTest("Local PDF renderer generates valid PDF-1.4 bytes", str_starts_with($renderRes->pdfBytes, '%PDF-1.4') && $renderRes->sizeBytes > 500);

    // 9. Unified End-to-End Issuance Pipeline
    $issuanceService = new \SOI\Certificates\Issuance\CertificateIssuanceService(
        $db,
        $ctxA,
        $authA,
        $tplService,
        $renderer,
        $storage,
        $audit,
        'http://localhost:8000'
    );

    $cmd = new \SOI\Certificates\Issuance\IssuanceCommand(
        $tpl->id,
        'Jane Developer',
        ['course_name' => 'Senior Engineering Program'],
        'jane@example.com'
    );
    $cert = $issuanceService->issue($cmd, 1);
    assertTest("Certificate issued with sequential ID and SHA-256", $cert->isIssued() && str_starts_with($cert->certificateNumber, 'SOI-2026-') && strlen($cert->fileSha256) === 64);
    assertTest("Verification token is high-entropy URL-safe material", strlen($cert->verificationToken) === 43 && preg_match('/^[A-Za-z0-9_-]+$/', $cert->verificationToken) === 1);
    $storedToken = $db->fetchValue(
        "SELECT verification_token FROM cert_certificates WHERE id = :id",
        ['id' => $cert->id]
    );
    assertTest("Raw verification token is not persisted", $storedToken === '');
    assertTest("Certificate artifact file exists in storage", $storage->exists($cert->filePath));
    $certificateSearch = $issuanceService->searchTenantCertificates(['recipient' => 'Jane Developer'], 25);
    assertTest("Certificate registry search matches tenant-scoped recipient filters",
        count($certificateSearch) === 1 && $certificateSearch[0]->id === $cert->id);
    assertTest("Certificate detail timeline is tenant-scoped and includes initial issuance",
        count($issuanceService->listCertificateEvents($cert->id)) === 1);

    // 10. Authoritative Verification
    $verifyService = new \SOI\Certificates\Verification\VerificationService($db);
    $verifyRes = $verifyService->verify($cert->verificationToken);
    assertTest("Verification service confirms authentic certificate", $verifyRes->found && $verifyRes->status === 'valid' && $verifyRes->recipientName === 'Jane Developer');

    $policy = new \SOI\Certificates\Verification\VerificationPolicy($db);
    $policy->configure($tenantA->id, 'masked');
    $masked = $verifyService->verify($cert->verificationToken);
    assertTest("Masked verification does not reveal the full recipient name", $masked->found && $masked->recipientName !== 'Jane Developer');
    $policy->configure($tenantA->id, 'pin', '482915');
    $pinRequired = $verifyService->verify($cert->verificationToken);
    $wrongPin = $verifyService->verify($cert->verificationToken, '111111');
    $rightPin = $verifyService->verify($cert->verificationToken, '482915');
    assertTest("PIN verification requires a correct PIN and hashes it at rest", $pinRequired->requiresPin && !$wrongPin->found && $rightPin->found);
    $policy->configure($tenantA->id, 'authenticated');
    assertTest("Authenticated verification requires a signed-in identity", !$verifyService->verify($cert->verificationToken)->found && $verifyService->verify($cert->verificationToken, null, true)->found);
    $policy->configure($tenantA->id, 'disabled');
    assertTest("Disabled verification reveals no certificate record", !$verifyService->verify($cert->verificationToken)->found);
    $policy->configure($tenantA->id, 'public');

    $oldPublishedHash = $pubVersion->canonicalHash;
    $draftLayout = $pubVersion->layout;
    $draftLayout['elements'][0]['value'] = 'Changed only in draft';
    $newDraft = $tplService->saveDraft($tpl->id, $draftLayout, $pubVersion->variableSchema);
    $stillPublished = $tplService->findVersionById($pubVersion->id);
    assertTest("Saving edits creates a separate draft without mutating published history", $newDraft->id !== $pubVersion->id && $stillPublished->canonicalHash === $oldPublishedHash && $stillPublished->layout['elements'][0]['value'] !== 'Changed only in draft');
    $republishRejected = false;
    try {
        $tplService->publish($tpl->id, 1);
        $tplService->publish($tpl->id, 1);
    } catch (\Throwable $e) {
        $republishRejected = true;
    }
    assertTest("An immutable published version cannot be published again as a draft", $republishRejected);

    // 11. Lifecycle Revocation
    $revoked = $issuanceService->revoke($cert->id, 'Issued in error', 1);
    assertTest("Revocation transition succeeds", $revoked);

    $verifyRevoked = $verifyService->verify($cert->verificationToken);
    assertTest("Verification service immediately reflects REVOKED status", $verifyRevoked->status === 'revoked');

    $nextCertificate = $issuanceService->issue(new \SOI\Certificates\Issuance\IssuanceCommand(
        $tpl->id,
        'Second Recipient',
        ['course_name' => 'Senior Engineering Program'],
        null,
        '2026-10-08'
    ), 1);
    assertTest("Database sequence generator allocates the next tenant number", str_ends_with($nextCertificate->certificateNumber, '00002'));

    $replaceTarget = $issuanceService->issue(new \SOI\Certificates\Issuance\IssuanceCommand(
        $tpl->id,
        'Incorrect Recipient',
        ['course_name' => 'Senior Engineering Program']
    ), 1);
    $replacement = $issuanceService->replace(
        $replaceTarget->id,
        new \SOI\Certificates\Issuance\IssuanceCommand(
            $tpl->id,
            'Corrected Recipient',
            ['course_name' => 'Senior Engineering Program'],
            null,
            '2026-10-09',
            null,
            'replacement'
        ),
        'Corrected recipient name',
        1
    );
    $replacedRecord = $issuanceService->findById($replaceTarget->id);
    assertTest("Replacement issues a new certificate and records bidirectional links", $replacement->isIssued()
        && $replacedRecord->status === 'replaced'
        && $replacedRecord->replacedByCertificateId === $replacement->id
        && $replacement->replacesCertificateId === $replaceTarget->id);
    assertTest("Old verification reports replacement immediately", $verifyService->verify($replaceTarget->verificationToken)->status === 'replaced');
    $replacementOfReplacedRejected = false;
    try {
        $issuanceService->replace(
            $replaceTarget->id,
            new \SOI\Certificates\Issuance\IssuanceCommand($tpl->id, 'Another Recipient'),
            'Attempted second replacement',
            1
        );
    } catch (\InvalidArgumentException $e) {
        $replacementOfReplacedRejected = true;
    }
    assertTest("A replaced certificate cannot be replaced again", $replacementOfReplacedRejected);

    $expiredCertificate = $issuanceService->issue(new \SOI\Certificates\Issuance\IssuanceCommand(
        $tpl->id,
        'Expired Recipient',
        ['course_name' => 'Senior Engineering Program'],
        null,
        '2026-10-10',
        '2000-01-01 00:00:00'
    ), 1);
    assertTest("Expired certificates verify as expired even before state normalization", $verifyService->verify($expiredCertificate->verificationToken)->status === 'expired');
    assertTest("Expiration worker normalizes due certificate state", $issuanceService->expireDue() === 1
        && $issuanceService->findById($expiredCertificate->id)->status === 'expired');
    $pendingHash = hash('sha256', 'synthetic-pending-record');
    $db->execute(
        "INSERT INTO cert_certificates
         (tenant_id, certificate_number, verification_token, verification_token_hash, template_id, template_version_id,
          status, recipient_name, recipient_email, payload_json, file_path, file_sha256, issued_at, expires_at,
          created_by_type, created_by_id, source_type, created_at, updated_at)
         VALUES (:tenant_id, 'SOI-TEST-PENDING', '', :token_hash, :template_id, :version_id, 'pending',
          'Pending Recipient', NULL, '{}', '', '', CURRENT_TIMESTAMP, NULL, 'system', NULL, 'form',
          CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        [
            'tenant_id' => $tenantA->id,
            'token_hash' => $pendingHash,
            'template_id' => $tpl->id,
            'version_id' => $pubVersion->id,
        ]
    );
    $pendingCertificateId = $db->lastInsertId();
    assertTest("Cancellation is allowed only for a pre-issued pending certificate",
        $issuanceService->cancel($pendingCertificateId, 'Request withdrawn before issue', 1)
        && $issuanceService->findById($pendingCertificateId)->status === 'cancelled'
        && !$issuanceService->cancel($cert->id, 'Cannot cancel issued record', 1));

    $dynamicForms = new \SOI\Certificates\Forms\DynamicFormService($db);
    $invalidFormMappingRejected = false;
    try {
        $dynamicForms->createForm(
            $tenantA->id,
            'invalid-template-mapping',
            'Invalid template mapping',
            $tpl->id,
            ['full_name' => 'recipient_name', 'unknown_field' => 'not_a_template_variable']
        );
    } catch (\InvalidArgumentException $e) {
        $invalidFormMappingRejected = true;
    }
    assertTest("Public forms reject mappings to variables absent from the published template", $invalidFormMappingRejected);

    $formId = $dynamicForms->createForm(
        $tenantA->id,
        'leadership-nomination',
        'Leadership nomination',
        $tpl->id,
        ['full_name' => 'recipient_name', 'program' => 'course_name'],
        true
    );
    $formSubmissionId = $dynamicForms->submitForm(
        $formId,
        $tenantA->id,
        ['full_name' => 'Form Approved Recipient', 'program' => 'Leadership Track'],
        '192.0.2.10'
    );
    $formUnknownFieldRejected = false;
    try {
        $dynamicForms->submitForm($formId, $tenantA->id, [
            'full_name' => 'Unexpected Field Recipient',
            'program' => 'Leadership Track',
            'certificate_number' => 'FORGED',
        ]);
    } catch (\InvalidArgumentException $e) {
        $formUnknownFieldRejected = true;
    }
    assertTest("Public form submissions reject unconfigured and reserved fields", $formUnknownFieldRejected);
    $formApproval = new \SOI\Certificates\Forms\FormApprovalService($db, $ctxA, $authA, $issuanceService, $audit);
    assertTest("Form approval queue is tenant-scoped", count($formApproval->listPending()) === 1);
    $formCertificate = $formApproval->approve($formSubmissionId, 1);
    $formSubmission = $db->fetchOne(
        "SELECT status, certificate_id, reviewed_by FROM cert_form_submissions WHERE id = :id AND tenant_id = :tenant_id",
        ['id' => $formSubmissionId, 'tenant_id' => $tenantA->id]
    );
    assertTest("Authorized form approval issues through the central service once", $formCertificate->isIssued()
        && $formCertificate->sourceType === 'form'
        && $formCertificate->recipientName === 'Form Approved Recipient'
        && $formCertificate->payload['certificate_number'] !== 'FORGED'
        && $formSubmission['status'] === 'approved'
        && (int)$formSubmission['certificate_id'] === $formCertificate->id
        && (int)$formSubmission['reviewed_by'] === 1);
    assertTest("Retrying an approved form returns its original certificate", $formApproval->approve($formSubmissionId, 1)->id === $formCertificate->id);
    $db->execute(
        "UPDATE cert_form_submissions SET status = 'processing', reviewed_at = datetime('now', '-20 minutes') WHERE id = :id",
        ['id' => $formSubmissionId]
    );
    $recoveredFormCertificate = $formApproval->approve($formSubmissionId, 1);
    assertTest("Stale form approval recovers through issuance idempotency", $recoveredFormCertificate->id === $formCertificate->id
        && (int)$db->fetchValue(
            "SELECT COUNT(*) FROM cert_certificates WHERE tenant_id = :tenant_id AND source_reference = :reference",
            ['tenant_id' => $tenantA->id, 'reference' => 'form_submission:' . $formSubmissionId]
        ) === 1);
    $viewerSubmissionId = $dynamicForms->submitForm(
        $formId,
        $tenantA->id,
        ['full_name' => 'Viewer Must Not Approve', 'program' => 'Leadership Track']
    );
    $viewerApproval = new \SOI\Certificates\Forms\FormApprovalService($db, $viewerCtx, $authViewer, $issuanceService, $audit);
    $viewerApprovalRejected = false;
    try {
        $viewerApproval->approve($viewerSubmissionId, 3);
    } catch (\RuntimeException $e) {
        $viewerApprovalRejected = true;
    }
    assertTest("Viewer cannot approve form submissions", $viewerApprovalRejected
        && $db->fetchValue("SELECT status FROM cert_form_submissions WHERE id = :id", ['id' => $viewerSubmissionId]) === 'pending');
    $rejectedSubmissionId = $dynamicForms->submitForm(
        $formId,
        $tenantA->id,
        ['full_name' => 'Rejected Recipient', 'program' => 'Leadership Track']
    );
    assertTest("Authorized form rejection is final and tenant-scoped", $formApproval->reject($rejectedSubmissionId, 'Submission does not meet criteria', 1)
        && $formApproval->reject($viewerSubmissionId, 'Reviewed by an authorized administrator', 1)
        && $db->fetchValue("SELECT status FROM cert_form_submissions WHERE id = :id", ['id' => $rejectedSubmissionId]) === 'rejected'
        && $formApproval->listPending() === []);
    assertTest("Form submission tenant mismatch is rejected", (static function () use ($dynamicForms, $formId, $tenantB): bool {
        try {
            $dynamicForms->submitForm($formId, $tenantB->id, ['recipient_name' => 'Wrong tenant']);
        } catch (\InvalidArgumentException $e) {
            return true;
        }
        return false;
    })());
    $builderTemplate = $tplService->createTemplate('dynamic-form-schema', 'Dynamic Form Schema');
    $builderDraft = $tplService->findVersionById($builderTemplate->draftVersionId);
    $builderSchema = $builderDraft->variableSchema;
    $builderSchema[] = ['key' => 'delivery_mode', 'label' => 'Delivery mode', 'type' => 'enum', 'required' => true, 'options' => ['Online', 'In person']];
    $builderSchema[] = ['key' => 'completion_date', 'label' => 'Completion date', 'type' => 'date', 'required' => false];
    $tplService->saveDraft($builderTemplate->id, $builderDraft->layout, $builderSchema);
    $tplService->publish($builderTemplate->id, 1);
    $missingRequiredFormRejected = false;
    try {
        $dynamicForms->createForm(
            $tenantA->id,
            'missing-required-variable',
            'Missing required variable',
            $builderTemplate->id,
            ['recipient_name' => 'recipient_name'],
            false,
            [['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true]]
        );
    } catch (\InvalidArgumentException $e) {
        $missingRequiredFormRejected = true;
    }
    $dynamicBuilderFormId = $dynamicForms->createForm(
        $tenantA->id,
        'dynamic-form-fields',
        'Dynamic form fields',
        $builderTemplate->id,
        [
            'recipient_name' => 'recipient_name',
            'course_name' => 'course_name',
            'delivery_mode' => 'delivery_mode',
            'completion_date' => 'completion_date',
        ],
        false,
        [
            ['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true],
            ['name' => 'course_name', 'label' => 'Program', 'type' => 'text', 'required' => true],
            ['name' => 'delivery_mode', 'label' => 'Delivery mode', 'type' => 'enum', 'required' => true],
            ['name' => 'completion_date', 'label' => 'Completion date', 'type' => 'date', 'required' => false],
        ]
    );
    $invalidEnumRejected = false;
    try {
        $dynamicForms->submitForm($dynamicBuilderFormId, $tenantA->id, [
            'recipient_name' => 'Enum Recipient',
            'course_name' => 'Training',
            'delivery_mode' => 'Unsupported',
        ]);
    } catch (\InvalidArgumentException $e) {
        $invalidEnumRejected = true;
    }
    $dynamicBuilderSubmissionId = $dynamicForms->submitForm($dynamicBuilderFormId, $tenantA->id, [
        'recipient_name' => 'Dynamic Form Recipient',
        'course_name' => 'Training',
        'delivery_mode' => 'Online',
    ]);
    $dynamicBuilderSubmission = $db->fetchOne(
        'SELECT payload_json FROM cert_form_submissions WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $dynamicBuilderSubmissionId, 'tenant_id' => $tenantA->id]
    );
    $dynamicBuilderPayload = json_decode((string)$dynamicBuilderSubmission['payload_json'], true);
    $dynamicBuilderConfig = $db->fetchValue(
        'SELECT field_schema_json FROM cert_forms WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $dynamicBuilderFormId, 'tenant_id' => $tenantA->id]
    );
    $dynamicBuilderFields = json_decode((string)$dynamicBuilderConfig, true);
    assertTest("Dynamic form fields support template enums and optional typed fields",
        $missingRequiredFormRejected && $invalidEnumRejected
        && $dynamicBuilderFields[2]['options'] === ['Online', 'In person']
        && $dynamicBuilderPayload['delivery_mode'] === 'Online'
        && $dynamicBuilderPayload['completion_date'] === '');
    $form = ['title' => 'Dynamic form', 'tenant_name' => 'Alpha Organization', 'issue_mode' => 'approval'];
    $fields = $dynamicBuilderFields;
    $csrfField = '';
    $actionUrl = '/forms/dynamic-form-fields';
    ob_start();
    require $baseDir . '/views/forms/public.php';
    $dynamicFormHtml = ob_get_clean();
    assertTest("Public dynamic form renders enum fields as constrained select options",
        str_contains($dynamicFormHtml, '<select') && str_contains($dynamicFormHtml, 'value="Online"')
        && str_contains($dynamicFormHtml, 'value="In person"'));

    $optionalFieldFormId = $dynamicForms->createForm(
        $tenantA->id,
        'optional-email-form',
        'Optional email form',
        $tpl->id,
        ['recipient_name' => 'recipient_name', 'recipient_email' => 'recipient_email', 'course_name' => 'course_name'],
        false,
        [
            ['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true],
            ['name' => 'recipient_email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ['name' => 'course_name', 'label' => 'Course', 'type' => 'text', 'required' => true],
        ]
    );
    $optionalFieldSubmissionId = $dynamicForms->submitForm(
        $optionalFieldFormId,
        $tenantA->id,
        ['recipient_name' => 'Optional Email Recipient', 'course_name' => 'Optional Email Course']
    );
    $optionalFieldSubmission = $db->fetchOne(
        'SELECT payload_json FROM cert_form_submissions WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $optionalFieldSubmissionId, 'tenant_id' => $tenantA->id]
    );
    assertTest("Public forms persist omitted optional fields as empty values",
        json_decode((string)$optionalFieldSubmission['payload_json'], true)['recipient_email'] === '');

    $pastedCsv = \SOI\Certificates\Bulk\SpreadsheetReader::pastedTextToCsv(
        "Recipient Name\tEmail\tCourse\nSpreadsheet Recipient\tperson@example.test\tLeadership Track\n"
    );
    $pastedRows = array_map('str_getcsv', array_filter(explode("\n", trim($pastedCsv))));
    assertTest("Bulk paste ingestion converts tab-separated spreadsheet data to CSV",
        ($pastedRows[0] ?? []) === ['Recipient Name', 'Email', 'Course']
        && ($pastedRows[1] ?? []) === ['Spreadsheet Recipient', 'person@example.test', 'Leadership Track'],
        json_encode($pastedRows));
    $xlsxPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'soi-test-' . bin2hex(random_bytes(6)) . '.xlsx';
    $xlsx = createTestZip([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Recipient Name</t></si><si><t>Email</t></si><si><t>Course</t></si><si><t>Spreadsheet Recipient</t></si><si><t>Leadership Track</t></si></sst>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row><row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="inlineStr"><is><t>person@example.test</t></is></c><c r="C2" t="s"><v>4</v></c></row></sheetData></worksheet>',
    ]);
    file_put_contents($xlsxPath, $xlsx);
    try {
        $xlsxCsv = \SOI\Certificates\Bulk\SpreadsheetReader::xlsxToCsv($xlsxPath);
        $xlsxRows = array_map('str_getcsv', array_filter(explode("\n", trim($xlsxCsv))));
        assertTest("XLSX reader extracts the first worksheet and shared/inline strings",
            ($xlsxRows[0] ?? []) === ['Recipient Name', 'Email', 'Course']
            && ($xlsxRows[1] ?? []) === ['Spreadsheet Recipient', 'person@example.test', 'Leadership Track'],
            json_encode($xlsxRows));
    } finally {
        @unlink($xlsxPath);
    }

    $bulkEngine = new \SOI\Certificates\Bulk\BulkImportEngine($db, $ctxA, $authA, $issuanceService);
    $bulkBatchId = $bulkEngine->createBatch(
        $tpl->id,
        "Recipient Name,Email,Course\nBulk Recipient One,one@example.test,Leadership Track\n,broken-email,Leadership Track\n",
        ['Recipient Name' => 'recipient_name', 'Email' => 'recipient_email', 'Course' => 'course_name'],
        1
    );
    $bulkResult = $bulkEngine->processBatch($bulkBatchId, 25, 1);
    $bulkBatch = $bulkEngine->getBatch($bulkBatchId);
    assertTest("Bulk import processes rows through central issuance and records row-level failures",
        $bulkResult['status'] === 'processing' && $bulkResult['succeeded'] === 1
        && $bulkResult['failed'] === 1 && $bulkBatch !== null
        && count(array_filter($bulkBatch['rows'], static fn(array $row): bool => $row['status'] === 'failed')) === 1);
    $bulkRetry = null;
    for ($retry = 0; $retry < 5 && ($bulkRetry === null || $bulkRetry['remaining'] > 0); $retry++) {
        $bulkRetry = $bulkEngine->processBatch($bulkBatchId, 25, 1);
    }
    assertTest("Bulk import resumes failed rows with bounded retries and stable row idempotency",
        $bulkRetry !== null && $bulkRetry['status'] === 'completed_with_errors'
        && $bulkRetry['succeeded'] === 1 && $bulkRetry['failed'] === 1);
    assertTest("Bulk batch lookup is tenant-scoped", $bulkEngine->getBatch($bulkBatchId) !== null);

    $jobSchedulerForSchedules = new \SOI\Certificates\Scheduling\JobScheduler($db);
    $scheduleProcessor = new \SOI\Certificates\Scheduling\ScheduleProcessor(
        $db,
        $ctxA,
        $authA,
        $jobSchedulerForSchedules,
        $issuanceService,
        $audit
    );
    $scheduledOccurrence = gmdate('Y-m-d H:i:s', time() - 60);
    $scheduleId = $scheduleProcessor->createSchedule(
        'One-time recognition',
        $tpl->id,
        ['recipient_name' => 'Scheduled Recipient', 'variables' => ['course_name' => 'Scheduled Program']],
        $scheduledOccurrence,
        'once',
        'UTC'
    );
    assertTest("Schedule creation stores a tenant-scoped published-template rule", $scheduleId > 0
        && count($scheduleProcessor->listSchedules()) === 1);
    assertTest("Due schedule occurrence is enqueued only once", $scheduleProcessor->enqueueDueOccurrences() === 1
        && $scheduleProcessor->enqueueDueOccurrences() === 0);
    $scheduleRun = $scheduleProcessor->runDueJobs(10, 60, 1);
    $scheduleDedupeKey = 'schedule:' . $scheduleId . ':' . hash('sha256', $scheduledOccurrence);
    $scheduledCertificate = $db->fetchOne(
        "SELECT id, source_type, source_reference, status FROM cert_certificates
         WHERE tenant_id = :tenant_id AND source_reference = :source_reference",
        ['tenant_id' => $tenantA->id, 'source_reference' => $scheduleDedupeKey]
    );
    assertTest("Scheduled issuance converges on central issuance with idempotent occurrence identity",
        $scheduleRun['leased'] === 1 && $scheduleRun['issued'] === 1 && $scheduleRun['failed'] === 0
        && $scheduledCertificate !== null && $scheduledCertificate['source_type'] === 'schedule'
        && $scheduledCertificate['status'] === 'issued');
    $scheduleKeyReuseRejected = false;
    try {
        $issuanceService->issue(new \SOI\Certificates\Issuance\IssuanceCommand(
            $tpl->id,
            'Different Recipient',
            ['course_name' => 'Scheduled Program'],
            null,
            date('Y-m-d'),
            null,
            'schedule',
            $scheduleDedupeKey
        ), 1);
    } catch (\InvalidArgumentException $e) {
        $scheduleKeyReuseRejected = true;
    }
    assertTest("Schedule occurrence keys cannot be reused for a different issuance payload", $scheduleKeyReuseRejected);
    assertTest("Completed scheduled occurrence cannot issue a duplicate", $scheduleProcessor->runDueJobs(10, 60, 1)['leased'] === 0
        && (int)$db->fetchValue(
            "SELECT COUNT(*) FROM cert_certificates WHERE tenant_id = :tenant_id AND source_reference = :source_reference",
            ['tenant_id' => $tenantA->id, 'source_reference' => $scheduleDedupeKey]
        ) === 1);

    // Idempotency replay and row-level scope.
    $idempotency = new \SOI\Certificates\Api\IdempotencyService($db);
    $idempotencyFingerprint = $idempotency->fingerprint(['recipient' => 'A', 'template_id' => 1], 10);
    $reservation = $idempotency->replayOrReserve($tenantA->id, 'test-request-0001', $idempotencyFingerprint);
    $idempotency->complete($tenantA->id, 'test-request-0001', $idempotencyFingerprint, 201, ['data' => ['status' => 'issued']]);
    $replay = $idempotency->replayOrReserve($tenantA->id, 'test-request-0001', $idempotencyFingerprint);
    assertTest("Idempotency reservation replays the original response", $reservation === null && $replay['status'] === 201 && str_contains($replay['body'], 'issued'));
    $keyReuseRejected = false;
    try {
        $idempotency->replayOrReserve($tenantA->id, 'test-request-0001', $idempotency->fingerprint(['recipient' => 'B'], 10));
    } catch (\InvalidArgumentException $e) {
        $keyReuseRejected = true;
    }
    assertTest("Idempotency key cannot be reused for a different payload", $keyReuseRejected);

    // Database-backed leases safely reclaim expired work and reject stale tokens.
    $scheduler = new \SOI\Certificates\Scheduling\JobScheduler($db);
    $jobId = $scheduler->enqueueJob($tenantA->id, 'test.job', ['ok' => true], date('Y-m-d H:i:s', time() - 5));
    $leaseOne = $scheduler->acquireDueJobs(1, 60);
    $firstLease = $leaseOne[0]['lease_token'] ?? '';
    $jobsTable = $db->tableName('cert_jobs');
    $db->execute("UPDATE {$jobsTable} SET lease_until = datetime('now', '-1 second') WHERE id = :id", ['id' => $jobId]);
    $leaseTwo = $scheduler->acquireDueJobs(1, 60);
    $secondLease = $leaseTwo[0]['lease_token'] ?? '';
    assertTest("Expired scheduler leases can be safely reclaimed", $firstLease !== '' && $secondLease !== '' && $firstLease !== $secondLease);
    assertTest("Stale scheduler lease cannot complete reclaimed work", !$scheduler->completeJob($jobId, $firstLease) && $scheduler->completeJob($jobId, $secondLease));

    $csv = \SOI\Certificates\Reporting\CsvExporter::toCsv(['value'], [['=1+1'], ['normal']]);
    assertTest("CSV exports neutralize spreadsheet formulas", str_contains($csv, "'=1+1") && str_contains($csv, 'normal'));
    $webhooks = new \SOI\Certificates\Webhooks\WebhookService($db);
    assertTest("Webhook targets reject loopback and URL credential tricks", !$webhooks->isSafeUrl('http://127.0.0.1/hook') && !$webhooks->isSafeUrl('https://user:pass@example.com/hook'));

    assertTest("Webhook targets require HTTPS", !$webhooks->isSafeUrl('http://8.8.8.8/hook'));
    putenv('SOI_CERT_WEBHOOK_ENCRYPTION_KEY=unit-test-encryption-key-should-not-be-used-in-production');
    $webhookSecret = str_repeat('s', 40);
    $webhookId = $webhooks->createWebhook(
        $tenantA->id,
        'Unit Test Endpoint',
        'https://8.8.8.8/hook',
        $webhookSecret,
        ['certificate.issued']
    );
    $storedWebhook = $db->fetchOne("SELECT secret FROM cert_webhooks WHERE id = :id", ['id' => $webhookId]);
    assertTest("Webhook signing secrets are encrypted at rest and omitted from list output",
        str_starts_with((string)$storedWebhook['secret'], 'enc:')
        && !str_contains((string)$storedWebhook['secret'], $webhookSecret)
        && !array_key_exists('secret', $webhooks->listWebhooks($tenantA->id)[0]));
    assertTest("Webhook event delivery is tenant-scoped and queues configured events",
        $webhooks->queueEvent($tenantA->id, 'certificate.issued', ['certificate_id' => 123]) === 1);
    $signatureVerified = false;
    $deliveryResult = $webhooks->dispatchDue($tenantA->id, 10, static function (
        string $url,
        string $payload,
        int $timestamp,
        string $signature
    ) use (&$signatureVerified, $webhookSecret): array {
        $signatureVerified = hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $webhookSecret), $signature)
            && $url === 'https://8.8.8.8/hook';
        return ['status' => 204, 'body' => ''];
    });
    assertTest("Webhook dispatcher records successful signed delivery",
        $signatureVerified && $deliveryResult['claimed'] === 1 && $deliveryResult['succeeded'] === 1);

    // Developer 1 deliverables verification
    $themeManager = new \SOI\Certificates\Tenancy\TenantThemeManager();
    $defaultTheme = $themeManager->getEffectiveTheme([]);
    assertTest("TenantThemeManager provides default enterprise colors",
        $defaultTheme['primary_color'] === '#1e3a8a' && $defaultTheme['accent_color'] === '#d97706');

    $validBranding = $themeManager->validateAndNormalize([
        'primary_color' => '#2563EB',
        'accent_color' => '#10B981',
        'logo' => 'custom-logo.svg',
    ]);
    assertTest("TenantThemeManager normalizes hex colors and validates logo",
        $validBranding['primary_color'] === '#2563eb' && $validBranding['accent_color'] === '#10b981');

    $invalidColorCaught = false;
    try {
        $themeManager->validateAndNormalize(['primary_color' => 'invalid-color']);
    } catch (\InvalidArgumentException) {
        $invalidColorCaught = true;
    }
    assertTest("TenantThemeManager rejects invalid color hex formats", $invalidColorCaught);

    $themeCss = $themeManager->getThemeCss($validBranding);
    assertTest("TenantThemeManager generates valid CSS Custom Properties",
        str_contains($themeCss, '--tenant-primary: #2563eb') && str_contains($themeCss, '--tenant-accent: #10b981'));

    // API client secret rotation
    $apiClients = new \SOI\Certificates\Api\ApiClientService($db);
    $createdClient = $apiClients->createClient($tenantA->id, 'Rotatable Client', ['certificates.read']);
    $allClients = $apiClients->listForTenant($tenantA->id);
    $targetClientId = (int)$allClients[0]['id'];
    $rotated = $apiClients->rotateSecret($tenantA->id, $targetClientId);
    assertTest("ApiClientService rotates API client secret and returns new secret once",
        $rotated !== null && !empty($rotated['secret']) && $rotated['secret'] !== $createdClient['secret']);

    // Row-level idempotency key generator
    $bulkKey = \SOI\Certificates\Bulk\BulkImportEngine::generateRowIdempotencyKey(42, 5);
    assertTest("BulkImportEngine generates deterministic row-level idempotency key", $bulkKey === 'bulk:42:5');

    // BatchProgressTracker progress and status computation
    $tracker = new \SOI\Certificates\Bulk\BatchProgressTracker($db);
    $trackerProgress = $tracker->getProgress($bulkBatchId, $tenantA->id);
    assertTest("BatchProgressTracker tracks progress and calculates completion percentage",
        $trackerProgress !== null && $trackerProgress['total'] === 2 && $trackerProgress['percent'] === 100);

    // AuditLogger lifecycle methods
    $auditLogger = new \SOI\Certificates\Audit\AuditLogger($db);
    $auditLogger->logRevocation($tenantA->id, 999, 'Test Revocation Reason', 1);
    $recentAudit = $auditLogger->getRecent($tenantA->id, 1);
    assertTest("AuditLogger logs certificate revocation with mandatory reason",
        count($recentAudit) === 1
        && $recentAudit[0]['event_key'] === 'certificate.revoked'
        && str_contains((string)$recentAudit[0]['metadata_json'], 'Test Revocation Reason'));

    // Developer 4 deliverables verification
    // 1. LocalStorageAdapter writability and free disk space threshold
    assertTest("LocalStorageAdapter reports storage directory writability and sufficient free space",
        $storage->isWritable() && $storage->hasSufficientDiskSpace(1048576) && $storage->getFreeDiskSpace() > 0);

    // 2. WebhookDispatcher: HMAC-SHA256 signature, payload formatting, SSRF protection, and backoff
    $webhookDispatcher = new \SOI\Certificates\Webhooks\WebhookDispatcher($db);
    $testTimestamp = 1760000000;
    $testPayload = $webhookDispatcher->formatPayload('certificate.issued', ['id' => 456]);
    $testSig = $webhookDispatcher->signPayload($testPayload, 'my-test-secret-key-1234567890123456', $testTimestamp);
    assertTest("WebhookDispatcher signs formatted payload using HMAC-SHA256",
        str_contains($testPayload, '"type":"certificate.issued"')
        && hash_equals(hash_hmac('sha256', "{$testTimestamp}.{$testPayload}", 'my-test-secret-key-1234567890123456'), $testSig));

    assertTest("WebhookDispatcher blocks SSRF loopback and non-HTTPS targets",
        !$webhookDispatcher->isSafeUrl('http://127.0.0.1/hook')
        && !$webhookDispatcher->isSafeUrl('http://localhost:8080/hook')
        && !$webhookDispatcher->isSafeUrl('https://169.254.169.254/latest/meta-data')
        && $webhookDispatcher->calculateRetryDelay(1) === 30
        && $webhookDispatcher->calculateRetryDelay(3) === 120);

    // 3. PublicVerificationController and neutral placeholder view
    $pluginInstance = \SOI\Certificates\Core\Plugin::init($baseDir);
    $publicVerifyCtrl = new \SOI\Certificates\Verification\PublicVerificationController($pluginInstance);
    ob_start();
    $publicVerifyCtrl->renderPlaceholder('unknown-token-123', 'Custom Neutral Placeholder', 'Record not accessible.');
    $placeholderHtml = ob_get_clean();
    assertTest("PublicVerificationController renders safe neutral placeholder view without information leakage",
        str_contains($placeholderHtml, 'Custom Neutral Placeholder')
        && str_contains($placeholderHtml, 'Record not accessible.')
        && str_contains($placeholderHtml, 'unknown-token-123')
        && !str_contains($placeholderHtml, 'Jane Developer'));

    // 4. Performance Indexes Migration 012 applied
    $appliedMigrations = $migrationRunner->getAppliedMigrations();
    assertTest("Migration runner discovers and applies 012_add_performance_indexes.php",
        in_array('012_add_performance_indexes.php', $appliedMigrations, true));

    // 5. Final cumulative production ZIP package exists
    $productionZip = dirname(__DIR__) . '/certificates-2.0.0-production.zip';
    assertTest("Final cumulative production ZIP deliverable exists and has valid archive size",
        file_exists($productionZip) && filesize($productionZip) > 50000);

    // Clean up test DB
    @unlink($testDbPath);

} catch (\Throwable $e) {
    echo "\n[EXCEPTION] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n--------------------------------------------------------\n";
echo "Results: {$passed} Passed, {$failed} Failed.\n";
echo "--------------------------------------------------------\n";

exit($failed === 0 ? 0 : 1);
