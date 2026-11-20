-- ============================================================
-- Recepcion -> corrales temporales RECEP-1/2 -> clasificacion manual
-- Corrales con borrado logico, bajas con arete, stock minimo de medicamentos
-- ============================================================

-- 1) Corrales: la tabla real pasa a ser corrales_todos y "corrales" es una vista
--    con los activos. Asi todas las consultas existentes ignoran los corrales
--    eliminados sin perder el historial (movimientos, alimentaciones).
ALTER TABLE corrales
    ADD COLUMN es_recepcion TINYINT(1) DEFAULT 0 AFTER es_venta,
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER ocupacion_actual,
    ADD COLUMN fecha_eliminado DATETIME NULL AFTER activo;
RENAME TABLE corrales TO corrales_todos;
CREATE VIEW corrales AS SELECT * FROM corrales_todos WHERE activo = 1;

-- RECEP-1 machos / RECEP-2 hembras: corrales temporales de llegada
UPDATE corrales_todos SET es_recepcion = 1, tipo = 'Machos',  sexo_permitido = 'M' WHERE nombre = 'RECEP-1';
UPDATE corrales_todos SET es_recepcion = 1, tipo = 'Hembras', sexo_permitido = 'H' WHERE nombre = 'RECEP-2';

-- 2) Movimiento de traslado entre corrales (migraciones)
ALTER TABLE movimientos_bovino
    MODIFY tipo_movimiento ENUM('recepcion','clasificacion','hospital','alta','venta','regreso','muerte','traslado') NOT NULL;

-- 3) Bajas de recepcion con el arete del animal
ALTER TABLE bajas_recepcion
    ADD COLUMN compra_bovino_id INT NULL AFTER recepcion_id,
    ADD COLUMN numero_arete VARCHAR(50) NULL AFTER compra_bovino_id;

-- 4) Bovinos recibidos que quedaron sin corral -> RECEP segun sexo, pendientes de clasificar
INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
SELECT b.id, NULL, c.id, 'recepcion', 'Corral temporal de recepcion', (SELECT MIN(id) FROM usuarios WHERE rol = 'admin'), NOW()
FROM bovinos b
JOIN corrales_todos c ON c.es_recepcion = 1 AND c.sexo_permitido = b.sexo
WHERE b.corral_id IS NULL AND b.en_hospital = 0 AND b.estado NOT IN ('vendido','muerto','devuelto');

UPDATE bovinos b
JOIN corrales_todos c ON c.es_recepcion = 1 AND c.sexo_permitido = b.sexo
SET b.corral_id = c.id, b.estado = 'recibido', b.categoria = 'Sin clasificar'
WHERE b.corral_id IS NULL AND b.en_hospital = 0 AND b.estado NOT IN ('vendido','muerto','devuelto');

-- Ocupacion real de cada corral
UPDATE corrales_todos c SET ocupacion_actual = (
    SELECT COUNT(*) FROM bovinos b WHERE b.corral_id = c.id AND b.estado NOT IN ('vendido','muerto','devuelto')
);

-- 5) Stock minimo realista para alertar a tiempo (antes 5 ml)
UPDATE inventario_medicamentos SET stock_minimo = 100 WHERE stock_minimo < 100;

-- 6) Alerta para el clasificador cuando llegan bovinos a RECEP
ALTER TABLE alertas MODIFY tipo ENUM('stock_bajo','animal_enfermo','animal_sin_pesar','corral_lleno','vacuna_proxima','retiro_activo','compra_pendiente','solicitud_compra','medicamento_bajo','medicamento_caducado','bovinos_por_clasificar','otro') NOT NULL;
