<?php
/**
 * Tratamientos, inventario de medicamentos y retiro (HU-011)
 * Proyecto: Ganaderia El Rosario
 */
require_once __DIR__ . '/alertas_lib.php';

/**
 * Registra un tratamiento completo:
 *  - toma dias de retiro del medicamento (o el valor indicado por el veterinario)
 *  - con varias dosis (ej. 5 dosis cada 24 h) descuenta TODAS del inventario de una vez:
 *    quedan reservadas para este bovino; la primera se marca aplicada y las demas pendientes
 *  - calcula el costo (cantidad total * precio unitario del inventario)
 *  - si el stock queda en/bajo el minimo avisa a veterinario y administrador
 *
 * @return int id del tratamiento
 * @throws Exception si no hay stock suficiente o faltan datos
 */
function registrarTratamiento($bovino_id, $medicamento_id, $dosis, $via, $diagnostico,
                              $observaciones, $usuario_id, $dias_retiro = null,
                              $num_dosis = 1, $intervalo_horas = 24) {
    $db = Database::getInstance()->getConnection();

    $dosis = (float)$dosis;
    $num_dosis = max(1, min(60, (int)$num_dosis));
    $intervalo_horas = max(1, (int)$intervalo_horas);
    $total = round($dosis * $num_dosis, 2);
    if ($dosis <= 0) {
        throw new Exception('La dosis debe ser mayor a 0');
    }
    if (trim($diagnostico) === '') {
        throw new Exception('El diagnostico es obligatorio');
    }

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();

    try {
        $stmt = $db->prepare("SELECT id, id_siniiga, estado FROM bovinos WHERE id = ? FOR UPDATE");
        $stmt->execute([$bovino_id]);
        $bov = $stmt->fetch();
        if (!$bov) throw new Exception('Bovino no encontrado');
        if (in_array($bov['estado'], ['vendido', 'muerto', 'devuelto'], true)) {
            throw new Exception('El bovino ya no esta en el rancho (' . $bov['estado'] . ')');
        }

        $stmt = $db->prepare("SELECT * FROM medicamentos WHERE id = ? AND activo = 1");
        $stmt->execute([$medicamento_id]);
        $med = $stmt->fetch();
        if (!$med) throw new Exception('Medicamento no valido');

        $stmt = $db->prepare("SELECT * FROM inventario_medicamentos WHERE medicamento_id = ? FOR UPDATE");
        $stmt->execute([$medicamento_id]);
        $inv = $stmt->fetch();
        if (!$inv) {
            throw new Exception("{$med['nombre']} no esta en el inventario. Registre primero una entrada.");
        }
        if ((float)$inv['stock_actual'] < $total) {
            throw new Exception("Stock insuficiente de {$med['nombre']}: hay "
                . number_format($inv['stock_actual'], 2) . " {$inv['unidad']} y el tratamiento necesita "
                . number_format($total, 2) . ($num_dosis > 1 ? " ($num_dosis dosis de " . number_format($dosis, 2) . ")" : ''));
        }

        $dias = $dias_retiro === null || $dias_retiro === '' ? (int)$med['dias_retiro'] : max(0, (int)$dias_retiro);
        // El retiro cuenta desde la ultima dosis programada
        $ultimaDosis = time() + ($num_dosis - 1) * $intervalo_horas * 3600;
        $finRetiro = $dias > 0 ? date('Y-m-d', $ultimaDosis + $dias * 86400) : null;
        $costo = round($total * (float)($inv['precio_unitario'] ?? 0), 2);
        $via = $via !== '' && $via !== null ? $via : ($med['via_administracion'] ?? null);

        $db->prepare("
            INSERT INTO tratamientos
                (bovino_id, medicamento_id, dosis, num_dosis, intervalo_horas, cantidad_total, via_administracion, diagnostico,
                 dias_retiro, fecha_aplicacion, fecha_fin_retiro, costo, veterinario_id, observaciones, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
        ")->execute([$bovino_id, $medicamento_id, $dosis, $num_dosis, $intervalo_horas, $total, $via, $diagnostico,
                     $dias, $finRetiro, $costo, $usuario_id, $observaciones ?: null,
                     $num_dosis > 1 ? 'en_curso' : 'terminado']);
        $tratamiento_id = (int)$db->lastInsertId();

        // Calendario de dosis: la 1a se aplica ahora, las demas quedan pendientes (ya descontadas = reservadas)
        $insDosis = $db->prepare("
            INSERT INTO tratamiento_dosis (tratamiento_id, numero, cantidad, fecha_programada, estado, fecha_aplicada, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        for ($n = 1; $n <= $num_dosis; $n++) {
            $cuando = date('Y-m-d H:i:s', time() + ($n - 1) * $intervalo_horas * 3600);
            $insDosis->execute([$tratamiento_id, $n, $dosis, $cuando,
                                $n === 1 ? 'aplicada' : 'pendiente', $n === 1 ? $cuando : null, $n === 1 ? $usuario_id : null]);
        }

        $db->prepare("UPDATE inventario_medicamentos SET stock_actual = stock_actual - ? WHERE medicamento_id = ?")
           ->execute([$total, $medicamento_id]);

        $db->prepare("
            INSERT INTO movimientos_medicamento
                (medicamento_id, tipo, cantidad, precio_unitario, referencia, bovino_id, usuario_id)
            VALUES (?, 'salida', ?, ?, ?, ?, ?)
        ")->execute([$medicamento_id, $total, $inv['precio_unitario'] ?? 0,
                     'Tratamiento #' . $tratamiento_id . ($num_dosis > 1 ? " ($num_dosis dosis reservadas)" : ''),
                     $bovino_id, $usuario_id]);

        $nuevoStock = (float)$inv['stock_actual'] - $total;

        if ($propia) $db->commit();

        // Avisos (fuera de la transaccion: no deben romper el tratamiento)
        if ($nuevoStock <= (float)$inv['stock_minimo']) {
            $msg = "Medicamento bajo: {$med['nombre']} quedan " . number_format($nuevoStock, 1)
                 . " {$inv['unidad']}. Solicite mas al administrador.";
            crearAlerta('medicamento_bajo', $msg, 'veterinario');
            crearAlerta('medicamento_bajo', $msg, 'admin');
        }
        if ($dias > 0) {
            crearAlerta('retiro_activo',
                "{$bov['id_siniiga']} en retiro por {$med['nombre']} hasta " . date('d/m/Y', strtotime($finRetiro)) . ".",
                'admin', $bovino_id);
        }

        return $tratamiento_id;
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Fecha en que el bovino puede venderse (null si no tiene retiro activo) */
function fechaFinRetiro($bovino_id) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT MAX(fecha_fin_retiro) FROM tratamientos
        WHERE bovino_id = ? AND fecha_fin_retiro >= CURDATE()
    ");
    $stmt->execute([$bovino_id]);
    $f = $stmt->fetchColumn();
    return $f ?: null;
}

function tieneRetiroActivo($bovino_id) {
    return fechaFinRetiro($bovino_id) !== null;
}

/** Fragmento SQL: el bovino NO tiene retiro activo (para listas de venta) */
function sqlSinRetiro($alias = 'b') {
    return "NOT EXISTS (SELECT 1 FROM tratamientos tr
                        WHERE tr.bovino_id = {$alias}.id AND tr.fecha_fin_retiro >= CURDATE())";
}

/** Marca una dosis pendiente como aplicada; si ya no quedan, el tratamiento termina */
function aplicarDosis($dosis_id, $usuario_id) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM tratamiento_dosis WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$dosis_id]);
    $d = $stmt->fetch();
    if (!$d) throw new Exception('La dosis ya fue aplicada o cancelada');
    $db->prepare("UPDATE tratamiento_dosis SET estado = 'aplicada', fecha_aplicada = NOW(), usuario_id = ? WHERE id = ?")
       ->execute([$usuario_id, $dosis_id]);
    $db->prepare("
        UPDATE tratamientos SET estado = 'terminado'
        WHERE id = ? AND NOT EXISTS (SELECT 1 FROM tratamiento_dosis WHERE tratamiento_id = ? AND estado = 'pendiente')
    ")->execute([$d['tratamiento_id'], $d['tratamiento_id']]);
    return $d;
}

/**
 * Cancela las dosis que faltan de un tratamiento y regresa ese medicamento reservado al stock
 * (ej. el animal se recupero antes). El costo y el retiro se ajustan a lo realmente aplicado.
 */
function cancelarDosisPendientes($tratamiento_id, $usuario_id, $motivo = '') {
    $db = Database::getInstance()->getConnection();
    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM tratamientos WHERE id = ? FOR UPDATE");
        $stmt->execute([$tratamiento_id]);
        $t = $stmt->fetch();
        if (!$t) throw new Exception('Tratamiento no encontrado');

        $stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(cantidad), 0) FROM tratamiento_dosis WHERE tratamiento_id = ? AND estado = 'pendiente'");
        $stmt->execute([$tratamiento_id]);
        [$n, $devolver] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int)$n === 0) throw new Exception('Este tratamiento ya no tiene dosis pendientes');

        $db->prepare("UPDATE tratamiento_dosis SET estado = 'cancelada', usuario_id = ? WHERE tratamiento_id = ? AND estado = 'pendiente'")
           ->execute([$usuario_id, $tratamiento_id]);
        $db->prepare("UPDATE inventario_medicamentos SET stock_actual = stock_actual + ? WHERE medicamento_id = ?")
           ->execute([$devolver, $t['medicamento_id']]);
        $stmt = $db->prepare("SELECT precio_unitario FROM inventario_medicamentos WHERE medicamento_id = ?");
        $stmt->execute([$t['medicamento_id']]);
        $precio = (float)$stmt->fetchColumn();
        $db->prepare("
            INSERT INTO movimientos_medicamento (medicamento_id, tipo, cantidad, precio_unitario, referencia, bovino_id, usuario_id)
            VALUES (?, 'entrada', ?, ?, ?, ?, ?)
        ")->execute([$t['medicamento_id'], $devolver, $precio,
                     "Devolucion de $n dosis no aplicadas, tratamiento #$tratamiento_id", $t['bovino_id'], $usuario_id]);

        // Retiro desde la ultima dosis realmente aplicada
        $stmt = $db->prepare("SELECT MAX(fecha_aplicada) FROM tratamiento_dosis WHERE tratamiento_id = ? AND estado = 'aplicada'");
        $stmt->execute([$tratamiento_id]);
        $ultima = $stmt->fetchColumn();
        $finRetiro = $t['dias_retiro'] > 0 && $ultima ? date('Y-m-d', strtotime($ultima) + $t['dias_retiro'] * 86400) : null;
        $db->prepare("
            UPDATE tratamientos
            SET estado = 'cancelado', costo = GREATEST(costo - ?, 0), fecha_fin_retiro = ?,
                observaciones = CONCAT_WS(' | ', observaciones, ?)
            WHERE id = ?
        ")->execute([round($devolver * $precio, 2), $finRetiro,
                     "Cancelado: $n dosis devueltas al inventario" . ($motivo ? " ($motivo)" : ''), $tratamiento_id]);
        if ($propia) $db->commit();
        return [(int)$n, (float)$devolver];
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Medicamento apartado en dosis pendientes, por medicamento_id */
function medicamentoReservado() {
    $db = Database::getInstance()->getConnection();
    return $db->query("
        SELECT t.medicamento_id, SUM(d.cantidad)
        FROM tratamiento_dosis d JOIN tratamientos t ON t.id = d.tratamiento_id
        WHERE d.estado = 'pendiente' GROUP BY t.medicamento_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}
