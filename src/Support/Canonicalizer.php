<?php

declare(strict_types=1);

namespace Tarrou\Support;

final class Canonicalizer
{
    public static function encode(array $value): string
    {
        return json_encode(self::sortRecursive($value), JSON_THROW_ON_ERROR);
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            return array_map(static fn ($item) => self::sortRecursive($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $entry) {
            $value[$key] = self::sortRecursive($entry);
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
