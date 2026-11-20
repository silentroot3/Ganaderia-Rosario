-- ============================================================
-- MIGRACION FINAL - Ganaderia El Rosario
-- Ejecutar UNA vez sobre la BD actual (es idempotente):
--   mariadb -P 3307 -h 127.0.0.1 -u silentroot -p Ganaderia_Rosario < database/migracion_final.sql
-- Hacer respaldo antes (./dump.sh)
-- ============================================================

USE Ganaderia_Rosario;

-- 1. Tabla que faltaba (la usa alimentacion/inventario.php y ahora registrar.php)
CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id INT(11) NOT NULL AUTO_INCREMENT,
    ingrediente_id INT(11) NOT NULL,
    tipo ENUM('entrada','salida','ajuste') NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL,
    precio_unitario DECIMAL(10,2) DEFAULT 0,
    proveedor VARCHAR(150) DEFAULT NULL,
    referencia VARCHAR(100) DEFAULT NULL,
    usuario_id INT(11) NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mi_ing (ingrediente_id),
    KEY idx_mi_fecha (fecha),
    CONSTRAINT mi_ing_fk FOREIGN KEY (ingrediente_id) REFERENCES ingredientes (id),
    CONSTRAINT mi_usr_fk FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Movimientos del inventario de medicamentos (entradas / salidas por tratamiento)
CREATE TABLE IF NOT EXISTS movimientos_medicamento (
    id INT(11) NOT NULL AUTO_INCREMENT,
    medicamento_id INT(11) NOT NULL,
    tipo ENUM('entrada','salida','ajuste') NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL,
    precio_unitario DECIMAL(10,2) DEFAULT 0,
    referencia VARCHAR(100) DEFAULT NULL,
    bovino_id INT(11) DEFAULT NULL,
    usuario_id INT(11) NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mm_med (medicamento_id),
    CONSTRAINT mm_med_fk FOREIGN KEY (medicamento_id) REFERENCES medicamentos (id),
    CONSTRAINT mm_usr_fk FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Solicitudes de compra (veterinario / alimentador -> administrador)
CREATE TABLE IF NOT EXISTS solicitudes_compra (
    id INT(11) NOT NULL AUTO_INCREMENT,
    folio VARCHAR(20) NOT NULL,
    tipo ENUM('medicamento','alimento') NOT NULL,
    medicamento_id INT(11) DEFAULT NULL,
    ingrediente_id INT(11) DEFAULT NULL,
    cantidad DECIMAL(10,2) NOT NULL,
    unidad VARCHAR(20) DEFAULT NULL,
    urgencia ENUM('normal','alta','urgente') DEFAULT 'normal',
    motivo VARCHAR(255) DEFAULT NULL,
    estado ENUM('pendiente','aprobada','pedida','recibida','rechazada') DEFAULT 'pendiente',
    proveedor_destino VARCHAR(150) DEFAULT NULL,
    respuesta_admin VARCHAR(255) DEFAULT NULL,
    precio_unitario DECIMAL(10,2) DEFAULT NULL,
    solicitante_id INT(11) NOT NULL,
    resuelto_por INT(11) DEFAULT NULL,
    fecha_resolucion DATETIME DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folio (folio),
    KEY idx_sol_estado (estado),
    CONSTRAINT sol_med_fk FOREIGN KEY (medicamento_id) REFERENCES medicamentos (id),
    CONSTRAINT sol_ing_fk FOREIGN KEY (ingrediente_id) REFERENCES ingredientes (id),
    CONSTRAINT sol_usr_fk FOREIGN KEY (solicitante_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Nuevos tipos de alerta
ALTER TABLE alertas
    MODIFY COLUMN tipo ENUM('stock_bajo','animal_enfermo','animal_sin_pesar','corral_lleno',
        'vacuna_proxima','retiro_activo','compra_pendiente','solicitud_compra',
        'medicamento_bajo','medicamento_caducado','otro') NOT NULL;

-- 5. Inventario de medicamentos: asegurar columnas (por si la BD viene del backup viejo)
ALTER TABLE inventario_medicamentos
    ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(10,2) DEFAULT 0 AFTER stock_actual,
    ADD COLUMN IF NOT EXISTS precio_unitario DECIMAL(10,2) DEFAULT 0 AFTER proveedor;

-- 6. Costo por unidad de dieta / ingredientes (para costo real por bovino)
--    (precio_unitario de ingredientes ya existe; dietas.costo_por_kg se recalcula desde ellos)

-- 7. Un solo registro de inventario por medicamento
ALTER TABLE inventario_medicamentos
    ADD UNIQUE KEY IF NOT EXISTS uq_inv_med (medicamento_id);

-- 8. Indices para trazabilidad y reportes
ALTER TABLE pesajes ADD INDEX IF NOT EXISTS idx_pes_bov_fecha (bovino_id, fecha);
ALTER TABLE movimientos_bovino ADD INDEX IF NOT EXISTS idx_mov_bov_fecha (bovino_id, fecha);
ALTER TABLE alimentaciones ADD INDEX IF NOT EXISTS idx_ali_corral_fecha (corral_id, fecha);
ALTER TABLE tratamientos ADD INDEX IF NOT EXISTS idx_trat_bov (bovino_id, fecha_fin_retiro);
ALTER TABLE detalle_ventas ADD INDEX IF NOT EXISTS idx_dv_bov (bovino_id);

-- 9. Configuracion adicional
INSERT IGNORE INTO configuracion (clave, valor, descripcion, tipo) VALUES
    ('alerta_dias_sin_medicina', '1', 'Dias con animal enfermo sin tratamiento para alerta urgente', 'numero'),
    ('alerta_dias_caducidad', '30', 'Dias antes de caducidad de medicamento para alertar', 'numero');

-- 10. Raza capturada en la compra (la usaba el codigo pero la columna no existia)
ALTER TABLE compra_bovinos ADD COLUMN IF NOT EXISTS raza VARCHAR(50) DEFAULT NULL AFTER sexo;

SELECT 'Migracion final aplicada' AS Mensaje;
