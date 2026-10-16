-- ============================================================
-- MÓDULO: BAJAS EN RECEPCIÓN
-- ============================================================

USE Ganaderia_Rosario;

-- 1. Modificar tabla recepciones
ALTER TABLE recepciones
    ADD COLUMN IF NOT EXISTS cantidad_llegada INT(11) DEFAULT 0 AFTER cantidad_animales,
    ADD COLUMN IF NOT EXISTS cantidad_recibida INT(11) DEFAULT 0 AFTER cantidad_llegada,
    ADD COLUMN IF NOT EXISTS cantidad_bajas INT(11) DEFAULT 0 AFTER cantidad_recibida,
    ADD COLUMN IF NOT EXISTS peso_total_recibido DECIMAL(10,2) DEFAULT 0 AFTER cantidad_bajas;

-- 2. Crear tabla bajas_recepcion
CREATE TABLE IF NOT EXISTS bajas_recepcion (
    id INT(11) NOT NULL AUTO_INCREMENT,
    recepcion_id INT(11) NOT NULL,
    tipo ENUM('muerte','devolucion','rechazo') NOT NULL,
    cantidad INT(11) NOT NULL DEFAULT 1,
    motivo VARCHAR(255) DEFAULT NULL,
    observaciones TEXT DEFAULT NULL,
    usuario_id INT(11) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recepcion (recepcion_id),
    KEY idx_tipo (tipo),
    CONSTRAINT bajas_recepcion_fk FOREIGN KEY (recepcion_id) REFERENCES recepciones(id) ON DELETE CASCADE,
    CONSTRAINT bajas_usuario_fk FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- 3. Verificar
SELECT '✅ Módulo de Bajas creado' AS Mensaje;
SHOW COLUMNS FROM recepciones LIKE 'cantidad%';
SHOW TABLES LIKE 'bajas_recepcion';
