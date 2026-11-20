-- ============================================================
-- Dietas por etapa (Inicio / Desarrollo / Engorda), 2 comidas al dia,
-- mezclas preparadas por tonelada con regreso de sobrante al inventario,
-- prefijo de la ganaderia para facturas.
-- ============================================================

-- 1) Solo 3 dietas, una por etapa de alimentacion
UPDATE dietas SET nombre = 'Inicio',     etapa_id = 1, categorias = 'Sin clasificar,Becerro,Becerra',
       descripcion = 'Recien llegados, becerros y becerras: mas forraje para adaptarse' WHERE id = 1;
UPDATE dietas SET nombre = 'Desarrollo', etapa_id = 2, categorias = 'Torete,Vaquilla',
       descripcion = 'Toretes y vaquillas en crecimiento' WHERE id = 2;
UPDATE dietas SET nombre = 'Engorda',    etapa_id = 3, categorias = 'Novillo,Toro,Vaca',
       descripcion = 'Engorda final: alta energia previa a la venta' WHERE id = 3;
UPDATE dietas SET activo = 0 WHERE id NOT IN (1, 2, 3);
UPDATE dietas SET comidas_dia = 2;
UPDATE corral_dietas SET activa = 0 WHERE dieta_id NOT IN (1, 2, 3);

-- 2) Mezcla preparada por toneladas: los ingredientes salen de bodega al preparar,
--    las comidas se sirven de la mezcla y lo que sobra regresa a la bodega al cerrarla
CREATE TABLE mezclas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dieta_id INT NOT NULL,
    kg_preparados DECIMAL(10,2) NOT NULL,
    kg_servidos DECIMAL(10,2) NOT NULL DEFAULT 0,
    kg_devueltos DECIMAL(10,2) NOT NULL DEFAULT 0,
    estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
    usuario_id INT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    cerrada_por INT NULL,
    fecha_cierre DATETIME NULL,
    KEY idx_abiertas (estado, dieta_id),
    CONSTRAINT mezcla_dieta_fk FOREIGN KEY (dieta_id) REFERENCES dietas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mezcla_ingredientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mezcla_id INT NOT NULL,
    ingrediente_id INT NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    kg DECIMAL(10,2) NOT NULL,
    CONSTRAINT mezing_mezcla_fk FOREIGN KEY (mezcla_id) REFERENCES mezclas (id) ON DELETE CASCADE,
    CONSTRAINT mezing_ingrediente_fk FOREIGN KEY (ingrediente_id) REFERENCES ingredientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE alimentaciones ADD COLUMN mezcla_id INT NULL AFTER dieta_id;

-- 3) Prefijo de la ganaderia para folios de factura (GER = Ganaderia El Rosario)
INSERT INTO configuracion (clave, valor, descripcion, tipo)
VALUES ('empresa_prefijo', 'GER', 'Prefijo para numeros de factura', 'texto')
ON DUPLICATE KEY UPDATE valor = valor;
