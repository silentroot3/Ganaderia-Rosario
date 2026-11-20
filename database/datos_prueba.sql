-- ============================================================
-- DATOS DE PRUEBA (idempotente) - Ganaderia El Rosario
-- Ejecutar DESPUES de migracion_final.sql. Deja listo el sistema para
-- el recorrido completo de HU-019 (no crea animales: se registran por la compra/recepcion).
--   mariadb -P 3307 -h 127.0.0.1 -u silentroot -p Ganaderia_Rosario < database/datos_prueba.sql
-- ============================================================
USE Ganaderia_Rosario;

-- 1. Stock y precios de medicamentos (por unidad: ml)
UPDATE inventario_medicamentos im
JOIN medicamentos m ON m.id = im.medicamento_id
SET im.stock_actual   = IF(im.stock_actual = 0, 500, im.stock_actual),
    im.stock_minimo   = IF(im.stock_minimo = 0, 50, im.stock_minimo),
    im.precio_unitario = IF(im.precio_unitario = 0,
        CASE m.tipo WHEN 'vacuna' THEN 12.00 WHEN 'antibiotico' THEN 9.50 WHEN 'desparasitante' THEN 4.00 ELSE 3.00 END,
        im.precio_unitario),
    im.fecha_caducidad = COALESCE(im.fecha_caducidad, DATE_ADD(CURDATE(), INTERVAL 18 MONTH));

-- medicamentos del catalogo que no tengan renglon de inventario
INSERT INTO inventario_medicamentos (medicamento_id, stock_actual, stock_minimo, unidad, precio_unitario)
SELECT m.id, 500, 50, 'ml', 5.00 FROM medicamentos m
WHERE NOT EXISTS (SELECT 1 FROM inventario_medicamentos x WHERE x.medicamento_id = m.id);

-- 2. Bodega de alimento
INSERT INTO inventario_alimento (ingrediente_id, stock_actual, unidad, stock_minimo)
SELECT i.id, 5000, 'kg', 800 FROM ingredientes i
WHERE NOT EXISTS (SELECT 1 FROM inventario_alimento x WHERE x.ingrediente_id = i.id);

-- 3. Dietas con receta (porcentajes suman 100)
INSERT INTO dietas (nombre, descripcion, activo)
SELECT 'Adaptacion', 'Primeras semanas: mas forraje', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dietas WHERE nombre = 'Adaptacion');
INSERT INTO dietas (nombre, descripcion, activo)
SELECT 'Crecimiento', 'Etapa media de engorda', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dietas WHERE nombre = 'Crecimiento');
INSERT INTO dietas (nombre, descripcion, activo)
SELECT 'Finalizacion', 'Alta energia previa a venta', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dietas WHERE nombre = 'Finalizacion');

INSERT INTO dieta_ingredientes (dieta_id, ingrediente_id, porcentaje)
SELECT d.id, i.id, r.pct
FROM (
    SELECT 'Adaptacion' dieta, 'Maíz molido' ing, 30 pct UNION ALL SELECT 'Adaptacion','Alfalfa',40 UNION ALL SELECT 'Adaptacion','Soya',10
    UNION ALL SELECT 'Adaptacion','Salvado',15 UNION ALL SELECT 'Adaptacion','Melaza',3 UNION ALL SELECT 'Adaptacion','Minerales',2
    UNION ALL SELECT 'Crecimiento','Maíz molido',45 UNION ALL SELECT 'Crecimiento','Alfalfa',25 UNION ALL SELECT 'Crecimiento','Soya',12
    UNION ALL SELECT 'Crecimiento','Salvado',10 UNION ALL SELECT 'Crecimiento','Melaza',5 UNION ALL SELECT 'Crecimiento','Minerales',3
    UNION ALL SELECT 'Finalizacion','Maíz molido',60 UNION ALL SELECT 'Finalizacion','Alfalfa',12 UNION ALL SELECT 'Finalizacion','Soya',14
    UNION ALL SELECT 'Finalizacion','Salvado',5 UNION ALL SELECT 'Finalizacion','Melaza',6 UNION ALL SELECT 'Finalizacion','Minerales',3
) r
JOIN dietas d ON d.nombre = r.dieta
JOIN ingredientes i ON i.nombre = r.ing
WHERE NOT EXISTS (SELECT 1 FROM dieta_ingredientes x WHERE x.dieta_id = d.id);

-- 4. Compradores de ejemplo
INSERT INTO compradores (nombre, rfc, telefono)
SELECT 'Rastro Municipal de Prueba', 'XAXX010101000', '555-000-0001' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM compradores WHERE nombre = 'Rastro Municipal de Prueba');
INSERT INTO compradores (nombre, rfc, telefono)
SELECT 'Carnes del Norte SA', 'XEXX010101000', '555-000-0002' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM compradores WHERE nombre = 'Carnes del Norte SA');

SELECT 'Datos de prueba cargados' AS Mensaje;
