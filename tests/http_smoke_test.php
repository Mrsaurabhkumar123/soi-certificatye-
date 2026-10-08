<?php
declare(strict_types=1);

/**
 * HTTP Smoke Test validating all primary web surfaces and REST APIs.
 */

// Start session early in CLI environment before any output
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
putenv('SOI_CERT_ENV=development');
putenv('SOI_CERT_STANDALONE_DEMO=1');
ob_start();

$baseDir = dirname(__DIR__) . '/plugins/certificates';
require_once $baseDir . '/src/Core/Autoloader.php';
\SOI\Certificates\Core\Autoloader::register($baseDir . '/src');

$plugin = \SOI\Certificates\Core\Plugin::init($baseDir);
$router = $plugin->router;

echo "========================================================\n";
echo "   SOI Certificate Platform - HTTP Smoke Tests          \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertHttp(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $failed++;
    }
}

// 1. Test /api/v1/health
$apiCredentials = $plugin->apiClientService->createClient(1, 'Smoke Test Client', [
    'platform.read',
    'templates.read',
    'certificates.read',
    'users.read',
    'roles.read',
]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $apiCredentials['secret'];
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'smoke-api-issuance-2026-10-07';
ob_start();
$router->dispatch('GET', '/api/v1/health');
$output = ob_get_clean();
$json = json_decode($output, true);
assertHttp("GET /api/v1/health returns valid JSON", isset($json['data']['status']) && $json['data']['status'] === 'healthy');

// 2. Test Module APIs (/api/v1/templates, /api/v1/roles, /api/v1/users)
ob_start();
$router->dispatch('GET', '/api/v1/templates');
$output = ob_get_clean();
$json = json_decode($output, true);
assertHttp("GET /api/v1/templates returns template array", isset($json['data']) && is_array($json['data']));

ob_start();
$router->dispatch('GET', '/api/v1/roles');
$rolesOut = ob_get_clean();
$rolesJson = json_decode($rolesOut, true);
assertHttp("GET /api/v1/roles returns role specifications", isset($rolesJson['data']) && is_array($rolesJson['data']));

ob_start();
$router->dispatch('GET', '/api/v1/users');
$usersOut = ob_get_clean();
$usersJson = json_decode($usersOut, true);
assertHttp("GET /api/v1/users returns workspace memberships", isset($usersJson['data']) && is_array($usersJson['data']));

// 3. Test programmatic issuance
$cmd = new \SOI\Certificates\Issuance\IssuanceCommand(
    1,
    'Dr. Robert Miller',
    ['course_name' => 'Quantum Computing Workshop'],
    null,
    '2026-10-07'
);
$apiCert = $plugin->issuanceService->issue($cmd, 1);
assertHttp("Issuance produces valid certificate via unified engine", $apiCert->isIssued() && !empty($apiCert->verificationToken));

ob_start();
$router->dispatch('GET', '/console/certificates/' . $apiCert->id . '/download');
$downloadedPdf = ob_get_clean();
assertHttp("GET /console/certificates/{id}/download serves an integrity-verified PDF",
    str_starts_with($downloadedPdf, '%PDF-') && hash('sha256', $downloadedPdf) === $apiCert->fileSha256);

ob_start();
$router->dispatch('GET', '/api/v1/certificates/' . $apiCert->id);
$certApiOut = ob_get_clean();
$certApiJson = json_decode($certApiOut, true);
assertHttp("GET /api/v1/certificates/{id} returns certificate contract",
    isset($certApiJson['data']['id']) && $certApiJson['data']['id'] === $apiCert->id && isset($certApiJson['data']['verification_url']));

ob_start();
$router->dispatch('GET', '/api/v1/verification/' . $apiCert->verificationToken);
$verApiOut = ob_get_clean();
$verApiJson = json_decode($verApiOut, true);
assertHttp("GET /api/v1/verification/{token} returns machine-readable status contract",
    isset($verApiJson['data']['status']) && $verApiJson['data']['status'] === 'valid' && isset($verApiJson['data']['recipient_name']));

// 4. Test /verify/{token}
ob_start();
$router->dispatch('GET', '/verify/' . $apiCert->verificationToken);
$output = ob_get_clean();
assertHttp("GET /verify/{token} renders authentic badge and recipient", str_contains($output, 'Authentic Certificate') && str_contains($output, 'Dr. Robert Miller'));

// 5. Test /console
ob_start();
$router->dispatch('GET', '/console');
$output = ob_get_clean();
assertHttp("GET /console renders issuance and lifecycle controls", str_contains($output, 'Operations Console')
    && str_contains($output, 'Issue New Certificate') && str_contains($output, '/replace')
    && str_contains($output, 'filter_number')
    && str_contains($output, 'Revocation reason')
    && str_contains($output, 'Issued in last 30 days')
    && str_contains($output, 'Failures in last 30 days'));

http_response_code(200);
ob_start();
$router->dispatch('GET', '/console/certificates/' . $apiCert->id);
$certificateDetail = ob_get_clean();
assertHttp("GET /console/certificates/{id} renders tenant-safe lifecycle detail",
    str_contains($certificateDetail, $apiCert->certificateNumber) && str_contains($certificateDetail, 'Lifecycle timeline'));
http_response_code(200);
ob_start();
$router->dispatch('GET', '/manage/reports/certificates.csv?recipient=Dr.%20Robert');
$registryCsv = ob_get_clean();
assertHttp("GET /manage/reports/certificates.csv exports filtered registry data",
    str_contains($registryCsv, 'Certificate Number') && str_contains($registryCsv, 'Dr. Robert Miller'));

// 6. Test /manage
ob_start();
$router->dispatch('GET', '/manage');
$output = ob_get_clean();
assertHttp("GET /manage renders tenant administration and membership controls", str_contains($output, 'Certificate Templates')
    && str_contains($output, 'Recent Audit Trail') && str_contains($output, 'Tenant Members')
    && str_contains($output, '/manage/members/add') && str_contains($output, 'Form Approval Queue')
    && str_contains($output, 'Scheduled Issuance') && str_contains($output, '/manage/schedules/create')
    && str_contains($output, 'Pending form approvals')
    && str_contains($output, 'paste CSV/TSV data')
    && str_contains($output, 'Public form fields')
    && str_contains($output, '/assets/js/form-builder.js'));

assertHttp("GET /manage includes permission-aware side navigation and templates library",
    str_contains($output, 'app-sidebar') && str_contains($output, 'Templates Library'));

// 6b. Test /admin canonical alias to /manage
ob_start();
$router->dispatch('GET', '/admin');
$adminRedirectOut = ob_get_clean();
assertHttp("GET /admin canonically redirects (302) to /manage", http_response_code() === 302);

http_response_code(200);
ob_start();
$router->dispatch('GET', '/manage/templates/1/designer');
$designerHtml = ob_get_clean();
assertHttp("GET /manage/templates/{id}/designer renders the editor and interaction controls",
    str_contains($designerHtml, 'id="canvas"')
    && str_contains($designerHtml, 'id="bring-forward"')
    && str_contains($designerHtml, '/assets/js/designer-canvas.js'));

$publicFormKey = 'smoke-public-form-' . bin2hex(random_bytes(4));
$publicFormId = $plugin->dynamicFormService->createForm(
    $plugin->tenantContext->getTenantId(),
    $publicFormKey,
    'Smoke Public Application',
    1,
    ['recipient_name' => 'recipient_name', 'recipient_email' => 'recipient_email', 'course_name' => 'course_name'],
    false,
    [
        ['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true],
        ['name' => 'recipient_email', 'label' => 'Email', 'type' => 'email', 'required' => false],
        ['name' => 'course_name', 'label' => 'Course', 'type' => 'text', 'required' => true],
    ],
    'immediate'
);
ob_start();
$router->dispatch('GET', '/forms/' . $publicFormKey);
$publicFormHtml = ob_get_clean();
assertHttp("GET /forms/{form_key} renders a configured public application form",
    str_contains($publicFormHtml, 'Smoke Public Application') && str_contains($publicFormHtml, 'recipient_name'));

$_POST = [
    '_csrf_token' => \SOI\Certificates\Core\Session::getCsrfToken(),
    'fields' => [
        'recipient_name' => 'Public Form Recipient',
        'recipient_email' => 'public@example.test',
        'course_name' => 'Public Program',
    ],
];
$_SERVER['REMOTE_ADDR'] = '192.0.2.123';
http_response_code(200);
ob_start();
$router->dispatch('POST', '/forms/' . $publicFormKey);
$publicFormResponse = ob_get_clean();
assertHttp("POST /forms/{form_key} issues immediately only under the configured tenant form policy",
    http_response_code() === 201 && str_contains($publicFormResponse, 'Certificate issued'));
unset($_POST['_csrf_token'], $_POST['fields']);

$_POST['_csrf_token'] = \SOI\Certificates\Core\Session::getCsrfToken();
ob_start();
$router->dispatch('POST', '/scheduler/run');
$output = ob_get_clean();
$runnerResult = json_decode($output, true);
assertHttp("POST /scheduler/run is reachable through authorized CSRF-protected web control",
    isset($runnerResult['data']['queued'], $runnerResult['data']['leased']));
unset($_POST['_csrf_token']);

// 7. Test /super-admin
ob_start();
$router->dispatch('GET', '/super-admin');
$output = ob_get_clean();
assertHttp("GET /super-admin renders platform control shell", str_contains($output, 'Platform Super Admin') && str_contains($output, 'Tenant Organizations'));

// 8. Test /docs
ob_start();
$router->dispatch('GET', '/docs');
$output = ob_get_clean();
assertHttp("GET /docs renders developer documentation",
    str_contains($output, 'SOI Certificate Platform Documentation')
    && str_contains($output, '/api/v1/certificates')
    && str_contains($output, 'Response and Error Codes')
    && str_contains($output, 'IDEMPOTENCY_REQUIRED'));




echo "\n--------------------------------------------------------\n";
echo "Smoke Results: {$passed} Passed, {$failed} Failed.\n";
echo "--------------------------------------------------------\n";

ob_end_flush();
exit($failed === 0 ? 0 : 1);
