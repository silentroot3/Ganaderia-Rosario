<?php
// ============================================
// VENTAS - Cancelar una venta (solo admin)
// Los animales regresan al corral del que salieron (o a RECEP si ese corral
// ya no existe o esta lleno) y la venta deja de contar en reportes.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Solicitud no valida');
    exigirCSRF();
    $motivo = trim($_POST['motivo'] ?? '');
    if ($motivo === '') throw new Exception('Escribe el motivo de la cancelacion');

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM ventas WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new Exception('Venta no encontrada');
    if ($v['estado'] !== 'activa') throw new Exception('La venta ya estaba cancelada');

    $stmt = $db->prepare("
        SELECT b.id, b.sexo, b.estado,
               (SELECT m.corral_origen_id FROM movimientos_bovino m
                WHERE m.bovino_id = b.id AND m.tipo_movimiento = 'venta' ORDER BY m.id DESC LIMIT 1) AS corral_antes
        FROM detalle_ventas dv JOIN bovinos b ON b.id = dv.bovino_id
        WHERE dv.venta_id = ? FOR UPDATE
    ");
    $stmt->execute([$id]);
    $animales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recep = $db->query("SELECT sexo_permitido, id FROM corrales WHERE es_recepcion = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    $aRecep = 0;
    foreach ($animales as $a) {
        if ($a['estado'] !== 'vendido') continue;   // ya se movio por otro lado
        // corrales es la vista de activos: si el corral se elimino no aparece
        $stmt = $db->prepare("SELECT id, es_recepcion FROM corrales WHERE id = ? AND ocupacion_actual < capacidad_maxima AND estado != 'Mantenimiento'");
        $stmt->execute([(int)$a['corral_antes']]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            $c = ['id' => $recep[$a['sexo']] ?? null, 'es_recepcion' => 1];
            $aRecep++;
        }
        if (!$c['id']) throw new Exception('No hay corral para regresar los animales (falta RECEP)');

        $db->prepare("UPDATE bovinos SET estado = IF(? = 1, 'recibido', 'en_corral'), corral_id = ?, fecha_salida = NULL WHERE id = ?")
           ->execute([(int)$c['es_recepcion'], $c['id'], $a['id']]);
        $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")->execute([$c['id']]);
        $db->prepare("
            INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, NULL, ?, 'regreso', ?, ?, NOW())
        ")->execute([$a['id'], $c['id'], "Venta {$v['folio']} cancelada", $_SESSION['user_id']]);
    }

    $db->prepare("UPDATE ventas SET estado = 'cancelada', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
    $db->commit();
    registrarLog($_SESSION['user_id'], 'venta_cancelar', "{$v['folio']}: $motivo");

    $msg = "Venta {$v['folio']} cancelada. " . count($animales) . " animales regresaron al rancho"
         . ($aRecep ? " ($aRecep a RECEP porque su corral ya no existe o esta lleno)" : '');
    header('Location: ' . BASE_URL . '/ventas/detalle?id=' . $id . '&msg=' . urlencode($msg));
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    header('Location: ' . BASE_URL . '/ventas/detalle?id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
