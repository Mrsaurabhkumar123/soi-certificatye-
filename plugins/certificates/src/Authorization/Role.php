<?php
declare(strict_types=1);

namespace SOI\Certificates\Authorization;

/**
 * Standard human role mapping and default capabilities.
 */
class Role
{
    public const PLATFORM_SUPER_ADMIN = 'platform_super_admin';
    public const TENANT_OWNER = 'tenant_owner';
    public const TENANT_ADMIN = 'tenant_admin';
    public const TEMPLATE_DESIGNER = 'template_designer';
    public const ISSUER = 'issuer';
    public const VIEWER = 'viewer';

    public static function getDefaultRolePermissions(): array
    {
        return [
            self::TENANT_OWNER => [
                Permissions::TENANTS_READ,
                Permissions::TENANTS_MANAGE,
                Permissions::USERS_READ,
                Permissions::USERS_MANAGE,
                Permissions::ROLES_READ,
                Permissions::ROLES_MANAGE,
                Permissions::TEMPLATES_READ,
                Permissions::TEMPLATES_CREATE,
                Permissions::TEMPLATES_UPDATE,
                Permissions::TEMPLATES_PUBLISH,
                Permissions::TEMPLATES_ARCHIVE,
                Permissions::CERTIFICATES_READ,
                Permissions::CERTIFICATES_ISSUE,
                Permissions::CERTIFICATES_DOWNLOAD,
                Permissions::CERTIFICATES_REVOKE,
                Permissions::CERTIFICATES_REPLACE,
                Permissions::CERTIFICATES_CANCEL,
                Permissions::FORMS_MANAGE,
                Permissions::FORMS_SUBMIT,
                Permissions::SCHEDULES_MANAGE,
                Permissions::API_CLIENTS_MANAGE,
                Permissions::WEBHOOKS_MANAGE,
                Permissions::AUDIT_READ,
                Permissions::REPORTS_EXPORT,
                Permissions::VERIFICATION_MANAGE,
                Permissions::SETTINGS_MANAGE,
            ],
            self::TENANT_ADMIN => [
                Permissions::TENANTS_READ,
                Permissions::TENANTS_MANAGE,
                Permissions::USERS_READ,
                Permissions::USERS_MANAGE,
                Permissions::ROLES_READ,
                Permissions::ROLES_MANAGE,
                Permissions::TEMPLATES_READ,
                Permissions::TEMPLATES_CREATE,
                Permissions::TEMPLATES_UPDATE,
                Permissions::TEMPLATES_PUBLISH,
                Permissions::TEMPLATES_ARCHIVE,
                Permissions::CERTIFICATES_READ,
                Permissions::CERTIFICATES_ISSUE,
                Permissions::CERTIFICATES_DOWNLOAD,
                Permissions::CERTIFICATES_REVOKE,
                Permissions::CERTIFICATES_REPLACE,
                Permissions::FORMS_MANAGE,
                Permissions::FORMS_SUBMIT,
                Permissions::SCHEDULES_MANAGE,
                Permissions::API_CLIENTS_MANAGE,
                Permissions::WEBHOOKS_MANAGE,
                Permissions::AUDIT_READ,
                Permissions::REPORTS_EXPORT,
                Permissions::VERIFICATION_MANAGE,
                Permissions::SETTINGS_MANAGE,
            ],
            self::TEMPLATE_DESIGNER => [
                Permissions::TEMPLATES_READ,
                Permissions::TEMPLATES_CREATE,
                Permissions::TEMPLATES_UPDATE,
                Permissions::TEMPLATES_PUBLISH,
                Permissions::TEMPLATES_ARCHIVE,
            ],
            self::ISSUER => [
                Permissions::TEMPLATES_READ,
                Permissions::CERTIFICATES_READ,
                Permissions::CERTIFICATES_ISSUE,
                Permissions::CERTIFICATES_DOWNLOAD,
                Permissions::CERTIFICATES_REVOKE,
                Permissions::CERTIFICATES_REPLACE,
                Permissions::FORMS_SUBMIT,
            ],
            self::VIEWER => [
                Permissions::TEMPLATES_READ,
                Permissions::CERTIFICATES_READ,
                Permissions::CERTIFICATES_DOWNLOAD,
            ],
        ];
    }
}
