-- ============================================================
-- FIX: Convertir categorías viejas a nuevas
-- ============================================================

USE Ganaderia_Rosario;

-- 1. Convertir a VARCHAR temporalmente
ALTER TABLE bovinos
    MODIFY COLUMN categoria VARCHAR(50) DEFAULT NULL;

-- 2. Actualizar categorías según sexo, edad y castrado

-- HEMBRAS
UPDATE bovinos SET categoria = 'Becerra' 
WHERE categoria IN ('Becerro', 'Ternero') AND sexo = 'H';

UPDATE bovinos SET categoria = 'Vaquilla' 
WHERE categoria IN ('Novillo', 'Adulto') 
  AND sexo = 'H' 
  AND (edad_meses IS NULL OR edad_meses < 36);

UPDATE bovinos SET categoria = 'Vaca' 
WHERE categoria IN ('Novillo', 'Adulto') 
  AND sexo = 'H' 
  AND edad_meses >= 36;

-- MACHOS
UPDATE bovinos SET categoria = 'Becerro' 
WHERE categoria IN ('Becerro', 'Ternero') 
  AND sexo = 'M' 
  AND castrado = 0;

UPDATE bovinos SET categoria = 'Novillo' 
WHERE categoria IN ('Becerro', 'Ternero') 
  AND sexo = 'M' 
  AND castrado = 1;

UPDATE bovinos SET categoria = 'Torete' 
WHERE categoria IN ('Novillo', 'Adulto') 
  AND sexo = 'M' 
  AND castrado = 0 
  AND (edad_meses IS NULL OR edad_meses < 36);

UPDATE bovinos SET categoria = 'Toro' 
WHERE categoria IN ('Novillo', 'Adulto') 
  AND sexo = 'M' 
  AND castrado = 0 
  AND edad_meses >= 36;

-- 3. Cualquier categoría vacía o desconocida
UPDATE bovinos SET categoria = 'Sin clasificar' 
WHERE categoria IS NULL 
   OR categoria NOT IN ('Becerro','Becerra','Novillo','Torete','Toro','Vaquilla','Vaca');

-- 4. Ahora convertir a ENUM
ALTER TABLE bovinos
    MODIFY COLUMN categoria ENUM(
        'Becerro',
        'Becerra',
        'Novillo',
        'Torete',
        'Toro',
        'Vaquilla',
        'Vaca',
        'Sin clasificar'
    ) DEFAULT 'Sin clasificar';

-- 5. Verificar
SELECT '✅ Categorías convertidas' AS Mensaje;
SELECT categoria, sexo, COUNT(*) AS total FROM bovinos GROUP BY categoria, sexo ORDER BY categoria;
