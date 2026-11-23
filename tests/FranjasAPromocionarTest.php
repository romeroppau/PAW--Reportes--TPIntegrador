<?php

use PHPUnit\Framework\TestCase;

// Pruebas de las franjas que el motor sugiere promocionar (MotorRecomendaciones::franjasAPromocionar):
// las 5 con menos turnos, con su descuento sugerido. No usan la base de datos.
class FranjasAPromocionarTest extends TestCase
{
    // Las 12 franjas (lunes a sábado, mañana y tarde) con estos turnos, en el orden del motor.
    private function franjas(array $turnos): array
    {
        $lista = [];
        $i = 0;
        foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] as $d) {
            foreach (['mañana', 'tarde'] as $f) {
                $lista[] = ['dia_semana' => $d, 'franja' => $f, 'cantidad_turnos' => $turnos[$i++]];
            }
        }
        return $lista;
    }

    public function testSonLasCincoConMenosTurnosConSuDescuento(): void
    {
        //                 Lun     Mar     Mié     Jue     Vie     Sáb
        $lista = $this->franjas([9, 4, 8, 0, 7, 2, 6, 3, 9, 9, 1, 5]);

        $sugeridas = MotorRecomendaciones::franjasAPromocionar($lista, 15000);

        $this->assertCount(5, $sugeridas);
        $this->assertSame(
            [['Martes', 'tarde', 0, 25], ['Sábado', 'mañana', 1, 15], ['Miércoles', 'tarde', 2, 15], ['Jueves', 'tarde', 3, 10], ['Lunes', 'tarde', 4, 10]],
            array_map(fn ($f) => [$f['dia_semana'], $f['franja'], $f['cantidad_turnos'], $f['descuento_sugerido']], $sugeridas)
        );
        $this->assertSame(['dia_numero' => 2, 'franja_codigo' => 'TARDE', 'precio_sugerido' => 11250.0],
            array_intersect_key($sugeridas[0], ['dia_numero' => 1, 'franja_codigo' => 1, 'precio_sugerido' => 1]));
        $this->assertSame('MANIANA', $sugeridas[1]['franja_codigo']);
        $this->assertSame(6, $sugeridas[1]['dia_numero']);
    }

    public function testSiEmpatanVaPrimeroLaDeAntesEnLaSemana(): void
    {
        $sugeridas = MotorRecomendaciones::franjasAPromocionar($this->franjas(array_fill(0, 12, 0)), 10000);

        $this->assertSame(
            ['Lunes mañana', 'Lunes tarde', 'Martes mañana', 'Martes tarde', 'Miércoles mañana'],
            array_map(fn ($f) => "{$f['dia_semana']} {$f['franja']}", $sugeridas)
        );
        $this->assertSame(7500.0, $sugeridas[0]['precio_sugerido']);
    }
}
