<?php

use PHPUnit\Framework\TestCase;

// Las fechas de los avisos del motor (vacunas y productos por vencer) van en día/mes/año.
class FechaAvisosTest extends TestCase
{
    public function testLaFechaDeLaBaseQuedaEnDiaMesAnio(): void
    {
        $this->assertSame('24/11/2026', MotorRecomendaciones::fecha('2026-11-24'));
        $this->assertSame('05/01/2027', MotorRecomendaciones::fecha('2027-01-05 00:00:00'));
    }

    public function testSiNoEsUnaFechaQuedaComoVino(): void
    {
        $this->assertSame('mañana', MotorRecomendaciones::fecha('mañana'));
        $this->assertSame('', MotorRecomendaciones::fecha(null));
    }
}
