<?php

class ReporteRepositorio
{
    public static function obtenerVacunasProximas(): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->query("
            SELECT e.id AS evento_id, e.nombre AS vacuna, e.fecha_programada,
                   m.id AS mascota_id, m.nombre AS mascota_nombre, u.id AS usuario_id, u.telefono
            FROM evento_sanitario e
            JOIN mascota m ON m.id = e.mascota_id
            JOIN usuario u ON u.id = m.duenio_id
            WHERE e.tipo = 'VACUNA'
              AND e.fecha_aplicada IS NULL
              AND e.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        return $stmt->fetchAll();
    }

    public static function obtenerVacunasVencidas(): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->query("
            SELECT e.id AS evento_id, e.nombre AS vacuna, e.fecha_programada,
                   m.id AS mascota_id, m.nombre AS mascota_nombre, u.id AS usuario_id, u.telefono
            FROM evento_sanitario e
            JOIN mascota m ON m.id = e.mascota_id
            JOIN usuario u ON u.id = m.duenio_id
            WHERE e.tipo = 'VACUNA'
              AND e.fecha_aplicada IS NULL
              AND e.fecha_programada < CURDATE()
        ");
        return $stmt->fetchAll();
    }

    public static function obtenerControlesAdopcion(string $hoy): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->prepare("
            SELECT a.id AS adopcion_id, a.fecha_fin, m.nombre AS mascota_nombre, u.id AS adoptante_id
            FROM adopcion a
            JOIN publicacion p ON p.id = a.publicacion_id
            JOIN mascota m ON m.id = p.mascota_id
            JOIN usuario u ON u.id = a.adoptante_id
            WHERE a.estado = 'FINALIZADA'
              AND (
                DATE(a.fecha_fin) = DATE_SUB(:hoy1, INTERVAL 30 DAY)
                OR DATE(a.fecha_fin) = DATE_SUB(:hoy2, INTERVAL 90 DAY)
              )
        ");
        $stmt->execute(['hoy1' => $hoy, 'hoy2' => $hoy]);
        return $stmt->fetchAll();
    }

    public static function obtenerStockBajo(): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->query("
            SELECT 'producto' AS tipo, id, nombre, stock FROM producto WHERE activo = 1 AND stock <= 10
            UNION ALL
            SELECT 'insumo' AS tipo, id, nombre, stock FROM insumo WHERE activo = 1 AND stock <= 10
        ");
        return $stmt->fetchAll();
    }

    public static function obtenerProductosPorVencer(): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->query("
            SELECT id, nombre, stock, precio, fecha_vencimiento,
                   DATEDIFF(fecha_vencimiento, CURDATE()) AS dias_restantes
            FROM producto
            WHERE activo = 1
              AND stock > 0
              AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ");
        return $stmt->fetchAll();
    }

    public static function obtenerTurnosUltimasCuatroSemanas(): array
    {
        $pdo = Database::conectar();
        $stmt = $pdo->query("
            SELECT fecha, hora, estado
            FROM turno
            WHERE estado <> 'CANCELADO'
              AND fecha >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
              AND fecha <= CURDATE()
        ");
        return $stmt->fetchAll();
    }

    public static function obtenerTurnosAtendidos(?string $desde, ?string $hasta): array
    $pdo = Database::conectar();
    $sql = "SELECT t.*, m.nombre AS mascota_nombre FROM v_turnos_atendidos t JOIN mascota m ON m.id = t.mascota_id WHERE 1=1";
    $params = [];
    if ($desde) { $sql .= " AND t.fecha >= :desde"; $params['desde'] = $desde; }
    if ($hasta) { $sql .= " AND t.fecha <= :hasta"; $params['hasta'] = $hasta; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

public static function obtenerStockYMovimientos(?string $desde, ?string $hasta): array
{
    $pdo = Database::conectar();
    $productos = $pdo->query("SELECT * FROM producto WHERE activo = 1")->fetchAll();
    $insumos = $pdo->query("SELECT * FROM insumo WHERE activo = 1")->fetchAll();
    
    $sql = "SELECT * FROM movimiento_inventario WHERE 1=1";
    $params = [];
    if ($desde) { $sql .= " AND DATE(registrado_en) >= :desde"; $params['desde'] = $desde; }
    if ($hasta) { $sql .= " AND DATE(registrado_en) <= :hasta"; $params['hasta'] = $hasta; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $movimientos = $stmt->fetchAll();

    return ['productos' => $productos, 'insumos' => $insumos, 'movimientos' => $movimientos];
}
}
