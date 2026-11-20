<?php
// ============================================
// COSTOS - Desglose de gasto de un animal
// ============================================

$titulo = 'Costo del animal';
$modulo = 'costos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT b.*, c.nombre AS corral, comp.folio AS compra_folio, comp.precio_por_kg, comp.precio_total, comp.cantidad_total, comp.fecha_compra
    FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id LEFT JOIN compras comp ON comp.id = b.compra_id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b) { header('Location: ' . BASE_URL . '/costos'); exit; }

$c = costoBovino($id);
$peso = pesoActualBovino($id);
$ganado = $peso - (float)$b['peso_entrada'];

$stmt = $db->prepare("SELECT t.*, m.nombre FROM tratamientos t INNER JOIN medicamentos m ON m.id = t.medicamento_id WHERE t.bovino_id = ? ORDER BY t.fecha_aplicacion");
$stmt->execute([$id]); $trat = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM costos WHERE bovino_id = ? ORDER BY fecha");
$stmt->execute([$id]); $otros = $stmt->fetchAll();

$venta = null;
$stmt = $db->prepare("SELECT dv.*, v.folio, v.fecha FROM detalle_ventas dv INNER JOIN ventas v ON v.id = dv.venta_id WHERE dv.bovino_id = ? AND v.estado = 'activa' ORDER BY dv.id DESC LIMIT 1");
$stmt->execute([$id]); $venta = $stmt->fetch();

$minimo = $peso > 0 ? $c['total'] / $peso : 0;
?>

<div style="display:flex;gap:10px;margin-bottom:20px;">
    <a href="<?= BASE_URL ?>/costos" class="btn btn-secondary">Volver</a>
    <a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($b['id_siniiga']) ?>" class="btn btn-secondary">Hoja de vida</a>
    <a href="<?= BASE_URL ?>/costos/gasto?arete=<?= h($b['id_siniiga']) ?>" class="btn btn-primary">+ Gasto a este animal</a>
</div>

<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2><?= h($b['id_siniiga']) ?> <?= pillEstado($b['estado']) ?></h2></div>
    <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px;">
        <?php
        $tarjetas = [
            ['Compra', $c['compra'], $b['compra_folio'] ? 'Compra ' . $b['compra_folio'] : ''],
            ['Alimentacion', $c['alimentacion'], 'Repartido por dia en corral'],
            ['Medicinas', $c['veterinario'], count($trat) . ' tratamiento(s)'],
            ['Otros gastos', $c['otros'], count($otros) . ' registro(s) + ' . dinero($c['generales']) . ' de gastos generales'],
            ['TOTAL INVERTIDO', $c['total'], 'Peso actual ' . number_format($peso, 1) . ' kg'],
            ['Precio minimo para no perder', $minimo, 'por kilo (total / peso actual)'],
        ];
        foreach ($tarjetas as $t): ?>
            <div style="padding:14px;background:var(--suave);border-radius:8px;">
                <div style="font-size:0.75rem;color:var(--tenue);text-transform:uppercase;font-weight:600;"><?= h($t[0]) ?></div>
                <div style="font-size:1.4rem;font-weight:700;color:#0b4f86;"><?= dinero($t[1]) ?></div>
                <div style="font-size:0.78rem;color:var(--tenue);"><?= h($t[2]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($venta): $ing = (float)$venta['subtotal']; $gan = $ing - $c['total']; ?>
    <div style="margin:0 20px 20px;padding:15px;border-radius:8px;background:<?= $gan >= 0 ? 'var(--f-verde)' : 'var(--f-rojo)' ?>;">
        <strong>Vendido (<?= h($venta['folio']) ?>, <?= fechaCorta($venta['fecha']) ?>):</strong>
        <?= number_format($venta['peso_final'], 1) ?> kg x <?= dinero($venta['precio_kg']) ?> = <?= dinero($ing) ?> &nbsp;|&nbsp;
        <strong>Ganancia real: <?= dinero($gan) ?></strong> (<?= $c['total'] > 0 ? number_format($gan / $c['total'] * 100, 1) : '0' ?>% sobre lo invertido)
    </div>
    <?php endif; ?>
</div>

<?php if ($trat): ?>
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Medicinas aplicadas</h2></div>
    <table><thead><tr><th>Fecha</th><th>Medicamento</th><th>Dosis</th><th>Costo</th></tr></thead><tbody>
    <?php foreach ($trat as $t): ?><tr><td><?= fechaHora($t['fecha_aplicacion']) ?></td><td><?= h($t['nombre']) ?></td><td><?= number_format($t['dosis'], 2) ?></td><td><?= dinero($t['costo']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php endif; ?>

<?php if ($otros): ?>
<div class="table-container">
    <div class="table-header"><h2>Otros gastos</h2></div>
    <table><thead><tr><th>Fecha</th><th>Tipo</th><th>Descripcion</th><th>Monto</th></tr></thead><tbody>
    <?php foreach ($otros as $o): ?><tr><td><?= fechaCorta($o['fecha']) ?></td><td><?= h($o['tipo']) ?></td><td><?= h($o['descripcion']) ?></td><td><?= dinero($o['monto']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
