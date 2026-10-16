-- ============================================================
-- MIGRACION: Estados de bovinos con bajas individuales
-- ============================================================

USE Ganaderia_Rosario;

-- 1. Ampliar ENUM de estado en bovinos
ALTER TABLE bovinos
    MODIFY COLUMN estado ENUM(
        'recibido',
        'revision',
        'apto',
        'observacion',
        'rechazado',
        'clasificado',
        'en_corral',
        'en_cuarentena',
        'vendido',
        'muerto',
        'devuelto'
    ) DEFAULT 'recibido';

-- 2. Agregar campos de baja al bovino
ALTER TABLE bovinos
    ADD COLUMN IF NOT EXISTS motivo_baja VARCHAR(255) DEFAULT NULL AFTER fecha_salida,
    ADD COLUMN IF NOT EXISTS observaciones_baja TEXT DEFAULT NULL AFTER motivo_baja,
    ADD COLUMN IF NOT EXISTS fecha_baja DATETIME DEFAULT NULL AFTER observaciones_baja;

-- 3. Indice para filtrar por estado
ALTER TABLE bovinos
    ADD INDEX IF NOT EXISTS idx_estado_bovino (estado);

-- 4. Verificar
SELECT 'Migracion completada' AS Mensaje;

SHOW COLUMNS FROM bovinos WHERE Field IN ('estado', 'motivo_baja', 'observaciones_baja', 'fecha_baja');
