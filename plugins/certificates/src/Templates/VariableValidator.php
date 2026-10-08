<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

use InvalidArgumentException;

final class VariableValidator
{
    private const SYSTEM_VARIABLES = ['certificate_number', 'verification_url', 'issue_date'];

    public function validateSchema(array $schema): array
    {
        $keys = [];
        foreach ($schema as $field) {
            if (!is_array($field)
                || !isset($field['key'], $field['type'])
                || !is_string($field['key'])
                || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field['key'])
                || !is_string($field['type'])) {
                throw new InvalidArgumentException('Variable schema entries require a valid key and type.');
            }
            $unknown = array_diff(array_keys($field), ['key', 'label', 'type', 'required', 'options']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Variable schema contains an unsupported property.');
            }
            if (isset($field['label']) && (!is_string($field['label']) || strlen($field['label']) > 128)) {
                throw new InvalidArgumentException('Variable labels must be plain text of at most 128 bytes.');
            }

            $type = $field['type'];
            if (!in_array($type, ['string', 'short_text', 'date', 'email', 'enum'], true)) {
                throw new InvalidArgumentException('Variable schema contains an unsupported type.');
            }
            if (isset($keys[$field['key']])) {
                throw new InvalidArgumentException('Variable schema keys must be unique.');
            }
            $keys[$field['key']] = true;

            if ($type === 'enum') {
                if (!isset($field['options']) || !is_array($field['options']) || $field['options'] === []) {
                    throw new InvalidArgumentException('Enum variables require a non-empty options list.');
                }
                foreach ($field['options'] as $option) {
                    if (!is_string($option) || $option === '' || strlen($option) > 255) {
                        throw new InvalidArgumentException('Enum options must be non-empty strings of at most 255 bytes.');
                    }
                }
            }
            if (isset($field['required']) && !is_bool($field['required'])) {
                throw new InvalidArgumentException('Variable required setting must be boolean.');
            }
        }
        return $schema;
    }

    public function validateValues(array $schema, array $values): array
    {
        $schema = $this->validateSchema($schema);
        $allowed = array_fill_keys(array_merge(self::SYSTEM_VARIABLES, ['recipient_email'], array_column($schema, 'key')), true);
        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('An unsupported template variable was supplied.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Template variable values must be scalar.');
            }
        }

        foreach ($schema as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? null;
            if (($field['required'] ?? false) && ($value === null || $value === '')) {
                throw new InvalidArgumentException('A required template variable is missing: ' . $key . '.');
            }
            if ($value === null || $value === '') {
                continue;
            }

            $value = (string)$value;
            if (strlen($value) > 5000) {
                throw new InvalidArgumentException('A template variable exceeds the supported length.');
            }
            if ($field['type'] === 'date' && !self::isValidDate($value)) {
                throw new InvalidArgumentException('A template date variable has an invalid value.');
            }
            if ($field['type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('A template email variable has an invalid value.');
            }
            if ($field['type'] === 'enum' && !in_array($value, $field['options'], true)) {
                throw new InvalidArgumentException('A template enum variable has an unsupported value.');
            }
        }
        return $values;
    }

    private static function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
