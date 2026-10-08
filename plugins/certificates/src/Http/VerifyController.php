<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\CmsIdentity;
use SOI\Certificates\Core\Session;

class VerifyController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function verify(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $pin = $_GET['pin'] ?? null;

        // Security headers
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, max-age=0');

        $verificationToken = $token;
        $result = $this->plugin->verificationService->verify($token, null, CmsIdentity::current()->userId !== null);

        require $this->plugin->baseDir . '/views/verify.php';
    }

    public function verifyWithPin(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, max-age=0');
        $verificationToken = $token;
        $result = $this->plugin->verificationService->verify(
            $token,
            isset($_POST['pin']) ? (string)$_POST['pin'] : null,
            CmsIdentity::current()->userId !== null
        );
        require $this->plugin->baseDir . '/views/verify.php';
    }
}
