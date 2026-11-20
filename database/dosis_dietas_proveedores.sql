-- ============================================================
-- Bovinos que llegan enfermos (pasan primero por el clasificador),
-- plan de dosis con reserva de medicamento, dietas por tipo de bovino
-- con racion segun peso del corral, y datos de recepcion de solicitudes.
-- ============================================================

-- 1) Marca permanente de "llego enfermo/herido" (el clasificador lo envia a veterinaria)
ALTER TABLE bovinos
    ADD COLUMN llego_enfermo TINYINT(1) NOT NULL DEFAULT 0 AFTER motivo_hospital,
    ADD COLUMN motivo_llegada VARCHAR(255) NULL AFTER llego_enfermo;
UPDATE bovinos SET llego_enfermo = 1, motivo_llegada = motivo_hospital
WHERE recepcion_id IS NOT NULL AND motivo_hospital IS NOT NULL;

-- 2) Tratamientos con varias dosis: el total se descuenta (reserva) al prescribir
ALTER TABLE tratamientos
    ADD COLUMN num_dosis INT NOT NULL DEFAULT 1 AFTER dosis,
    ADD COLUMN intervalo_horas INT NOT NULL DEFAULT 24 AFTER num_dosis,
    ADD COLUMN cantidad_total DECIMAL(10,2) NULL AFTER intervalo_horas,
    ADD COLUMN estado ENUM('en_curso','terminado','cancelado') NOT NULL DEFAULT 'terminado' AFTER observaciones;
UPDATE tratamientos SET cantidad_total = CAST(dosis AS DECIMAL(10,2)) WHERE cantidad_total IS NULL;

CREATE TABLE tratamiento_dosis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tratamiento_id INT NOT NULL,
    numero INT NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL,
    fecha_programada DATETIME NOT NULL,
    estado ENUM('pendiente','aplicada','cancelada') NOT NULL DEFAULT 'pendiente',
    fecha_aplicada DATETIME NULL,
    usuario_id INT NULL,
    KEY idx_trat (tratamiento_id),
    KEY idx_pendientes (estado, fecha_programada),
    CONSTRAINT tdosis_trat_fk FOREIGN KEY (tratamiento_id) REFERENCES tratamientos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO tratamiento_dosis (tratamiento_id, numero, cantidad, fecha_programada, estado, fecha_aplicada, usuario_id)
SELECT id, 1, cantidad_total, fecha_aplicacion, 'aplicada', fecha_aplicacion, veterinario_id FROM tratamientos;

-- 3) Dietas: tabla que faltaba (corral -> dieta) y racion por peso corporal
CREATE TABLE corral_dietas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    corral_id INT NOT NULL,
    dieta_id INT NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    usuario_id INT NULL,
    fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_corral (corral_id, activa),
    CONSTRAINT cd_corral_fk FOREIGN KEY (corral_id) REFERENCES corrales_todos (id),
    CONSTRAINT cd_dieta_fk FOREIGN KEY (dieta_id) REFERENCES dietas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE dietas
    ADD COLUMN categorias VARCHAR(200) NULL AFTER descripcion,
    ADD COLUMN porcentaje_peso DECIMAL(4,2) NOT NULL DEFAULT 2.50 AFTER categorias,
    ADD COLUMN comidas_dia TINYINT NOT NULL DEFAULT 2 AFTER porcentaje_peso;

-- porcentaje_peso = kg de alimento al dia por cada 100 kg de peso vivo
UPDATE dietas SET etapa_id = 1, porcentaje_peso = 2.50, categorias = 'Sin clasificar,Becerro,Becerra',
       descripcion = 'Recien llegados y becerros: mas forraje para adaptarse' WHERE nombre = 'Adaptacion';
UPDATE dietas SET etapa_id = 2, porcentaje_peso = 2.80, categorias = 'Torete,Vaquilla',
       descripcion = 'Desarrollo: toretes y vaquillas' WHERE nombre = 'Crecimiento';
UPDATE dietas SET etapa_id = 3, porcentaje_peso = 3.00, categorias = 'Novillo,Toro',
       descripcion = 'Engorda final: alta energia previa a la venta' WHERE nombre = 'Finalizacion';

INSERT INTO dietas (nombre, descripcion, categorias, porcentaje_peso, comidas_dia, activo) VALUES
    ('Mantenimiento vacas', 'Vacas adultas: forraje con poco grano', 'Vaca', 2.20, 2, 1),
    ('Hospital', 'Recuperacion en hospital: forraje suave', NULL, 2.00, 2, 1);
INSERT INTO dieta_ingredientes (dieta_id, ingrediente_id, porcentaje)
SELECT d.id, i.id, r.pct FROM (
    SELECT 'Mantenimiento vacas' dieta, 'Alfalfa' ing, 60 pct UNION ALL SELECT 'Mantenimiento vacas', 'Maíz molido', 20
    UNION ALL SELECT 'Mantenimiento vacas', 'Salvado', 15 UNION ALL SELECT 'Mantenimiento vacas', 'Minerales', 3
    UNION ALL SELECT 'Mantenimiento vacas', 'Melaza', 2
    UNION ALL SELECT 'Hospital', 'Alfalfa', 70 UNION ALL SELECT 'Hospital', 'Salvado', 15
    UNION ALL SELECT 'Hospital', 'Maíz molido', 10 UNION ALL SELECT 'Hospital', 'Minerales', 3
    UNION ALL SELECT 'Hospital', 'Melaza', 2
) r JOIN dietas d ON d.nombre = r.dieta JOIN ingredientes i ON i.nombre = r.ing;

-- 4) Solicitudes: lo que realmente se recibio (para la hoja de compra)
ALTER TABLE solicitudes_compra
    ADD COLUMN cantidad_recibida DECIMAL(10,2) NULL AFTER precio_unitario,
    ADD COLUMN fecha_recibida DATETIME NULL AFTER cantidad_recibida,
    ADD COLUMN factura VARCHAR(60) NULL AFTER fecha_recibida;
