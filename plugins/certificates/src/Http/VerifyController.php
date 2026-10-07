<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;

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
        header('X-Frame-Options: SAMEORIGIN');

        $result = $this->plugin->verificationService->verify($token, $pin);

        require $this->plugin->baseDir . '/views/verify.php';
    }
}
