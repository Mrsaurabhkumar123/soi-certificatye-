<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;

class DocsController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function index(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        require $this->plugin->baseDir . '/views/docs.php';
    }
}
