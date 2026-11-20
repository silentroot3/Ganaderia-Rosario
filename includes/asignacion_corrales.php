<?php
/**
 * Sistema de asignación automática de corrales
 * Basado en sexo y rango de peso
 */

function encontrarCorralAdecuado($sexo, $peso, $esHospital = false) {
    $db = Database::getInstance()->getConnection();
    
    if ($esHospital) {
        $stmt = $db->prepare("
            SELECT * FROM corrales
            WHERE es_hospital = 1
              AND estado != 'Mantenimiento'
              AND ocupacion_actual < capacidad_maxima
            ORDER BY ocupacion_actual ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    $stmt = $db->prepare("
        SELECT * FROM corrales
        WHERE es_hospital = 0
          AND es_venta = 0
          AND (sexo_permitido = ? OR sexo_permitido = 'Mixto')
          AND peso_min IS NOT NULL
          AND peso_max IS NOT NULL
          AND ? BETWEEN peso_min AND peso_max
          AND estado != 'Mantenimiento'
          AND ocupacion_actual < capacidad_maxima
        ORDER BY ocupacion_actual ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$sexo, $peso]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function asignarCorral($bovino_id, $corral_id, $usuario_id, $motivo = '', $tipo = 'clasificacion') {
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT corral_id FROM bovinos WHERE id = ?");
        $stmt->execute([$bovino_id]);
        $bovino = $stmt->fetch(PDO::FETCH_ASSOC);
        $corral_origen = $bovino['corral_id'] ?? null;
        
        $stmt = $db->prepare("
            UPDATE bovinos 
            SET corral_id = ?, 
                corral_original_id = IF(corral_original_id IS NULL, corral_id, corral_original_id)
            WHERE id = ?
        ");
        $stmt->execute([$corral_id, $bovino_id]);
        
        $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")
           ->execute([$corral_id]);
        
        if ($corral_origen) {
            $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")
               ->execute([$corral_origen]);
        }
        
        $stmt = $db->prepare("
            INSERT INTO movimientos_bovino 
            (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$bovino_id, $corral_origen, $corral_id, $tipo, $motivo, $usuario_id]);
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error al asignar corral: " . $e->getMessage());
        return false;
    }
}

function enviarAHospital($bovino_id, $motivo, $usuario_id) {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->query("
        SELECT id FROM corrales
        WHERE es_hospital = 1
          AND estado != 'Mantenimiento'
          AND ocupacion_actual < capacidad_maxima
        ORDER BY ocupacion_actual ASC
        LIMIT 1
    ");
    $hospital = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$hospital) {
        return ['success' => false, 'error' => 'No hay hospital con espacio disponible'];
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT corral_id FROM bovinos WHERE id = ?");
        $stmt->execute([$bovino_id]);
        $bovino = $stmt->fetch(PDO::FETCH_ASSOC);
        $corral_origen = $bovino['corral_id'] ?? null;
        
        $db->prepare("
            UPDATE bovinos 
            SET corral_original_id = ?,
                corral_id = ?,
                en_hospital = 1,
                motivo_hospital = ?,
                fecha_entrada_hospital = NOW()
            WHERE id = ?
        ")->execute([$corral_origen, $hospital['id'], $motivo, $bovino_id]);
        
        $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")
           ->execute([$hospital['id']]);
        
        if ($corral_origen) {
            $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")
               ->execute([$corral_origen]);
        }
        
        $db->prepare("
            INSERT INTO movimientos_bovino 
            (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, ?, ?, 'hospital', ?, ?, NOW())
        ")->execute([$bovino_id, $corral_origen, $hospital['id'], $motivo, $usuario_id]);
        
        $db->commit();
        
        return [
            'success' => true,
            'hospital_id' => $hospital['id']
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function darDeAlta($bovino_id, $usuario_id, $notas = '') {
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT corral_id, corral_original_id FROM bovinos WHERE id = ?");
        $stmt->execute([$bovino_id]);
        $bovino = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hospital_actual = $bovino['corral_id'];
        $corral_destino = $bovino['corral_original_id'];
        
        if (!$corral_destino) {
            throw new Exception('No hay corral original registrado');
        }
        
        $db->prepare("
            UPDATE bovinos 
            SET corral_id = ?,
                en_hospital = 0,
                fecha_salida_hospital = NOW()
            WHERE id = ?
        ")->execute([$corral_destino, $bovino_id]);
        
        $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")
           ->execute([$corral_destino]);
        
        if ($hospital_actual) {
            $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")
               ->execute([$hospital_actual]);
        }
        
        $db->prepare("
            INSERT INTO movimientos_bovino 
            (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, ?, ?, 'alta', ?, ?, NOW())
        ")->execute([$bovino_id, $hospital_actual, $corral_destino, $notas, $usuario_id]);
        
        $db->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function obtenerCorralesDisponibles($sexo = null, $peso = null) {
    $db = Database::getInstance()->getConnection();
    
    $sql = "SELECT * FROM corrales WHERE estado != 'Mantenimiento' AND ocupacion_actual < capacidad_maxima";
    $params = [];
    
    if ($sexo && $peso) {
        $sql .= " AND es_hospital = 0 AND es_venta = 0 
                  AND (sexo_permitido = ? OR sexo_permitido = 'Mixto')
                  AND peso_min IS NOT NULL AND peso_max IS NOT NULL
                  AND ? BETWEEN peso_min AND peso_max";
        $params = [$sexo, $peso];
    }
    
    $sql .= " ORDER BY es_hospital ASC, es_venta ASC, ocupacion_actual ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mueve un bovino a otro corral dentro de una transaccion ya abierta:
 * ajusta la ocupacion de ambos corrales y deja el movimiento en el historial.
 * corral_original_id queda en el destino (a donde regresa si luego va al hospital),
 * salvo que $actualizarOriginal sea false (animales en hospital conservan su corral de regreso).
 */
function moverBovinoACorral($db, $bovino_id, $destino_id, $usuario_id, $tipo, $motivo, $actualizarOriginal = true) {
    $stmt = $db->prepare("SELECT corral_id FROM bovinos WHERE id = ?");
    $stmt->execute([$bovino_id]);
    $origen_id = $stmt->fetchColumn() ?: null;
    if ((int)$origen_id === (int)$destino_id) return false;

    $db->prepare("UPDATE bovinos SET corral_id = ?, corral_original_id = IF(?, ?, corral_original_id) WHERE id = ?")
       ->execute([$destino_id, $actualizarOriginal ? 1 : 0, $destino_id, $bovino_id]);
    $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")->execute([$destino_id]);
    if ($origen_id) {
        $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")->execute([$origen_id]);
    }
    $db->prepare("
        INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$bovino_id, $origen_id, $destino_id, $tipo, $motivo, $usuario_id]);
    return true;
}

/** Categorias que puede elegir el clasificador segun el sexo (M / H) */
function categoriasPorSexo($sexo) {
    return $sexo === 'M'
        ? ['Becerro' => 'Menos de 12 meses', 'Torete' => '12 a 24 meses, entero', 'Novillo' => 'Castrado', 'Toro' => 'Mas de 24 meses, entero']
        : ['Becerra' => 'Menos de 12 meses', 'Vaquilla' => '12 a 30 meses, sin parto', 'Vaca' => 'Adulta / con parto'];
}

/** Fragmento SQL: peso actual del bovino (ultimo pesaje o, si no hay, el de entrada) */
function sqlPesoActual($alias = 'b') {
    return "COALESCE((SELECT pz.peso FROM pesajes pz WHERE pz.bovino_id = {$alias}.id ORDER BY pz.fecha DESC, pz.id DESC LIMIT 1), {$alias}.peso_entrada)";
}

/** Registra un pesaje con su ganancia diaria (gdp) respecto al pesaje anterior o al peso de entrada */
function registrarPesaje($db, $bovino_id, $peso, $usuario_id, $observaciones = null) {
    $stmt = $db->prepare("
        SELECT peso, fecha FROM pesajes WHERE bovino_id = ? ORDER BY fecha DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$bovino_id]);
    $ant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ant) {
        $stmt = $db->prepare("SELECT peso_entrada AS peso, DATE(created_at) AS fecha FROM bovinos WHERE id = ?");
        $stmt->execute([$bovino_id]);
        $ant = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $dias = $ant && $ant['fecha'] ? (strtotime(date('Y-m-d')) - strtotime($ant['fecha'])) / 86400 : 0;
    $gdp = $dias > 0 && $ant['peso'] ? round(($peso - $ant['peso']) / $dias, 3) : null;
    $db->prepare("INSERT INTO pesajes (bovino_id, peso, fecha, hora, gdp, usuario_id, observaciones) VALUES (?, ?, CURDATE(), CURTIME(), ?, ?, ?)")
       ->execute([$bovino_id, $peso, $gdp, $usuario_id, $observaciones]);
}
