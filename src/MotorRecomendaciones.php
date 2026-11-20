<?php

class MotorRecomendaciones
{
    private const VETERINARIO_ID = 101;

    public static function ejecutarDiario(): array
    {
        $zona = new DateTimeZone('America/Argentina/Buenos_Aires');
        $ahoraDt = new DateTimeImmutable('now', $zona);
        $hoy = $ahoraDt->format('Y-m-d');
        $ahora = $ahoraDt->format('Y-m-d H:i:s');
        $semanaIso = $ahoraDt->format('o-W');

        $conteo = [
            'generados' => 0,
            'aplicados' => 0,
            'repetidos' => 0,
            'fallidos' => 0,
            'detalle' => [
                'vacuna_proxima' => 0,
                'vacuna_vencida' => 0,
                'control_adopcion' => 0,
                'stock_bajo' => 0,
                'liquidar' => 0,
            ],
        ];

        self::recordarCalendarioSanitario($hoy, $ahora, $semanaIso, $conteo);
        self::revisarControlesAdopcion($hoy, $ahora, $conteo);
        self::revisarStockBajo($hoy, $ahora, $conteo);
        self::revisarVencimientos($ahora, $semanaIso, $conteo);

        return [
            'fecha' => $hoy,
            'generados' => $conteo['generados'],
            'aplicados' => $conteo['aplicados'],
            'repetidos' => $conteo['repetidos'],
            'fallidos' => $conteo['fallidos'],
            'detalle' => $conteo['detalle'],
        ];
    }

    // Reglas 1 y 2: Vacuna próxima (dentro de N días) y Vacuna vencida (una vez por semana ISO).
    public static function recordarCalendarioSanitario(string $hoy, string $ahora, string $semanaIso, array &$conteo): void
    {
        foreach (ReporteRepositorio::obtenerVacunasProximas(ParametrosMotor::diasAvisoVacuna()) as $vacuna) {
            $mensaje = "A {$vacuna['mascota_nombre']} le toca la vacuna {$vacuna['vacuna']} el {$vacuna['fecha_programada']}";
            $idBase = "vacuna-proxima-{$vacuna['evento_id']}";
            self::enviarPorCanales((int) $vacuna['usuario_id'], 'VACUNA', $mensaje, $ahora, $idBase, ['web' => 'WEB', 'wa' => 'WHATSAPP'], 'vacuna_proxima', $conteo);
        }

        foreach (ReporteRepositorio::obtenerVacunasVencidas() as $vacuna) {
            $mensaje = "La vacuna {$vacuna['vacuna']} de {$vacuna['mascota_nombre']} está vencida desde el {$vacuna['fecha_programada']}";
            $idBase = "vacuna-vencida-{$vacuna['evento_id']}-{$semanaIso}";
            self::enviarPorCanales((int) $vacuna['usuario_id'], 'VACUNA', $mensaje, $ahora, $idBase, ['web' => 'WEB', 'wa' => 'WHATSAPP'], 'vacuna_vencida', $conteo);
        }
    }

    // Regla 3: Control de adopción (a los días configurados; por defecto 30 y 90).
    public static function revisarControlesAdopcion(string $hoy, string $ahora, array &$conteo): void
    {
        [$primerControl, $segundoControl] = ParametrosMotor::controlesAdopcion();
        foreach (ReporteRepositorio::obtenerControlesAdopcion($hoy, $primerControl, $segundoControl) as $ctrl) {
            $tipoControl = (int) $ctrl['dias_control'];
            $mensaje = "Se cumplen {$tipoControl} días de la adopción de {$ctrl['mascota_nombre']}. Corresponde control veterinario.";
            $idBase = "control-{$ctrl['adopcion_id']}-{$tipoControl}";
            self::enviarPorCanales((int) $ctrl['adoptante_id'], 'CONTROL_ADOPCION', $mensaje, $ahora, $idBase, ['web' => 'WEB', 'wa' => 'WHATSAPP'], 'control_adopcion', $conteo);
        }
    }

    // Regla 4: Stock bajo (productos e insumos activos con stock <= limite_stock_bajo).
    public static function revisarStockBajo(string $hoy, string $ahora, array &$conteo): void
    {
        foreach (ReporteRepositorio::obtenerStockBajo(ParametrosMotor::limiteStockBajo()) as $item) {
            $mensaje = "Stock bajo para {$item['tipo']} '{$item['nombre']}' (quedan {$item['stock']} unidades).";
            $idOp = "stock-{$item['tipo']}-{$item['id']}-{$hoy}";
            self::enviarUnico(self::VETERINARIO_ID, 'STOCK_BAJO', 'WEB', $mensaje, $ahora, $idOp, 'stock_bajo', $conteo);
        }
    }

    // Regla 5 (aviso web) y cruce "Qué liquidar": productos activos con stock > 0 próximos a vencer.
    public static function revisarVencimientos(string $ahora, string $semanaIso, array &$conteo): void
    {
        foreach (ReporteRepositorio::obtenerProductosPorVencer(ParametrosMotor::diasLiquidar()) as $prod) {
            $dto = (int) $prod['dias_restantes'] < 10 ? 30 : 15;
            $mensaje = "{$prod['nombre']}: vence el {$prod['fecha_vencimiento']}, quedan {$prod['stock']}. Descuento sugerido: {$dto}%";
            $idOp = "liquidar-{$prod['id']}-{$semanaIso}";
            self::enviarUnico(self::VETERINARIO_ID, 'PROMOCION', 'WEB', $mensaje, $ahora, $idOp, 'liquidar', $conteo);
        }
    }

    // Cruce "Qué promocionar": franjas horarias con menor ocupación en las últimas 4 semanas.
    public static function revisarFranjasConPocosTurnos(): array
    {
        $turnos = ReporteRepositorio::obtenerTurnosUltimasCuatroSemanas();
        $franjasConteo = [];
        $diasNombres = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

        foreach ($turnos as $t) {
            $timestamp = strtotime((string) $t['fecha']);
            $diaSemanaNum = (int) date('N', $timestamp);
            if ($diaSemanaNum > 6) {
                continue;
            }
            $diaNombre = $diasNombres[$diaSemanaNum];
            $hora = (int) substr((string) $t['hora'], 0, 2);
            $franja = $hora < 13 ? 'mañana' : 'tarde';
            $clave = "$diaNombre|$franja";
            $franjasConteo[$clave] = ($franjasConteo[$clave] ?? 0) + 1;
        }

        $menorCant = PHP_INT_MAX;
        $mejorClave = ['dia_semana' => 'Lunes', 'franja' => 'mañana', 'cantidad_turnos' => 0];
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

        $sugerencia = "Los {$mejorClave['dia_semana']} a la {$mejorClave['franja']} hubo {$mejorClave['cantidad_turnos']} turnos en 4 semanas: conviene promocionarlos";

        return [
            'franja_sugerida' => [
                'dia_semana' => $mejorClave['dia_semana'],
                'franja' => $mejorClave['franja'],
                'cantidad_turnos' => $mejorClave['cantidad_turnos'],
                'semanas_analizadas' => 4,
                'sugerencia' => $sugerencia,
            ],
            'franjas' => $listaFranjas,
        ];
    }

    private static function enviarPorCanales(
        int $usuarioId,
        string $tipo,
        string $mensaje,
        string $fechaProgramada,
        string $idBase,
        array $canales,
        string $claveDetalle,
        array &$conteo
    ): void {
        $conteo['generados']++;
        $algunoAplicado = false;
        $algunoFallido = false;

        foreach ($canales as $sufijo => $canal) {
            $res = ClienteNotificaciones::enviar($usuarioId, $tipo, $canal, $mensaje, $fechaProgramada, "{$idBase}-{$sufijo}");
            if ($res['codigo'] !== 200 || !is_bool($res['datos']['aplicado'] ?? null)) {
                $algunoFallido = true;
            } elseif ($res['datos']['aplicado'] === true) {
                $algunoAplicado = true;
            }
        }

        if ($algunoFallido) {
            $conteo['fallidos']++;
        } elseif ($algunoAplicado) {
            $conteo['aplicados']++;
            $conteo['detalle'][$claveDetalle]++;
        } else {
            $conteo['repetidos']++;
        }
    }

    private static function enviarUnico(
        int $usuarioId,
        string $tipo,
        string $canal,
        string $mensaje,
        string $fechaProgramada,
        string $idOperacion,
        string $claveDetalle,
        array &$conteo
    ): void {
        $conteo['generados']++;
        $res = ClienteNotificaciones::enviar($usuarioId, $tipo, $canal, $mensaje, $fechaProgramada, $idOperacion);

        if ($res['codigo'] !== 200 || !is_bool($res['datos']['aplicado'] ?? null)) {
            $conteo['fallidos']++;
        } elseif ($res['datos']['aplicado'] === true) {
            $conteo['aplicados']++;
            $conteo['detalle'][$claveDetalle]++;
        } else {
            $conteo['repetidos']++;
        }
    }
}
