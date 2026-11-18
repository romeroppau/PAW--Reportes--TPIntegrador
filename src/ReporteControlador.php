<?php

class ReporteControlador
{
    public static function sugerencias(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $hoy = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');

        // Franjas turnos (últimas 4 semanas)
        $turnos = ReporteRepositorio::obtenerTurnosUltimasCuatroSemanas();
        $franjasConteo = [];
        foreach ($turnos as $t) {
            $timestamp = strtotime($t['fecha']);
            $diaSemanaNum = (int)date('N', $timestamp); // 1=Lunes .. 7=Domingo
            if ($diaSemanaNum > 6) continue; // solo lunes a sábado
            $diasNombres = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];
            $diaNombre = $diasNombres[$diaSemanaNum];
            $hora = (int)substr($t['hora'], 0, 2);
            $franja = $hora < 13 ? 'mañana' : 'tarde';
            $clave = "$diaNombre|$franja";
            $franjasConteo[$clave] = ($franjasConteo[$clave] ?? 0) + 1;
        }

        // Encontrar menor ocupación
        $menorCant = 999999;
        $mejorClave = ['dia_semana' => 'Lunes', 'franja' => 'mañana', 'cantidad' => 0];
        $listaFranjas = [];
        foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] as $d) {
            foreach (['mañana', 'tarde'] as $f) {
                $cant = $franjasConteo["$d|$f"] ?? 0;
                $listaFranjas[] = ['dia_semana' => $d, 'franja' => $f, 'cantidad_turnos' => $cant];
                if ($cant < $menorCant) {
                    $menorCant = $cant;
                    $mejorClave = ['dia_semana' => $d, 'franja' => $f, 'cantidad_turnos' => $cant];
                }
            }
        }

        $sugerenciaFranja = "Los {$mejorClave['dia_semana']} a la {$mejorClave['franja']} hubo {$mejorClave['cantidad_turnos']} turnos en 4 semanas: conviene promocionarlos";

        // Productos a liquidar
        $prodsVencer = ReporteRepositorio::obtenerProductosPorVencer();
        $liquidarRes = [];
        foreach ($prodsVencer as $p) {
            $dto = $p['dias_restantes'] < 10 ? 30 : 15;
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
            'que_promocionar' => [
                'franja_sugerida' => [
                    'dia_semana' => $mejorClave['dia_semana'],
                    'franja' => $mejorClave['franja'],
                    'cantidad_turnos' => $mejorClave['cantidad_turnos'],
                    'semanas_analizadas' => 4,
                    'sugerencia' => $sugerenciaFranja
                ],
                'franjas' => $listaFranjas
            ],
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

        $prodBajo = count(array_filter($datos['productos'], fn($p) => $p['stock'] <= 10));
        $insBajo = count(array_filter($datos['insumos'], fn($i) => $i['stock'] <= 10));

        Response::json([
            'resumen' => ['productos_stock_bajo' => $prodBajo, 'insumos_stock_bajo' => $insBajo],
            'productos' => $datos['productos'],
            'insumos' => $datos['insumos'],
            'movimientos' => $datos['movimientos']
        ]);
    }

    public static function tareaDiaria(): void
    {
        $tokenEsperado = Config::get('TAREAS_TOKEN') ?: '';
        $tokenEnviado = $_SERVER['HTTP_X_TAREA_TOKEN'] ?? '';

        if (!is_string($tokenEnviado) || !hash_equals($tokenEsperado, $tokenEnviado)) {
            Response::error('No autorizado', 401);
            return;
        }

        $resultado = MotorRecomendaciones::ejecutarDiario();
        Response::json($resultado);
    }
}
