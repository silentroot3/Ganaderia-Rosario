<?php
// ============================================
// MODULO CONFIGURACION - COMPRAS
// Editar compra existente
// ============================================

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/compra_form.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

if ($_SESSION['rol'] !== 'admin') {
    header('Location: ' . BASE_URL . '/configuracion/compras?error=sin_permiso');
    exit;
}

$compra_id = (int)($_GET['id'] ?? 0);
if (!$compra_id) {
    header('Location: ' . BASE_URL . '/configuracion/compras');
    exit;
}

$db = Database::getInstance()->getConnection();

// Obtener compra
$stmt = $db->prepare("SELECT * FROM compras WHERE id = ?");
$stmt->execute([$compra_id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) {
    header('Location: ' . BASE_URL . '/configuracion/compras?error=no_existe');
    exit;
}

// Solo se pueden editar compras pendientes
if ($compra['estado'] !== 'pendiente') {
    header('Location: ' . BASE_URL . '/configuracion/compras/detalle?id=' . $compra_id . '&error=no_editable');
    exit;
}

$error = '';

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        // Alta o actualizacion del proveedor por su UPP
        $proveedor = guardarProveedorCompra($db, $_POST);
        $precio_por_kg = (float)($_POST['precio_por_kg'] ?? 0);

        // Actualizar datos generales
        $stmt = $db->prepare("
            UPDATE compras SET
                fecha_compra = ?,
                fecha_llegada_estimada = ?,
                precio_por_kg = ?,
                proveedor_id = ?,
                origen_nombre = ?,
                origen_upp = ?,
                origen_localidad = ?,
                origen_municipio = ?,
                origen_estado = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $_POST['fecha_compra'] ?? date('Y-m-d'),
            ($_POST['fecha_llegada_estimada'] ?? '') ?: null,
            $precio_por_kg,
            $proveedor['id'],
            $proveedor['nombre'],
            $proveedor['upp'],
            $proveedor['localidad'] ?: null,
            $proveedor['municipio'] ?: null,
            $proveedor['estado'] ?: null,
            $compra_id
        ]);
        
        // Borrar bovinos existentes (solo los no recibidos)
        $stmt = $db->prepare("DELETE FROM compra_bovinos WHERE compra_id = ? AND recibido = 0");
        $stmt->execute([$compra_id]);
        
        // Insertar bovinos nuevos (las lineas ya recibidas se conservan)
        $bovinos = $_POST['bovinos'] ?? [];
        validarBovinosCompra($db, $bovinos);
        $stmt = $db->prepare("SELECT COALESCE(MAX(numero_linea), 0) FROM compra_bovinos WHERE compra_id = ?");
        $stmt->execute([$compra_id]);
        guardarBovinosCompra($db, $compra_id, $bovinos, (int)$stmt->fetchColumn());

        // Actualizar cantidad, peso y precio total (precio por kg x peso estimado)
        $stmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(peso_estimado), 0) FROM compra_bovinos WHERE compra_id = ?");
        $stmt->execute([$compra_id]);
        [$cantidad, $peso_total] = $stmt->fetch(PDO::FETCH_NUM);

        $db->prepare("UPDATE compras SET cantidad_total = ?, peso_total_compra = ?, precio_total = ? WHERE id = ?")
           ->execute([$cantidad, $peso_total, round($peso_total * $precio_por_kg, 2), $compra_id]);
        
        $db->commit();
        
        header('Location: ' . BASE_URL . '/configuracion/compras/detalle?id=' . $compra_id . '&msg=actualizada');
        exit;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Telefono y correo del proveedor ligado (o por su UPP en compras viejas)
$stmt = $db->prepare("SELECT telefono, email FROM proveedores WHERE id = ? OR (upp IS NOT NULL AND upp = ?) ORDER BY id = ? DESC LIMIT 1");
$stmt->execute([$compra['proveedor_id'], $compra['origen_upp'], $compra['proveedor_id']]);
$proveedorActual = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Obtener bovinos actuales
$stmt = $db->prepare("SELECT * FROM compra_bovinos WHERE compra_id = ? ORDER BY numero_linea");
$stmt->execute([$compra_id]);
$bovinosActuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$titulo = 'Editar Compra';
$modulo = 'configuracion';
$accion = 'editar';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="table-container" style="max-width: 1100px; margin: 0 auto;">
    <div class="table-header">
        <h2>Editar Compra <?= htmlspecialchars($compra['folio']) ?></h2>
        <a href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $compra_id ?>" class="btn btn-secondary">Volver</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" style="padding: 30px;">
        
        <?php compraProveedorCampos($db, [
            'upp'       => $_POST['proveedor_upp'] ?? $compra['origen_upp'] ?? '',
            'nombre'    => $_POST['proveedor_nombre'] ?? $compra['origen_nombre'] ?? '',
            'localidad' => $_POST['proveedor_localidad'] ?? $compra['origen_localidad'] ?? '',
            'municipio' => $_POST['proveedor_municipio'] ?? $compra['origen_municipio'] ?? '',
            'estado'    => $_POST['proveedor_estado'] ?? $compra['origen_estado'] ?? '',
            'telefono'  => $_POST['proveedor_telefono'] ?? $proveedorActual['telefono'] ?? '',
            'email'     => $_POST['proveedor_email'] ?? $proveedorActual['email'] ?? '',
        ]); ?>

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">
            Datos de la Compra
        </h3>

        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">No. REEMO / Guia</label>
                <div style="padding: 10px; background: var(--suave); border-radius: 6px; font-family: monospace; font-weight: 600;"><?= htmlspecialchars($compra['numero_reemo'] ?? '-') ?></div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Fecha de compra *</label>
                <input type="date" name="fecha_compra" required
                       value="<?= htmlspecialchars($compra['fecha_compra']) ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Fecha estimada de llegada</label>
                <input type="date" name="fecha_llegada_estimada"
                       value="<?= htmlspecialchars($compra['fecha_llegada_estimada'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Precio por kg *</label>
                <input type="number" name="precio_por_kg" step="0.01" min="0" required
                       value="<?= htmlspecialchars($compra['precio_por_kg'] ?? 0) ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>
        
        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">
            Bovinos (<span id="totalBovinos"><?= count($bovinosActuales) ?></span>)
        </h3>
        
        <?php compraBovinosWidget($_POST['bovinos'] ?? array_values(array_filter($bovinosActuales, fn($x) => !$x['recibido']))); ?>

        <div style="height:25px"></div>

        <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid var(--linea);">
            <a href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $compra_id ?>" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" style="padding: 12px 30px;">
                Guardar Cambios
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
