# Contrato de Reportes y Motor de Recomendaciones (Rol 4)

Proceso en 127.0.0.1:9005, expuesto por Nginx bajo /api/reportes/.
El usuario de MySQL reportes tiene únicamente permisos SELECT: este módulo no escribe en la base de datos. Los avisos generados por el motor se envían a Backend mediante POST [http://127.0.0.1:9001/internal/notificaciones](http://127.0.0.1:9001/internal/notificaciones).

---

## 1. Salud del módulo

### GET /api/reportes/salud
Comprueba la conexión de solo lectura con MySQL (SELECT 1).
- 200 OK: { "estado": "funciona", "modulo": "Reportes" }
- 503 Service Unavailable: { "estado": "sin conexión a la base", "modulo": "Reportes" }

---

## 2. Tarea diaria del motor de avisos

### POST /api/reportes/tareas/diaria
Ejecuta las reglas diarias del MotorRecomendaciones tomando la fecha actual de Argentina (America/Argentina/Buenos_Aires) y solicita los avisos correspondientes a Backend (/internal/notificaciones) con id_operacion determinístico para garantizar idempotencia.

- Cabecera obligatoria: X-Tarea-Token: <TAREAS_TOKEN> (validado con hash_equals).
- Errores:
  - 401 Unauthorized: si falta el header X-Tarea-Token o no coincide.
- Reglas ejecutadas:
  1. Vacuna próxima (VACUNA): evento_sanitario sin aplicar (fecha_aplicada IS NULL) cuya fecha_programada cae entre hoy y los próximos N días (N = `dias_aviso_vacuna`, por defecto 7). Canales: WEB y WHATSAPP. id_operacion: vacuna-proxima-{evento}.
  2. Vacuna vencida (VACUNA): evento_sanitario sin aplicar cuya fecha_programada es anterior a hoy (fecha_programada < hoy). Una vez por semana ISO (o-\WW). Canales: WEB y WHATSAPP. id_operacion: vacuna-vencida-{evento}-{semana}.
  3. Control de adopción (CONTROL_ADOPCION): adopcion con estado = 'FINALIZADA' cuya DATE(fecha_fin) cumple exactamente los días del primer o del segundo control (`dias_control_adopcion_1` y `dias_control_adopcion_2`, por defecto 30 y 90). Canales: WEB y WHATSAPP. id_operacion: control-{adopcion}-{días del control}.
  4. Stock bajo (STOCK_BAJO): producto o insumo activo (activo = 1) con stock <= `limite_stock_bajo` (por defecto 10). De un producto cuenta lo que se puede vender: la suma de sus lotes sin vencer (26/11, migración 028). Destinatario: cada veterinaria (WEB; 25/11, antes solo la 101). id_operacion: stock-{tipo}-{id}-{fecha}-vet-{veterinaria}.
  5. Qué liquidar (PROMOCION), **por lote** (26/11, migración 028 de Inventario: cada producto tiene lotes con su vencimiento): lote con stock > 0 de un producto activo que vence entre hoy y los próximos `dias_liquidar` días (por defecto 30). Descuento sugerido: 30% si faltan menos de 10 días (< 10), 15% si faltan 10 o más. El aviso nombra el lote: "Pipeta Frontline Plus para gatos, lote L-0425: vence el 16/12/2026, quedan 18. Descuento sugerido: 15%" ("lote sin número" si no tiene). Destinatario: cada veterinaria (WEB). id_operacion: liquidar-{producto}-lote-{lote}-{semana}-vet-{veterinaria} (antes del 26/11, liquidar-{producto}-{semana}-vet-{veterinaria}: esa semana puede llegar una vez más con el lote).
  6. Lote vencido (STOCK_BAJO, 26/11): lote vencido que todavía tiene stock (ya no se vende): "…, lote L-0311: venció el 20/11/2026 y quedan 3. Ya no se vende: retiralo del stock." Una sola vez por lote. Destinatario: cada veterinaria (WEB). id_operacion: lote-vencido-{lote}-vet-{veterinaria}.

- 200 OK:
  {
    "fecha": "2026-11-18",
    "generados": 9,
    "aplicados": 9,
    "repetidos": 0,
    "fallidos": 0,
    "detalle": {
      "vacuna_proxima": 1,
      "vacuna_vencida": 2,
      "control_adopcion": 1,
      "stock_bajo": 4,
      "liquidar": 1,
      "lote_vencido": 0
    }
  }

---

## 3. Reportes y sugerencias (Rol VETERINARIO)

Todos los endpoints de reportes exigen sesión activa con rol VETERINARIO (401 sin sesión, 403 otro rol).

### GET /api/reportes/sugerencias
Devuelve los dos cruces de datos con sus sugerencias concretas y los números de los que salen:
1. que_promocionar (Franjas con pocos turnos): analiza los turnos no cancelados de las últimas 4 semanas (28 días hacia atrás desde hoy), agrupados por día de la semana (Lunes a Sábado) y franja horaria (mañana: antes de las 13:00; tarde: desde las 13:00). Identifica la franja con menor ocupación y genera el texto de sugerencia.
   - `a_promocionar` (Entrega 5): las **5 franjas con menos turnos** (si empatan, la primera de la semana), para promocionarlas con un descuento a la consulta. Cada una trae `dia_semana`, `franja`, `cantidad_turnos`, `dia_numero` (1 = lunes), `franja_codigo` (`MANIANA` o `TARDE`), `descuento_sugerido` (25 % si no tuvo turnos, 15 % con 1 o 2, 10 % con más) y `precio_sugerido` (el precio base con ese descuento). `precio_consulta`: el precio base de la configuración. La veterinaria decide si la publicita y con qué porcentaje: `POST /api/pacientes/promociones-turnos` de Pacientes (contrato-mascotas-turnos.md, "Precio del turno y franjas promocionadas").
2. que_liquidar (Lotes por vencer con stock, 26/11): un elemento **por lote** con stock > 0 de un producto activo que vence dentro de los próximos `dias_liquidar` días (por defecto 30), el que vence primero arriba: `producto_id`, `nombre`, `lote_id`, `lote` (el número, o `null`), `fecha_vencimiento` (la del lote), `dias_restantes`, `stock` (las unidades de ese lote), `precio`, `descuento_sugerido` (15 o 30), `precio_sugerido` y `sugerencia` (el texto, que nombra el lote). "Publicitar" manda el `lote_id` a `POST /api/inventario/promociones` (Inventario, contrato-tienda.md): la promoción es de ese lote.
3. lotes_vencidos (26/11): los lotes vencidos que todavía tienen stock (ya no se venden: hay que retirarlos desde Productos): `producto_id`, `nombre`, `lote_id`, `lote`, `fecha_vencimiento`, `stock` y `sugerencia`.

### GET /api/reportes/turnos-atendidos?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
Consulta la vista v_turnos_atendidos (turno + consulta finalizada + cantidades de insumos y productos + factura), junto con los nombres de la mascota, dueño y veterinario.
- Parámetros opcionales desde y hasta (YYYY-MM-DD). Si se envía uno con formato inválido o desde > hasta, responde 400 Bad Request.

### GET /api/reportes/stock?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
Devuelve el estado actual de productos e insumos activos (marcando cuáles tienen stock bajo, <= `limite_stock_bajo`, o están sin stock = 0). `resumen.limite_stock_bajo` dice el límite que se usó y el historial de movimiento_inventario (filtrable por desde y hasta).

## Parámetros de la veterinaria (Entrega 5)

Los días y el límite que usan el motor y los reportes los cambia la veterinaria desde su panel (tabla `configuracion`, migración 014; catálogo y reglas en `docs/api/contrato.md` de Backend, sección "Configuración"). Este módulo los lee con `comun/configuracion.php` (`src/ParametrosMotor.php`): si no están guardados, o lo guardado no sirve, usa los valores por defecto de siempre. El usuario `reportes` tiene `SELECT` sobre `configuracion`.
