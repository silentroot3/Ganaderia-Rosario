<?php
// ============================================
// VENTAS - Hoja de venta (el admin ve ademas costo y ganancia real por animal)
// ============================================

$titulo = 'Detalle de venta';
$modulo = 'ventas';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT v.*, c.nombre AS comprador, c.rfc, c.telefono, u.nombre AS un, u.apellido AS ua
                      FROM ventas v INNER JOIN compradores c ON c.id = v.comprador_id LEFT JOIN usuarios u ON u.id = v.usuario_id WHERE v.id = ?");
$stmt->execute([$id]);
$v = $stmt->fetch();
if (!$v) { header('Location: ' . BASE_URL . '/ventas'); exit; }

$stmt = $db->prepare("SELECT dv.*, b.id_siniiga, b.categoria, b.sexo, b.peso_entrada FROM detalle_ventas dv INNER JOIN bovinos b ON b.id = dv.bovino_id WHERE dv.venta_id = ? ORDER BY b.id_siniiga");
$stmt->execute([$id]);
$det = $stmt->fetchAll();

$verCostos = puedeVerPrecios();   // el vendedor no ve lo invertido ni la ganancia
$costos = $verCostos ? costosBovinos(array_map(fn($d) => (int)$d['bovino_id'], $det)) : [];
$totIng = 0; $totCosto = 0; $kilos = 0;
?>
<style>@media print { .sidebar, .top-bar, .no-print { display:none !important; } .main-content { margin:0 !important; } }</style>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success no-print" style="margin-bottom:20px;"><?= h($_GET['msg']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-error no-print" style="margin-bottom:20px;"><?= h($_GET['error']) ?></div><?php endif; ?>
<?php if ($v['estado'] === 'cancelada'): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><strong>Venta cancelada.</strong> Motivo: <?= h($v['motivo_cancelacion'] ?? '-') ?>. No cuenta en reportes.</div>
<?php endif; ?>
<div class="no-print" style="display:flex;gap:10px;margin-bottom:20px;">
    <a href="<?= BASE_URL ?>/ventas" class="btn btn-secondary">Volver</a>
    <button onclick="window.print()" class="btn btn-secondary">Imprimir / PDF</button>
</div>

<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Venta <?= h($v['folio']) ?></h2><?= $v['estado'] === 'activa' ? pill('Activa', 'var(--f-verde)', 'var(--t-verde)') : pill('Cancelada', 'var(--f-rojo)', 'var(--t-rojo)') ?></div>
    <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;">
        <div><small style="color:var(--tenue);">COMPRADOR</small><div><strong><?= h($v['comprador']) ?></strong></div><div style="color:var(--tenue);"><?= h($v['rfc']) ?> <?= h($v['telefono']) ?></div></div>
        <div><small style="color:var(--tenue);">FECHA</small><div><?= fechaCorta($v['fecha']) ?> <?= h(substr((string)$v['hora'], 0, 5)) ?></div></div>
        <div><small style="color:var(--tenue);">FACTURA / GUIA</small><div><?= h($v['factura'] ?: '-') ?> / <?= h($v['guia_senasica'] ?: '-') ?></div></div>
        <div><small style="color:var(--tenue);">REGISTRO</small><div><?= h(trim($v['un'] . ' ' . $v['ua'])) ?></div></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header"><h2>Animales vendidos</h2></div>
    <table>
        <thead><tr><th>Arete</th><th>Categoria</th><th>Peso entrada</th><th>Peso final</th><th>Precio/kg</th><th>Ingreso</th><?php if ($verCostos): ?><th>Costo total</th><th>Ganancia real</th><th>Margen</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($det as $d):
            $c = $costos[$d['bovino_id']]['total'] ?? 0;
            $g = (float)$d['subtotal'] - $c;
            $totIng += (float)$d['subtotal']; $totCosto += $c; $kilos += (float)$d['peso_final']; ?>
            <tr>
                <td><a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($d['id_siniiga']) ?>"><strong><?= h($d['id_siniiga']) ?></strong></a></td>
                <td><?= h($d['categoria']) ?></td>
                <td><?= number_format($d['peso_entrada'], 1) ?></td>
                <td><?= number_format($d['peso_final'], 1) ?></td>
                <td><?= dinero($d['precio_kg']) ?></td>
                <td><?= dinero($d['subtotal']) ?></td>
                <?php if ($verCostos): ?>
                <td><?= dinero($c) ?></td>
                <td style="font-weight:700;color:<?= $g >= 0 ? '#28a745' : '#dc3545' ?>;"><?= dinero($g) ?></td>
                <td><?= $c > 0 ? number_format($g / $c * 100, 1) . '%' : '-' ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:700;background:var(--suave);">
                <td colspan="3">TOTAL (<?= count($det) ?> cabezas)</td><td><?= number_format($kilos, 1) ?></td><td></td>
                <td><?= dinero($totIng) ?></td>
                <?php if ($verCostos): ?><td><?= dinero($totCosto) ?></td>
                <td style="color:<?= $totIng - $totCosto >= 0 ? '#28a745' : '#dc3545' ?>;"><?= dinero($totIng - $totCosto) ?></td>
                <td><?= $totCosto > 0 ? number_format(($totIng - $totCosto) / $totCosto * 100, 1) . '%' : '-' ?></td><?php endif; ?>
            </tr>
        </tfoot>
    </table>
    <div style="padding:20px;text-align:right;line-height:1.8;">
        Subtotal: <?= dinero($v['subtotal']) ?><br>IVA: <?= dinero($v['iva']) ?><br>
        <span style="font-size:1.3rem;">Total a cobrar: <strong><?= dinero($v['total']) ?></strong></span>
    </div>
</div>

<?php if ($v['estado'] === 'activa' && Permisos::esAdmin()): ?>
<div class="table-container no-print" style="margin-top:25px;">
    <div class="table-header"><h2>Cancelar esta venta</h2></div>
    <form method="POST" action="<?= BASE_URL ?>/ventas/cancelar" style="padding:20px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
        <?= csrfField() ?><input type="hidden" name="id" value="<?= $v['id'] ?>">
        <div>
            <label style="<?= ESTILO_LABEL ?>">Motivo *</label>
            <input type="text" name="motivo" required placeholder="Ej: error en la captura, el comprador no se llevo el ganado" style="<?= ESTILO_INPUT ?>">
            <small style="color:var(--tenue);">Los <?= count($det) ?> animales regresan al corral del que salieron (o a RECEP si ya no existe o esta lleno).</small>
        </div>
        <button type="submit" class="btn" style="background:#dc3545;color:#fff;" onclick="return confirm('¿Cancelar la venta <?= h($v['folio']) ?>? Los animales regresan al rancho.')">Cancelar venta</button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
