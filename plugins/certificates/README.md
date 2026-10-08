# SOI Certificate Management Plugin

Enterprise modular multi-tenant certificate issuance, verification, and lifecycle management plugin for SOI CMS.

## Architecture
- **Namespace**: `SOI\Certificates`
- **Autoloader**: PSR-4 lightweight autoloader in `src/Core/Autoloader.php`
- **Bootstrap**: `plugin.php`
- **Migrations**: Web-safe incremental migrations in `migrations/` (001 to 012)
- **Isolation**: Tenant-isolated repository boundary and file storage
- **Renderer**: Pure-PHP self-hosted PDF-1.4 and Vector QR Code engines

See the root [README.md](../../README.md) and [docs/development-status.md](../../docs/development-status.md) for complete details.
