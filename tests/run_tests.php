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

try {
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

    // Negative Test: Tenant B cannot access Tenant A's template
    $tplServiceB = new \SOI\Certificates\Templates\TemplateService($db, $ctxB);
    $leakedTpl = $tplServiceB->findById($tpl->id);
    assertTest("Cross-tenant isolation: Tenant B cannot access Tenant A template", $leakedTpl === null);

    // 7. Local Storage Adapter
    $testStorageDir = sys_get_temp_dir() . '/soi_storage_' . time();
    $storage = new \SOI\Certificates\Storage\LocalStorageAdapter($testStorageDir);
    $storage->put('test/file.txt', 'SOI CERTIFICATE PLATFORM');
    assertTest("Local storage adapter persists and hashes artifacts", $storage->exists('test/file.txt') && strlen($storage->hash('test/file.txt')) === 64);

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
    assertTest("Certificate artifact file exists in storage", $storage->exists($cert->filePath));

    // 10. Authoritative Verification
    $verifyService = new \SOI\Certificates\Verification\VerificationService($db);
    $verifyRes = $verifyService->verify($cert->verificationToken);
    assertTest("Verification service confirms authentic certificate", $verifyRes->found && $verifyRes->status === 'valid' && $verifyRes->recipientName === 'Jane Developer');

    // 11. Lifecycle Revocation
    $revoked = $issuanceService->revoke($cert->id, 'Issued in error', 1);
    assertTest("Revocation transition succeeds", $revoked);

    $verifyRevoked = $verifyService->verify($cert->verificationToken);
    assertTest("Verification service immediately reflects REVOKED status", $verifyRevoked->status === 'revoked');

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
