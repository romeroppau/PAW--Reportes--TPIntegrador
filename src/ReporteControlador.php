<?php

class ReporteControlador
{
    public static function sugerencias(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $hoy = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');

        $quePromocionar = MotorRecomendaciones::revisarFranjasConPocosTurnos();

        // Productos a liquidar
        $prodsVencer = ReporteRepositorio::obtenerProductosPorVencer(ParametrosMotor::diasLiquidar());
        $liquidarRes = [];
        foreach ($prodsVencer as $p) {
            $dto = (int)$p['dias_restantes'] < 10 ? 30 : 15;
            $precioSugerido = round($p['precio'] * (1 - $dto / 100), 2);
            $liquidarRes[] = [
                'producto_id' => (int)$p['id'],
                'nombre' => $p['nombre'],
                'fecha_vencimiento' => $p['fecha_vencimiento'],
                'dias_restantes' => (int)$p['dias_restantes'],
                'stock' => (int)$p['stock'],
                'precio' => (float)$p['precio'],
                'descuento_sugerido' => $dto,
                'precio_sugerido' => $precioSugerido,
                'sugerencia' => "{$p['nombre']}: vence el " . date('d/m/Y', strtotime($p['fecha_vencimiento'])) . ", quedan {$p['stock']}. Descuento sugerido: {$dto}%"
            ];
        }

        Response::json([
            'fecha_referencia' => $hoy,
            'que_promocionar' => $quePromocionar,
            'que_liquidar' => $liquidarRes
        ]);
    }

    public static function turnosAtendidos(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $fechas = ValidadorReportes::fechas($_GET['desde'] ?? null, $_GET['hasta'] ?? null);
        if ($fechas === null) {
            Response::error('Parámetros de fecha inválidos', 400);
            return;
        }
        [$desde, $hasta] = $fechas;
        $turnos = ReporteRepositorio::obtenerTurnosAtendidos($desde, $hasta);
        $totalFacturado = array_sum(array_column($turnos, 'total'));

        Response::json([
            'filtros' => ['desde' => $desde, 'hasta' => $hasta],
            'resumen' => ['cantidad_turnos' => count($turnos), 'total_facturado' => (float)$totalFacturado],
            'turnos' => $turnos
        ]);
    }

    public static function stock(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $fechas = ValidadorReportes::fechas($_GET['desde'] ?? null, $_GET['hasta'] ?? null);
        if ($fechas === null) {
            Response::error('Parámetros de fecha inválidos', 400);
            return;
        }
        [$desde, $hasta] = $fechas;
        $datos = ReporteRepositorio::obtenerStockYMovimientos($desde, $hasta);

        // Stock bajo: el límite que configuró la veterinaria (por defecto, 10 unidades o menos).
        $limite = ParametrosMotor::limiteStockBajo();
        $prodBajo = count(array_filter($datos['productos'], fn($p) => $p['stock'] <= $limite));
        $insBajo = count(array_filter($datos['insumos'], fn($i) => $i['stock'] <= $limite));

        Response::json([
            'resumen' => ['productos_stock_bajo' => $prodBajo, 'insumos_stock_bajo' => $insBajo, 'limite_stock_bajo' => $limite],
            'productos' => $datos['productos'],
            'insumos' => $datos['insumos'],
            'movimientos' => $datos['movimientos']
        ]);
    }

    public static function tareaDiaria(): void
    {
        $tokenEsperado = Config::get('TAREAS_TOKEN') ?: '';
        $tokenEnviado = $_SERVER['HTTP_X_TAREA_TOKEN'] ?? '';

        if ($tokenEsperado === '' || !is_string($tokenEnviado) || !hash_equals($tokenEsperado, $tokenEnviado)) {
            Response::error('No autorizado', 401);
            return;
        }

        $resultado = MotorRecomendaciones::ejecutarDiario();
        Response::json($resultado);
    }
}
