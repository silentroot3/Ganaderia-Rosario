-- ============================================
-- Sprint: precios de venta por corral, descuentos con autorizacion,
-- rol vendedor, dosis por peso, comida 3% repartida 40/60 y control de comidas
-- ============================================

-- Rol vendedor (solo ve precio minimo y recomendado, nunca lo invertido ni la ganancia)
ALTER TABLE usuarios MODIFY rol ENUM('admin','recepcionista','veterinario','clasificador','alimentador','vendedor') DEFAULT 'recepcionista';

-- Precio por kg que el admin fija a cada corral de venta
ALTER TABLE corrales_todos
    ADD COLUMN precio_venta_kg DECIMAL(8,2) NULL AFTER es_recepcion,
    ADD COLUMN precio_fecha DATETIME NULL AFTER precio_venta_kg;

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW corrales AS
SELECT id, nombre, capacidad_maxima, tipo, sexo_permitido, peso_min, peso_max, dimensiones_m2, estado,
       es_hospital, es_venta, es_recepcion, precio_venta_kg, precio_fecha, ocupacion_actual, activo,
       fecha_eliminado, created_at, updated_at
FROM corrales_todos WHERE activo = 1;

-- Dosis segun el peso: dosis_ml por cada dosis_cada_kg kilos (NULL = dosis fija de dosis_ml)
ALTER TABLE medicamentos
    ADD COLUMN dosis_ml DECIMAL(8,2) NULL AFTER dosis_recomendada,
    ADD COLUMN dosis_cada_kg DECIMAL(8,2) NULL AFTER dosis_ml;
UPDATE medicamentos SET dosis_ml = 2,  dosis_cada_kg = NULL WHERE nombre IN ('Vacuna Aftosa', 'Vacuna Rabia');
UPDATE medicamentos SET dosis_ml = 5,  dosis_cada_kg = NULL WHERE nombre IN ('Vacuna Clostridial', 'Vitamina B12', 'Vitamina ADE');
UPDATE medicamentos SET dosis_ml = 10, dosis_cada_kg = NULL WHERE nombre = 'Desparasitante Oral';
UPDATE medicamentos SET dosis_ml = 1,  dosis_cada_kg = 50 WHERE nombre = 'Ivermectina';
UPDATE medicamentos SET dosis_ml = 1,  dosis_cada_kg = 25 WHERE nombre = 'Penicilina';
UPDATE medicamentos SET dosis_ml = 1,  dosis_cada_kg = 20 WHERE nombre IN ('Oxitetraciclina', 'Antibiotico Amplio Espectro');

-- Comida: lo que tocaba servir cuando se registro (para el control de comidas)
ALTER TABLE alimentaciones
    ADD COLUMN kg_esperado DECIMAL(10,2) NULL AFTER cantidad_kg,
    ADD COLUMN peso_corral DECIMAL(10,2) NULL AFTER kg_esperado;

-- 3% del peso de los animales al dia
UPDATE dietas SET porcentaje_peso = 3.00;

INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES
 ('alimento_porcentaje_peso', '3', 'Alimento diario = este % del peso total del corral', 'numero'),
 ('comida_pct_manana', '40', '% del alimento diario que se da en la mañana (el resto en la tarde)', 'numero'),
 ('hora_comida_manana', '07:00', 'Hora de la comida de la mañana', 'texto'),
 ('hora_comida_tarde', '15:00', 'Hora de la comida de la tarde', 'texto'),
 ('tolerancia_comida_min', '60', 'Minutos de tolerancia para considerar la comida a tiempo', 'numero'),
 ('margen_venta', '8', '% que se suma al costo por kg para el precio recomendado', 'numero')
ON DUPLICATE KEY UPDATE clave = clave;
UPDATE configuracion SET valor = '15' WHERE clave = 'alerta_dias_sin_pesar';

-- Solicitudes de descuento del vendedor; el admin autoriza o rechaza
CREATE TABLE IF NOT EXISTS solicitudes_descuento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    cliente VARCHAR(150) NULL,
    cantidad INT NOT NULL,
    precio_recomendado DECIMAL(8,2) NOT NULL,
    precio_minimo DECIMAL(8,2) NOT NULL,
    precio_solicitado DECIMAL(8,2) NOT NULL,
    motivo TEXT NOT NULL,
    estado ENUM('pendiente','aprobada','rechazada','usada') NOT NULL DEFAULT 'pendiente',
    precio_aprobado DECIMAL(8,2) NULL,
    respuesta TEXT NULL,
    admin_id INT NULL,
    fecha_respuesta DATETIME NULL,
    venta_id INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_desc_estado (estado),
    CONSTRAINT sdesc_usuario_fk FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT sdesc_admin_fk FOREIGN KEY (admin_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitud_descuento_bovinos (
    solicitud_id INT NOT NULL,
    bovino_id INT NOT NULL,
    PRIMARY KEY (solicitud_id, bovino_id),
    CONSTRAINT sdb_sol_fk FOREIGN KEY (solicitud_id) REFERENCES solicitudes_descuento (id) ON DELETE CASCADE,
    CONSTRAINT sdb_bov_fk FOREIGN KEY (bovino_id) REFERENCES bovinos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE alertas MODIFY tipo ENUM('stock_bajo','animal_enfermo','animal_sin_pesar','corral_lleno','vacuna_proxima','retiro_activo','compra_pendiente','solicitud_compra','medicamento_bajo','medicamento_caducado','bovinos_por_clasificar','solicitud_descuento','otro') NOT NULL;
