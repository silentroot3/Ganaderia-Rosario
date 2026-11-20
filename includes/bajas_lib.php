<?php
/**
 * Salidas de bovinos que no son venta: muerte dentro del rancho.
 * Tambien libera el medicamento apartado cuando un animal deja el rancho (venta o muerte).
 */

require_once __DIR__ . '/tratamientos_lib.php';
require_once __DIR__ . '/costos.php';

const CAUSAS_MUERTE = ['Enfermedad respiratoria', 'Problema digestivo / timpanismo', 'Accidente o lesion', 'Complicacion de tratamiento',
                       'Intoxicacion', 'Causa desconocida', 'Otra'];

/** Cancela las dosis pendientes de todos los tratamientos del bovino; regresa al stock lo apartado */
function cancelarDosisDeBovino($db, $bovino_id, $usuario_id, $motivo) {
    $stmt = $db->prepare("
        SELECT DISTINCT t.id FROM tratamientos t
        JOIN tratamiento_dosis d ON d.tratamiento_id = t.id AND d.estado = 'pendiente'
        WHERE t.bovino_id = ?
    ");
    $stmt->execute([$bovino_id]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        [$canceladas] = cancelarDosisPendientes((int)$tid, $usuario_id, $motivo);
        $n += $canceladas;
    }
    return $n;
}

/** Dosis pendientes por bovino (para avisar antes de vender) */
function dosisPendientesPorBovino($db) {
    return $db->query("
        SELECT t.bovino_id, COUNT(*) FROM tratamiento_dosis d JOIN tratamientos t ON t.id = d.tratamiento_id
        WHERE d.estado = 'pendiente' GROUP BY t.bovino_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Registra la muerte de un bovino que estaba en el rancho:
 * lo da de baja, libera su corral, cancela sus dosis pendientes y deja el historial.
 * @return array ['costo' => lo invertido (perdida), 'dosis' => dosis canceladas]
 */
function registrarMuerte($db, $bovino_id, $fecha, $causa, $observaciones, $usuario_id) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) throw new Exception('Fecha invalida');
    if (!in_array($causa, CAUSAS_MUERTE, true)) throw new Exception('Elige la causa probable');

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM bovinos WHERE id = ? FOR UPDATE");
        $stmt->execute([$bovino_id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new Exception('Bovino no encontrado');
        if (in_array($b['estado'], ['vendido', 'muerto', 'devuelto'], true)) {
            throw new Exception("{$b['id_siniiga']} ya no esta en el rancho ({$b['estado']})");
        }

        $dosis = cancelarDosisDeBovino($db, $bovino_id, $usuario_id, 'Bovino muerto');

        $db->prepare("INSERT INTO mortalidad (bovino_id, fecha, causa_probable, observaciones, usuario_id) VALUES (?, ?, ?, ?, ?)")
           ->execute([$bovino_id, $fecha, $causa, $observaciones ?: null, $usuario_id]);
        $db->prepare("
            UPDATE bovinos SET estado = 'muerto', corral_id = NULL, en_hospital = 0,
                   fecha_baja = ?, motivo_baja = ?, observaciones_baja = ?
            WHERE id = ?
        ")->execute([$fecha . ' ' . date('H:i:s'), $causa, $observaciones ?: null, $bovino_id]);
        if ($b['corral_id']) {
            $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")->execute([$b['corral_id']]);
        }
        $db->prepare("
            INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, ?, NULL, 'muerte', ?, ?, ?)
        ")->execute([$bovino_id, $b['corral_id'], $causa, $usuario_id, $fecha . ' ' . date('H:i:s')]);

        if ($propia) $db->commit();
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['costo' => costoBovino($bovino_id)['total'], 'dosis' => $dosis, 'arete' => $b['id_siniiga']];
}

/** Perdidas por mortalidad en el rango: animales muertos dentro del rancho y lo que se habia invertido en ellos */
function perdidasMortalidad($desde, $hasta) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT m.*, b.id_siniiga, b.categoria FROM mortalidad m JOIN bovinos b ON b.id = m.bovino_id
        WHERE m.fecha BETWEEN ? AND ? ORDER BY m.fecha
    ");
    $stmt->execute([$desde, $hasta]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $costos = costosBovinos(array_map(fn($f) => (int)$f['bovino_id'], $filas));
    foreach ($filas as &$f) $f['costo'] = $costos[(int)$f['bovino_id']]['total'] ?? 0;
    unset($f);
    return $filas;
}
