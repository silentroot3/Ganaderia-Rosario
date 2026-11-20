# Ganadería El Rosario – Entrega de módulos semanas 6–9

## Instalación sobre tu proyecto actual
1. **Respaldo**: `./dump.sh`
2. Copia el contenido de este paquete sobre tu carpeta `desarrollo_software/` (reemplaza archivos).
3. Base de datos (en este orden):
   ```bash
   mariadb -h 127.0.0.1 -P 3307 -u silentroot -p Ganaderia_Rosario < database/migracion_final.sql
   mariadb -h 127.0.0.1 -P 3307 -u silentroot -p Ganaderia_Rosario < database/datos_prueba.sql   # opcional (stock + dietas)
   ```
4. Reinicia Apache (`sudo systemctl restart apache2`) para que tome el nuevo `.htaccess`.
5. Sigue `docs/PRUEBAS_HU019.md`.

## Qué incluye
| HU | Módulo | Archivos principales |
|---|---|---|
| 011 | Tratamientos, inventario de medicamentos, retiro | `modules/tratamientos/*`, `includes/tratamientos_lib.php` |
| — | Solicitudes de compra (vet/alimentador → admin) | `modules/solicitudes/*`, `includes/inventario_lib.php` |
| 012 | Seguimiento y GDP | `modules/seguimiento/*` |
| 013 | Costos por animal | `modules/costos/*`, `includes/costos.php` |
| 014 | Ventas | `modules/ventas/*` |
| 015 | Reportes + Excel/PDF | `modules/reportes/*`, `includes/reportes_lib.php` |
| 016 | Trazabilidad (hoja de vida) | `modules/trazabilidad/*`, `modules/bovinos/index.php` |
| 017 | Alertas automáticas | `includes/alertas_lib.php` |
| 018 | Ajustes del rancho + respaldo SQL | `modules/configuracion/ajustes.php`, `backup.php` |
| 004 | Alta / activar-desactivar usuarios | `modules/usuarios/crear.php`, `toggle.php` |

## Correcciones a lo que ya tenías
- `alimentacion/inventario.php` usaba la tabla `movimientos_inventario`, que **no existía** → ahora se crea en la migración.
- `alimentacion/registrar.php` **no descontaba la bodega** (HU-010) → ahora descuenta cada ingrediente según la receta, deja movimiento y avisa si queda bajo mínimo.
- `sanidad/atender.php` guardaba la dosis como texto en una columna `DECIMAL`, usaba "días de tratamiento" como días de retiro y no tocaba inventario → ahora usa `registrarTratamiento()`.
- `usuarios/editar.php` y otros llamaban a `Permisos::` antes de cargar la clase → `config.php` ahora la carga.
- `usuarios/reset.php` y el cambio de estado eran por enlace GET (cualquiera podía engañar a un admin con un link) → ahora POST + token CSRF.
- Login: bloqueo a 3 intentos (HU-001) y sin enlace de registro (HU-002).
- `bd_estructura_actual.sql` está desactualizado (no tiene `compras`, `compra_bovinos`…). La fuente de verdad es `rebuild_completo.sql` + `migracion_final.sql`.

## Cómo se calcula el costo por animal
`compra + alimentación + medicina + otros`
- **Compra**: `precio_por_kg` de la compra × peso de entrada (si no hay precio por kg: `precio_total / cantidad`).
- **Alimentación**: cada comida (kg × costo/kg de la dieta) se reparte entre los animales que estaban en ese corral **ese día**, reconstruido de `movimientos_bovino`.
- **Medicina**: dosis × precio unitario del inventario (promedio ponderado de las entradas).
- **Otros**: gastos manuales (`Costos → Registrar gasto`). Los gastos "generales" sin animal se restan en la ganancia neta del reporte.

## Pendiente / decisiones tuyas
- **Seguridad antes de publicar el repo**: `includes/config.php` tiene usuario/contraseña de BD y `SALT` en texto, y `display_errors=1`. Mueve credenciales a un `.env` fuera del repo y apaga `display_errors` en producción. Si el repo es público, **cambia la contraseña de la BD** (ya estuvo en el repositorio).
- **IVA**: la config trae 16 %; la venta de ganado en pie normalmente va a tasa 0 %. Ajusta en *Configuración → Datos del rancho* (se puede editar por venta).
- **Umbral "20 %" (HU-017)**: la alerta de comida usa `stock_mínimo` de cada ingrediente. Fija ese mínimo como el 20 % que quieras.
- **Cancelar venta**: la tabla lo permite, pero no hay pantalla (reabrir corrales/cupo requiere reglas).
- **PDF/Excel**: PDF por impresión del navegador; Excel como CSV (`;`, UTF-8). Sin librerías externas.
- `configuracion/precios` (card en el panel) aún no tiene pantalla.
- Archivos `.bak*` en `modules/`: bórralos del repo.
- **No pude ejecutar la BD** (no hay MariaDB en mi entorno): todo pasó `php -l` y revisé columnas contra `rebuild_completo.sql`, pero **probar con la guía HU-019 es obligatorio**.
