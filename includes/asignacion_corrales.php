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
