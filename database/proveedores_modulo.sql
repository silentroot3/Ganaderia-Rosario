-- ============================================
-- Modulo de proveedores: ranchos (ganado), alimento, medicamento y otros
-- Cada compra / entrada / pedido queda ligado a su proveedor para ver la relacion
-- ============================================
ALTER TABLE proveedores
    ADD COLUMN tipo SET('ganado','alimento','medicamento','otro') NOT NULL DEFAULT 'ganado' AFTER nombre,
    ADD COLUMN contacto VARCHAR(120) NULL AFTER email,
    ADD COLUMN notas TEXT NULL AFTER estado,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE solicitudes_compra ADD COLUMN proveedor_id INT NULL AFTER proveedor_destino,
    ADD CONSTRAINT solc_prov_fk FOREIGN KEY (proveedor_id) REFERENCES proveedores (id);
ALTER TABLE movimientos_inventario ADD COLUMN proveedor_id INT NULL AFTER proveedor,
    ADD CONSTRAINT movinv_prov_fk FOREIGN KEY (proveedor_id) REFERENCES proveedores (id);
ALTER TABLE movimientos_medicamento ADD COLUMN proveedor_id INT NULL AFTER bovino_id,
    ADD CONSTRAINT movmed_prov_fk FOREIGN KEY (proveedor_id) REFERENCES proveedores (id);
