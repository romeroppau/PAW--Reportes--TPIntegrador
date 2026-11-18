<?php

$router->get('/api/reportes/salud', function (): void {
    try {
        Database::conectar()->query('SELECT 1');
    } catch (Throwable $error) {
        Logger::error(
            'Salud de Reportes: no se pudo conectar a la base: '
            . $error->getMessage()
        );

        Response::json([
            'estado' => 'sin conexión a la base',
            'modulo' => 'Reportes'
        ], 503);
        return;
    }

    Response::json([
        'estado' => 'funciona',
        'modulo' => 'Reportes'
    ], 200);
});
