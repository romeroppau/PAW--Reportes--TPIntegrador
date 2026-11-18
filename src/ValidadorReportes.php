<?php

class ValidadorReportes
{
    public static function fechas(?string $desde, ?string $hasta): ?array
    {
        $d = $desde ?: null;
        $h = $hasta ?: null;

        if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        if ($h !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)) return null;

        if ($d !== null && $h !== null && $d > $h) return null;

        return [$d, $h];
    }
}
