<?php
// ============================================
// ALIMENTACION - Entradas de alimento (compras)
// De donde vino, cuanto entro, cuanto costo; y resumen comprado vs. consumido.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('alimentacion', 'ver');

$db = Database::getInstance()->getConnection();

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
$ingrediente = (int)($_GET['ingrediente'] ?? 0);

$where = "m.fecha >= ? AND m.fecha < DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$desde, $hasta];
if ($ingrediente) { $where .= " AND m.ingrediente_id = ?"; $params[] = $ingrediente; }

$stmt = $db->prepare("
    SELECT m.*, i.nombre AS ingrediente, COALESCE(inv.unidad, 'kg') AS unidad,
           u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
    FROM movimientos_inventario m
    JOIN ingredientes i ON i.id = m.ingrediente_id
    LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = m.ingrediente_id
    LEFT JOIN usuarios u ON u.id = m.usuario_id
    WHERE m.tipo = 'entrada' AND $where
    ORDER BY m.fecha DESC, m.id DESC
");
$stmt->execute($params);
$entradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT i.nombre, COALESCE(inv.unidad, 'kg') AS unidad,
           SUM(CASE WHEN m.tipo = 'entrada' THEN m.cantidad ELSE 0 END) AS comprado,
           SUM(CASE WHEN m.tipo = 'entrada' THEN m.cantidad * m.precio_unitario ELSE 0 END) AS gastado,
           SUM(CASE WHEN m.tipo = 'salida' THEN m.cantidad WHEN m.tipo = 'ajuste' THEN -m.cantidad ELSE 0 END) AS consumido,
           MAX(inv.stock_actual) AS stock
    FROM movimientos_inventario m
    JOIN ingredientes i ON i.id = m.ingrediente_id
    LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = m.ingrediente_id
    WHERE $where
    GROUP BY i.id ORDER BY i.nombre
");
$stmt->execute($params);
$resumen = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalKg = array_sum(array_column($entradas, 'cantidad'));
$totalCosto = array_sum(array_map(fn($e) => $e['cantidad'] * $e['precio_unitario'], $entradas));
$ingredientes = $db->query("SELECT id, nombre FROM ingredientes ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$titulo = 'Entradas de alimento';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="<?= BASE_URL ?>/alimentacion/inventario" class="btn btn-secondary">Volver al inventario</a>
    <a href="<?= BASE_URL ?>/alimentacion/inventario" class="btn btn-primary">+ Registrar entrada</a>
</div>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card blue">
        <div class="stat-label">Entradas en el periodo</div>
        <div class="stat-value"><?= count($entradas) ?></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Cantidad comprada</div>
        <div class="stat-value"><?= number_format($totalKg, 0) ?> kg</div>
    </div>
    <div class="stat-card yellow">
        <div class="stat-label">Costo total</div>
        <div class="stat-value">$<?= number_format($totalCosto, 2) ?></div>
    </div>
</div>

<div class="table-container" style="margin-bottom: 25px;">
    <form method="GET" style="padding: 20px; display: grid; grid-template-columns: 1fr 1fr 2fr auto; gap: 10px; align-items: end;">
        <div><label style="<?= ESTILO_LABEL ?>">Desde</label><input type="date" name="desde" value="<?= h($desde) ?>" style="<?= ESTILO_INPUT ?>"></div>
        <div><label style="<?= ESTILO_LABEL ?>">Hasta</label><input type="date" name="hasta" value="<?= h($hasta) ?>" style="<?= ESTILO_INPUT ?>"></div>
        <div>
            <label style="<?= ESTILO_LABEL ?>">Ingrediente</label>
            <select name="ingrediente" style="<?= ESTILO_INPUT ?>">
                <option value="">Todos</option>
                <?php foreach ($ingredientes as $i): ?>
                    <option value="<?= $i['id'] ?>" <?= $ingrediente === (int)$i['id'] ? 'selected' : '' ?>><?= h($i['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-secondary" type="submit">Filtrar</button>
    </form>
</div>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header"><h2>Entradas (<?= count($entradas) ?>)</h2></div>
    <?php if ($entradas): ?>
    <table>
        <thead>
            <tr><th>Fecha</th><th>Ingrediente</th><th>De donde viene</th><th>Cantidad</th><th>Precio/kg</th><th>Costo</th><th>Referencia</th><th>Registro</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entradas as $e): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($e['fecha'])) ?></td>
                <td><strong><?= h($e['ingrediente']) ?></strong></td>
                <td><?= h($e['proveedor'] ?? '-') ?></td>
                <td><?= number_format($e['cantidad'], 1) ?> <?= h($e['unidad']) ?></td>
                <td><?= $e['precio_unitario'] > 0 ? '$' . number_format($e['precio_unitario'], 2) : '-' ?></td>
                <td><strong><?= $e['precio_unitario'] > 0 ? '$' . number_format($e['cantidad'] * $e['precio_unitario'], 2) : '-' ?></strong></td>
                <td style="font-size: 0.85rem;"><?= h($e['referencia'] ?? '-') ?></td>
                <td style="font-size: 0.85rem;"><?= h(trim(($e['usuario_nombre'] ?? '') . ' ' . ($e['usuario_apellido'] ?? ''))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: 700; background: var(--suave);">
                <td colspan="3">Total</td><td><?= number_format($totalKg, 1) ?> kg</td><td></td><td>$<?= number_format($totalCosto, 2) ?></td><td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <div style="padding: 40px; text-align: center; color: var(--tenue);">No hay entradas en este periodo.</div>
    <?php endif; ?>
</div>

<div class="table-container">
    <div class="table-header"><h2>Comprado vs. consumido en el periodo</h2></div>
    <?php if ($resumen): ?>
    <table>
        <thead><tr><th>Ingrediente</th><th>Comprado</th><th>Gastado</th><th>Usado en mezclas (menos sobrantes)</th><th>Stock hoy</th></tr></thead>
        <tbody>
        <?php foreach ($resumen as $r): ?>
            <tr>
                <td><strong><?= h($r['nombre']) ?></strong></td>
                <td><?= number_format($r['comprado'], 1) ?> <?= h($r['unidad']) ?></td>
                <td><?= $r['gastado'] > 0 ? '$' . number_format($r['gastado'], 2) : '-' ?></td>
                <td><?= number_format($r['consumido'], 1) ?> <?= h($r['unidad']) ?></td>
                <td><?= number_format($r['stock'], 1) ?> <?= h($r['unidad']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div style="padding: 30px; text-align: center; color: var(--tenue);">Sin movimientos en el periodo.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
