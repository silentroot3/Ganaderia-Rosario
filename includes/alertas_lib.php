<?php
/**
 * Alertas del sistema (HU-017) y notificaciones entre roles
 * Proyecto: Ganaderia El Rosario
 */

/**
 * Crea una alerta evitando duplicados: si ya existe una alerta SIN LEER del mismo
 * tipo, rol y objetivo (bovino/corral/ingrediente), solo actualiza su mensaje.
 */
function crearAlerta($tipo, $mensaje, $rol_destino = null, $bovino_id = null, $corral_id = null, $ingrediente_id = null) {
    // Una alerta nunca debe tumbar la operacion principal (tratamiento, comida, venta...)
    try {
        return _crearAlerta($tipo, $mensaje, $rol_destino, $bovino_id, $corral_id, $ingrediente_id);
    } catch (Exception $e) {
        error_log('crearAlerta: ' . $e->getMessage() . ' (¿se aplico migracion_final.sql?)');
        return 0;
    }
}

function _crearAlerta($tipo, $mensaje, $rol_destino, $bovino_id, $corral_id, $ingrediente_id) {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT id FROM alertas
        WHERE leida = 0 AND tipo = ?
          AND rol_destino <=> ?
          AND bovino_id <=> ?
          AND corral_id <=> ?
          AND ingrediente_id <=> ?
          AND compra_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$tipo, $rol_destino, $bovino_id, $corral_id, $ingrediente_id]);
    $existente = $stmt->fetchColumn();

    if ($existente) {
        $db->prepare("UPDATE alertas SET mensaje = ? WHERE id = ?")
           ->execute([mb_substr($mensaje, 0, 255), $existente]);
        return (int)$existente;
    }

    $db->prepare("
        INSERT INTO alertas (tipo, mensaje, bovino_id, corral_id, ingrediente_id, rol_destino)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$tipo, mb_substr($mensaje, 0, 255), $bovino_id, $corral_id, $ingrediente_id, $rol_destino]);
    return (int)$db->lastInsertId();
}

/** Lee un valor de la tabla configuracion */
function configValor($clave, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $db = Database::getInstance()->getConnection();
            foreach ($db->query("SELECT clave, valor FROM configuracion")->fetchAll() as $r) {
                $cache[$r['clave']] = $r['valor'];
            }
        } catch (Exception $e) {
            error_log('configValor: ' . $e->getMessage());
        }
    }
    return array_key_exists($clave, $cache) && $cache[$clave] !== null && $cache[$clave] !== ''
        ? $cache[$clave] : $default;
}

/**
 * Revisa el estado del rancho y genera las alertas que correspondan.
 * Se llama desde el dashboard y desde /alertas (maximo una vez cada 5 min por sesion).
 */
function generarAlertasAutomaticas($forzar = false) {
    if (!$forzar && isset($_SESSION['alertas_gen']) && (time() - $_SESSION['alertas_gen']) < 300) {
        return;
    }
    $_SESSION['alertas_gen'] = time();

    $db = Database::getInstance()->getConnection();

    try {
        // 1) Alimento bajo el minimo -> alimentador y admin
        $stmt = $db->query("
            SELECT i.id, i.nombre, inv.stock_actual, inv.stock_minimo, inv.unidad
            FROM inventario_alimento inv
            INNER JOIN ingredientes i ON i.id = inv.ingrediente_id
            WHERE i.activo = 1 AND inv.stock_actual <= inv.stock_minimo
        ");
        foreach ($stmt->fetchAll() as $r) {
            $msg = "Stock bajo de {$r['nombre']}: quedan " . number_format($r['stock_actual'], 1)
                 . " {$r['unidad']} (minimo " . number_format($r['stock_minimo'], 1) . "). Solicite mas.";
            crearAlerta('stock_bajo', $msg, 'alimentador', null, null, $r['id']);
            crearAlerta('stock_bajo', $msg, 'admin', null, null, $r['id']);
        }

        // 2) Medicamentos bajo el minimo o por caducar -> veterinario y admin
        $diasCad = (int)configValor('alerta_dias_caducidad', 30);
        $stmt = $db->query("
            SELECT m.id, m.nombre, inv.stock_actual, inv.stock_minimo, inv.unidad, inv.fecha_caducidad
            FROM inventario_medicamentos inv
            INNER JOIN medicamentos m ON m.id = inv.medicamento_id
            WHERE m.activo = 1
        ");
        foreach ($stmt->fetchAll() as $r) {
            if ($r['stock_actual'] <= $r['stock_minimo']) {
                $msg = "Medicamento bajo: {$r['nombre']} quedan " . number_format($r['stock_actual'], 1)
                     . " {$r['unidad']} (minimo " . number_format($r['stock_minimo'], 1) . ").";
                crearAlerta('medicamento_bajo', $msg, 'veterinario');
                crearAlerta('medicamento_bajo', $msg, 'admin');
            }
            if ($r['fecha_caducidad'] && strtotime($r['fecha_caducidad']) <= strtotime("+{$diasCad} days")) {
                $msg = "Medicamento {$r['nombre']} caduca el " . date('d/m/Y', strtotime($r['fecha_caducidad'])) . ".";
                crearAlerta('medicamento_caducado', $msg, 'veterinario');
            }
        }

        // 3) Enfermos (hospital) sin medicina despues de 24 h -> veterinario (urgente)
        $horas = max(1, (int)configValor('alerta_dias_sin_medicina', 1)) * 24;
        $stmt = $db->prepare("
            SELECT b.id, b.id_siniiga
            FROM bovinos b
            WHERE b.en_hospital = 1
              AND b.fecha_entrada_hospital IS NOT NULL
              AND b.fecha_entrada_hospital < (NOW() - INTERVAL ? HOUR)
              AND NOT EXISTS (
                    SELECT 1 FROM tratamientos t
                    WHERE t.bovino_id = b.id AND t.fecha_aplicacion >= b.fecha_entrada_hospital)
        ");
        $stmt->execute([$horas]);
        foreach ($stmt->fetchAll() as $r) {
            crearAlerta('animal_enfermo',
                "URGENTE: {$r['id_siniiga']} lleva mas de {$horas} h en hospital sin medicina.",
                'veterinario', $r['id']);
        }

        // 4) Corrales con bovinos sin pesar -> clasificador
        $dias = (int)configValor('alerta_dias_sin_pesar', 14);
        $stmt = $db->prepare("
            SELECT c.id, c.nombre, COUNT(*) AS n
            FROM bovinos b
            INNER JOIN corrales c ON c.id = b.corral_id
            WHERE b.estado = 'en_corral' AND c.es_hospital = 0
              AND COALESCE((SELECT MAX(p.fecha) FROM pesajes p WHERE p.bovino_id = b.id), DATE(b.created_at))
                  < (CURDATE() - INTERVAL ? DAY)
            GROUP BY c.id, c.nombre
        ");
        $stmt->execute([$dias]);
        foreach ($stmt->fetchAll() as $r) {
            crearAlerta('animal_sin_pesar',
                "Corral {$r['nombre']}: {$r['n']} bovino(s) sin pesar en {$dias}+ dias.",
                'clasificador', null, $r['id']);
        }

        // 5) Vacunas proximas -> veterinario
        $diasVac = (int)configValor('alerta_dias_vacuna', 3);
        $stmt = $db->prepare("
            SELECT v.bovino_id, b.id_siniiga, m.nombre, v.proxima_dosis
            FROM vacunaciones v
            INNER JOIN bovinos b ON b.id = v.bovino_id
            INNER JOIN medicamentos m ON m.id = v.medicamento_id
            WHERE v.proxima_dosis IS NOT NULL
              AND v.proxima_dosis BETWEEN CURDATE() AND (CURDATE() + INTERVAL ? DAY)
              AND b.estado NOT IN ('vendido','muerto','devuelto')
        ");
        $stmt->execute([$diasVac]);
        foreach ($stmt->fetchAll() as $r) {
            crearAlerta('vacuna_proxima',
                "{$r['id_siniiga']}: {$r['nombre']} toca el " . date('d/m/Y', strtotime($r['proxima_dosis'])) . ".",
                'veterinario', $r['bovino_id']);
        }

        // 6) Corrales llenos -> clasificador
        $stmt = $db->query("
            SELECT id, nombre FROM corrales
            WHERE es_hospital = 0 AND es_venta = 0 AND estado != 'Mantenimiento'
              AND capacidad_maxima > 0 AND ocupacion_actual >= capacidad_maxima
        ");
        foreach ($stmt->fetchAll() as $r) {
            crearAlerta('corral_lleno', "El corral {$r['nombre']} esta lleno.", 'clasificador', null, $r['id']);
        }
    } catch (Exception $e) {
        error_log('generarAlertasAutomaticas: ' . $e->getMessage());
    }
}
