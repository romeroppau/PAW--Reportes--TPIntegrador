<?php

class ReporteControlador
{
    public static function sugerencias(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $hoy = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');

        $quePromocionar = MotorRecomendaciones::revisarFranjasConPocosTurnos();

        // Qué liquidar: los lotes que vencen pronto (migración 028: cada lote con su vencimiento), y los
        // lotes vencidos con stock, para retirarlos.
        $liquidarRes = array_map(
            [LotesPorVencer::class, 'sugerencia'],
            ReporteRepositorio::obtenerLotesPorVencer(ParametrosMotor::diasLiquidar())
        );
        $vencidos = array_map([LotesPorVencer::class, 'vencido'], ReporteRepositorio::obtenerLotesVencidos());

        Response::json([
            'fecha_referencia' => $hoy,
            'que_promocionar' => $quePromocionar,
            'que_liquidar' => $liquidarRes,
            'lotes_vencidos' => $vencidos,
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
