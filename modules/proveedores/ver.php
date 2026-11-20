<?php
// ============================================
// PROVEEDORES - Ficha y relacion completa con el proveedor
// Compras de ganado, entradas de alimento y medicamento, y pedidos (solicitudes).
// ============================================

$titulo = 'Proveedor';
$modulo = 'proveedores';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/proveedores_lib.php';

$db = Database::getInstance()->getConnection();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    echo '<div class="vacio"><p>Proveedor no encontrado.</p><a class="btn btn-secondary" href="' . BASE_URL . '/proveedores">Volver</a></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
$verMontos = puedeVerPrecios();

$q = $db->prepare("SELECT c.id, c.folio, c.numero_reemo, c.fecha_compra, c.cantidad_total, c.peso_total_compra, c.precio_por_kg, c.precio_total, c.estado,
                          (SELECT COUNT(*) FROM bovinos b WHERE b.compra_id = c.id) AS recibidos
                   FROM compras c WHERE c.proveedor_id = ? ORDER BY c.fecha_compra DESC, c.id DESC");
$q->execute([$id]);
$compras = $q->fetchAll(PDO::FETCH_ASSOC);

$q = $db->prepare("SELECT m.fecha, i.nombre AS producto, m.cantidad, 'kg' AS unidad, m.precio_unitario, m.referencia, 'Alimento' AS tipo
                   FROM movimientos_inventario m JOIN ingredientes i ON i.id = m.ingrediente_id
                   WHERE m.proveedor_id = ? AND m.tipo = 'entrada'
                   UNION ALL
                   SELECT m.fecha, md.nombre, m.cantidad, COALESCE(inv.unidad, 'ml'), m.precio_unitario, m.referencia, 'Medicamento'
                   FROM movimientos_medicamento m JOIN medicamentos md ON md.id = m.medicamento_id
                   LEFT JOIN inventario_medicamentos inv ON inv.medicamento_id = m.medicamento_id
                   WHERE m.proveedor_id = ? AND m.tipo = 'entrada'
                   ORDER BY fecha DESC LIMIT 200");
$q->execute([$id, $id]);
$entradas = $q->fetchAll(PDO::FETCH_ASSOC);

$q = $db->prepare("SELECT s.id, s.folio, s.tipo, s.cantidad, s.unidad, s.estado, s.created_at, COALESCE(m.nombre, i.nombre) AS producto
                   FROM solicitudes_compra s LEFT JOIN medicamentos m ON m.id = s.medicamento_id LEFT JOIN ingredientes i ON i.id = s.ingrediente_id
                   WHERE s.proveedor_id = ? ORDER BY s.id DESC LIMIT 100");
$q->execute([$id]);
$pedidos = $q->fetchAll(PDO::FETCH_ASSOC);

// Productos que le compramos (para pedir de nuevo)
$productos = [];
foreach ($entradas as $e) {
    $k = $e['tipo'] . '|' . $e['producto'];
    if (!isset($productos[$k])) $productos[$k] = ['tipo' => $e['tipo'], 'producto' => $e['producto'], 'ultima' => $e['fecha'], 'precio' => $e['precio_unitario'], 'unidad' => $e['unidad'], 'veces' => 0, 'cantidad' => 0];
    $productos[$k]['veces']++;
    $productos[$k]['cantidad'] += (float)$e['cantidad'];
}
$r = resumenProveedores($db)[$id] ?? ['ops' => 0, 'ultima' => null, 'monto' => 0];
$cabezas = array_sum(array_column(array_filter($compras, fn($c) => $c['estado'] !== 'cancelada'), 'recibidos'));
?>

<div class="page-actions">
    <a href="<?= BASE_URL ?>/proveedores" class="btn btn-secondary">&larr; Proveedores</a>
    <?php if (Permisos::puedeEditar('proveedores')): ?><a href="<?= BASE_URL ?>/proveedores/editar?id=<?= $id ?>" class="btn btn-primary">Editar datos</a><?php endif; ?>
</div>

<div class="table-container ficha">
    <div class="ficha-head">
        <div class="prov-avatar grande"><?= h(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></div>
        <div>
            <h2><?= h($p['nombre']) ?></h2>
            <div class="prov-chips"><?= chipsTipoProveedor($p['tipo']) ?><?= $p['activo'] ? '' : '<span class="chip chip-gris">Inactivo</span>' ?></div>
        </div>
    </div>
    <div class="datos-grid">
        <?php foreach ([
            ['UPP', $p['upp']], ['RFC', $p['rfc']], ['Telefono', $p['telefono']], ['Correo', $p['email']], ['Contacto', $p['contacto']],
            ['Lugar', trim(implode(', ', array_filter([$p['localidad'], $p['municipio'], $p['estado']])))], ['Direccion', $p['direccion']],
        ] as [$l, $v]): if (trim((string)$v) === '') continue; ?>
            <div><span><?= $l ?></span><?= $l === 'Telefono' ? '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $v)) . '">' . h($v) . '</a>' : h($v) ?></div>
        <?php endforeach; ?>
    </div>
    <?php if ($p['notas']): ?><p class="ficha-notas"><?= nl2br(h($p['notas'])) ?></p><?php endif; ?>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Operaciones</div><div class="stat-value"><?= (int)$r['ops'] ?></div><div class="stat-desc">compras y entradas</div></div>
    <div class="stat-card green"><div class="stat-label">Ultima compra</div><div class="stat-value" style="font-size:1.3rem;"><?= $r['ultima'] ? fechaCorta($r['ultima']) : '—' ?></div><div class="stat-desc">&nbsp;</div></div>
    <?php if ($compras): ?><div class="stat-card yellow"><div class="stat-label">Ganado recibido</div><div class="stat-value"><?= $cabezas ?></div><div class="stat-desc">cabezas en <?= count($compras) ?> compra(s)</div></div><?php endif; ?>
    <?php if ($verMontos): ?><div class="stat-card purple"><div class="stat-label">Total comprado</div><div class="stat-value" style="font-size:1.3rem;"><?= dinero($r['monto']) ?></div><div class="stat-desc">historico</div></div><?php endif; ?>
</div>

<?php if ($productos): ?>
<div class="table-container">
    <div class="table-header"><h2>Lo que le compramos</h2>
        <?php if (Permisos::puedeCrear('solicitudes')): ?><a href="<?= BASE_URL ?>/solicitudes/crear" class="btn btn-secondary btn-sm">Hacer una solicitud</a><?php endif; ?></div>
    <table>
        <thead><tr><th>Producto</th><th>Tipo</th><th>Veces</th><th>Cantidad total</th><th>Ultima vez</th><?php if ($verMontos): ?><th>Ultimo precio</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($productos as $x): ?>
            <tr><td><strong><?= h($x['producto']) ?></strong></td><td><?= h($x['tipo']) ?></td><td><?= $x['veces'] ?></td>
                <td><?= number_format($x['cantidad'], 1) ?> <?= h($x['unidad']) ?></td><td><?= fechaCorta($x['ultima']) ?></td>
                <?php if ($verMontos): ?><td><?= $x['precio'] > 0 ? dinero($x['precio']) . '/' . h($x['unidad']) : '—' ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($compras): ?>
<div class="table-container">
    <div class="table-header"><h2>Compras de ganado (<?= count($compras) ?>)</h2></div>
    <table>
        <thead><tr><th>Folio</th><th>REEMO</th><th>Fecha</th><th>Cabezas</th><th>Kg</th><?php if ($verMontos): ?><th>$/kg</th><th>Total</th><?php endif; ?><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
            <tr><td><strong><?= h($c['folio']) ?></strong></td><td><?= h($c['numero_reemo'] ?: '—') ?></td><td><?= fechaCorta($c['fecha_compra']) ?></td>
                <td><?= (int)$c['recibidos'] ?> / <?= (int)$c['cantidad_total'] ?></td><td><?= number_format((float)$c['peso_total_compra'], 0) ?></td>
                <?php if ($verMontos): ?><td><?= dinero($c['precio_por_kg']) ?></td><td><?= dinero($c['precio_total']) ?></td><?php endif; ?>
                <td><?= pill($c['estado']) ?></td>
                <td><?php if (Permisos::esAdmin()): ?><a class="btn-icon" href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $c['id'] ?>">Ver</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($entradas): ?>
<div class="table-container">
    <div class="table-header"><h2>Entradas a bodega (<?= count($entradas) ?>)</h2></div>
    <table>
        <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><?php if ($verMontos): ?><th>Precio</th><th>Importe</th><?php endif; ?><th>Referencia</th></tr></thead>
        <tbody>
        <?php foreach ($entradas as $e): ?>
            <tr><td><?= fechaCorta($e['fecha']) ?></td><td><strong><?= h($e['producto']) ?></strong></td><td><?= h($e['tipo']) ?></td>
                <td><?= number_format($e['cantidad'], 1) ?> <?= h($e['unidad']) ?></td>
                <?php if ($verMontos): ?><td><?= $e['precio_unitario'] > 0 ? dinero($e['precio_unitario']) : '—' ?></td><td><?= dinero($e['cantidad'] * $e['precio_unitario']) ?></td><?php endif; ?>
                <td class="muted"><?= h($e['referencia']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($pedidos): ?>
<div class="table-container">
    <div class="table-header"><h2>Pedidos (solicitudes de compra)</h2></div>
    <table>
        <thead><tr><th>Folio</th><th>Fecha</th><th>Producto</th><th>Cantidad</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($pedidos as $s): ?>
            <tr><td><strong><?= h($s['folio']) ?></strong></td><td><?= fechaCorta($s['created_at']) ?></td><td><?= h($s['producto']) ?></td>
                <td><?= number_format($s['cantidad'], 1) ?> <?= h($s['unidad']) ?></td><td><?= pill($s['estado']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!$compras && !$entradas && !$pedidos): ?>
    <div class="vacio"><p>Aun no hay compras registradas con este proveedor.</p></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
