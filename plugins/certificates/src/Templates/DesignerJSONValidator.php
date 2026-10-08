<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

use InvalidArgumentException;
use JsonException;
use SOI\Certificates\Rendering\FontManager;

final class DesignerJSONValidator
{
    private const MAX_ELEMENTS = 100;
    private const MAX_COORDINATE = 10000;

    public function decodeAndValidate(string $json): array
    {
        try {
            $layout = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Template layout must be valid JSON.', 0, $e);
        }

        if (!is_array($layout)) {
            throw new InvalidArgumentException('Template layout must be a JSON object.');
        }

        return $this->validate($layout);
    }

    public function validate(array $layout): array
    {
        $this->assertAllowedKeys($layout, ['schema_version', 'page', 'theme', 'elements'], 'layout');

        if (isset($layout['schema_version']) && $layout['schema_version'] !== 1) {
            throw new InvalidArgumentException('Unsupported designer schema version.');
        }
        if (!isset($layout['page']) || !is_array($layout['page'])) {
            throw new InvalidArgumentException('Template layout must define a page.');
        }
        if (!isset($layout['elements']) || !is_array($layout['elements']) || $layout['elements'] === []) {
            throw new InvalidArgumentException('Template layout must contain at least one element.');
        }
        if (count($layout['elements']) > self::MAX_ELEMENTS) {
            throw new InvalidArgumentException('Template layout exceeds the supported element limit.');
        }

        $this->validatePage($layout['page']);
        if (isset($layout['theme'])) {
            $this->validateTheme($layout['theme']);
        }

        $ids = [];
        $bounds = $this->pageBounds($layout['page']);
        foreach ($layout['elements'] as $element) {
            if (!is_array($element)) {
                throw new InvalidArgumentException('Every designer element must be an object.');
            }
            $this->validateElement($element, $bounds);
            if (isset($ids[$element['id']])) {
                throw new InvalidArgumentException('Designer element IDs must be unique.');
            }
            $ids[$element['id']] = true;
        }

        return $layout;
    }

    private function validatePage(array $page): void
    {
        $this->assertAllowedKeys($page, ['size', 'orientation', 'width', 'height', 'unit'], 'page');
        $size = $page['size'] ?? null;
        if (!in_array($size, ['A4', 'Letter', 'custom'], true)) {
            throw new InvalidArgumentException('Page size must be A4, Letter or custom.');
        }
        if (!in_array($page['orientation'] ?? null, ['portrait', 'landscape'], true)) {
            throw new InvalidArgumentException('Page orientation must be portrait or landscape.');
        }
        if ($size === 'custom') {
            $this->assertDimension($page['width'] ?? null, 'page width');
            $this->assertDimension($page['height'] ?? null, 'page height');
            if (!in_array($page['unit'] ?? null, ['mm', 'in', 'pt'], true)) {
                throw new InvalidArgumentException('Custom page unit must be mm, in or pt.');
            }
        }
    }

    private function validateTheme(mixed $theme): void
    {
        if (!is_array($theme)) {
            throw new InvalidArgumentException('Template theme must be an object.');
        }
        $this->assertAllowedKeys($theme, ['primary_color', 'accent_color', 'background_color'], 'theme');
        foreach ($theme as $name => $color) {
            $this->assertColor($color, 'theme.' . $name);
        }
    }

    private function validateElement(array $element, array $bounds): void
    {
        $type = $element['type'] ?? null;
        if (!in_array($type, ['text', 'variable', 'image', 'line', 'rectangle', 'shape', 'qr'], true)) {
            throw new InvalidArgumentException('Template contains an unsupported element type.');
        }

        $keys = ['id', 'type', 'x', 'y', 'w', 'h', 'z_index'];
        $typeKeys = [
            'text' => ['value', 'font_size', 'align', 'bold', 'italic', 'color', 'font_family', 'line_height', 'letter_spacing'],
            'variable' => ['key', 'font_size', 'align', 'bold', 'italic', 'color', 'font_family'],
            'image' => ['asset_id', 'fit', 'alt'],
            'line' => ['color', 'stroke_width'],
            'rectangle' => ['color', 'fill_color', 'stroke_width'],
            'shape' => ['shape', 'color', 'fill_color', 'stroke_width'],
            'qr' => ['size'],
        ];
        $this->assertAllowedKeys($element, array_merge($keys, $typeKeys[$type]), 'element');

        if (!isset($element['id']) || !is_string($element['id']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $element['id'])) {
            throw new InvalidArgumentException('Every element must have a stable, valid ID.');
        }

        foreach (['x', 'y'] as $coordinate) {
            $this->assertCoordinate($element[$coordinate] ?? null, $coordinate);
        }
        if ($type === 'qr' && !isset($element['w'], $element['h'])) {
            $size = $element['size'] ?? null;
            if (!$this->isNumber($size) || $size <= 0 || $size > self::MAX_COORDINATE) {
                throw new InvalidArgumentException('QR size must be a positive number within the supported range.');
            }
            $width = $height = $size;
        } else {
            $this->assertDimension($element['w'] ?? null, 'element width');
            $this->assertDimension($element['h'] ?? null, 'element height');
            $width = $element['w'];
            $height = $element['h'];
        }
        if ($element['x'] + $width > $bounds['width'] || $element['y'] + $height > $bounds['height']) {
            throw new InvalidArgumentException('Element dimensions must fit inside the defined page.');
        }

        if (isset($element['z_index']) && (!is_int($element['z_index']) || $element['z_index'] < -1000 || $element['z_index'] > 1000)) {
            throw new InvalidArgumentException('Element z-index is outside the supported range.');
        }
        if (isset($element['value']) && (!is_string($element['value']) || strlen($element['value']) > 5000)) {
            throw new InvalidArgumentException('Static text must be plain text of at most 5000 bytes.');
        }
        if (isset($element['value']) && preg_match('/<\/?[A-Za-z!][^>]*>/', $element['value'])) {
            throw new InvalidArgumentException('Template text cannot contain HTML markup.');
        }
        if ($type === 'variable' && (!isset($element['key']) || !is_string($element['key']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $element['key']))) {
            throw new InvalidArgumentException('Variable elements must reference a valid variable key.');
        }
        if ($type === 'image' && (!isset($element['asset_id']) || !is_int($element['asset_id']) || $element['asset_id'] < 1)) {
            throw new InvalidArgumentException('Image elements must reference a valid managed asset ID.');
        }
        if (isset($element['font_size']) && (!$this->isNumber($element['font_size']) || $element['font_size'] < 1 || $element['font_size'] > 256)) {
            throw new InvalidArgumentException('Font size is outside the supported range.');
        }
        if (isset($element['align']) && !in_array($element['align'], ['left', 'center', 'right'], true)) {
            throw new InvalidArgumentException('Text alignment is unsupported.');
        }
        if (isset($element['bold']) && !is_bool($element['bold'])) {
            throw new InvalidArgumentException('Element bold setting must be boolean.');
        }
        if (isset($element['italic']) && !is_bool($element['italic'])) {
            throw new InvalidArgumentException('Element italic setting must be boolean.');
        }
        foreach (['color', 'fill_color'] as $colorKey) {
            if (isset($element[$colorKey])) {
                $this->assertColor($element[$colorKey], $colorKey);
            }
        }
        if (isset($element['font_family'])) {
            if (!is_string($element['font_family'])) {
                throw new InvalidArgumentException('Font family is invalid.');
            }
            (new FontManager())->resolve($element['font_family']);
        }
        if (isset($element['fit']) && !in_array($element['fit'], ['contain', 'cover', 'fill'], true)) {
            throw new InvalidArgumentException('Image fit mode is unsupported.');
        }
        if (isset($element['alt']) && (!is_string($element['alt']) || strlen($element['alt']) > 255)) {
            throw new InvalidArgumentException('Image alternative text is invalid.');
        }
        if (isset($element['shape']) && !in_array($element['shape'], ['rectangle', 'ellipse'], true)) {
            throw new InvalidArgumentException('Shape kind is unsupported.');
        }
        if (isset($element['stroke_width']) && (!$this->isNumber($element['stroke_width']) || $element['stroke_width'] < 0 || $element['stroke_width'] > 100)) {
            throw new InvalidArgumentException('Stroke width is outside the supported range.');
        }
    }

    private function assertAllowedKeys(array $data, array $allowed, string $label): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unsupported ' . $label . ' property: ' . (string)reset($unknown) . '.');
        }
    }

    private function assertCoordinate(mixed $value, string $label): void
    {
        if (!$this->isNumber($value) || $value < 0 || $value > self::MAX_COORDINATE) {
            throw new InvalidArgumentException('Element ' . $label . ' is outside the supported range.');
        }
    }

    private function assertDimension(mixed $value, string $label): void
    {
        if (!$this->isNumber($value) || $value <= 0 || $value > self::MAX_COORDINATE) {
            throw new InvalidArgumentException(ucfirst($label) . ' is outside the supported range.');
        }
    }

    private function assertColor(mixed $value, string $label): void
    {
        if (!is_string($value) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            throw new InvalidArgumentException('Color ' . $label . ' must use #RRGGBB format.');
        }
    }

    private function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }

    private function pageBounds(array $page): array
    {
        $orientation = $page['orientation'];
        if ($page['size'] === 'A4') {
            $width = 842.0;
            $height = 595.0;
        } elseif ($page['size'] === 'Letter') {
            $width = 792.0;
            $height = 612.0;
        } else {
            $width = (float)$page['width'];
            $height = (float)$page['height'];
            $unit = $page['unit'];
            if ($unit === 'mm') {
                $width *= 72 / 25.4;
                $height *= 72 / 25.4;
            } elseif ($unit === 'in') {
                $width *= 72;
                $height *= 72;
            }
        }

        if ($orientation === 'portrait' && $width > $height) {
            [$width, $height] = [$height, $width];
        } elseif ($orientation === 'landscape' && $height > $width) {
            [$width, $height] = [$height, $width];
        }
        return ['width' => $width, 'height' => $height];
    }
}
