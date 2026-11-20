<?php

class MotorRecomendaciones
{
    public static function ejecutarDiario(): array
    {
        $hoy = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        $semanaIso = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('o-W');

        $generados = 0;
        $aplicados = 0;
        $repetidos = 0;
        $fallidos = 0;

        $detalle = [
            'vacuna_proxima' => 0,
            'vacuna_vencida' => 0,
            'control_adopcion' => 0,
            'stock_bajo' => 0,
            'liquidar' => 0
        ];

        // 1. Vacuna próxima
        foreach (ReporteRepositorio::obtenerVacunasProximas(ParametrosMotor::diasAvisoVacuna()) as $vacuna) {
            $generados++;
            $mensaje = "A {$vacuna['mascota_nombre']} le toca la vacuna {$vacuna['vacuna']} el {$vacuna['fecha_programada']}";
            $idOpWeb = "vacuna-proxima-{$vacuna['evento_id']}-web";
            $idOpWa = "vacuna-proxima-{$vacuna['evento_id']}-wa";

            $resWeb = ClienteNotificaciones::enviar($vacuna['usuario_id'], 'VACUNA', 'WEB', $mensaje, $vacuna['fecha_programada'], $idOpWeb);
            if (($resWeb['datos']['aplicado'] ?? false) === true) $aplicados++; else $repetidos++;

            $resWa = ClienteNotificaciones::enviar($vacuna['usuario_id'], 'VACUNA', 'WHATSAPP', $mensaje, $vacuna['fecha_programada'], $idOpWa);
            if (($resWa['datos']['aplicado'] ?? false) === true) { $aplicados++; $detalle['vacuna_proxima']++; } else $repetidos++;
        }

        // 2. Vacuna vencida
        foreach (ReporteRepositorio::obtenerVacunasVencidas() as $vacuna) {
            $generados++;
            $mensaje = "La vacuna {$vacuna['vacuna']} de {$vacuna['mascota_nombre']} está vencida desde el {$vacuna['fecha_programada']}";
            $idOpWeb = "vacuna-vencida-{$vacuna['evento_id']}-{$semanaIso}-web";
            $idOpWa = "vacuna-vencida-{$vacuna['evento_id']}-{$semanaIso}-wa";

            $resWeb = ClienteNotificaciones::enviar($vacuna['usuario_id'], 'VACUNA', 'WEB', $mensaje, $hoy, $idOpWeb);
            if (($resWeb['datos']['aplicado'] ?? false) === true) $aplicados++; else $repetidos++;

            $resWa = ClienteNotificaciones::enviar($vacuna['usuario_id'], 'VACUNA', 'WHATSAPP', $mensaje, $hoy, $idOpWa);
            if (($resWa['datos']['aplicado'] ?? false) === true) { $aplicados++; $detalle['vacuna_vencida']++; } else $repetidos++;
        }

        // 3. Control de adopción (a los días que configuró la veterinaria; por defecto, 30 y 90)
        [$primerControl, $segundoControl] = ParametrosMotor::controlesAdopcion();
        foreach (ReporteRepositorio::obtenerControlesAdopcion($hoy, $primerControl, $segundoControl) as $ctrl) {
            $tipoControl = (int) $ctrl['dias_control'];
            $generados++;
            $mensaje = "Se cumplen {$tipoControl} días de la adopción de {$ctrl['mascota_nombre']}. Corresponde control veterinario.";
            $idOpWeb = "control-{$ctrl['adopcion_id']}-{$tipoControl}-web";
            $idOpWa = "control-{$ctrl['adopcion_id']}-{$tipoControl}-wa";

            $resWeb = ClienteNotificaciones::enviar($ctrl['adoptante_id'], 'CONTROL_ADOPCION', 'WEB', $mensaje, $hoy, $idOpWeb);
            if (($resWeb['datos']['aplicado'] ?? false) === true) $aplicados++; else $repetidos++;

            $resWa = ClienteNotificaciones::enviar($ctrl['adoptante_id'], 'CONTROL_ADOPCION', 'WHATSAPP', $mensaje, $hoy, $idOpWa);
            if (($resWa['datos']['aplicado'] ?? false) === true) { $aplicados++; $detalle['control_adopcion']++; } else $repetidos++;
        }

        // 4. Stock bajo (asumimos usuario ID 101 administrador/veterinaria)
        foreach (ReporteRepositorio::obtenerStockBajo(ParametrosMotor::limiteStockBajo()) as $item) {
            $generados++;
            $mensaje = "Stock bajo para {$item['tipo']} '{$item['nombre']}' (quedan {$item['stock']} unidades).";
            $idOp = "stock-{$item['tipo']}-{$item['id']}-{$hoy}";

            $res = ClienteNotificaciones::enviar(101, 'STOCK_BAJO', 'WEB', $mensaje, $hoy, $idOp);
            if (($res['datos']['aplicado'] ?? false) === true) { $aplicados++; $detalle['stock_bajo']++; } else $repetidos++;
        }

        // 5. Qué liquidar
        foreach (ReporteRepositorio::obtenerProductosPorVencer(ParametrosMotor::diasLiquidar()) as $prod) {
            $dto = $prod['dias_restantes'] < 10 ? 30 : 15;
            $generados++;
            $mensaje = "{$prod['nombre']}: vence el {$prod['fecha_vencimiento']}, quedan {$prod['stock']}. Descuento sugerido: {$dto}%";
            $idOp = "liquidar-{$prod['id']}-{$semanaIso}";

            $res = ClienteNotificaciones::enviar(101, 'PROMOCION', 'WEB', $mensaje, $hoy, $idOp);
            if (($res['datos']['aplicado'] ?? false) === true) { $aplicados++; $detalle['liquidar']++; } else $repetidos++;
        }

        return [
            'fecha' => $hoy,
            'generados' => $generados,
            'aplicados' => $aplicados,
            'repetidos' => $repetidos,
            'fallidos' => $fallidos,
            'detalle' => $detalle
        ];
    }
}
