<?php

use PHPUnit\Framework\TestCase;

// "Qué liquidar" por lote (26/11, migración 028 de Inventario): la sugerencia y el aviso nombran el lote.
class LotesPorVencerTest extends TestCase
{
    private const LOTE = [
        'producto_id' => '107', 'nombre' => 'Pipeta Frontline Plus para gatos', 'precio' => '6500.00',
        'lote_id' => '12', 'codigo' => 'L-0425', 'stock' => '8', 'fecha_vencimiento' => '2026-12-16', 'dias_restantes' => '20',
    ];

    public function testLaSugerenciaEsDelLote(): void
    {
        $this->assertSame([
            'producto_id' => 107,
            'nombre' => 'Pipeta Frontline Plus para gatos',
            'lote_id' => 12,
            'lote' => 'L-0425',
            'fecha_vencimiento' => '2026-12-16',
            'dias_restantes' => 20,
            'stock' => 8,
            'precio' => 6500.0,
            'descuento_sugerido' => 15,
            'precio_sugerido' => 5525.0,
            'sugerencia' => 'Pipeta Frontline Plus para gatos, lote L-0425: vence el 16/12/2026, quedan 8. Descuento sugerido: 15%',
        ], LotesPorVencer::sugerencia(self::LOTE));
    }

    public function testConMenosDeDiezDiasElDescuentoEsMayorYSinNumeroLoDice(): void
    {
        $lote = ['dias_restantes' => '9', 'codigo' => null] + self::LOTE;
        $sugerencia = LotesPorVencer::sugerencia($lote);

        $this->assertSame(30, $sugerencia['descuento_sugerido']);
        $this->assertSame('Pipeta Frontline Plus para gatos, lote sin número: vence el 16/12/2026, quedan 8. Descuento sugerido: 30%', $sugerencia['sugerencia']);
    }

    public function testUnLoteVencidoSeAvisaParaRetirarlo(): void
    {
        $lote = ['fecha_vencimiento' => '2026-11-20', 'stock' => '3', 'codigo' => 'L-0311'] + self::LOTE;

        $this->assertSame(
            'Pipeta Frontline Plus para gatos, lote L-0311: venció el 20/11/2026 y quedan 3. Ya no se vende: retiralo del stock.',
            LotesPorVencer::vencido($lote)['sugerencia']
        );
    }
}
