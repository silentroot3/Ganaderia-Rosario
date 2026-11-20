# HU-019 – Prueba del recorrido completo (10 animales)

**Antes de empezar:** respaldo (`./dump.sh`), `migracion_final.sql` y `datos_prueba.sql` aplicados, un usuario de cada puesto.

Marca cada casilla al pasar. Si algo falla, anota el paso y el mensaje de error.

## A. Preparación
- [ ] Admin: **Configuración → Compras → Nueva compra** con 10 animales (5 machos, 5 hembras, pesos 150–400 kg, `precio por kg` capturado).
- [ ] Verifica que llegó la alerta *Compra pendiente* al recepcionista.

## B. Llegada y revisión (HU-005/006/007)
- [ ] Recepcionista: **Recepción → Recibir compra**. Marca 7 como llegaron bien, 1 como enfermo (hospital), 1 como muerto, 1 como no llegó.
- [ ] Los 8 que llegaron aparecen en **Bovinos**; el muerto/faltante aparece en bajas de la recepción.
- [ ] Veterinario: **Veterinario** muestra al enfermo en hospital.

## C. Clasificación y corral (HU-008/009)
- [ ] Clasificador: los animales quedan en un corral por sexo y peso. Un corral lleno rechaza otro animal ("no hay cupo").
- [ ] Mover un animal de corral actualiza el conteo de **ambos** corrales.
- [ ] Clasificador envía otro animal a veterinaria (**Enviar a vet**): pasa a hospital.

## D. Medicina y retiro (HU-011)
- [ ] Veterinario: **Tratamientos → Inventario**: las existencias de `datos_prueba.sql` aparecen.
- [ ] Atender al enfermo con *Ivermectina* (retiro 21 d): el stock baja la dosis y aparece en **Tratamientos → En retiro**.
- [ ] Intentar aplicar más dosis que el stock → error "Stock insuficiente".
- [ ] Bajar el stock de un medicamento por debajo del mínimo → llega alerta *Medicamento bajo* al admin.
- [ ] Veterinario: **Solicitudes → Nueva** (medicamento). Admin la ve, la aprueba, la marca *pedida* y luego *recibida*: el stock sube.
- [ ] Dar de alta al enfermo (**Veterinario → Alta**): vuelve a su corral.

## E. Comida (HU-010)
- [ ] Alimentador: **Alimentación → Registrar** en dos corrales (mañana y tarde).
- [ ] La bodega baja según la receta (ej. 100 kg de *Finalización* = 60 kg de maíz, 12 de alfalfa…).
- [ ] Servir más de lo que hay → error "Stock insuficiente".
- [ ] Llevar un ingrediente bajo el mínimo → alerta *Stock bajo*; alimentador hace **Solicitud de alimento**; admin la recibe y la bodega sube.

## F. Pesajes (HU-012)
- [ ] Clasificador: **Seguimiento → Registrar pesajes** por corral (hoy y, cambiando la fecha, otro pesaje 15 días antes/después).
- [ ] La GDP = (peso actual − peso anterior) / días. Ej.: 300 → 330 kg en 30 días = **1.000** kg/día.

## G. Costos y venta (HU-013/014)
- [ ] Admin: **Costos** muestra compra + alimento + medicina por animal y el costo por kg.
- [ ] **Ventas → Nueva venta**: el animal en retiro **NO** aparece en la lista (aparece el aviso de bloqueados).
- [ ] Vender 4 animales con precio por kg → total correcto, IVA según el campo.
- [ ] Los vendidos quedan en estado *vendido*, el corral baja su ocupación y la **Hoja de venta** muestra la ganancia real de cada uno.

## H. Reportes, trazabilidad y alertas (HU-015/016/017)
- [ ] **Reportes**: rango de fechas → ventas y ganancia neta; descargar Excel (CSV) y PDF (imprimir).
- [ ] **Trazabilidad**: escribir el arete de un animal vendido → línea de tiempo completa (recepción, revisión, corral, medicina, pesos, venta). Imprimir/PDF.
- [ ] **Alertas**: aparecen avisos de comida baja y enfermo sin medicina > 24 h (para probar esto último, cambia `fecha_entrada_hospital` de un animal a ayer en la BD).

## I. Seguridad por rol (HU-003)
Intenta abrir estas direcciones con cada puesto — debe salir **403**:

| Puesto | Debe bloquearse |
|---|---|
| Recepcionista | `/costos`, `/ventas`, `/sanidad`, `/reportes`, `/usuarios`, `/configuracion` |
| Veterinario | `/costos`, `/ventas`, `/alimentacion`, `/usuarios` |
| Clasificador | `/costos`, `/ventas`, `/tratamientos`, `/alimentacion`, `/usuarios` |
| Alimentador | `/costos`, `/ventas`, `/sanidad`, `/clasificacion`, `/usuarios` |

- [ ] 3 intentos fallidos de login seguidos bloquean 15 min (HU-001).
- [ ] El login ya no muestra enlace de registro y `/register` redirige al login (HU-002).
- [ ] Usuario desactivado no puede volver a entrar.
