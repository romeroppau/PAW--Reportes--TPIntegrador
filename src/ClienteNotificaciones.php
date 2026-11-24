<?php

class ClienteNotificaciones
{
    public static function enviar(int $usuarioId, string $tipo, string $canal, string $mensaje, string $fechaProgramada, string $idOperacion): array
    {
        $token = Config::get('INTERNAL_TOKEN');

        if (!is_string($token) || $token === '') {
            Logger::error('Reportes: falta INTERNAL_TOKEN para llamar a Backend');
            return ['codigo' => 0, 'datos' => []];
        }

        $base = Config::get('BACKEND_URL') ?: 'http://127.0.0.1:9001';

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode([
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'canal' => $canal,
                'mensaje' => $mensaje,
                'fecha_programada' => $fechaProgramada,
                'id_operacion' => $idOperacion
            ]),
            'timeout' => 5,
            'ignore_errors' => true
        ]]);

        $cuerpo = @file_get_contents($base . '/internal/notificaciones', false, $contexto);

        if ($cuerpo === false) {
            Logger::error('Reportes: Backend no respondió al enviar la notificación ' . $idOperacion);
            return ['codigo' => 0, 'datos' => []];
        }

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $codigo = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $coincidencia)) {
            $codigo = (int) $coincidencia[1];
        }

        $datos = json_decode($cuerpo, true);

        return ['codigo' => $codigo, 'datos' => is_array($datos) ? $datos : []];
    }
}
