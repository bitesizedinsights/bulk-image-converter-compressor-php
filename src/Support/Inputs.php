<?php

declare(strict_types=1);

namespace ImageConverter\Support;

/**
 * Mirrors how the Actor normalizes and deduplicates its URLs and upload keys.
 *
 * @internal
 */
final class Inputs
{
    /**
     * Normalized values keyed by the original key; empty and repeated values are dropped (first occurrence wins).
     *
     * @param  array<int|string, string>  $inputs
     * @return ($inputs is array<int, string> ? array<int, string> : array<int|string, string>)
     */
    public static function unique(array $inputs, bool $isUrl): array
    {
        $result = [];
        $seen = [];

        foreach ($inputs as $key => $raw) {
            $value = self::normalize($raw, $isUrl);

            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $result[$key] = $value;
        }

        return $result;
    }

    /** Control characters and surrounding whitespace (Unicode spaces too) stripped; a bare URL gets `https://`. */
    public static function normalize(string $raw, bool $isUrl): string
    {
        $pattern = '/[\x00-\x1f\x7f]|[\x{80}-\x{9f}\x{200b}-\x{200f}\x{2028}-\x{202e}\x{2060}-\x{2069}\x{feff}]/u';
        $value = preg_replace($pattern, '', $raw) ?? $raw;
        $value = trim(preg_replace('/^\p{Zs}+|\p{Zs}+$/u', '', $value) ?? $value);

        if ($isUrl && $value !== '' && preg_match('#^https?://#i', $value) !== 1) {
            $value = 'https://'.$value;
        }

        return $value;
    }
}
