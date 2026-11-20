<?php
// ============================================
// COMPRAS - Todo lo que compra el rancho: ganado, alimento y medicamento
// En el periodo: cuanto se compro y la resta  inicio + comprado - usado = queda
// ============================================

$titulo = 'Compras';
$modulo = 'compras';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$hoy = date('Y-m-d');
$rangos = [
    'mes'      => ['Este mes', date('Y-m-01'), $hoy],
    'anterior' => ['Mes pasado', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90'       => ['Últimos 90 días', date('Y-m-d', strtotime('-89 day')), $hoy],
    'anio'     => ['Este año', date('Y-01-01'), $hoy],
];
$r = $_GET['r'] ?? 'mes';
$fechaOk = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) ? $f : null;
if (isset($rangos[$r])) { [, $desde, $hasta] = $rangos[$r]; }
else { $desde = $fechaOk($_GET['desde'] ?? '') ?: date('Y-m-01'); $hasta = $fechaOk($_GET['hasta'] ?? '') ?: $hoy; $r = ''; }
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$finHasta = "$hasta 23:59:59";
$tipo = in_array($_GET['tipo'] ?? '', ['ganado', 'alimento', 'medicamento'], true) ? $_GET['tipo'] : '';

// ---------- Totales del periodo ----------
$q = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(precio_total), 0) monto, COALESCE(SUM(cantidad_total), 0) cabezas, COALESCE(SUM(peso_total_compra), 0) kg
                   FROM compras WHERE estado != 'cancelada' AND fecha_compra BETWEEN ? AND ?");
$q->execute([$desde, $hasta]);
$tGanado = $q->fetch(PDO::FETCH_ASSOC);
$q = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(cantidad * COALESCE(precio_unitario, 0)), 0) monto FROM movimientos_inventario WHERE tipo = 'entrada' AND fecha BETWEEN ? AND ?");
$q->execute([$desde, $finHasta]);
$tAlim = $q->fetch(PDO::FETCH_ASSOC);
$q = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(cantidad * COALESCE(precio_unitario, 0)), 0) monto FROM movimientos_medicamento WHERE tipo = 'entrada' AND bovino_id IS NULL AND fecha BETWEEN ? AND ?");
$q->execute([$desde, $finHasta]);
$tMed = $q->fetch(PDO::FETCH_ASSOC);
$total = $tGanado['monto'] + $tAlim['monto'] + $tMed['monto'];

// ---------- La resta: inicio + comprado - usado = queda ----------
// Alimento: entrada = compra, salida = mezcla, ajuste = sobrante de mezcla que regresa
$q = $db->prepare("
    SELECT i.id, i.nombre, COALESCE(inv.stock_actual, 0) actual,
           COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND m.tipo = 'entrada' THEN m.cantidad END), 0) comprado,
           COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND m.tipo = 'salida' THEN m.cantidad END), 0)
         - COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND m.tipo = 'ajuste' THEN m.cantidad END), 0) usado,
           COALESCE(SUM(CASE WHEN m.fecha > ? THEN IF(m.tipo = 'salida', -m.cantidad, m.cantidad) END), 0) despues
    FROM ingredientes i LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = i.id
    LEFT JOIN movimientos_inventario m ON m.ingrediente_id = i.id
    WHERE i.activo = 1 GROUP BY i.id ORDER BY i.nombre");
$q->execute([$desde, $finHasta, $desde, $finHasta, $desde, $finHasta, $finHasta]);
$restaAlim = $q->fetchAll(PDO::FETCH_ASSOC);
// Medicamento: entrada sin bovino = compra; entrada con bovino = dosis que regresaron; salida = aplicado
$q = $db->prepare("
    SELECT md.id, md.nombre, COALESCE(inv.unidad, 'ml') unidad, COALESCE(inv.stock_actual, 0) actual,
           COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND m.tipo = 'entrada' AND m.bovino_id IS NULL THEN m.cantidad END), 0) comprado,
           COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND m.tipo = 'salida' THEN m.cantidad END), 0)
         - COALESCE(SUM(CASE WHEN m.fecha BETWEEN ? AND ? AND (m.tipo = 'ajuste' OR (m.tipo = 'entrada' AND m.bovino_id IS NOT NULL)) THEN m.cantidad END), 0) usado,
           COALESCE(SUM(CASE WHEN m.fecha > ? THEN IF(m.tipo = 'salida', -m.cantidad, m.cantidad) END), 0) despues
    FROM medicamentos md LEFT JOIN inventario_medicamentos inv ON inv.medicamento_id = md.id
    LEFT JOIN movimientos_medicamento m ON m.medicamento_id = md.id
    WHERE md.activo = 1 GROUP BY md.id ORDER BY md.nombre");
$q->execute([$desde, $finHasta, $desde, $finHasta, $desde, $finHasta, $finHasta]);
$restaMed = $q->fetchAll(PDO::FETCH_ASSOC);
foreach ([&$restaAlim, &$restaMed] as &$lista) {
    foreach ($lista as &$x) {
        $x['final'] = (float)$x['actual'] - (float)$x['despues'];
        $x['inicio'] = $x['final'] - (float)$x['comprado'] + (float)$x['usado'];
    }
    unset($x);
}
unset($lista);
// Ganado: comprados - vendidos - muertos = en el rancho
$q = $db->prepare("SELECT
        (SELECT COUNT(*) FROM bovinos WHERE DATE(created_at) < ?) -
        (SELECT COUNT(*) FROM bovinos WHERE estado IN ('vendido','muerto','devuelto') AND COALESCE(fecha_salida, fecha_baja, updated_at) < ?) inicio,
        (SELECT COUNT(*) FROM bovinos WHERE DATE(created_at) BETWEEN ? AND ?) entraron,
        (SELECT COUNT(*) FROM detalle_ventas dv JOIN ventas v ON v.id = dv.venta_id WHERE v.estado = 'activa' AND v.fecha BETWEEN ? AND ?) vendidos,
        (SELECT COUNT(*) FROM bovinos WHERE estado IN ('muerto','devuelto') AND DATE(COALESCE(fecha_baja, updated_at)) BETWEEN ? AND ?) bajas,
        (SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')) hoy");
$q->execute([$desde, $desde, $desde, $hasta, $desde, $hasta, $desde, $hasta]);
$rg = $q->fetch(PDO::FETCH_ASSOC);

// ---------- Lista de compras del periodo ----------
$partes = [];
$params = [];
if (!$tipo || $tipo === 'ganado') {
    $partes[] = "SELECT 'ganado' tipo, c.fecha_compra fecha, c.folio ref, COALESCE(p.nombre, c.origen_nombre) proveedor, c.proveedor_id pid,
                        CONCAT(c.cantidad_total, ' cabezas · ', FORMAT(c.peso_total_compra, 0), ' kg') concepto, c.precio_total monto, c.id link_id, c.estado
                 FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id WHERE c.fecha_compra BETWEEN ? AND ?";
    array_push($params, $desde, $hasta);
}
if (!$tipo || $tipo === 'alimento') {
    $partes[] = "SELECT 'alimento' tipo, DATE(m.fecha) fecha, m.referencia ref, COALESCE(p.nombre, m.proveedor) proveedor, m.proveedor_id pid,
                        CONCAT(i.nombre, ' · ', FORMAT(m.cantidad, 0), ' kg') concepto, m.cantidad * COALESCE(m.precio_unitario, 0) monto, NULL link_id, 'recibida' estado
                 FROM movimientos_inventario m JOIN ingredientes i ON i.id = m.ingrediente_id LEFT JOIN proveedores p ON p.id = m.proveedor_id
                 WHERE m.tipo = 'entrada' AND m.fecha BETWEEN ? AND ?";
    array_push($params, $desde, $finHasta);
}
if (!$tipo || $tipo === 'medicamento') {
    $partes[] = "SELECT 'medicamento' tipo, DATE(m.fecha) fecha, m.referencia ref, p.nombre proveedor, m.proveedor_id pid,
                        CONCAT(md.nombre, ' · ', FORMAT(m.cantidad, 1), ' ', COALESCE(inv.unidad, 'ml')) concepto, m.cantidad * COALESCE(m.precio_unitario, 0) monto, NULL link_id, 'recibida' estado
                 FROM movimientos_medicamento m JOIN medicamentos md ON md.id = m.medicamento_id LEFT JOIN inventario_medicamentos inv ON inv.medicamento_id = md.id
                 LEFT JOIN proveedores p ON p.id = m.proveedor_id
                 WHERE m.tipo = 'entrada' AND m.bovino_id IS NULL AND m.fecha BETWEEN ? AND ?";
    array_push($params, $desde, $finHasta);
}
$q = $db->prepare(implode(' UNION ALL ', $partes) . " ORDER BY fecha DESC LIMIT 300");
$q->execute($params);
$lista = $q->fetchAll(PDO::FETCH_ASSOC);
$pendientes = (int)$db->query("SELECT COUNT(*) FROM solicitudes_compra WHERE estado IN ('pendiente','aprobada','pedida')")->fetchColumn();
$fmt = fn($n, $d = 0) => number_format((float)$n, $d);
$qs = fn($extra) => '?' . http_build_query(array_merge(array_filter(['r' => $r, 'desde' => $r ? null : $desde, 'hasta' => $r ? null : $hasta, 'tipo' => $tipo]), $extra));
?>

<div class="page-actions">
    <div class="segmentado">
        <?php foreach ($rangos as $k => $x): ?><a href="<?= $qs(['r' => $k, 'desde' => null, 'hasta' => null]) ?>" class="<?= $r === $k ? 'on' : '' ?>"><?= $x[0] ?></a><?php endforeach; ?>
    </div>
    <form method="GET" class="rango">
        <input type="date" name="desde" value="<?= h($desde) ?>" max="<?= $hoy ?>"> a <input type="date" name="hasta" value="<?= h($hasta) ?>" max="<?= $hoy ?>">
        <?php if ($tipo): ?><input type="hidden" name="tipo" value="<?= h($tipo) ?>"><?php endif; ?>
        <button class="btn btn-secondary btn-sm" type="submit">Ver</button>
    </form>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Total comprado</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($total) ?></div><div class="stat-desc"><?= fechaCorta($desde) ?> al <?= fechaCorta($hasta) ?></div></div>
    <a class="stat-card yellow enlace" href="<?= $qs(['tipo' => 'ganado']) ?>"><div class="stat-label">Ganado</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tGanado['monto']) ?></div><div class="stat-desc"><?= (int)$tGanado['cabezas'] ?> cabezas en <?= (int)$tGanado['n'] ?> compra(s)</div></a>
    <a class="stat-card green enlace" href="<?= $qs(['tipo' => 'alimento']) ?>"><div class="stat-label">Alimento</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tAlim['monto']) ?></div><div class="stat-desc"><?= (int)$tAlim['n'] ?> entrada(s) a bodega</div></a>
    <a class="stat-card purple enlace" href="<?= $qs(['tipo' => 'medicamento']) ?>"><div class="stat-label">Medicamento</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tMed['monto']) ?></div><div class="stat-desc"><?= (int)$tMed['n'] ?> entrada(s)</div></a>
</div>

<div class="acciones-compra">
    <a href="<?= BASE_URL ?>/configuracion/compras/crear" class="btn btn-primary">+ Compra de ganado</a>
    <a href="<?= BASE_URL ?>/alimentacion/inventario" class="btn btn-secondary">+ Entrada de alimento</a>
    <a href="<?= BASE_URL ?>/tratamientos/inventario" class="btn btn-secondary">+ Entrada de medicamento</a>
    <a href="<?= BASE_URL ?>/compras/ganado" class="btn btn-secondary">Compras de ganado (folios, guías)</a>
    <a href="<?= BASE_URL ?>/solicitudes" class="btn btn-secondary">Solicitudes por comprar<?= $pendientes ? ' <span class="badge-num">' . $pendientes . '</span>' : '' ?></a>
</div>

<!-- La resta -->
<div class="table-container">
    <div class="table-header"><div><h2>Lo que había + lo que compramos − lo que usamos = lo que queda</h2>
        <small class="muted">Del <?= fechaCorta($desde) ?> al <?= fechaCorta($hasta) ?></small></div></div>

    <div class="resta-ganado">
        <div><small>Había</small><strong><?= (int)$rg['inicio'] ?></strong></div><span>+</span>
        <div><small>Llegaron</small><strong class="txt-verde"><?= (int)$rg['entraron'] ?></strong></div><span>−</span>
        <div><small>Vendidos</small><strong><?= (int)$rg['vendidos'] ?></strong></div><span>−</span>
        <div><small>Muertos / bajas</small><strong class="txt-rojo"><?= (int)$rg['bajas'] ?></strong></div><span>=</span>
        <div class="total"><small>Cabezas</small><strong><?= (int)$rg['inicio'] + (int)$rg['entraron'] - (int)$rg['vendidos'] - (int)$rg['bajas'] ?></strong></div>
        <p class="muted">Hoy hay <strong><?= (int)$rg['hoy'] ?></strong> cabezas en el rancho.</p>
    </div>

    <div class="tabs" style="margin:0 20px;"><a href="#t-alim" class="tab on" data-tab="t-alim">Alimento (kg)</a><a href="#t-med" class="tab" data-tab="t-med">Medicamento</a></div>
    <?php foreach (['t-alim' => [$restaAlim, 'kg'], 't-med' => [$restaMed, null]] as $tid => [$filas, $u]): ?>
    <div id="<?= $tid ?>" class="tab-panel" <?= $tid === 't-med' ? 'hidden' : '' ?>>
        <table>
            <thead><tr><th>Producto</th><th class="num">Había</th><th class="num">+ Compramos</th><th class="num">− Usamos</th><th class="num">= Queda</th></tr></thead>
            <tbody>
            <?php foreach ($filas as $x): if (abs($x['inicio']) < .01 && $x['comprado'] == 0 && $x['usado'] == 0 && abs($x['final']) < .01) continue; $uu = $u ?? $x['unidad']; ?>
                <tr><td><strong><?= h($x['nombre']) ?></strong></td>
                    <td class="num"><?= $fmt($x['inicio'], 1) ?> <?= h($uu) ?></td>
                    <td class="num txt-verde"><?= $x['comprado'] > 0 ? '+' . $fmt($x['comprado'], 1) : '—' ?></td>
                    <td class="num"><?= $x['usado'] != 0 ? '−' . $fmt($x['usado'], 1) : '—' ?></td>
                    <td class="num"><strong><?= $fmt($x['final'], 1) ?> <?= h($uu) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-container">
    <div class="table-header"><h2>Compras del periodo (<?= count($lista) ?>)</h2>
        <div class="tabs" style="border:0;margin:0;">
            <?php foreach (['' => 'Todo', 'ganado' => 'Ganado', 'alimento' => 'Alimento', 'medicamento' => 'Medicamento'] as $k => $t): ?>
                <a href="<?= $qs(['tipo' => $k ?: null]) ?>" class="tab <?= $tipo === $k ? 'on' : '' ?>"><?= $t ?></a>
            <?php endforeach; ?>
        </div></div>
    <?php if ($lista): ?>
    <table>
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Proveedor</th><th>Concepto</th><th>Referencia</th><th class="num">Importe</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $c): ?>
            <tr<?= $c['estado'] === 'cancelada' ? ' style="opacity:.5"' : '' ?>>
                <td><?= fechaCorta($c['fecha']) ?></td>
                <td><span class="chip <?= ['ganado' => 'chip-azul', 'alimento' => 'chip-verde', 'medicamento' => 'chip-rojo'][$c['tipo']] ?>"><?= ucfirst($c['tipo']) ?></span></td>
                <td><?= $c['pid'] ? '<a href="' . BASE_URL . '/proveedores/ver?id=' . (int)$c['pid'] . '">' . h($c['proveedor']) . '</a>' : h($c['proveedor'] ?: '—') ?></td>
                <td><?= h($c['concepto']) ?></td>
                <td class="muted"><?= $c['link_id'] ? '<a href="' . BASE_URL . '/configuracion/compras/detalle?id=' . (int)$c['link_id'] . '">' . h($c['ref']) . '</a>' : h($c['ref']) ?><?= $c['estado'] === 'cancelada' ? ' (cancelada)' : '' ?></td>
                <td class="num"><strong><?= $c['monto'] > 0 ? dinero($c['monto']) : '—' ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><p style="padding:30px;text-align:center;" class="muted">No hay compras en este periodo.</p><?php endif; ?>
</div>

<style>
.segmentado { display: inline-flex; flex-wrap: wrap; background: var(--superficie); border: 1px solid var(--linea-fuerte); border-radius: 8px; padding: 3px; gap: 3px; }
.segmentado a { padding: 6px 12px; border-radius: 6px; font-size: .85rem; font-weight: 500; color: var(--texto-2); }
.segmentado a:hover { text-decoration: none; background: var(--fondo); }
.segmentado a.on { background: var(--azul); color: #fff; }
.rango { display: flex; gap: 8px; align-items: center; margin-left: auto; color: var(--tenue); font-size: .88rem; }
.stat-card.enlace { color: inherit; } .stat-card.enlace:hover { text-decoration: none; }
.acciones-compra { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.txt-verde { color: var(--verde); } .txt-rojo { color: var(--rojo); }
.resta-ganado { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 18px 20px; border-bottom: 1px solid var(--linea); }
.resta-ganado > div { min-width: 90px; }
.resta-ganado small { display: block; font-size: .72rem; text-transform: uppercase; color: var(--tenue); letter-spacing: .04em; }
.resta-ganado strong { font-size: 1.5rem; }
.resta-ganado > span { font-size: 1.4rem; color: var(--tenue); }
.resta-ganado .total strong { color: var(--azul); }
.resta-ganado p { width: 100%; font-size: .85rem; }
</style>
<script>
document.querySelectorAll('[data-tab]').forEach(function (t) {
    t.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === t); document.getElementById(x.dataset.tab).hidden = x !== t; });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
