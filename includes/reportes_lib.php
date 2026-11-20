<?php
/**
 * Datos de reportes de ventas y rentabilidad (HU-015)
 */
require_once __DIR__ . '/costos.php';
require_once __DIR__ . '/bajas_lib.php';

function normalizarRango($desde, $hasta) {
    $ok = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f);
    if (!$ok($desde)) $desde = date('Y-m-01');
    if (!$ok($hasta)) $hasta = date('Y-m-d');
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
    return [$desde, $hasta];
}

/** Una fila por animal vendido en el rango (ventas activas) con ingreso, costo y ganancia */
function datosRentabilidad($desde, $hasta) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT v.id AS venta_id, v.folio, v.fecha, c.nombre AS comprador,
               dv.bovino_id, dv.peso_final, dv.precio_kg, dv.subtotal,
               b.id_siniiga, b.categoria, b.peso_entrada
        FROM ventas v
        INNER JOIN detalle_ventas dv ON dv.venta_id = v.id
        INNER JOIN bovinos b ON b.id = dv.bovino_id
        INNER JOIN compradores c ON c.id = v.comprador_id
        WHERE v.estado = 'activa' AND v.fecha BETWEEN ? AND ?
        ORDER BY v.fecha, v.id, b.id_siniiga
    ");
    $stmt->execute([$desde, $hasta]);
    $filas = $stmt->fetchAll();

    $costos = costosBovinos(array_map(fn($f) => (int)$f['bovino_id'], $filas));
    $tot = ['cabezas' => 0, 'kilos' => 0, 'ingreso' => 0, 'compra' => 0, 'alimentacion' => 0, 'veterinario' => 0, 'otros' => 0, 'costo' => 0, 'ganancia' => 0];
    $meses = [];

    foreach ($filas as &$f) {
        $c = $costos[(int)$f['bovino_id']] ?? ['compra'=>0,'alimentacion'=>0,'veterinario'=>0,'otros'=>0,'total'=>0];
        $f['c_compra'] = $c['compra']; $f['c_alimentacion'] = $c['alimentacion'];
        $f['c_veterinario'] = $c['veterinario']; $f['c_otros'] = $c['otros'];
        $f['costo'] = $c['total'];
        $f['ganancia'] = round((float)$f['subtotal'] - $c['total'], 2);
        $f['margen'] = $c['total'] > 0 ? $f['ganancia'] / $c['total'] * 100 : null;

        $tot['cabezas']++; $tot['kilos'] += (float)$f['peso_final']; $tot['ingreso'] += (float)$f['subtotal'];
        $tot['compra'] += $c['compra']; $tot['alimentacion'] += $c['alimentacion'];
        $tot['veterinario'] += $c['veterinario']; $tot['otros'] += $c['otros'];
        $tot['costo'] += $c['total']; $tot['ganancia'] += $f['ganancia'];

        $m = substr($f['fecha'], 0, 7);
        $meses[$m] = $meses[$m] ?? ['ingreso' => 0, 'ganancia' => 0, 'cabezas' => 0];
        $meses[$m]['ingreso'] += (float)$f['subtotal'];
        $meses[$m]['ganancia'] += $f['ganancia'];
        $meses[$m]['cabezas']++;
    }
    unset($f);

    // Los gastos generales ya van repartidos en el costo de cada animal; solo quedan fuera
    // los de dias en que no habia ningun animal en el rancho
    $generales = gastosGeneralesSinRepartir($desde, $hasta);
    // Animales que murieron en el rancho: todo lo invertido en ellos es perdida
    $muertes = perdidasMortalidad($desde, $hasta);
    $perdidas = array_sum(array_column($muertes, 'costo'));
    ksort($meses);
    return ['filas' => $filas, 'totales' => $tot, 'meses' => $meses, 'generales' => $generales,
            'muertes' => $muertes, 'perdidas' => $perdidas,
            'neta' => $tot['ganancia'] - $generales - $perdidas];
}

/** Resumen de ventas (por venta) en el rango */
function datosVentas($desde, $hasta) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT v.folio, v.fecha, c.nombre AS comprador, v.subtotal, v.iva, v.total, v.factura,
               (SELECT COUNT(*) FROM detalle_ventas d WHERE d.venta_id = v.id) AS cabezas,
               (SELECT COALESCE(SUM(d.peso_final),0) FROM detalle_ventas d WHERE d.venta_id = v.id) AS kilos
        FROM ventas v INNER JOIN compradores c ON c.id = v.comprador_id
        WHERE v.estado = 'activa' AND v.fecha BETWEEN ? AND ?
        ORDER BY v.fecha, v.id
    ");
    $stmt->execute([$desde, $hasta]);
    return $stmt->fetchAll();
}
