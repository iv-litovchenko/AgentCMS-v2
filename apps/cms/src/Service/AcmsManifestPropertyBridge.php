<?php

namespace App\Service;

/**
 * Ключи frontmatter manifest: канон acms-*, legacy awn-*.
 */
class AcmsManifestPropertyBridge
{
    /**
     * @param array<string, mixed> $properties
     */
    public function stringProperty(array $properties, string $acmsKey): string
    {
        $acmsKey = trim($acmsKey);
        if ($acmsKey === '') {
            return '';
        }

        $value = $this->rawString($properties, $acmsKey);
        if ($value !== '') {
            return $value;
        }

        $legacy = $this->legacyKeyForAcms($acmsKey);
        if ($legacy === '') {
            return '';
        }

        return $this->rawString($properties, $legacy);
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function intProperty(array $properties, string $acmsKey): int
    {
        $acmsKey = trim($acmsKey);
        if ($acmsKey === '') {
            return 0;
        }

        if (array_key_exists($acmsKey, $properties)) {
            return $this->normalizeInt($properties[$acmsKey]);
        }

        $legacy = $this->legacyKeyForAcms($acmsKey);
        if ($legacy !== '' && array_key_exists($legacy, $properties)) {
            return $this->normalizeInt($properties[$legacy]);
        }

        return 0;
    }

    public function acmsKeyFromAny(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (str_starts_with($key, 'acms-')) {
            return $key;
        }
        if (str_starts_with($key, 'awn-')) {
            return 'acms-' . substr($key, strlen('awn-'));
        }

        return $key;
    }

    public function isLegacyAwnKey(string $key): bool
    {
        return str_starts_with(trim($key), 'awn-');
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function hasCanonicalKey(array $properties, string $legacyAwnKey): bool
    {
        $acmsKey = $this->acmsKeyFromAny($legacyAwnKey);
        if ($acmsKey === '' || $acmsKey === $legacyAwnKey) {
            return false;
        }

        return array_key_exists($acmsKey, $properties);
    }

    public function legacyKeyForAcms(string $acmsKey): string
    {
        if (!str_starts_with($acmsKey, 'acms-')) {
            return '';
        }

        return 'awn-' . substr($acmsKey, strlen('acms-'));
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    public function stripLegacyAwnDuplicates(array $properties): array
    {
        $result = $properties;
        foreach (array_keys($properties) as $key) {
            if (!is_string($key) || !$this->isLegacyAwnKey($key)) {
                continue;
            }
            if ($this->hasCanonicalKey($result, $key)) {
                unset($result[$key]);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function rawString(array $properties, string $key): string
    {
        if (!isset($properties[$key]) || !is_string($properties[$key])) {
            return '';
        }

        return trim($properties[$key]);
    }

    private function normalizeInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }
}
