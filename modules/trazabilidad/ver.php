<?php
// ============================================
// TRAZABILIDAD - Hoja de vida completa del animal (HU-016)
// Origen (rancho / UPP / REEMO), revisiones, clasificacion, corrales, comida,
// cada dosis de medicina y vacuna, pesajes, retiro y cuando se puede vender.
// ============================================

$titulo = 'Hoja de vida';
$modulo = 'trazabilidad';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$arete = trim($_GET['arete'] ?? '');

$stmt = $db->prepare("
    SELECT b.*, c.nombre AS corral, r.folio AS folio_recepcion, r.fecha_recepcion, r.chofer, r.placas,
           comp.folio AS compra_folio, comp.fecha_compra, comp.numero_reemo, comp.precio_por_kg,
           comp.origen_upp, comp.origen_nombre, comp.origen_localidad, comp.origen_municipio, comp.origen_estado,
           p.nombre AS proveedor, p.telefono AS prov_tel, p.upp AS prov_upp
    FROM bovinos b
    LEFT JOIN corrales_todos c ON c.id = b.corral_id
    LEFT JOIN recepciones r ON r.id = b.recepcion_id
    LEFT JOIN compras comp ON comp.id = b.compra_id
    LEFT JOIN proveedores p ON p.id = COALESCE(comp.proveedor_id, r.proveedor_id)
    WHERE b.id_siniiga = ?
");
$stmt->execute([$arete]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b) {
    header('Location: ' . BASE_URL . '/trazabilidad?q=' . urlencode($arete));
    exit;
}
$id = (int)$b['id'];
$hoy = date('Y-m-d');
$enRancho = !in_array($b['estado'], ['vendido', 'muerto', 'devuelto'], true);
$ev = []; // [fecha, tipo, titulo, detalle, pendiente?]
$upp = $b['origen_upp'] ?: $b['prov_upp'];

$ev[] = [$b['fecha_recepcion'] ?: $b['created_at'], 'Recepcion', 'Llego al rancho',
    trim('Recepcion ' . ($b['folio_recepcion'] ?? '-') . ($b['compra_folio'] ? ' | Compra ' . $b['compra_folio'] : '')
        . ($b['numero_reemo'] ? ' | REEMO ' . $b['numero_reemo'] : '')
        . ($b['proveedor'] ? ' | ' . $b['proveedor'] . ($upp ? ' (UPP ' . $upp . ')' : '') : '')
        . ($b['peso_entrada'] ? ' | Peso entrada ' . number_format($b['peso_entrada'], 1) . ' kg' : '')
        . ($b['llego_enfermo'] ? ' | Llego enfermo: ' . $b['motivo_llegada'] : ''))];

// ---------- revisiones ----------
$q = $db->prepare("SELECT r.*, u.nombre, u.apellido FROM revisiones r LEFT JOIN usuarios u ON u.id = r.veterinario_id WHERE r.bovino_id = ?");
$q->execute([$id]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ev[] = [$r['fecha'], 'Revision', 'Revision veterinaria: ' . $r['estado'],
        trim(($r['temperatura'] ? 'Temp ' . $r['temperatura'] . ' C. ' : '') . ($r['diagnostico_preliminar'] ?? '') . ' ' . ($r['motivo_rechazo'] ?? '')
        . ' (Dr. ' . $r['nombre'] . ' ' . $r['apellido'] . ')')];
}

// ---------- clasificaciones ----------
$q = $db->prepare("SELECT cl.*, u.nombre FROM clasificaciones cl LEFT JOIN usuarios u ON u.id = cl.usuario_id WHERE cl.bovino_id = ?");
$q->execute([$id]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ev[] = [$r['fecha'], 'Clasificacion', 'Datos actualizados por el clasificador',
        ($r['sexo'] === 'M' ? 'Macho' : 'Hembra') . ', ' . number_format($r['peso'], 1) . ' kg' . ($r['edad_meses'] ? ', ' . $r['edad_meses'] . ' meses' : '') . ($r['nombre'] ? ' (' . $r['nombre'] . ')' : '')];
}

// ---------- corrales ----------
$q = $db->prepare("SELECT m.*, co.nombre AS origen, cd.nombre AS destino FROM movimientos_bovino m
                   LEFT JOIN corrales_todos co ON co.id = m.corral_origen_id LEFT JOIN corrales_todos cd ON cd.id = m.corral_destino_id
                   WHERE m.bovino_id = ? ORDER BY m.fecha, m.id");
$q->execute([$id]);
$movs = $q->fetchAll(PDO::FETCH_ASSOC);
foreach ($movs as $r) {
    $ev[] = [$r['fecha'], 'Corral', ucfirst($r['tipo_movimiento']),
        ($r['origen'] ? $r['origen'] . ' -> ' : '') . ($r['destino'] ?: '(fuera del rancho)') . ($r['motivo'] ? ' | ' . $r['motivo'] : '')];
}

// ---------- medicinas: cada tratamiento con su calendario de dosis ----------
$q = $db->prepare("
    SELECT t.*, m.nombre AS medicamento, m.tipo AS tipo_med, u.nombre AS vet, u.apellido AS vet_ap,
           (SELECT COUNT(*) FROM tratamiento_dosis d WHERE d.tratamiento_id = t.id AND d.estado = 'aplicada') AS aplicadas,
           (SELECT COUNT(*) FROM tratamiento_dosis d WHERE d.tratamiento_id = t.id AND d.estado = 'pendiente') AS pendientes,
           (SELECT MIN(d.fecha_programada) FROM tratamiento_dosis d WHERE d.tratamiento_id = t.id AND d.estado = 'pendiente') AS proxima
    FROM tratamientos t
    INNER JOIN medicamentos m ON m.id = t.medicamento_id
    LEFT JOIN usuarios u ON u.id = t.veterinario_id
    WHERE t.bovino_id = ? ORDER BY t.fecha_aplicacion, t.id
");
$q->execute([$id]);
$tratamientos = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $db->prepare("
    SELECT d.*, m.nombre AS medicamento, t.num_dosis, t.diagnostico
    FROM tratamiento_dosis d INNER JOIN tratamientos t ON t.id = d.tratamiento_id INNER JOIN medicamentos m ON m.id = t.medicamento_id
    WHERE t.bovino_id = ? AND d.estado != 'cancelada'
");
$q->execute([$id]);
$conDosis = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $conDosis[$d['tratamiento_id']] = true;
    $pend = $d['estado'] === 'pendiente';
    $ev[] = [$pend ? $d['fecha_programada'] : ($d['fecha_aplicada'] ?: $d['fecha_programada']), 'Medicina',
        $d['medicamento'] . ($d['num_dosis'] > 1 ? " - dosis {$d['numero']} de {$d['num_dosis']}" : '') . ' (' . rtrim(rtrim(number_format($d['cantidad'], 2), '0'), '.') . ')'
        . ($pend ? ' - PENDIENTE' : ''),
        $d['diagnostico'] ?? '', $pend];
}
foreach ($tratamientos as $t) {
    // tratamientos viejos sin calendario de dosis
    if (empty($conDosis[$t['id']])) {
        $ev[] = [$t['fecha_aplicacion'], 'Medicina', $t['medicamento'] . ' (' . $t['dosis'] . ')', $t['diagnostico'] ?? ''];
    }
    if ($t['fecha_fin_retiro'] && $t['estado'] !== 'cancelado') {
        $ev[] = [date('Y-m-d', strtotime($t['fecha_fin_retiro'] . ' +1 day')) . ' 00:00:00', 'Retiro', 'Termina el retiro de ' . $t['medicamento'],
            'Desde este dia la carne ya no tiene residuos de ' . $t['medicamento'], $t['fecha_fin_retiro'] >= $hoy];
    }
}

// ---------- vacunas ----------
$q = $db->prepare("SELECT v.*, m.nombre FROM vacunaciones v INNER JOIN medicamentos m ON m.id = v.medicamento_id WHERE v.bovino_id = ? ORDER BY v.fecha_aplicacion, v.id");
$q->execute([$id]);
$vacunas = $q->fetchAll(PDO::FETCH_ASSOC);
foreach ($vacunas as $r) {
    $ev[] = [$r['created_at'] && substr($r['created_at'], 0, 10) === $r['fecha_aplicacion'] ? $r['created_at'] : $r['fecha_aplicacion'] . ' 12:00:00',
        'Vacuna', $r['nombre'] . ($r['observaciones'] ? ' (' . $r['observaciones'] . ')' : ''), $r['proxima_dosis'] ? 'Refuerzo ' . fechaCorta($r['proxima_dosis']) : ''];
}

// ---------- pesajes ----------
$q = $db->prepare("SELECT * FROM pesajes WHERE bovino_id = ? ORDER BY fecha, id");
$q->execute([$id]);
$pesajes = $q->fetchAll(PDO::FETCH_ASSOC);
foreach ($pesajes as $r) {
    $ev[] = [$r['fecha'] . ' ' . ($r['hora'] ?: '00:00:00'), 'Pesaje', number_format($r['peso'], 1) . ' kg',
        ($r['gdp'] !== null ? 'GDP ' . number_format($r['gdp'], 3) . ' kg/dia' : '') . ($r['observaciones'] ? ' | ' . $r['observaciones'] : '')];
}

// ---------- comida: lo que le toco de cada comida servida en su corral ----------
$q = $db->prepare("
    WITH estancias AS (
        SELECT m.bovino_id, m.corral_destino_id AS corral_id, DATE(m.fecha) AS desde,
               (SELECT IF(m2.corral_destino_id IS NULL, DATE_ADD(DATE(m2.fecha), INTERVAL 1 DAY), DATE(m2.fecha))
                FROM movimientos_bovino m2
                WHERE m2.bovino_id = m.bovino_id AND (m2.fecha > m.fecha OR (m2.fecha = m.fecha AND m2.id > m.id))
                ORDER BY m2.fecha, m2.id LIMIT 1) AS hasta
        FROM movimientos_bovino m WHERE m.corral_destino_id IS NOT NULL
    )
    SELECT ct.nombre AS corral, s.desde, s.hasta, d.nombre AS dieta,
           COUNT(a.id) AS comidas, MIN(a.fecha) AS primera, MAX(a.fecha) AS ultima,
           SUM(a.cantidad_kg / (SELECT COUNT(*) FROM estancias o WHERE o.corral_id = a.corral_id AND a.fecha >= o.desde AND (o.hasta IS NULL OR a.fecha < o.hasta))) AS kg
    FROM estancias s
    INNER JOIN alimentaciones a ON a.corral_id = s.corral_id AND a.fecha >= s.desde AND (s.hasta IS NULL OR a.fecha < s.hasta)
    INNER JOIN dietas d ON d.id = a.dieta_id
    INNER JOIN corrales_todos ct ON ct.id = s.corral_id
    WHERE s.bovino_id = ?
    GROUP BY s.corral_id, s.desde, s.hasta, d.id
    ORDER BY s.desde, primera
");
$q->execute([$id]);
$comida = $q->fetchAll(PDO::FETCH_ASSOC);
$kgComida = array_sum(array_column($comida, 'kg'));

// ---------- venta ----------
$q = $db->prepare("SELECT dv.*, v.folio, v.factura, v.fecha, c.nombre AS comprador FROM detalle_ventas dv INNER JOIN ventas v ON v.id = dv.venta_id
                   INNER JOIN compradores c ON c.id = v.comprador_id WHERE dv.bovino_id = ? AND v.estado = 'activa'");
$q->execute([$id]);
$venta = $q->fetch(PDO::FETCH_ASSOC);
if ($venta) {
    $ev[] = [$venta['fecha'] . ' 23:59:00', 'Venta', 'Vendido a ' . $venta['comprador'],
        'Folio ' . $venta['folio'] . ($venta['factura'] ? ' | Factura ' . $venta['factura'] : '') . ' | ' . number_format($venta['peso_final'], 1) . ' kg a ' . dinero($venta['precio_kg']) . '/kg'];
}
if (in_array($b['estado'], ['muerto', 'devuelto'], true)) {
    $ev[] = [$b['fecha_baja'] ?: $b['updated_at'], 'Baja', ucfirst($b['estado']), trim(($b['motivo_baja'] ?? '') . ' ' . ($b['observaciones_baja'] ?? ''))];
}

usort($ev, fn($x, $y) => strcmp((string)$x[0], (string)$y[0]));

$colores = ['Recepcion' => '#1e88e5', 'Revision' => '#43a047', 'Clasificacion' => '#fbc02d', 'Corral' => '#8e24aa',
            'Medicina' => '#dc3545', 'Vacuna' => '#00897b', 'Retiro' => '#e67e22', 'Pesaje' => '#6d4c41', 'Venta' => '#0b4f86', 'Baja' => '#343a40'];

// ---------- resumen de peso ----------
$ultimo = $pesajes ? end($pesajes) : null;
$pesoActual = $ultimo ? (float)$ultimo['peso'] : (float)$b['peso_entrada'];
$fechaPeso = $ultimo ? $ultimo['fecha'] : substr($b['fecha_recepcion'] ?: $b['created_at'], 0, 10);
$inicio = $b['fecha_recepcion'] ?: $b['created_at'];
$dias = max(1, (int)((strtotime($b['fecha_salida'] ?: 'now') - strtotime($inicio)) / 86400));
$diasPesados = max(1, (int)((strtotime($fechaPeso) - strtotime(substr($inicio, 0, 10))) / 86400));
$ganado = $pesoActual - (float)$b['peso_entrada'];
$gdp = $ganado > 0 ? $ganado / $diasPesados : 0;
// GDP reciente: promedio de los ultimos 2 pesajes con gdp (mas fiel a como va ahora)
$recientes = array_values(array_filter(array_column($pesajes, 'gdp'), fn($g) => $g !== null && $g > 0));
if (count($recientes) >= 2) $gdp = array_sum(array_slice($recientes, -2)) / 2;
$diasSinPesar = (int)((strtotime($hoy) - strtotime($fechaPeso)) / 86400);
$pesoHoy = $pesoActual + ($enRancho ? $gdp * $diasSinPesar : 0);

// ---------- ¿cuando se puede vender? ----------
$finRetiro = null; $medRetiro = '';
foreach ($tratamientos as $t) {
    if ($t['estado'] !== 'cancelado' && $t['fecha_fin_retiro'] && $t['fecha_fin_retiro'] >= $hoy && $t['fecha_fin_retiro'] > (string)$finRetiro) {
        $finRetiro = $t['fecha_fin_retiro']; $medRetiro = $t['medicamento'];
    }
}
$dosisPend = array_sum(array_column($tratamientos, 'pendientes'));
$motivos = [];
if ($finRetiro) $motivos[] = "En retiro por $medRetiro hasta el " . fechaCorta($finRetiro);
if ($dosisPend) $motivos[] = "Tiene $dosisPend dosis de medicina pendientes";
if ($b['en_hospital']) $motivos[] = 'Esta en hospital' . ($b['motivo_hospital'] ? ': ' . $b['motivo_hospital'] : '');
if ($b['categoria'] === 'Sin clasificar') $motivos[] = 'Falta que el clasificador lo clasifique';
$fechaApto = $finRetiro ? date('Y-m-d', strtotime($finRetiro . ' +1 day')) : $hoy;
$diasParaApto = max(0, (int)((strtotime($fechaApto) - strtotime($hoy)) / 86400));
$pesoApto = $pesoHoy + $gdp * $diasParaApto;

$verCostos = puedeVerPrecios();
$costo = $verCostos ? costoBovino($id) : null;
$minimoKg = $costo && $pesoApto > 0 ? $costo['total'] / $pesoApto : null;
$kg = fn($n) => number_format($n, 1) . ' kg';
?>
<style>
@media print {
    .sidebar, .top-bar, .no-print { display:none !important; }
    .main-content { margin:0 !important; }
    .content { padding:0 !important; }
    body { background:var(--superficie) !important; }
    .table-container { break-inside: avoid; }
}
.tl { position:relative; margin:0; padding:0 0 0 28px; list-style:none; }
.tl:before { content:''; position:absolute; left:9px; top:0; bottom:0; width:2px; background:var(--suave); }
.tl li { position:relative; margin-bottom:14px; }
.tl li.pend { opacity:.6; }
.tl li.pend .dot { background:var(--superficie) !important; border-style:dashed; }
.tl .dot { position:absolute; left:-27px; top:4px; width:14px; height:14px; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px var(--linea); }
.datos { padding:20px; display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.datos > div { padding:10px; background:var(--suave); border-radius:6px; }
.datos .l { font-size:0.7rem; color:var(--tenue); text-transform:uppercase; font-weight:600; }
.datos .v { font-weight:600; }
.venta-box { padding:20px; display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; }
.venta-box .big { font-size:1.6rem; font-weight:700; }
.filtros-tl { display:flex; gap:6px; flex-wrap:wrap; }
.filtros-tl button { border:1px solid var(--linea); background:var(--superficie); border-radius:14px; padding:3px 10px; font-size:0.8rem; cursor:pointer; }
.filtros-tl button.on { color:#fff; }
.tabla-scroll { overflow-x:auto; }
</style>

<div class="no-print" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="<?= BASE_URL ?>/trazabilidad" class="btn btn-secondary">Nueva busqueda</a>
    <button onclick="window.print()" class="btn btn-primary">Imprimir / guardar PDF</button>
    <?php if ($verCostos): ?><a href="<?= BASE_URL ?>/costos/animal?id=<?= $id ?>" class="btn btn-secondary">Ver costos</a><?php endif; ?>
    <?php if ($upp): ?><a href="<?= BASE_URL ?>/trazabilidad?q=<?= urlencode($upp) ?>" class="btn btn-secondary">Otros animales de UPP <?= h($upp) ?></a><?php endif; ?>
</div>

<!-- ============ ¿SE PUEDE VENDER? ============ -->
<?php if ($enRancho): $apto = !$motivos; ?>
<div class="table-container" style="margin-bottom:25px;border-left:6px solid <?= $apto ? '#28a745' : '#e67e22' ?>;">
    <div class="table-header">
        <h2><?= $apto ? 'Listo para venta hoy' : 'Todavia no se puede vender' ?></h2>
        <?php if (!$apto): ?><small style="color:#b45309;font-weight:600;"><?= h(implode(' · ', $motivos)) ?></small><?php endif; ?>
    </div>
    <div class="venta-box">
        <div>
            <div class="l" style="font-size:0.75rem;color:var(--tenue);font-weight:600;text-transform:uppercase;">Ultimo pesaje (<?= fechaCorta($fechaPeso) ?>)</div>
            <div class="big"><?= $kg($pesoActual) ?></div>
            <small style="color:var(--tenue);">hace <?= $diasSinPesar ?> dia(s)</small>
        </div>
        <div>
            <div style="font-size:0.75rem;color:var(--tenue);font-weight:600;text-transform:uppercase;">Peso estimado hoy</div>
            <div class="big"><?= $kg($pesoHoy) ?></div>
            <small style="color:var(--tenue);">gana <?= number_format($gdp, 2) ?> kg por dia</small>
        </div>
        <div>
            <div style="font-size:0.75rem;color:var(--tenue);font-weight:600;text-transform:uppercase;">Se puede vender desde</div>
            <div class="big" style="color:<?= $diasParaApto ? '#e67e22' : '#28a745' ?>;"><?= $diasParaApto ? fechaCorta($fechaApto) : 'Hoy' ?></div>
            <small style="color:var(--tenue);"><?= $diasParaApto ? "faltan $diasParaApto dia(s) de retiro" : 'sin retiro de medicamento' ?></small>
        </div>
        <?php if ($diasParaApto): ?>
        <div>
            <div style="font-size:0.75rem;color:var(--tenue);font-weight:600;text-transform:uppercase;">Peso estimado ese dia</div>
            <div class="big"><?= $kg($pesoApto) ?></div>
            <small style="color:var(--tenue);">+<?= $kg($pesoApto - $pesoHoy) ?> mas que hoy</small>
        </div>
        <?php endif; ?>
        <?php if ($minimoKg): ?>
        <div>
            <div style="font-size:0.75rem;color:var(--tenue);font-weight:600;text-transform:uppercase;">Precio minimo para no perder</div>
            <div class="big"><?= dinero($minimoKg) ?>/kg</div>
            <small style="color:var(--tenue);">invertido <?= dinero($costo['total']) ?></small>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($dosisPend || $b['en_hospital']): ?>
        <p style="padding:0 20px 15px;color:var(--tenue);font-size:0.85rem;">Al terminar las dosis pendientes el retiro puede recorrerse. La fecha se recalcula sola.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ DATOS GENERALES ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header">
        <h2>Arete <?= h($b['id_siniiga']) ?> <?= pillEstado($b['estado']) ?></h2>
        <small style="color:var(--tenue);">Ganaderia El Rosario - Impreso <?= date('d/m/Y H:i') ?></small>
    </div>
    <div class="datos">
        <?php $datos = [
            ['Sexo', $b['sexo'] === 'M' ? 'Macho' : 'Hembra'], ['Raza', $b['raza'] ?: '-'], ['Categoria', $b['categoria'] ?: '-'],
            ['Edad', $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-'], ['Corral actual', $b['corral'] ?: '-'],
            ['Peso de entrada', $kg((float)$b['peso_entrada'])], ['Peso actual / final', $kg($pesoActual)],
            ['Kilos ganados', ($ganado >= 0 ? '+' : '') . $kg($ganado)], ['Dias en el rancho', $dias],
            ['GDP promedio', number_format($ganado / $diasPesados, 3) . ' kg/dia'],
            ['Comida consumida (aprox.)', number_format($kgComida, 0) . ' kg'],
            ['Tratamientos', count($tratamientos)], ['Vacunas', count($vacunas)],
        ];
        if ($costo) { $datos[] = ['Total invertido', dinero($costo['total'])]; }
        if ($costo && $venta) { $datos[] = ['Ganancia real', dinero($venta['subtotal'] - $costo['total'])]; }
        foreach ($datos as $d): ?>
            <div><div class="l"><?= h($d[0]) ?></div><div class="v"><?= h($d[1]) ?></div></div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============ ORIGEN ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Origen</h2>
        <?php if ($b['compra_id'] && Permisos::esAdmin()): ?><a class="btn btn-secondary btn-sm no-print" href="<?= BASE_URL ?>/configuracion/compras/guia?id=<?= (int)$b['compra_id'] ?>">Guia de transito</a><?php endif; ?>
    </div>
    <div class="datos">
        <?php foreach ([
            ['UPP', $upp ?: '-'], ['Rancho', $b['proveedor'] ?: ($b['origen_nombre'] ?: '-')],
            ['Lugar', trim(implode(', ', array_filter([$b['origen_localidad'], $b['origen_municipio'], $b['origen_estado']]))) ?: '-'],
            ['Telefono', $b['prov_tel'] ?: '-'], ['Compra', $b['compra_folio'] ?: '-'], ['No. REEMO', $b['numero_reemo'] ?: '-'],
            ['Fecha de compra', $b['fecha_compra'] ? fechaCorta($b['fecha_compra']) : '-'], ['Recepcion', $b['folio_recepcion'] ?: '-'],
            ['Llego', $b['fecha_recepcion'] ? fechaHora($b['fecha_recepcion']) : '-'], ['Chofer / placas', trim(($b['chofer'] ?? '') . ' ' . ($b['placas'] ? '(' . $b['placas'] . ')' : '')) ?: '-'],
            ['Llego enfermo', $b['llego_enfermo'] ? 'Si: ' . $b['motivo_llegada'] : 'No'],
        ] as $d): ?>
            <div><div class="l"><?= h($d[0]) ?></div><div class="v"><?= h($d[1]) ?></div></div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============ MEDICINAS ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Medicinas aplicadas (<?= count($tratamientos) ?>)</h2></div>
    <?php if ($tratamientos): ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Inicio</th><th>Medicamento</th><th>Diagnostico</th><th>Dosis</th><th>Aplicadas</th><th>Via</th><th>Retiro</th><th>Libre de retiro</th><th>Veterinario</th><?php if ($verCostos): ?><th>Costo</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($tratamientos as $t):
            $activo = $t['estado'] !== 'cancelado' && $t['fecha_fin_retiro'] && $t['fecha_fin_retiro'] >= $hoy; ?>
            <tr<?= $t['estado'] === 'cancelado' ? ' style="opacity:.55;"' : '' ?>>
                <td><?= fechaHora($t['fecha_aplicacion']) ?></td>
                <td><strong><?= h($t['medicamento']) ?></strong><br><small style="color:var(--tenue);"><?= h($t['tipo_med']) ?></small></td>
                <td><?= h($t['diagnostico'] ?? '') ?></td>
                <td><?= h($t['dosis']) ?><?= $t['num_dosis'] > 1 ? ' x ' . (int)$t['num_dosis'] . ' (cada ' . (int)$t['intervalo_horas'] . ' h)' : '' ?></td>
                <td><?= (int)$t['aplicadas'] ?> / <?= (int)$t['num_dosis'] ?>
                    <?php if ($t['pendientes']): ?><br><small style="color:#e67e22;">proxima <?= fechaHora($t['proxima']) ?></small><?php endif; ?>
                    <?php if ($t['estado'] === 'cancelado'): ?><br><small>cancelado</small><?php endif; ?></td>
                <td><?= h($t['via_administracion'] ?? '-') ?></td>
                <td><?= (int)$t['dias_retiro'] ?> dias</td>
                <td><?php if (!$t['fecha_fin_retiro']): ?>-<?php elseif ($activo): ?>
                        <span style="color:#e67e22;font-weight:700;"><?= fechaCorta(date('Y-m-d', strtotime($t['fecha_fin_retiro'] . ' +1 day'))) ?></span>
                    <?php else: ?><span style="color:#28a745;">Ya cumplio</span><?php endif; ?></td>
                <td><?= h(trim(($t['vet'] ?? '') . ' ' . ($t['vet_ap'] ?? ''))) ?></td>
                <?php if ($verCostos): ?><td><?= dinero($t['costo']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><p style="padding:20px;color:var(--tenue);">No se le ha aplicado ninguna medicina.</p><?php endif; ?>
</div>

<!-- ============ VACUNAS ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Vacunas (<?= count($vacunas) ?>)</h2></div>
    <?php if ($vacunas): ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Fecha</th><th>Vacuna</th><th>Cantidad</th><th>Refuerzo</th></tr></thead>
        <tbody>
        <?php foreach ($vacunas as $v): ?>
            <tr><td><?= fechaCorta($v['fecha_aplicacion']) ?></td><td><strong><?= h($v['nombre']) ?></strong></td><td><?= h($v['observaciones'] ?: '-') ?></td>
                <td><?php if ($v['proxima_dosis']): ?><span style="color:<?= $v['proxima_dosis'] < $hoy ? '#dc3545' : '#e67e22' ?>;"><?= fechaCorta($v['proxima_dosis']) ?><?= $v['proxima_dosis'] < $hoy ? ' (atrasado)' : '' ?></span><?php else: ?>-<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><p style="padding:20px;color:var(--tenue);">Sin vacunas registradas.</p><?php endif; ?>
</div>

<!-- ============ PESAJES ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Pesajes (<?= count($pesajes) ?>)</h2></div>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Fecha</th><th>Peso</th><th>Cambio</th><th>GDP</th><th>Nota</th></tr></thead>
        <tbody>
            <tr><td><?= fechaCorta(substr($inicio, 0, 10)) ?></td><td><strong><?= $kg((float)$b['peso_entrada']) ?></strong></td><td>-</td><td>-</td><td>Peso de entrada</td></tr>
            <?php $ant = (float)$b['peso_entrada']; foreach ($pesajes as $p): $dif = $p['peso'] - $ant; $ant = (float)$p['peso']; ?>
            <tr><td><?= fechaCorta($p['fecha']) ?></td><td><strong><?= $kg($p['peso']) ?></strong></td>
                <td style="color:<?= $dif >= 0 ? '#28a745' : '#dc3545' ?>;"><?= ($dif >= 0 ? '+' : '') . $kg($dif) ?></td>
                <td><?= $p['gdp'] !== null ? number_format($p['gdp'], 3) . ' kg/dia' : '-' ?></td><td><?= h($p['observaciones'] ?? '') ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<!-- ============ COMIDA ============ -->
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Alimentacion (<?= number_format($kgComida, 0) ?> kg aprox.)</h2>
        <small style="color:var(--tenue);">La comida se sirve por corral; aqui se ve la parte que le toco a este animal</small></div>
    <?php if ($comida): ?>
    <div class="tabla-scroll"><table>
        <thead><tr><th>Corral</th><th>Del</th><th>Al</th><th>Dieta</th><th>Comidas servidas</th><th>Kg que le tocaron</th><th>Kg por dia</th></tr></thead>
        <tbody>
        <?php foreach ($comida as $c): $nd = max(1, (int)((strtotime($c['ultima']) - strtotime($c['primera'])) / 86400) + 1); ?>
            <tr><td><strong><?= h($c['corral']) ?></strong></td><td><?= fechaCorta($c['primera']) ?></td><td><?= fechaCorta($c['ultima']) ?></td>
                <td><?= h($c['dieta']) ?></td><td><?= (int)$c['comidas'] ?></td><td><?= $kg($c['kg']) ?></td><td><?= $kg($c['kg'] / $nd) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><p style="padding:20px;color:var(--tenue);">No hay comidas registradas en los corrales donde ha estado.</p><?php endif; ?>
</div>

<!-- ============ LINEA DE TIEMPO ============ -->
<div class="table-container">
    <div class="table-header"><h2>Linea de tiempo (<?= count($ev) ?> eventos)</h2>
        <div class="filtros-tl no-print" id="filtrosTl">
            <button type="button" class="on" data-t="" style="background:#555;border-color:var(--texto-2);">Todo</button>
            <?php foreach (array_unique(array_column($ev, 1)) as $t): ?>
                <button type="button" data-t="<?= h($t) ?>" data-c="<?= $colores[$t] ?? 'var(--tenue)' ?>"><?= h($t) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div style="padding:25px;">
        <ul class="tl" id="tl">
        <?php foreach ($ev as $e): $pend = !empty($e[4]); ?>
            <li data-t="<?= h($e[1]) ?>"<?= $pend ? ' class="pend"' : '' ?>>
                <span class="dot" style="background:<?= $colores[$e[1]] ?? '#999' ?>;"></span>
                <div style="font-size:0.8rem;color:var(--tenue);"><?= fechaHora($e[0]) ?> &middot; <strong style="color:<?= $colores[$e[1]] ?? 'var(--tenue)' ?>;"><?= h($e[1]) ?></strong><?= $pend ? ' &middot; <em>programado</em>' : '' ?></div>
                <div style="font-weight:600;"><?= h($e[2]) ?></div>
                <?php if (trim((string)$e[3]) !== ''): ?><div style="color:var(--texto-2);font-size:0.9rem;"><?= h($e[3]) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
</div>

<script>
(function () {
    var bs = document.querySelectorAll('#filtrosTl button');
    bs.forEach(function (b) {
        b.addEventListener('click', function () {
            bs.forEach(function (x) { x.classList.remove('on'); x.style.background = 'var(--superficie)'; x.style.borderColor = 'var(--linea)'; });
            b.classList.add('on'); b.style.background = b.dataset.c || '#555'; b.style.borderColor = b.style.background;
            document.querySelectorAll('#tl li').forEach(function (li) { li.hidden = b.dataset.t !== '' && li.dataset.t !== b.dataset.t; });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
