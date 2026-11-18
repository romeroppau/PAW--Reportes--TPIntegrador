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
  1. Vacuna próxima (VACUNA): evento_sanitario sin aplicar (fecha_aplicada IS NULL) cuya fecha_programada cae entre hoy y los próximos 7 días (0 <= dias <= 7). Canales: WEB y WHATSAPP. id_operacion: vacuna-proxima-{evento}.
  2. Vacuna vencida (VACUNA): evento_sanitario sin aplicar cuya fecha_programada es anterior a hoy (fecha_programada < hoy). Una vez por semana ISO (o-\WW). Canales: WEB y WHATSAPP. id_operacion: vacuna-vencida-{evento}-{semana}.
  3. Control de adopción (CONTROL_ADOPCION): adopcion con estado = 'FINALIZADA' cuya DATE(fecha_fin) cumple exactamente 30 o 90 días hoy. Canales: WEB y WHATSAPP. id_operacion: control-{adopcion}-{30|90}.
  4. Stock bajo (STOCK_BAJO): producto o insumo activo (activo = 1) con stock <= 10. Destinatario: veterinaria (WEB). id_operacion: stock-{tipo}-{id}-{fecha}.
  5. Qué liquidar (PROMOCION): producto activo con stock > 0 y fecha_vencimiento entre hoy y los próximos 30 días. Descuento sugerido: 30% si faltan menos de 10 días (< 10), 15% si faltan entre 10 y 30 días. Destinatario: veterinaria (WEB). id_operacion: liquidar-{producto}-{semana}.

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
      "liquidar": 1
    }
  }

---

## 3. Reportes y sugerencias (Rol VETERINARIO)

Todos los endpoints de reportes exigen sesión activa con rol VETERINARIO (401 sin sesión, 403 otro rol).

### GET /api/reportes/sugerencias
Devuelve los dos cruces de datos con sus sugerencias concretas y los números de los que salen:
1. que_promocionar (Franjas con pocos turnos): analiza los turnos no cancelados de las últimas 4 semanas (28 días hacia atrás desde hoy), agrupados por día de la semana (Lunes a Sábado) y franja horaria (mañana: antes de las 13:00; tarde: desde las 13:00). Identifica la franja con menor ocupación y genera el texto de sugerencia.
2. que_liquidar (Productos por vencer con stock): productos activos con stock > 0 y fecha_vencimiento dentro de los próximos 30 días, indicando dias_restantes, stock, descuento_sugerido (15 o 30) y texto de sugerencia.

### GET /api/reportes/turnos-atendidos?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
Consulta la vista v_turnos_atendidos (turno + consulta finalizada + cantidades de insumos y productos + factura), junto con los nombres de la mascota, dueño y veterinario.
- Parámetros opcionales desde y hasta (YYYY-MM-DD). Si se envía uno con formato inválido o desde > hasta, responde 400 Bad Request.

### GET /api/reportes/stock?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
Devuelve el estado actual de productos e insumos activos (marcando cuáles tienen stock bajo <= 10 o están sin stock = 0) y el historial de movimiento_inventario (filtrable por desde y hasta).
