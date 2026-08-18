<?php

declare(strict_types=1);

namespace Kanon\Support;

/**
 * Diacritics-insensitive normalization.
 *
 * An explicit character map is used rather than intl's Transliterator so that
 * the output cannot change when the container's ICU version changes. Match keys
 * built by the importer must stay byte-identical across re-imports.
 */
final class Normalize
{
    private const MAP = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ô' => 'o',
        'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ł' => 'l', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ą' => 'a',
        'ę' => 'e', 'ğ' => 'g', 'ı' => 'i', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    public static function text(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::MAP);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    public static function key(string $value): string
    {
        $value = self::text($value);
        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);

        return trim($value, '-');
    }
}
