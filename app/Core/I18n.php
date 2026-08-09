<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

/**
 * Array-file based translations (lang/de.php etc.) — gettext is unreliable on
 * shared hosting. German is the default locale; unknown keys fall back to
 * German, then to the key itself (visible, greppable).
 */
final class I18n
{
    public const DEFAULT_LOCALE = 'de';
    public const SUPPORTED = ['de', 'en', 'fr', 'it'];

    private static string $locale = self::DEFAULT_LOCALE;
    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];
    private static string $langDir = '';

    public static function init(string $langDir, string $locale = self::DEFAULT_LOCALE): void
    {
        self::$langDir = $langDir;
        self::$catalogs = [];
        self::setLocale($locale);
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string, string|int|float> $params */
    public static function translate(string $key, array $params = []): string
    {
        $text = self::catalog(self::$locale)[$key]
            ?? self::catalog(self::DEFAULT_LOCALE)[$key]
            ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (!isset(self::$catalogs[$locale])) {
            $file = self::$langDir . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            self::$catalogs[$locale] = is_array($data) ? $data : [];
        }

        return self::$catalogs[$locale];
    }
}
