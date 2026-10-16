<?php
// ============================================
// CREAR RECEPCIÓN
// ============================================

// 1. Cargar config PRIMERO
require_once __DIR__ . '/../../includes/config.php';

// 2. Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// 3. Procesar POST ANTES de cualquier output
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        
        $proveedor_id = (int)($_POST['proveedor_id'] ?? 0);
        $transporte = trim($_POST['transporte'] ?? '');
        $placas = trim($_POST['placas'] ?? '');
        $chofer = trim($_POST['chofer'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $cantidad = (int)($_POST['cantidad_animales'] ?? 0);
        
        if ($cantidad <= 0) {
            throw new Exception('La cantidad de animales debe ser mayor a 0');
        }
        
        // Generar folio único
        $folio = 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        
        // Insertar
        $stmt = $db->prepare("
            INSERT INTO recepciones 
            (folio, proveedor_id, usuario_id, fecha_recepcion, cantidad_animales, transporte, placas, chofer, observaciones, estado)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, 'borrador')
        ");
        $stmt->execute([
            $folio,
            $proveedor_id ?: null,
            $_SESSION['user_id'],
            $cantidad,
            $transporte,
            $placas,
            $chofer,
            $observaciones
        ]);
        
        $recepcion_id = $db->lastInsertId();
        
        // Redirigir ANTES de cualquier output
        header('Location: ' . BASE_URL . '/recepcion/bovinos?id=' . $recepcion_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 4. AHORA sí, cargar el header (envía HTML)
$titulo = 'Nueva Recepción';
$modulo = 'recepcion';
$accion = 'crear';

require_once __DIR__ . '/../../includes/header.php';

// 5. Cargar datos para el formulario
$db = Database::getInstance()->getConnection();
$proveedores = $db->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();
?>

<div class="table-container" style="max-width: 900px; margin: 0 auto;">
    <div class="table-header">
        <h2>🚚 Nueva Recepción de Ganado</h2>
        <a href="<?= BASE_URL ?>/recepcion" class="btn btn-secondary">← Volver</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" style="padding: 30px;">
        <h3 style="margin-bottom: 20px; color: #226633;">📋 Datos de la Recepción</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <div class="form-group" style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Proveedor *</label>
                <select name="proveedor_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="">-- Seleccionar proveedor --</option>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (isset($_POST['proveedor_id']) && $_POST['proveedor_id'] == $p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Transporte</label>
                <input type="text" name="transporte" placeholder="Ej: Camión 1"
                       value="<?= htmlspecialchars($_POST['transporte'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div class="form-group">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Placas</label>
                <input type="text" name="placas" placeholder="Ej: ABC-123"
                       value="<?= htmlspecialchars($_POST['placas'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div class="form-group">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Chofer</label>
                <input type="text" name="chofer" placeholder="Nombre del chofer"
                       value="<?= htmlspecialchars($_POST['chofer'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div class="form-group">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Cantidad de Animales *</label>
                <input type="number" name="cantidad_animales" min="1" required
                       placeholder="Ej: 10"
                       value="<?= htmlspecialchars($_POST['cantidad_animales'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div class="form-group" style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Observaciones</label>
                <textarea name="observaciones" rows="3" placeholder="Notas adicionales..."
                          style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;"><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
        
        <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/recepcion" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                ➡️ Siguiente: Registrar Animales
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
