<?php
// ============================================
// MODULO CONFIGURACION - COMPRAS
// Crear nueva compra de ganado
// ============================================

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/compra_form.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

if ($_SESSION['rol'] !== 'admin') {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$error = '';
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fecha_compra = $_POST['fecha_compra'] ?? date('Y-m-d');
        $fecha_llegada = $_POST['fecha_llegada_estimada'] ?? null;
        $precio_por_kg = (float)($_POST['precio_por_kg'] ?? 0);

        $bovinos = $_POST['bovinos'] ?? [];

        validarBovinosCompra($db, $bovinos);

        $db->beginTransaction();

        // Alta o actualizacion del proveedor por su UPP
        $proveedor = guardarProveedorCompra($db, $_POST);

        $folio = 'COMP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $numero_reemo = siguienteReemo($db);   // la guia REEMO se genera sola

        $cantidad_total = count($bovinos);
        $peso_total = array_sum(array_map(fn($b) => (float)($b['peso_estimado'] ?? 0), $bovinos));
        $precio_total = round($peso_total * $precio_por_kg, 2);

        $stmt = $db->prepare("
            INSERT INTO compras (
                folio, numero_reemo, proveedor_id, motivo,
                origen_nombre, origen_upp, origen_localidad, origen_municipio, origen_estado,
                cantidad_total, peso_total_compra, precio_total, precio_por_kg,
                fecha_compra, fecha_llegada_estimada,
                estado, usuario_compra_id, observaciones
            ) VALUES (
                ?, ?, ?, 'ENGORDA',
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                'pendiente', ?, ?
            )
        ");

        $stmt->execute([
            $folio, $numero_reemo, $proveedor['id'],
            $proveedor['nombre'], $proveedor['upp'], $proveedor['localidad'] ?: null,
            $proveedor['municipio'] ?: null, $proveedor['estado'] ?: null,
            $cantidad_total, $peso_total, $precio_total, $precio_por_kg,
            $fecha_compra, $fecha_llegada ?: null,
            $_SESSION['user_id'], null
        ]);

        $compra_id = $db->lastInsertId();

        guardarBovinosCompra($db, $compra_id, $bovinos);

        $mensaje = "Nueva compra {$folio} de " . $cantidad_total . " animales desde " . $proveedor['nombre'];
        $stmt = $db->prepare("
            INSERT INTO alertas (tipo, mensaje, compra_id, rol_destino)
            VALUES ('compra_pendiente', ?, ?, 'recepcionista')
        ");
        $stmt->execute([$mensaje, $compra_id]);

        $db->commit();

        header('Location: ' . BASE_URL . '/configuracion/compras/detalle?id=' . $compra_id . '&msg=creada');
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$titulo = 'Nueva Compra';
$modulo = 'configuracion';
$accion = 'crear';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="table-container" style="max-width: 1200px; margin: 0 auto;">
    <div class="table-header">
        <h2>Nueva Compra de Ganado</h2>
        <a href="<?= BASE_URL ?>/configuracion/compras" class="btn btn-secondary">Volver</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" style="padding: 30px;">

        <?php compraProveedorCampos($db, [
            'upp'       => $_POST['proveedor_upp'] ?? '',
            'nombre'    => $_POST['proveedor_nombre'] ?? '',
            'localidad' => $_POST['proveedor_localidad'] ?? '',
            'municipio' => $_POST['proveedor_municipio'] ?? '',
            'estado'    => $_POST['proveedor_estado'] ?? '',
            'telefono'  => $_POST['proveedor_telefono'] ?? '',
            'email'     => $_POST['proveedor_email'] ?? '',
        ]); ?>

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">
            Datos de la Compra
        </h3>

        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">No. REEMO / Guia</label>
                <div style="padding: 10px; background: var(--suave); border-radius: 6px; font-family: monospace; font-weight: 600;"><?= siguienteReemo($db) ?></div>
                <small style="color: var(--tenue);">Se asigna automaticamente al guardar</small>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Fecha de compra *</label>
                <input type="date" name="fecha_compra" required
                       value="<?= htmlspecialchars($_POST['fecha_compra'] ?? date('Y-m-d')) ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Fecha estimada de llegada</label>
                <input type="date" name="fecha_llegada_estimada"
                       value="<?= htmlspecialchars($_POST['fecha_llegada_estimada'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Precio por kg *</label>
                <input type="number" name="precio_por_kg" step="0.01" min="0" required
                       placeholder="Ej: 85.50"
                       value="<?= htmlspecialchars($_POST['precio_por_kg'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">
            Bovinos (<span id="totalBovinos">0</span>)
        </h3>

        <?php compraBovinosWidget($_POST['bovinos'] ?? []); ?>

        <div style="height:25px"></div>

        <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid var(--linea);">
            <a href="<?= BASE_URL ?>/configuracion/compras" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" style="padding: 12px 30px;">
                Guardar Compra y Alertar Recepcion
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
