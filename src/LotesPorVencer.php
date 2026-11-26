<?php

// "Qué liquidar" por lote (migración 028 de Inventario: cada producto tiene lotes, cada uno con su
// vencimiento). Arma la sugerencia de cada lote que vence pronto y el aviso de cada lote vencido con
// stock (de un producto o, desde la migración 029, de un insumo). La usan el reporte
// (ReporteControlador::sugerencias) y el motor diario (MotorRecomendaciones::revisarVencimientos), así
// dicen lo mismo. No usa la base.
// Cada lote se nombra con su número de Inventario (L1, L2, L3…, 029) y, si tiene, el del fabricante.
class LotesPorVencer
{
    // El descuento sugerido: 30 % si faltan menos de 10 días, 15 % si no.
    public static function descuento(int $diasRestantes): int
    {
        return $diasRestantes < 10 ? 30 : 15;
    }

    // El lote: "L3" o, con el número del fabricante, "L3 (FL-2612)" (igual que Inventario, Lotes::nombre).
    // Sin número (un lote que creó el Inventario anterior a la 029): el del fabricante o "sin número".
    public static function nombre(?int $numero, ?string $codigo): string
    {
        $fabricante = $codigo !== null && $codigo !== '' ? $codigo : null;
        if ($numero === null) {
            return $fabricante ?? 'sin número';
        }

        return 'L' . $numero . ($fabricante !== null ? " ($fabricante)" : '');
    }

    // El número de lote de una fila (null si no tiene).
    private static function numero(array $fila): ?int
    {
        return isset($fila['numero']) ? (int) $fila['numero'] : null;
    }

    // El lote de una fila, como se nombra en los textos.
    private static function lote(array $fila): string
    {
        return 'lote ' . self::nombre(self::numero($fila), $fila['codigo']);
    }

    // Una fila de ReporteRepositorio::obtenerLotesPorVencer() al formato de "que_liquidar".
    public static function sugerencia(array $fila): array
    {
        $dias = (int) $fila['dias_restantes'];
        $dto = self::descuento($dias);
        $precio = (float) $fila['precio'];

        return [
            'producto_id' => (int) $fila['producto_id'],
            'nombre' => $fila['nombre'],
            'lote_id' => (int) $fila['lote_id'],
            'lote' => self::nombre(self::numero($fila), $fila['codigo']),
            'fecha_vencimiento' => $fila['fecha_vencimiento'],
            'dias_restantes' => $dias,
            // Las unidades de ese lote (no las de todo el producto).
            'stock' => (int) $fila['stock'],
            'precio' => $precio,
            'descuento_sugerido' => $dto,
            'precio_sugerido' => round($precio * (1 - $dto / 100), 2),
            'sugerencia' => self::texto($fila, $dto),
        ];
    }

    // "Pipeta Frontline Plus para gatos, lote L1 (FL-2612): vence el 16/12/2026, quedan 18. Descuento sugerido: 15%"
    public static function texto(array $fila, int $dto): string
    {
        return "{$fila['nombre']}, " . self::lote($fila) . ': vence el ' . MotorRecomendaciones::fecha($fila['fecha_vencimiento'])
            . ", quedan {$fila['stock']}. Descuento sugerido: {$dto}%";
    }

    // Una fila de ReporteRepositorio::obtenerLotesVencidos() al formato de "lotes_vencidos": de un
    // producto (producto_id) o de un insumo (insumo_id, 029). tipo: "producto" o "insumo".
    public static function vencido(array $fila): array
    {
        $tipo = $fila['tipo'] ?? 'producto';

        return [
            'tipo' => $tipo,
            $tipo . '_id' => (int) $fila[$tipo === 'insumo' ? 'insumo_id' : 'producto_id'],
            'nombre' => $fila['nombre'],
            'lote_id' => (int) $fila['lote_id'],
            'lote' => self::nombre(self::numero($fila), $fila['codigo']),
            'fecha_vencimiento' => $fila['fecha_vencimiento'],
            'stock' => (int) $fila['stock'],
            'sugerencia' => self::textoVencido($fila),
        ];
    }

    // "Pipeta Frontline Plus para gatos, lote L2: venció el 20/11/2026 y quedan 3. Ya no se vende: retiralo del stock."
    // De un insumo: "… Ya no se usa en las consultas: retiralo del stock."
    public static function textoVencido(array $fila): string
    {
        $uso = ($fila['tipo'] ?? 'producto') === 'insumo' ? 'Ya no se usa en las consultas' : 'Ya no se vende';

        return "{$fila['nombre']}, " . self::lote($fila) . ': venció el ' . MotorRecomendaciones::fecha($fila['fecha_vencimiento'])
            . " y quedan {$fila['stock']}. $uso: retiralo del stock.";
    }
}
