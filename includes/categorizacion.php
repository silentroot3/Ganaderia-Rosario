<?php
/**
 * Sistema de Categorización de Bovinos
 * Basado en la tabla de precios BECEMAR
 */


/**
 * Categoriza un bovino según su sexo y peso
 * @param string $sexo  'M' = Macho, 'H' = Hembra
 * @param float  $peso  Peso en kg
 * @return array  Datos de la categoría encontrada
 */


function categorizarBovino($sexo, $peso) {
    $db = Database::getInstance()->getConnection();
    
    // Determinar categoría base por sexo
    $categoriaBase = ($sexo === 'M') ? 'MACHOS_ENTEROS' : 'HEMBRAS';
    
    // Buscar el rango de precio en la BD
    $stmt = $db->prepare("
        SELECT categoria, peso_min, peso_max, precio_kg
        FROM precios_becear
        WHERE categoria = ?
          AND activo = 1
          AND ? BETWEEN peso_min AND peso_max
        ORDER BY peso_min
        LIMIT 1
    ");
    $stmt->execute([$categoriaBase, $peso]);
    $rango = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Subcategoría descriptiva
    $subcategoria = '';
    if ($peso < 200) {
        $subcategoria = 'Becerro';
    } elseif ($peso < 250) {
        $subcategoria = 'Ternero';
    } elseif ($peso < 300) {
        $subcategoria = 'Novillo';
    } else {
        $subcategoria = 'Adulto';
    }
    
    // Género legible
    $genero = ($sexo === 'M') ? 'Macho' : 'Hembra';
    
    return [
        'categoria'      => $categoriaBase,
        'subcategoria'   => $subcategoria,
        'genero'         => $genero,
        'peso'           => $peso,
        'peso_min'       => $rango['peso_min'] ?? null,
        'peso_max'       => $rango['peso_max'] ?? null,
        'precio_kg'      => $rango['precio_kg'] ?? 0,
        'precio_total'   => ($rango['precio_kg'] ?? 0) * $peso,
        'nombre_completo' => $genero . ' ' . $subcategoria
    ];
}

/**
 * Obtiene todos los rangos de precios vigentes
 */
function obtenerTablaPrecios() {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("
        SELECT * FROM precios_becear 
        WHERE activo = 1 
        ORDER BY categoria, peso_min
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula el precio estimado de un bovino
 */

function calcularPrecio($sexo, $peso) {
    $cat = categorizarBovino($sexo, $peso);
    return $cat['precio_total'];
}

/**
 * Genera un código de categoría corto
 * Ejemplo: M-170 (Macho, 170kg)
 */
function codigoCategoria($sexo, $peso) {
    return $sexo . '-' . round($peso);
}
