<?php
// ============================================
// MODULO CONFIGURACION - COMPRAS
// Cancelar/Eliminar una compra
// ============================================

require_once __DIR__ . '/../../../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// Solo admin puede cancelar
if ($_SESSION['rol'] !== 'admin') {
    header('Location: ' . BASE_URL . '/configuracion/compras?error=sin_permiso');
    exit;
}

$db = Database::getInstance()->getConnection();

$compra_id = (int)($_GET['id'] ?? 0);
if (!$compra_id) {
    header('Location: ' . BASE_URL . '/configuracion/compras');
    exit;
}

// Obtener compra
$stmt = $db->prepare("SELECT * FROM compras WHERE id = ?");
$stmt->execute([$compra_id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) {
    header('Location: ' . BASE_URL . '/configuracion/compras?error=no_existe');
    exit;
}

// Solo se pueden cancelar compras pendientes
if ($compra['estado'] !== 'pendiente') {
    header('Location: ' . BASE_URL . '/configuracion/compras/detalle?id=' . $compra_id . '&error=no_cancelable');
    exit;
}

// Confirmar cancelación
$confirmar = $_GET['confirmar'] ?? '';
$motivo = trim($_GET['motivo'] ?? '');

if ($confirmar !== 'si') {
    // Mostrar pantalla de confirmación
    $titulo = 'Cancelar Compra';
    $modulo = 'configuracion';
    $accion = 'editar';
    require_once __DIR__ . '/../../../includes/header.php';
    ?>
    
    <div class="table-container" style="max-width: 600px; margin: 50px auto;">
        <div class="table-header" style="background: var(--f-rojo);">
            <h2 style="color: var(--t-rojo);">Cancelar Compra</h2>
        </div>
        
        <div style="padding: 30px;">
            <div style="text-align: center; margin-bottom: 25px;">
                <div style="font-size: 4rem; color: #dc3545;">&#9888;</div>
                <h3>¿Estás seguro de cancelar esta compra?</h3>
            </div>
            
            <div style="background: var(--suave); padding: 15px; border-radius: 6px; margin-bottom: 25px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <strong>Folio:</strong><br>
                        <?= htmlspecialchars($compra['folio']) ?>
                    </div>
                    <div>
                        <strong>Proveedor:</strong><br>
                        <?= htmlspecialchars($compra['origen_nombre'] ?? 'N/A') ?>
                    </div>
                    <div>
                        <strong>Animales:</strong><br>
                        <?= $compra['cantidad_total'] ?>
                    </div>
                    <div>
                        <strong>Precio:</strong><br>
                        $<?= number_format($compra['precio_total'], 2) ?>
                    </div>
                </div>
            </div>
            
            <form method="GET" action="">
                <input type="hidden" name="id" value="<?= $compra_id ?>">
                <input type="hidden" name="confirmar" value="si">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Motivo de cancelación</label>
                    <textarea name="motivo" rows="3" required
                              placeholder="Ej: Error de captura, proveedor canceló, etc."
                              style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;"></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $compra_id ?>" 
                       class="btn btn-secondary">No, volver</a>
                    <button type="submit" class="btn" style="background: #dc3545; color: white;">
                        Sí, cancelar compra
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php
    require_once __DIR__ . '/../../../includes/footer.php';
    exit;
}

// Si confirmó, cancelar
try {
    $db->beginTransaction();
    
    // Actualizar estado a cancelada
    $stmt = $db->prepare("
        UPDATE compras 
        SET estado = 'cancelada',
            observaciones = CONCAT(IFNULL(observaciones, ''), '\n[CANCELADA] ', ?)
        WHERE id = ?
    ");
    $stmt->execute([$motivo, $compra_id]);
    
    // Marcar alerta como leída
    $stmt = $db->prepare("
        UPDATE alertas 
        SET leida = 1, leida_por = ?, fecha_lectura = NOW()
        WHERE compra_id = ? AND tipo = 'compra_pendiente'
    ");
    $stmt->execute([$_SESSION['user_id'], $compra_id]);
    
    // Registrar en bitácora
    $db->prepare("
        INSERT INTO logs_actividad (usuario_id, accion, descripcion, ip, user_agent)
        VALUES (?, 'compra_cancelada', ?, ?, ?)
    ")->execute([
        $_SESSION['user_id'],
        "Compra {$compra['folio']} cancelada. Motivo: {$motivo}",
        $_SERVER['REMOTE_ADDR'] ?? 'desconocida',
        $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'
    ]);
    
    $db->commit();
    
    header('Location: ' . BASE_URL . '/configuracion/compras?msg=cancelada');
    exit;
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error cancelando compra: " . $e->getMessage());
    header('Location: ' . BASE_URL . '/configuracion/compras/detalle?id=' . $compra_id . '&error=' . urlencode($e->getMessage()));
    exit;
}
