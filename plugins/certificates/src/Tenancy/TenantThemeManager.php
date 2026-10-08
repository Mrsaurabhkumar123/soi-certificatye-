<?php
declare(strict_types=1);

namespace SOI\Certificates\Tenancy;

use InvalidArgumentException;

/**
 * Tenant Theme Manager
 * Responsible for validating tenant theme & branding configurations,
 * providing sensible defaults, and generating CSS variables for UI surfaces.
 */
final class TenantThemeManager
{
    public const DEFAULT_PRIMARY_COLOR = '#1e3a8a';
    public const DEFAULT_ACCENT_COLOR = '#d97706';

    /**
     * Validate and normalize branding inputs.
     *
     * @param array $branding Raw branding array (e.g. from $_POST or settings)
     * @return array Normalized branding settings
     * @throws InvalidArgumentException If colors or fields violate security/formatting rules
     */
    public function validateAndNormalize(array $branding): array
    {
        $allowed = ['primary_color', 'accent_color', 'logo'];
        $clean = [];

        foreach ($branding as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported branding setting: '{$key}'");
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException("Branding value for '{$key}' must be a string.");
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $clean[$key] = $trimmed;
            }
        }

        foreach (['primary_color', 'accent_color'] as $colorKey) {
            if (isset($clean[$colorKey])) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $clean[$colorKey])) {
                    throw new InvalidArgumentException(
                        ucfirst(str_replace('_', ' ', $colorKey)) . " must use 6-digit hex format (e.g. #1e3a8a)."
                    );
                }
                $clean[$colorKey] = strtolower($clean[$colorKey]);
            }
        }

        if (isset($clean['logo'])) {
            if (strlen($clean['logo']) > 255) {
                throw new InvalidArgumentException('Branding logo reference must not exceed 255 characters.');
            }
            if (preg_match('/[\x00-\x1F\x7F<>"\'`]/', $clean['logo'])) {
                throw new InvalidArgumentException('Branding logo reference contains disallowed characters.');
            }
        }

        return $clean;
    }

    /**
     * Get effective theme tokens for a tenant, falling back to enterprise defaults.
     */
    public function getEffectiveTheme(Tenant|array $tenantOrBranding): array
    {
        $branding = $tenantOrBranding instanceof Tenant
            ? $tenantOrBranding->branding
            : $tenantOrBranding;

        return [
            'primary_color' => $branding['primary_color'] ?? self::DEFAULT_PRIMARY_COLOR,
            'accent_color'  => $branding['accent_color'] ?? self::DEFAULT_ACCENT_COLOR,
            'logo'          => $branding['logo'] ?? '',
        ];
    }

    /**
     * Generate sanitized CSS Custom Properties for embedding in HTML <style> tags.
     */
    public function getThemeCss(Tenant|array $tenantOrBranding): string
    {
        $theme = $this->getEffectiveTheme($tenantOrBranding);
        $primary = htmlspecialchars($theme['primary_color'], ENT_QUOTES, 'UTF-8');
        $accent = htmlspecialchars($theme['accent_color'], ENT_QUOTES, 'UTF-8');

        return ":root { --tenant-primary: {$primary}; --tenant-accent: {$accent}; }";
    }
}
