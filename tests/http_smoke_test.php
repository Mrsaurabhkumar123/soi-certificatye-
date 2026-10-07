<?php
declare(strict_types=1);

/**
 * HTTP Smoke Test validating all primary web surfaces and REST APIs.
 */

// Start session early in CLI environment before any output
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

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
ob_start();
$router->dispatch('GET', '/api/v1/health');
$output = ob_get_clean();
$json = json_decode($output, true);
assertHttp("GET /api/v1/health returns valid JSON", isset($json['data']['status']) && $json['data']['status'] === 'healthy');

// 2. Test /api/v1/templates
ob_start();
$router->dispatch('GET', '/api/v1/templates');
$output = ob_get_clean();
$json = json_decode($output, true);
assertHttp("GET /api/v1/templates returns template array", isset($json['data']) && is_array($json['data']));

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

// 4. Test /verify/{token}
ob_start();
$router->dispatch('GET', '/verify/' . $apiCert->verificationToken);
$output = ob_get_clean();
assertHttp("GET /verify/{token} renders authentic badge and recipient", str_contains($output, 'Authentic Certificate') && str_contains($output, 'Dr. Robert Miller'));

// 5. Test /console
ob_start();
$router->dispatch('GET', '/console');
$output = ob_get_clean();
assertHttp("GET /console renders operator shell", str_contains($output, 'Operations Console') && str_contains($output, 'Issue New Certificate'));

// 6. Test /manage
ob_start();
$router->dispatch('GET', '/manage');
$output = ob_get_clean();
assertHttp("GET /manage renders tenant administration shell", str_contains($output, 'Certificate Templates') && str_contains($output, 'Recent Audit Trail'));

// 7. Test /super-admin
ob_start();
$router->dispatch('GET', '/super-admin');
$output = ob_get_clean();
assertHttp("GET /super-admin renders platform control shell", str_contains($output, 'Platform Super Admin') && str_contains($output, 'Tenant Organizations'));

// 8. Test /docs
ob_start();
$router->dispatch('GET', '/docs');
$output = ob_get_clean();
assertHttp("GET /docs renders developer documentation", str_contains($output, 'SOI Certificate Platform Documentation') && str_contains($output, '/api/v1/certificates'));




echo "\n--------------------------------------------------------\n";
echo "Smoke Results: {$passed} Passed, {$failed} Failed.\n";
echo "--------------------------------------------------------\n";

exit($failed === 0 ? 0 : 1);
