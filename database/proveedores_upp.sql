-- Proveedores identificados por UPP (unico por rancho, lo asigna la asociacion ganadera)
ALTER TABLE proveedores
    ADD COLUMN upp VARCHAR(50) NULL AFTER nombre,
    ADD COLUMN localidad VARCHAR(100) NULL AFTER direccion,
    ADD COLUMN municipio VARCHAR(100) NULL AFTER localidad,
    ADD COLUMN estado VARCHAR(50) NULL AFTER municipio,
    ADD UNIQUE KEY uq_proveedores_upp (upp);

ALTER TABLE compras
    ADD COLUMN proveedor_id INT NULL AFTER numero_reemo,
    ADD KEY compras_proveedor_fk (proveedor_id),
    ADD CONSTRAINT compras_proveedor_fk FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE SET NULL;

-- Datos de ejemplo
UPDATE proveedores SET upp = '240351162001', localidad = 'El Rosario',   municipio = 'Rioverde',      estado = 'San Luis Potosí' WHERE id = 1;
UPDATE proveedores SET upp = '240280045012', localidad = 'Los Pinos',    municipio = 'Cárdenas',      estado = 'San Luis Potosí' WHERE id = 2;
UPDATE proveedores SET upp = '240130087003', localidad = 'La Esperanza', municipio = 'Ciudad Valles', estado = 'San Luis Potosí' WHERE id = 3;

INSERT INTO proveedores (nombre, upp, telefono, localidad, municipio, estado) VALUES
    ('Rancho San Isidro',     '240350211045', '487-111-2233', 'San Isidro',  'Rioverde',        'San Luis Potosí'),
    ('Rancho Las Palmas',     '240400078120', '481-222-3344', 'Las Palmas',  'Tamuín',          'San Luis Potosí'),
    ('Ganadería El Mezquite', '280210033007', '831-333-4455', 'El Mezquite', 'El Mante',        'Tamaulipas'),
    ('Rancho Santa Rita',     '240100150066', '444-444-5566', 'Santa Rita',  'Cerritos',        'San Luis Potosí'),
    ('Rancho Los Álamos',     '220090024018', '442-555-6677', 'Los Álamos',  'Jalpan de Serra', 'Querétaro');
