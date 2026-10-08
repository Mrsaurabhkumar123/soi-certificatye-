<?php
declare(strict_types=1);

namespace SOI\Certificates\Verification;

use InvalidArgumentException;
use SOI\Certificates\Core\Database;

final class VerificationPolicy
{
    private const MODES = ['public', 'masked', 'pin', 'authenticated', 'disabled'];

    public function __construct(private readonly Database $db)
    {
    }

    public function getForTenant(int $tenantId): array
    {
        $table = $this->db->tableName('cert_settings');
        $row = $this->db->fetchOne(
            "SELECT value_json FROM {$table} WHERE tenant_id = :tenant_id AND setting_key = 'verification_policy'",
            ['tenant_id' => $tenantId]
        );
        $settings = $row ? json_decode((string)$row['value_json'], true) : null;
        if (!is_array($settings)) {
            return ['mode' => 'public', 'pin_hash' => null];
        }
        return [
            'mode' => in_array($settings['mode'] ?? null, self::MODES, true) ? $settings['mode'] : 'disabled',
            'pin_hash' => is_string($settings['pin_hash'] ?? null) ? $settings['pin_hash'] : null,
        ];
    }

    public function publicConfiguration(int $tenantId): array
    {
        $policy = $this->getForTenant($tenantId);
        return ['mode' => $policy['mode'], 'pin_configured' => $policy['pin_hash'] !== null];
    }

    public function configure(int $tenantId, string $mode, ?string $pin = null): void
    {
        if ($tenantId < 1 || !in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Verification policy configuration is invalid.');
        }
        $current = $this->getForTenant($tenantId);
        $pinHash = $current['pin_hash'];
        if ($mode === 'pin' && $pin !== null && $pin !== '') {
            if (!preg_match('/^[0-9]{6,12}$/', $pin)) {
                throw new InvalidArgumentException('Verification PIN must contain 6 to 12 digits.');
            }
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            if ($pinHash === false) {
                throw new InvalidArgumentException('Unable to secure the verification PIN.');
            }
        }
        if ($mode === 'pin' && $pinHash === null) {
            throw new InvalidArgumentException('Set a verification PIN before enabling PIN protection.');
        }

        $table = $this->db->tableName('cert_settings');
        $json = json_encode(['mode' => $mode, 'pin_hash' => $pinHash], JSON_THROW_ON_ERROR);
        if ($this->db->getDriver() === 'sqlite') {
            $this->db->execute(
                "INSERT INTO {$table} (tenant_id, setting_key, value_json, updated_at)
                 VALUES (:tenant_id, 'verification_policy', :value, datetime('now'))
                 ON CONFLICT(tenant_id, setting_key) DO UPDATE SET value_json = excluded.value_json, updated_at = datetime('now')",
                ['tenant_id' => $tenantId, 'value' => $json]
            );
            return;
        }
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, setting_key, value_json, updated_at)
             VALUES (:tenant_id, 'verification_policy', :value, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = CURRENT_TIMESTAMP",
            ['tenant_id' => $tenantId, 'value' => $json]
        );
    }

    public function allows(int $tenantId, ?string $pin, bool $authenticated): bool
    {
        $policy = $this->getForTenant($tenantId);
        return match ($policy['mode']) {
            'public', 'masked' => true,
            'pin' => $pin !== null && $policy['pin_hash'] !== null && password_verify($pin, $policy['pin_hash']),
            'authenticated' => $authenticated,
            default => false,
        };
    }
}
