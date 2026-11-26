<?php

use PHPUnit\Framework\TestCase;

// "Qué liquidar" por lote (26/11, migración 028 de Inventario): la sugerencia y el aviso nombran el lote
// (L1, L2… desde la 029, con el número del fabricante si tiene).
class LotesPorVencerTest extends TestCase
{
    private const LOTE = [
        'producto_id' => '107', 'nombre' => 'Pipeta Frontline Plus para gatos', 'precio' => '6500.00',
        'lote_id' => '12', 'numero' => '1', 'codigo' => 'FL-2612', 'stock' => '8', 'fecha_vencimiento' => '2026-12-16', 'dias_restantes' => '20',
    ];

    public function testLaSugerenciaEsDelLote(): void
    {
        $this->assertSame([
            'producto_id' => 107,
            'nombre' => 'Pipeta Frontline Plus para gatos',
            'lote_id' => 12,
            'lote' => 'L1 (FL-2612)',
            'fecha_vencimiento' => '2026-12-16',
            'dias_restantes' => 20,
            'stock' => 8,
            'precio' => 6500.0,
            'descuento_sugerido' => 15,
            'precio_sugerido' => 5525.0,
            'sugerencia' => 'Pipeta Frontline Plus para gatos, lote L1 (FL-2612): vence el 16/12/2026, quedan 8. Descuento sugerido: 15%',
        ], LotesPorVencer::sugerencia(self::LOTE));
    }

    public function testConMenosDeDiezDiasElDescuentoEsMayorYSinNumeroLoDice(): void
    {
        $lote = ['dias_restantes' => '9', 'numero' => '3', 'codigo' => null] + self::LOTE;
        $sugerencia = LotesPorVencer::sugerencia($lote);

        $this->assertSame(30, $sugerencia['descuento_sugerido']);
        $this->assertSame('Pipeta Frontline Plus para gatos, lote L3: vence el 16/12/2026, quedan 8. Descuento sugerido: 30%', $sugerencia['sugerencia']);
    }

    public function testUnLoteVencidoSeAvisaParaRetirarlo(): void
    {
        $lote = ['tipo' => 'producto', 'fecha_vencimiento' => '2026-11-20', 'stock' => '3', 'numero' => '2', 'codigo' => null] + self::LOTE;

        $this->assertSame(
            'Pipeta Frontline Plus para gatos, lote L2: venció el 20/11/2026 y quedan 3. Ya no se vende: retiralo del stock.',
            LotesPorVencer::vencido($lote)['sugerencia']
        );
        $this->assertSame([107, 'L2'], [LotesPorVencer::vencido($lote)['producto_id'], LotesPorVencer::vencido($lote)['lote']]);
    }

    // Migración 029: los insumos también tienen lotes; uno vencido con stock se avisa para retirarlo.
    public function testUnLoteVencidoDeUnInsumoSeAvisaConSuId(): void
    {
        $lote = ['tipo' => 'insumo', 'insumo_id' => '104', 'producto_id' => null, 'nombre' => 'Vacuna séxtuple canina (dosis)',
            'lote_id' => '7', 'numero' => '1', 'codigo' => null, 'stock' => '2', 'fecha_vencimiento' => '2026-11-20'];
        $vencido = LotesPorVencer::vencido($lote);

        $this->assertSame(['insumo', 104, 'L1'], [$vencido['tipo'], $vencido['insumo_id'], $vencido['lote']]);
        $this->assertArrayNotHasKey('producto_id', $vencido);
        $this->assertSame(
            'Vacuna séxtuple canina (dosis), lote L1: venció el 20/11/2026 y quedan 2. Ya no se usa en las consultas: retiralo del stock.',
            $vencido['sugerencia']
        );
    }
}
