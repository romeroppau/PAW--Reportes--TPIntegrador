<?php

$router->get('/api/reportes/sugerencias', [ReporteControlador::class, 'sugerencias']);
$router->get('/api/reportes/turnos-atendidos', [ReporteControlador::class, 'turnosAtendidos']);
$router->get('/api/reportes/stock', [ReporteControlador::class, 'stock']);
$router->post('/api/reportes/tareas/diaria', [ReporteControlador::class, 'tareaDiaria']);
