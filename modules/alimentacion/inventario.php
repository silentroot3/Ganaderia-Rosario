<?php
// ============================================
// ALIMENTACION - Inventario de alimentos
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/inventario_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$db = Database::getInstance()->getConnection();
$error = '';

// Guardar el stock minimo de cada ingrediente (no es igual para todos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'minimos') {
    try {
        if (!Permisos::puedeEditar('alimentacion')) throw new Exception('Sin permiso para cambiar los minimos');
        require_once __DIR__ . '/../../includes/ui.php';
        exigirCSRF();
        $n = 0;
        $upd = $db->prepare("UPDATE inventario_alimento SET stock_minimo = ? WHERE ingrediente_id = ?");
        $ins = $db->prepare("INSERT INTO inventario_alimento (ingrediente_id, stock_actual, stock_minimo) VALUES (?, 0, ?)");
        $hay = $db->prepare("SELECT COUNT(*) FROM inventario_alimento WHERE ingrediente_id = ?");
        foreach ((array)($_POST['minimo'] ?? []) as $ing => $min) {
            if ($min === '' || !is_numeric($min) || $min < 0) continue;
            $hay->execute([(int)$ing]);
            if ($hay->fetchColumn()) $upd->execute([round((float)$min, 2), (int)$ing]); else $ins->execute([(int)$ing, round((float)$min, 2)]);
            $n++;
        }
        registrarLog($_SESSION['user_id'], 'stock_minimo_alimento', "$n ingredientes");
        header('Location: ' . BASE_URL . '/alimentacion/inventario?msg=' . urlencode('Stock minimo guardado'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Registrar entrada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entrada'])) {
    try {
        $ingrediente_id = (int)($_POST['ingrediente_id'] ?? 0);
        $cantidad = (float)($_POST['cantidad'] ?? 0);
        $precio = (float)($_POST['precio'] ?? 0);
        $proveedor = trim($_POST['proveedor'] ?? '');
        
        if ($ingrediente_id <= 0 || $cantidad <= 0) {
            throw new Exception('Datos invalidos');
        }
        
        // Suma al stock existente (o crea el registro si el ingrediente aun no tenia) y guarda la entrada
        $factura = trim($_POST['factura'] ?? '');
        entradaAlimento($ingrediente_id, $cantidad, $precio, $proveedor, $_SESSION['user_id'],
            $factura !== '' ? 'Factura/nota ' . $factura : 'Entrada manual');

        header('Location: ' . BASE_URL . '/alimentacion/inventario?msg=entrada');
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener inventario
$stmt = $db->query("
    SELECT i.*, inv.stock_actual, inv.stock_minimo, inv.unidad, inv.proveedor
    FROM ingredientes i
    LEFT JOIN inventario_alimento inv ON i.id = inv.ingrediente_id
    WHERE i.activo = 1
    ORDER BY i.nombre
");
$inventario = $stmt->fetchAll();



require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';
$pron = pronosticoAlimento($db);
$editaMin = Permisos::puedeEditar('alimentacion');
$totalStockBajo = 0;
foreach ($inventario as $i) {
    if ($i['stock_actual'] <= $i['stock_minimo']) $totalStockBajo++;
}

$titulo = 'Inventario';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card blue">
        <div class="stat-label">Ingredientes</div>
        <div class="stat-value"><?= count($inventario) ?></div>
    </div>
    <div class="stat-card <?= $totalStockBajo > 0 ? '' : 'green' ?>" style="<?= $totalStockBajo > 0 ? 'border-left-color: #dc3545;' : '' ?>">
        <div class="stat-label">Stock bajo</div>
        <div class="stat-value"><?= $totalStockBajo ?></div>
        <div class="stat-desc">Por reponer</div>
    </div>
</div>

<div style="margin-bottom: 25px; display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver al dashboard</a>
    <a href="<?= BASE_URL ?>/alimentacion/entradas" class="btn btn-primary">Ver entradas de alimento (compras)</a>
</div>

<?php if (($_GET['msg'] ?? '') === 'entrada'): ?>
    <div class="alert alert-success">Entrada registrada: se sumo al stock. Puedes verla en <a href="<?= BASE_URL ?>/alimentacion/entradas">Entradas de alimento</a>.</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Registrar entrada -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Registrar Entrada de Alimento</h2>
    </div>
    
    <form method="POST" style="padding: 20px;">
        <input type="hidden" name="entrada" value="1">
        
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 2fr 1fr auto; gap: 10px; align-items: end;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Ingrediente</label>
                <select name="ingrediente_id" required style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($inventario as $i): ?>
                        <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Cantidad (kg)</label>
                <input type="number" name="cantidad" step="0.01" min="0.01" required
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Precio/kg</label>
                <input type="number" name="precio" step="0.01" min="0"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">De donde viene (proveedor)</label>
                <input type="text" name="proveedor" required list="dl-prov-alimento" autocomplete="off" placeholder="Escribe o elige"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Factura / nota</label>
                <input type="text" name="factura" placeholder="Opcional"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <button type="submit" class="btn btn-primary">+ Entrada</button>
        </div>
    </form>
</div>

<!-- Inventario actual -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Inventario Actual</h2>
    </div>
    
    <form method="POST" id="formMinimos"><?= csrfField() ?><input type="hidden" name="accion" value="minimos"></form>
    <p style="padding:10px 20px 0;" class="muted">El <b>stock mínimo</b> es lo que quieres tener siempre guardado de cada ingrediente. El sistema avisa para pedir antes de llegar a esa cantidad.</p>
    <table>
        <thead>
            <tr>
                <th>Ingrediente</th>
                <th>Stock actual</th>
                <th>Stock mínimo</th>
                <th>Se usa al día</th>
                <th>Estado</th>
                <th>Proveedor</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventario as $i): 
                $stock = $i['stock_actual'] ?? 0;
                $minimo = $i['stock_minimo'] ?? 0;
                $bajo = $stock <= $minimo;
            ?>
            <tr style="<?= $bajo ? 'background: var(--f-amarillo);' : '' ?>">
                <td><strong><?= htmlspecialchars($i['nombre']) ?></strong></td>
                <td><?= number_format($stock, 1) ?> <?= $i['unidad'] ?? 'kg' ?></td>
                <td><?php if ($editaMin): ?><input type="number" form="formMinimos" name="minimo[<?= $i['id'] ?>]" value="<?= h(rtrim(rtrim(number_format((float)$minimo, 2, '.', ''), '0'), '.')) ?>" min="0" step="10" style="width:110px;"> <?= h($i['unidad'] ?? 'kg') ?>
                    <?php else: ?><?= number_format($minimo, 1) ?> <?= $i['unidad'] ?? 'kg' ?><?php endif; ?></td>
                <td><?php $pp = $pron[$i['id']] ?? null; ?><?= $pp && $pp['diario'] > 0 ? number_format($pp['diario'], 1) . ' kg' . ($pp['dias'] !== null ? ' <small class="muted">· ' . ($pp['dias'] >= 365 ? '+1 año' : floor($pp['dias']) . ' días antes del mínimo') . '</small>' : '') : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($bajo): ?>
                        <span style="color: #dc3545; font-weight: 600;">Stock bajo</span>
                    <?php else: ?>
                        <span style="color: #28a745; font-weight: 600;">OK</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($i['proveedor'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($editaMin): ?><div style="padding:14px 20px;text-align:right;border-top:1px solid var(--linea);"><button type="submit" form="formMinimos" class="btn btn-primary">Guardar stock mínimo</button></div><?php endif; ?>
</div>

<?= datalistProveedores($db, 'alimento') ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
