<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * Interface that each functional product module implements.
 */
interface ModuleInterface
{
    public function getName(): string;
    public function registerRoutes(Router $router): void;
    public function getPermissions(): array;
    public function getNavigationItems(string $surface): array;
}

/**
 * Module registry managing module discovery and component lifecycle.
 */
class ModuleRegistry
{
    /** @var ModuleInterface[] */
    protected array $modules = [];

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->getName()] = $module;
    }

    public function get(string $name): ?ModuleInterface
    {
        return $this->modules[$name] ?? null;
    }

    /** @return ModuleInterface[] */
    public function all(): array
    {
        return $this->modules;
    }

    public function registerAllRoutes(Router $router): void
    {
        foreach ($this->modules as $module) {
            $module->registerRoutes($router);
        }
    }

    public function getAllPermissions(): array
    {
        $perms = [];
        foreach ($this->modules as $module) {
            $perms = array_merge($perms, $module->getPermissions());
        }
        return array_unique($perms);
    }
}
