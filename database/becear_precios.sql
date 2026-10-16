-- ============================================================
-- TABLA DE PRECIOS BECEMAR
-- ============================================================

USE Ganaderia_Rosario;

DROP TABLE IF EXISTS precios_becear;

CREATE TABLE precios_becear (
    id INT(11) NOT NULL AUTO_INCREMENT,
    categoria ENUM('MACHOS_ENTEROS','HEMBRAS') NOT NULL,
    peso_min DECIMAL(6,2) NOT NULL,
    peso_max DECIMAL(6,2) NOT NULL,
    precio_kg DECIMAL(8,2) NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_categoria (categoria),
    INDEX idx_peso (peso_min, peso_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Datos BECEMAR (precios vigentes)
INSERT INTO precios_becear (categoria, peso_min, peso_max, precio_kg) VALUES
-- MACHOS ENTEROS
('MACHOS_ENTEROS', 120.00, 170.00, 92.00),
('MACHOS_ENTEROS', 171.00, 200.00, 90.00),
('MACHOS_ENTEROS', 201.00, 230.00, 86.00),
('MACHOS_ENTEROS', 231.00, 250.00, 83.00),
('MACHOS_ENTEROS', 251.00, 300.00, 78.00),
('MACHOS_ENTEROS', 301.00, 350.00, 0.00),  -- Sin precio público
-- HEMBRAS
('HEMBRAS', 120.00, 200.00, 84.00),
('HEMBRAS', 201.00, 230.00, 81.00),
('HEMBRAS', 231.00, 250.00, 78.00),
('HEMBRAS', 251.00, 300.00, 78.00),
('HEMBRAS', 301.00, 350.00, 0.00);  -- Sin precio público

-- Verificar
SELECT * FROM precios_becear ORDER BY categoria, peso_min;
