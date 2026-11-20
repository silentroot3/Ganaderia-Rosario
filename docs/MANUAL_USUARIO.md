# Manual de usuario – Sistema de Gestión Ganadera El Rosario

Entra con tu correo y contraseña. El menú de la izquierda **solo muestra lo que te toca**. Si ves el aviso 🔔 en la barra superior, hay alertas pendientes.

---
## 🚚 Recepcionista
1. **Recepción → Recibir compra**: abre la compra pendiente (llegó una alerta).
2. Por cada animal marca: *llegó bien*, *llegó enfermo* (irá a hospital), *murió* o *no llegó*. Captura el peso real y el arete de 12 dígitos.
3. Guarda. Los animales sanos reciben corral automáticamente; los enfermos pasan al corral de hospital.
4. Para ver todo lo recibido: **Bovinos**. Para ver la historia de un animal: **Trazabilidad**.

## 🩺 Veterinario
- **Veterinario**: lista de animales en hospital. *Atender* → diagnóstico, medicamento del inventario, dosis (en ml u otra unidad del inventario), vía y días de retiro (vacío = el del medicamento).
  - El sistema descuenta el medicamento, calcula el costo y **bloquea la venta** del animal hasta que termine el retiro.
- **Alta**: cuando el animal mejora, dalo de alta; vuelve a su corral.
- **Tratamientos**: animales en retiro e historial. **Tratamientos → Inventario de medicamentos**: registra entradas, agrega medicamentos nuevos y mira existencias.
- ¿Falta medicamento? **Solicitar compra** (o **Solicitudes → Nueva**). El administrador recibe la alerta y te avisa cuando llegue.

## ⚖️ Clasificador
- **Clasificación**: animales recibidos sin corral → captura sexo y peso; el sistema sugiere categoría y corral. *Asignar* a un corral (no deja pasar del cupo). Si ves una anomalía: **Enviar a veterinaria**.
- **Corrales**: ver cuántos animales tiene cada corral y moverlos.
- **Seguimiento → Registrar pesajes**: elige el corral, captura el peso de cada animal (deja vacío el que no pesaste). El sistema calcula los **kilos ganados por día**.

## 🍽️ Alimentador
- **Alimentación → Registrar comida**: corral, dieta, kilos y horario (mañana/tarde). Se descuenta cada ingrediente de la bodega según la receta de la dieta.
- **Dietas**: recetas por etapa. **Inventario**: entradas de alimento y existencias.
- ¿Poco alimento? Llega alerta de *stock bajo*; pulsa **Solicitar alimento** para avisar al administrador.

## 🧑‍💼 Administrador
- **Configuración → Compras**: registra la compra de ganado (genera alerta al recepcionista).
- **Solicitudes**: lo que piden veterinario y alimentador. *Aprobar → Marcar pedida (a qué proveedor) → Recibida* (esto suma al inventario). También puede rechazar.
- **Costos**: lo invertido por animal (compra + comida + medicina + otros) y costo por kilo. *Registrar gasto* a un animal, a un corral o general.
- **Ventas → Nueva venta**: comprador, precio por kilo, selecciona animales listos (no aparecen los que tienen medicina en retiro). Al confirmar salen del inventario y liberan corral. La **hoja de venta** muestra la **ganancia real de cada animal**.
- **Reportes**: rango de fechas → ventas y rentabilidad, con gráfica mensual. *Descargar Excel* o *PDF/Imprimir*.
- **Trazabilidad**: escribe el arete → hoja de vida completa; botón *Imprimir / guardar PDF*.
- **Usuarios**: alta, edición, activar/desactivar, restablecer contraseña. **Configuración → Datos del rancho**: nombre, RFC, IVA, límites de alertas. **Configuración → Respaldo**: descarga un `.sql` con toda la información (guárdalo en lugar seguro).

---
### Consejos
- Para guardar un reporte como PDF: botón *PDF / Imprimir* → destino **Guardar como PDF**.
- Si un botón de venta/tratamiento marca error de "Stock insuficiente" o "retiro", es una protección del sistema, no una falla.
