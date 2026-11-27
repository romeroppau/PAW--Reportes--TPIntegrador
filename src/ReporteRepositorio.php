<?php

class ReporteRepositorio
{
    // La conexión, con la hora de Argentina: CURDATE() y NOW() dan el día de acá y no el de UTC (la
    // base de producción está en UTC: de 21 a 24 h, CURDATE() ya sería mañana). Igual que Inventario.
    private static function conectar(): PDO
    {
        $pdo = Database::conectar();
        $pdo->exec("SET time_zone = '-03:00'");

        return $pdo;
    }

    // Las que le tocan entre hoy y dentro de $dias días (ParametrosMotor::diasAvisoVacuna()).
    public static function obtenerVacunasProximas(int $dias): array
    {
        $pdo = self::conectar();
        $stmt = $pdo->prepare("
            SELECT e.id AS evento_id, e.nombre AS vacuna, e.fecha_programada,
                   m.id AS mascota_id, m.nombre AS mascota_nombre, u.id AS usuario_id, u.telefono
            FROM evento_sanitario e
            JOIN mascota m ON m.id = e.mascota_id
            JOIN usuario u ON u.id = m.duenio_id
            WHERE e.tipo = 'VACUNA'
              AND e.fecha_aplicada IS NULL
              AND e.fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$dias]);
        return $stmt->fetchAll();
    }

    public static function obtenerVacunasVencidas(): array
    {
        $pdo = self::conectar();
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

    // Las adopciones que hoy cumplen exactamente $primero o $segundo días (ParametrosMotor::controlesAdopcion()).
    // Cada fila trae dias_control: cuál de los dos controles le corresponde.
    public static function obtenerControlesAdopcion(string $hoy, int $primero, int $segundo): array
    {
        $fechaPrimero = (new DateTimeImmutable($hoy))->modify("-$primero days")->format('Y-m-d');
        $fechaSegundo = (new DateTimeImmutable($hoy))->modify("-$segundo days")->format('Y-m-d');
        $pdo = self::conectar();
        $stmt = $pdo->prepare("
            SELECT a.id AS adopcion_id, a.fecha_fin, m.nombre AS mascota_nombre, u.id AS adoptante_id
            FROM adopcion a
            JOIN publicacion p ON p.id = a.publicacion_id
            JOIN mascota m ON m.id = p.mascota_id
            JOIN usuario u ON u.id = a.adoptante_id
            WHERE a.estado = 'FINALIZADA'
              AND DATE(a.fecha_fin) IN (?, ?)
        ");
        $stmt->execute([$fechaPrimero, $fechaSegundo]);
        $filas = $stmt->fetchAll();
        foreach ($filas as &$fila) {
            $fila['dias_control'] = substr((string) $fila['fecha_fin'], 0, 10) === $fechaSegundo ? $segundo : $primero;
        }
        unset($fila);
        return $filas;
    }

    // Las veterinarias (los avisos de stock y de qué liquidar van a cada una).
    public static function veterinarias(): array
    {
        $filas = self::conectar()->query("SELECT id FROM usuario WHERE rol = 'VETERINARIO' ORDER BY id")->fetchAll();
        return array_map(fn (array $f): int => (int) $f['id'], $filas);
    }

    // Con $limite unidades o menos (ParametrosMotor::limiteStockBajo()). Cuenta lo que se puede vender
    // o usar: sin los lotes vencidos (migración 028 en productos, 029 en insumos).
    public static function obtenerStockBajo(int $limite): array
    {
        $pdo = self::conectar();
        $stmt = $pdo->prepare("
            SELECT * FROM (
                SELECT 'producto' AS tipo, p.id, p.nombre,
                       (SELECT COALESCE(SUM(l.stock), 0) FROM lote_producto l
                        WHERE l.producto_id = p.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE())) AS stock
                FROM producto p WHERE p.activo = 1
            ) productos WHERE stock <= ?
            UNION ALL
            SELECT * FROM (
                SELECT 'insumo' AS tipo, i.id, i.nombre,
                       (SELECT COALESCE(SUM(l.stock), 0) FROM lote_insumo l
                        WHERE l.insumo_id = i.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE())) AS stock
                FROM insumo i WHERE i.activo = 1
            ) insumos WHERE stock <= ?
        ");
        $stmt->execute([$limite, $limite]);
        return $stmt->fetchAll();
    }

    // Los lotes con stock que vencen entre hoy y dentro de $dias días (ParametrosMotor::diasLiquidar()),
    // de los productos activos, el que vence primero arriba (migración 028: el vencimiento es de cada lote).
    public static function obtenerLotesPorVencer(int $dias): array
    {
        $pdo = self::conectar();
        $stmt = $pdo->prepare("
            SELECT p.id AS producto_id, p.nombre, p.precio, l.id AS lote_id, l.numero, l.codigo, l.stock,
                   DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS fecha_vencimiento,
                   DATEDIFF(l.vencimiento, CURDATE()) AS dias_restantes
            FROM lote_producto l
            JOIN producto p ON p.id = l.producto_id
            WHERE p.activo = 1
              AND l.stock > 0
              AND l.vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY l.vencimiento, p.nombre, l.id
        ");
        $stmt->execute([$dias]);
        return $stmt->fetchAll();
    }

    // Los lotes vencidos que todavía tienen stock (ya no se venden ni se usan: hay que retirarlos), de
    // los productos y, desde la migración 029, de los insumos activos. tipo: "producto" o "insumo".
    public static function obtenerLotesVencidos(): array
    {
        $pdo = self::conectar();
        return $pdo->query("
            SELECT * FROM (
                SELECT 'producto' AS tipo, p.id AS producto_id, NULL AS insumo_id, p.nombre, l.id AS lote_id,
                       l.numero, l.codigo, l.stock, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS fecha_vencimiento
                FROM lote_producto l
                JOIN producto p ON p.id = l.producto_id
                WHERE p.activo = 1 AND l.stock > 0 AND l.vencimiento < CURDATE()
                UNION ALL
                SELECT 'insumo', NULL, i.id, i.nombre, l.id, l.numero, l.codigo, l.stock,
                       DATE_FORMAT(l.vencimiento, '%Y-%m-%d')
                FROM lote_insumo l
                JOIN insumo i ON i.id = l.insumo_id
                WHERE i.activo = 1 AND l.stock > 0 AND l.vencimiento < CURDATE()
            ) vencidos
            ORDER BY fecha_vencimiento, tipo DESC, nombre, lote_id
        ")->fetchAll();
    }

    public static function obtenerTurnosUltimasCuatroSemanas(): array
    {
        $pdo = self::conectar();
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
    {
        $pdo = self::conectar();
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
        $pdo = self::conectar();
        // El stock sin lo vencido (27/11): lo vencido no se vende ni se usa. La tarea diaria de Inventario
        // lo da de baja; hasta que corre, no se cuenta.
        $productos = $pdo->query("SELECT p.id, p.nombre,
                                         (SELECT COALESCE(SUM(l.stock), 0) FROM lote_producto l
                                          WHERE l.producto_id = p.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE())) AS stock,
                                         p.precio, p.fecha_vencimiento
                                  FROM producto p WHERE p.activo = 1")->fetchAll();
        $insumos = $pdo->query("SELECT i.id, i.nombre,
                                       (SELECT COALESCE(SUM(l.stock), 0) FROM lote_insumo l
                                        WHERE l.insumo_id = i.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE())) AS stock,
                                       i.costo, i.estado
                                FROM insumo i WHERE i.activo = 1")->fetchAll();
        
        $sql = "SELECT * FROM movimiento_inventario WHERE 1=1";
        $params = [];
        if ($desde) { $sql .= " AND DATE(fecha) >= :desde"; $params['desde'] = $desde; }
        if ($hasta) { $sql .= " AND DATE(fecha) <= :hasta"; $params['hasta'] = $hasta; }
        $sql .= " ORDER BY fecha DESC, id DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $movimientos = $stmt->fetchAll();

        return ['productos' => $productos, 'insumos' => $insumos, 'movimientos' => $movimientos];
    }
}
