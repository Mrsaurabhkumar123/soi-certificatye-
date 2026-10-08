<?php
declare(strict_types=1);

namespace SOI\Certificates\Api;

final class ApiClientAuth
{
    public function __construct(private readonly ApiClientService $clients)
    {
    }

    public function authenticateRequest(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer[ \t]+([A-Za-z0-9._~-]{24,512})$/i', trim($header), $matches)) {
            return null;
        }

        return $this->clients->authenticateBearer($matches[1]);
    }
}
