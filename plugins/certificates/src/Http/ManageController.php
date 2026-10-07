<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;

class ManageController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function index(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        $templates = $this->plugin->templateService->getTenantTemplates();
        $recentAudit = $this->plugin->audit->getRecent($tenant->id, 20);

        require $this->plugin->baseDir . '/views/manage.php';
    }

    public function createTemplate(): void
    {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if (!empty($name) && !empty($slug)) {
            $this->plugin->templateService->createTemplate($slug, $name, $category);
            Session::flash('success', "Template '{$name}' created in draft state.");
        } else {
            Session::flash('error', "Template name and slug are required.");
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function publishTemplate(): void
    {
        $id = (int)($_POST['template_id'] ?? 0);
        try {
            $version = $this->plugin->templateService->publish($id, 1);
            Session::flash('success', "Template published as immutable version v{$version->versionNumber}.");
        } catch (\Throwable $e) {
            Session::flash('error', "Publish error: " . $e->getMessage());
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }
}
