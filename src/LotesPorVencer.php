<?php

// "Qué liquidar" por lote (migración 028 de Inventario: cada producto tiene lotes, cada uno con su
// vencimiento). Arma la sugerencia de cada lote que vence pronto y el aviso de cada lote vencido con
// stock. La usan el reporte (ReporteControlador::sugerencias) y el motor diario
// (MotorRecomendaciones::revisarVencimientos), así dicen lo mismo. No usa la base.
class LotesPorVencer
{
    // El descuento sugerido: 30 % si faltan menos de 10 días, 15 % si no.
    public static function descuento(int $diasRestantes): int
    {
        return $diasRestantes < 10 ? 30 : 15;
    }

    // Cómo se nombra el lote en los textos: "lote L-0425" o, si no tiene número, "lote sin número".
    public static function nombre(?string $codigo): string
    {
        return $codigo !== null && $codigo !== '' ? "lote $codigo" : 'lote sin número';
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
            'lote' => $fila['codigo'],
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

    // "Pipeta Frontline Plus para gatos, lote L-0425: vence el 16/12/2026, quedan 18. Descuento sugerido: 15%"
    public static function texto(array $fila, int $dto): string
    {
        return "{$fila['nombre']}, " . self::nombre($fila['codigo']) . ': vence el ' . MotorRecomendaciones::fecha($fila['fecha_vencimiento'])
            . ", quedan {$fila['stock']}. Descuento sugerido: {$dto}%";
    }

    // Una fila de ReporteRepositorio::obtenerLotesVencidos() al formato de "lotes_vencidos".
    public static function vencido(array $fila): array
    {
        return [
            'producto_id' => (int) $fila['producto_id'],
            'nombre' => $fila['nombre'],
            'lote_id' => (int) $fila['lote_id'],
            'lote' => $fila['codigo'],
            'fecha_vencimiento' => $fila['fecha_vencimiento'],
            'stock' => (int) $fila['stock'],
            'sugerencia' => self::textoVencido($fila),
        ];
    }

    // "Pipeta Frontline Plus para gatos, lote L-0311: venció el 20/11/2026 y quedan 3. Ya no se vende: retiralo del stock."
    public static function textoVencido(array $fila): string
    {
        return "{$fila['nombre']}, " . self::nombre($fila['codigo']) . ': venció el ' . MotorRecomendaciones::fecha($fila['fecha_vencimiento'])
            . " y quedan {$fila['stock']}. Ya no se vende: retiralo del stock.";
    }
}
