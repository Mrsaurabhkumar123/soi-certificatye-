<?php
declare(strict_types=1);

namespace SOI\Certificates\Authorization;

/**
 * Granular capability permission definitions.
 */
class Permissions
{
    public const PLATFORM_READ = 'platform.read';
    public const PLATFORM_MANAGE = 'platform.manage';

    public const TENANTS_READ = 'tenants.read';
    public const TENANTS_MANAGE = 'tenants.manage';
    public const ROLES_MANAGE = 'roles.manage';

    public const TEMPLATES_READ = 'templates.read';
    public const TEMPLATES_CREATE = 'templates.create';
    public const TEMPLATES_UPDATE = 'templates.update';
    public const TEMPLATES_PUBLISH = 'templates.publish';
    public const TEMPLATES_ARCHIVE = 'templates.archive';

    public const CERTIFICATES_READ = 'certificates.read';
    public const CERTIFICATES_ISSUE = 'certificates.issue';
    public const CERTIFICATES_DOWNLOAD = 'certificates.download';
    public const CERTIFICATES_REVOKE = 'certificates.revoke';
    public const CERTIFICATES_REPLACE = 'certificates.replace';
    public const CERTIFICATES_CANCEL = 'certificates.cancel';

    public const FORMS_MANAGE = 'forms.manage';
    public const FORMS_SUBMIT = 'forms.submit';

    public const SCHEDULES_MANAGE = 'schedules.manage';
    public const API_CLIENTS_MANAGE = 'api_clients.manage';
    public const WEBHOOKS_MANAGE = 'webhooks.manage';

    public const AUDIT_READ = 'audit.read';
    public const REPORTS_EXPORT = 'reports.export';
    public const VERIFICATION_MANAGE = 'verification.manage';
}
