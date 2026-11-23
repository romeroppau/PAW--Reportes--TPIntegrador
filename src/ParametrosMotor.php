<?php

// Los parámetros del motor y de los reportes que la veterinaria cambia desde su panel (tabla
// configuracion; catálogo y reglas en src/CatalogoConfiguracion.php de Backend). Si no los cambió,
// o lo guardado no sirve, son los de siempre: los mismos valores por defecto que el catálogo.
class ParametrosMotor
{
    // Vacuna próxima: se avisa si le toca entre hoy y dentro de estos días.
    public static function diasAvisoVacuna(): int
    {
        return Configuracion::entero('dias_aviso_vacuna', 7, 1, 30);
    }

    // Controles de adopción: [primero, segundo], en días desde que terminó la adopción.
    public static function controlesAdopcion(): array
    {
        $primero = Configuracion::entero('dias_control_adopcion_1', 30, 7, 180);
        $segundo = Configuracion::entero('dias_control_adopcion_2', 90, 14, 365);

        // Backend no deja guardar el segundo antes del primero; si igual pasara, los de siempre.
        return $primero < $segundo ? [$primero, $segundo] : [30, 90];
    }

    // Stock bajo: con estas unidades o menos.
    public static function limiteStockBajo(): int
    {
        return Configuracion::entero('limite_stock_bajo', 10, 0, 1000);
    }

    // El precio base de la consulta (Pacientes lo usa para el precio de cada turno). Acá, para mostrar
    // el precio con el descuento sugerido de las franjas a promocionar.
    public static function precioConsulta(): int
    {
        return Configuracion::entero('precio_consulta', 15000, 0, 1000000);
    }

    // Qué liquidar: productos que vencen entre hoy y dentro de estos días.
    public static function diasLiquidar(): int
    {
        return Configuracion::entero('dias_liquidar', 30, 1, 180);
    }
}
