<?php
// ============================================
// REPORTES - Ventas y rentabilidad por rango de fechas (HU-015)
// ============================================

$titulo = 'Reportes';
$modulo = 'reportes';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/reportes_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

[$desde, $hasta] = normalizarRango($_GET['desde'] ?? '', $_GET['hasta'] ?? '');
$tipo = ($_GET['tipo'] ?? 'rentabilidad') === 'ventas' ? 'ventas' : 'rentabilidad';

$ventas = datosVentas($desde, $hasta);
$rent = datosRentabilidad($desde, $hasta);
$t = $rent['totales'];
$qs = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$maxMes = 0; foreach ($rent['meses'] as $m) $maxMes = max($maxMes, $m['ingreso']);
$nombresMes = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
?>
<style>@media print { .sidebar, .top-bar, .no-print { display:none !important; } .main-content { margin:0 !important; } }</style>

<form method="GET" class="no-print" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:20px;">
    <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
    <div><label style="<?= ESTILO_LABEL ?>">Desde</label><input type="date" name="desde" value="<?= h($desde) ?>" style="padding:8px;border:1px solid var(--linea);border-radius:6px;"></div>
    <div><label style="<?= ESTILO_LABEL ?>">Hasta</label><input type="date" name="hasta" value="<?= h($hasta) ?>" style="padding:8px;border:1px solid var(--linea);border-radius:6px;"></div>
    <button class="btn btn-primary">Generar</button>
    <a href="<?= BASE_URL ?>/reportes?tipo=rentabilidad&<?= $qs ?>" class="btn btn-sm <?= $tipo === 'rentabilidad' ? 'btn-primary' : 'btn-secondary' ?>">Rentabilidad</a>
    <a href="<?= BASE_URL ?>/reportes?tipo=ventas&<?= $qs ?>" class="btn btn-sm <?= $tipo === 'ventas' ? 'btn-primary' : 'btn-secondary' ?>">Ventas</a>
    <span style="margin-left:auto;display:flex;gap:8px;">
        <a href="<?= BASE_URL ?>/reportes/exportar?tipo=<?= $tipo ?>&<?= $qs ?>" class="btn btn-secondary">Descargar Excel (CSV)</a>
        <button type="button" onclick="window.print()" class="btn btn-secondary">PDF / Imprimir</button>
    </span>
</form>

<h2 style="margin-bottom:5px;">Reporte de <?= $tipo === 'ventas' ? 'ventas' : 'rentabilidad' ?></h2>
<p style="color:var(--tenue);margin-bottom:20px;">Del <?= fechaCorta($desde) ?> al <?= fechaCorta($hasta) ?> &middot; Ganaderia El Rosario</p>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card blue"><div class="stat-label">Cabezas vendidas</div><div class="stat-value"><?= $t['cabezas'] ?></div><div class="stat-desc"><?= number_format($t['kilos'], 1) ?> kg</div></div>
    <div class="stat-card green"><div class="stat-label">Ingresos</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($t['ingreso']) ?></div><div class="stat-desc">Sin IVA</div></div>
    <div class="stat-card yellow"><div class="stat-label">Costo de lo vendido</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($t['costo']) ?></div></div>
    <div class="stat-card purple"><div class="stat-label">Ganancia neta</div><div class="stat-value" style="font-size:1.5rem;color:<?= $rent['neta'] >= 0 ? '#0b4f86' : '#dc3545' ?>;"><?= dinero($rent['neta']) ?></div>
        <div class="stat-desc">Gastos generales ya repartidos en cada animal<?= $rent['perdidas'] > 0 ? ' · menos ' . dinero($rent['perdidas']) . ' por muertes' : '' ?><?= $rent['generales'] > 0 ? ' · menos ' . dinero($rent['generales']) . ' sin repartir' : '' ?></div></div>
</div>

<?php if ($rent['meses']): ?>
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Ingresos y ganancia por mes</h2></div>
    <div style="padding:20px;display:flex;gap:18px;align-items:flex-end;height:220px;overflow-x:auto;">
        <?php foreach ($rent['meses'] as $m => $d):
            $alt = $maxMes > 0 ? max(4, round($d['ingreso'] / $maxMes * 150)) : 4;
            $altG = $maxMes > 0 ? max(0, round(abs($d['ganancia']) / $maxMes * 150)) : 0; ?>
            <div style="text-align:center;min-width:80px;">
                <div style="display:flex;gap:4px;align-items:flex-end;justify-content:center;height:155px;">
                    <div title="Ingreso <?= dinero($d['ingreso']) ?>" style="width:28px;height:<?= $alt ?>px;background:#43a047;border-radius:4px 4px 0 0;"></div>
                    <div title="Ganancia <?= dinero($d['ganancia']) ?>" style="width:28px;height:<?= $altG ?>px;background:<?= $d['ganancia'] >= 0 ? '#1e88e5' : '#dc3545' ?>;border-radius:4px 4px 0 0;"></div>
                </div>
                <div style="font-size:0.8rem;font-weight:600;margin-top:6px;"><?= $nombresMes[substr($m, 5, 2)] . ' ' . substr($m, 0, 4) ?></div>
                <div style="font-size:0.72rem;color:var(--tenue);"><?= dinero($d['ingreso']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="padding:0 20px 15px;font-size:0.8rem;color:var(--tenue);"><span style="color:#43a047;">&#9632;</span> Ingreso &nbsp; <span style="color:#1e88e5;">&#9632;</span> Ganancia (rojo = perdida)</div>
</div>
<?php endif; ?>

<?php if ($tipo === 'ventas'): ?>
<div class="table-container">
    <div class="table-header"><h2>Ventas del periodo (<?= count($ventas) ?>)</h2></div>
    <?php if ($ventas): $sS = $sI = $sT = $sK = $sC = 0; ?>
    <table>
        <thead><tr><th>Folio</th><th>Fecha</th><th>Comprador</th><th>Cabezas</th><th>Kilos</th><th>Subtotal</th><th>IVA</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($ventas as $v): $sS += $v['subtotal']; $sI += $v['iva']; $sT += $v['total']; $sK += $v['kilos']; $sC += $v['cabezas']; ?>
            <tr><td><?= h($v['folio']) ?></td><td><?= fechaCorta($v['fecha']) ?></td><td><?= h($v['comprador']) ?></td><td><?= (int)$v['cabezas'] ?></td>
                <td><?= number_format($v['kilos'], 1) ?></td><td><?= dinero($v['subtotal']) ?></td><td><?= dinero($v['iva']) ?></td><td><strong><?= dinero($v['total']) ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:700;background:var(--suave);"><td colspan="3">TOTAL</td><td><?= $sC ?></td><td><?= number_format($sK, 1) ?></td><td><?= dinero($sS) ?></td><td><?= dinero($sI) ?></td><td><?= dinero($sT) ?></td></tr></tfoot>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">No hay ventas en este periodo.</div><?php endif; ?>
</div>
<?php else: ?>
<div class="table-container">
    <div class="table-header"><h2>Rentabilidad por animal (<?= $t['cabezas'] ?>)</h2></div>
    <?php if ($rent['filas']): ?>
    <table>
        <thead><tr><th>Fecha</th><th>Folio</th><th>Arete</th><th>Peso final</th><th>Ingreso</th><th>Compra</th><th>Alimento</th><th>Medicina</th><th>Otros</th><th>Costo</th><th>Ganancia</th><th>Margen</th></tr></thead>
        <tbody>
        <?php foreach ($rent['filas'] as $f): ?>
            <tr>
                <td><?= fechaCorta($f['fecha']) ?></td><td><?= h($f['folio']) ?></td>
                <td><a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($f['id_siniiga']) ?>"><?= h($f['id_siniiga']) ?></a></td>
                <td><?= number_format($f['peso_final'], 1) ?></td><td><?= dinero($f['subtotal']) ?></td>
                <td><?= dinero($f['c_compra']) ?></td><td><?= dinero($f['c_alimentacion']) ?></td><td><?= dinero($f['c_veterinario']) ?></td><td><?= dinero($f['c_otros']) ?></td>
                <td><?= dinero($f['costo']) ?></td>
                <td style="font-weight:700;color:<?= $f['ganancia'] >= 0 ? '#28a745' : '#dc3545' ?>;"><?= dinero($f['ganancia']) ?></td>
                <td><?= $f['margen'] !== null ? number_format($f['margen'], 1) . '%' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:700;background:var(--suave);">
            <td colspan="3">TOTAL</td><td><?= number_format($t['kilos'], 1) ?></td><td><?= dinero($t['ingreso']) ?></td>
            <td><?= dinero($t['compra']) ?></td><td><?= dinero($t['alimentacion']) ?></td><td><?= dinero($t['veterinario']) ?></td><td><?= dinero($t['otros']) ?></td>
            <td><?= dinero($t['costo']) ?></td><td><?= dinero($t['ganancia']) ?></td><td><?= $t['costo'] > 0 ? number_format($t['ganancia'] / $t['costo'] * 100, 1) . '%' : '-' ?></td>
        </tr></tfoot>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">No hay ventas en este periodo.</div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tipo !== 'ventas' && !empty($rent['muertes'])): ?>
<div class="table-container" style="margin-top:25px;">
    <div class="table-header"><h2>Perdidas por mortalidad (<?= count($rent['muertes']) ?>)</h2><strong style="color:#dc3545;"><?= dinero($rent['perdidas']) ?></strong></div>
    <table>
        <thead><tr><th>Fecha</th><th>Arete</th><th>Categoria</th><th>Causa</th><th>Invertido (perdido)</th></tr></thead>
        <tbody>
        <?php foreach ($rent['muertes'] as $m): ?>
            <tr><td><?= fechaCorta($m['fecha']) ?></td><td><strong><?= h($m['id_siniiga']) ?></strong></td><td><?= h($m['categoria']) ?></td>
                <td><?= h($m['causa_probable']) ?></td><td style="color:#dc3545;font-weight:700;"><?= dinero($m['costo']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
