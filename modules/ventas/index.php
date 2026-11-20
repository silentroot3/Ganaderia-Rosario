<?php
// ============================================
// VENTAS - Listado de ventas (HU-014)
// ============================================

$titulo = 'Ventas';
$modulo = 'ventas';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/tratamientos_lib.php';

$db = Database::getInstance()->getConnection();

$ventas = $db->query("
    SELECT v.*, c.nombre AS comprador,
           (SELECT COUNT(*) FROM detalle_ventas dv WHERE dv.venta_id = v.id) AS cabezas,
           (SELECT COALESCE(SUM(dv.peso_final),0) FROM detalle_ventas dv WHERE dv.venta_id = v.id) AS kilos
    FROM ventas v INNER JOIN compradores c ON c.id = v.comprador_id
    ORDER BY v.fecha DESC, v.id DESC LIMIT 200
")->fetchAll();

$mes = $db->query("
    SELECT COUNT(*), COALESCE(SUM(total),0) FROM ventas
    WHERE estado = 'activa' AND YEAR(fecha) = YEAR(CURDATE()) AND MONTH(fecha) = MONTH(CURDATE())
")->fetch(PDO::FETCH_NUM);

$disponibles = $db->query("
    SELECT COUNT(*) FROM bovinos b
    WHERE b.estado = 'en_corral' AND b.en_hospital = 0 AND " . sqlSinRetiro('b'))->fetchColumn();

?>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card green"><div class="stat-label">Ventas del mes</div><div class="stat-value"><?= (int)$mes[0] ?></div><div class="stat-desc"><?= dinero($mes[1]) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Listos para venta</div><div class="stat-value"><?= (int)$disponibles ?></div><div class="stat-desc">Sin retiro de medicina activo</div></div>
</div>

<?php
$pendDesc = (int)$db->query("SELECT COUNT(*) FROM solicitudes_descuento WHERE estado = 'pendiente'" . (Permisos::esAdmin() ? '' : ' AND usuario_id = ' . (int)$_SESSION['user_id']))->fetchColumn();
$aprobDesc = Permisos::esAdmin() ? 0 : (int)$db->query("SELECT COUNT(*) FROM solicitudes_descuento WHERE estado = 'aprobada' AND usuario_id = " . (int)$_SESSION['user_id'])->fetchColumn();
?>
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (Permisos::puedeCrear('ventas')): ?><a href="<?= BASE_URL ?>/ventas/crear" class="btn btn-primary">+ Nueva venta</a><?php endif; ?>
    <a href="<?= BASE_URL ?>/ventas/precios" class="btn btn-secondary">Corrales de venta y precios</a>
    <a href="<?= BASE_URL ?>/ventas/descuentos" class="btn btn-secondary">Descuentos<?= $pendDesc ? ' <span class="badge-num">' . $pendDesc . ' pendiente(s)</span>' : '' ?><?= $aprobDesc ? ' <span class="badge-num" style="background:#28a745;">' . $aprobDesc . ' autorizado(s)</span>' : '' ?></a>
</div>

<div class="table-container">
    <div class="table-header"><h2>Ventas registradas</h2></div>
    <?php if ($ventas): ?>
    <table>
        <thead><tr><th>Folio</th><th>Fecha</th><th>Comprador</th><th>Cabezas</th><th>Kilos</th><th>Total</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ventas as $v): ?>
            <tr>
                <td><strong><?= h($v['folio']) ?></strong></td>
                <td><?= fechaCorta($v['fecha']) ?></td>
                <td><?= h($v['comprador']) ?></td>
                <td><?= (int)$v['cabezas'] ?></td>
                <td><?= number_format($v['kilos'], 1) ?></td>
                <td><strong><?= dinero($v['total']) ?></strong></td>
                <td><?= $v['estado'] === 'activa' ? pill('Activa', 'var(--f-verde)', 'var(--t-verde)') : pill('Cancelada', 'var(--f-rojo)', 'var(--t-rojo)') ?></td>
                <td><a href="<?= BASE_URL ?>/ventas/detalle?id=<?= $v['id'] ?>" class="btn btn-secondary btn-sm">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">Aun no hay ventas.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
