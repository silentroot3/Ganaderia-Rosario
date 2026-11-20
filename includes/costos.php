<?php
/**
 * Calculo de costos y rentabilidad por bovino
 * Proyecto: Ganaderia El Rosario
 *
 * Costo total de un bovino = compra + alimentacion + veterinario + otros
 *
 *  - compra:       precio_por_kg de la compra * peso de entrada
 *                  (si la compra no trae precio por kg: precio_total / cantidad_total)
 *  - alimentacion: cada comida servida a un corral (kg * costo/kg de la dieta)
 *                  se reparte entre los bovinos que estaban en ese corral ese dia,
 *                  reconstruido a partir de movimientos_bovino.
 *  - veterinario:  suma de tratamientos.costo (dosis * precio unitario del inventario)
 *  - otros:        registros manuales de la tabla costos con bovino_id
 *                  + gastos generales del rancho (costos sin bovino_id) repartidos en partes
 *                    iguales entre los animales que estaban en el rancho el dia del gasto
 */

/** Fragmento SQL: el bovino {$b} estaba en el rancho en la fecha {$fecha} */
function sqlPresenteEnFecha($b, $fecha) {
    return "({$b}.estado <> 'devuelto' AND DATE({$b}.created_at) <= {$fecha}
             AND (COALESCE({$b}.fecha_salida, {$b}.fecha_baja) IS NULL OR DATE(COALESCE({$b}.fecha_salida, {$b}.fecha_baja)) >= {$fecha}))";
}

/**
 * Parte de los gastos generales que le toca a cada bovino.
 * @return array [bovino_id => monto]
 */
function costosGeneralesPorBovino($ids = null) {
    $db = Database::getInstance()->getConnection();
    $params = [];
    $filtro = '';
    if (is_array($ids)) {
        if (count($ids) === 0) return [];
        $filtro = 'AND b.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_map('intval', array_values($ids));
    }
    $stmt = $db->prepare("
        SELECT b.id, SUM(g.monto / (SELECT COUNT(*) FROM bovinos b2 WHERE " . sqlPresenteEnFecha('b2', 'g.fecha') . ")) AS monto
        FROM costos g
        JOIN bovinos b ON " . sqlPresenteEnFecha('b', 'g.fecha') . "
        WHERE g.bovino_id IS NULL $filtro
        GROUP BY b.id
    ");
    $stmt->execute($params);
    return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Gastos generales en el rango que no se pudieron repartir (ese dia no habia animales) */
function gastosGeneralesSinRepartir($desde = null, $hasta = null) {
    $db = Database::getInstance()->getConnection();
    $sql = "SELECT COALESCE(SUM(g.monto), 0) FROM costos g
            WHERE g.bovino_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM bovinos b WHERE " . sqlPresenteEnFecha('b', 'g.fecha') . ")";
    $params = [];
    if ($desde && $hasta) { $sql .= " AND g.fecha BETWEEN ? AND ?"; $params = [$desde, $hasta]; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * Costo por kg de cada dieta: usa dietas.costo_por_kg si existe,
 * si no lo calcula con la receta (dieta_ingredientes) y precios de ingredientes.
 * Devuelve el fragmento SQL para usar como columna.
 */
function sqlCostoDietaKg($alias = 'd') {
    return "COALESCE(NULLIF({$alias}.costo_por_kg, 0), (
                SELECT SUM(di.porcentaje / 100 * COALESCE(ing.precio_unitario, 0))
                FROM dieta_ingredientes di
                INNER JOIN ingredientes ing ON ing.id = di.ingrediente_id
                WHERE di.dieta_id = {$alias}.id
            ), 0)";
}

/**
 * Costo de alimentacion repartido por bovino.
 * @param array|null $ids  ids de bovinos (null = todos)
 * @return array [bovino_id => costo]
 */
function costosAlimentacionPorBovino($ids = null) {
    $db = Database::getInstance()->getConnection();
    $params = [];
    $filtro = '';
    if (is_array($ids)) {
        if (count($ids) === 0) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $filtro = "WHERE s.bovino_id IN ($in)";
        $params = array_map('intval', array_values($ids));
    }

    $costoKg = sqlCostoDietaKg('d');

    // "estancias": cada movimiento con corral destino abre una estancia que
    // termina en el siguiente movimiento del animal. Si el siguiente movimiento
    // no tiene corral (venta / muerte) el ultimo dia cuenta completo.
    $sql = "
        WITH estancias AS (
            SELECT m.bovino_id,
                   m.corral_destino_id AS corral_id,
                   DATE(m.fecha) AS desde,
                   (SELECT IF(m2.corral_destino_id IS NULL,
                              DATE_ADD(DATE(m2.fecha), INTERVAL 1 DAY),
                              DATE(m2.fecha))
                    FROM movimientos_bovino m2
                    WHERE m2.bovino_id = m.bovino_id
                      AND (m2.fecha > m.fecha OR (m2.fecha = m.fecha AND m2.id > m.id))
                    ORDER BY m2.fecha, m2.id
                    LIMIT 1) AS hasta
            FROM movimientos_bovino m
            WHERE m.corral_destino_id IS NOT NULL
        )
        SELECT s.bovino_id,
               SUM(
                   a.cantidad_kg * $costoKg /
                   (SELECT COUNT(*) FROM estancias o
                    WHERE o.corral_id = a.corral_id
                      AND a.fecha >= o.desde
                      AND (o.hasta IS NULL OR a.fecha < o.hasta))
               ) AS costo
        FROM estancias s
        INNER JOIN alimentaciones a
                ON a.corral_id = s.corral_id
               AND a.fecha >= s.desde
               AND (s.hasta IS NULL OR a.fecha < s.hasta)
        INNER JOIN dietas d ON d.id = a.dieta_id
        $filtro
        GROUP BY s.bovino_id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['bovino_id']] = (float)$r['costo'];
    }
    return $out;
}

/**
 * Costos completos por bovino.
 * @param array|null $ids ids de bovinos (null = todos)
 * @return array [bovino_id => ['compra','alimentacion','veterinario','otros','generales','total']]
 *         'otros' ya incluye 'generales' (la parte de gastos del rancho que le toca)
 */
function costosBovinos($ids = null) {
    $db = Database::getInstance()->getConnection();
    $params = [];
    $filtro = '';
    if (is_array($ids)) {
        if (count($ids) === 0) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $filtro = "WHERE b.id IN ($in)";
        $params = array_map('intval', array_values($ids));
    }

    $stmt = $db->prepare("
        SELECT b.id,
               CASE
                   WHEN c.precio_por_kg > 0 THEN COALESCE(b.peso_entrada, 0) * c.precio_por_kg
                   WHEN c.cantidad_total > 0 THEN c.precio_total / c.cantidad_total
                   ELSE 0
               END AS compra,
               COALESCE((SELECT SUM(t.costo) FROM tratamientos t WHERE t.bovino_id = b.id), 0) AS veterinario,
               COALESCE((SELECT SUM(co.monto) FROM costos co WHERE co.bovino_id = b.id), 0) AS otros
        FROM bovinos b
        LEFT JOIN compras c ON c.id = b.compra_id
        $filtro
    ");
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    $alim = costosAlimentacionPorBovino($ids);
    $generales = costosGeneralesPorBovino($ids);

    $out = [];
    foreach ($filas as $f) {
        $id = (int)$f['id'];
        $r = [
            'compra'      => round((float)$f['compra'], 2),
            'alimentacion'=> round($alim[$id] ?? 0, 2),
            'veterinario' => round((float)$f['veterinario'], 2),
            'generales'   => round($generales[$id] ?? 0, 2),
        ];
        $r['otros'] = round((float)$f['otros'] + $r['generales'], 2);
        $r['total'] = round($r['compra'] + $r['alimentacion'] + $r['veterinario'] + $r['otros'], 2);
        $out[$id] = $r;
    }
    return $out;
}

/** Costos de un solo bovino */
function costoBovino($bovino_id) {
    $r = costosBovinos([(int)$bovino_id]);
    return $r[(int)$bovino_id] ?? ['compra'=>0,'alimentacion'=>0,'veterinario'=>0,'otros'=>0,'generales'=>0,'total'=>0];
}

/** Ultimo peso conocido (pesajes o peso de entrada) */
function pesoActualBovino($bovino_id) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT peso FROM pesajes WHERE bovino_id = ? ORDER BY fecha DESC, id DESC LIMIT 1");
    $stmt->execute([$bovino_id]);
    $p = $stmt->fetchColumn();
    if ($p !== false && $p !== null) return (float)$p;
    $stmt = $db->prepare("SELECT peso_entrada FROM bovinos WHERE id = ?");
    $stmt->execute([$bovino_id]);
    return (float)$stmt->fetchColumn();
}

function dinero($n) {
    return '$' . number_format((float)$n, 2);
}
