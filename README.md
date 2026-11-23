# HuellaVet — Reportes

Repositorio del módulo **Reportes** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Tiene dos partes:

- **Reportes y sugerencias** para la veterinaria: qué franjas horarias promocionar, qué productos liquidar, los turnos atendidos y el estado del stock.
- **Motor diario de avisos**: una tarea que corre todos los días y genera los avisos de vacunas, controles de adopción, stock bajo y productos por vencer. Los avisos los guarda y los envía Backend (campana del sitio y WhatsApp).

Todas sus rutas empiezan con `/api/reportes/`.

## Autores

| Integrante | Legajo |
| --- | --- |
| Ana Paula Romero | 195388 |
| Maria Trinidad Lopez | 197958 |
| Valentino Aimale | 197961 |
| Cristian Tomás Anito | 158887 |

## Documentación

- [Contrato de Reportes y del motor](./docs/api/contrato-reportes.md): qué recibe y qué devuelve cada endpoint, y las reglas del motor.
- [Contrato de notificaciones](https://github.com/romeroppau/PAW--Backend--TPIntegrador/blob/dev/docs/api/contrato-notificaciones.md) (Backend): cómo se guardan y se envían los avisos.
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/dev/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.

## Entrega 5 — qué tiene

### Reportes (solo rol `VETERINARIO`)

| Endpoint | Qué devuelve |
| --- | --- |
| `GET /api/reportes/sugerencias` | **Qué promocionar:** las franjas (día de la semana, mañana o tarde) con menos turnos en las últimas 4 semanas, y las 5 con menos para ofrecer un descuento en la consulta. **Qué liquidar:** los productos con stock que vencen en los próximos días, con el descuento sugerido (15 % o 30 %) |
| `GET /api/reportes/turnos-atendidos?desde=&hasta=` | Los turnos atendidos con su consulta, los insumos y productos usados y la factura |
| `GET /api/reportes/stock?desde=&hasta=` | El stock actual de productos e insumos (marcando stock bajo y sin stock) y los movimientos de inventario del período |

Desde el panel, la veterinaria publica las promociones que surgen de estas sugerencias: el descuento de una franja de turnos (módulo Pacientes) y el de un producto por vencer (módulo Inventario).

### Motor diario de avisos

`POST /api/reportes/tareas/diaria` evalúa las reglas con la fecha de Argentina y le pide a Backend cada aviso (`POST /internal/notificaciones`), con un `id_operacion` fijo para que correrlo dos veces no repita ninguno.

| Regla | A quién | Canal | Cuándo |
| --- | --- | --- | --- |
| Vacuna próxima | Dueño de la mascota | Campana y WhatsApp | Le toca en los próximos `dias_aviso_vacuna` días (por defecto 7) |
| Vacuna vencida | Dueño de la mascota | Campana y WhatsApp | Una vez por semana mientras no se aplique |
| Control de adopción | Adoptante | Campana y WhatsApp | A los `dias_control_adopcion_1` y `dias_control_adopcion_2` días de cerrada (por defecto 30 y 90) |
| Stock bajo | Veterinaria | Campana | Producto o insumo con stock menor o igual a `limite_stock_bajo` (por defecto 10) |
| Qué liquidar | Veterinaria | Campana | Producto con stock que vence en los próximos `dias_liquidar` días (por defecto 30) |

Los parámetros (días de aviso, límite de stock, precio de la consulta) los configura la veterinaria en **Configuración**; el motor los lee de la tabla `configuracion` (Backend).

La tarea exige el header `X-Tarea-Token` (comparado con `hash_equals`). La llama el workflow **Motor diario** del repo ApiGateway todos los días a las 09:00 de Argentina, y después ese mismo workflow pide el envío de los WhatsApp que quedaron pendientes.

### Seguridad

- Los reportes exigen la sesión de una veterinaria (401 sin sesión, 403 con otro rol).
- La tarea diaria solo responde con el token de tareas; sin él, 401.
- El módulo se conecta a MySQL con su propio usuario (`reportes`), **solo de lectura**: no puede modificar ninguna tabla. Los avisos no los escribe él: se los pide a Backend por `/internal/`, con el token interno.
- No recibe la clave privada JWT, ni el token de WhatsApp, ni las credenciales de Mercado Pago.
- Las consultas usan parámetros (PDO) y las fechas se calculan con la zona de Argentina.

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/rutas/reportes.php`, `src/rutas/salud.php` | Las rutas del módulo |
| `src/ReporteControlador.php` | Un método por endpoint: controla la sesión, el rol o el token de tareas |
| `src/ReporteRepositorio.php` | Las consultas SQL de los reportes |
| `src/MotorRecomendaciones.php` | Las reglas del motor y los cruces de "qué promocionar" y "qué liquidar" |
| `src/ParametrosMotor.php` | Lee los parámetros configurables, con sus valores por defecto y límites |
| `src/ClienteNotificaciones.php` | La llamada interna a Backend para crear cada aviso |
| `src/ValidadorReportes.php` | Valida las fechas de los filtros |
| `tests/` | Pruebas PHPUnit |

## Variables

| Variable | Para qué |
| --- | --- |
| `DB_*` | Conexión a MySQL. `DB_USER` y `DB_PASSWORD` se los pasa `iniciar.sh` del Gateway desde `REPORTES_DB_USER` y `REPORTES_DB_PASSWORD` |
| `JWT_PUBLIC_KEY` | Para leer la sesión |
| `INTERNAL_TOKEN` | Para pedirle los avisos a Backend |
| `TAREAS_TOKEN` | El token que exige la tarea diaria |
| `BACKEND_URL` | Opcional. Por defecto `http://127.0.0.1:9001` |

## Cómo correrlo

No se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway** (proceso en `127.0.0.1:9005`), junto con Nginx, el código común (`comun/`) y los demás módulos. Nginx deriva `/api/reportes/` a este módulo.

Los pasos para levantar todo están en el README de [ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador). Una vez levantado:

```bash
curl http://localhost:10000/api/reportes/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Reportes"}`.

## Pruebas

- **PHPUnit** (`tests/`): parámetros del motor, fechas de los avisos, franjas a promocionar y validación de filtros.

  ```bash
  composer install
  COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun vendor/bin/phpunit --testdox
  ```

- **Pruebas de humo** (repo ApiGateway, `pruebas/casos/`): `12_reportes.sh` y `15_motor.sh` prueban los reportes y la tarea diaria contra el sistema levantado.

## No incluido

- **Aviso de recompra de alimento** y **productos recomendados según la mascota**: estaban en la idea inicial del motor, pero no se implementaron en esta entrega.
