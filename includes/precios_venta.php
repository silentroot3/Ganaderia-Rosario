<?php
/**
 * Precios de venta
 *  - Minimo por kg   = lo invertido en el animal / su peso actual (abajo de esto se pierde)
 *  - Recomendado     = minimo + margen (config margen_venta, 8% por omision), redondeado a 50 centavos arriba
 *  - Corral de venta = todos sus animales cuestan lo mismo por kg: minimo promedio del corral
 *                      (invertido total / peso total) y el precio que fije el admin, o el sugerido
 *  - El vendedor solo ve minimo y recomendado; para vender abajo del recomendado necesita
 *    un descuento autorizado por el admin (solicitudes_descuento).
 */
require_once __DIR__ . '/costos.php';
require_once __DIR__ . '/alertas_lib.php';
require_once __DIR__ . '/asignacion_corrales.php';

function margenVenta() {
    return max(0, (float)configValor('margen_venta', 8));
}

/** Redondea al siguiente multiplo de 0.50 */
function redondearPrecio($p) {
    return ceil(round($p * 2, 4)) / 2;
}

/**
 * Precios de todos los animales en corral (disponibles o no) y resumen de cada corral de venta.
 * @return array ['bovinos' => [id => [...]], 'corrales' => [corral_id => [...]]]
 */
function preciosVenta($db) {
    $filas = $db->query("
        SELECT b.id, b.corral_id, " . sqlPesoActual('b') . " AS peso, c.nombre AS corral, c.es_venta, c.precio_venta_kg
        FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id
        WHERE b.estado = 'en_corral'
    ")->fetchAll(PDO::FETCH_ASSOC);
    $costos = $filas ? costosBovinos(array_map(fn($f) => (int)$f['id'], $filas)) : [];
    $m = margenVenta();

    $corrales = [];
    foreach ($db->query("SELECT id, nombre, precio_venta_kg, precio_fecha, capacidad_maxima FROM corrales WHERE es_venta = 1 ORDER BY nombre") as $c) {
        $corrales[$c['id']] = $c + ['n' => 0, 'peso' => 0.0, 'costo' => 0.0, 'peso_min_animal' => null, 'peso_max_animal' => null];
    }
    foreach ($filas as $f) {
        if (!$f['es_venta'] || !isset($corrales[$f['corral_id']])) continue;
        $c = &$corrales[$f['corral_id']];
        $c['n']++;
        $c['peso'] += (float)$f['peso'];
        $c['costo'] += $costos[$f['id']]['total'] ?? 0;
        $c['peso_min_animal'] = $c['peso_min_animal'] === null ? (float)$f['peso'] : min($c['peso_min_animal'], (float)$f['peso']);
        $c['peso_max_animal'] = $c['peso_max_animal'] === null ? (float)$f['peso'] : max($c['peso_max_animal'], (float)$f['peso']);
        unset($c);
    }
    foreach ($corrales as &$c) {
        $c['minimo'] = $c['peso'] > 0 ? round($c['costo'] / $c['peso'], 2) : 0;
        $c['sugerido'] = $c['minimo'] > 0 ? redondearPrecio($c['minimo'] * (1 + $m / 100)) : 0;
        $c['fijo'] = $c['precio_venta_kg'] !== null ? (float)$c['precio_venta_kg'] : null;
        $c['recomendado'] = $c['fijo'] ?? $c['sugerido'];
    }
    unset($c);

    $bovinos = [];
    foreach ($filas as $f) {
        $costo = $costos[$f['id']]['total'] ?? 0;
        $peso = (float)$f['peso'];
        if ($f['es_venta'] && isset($corrales[$f['corral_id']])) {
            $c = $corrales[$f['corral_id']];
            $min = $c['minimo']; $rec = $c['recomendado'];
        } else {
            $min = $peso > 0 ? round($costo / $peso, 2) : 0;
            $rec = $min > 0 ? redondearPrecio($min * (1 + $m / 100)) : 0;
        }
        $bovinos[$f['id']] = ['minimo' => $min, 'recomendado' => $rec, 'peso' => $peso, 'costo' => $costo,
                              'corral_id' => $f['corral_id'], 'corral' => $f['corral'], 'es_venta' => (int)$f['es_venta']];
    }
    return ['bovinos' => $bovinos, 'corrales' => $corrales];
}

/** Descuentos autorizados que aun no se usan (del vendedor, o todos si es admin), con sus bovinos */
function descuentosAprobados($db, $usuario_id, $esAdmin) {
    $sql = "SELECT s.*, u.nombre AS vendedor FROM solicitudes_descuento s JOIN usuarios u ON u.id = s.usuario_id
            WHERE s.estado = 'aprobada'" . ($esAdmin ? '' : ' AND s.usuario_id = ' . (int)$usuario_id) . " ORDER BY s.id DESC";
    $out = [];
    foreach ($db->query($sql) as $s) { $s['bovinos'] = []; $out[$s['id']] = $s; }
    if ($out) {
        $ids = implode(',', array_keys($out));
        foreach ($db->query("SELECT solicitud_id, bovino_id FROM solicitud_descuento_bovinos WHERE solicitud_id IN ($ids)") as $r) {
            $out[$r['solicitud_id']]['bovinos'][] = (int)$r['bovino_id'];
        }
    }
    return $out;
}
