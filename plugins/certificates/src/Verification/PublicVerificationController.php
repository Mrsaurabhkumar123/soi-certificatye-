<?php
declare(strict_types=1);

namespace SOI\Certificates\Verification;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\CmsIdentity;

/**
 * Public Verification Route Controller & Stub.
 * Enforces strict security headers, anti-enumeration protections,
 * and safe neutral placeholder rendering for unverified or unknown tokens.
 */
class PublicVerificationController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Send mandatory security and anti-indexing headers.
     */
    protected function sendSecurityHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store, max-age=0');
        }
    }

    /**
     * Public GET verification handler for /verify/{token}.
     */
    public function verify(array $params): void
    {
        $this->sendSecurityHeaders();

        $token = trim((string)($params['token'] ?? ''));
        if ($token === '') {
            $this->renderPlaceholder($token, 'Registry Verification Record', 'No verification token provided.');
            return;
        }

        $pin = isset($_GET['pin']) ? (string)$_GET['pin'] : null;
        $isAuthenticated = CmsIdentity::current()->userId !== null;

        $result = $this->plugin->verificationService->verify($token, $pin, $isAuthenticated);

        // If verification is disabled or record not found, render safe neutral placeholder
        if ($result->status === 'disabled') {
            $this->renderPlaceholder($token, 'Registry Verification Record', 'No public record is available for this verification identifier.');
            return;
        }

        $verificationToken = $token;
        require $this->plugin->baseDir . '/views/verify.php';
    }

    /**
     * Public POST verification handler for /verify/{token} with PIN submission.
     */
    public function verifyWithPin(array $params): void
    {
        $this->sendSecurityHeaders();

        $token = trim((string)($params['token'] ?? ''));
        $pin = isset($_POST['pin']) ? (string)$_POST['pin'] : null;
        $isAuthenticated = CmsIdentity::current()->userId !== null;

        $result = $this->plugin->verificationService->verify($token, $pin, $isAuthenticated);

        if ($result->status === 'disabled') {
            $this->renderPlaceholder($token, 'Registry Verification Record', 'No public record is available for this verification identifier.');
            return;
        }

        $verificationToken = $token;
        require $this->plugin->baseDir . '/views/verify.php';
    }

    /**
     * Render the safe neutral verification placeholder view.
     */
    public function renderPlaceholder(string $token = '', ?string $title = null, ?string $message = null): void
    {
        $this->sendSecurityHeaders();
        $verificationToken = $token;
        $placeholderTitle = $title;
        $placeholderMessage = $message;

        require $this->plugin->baseDir . '/views/verify/placeholder.php';
    }
}
