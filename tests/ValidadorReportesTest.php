<?php

use PHPUnit\Framework\TestCase;

final class ValidadorReportesTest extends TestCase
{
    public function testFechasValidas(): void
    {
        $this->assertSame(['2026-10-01', '2026-11-18'], ValidadorReportes::fechas('2026-10-01', '2026-11-18'));
        $this->assertSame([null, null], ValidadorReportes::fechas(null, null));
    }

    public function testFechasInvalidasPorOrdenOFormato(): void
    {
        $this->assertNull(ValidadorReportes::fechas('2026-11-18', '2026-10-01'));
        $this->assertNull(ValidadorReportes::fechas('18-11-2026', null));
    }
}
