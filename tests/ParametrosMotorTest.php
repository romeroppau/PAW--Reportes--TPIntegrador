<?php

use PHPUnit\Framework\TestCase;

// Los parámetros del motor salen de la configuración de la veterinaria; si no hay nada guardado, o
// lo guardado no sirve, son los de siempre. Las consultas contra la base las prueba el Gateway.
final class ParametrosMotorTest extends TestCase
{
    protected function tearDown(): void
    {
        Configuracion::reiniciar();
    }

    public function testSinConfiguracionSonLosDeSiempre(): void
    {
        Configuracion::reiniciar([]);

        $this->assertSame(7, ParametrosMotor::diasAvisoVacuna());
        $this->assertSame([30, 90], ParametrosMotor::controlesAdopcion());
        $this->assertSame(10, ParametrosMotor::limiteStockBajo());
        $this->assertSame(30, ParametrosMotor::diasLiquidar());
    }

    public function testUsaLoQueConfiguroLaVeterinaria(): void
    {
        Configuracion::reiniciar([
            'dias_aviso_vacuna' => '5', 'dias_control_adopcion_1' => '15', 'dias_control_adopcion_2' => '60',
            'limite_stock_bajo' => '3', 'dias_liquidar' => '45',
        ]);

        $this->assertSame(5, ParametrosMotor::diasAvisoVacuna());
        $this->assertSame([15, 60], ParametrosMotor::controlesAdopcion());
        $this->assertSame(3, ParametrosMotor::limiteStockBajo());
        $this->assertSame(45, ParametrosMotor::diasLiquidar());
    }

    public function testUnValorQueNoSirveSeReemplazaPorElDeSiempre(): void
    {
        Configuracion::reiniciar(['dias_aviso_vacuna' => '0', 'limite_stock_bajo' => 'diez', 'dias_liquidar' => '-3']);

        $this->assertSame(7, ParametrosMotor::diasAvisoVacuna());
        $this->assertSame(10, ParametrosMotor::limiteStockBajo());
        $this->assertSame(30, ParametrosMotor::diasLiquidar());
    }

    public function testSiElSegundoControlNoEsDespuesDelPrimeroSonLosDeSiempre(): void
    {
        Configuracion::reiniciar(['dias_control_adopcion_1' => '100', 'dias_control_adopcion_2' => '60']);

        $this->assertSame([30, 90], ParametrosMotor::controlesAdopcion());
    }
}
