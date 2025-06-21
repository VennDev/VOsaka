<?php

declare(strict_types=1);

namespace venndev\vosaka;

final class MemoryUtils
{
    public static function parseIniMemory(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}