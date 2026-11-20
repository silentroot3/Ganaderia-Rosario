<?php
// ============================================
// ALIMENTACION - Control de comidas por corral
// Verifica que a cada corral se le dio la cantidad correcta (3% del peso:
// 40% mañana / 60% tarde, redondeado al kilo de arriba) y a su hora.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('alimentacion', 'ver');
$db = Database::getInstance()->getConnection();
$cfg = configComidas();
$hoy = date('Y-m-d');
$fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') && $_GET['fecha'] <= $hoy ? $_GET['fecha'] : $hoy;
$corralSel = (int)($_GET['corral'] ?? 0);
$puedeServir = Permisos::puedeCrear('alimentacion');
$horarios = ['mañana', 'tarde'];

/** Estado de una comida: [clave, texto, color] */
function estadoComida($fecha, $horario, $esperado, $servido, $hora, $cfg) {
    $limite = strtotime("$fecha {$cfg['hora'][$horario]}") + $cfg['tolerancia'] * 60;
    if ($servido === null) {
        if ($fecha === date('Y-m-d') && time() <= $limite) return ['pendiente', 'Pendiente', 'var(--tenue)'];
        return ['falta', 'No se dio', '#dc3545'];
    }
    $tarde = $hora && strtotime("$fecha $hora") > $limite;
    if ($esperado !== null) {
        $dif = $servido - $esperado;
        if ($dif < -0.5) return ['menos', 'Faltaron ' . number_format(-$dif, 1) . ' kg' . ($tarde ? ' y tarde' : ''), '#dc3545'];
        if ($dif > max(1, $esperado * 0.05)) return ['mas', 'De mas ' . number_format($dif, 1) . ' kg' . ($tarde ? ' y tarde' : ''), '#e67e22'];
    }
    if ($tarde) return ['tarde', 'Cantidad bien, pero tarde', '#e67e22'];
    return ['ok', $esperado === null ? 'Servida' : 'Correcta y a tiempo', '#28a745'];
}

// Comidas registradas: por corral, fecha y horario
function comidasRegistradas($db, $desde, $hasta, $corral = 0) {
    $sql = "SELECT a.corral_id, a.fecha, a.horario, SUM(a.cantidad_kg) AS kg, MAX(a.kg_esperado) AS esperado,
                   MIN(a.hora) AS hora, GROUP_CONCAT(DISTINCT d.nombre) AS dieta, GROUP_CONCAT(DISTINCT u.nombre) AS quien, COUNT(*) AS veces
            FROM alimentaciones a
            LEFT JOIN dietas d ON d.id = a.dieta_id LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.fecha BETWEEN ? AND ?" . ($corral ? " AND a.corral_id = " . (int)$corral : '') . "
            GROUP BY a.corral_id, a.fecha, a.horario";
    $stmt = $db->prepare($sql);
    $stmt->execute([$desde, $hasta]);
    $out = [];
    foreach ($stmt as $r) $out[$r['corral_id']][$r['fecha']][$r['horario']] = $r;
    return $out;
}

$raciones = [];
foreach (racionesCorrales($db) as $r) $raciones[$r['id']] = $r;
$nombres = $db->query("SELECT id, nombre FROM corrales_todos")->fetchAll(PDO::FETCH_KEY_PAIR);

$filas = [];      // vista por dia: corral x horario
$historial = [];  // vista de un corral: 15 dias
if ($corralSel) {
    $desde = date('Y-m-d', strtotime("$fecha -14 day"));
    $reg = comidasRegistradas($db, $desde, $fecha, $corralSel)[$corralSel] ?? [];
    for ($d = $fecha; $d >= $desde; $d = date('Y-m-d', strtotime("$d -1 day"))) {
        $dia = ['fecha' => $d];
        foreach ($horarios as $h) {
            $x = $reg[$d][$h] ?? null;
            $esp = $x['esperado'] ?? null;
            if ($esp === null && $d === $hoy && isset($raciones[$corralSel])) $esp = $h === 'mañana' ? $raciones[$corralSel]['kg_manana'] : $raciones[$corralSel]['kg_tarde'];
            $dia[$h] = ['x' => $x, 'esperado' => $esp !== null ? (float)$esp : null,
                        'estado' => estadoComida($d, $h, $esp !== null ? (float)$esp : null, $x ? (float)$x['kg'] : null, $x['hora'] ?? null, $cfg)];
        }
        $historial[] = $dia;
    }
} else {
    $reg = comidasRegistradas($db, $fecha, $fecha);
    $ids = array_unique(array_merge(array_keys($fecha === $hoy ? $raciones : []), array_keys($reg)));
    foreach ($ids as $cid) {
        $r = $raciones[$cid] ?? null;
        $fila = ['id' => $cid, 'nombre' => $nombres[$cid] ?? "#$cid", 'r' => $fecha === $hoy ? $r : null];
        foreach ($horarios as $h) {
            $x = $reg[$cid][$fecha][$h] ?? null;
            $esp = $x['esperado'] ?? null;
            if ($fecha === $hoy && $r) $esp = $h === 'mañana' ? $r['kg_manana'] : $r['kg_tarde'];
            $fila[$h] = ['x' => $x, 'esperado' => $esp !== null ? (float)$esp : null,
                         'estado' => estadoComida($fecha, $h, $esp !== null ? (float)$esp : null, $x ? (float)$x['kg'] : null, $x['hora'] ?? null, $cfg)];
        }
        $filas[] = $fila;
    }
    usort($filas, fn($a, $b) => strnatcmp($a['nombre'], $b['nombre']));
}

$cuenta = ['ok' => 0, 'mas' => 0, 'menos' => 0, 'tarde' => 0, 'falta' => 0, 'pendiente' => 0];
foreach ($corralSel ? $historial : $filas as $f) foreach ($horarios as $h) $cuenta[$f[$h]['estado'][0]]++;

$titulo = 'Control de comidas';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';

function celdaComida($c, $fecha, $corral_id, $horario, $puedeServir) {
    [$clave, $texto, $color] = $c['estado'];
    $x = $c['x'];
    ob_start(); ?>
    <div class="comida comida-<?= $clave ?>" style="border-left-color: <?= $color ?>;">
        <div class="comida-kg">
            <?= $x ? '<strong>' . number_format($x['kg'], 1) . '</strong>' : '<strong>-</strong>' ?>
            <span>/ <?= $c['esperado'] !== null ? number_format($c['esperado'], 0) . ' kg' : '?' ?></span>
            <?php if ($x): ?><small><?= substr($x['hora'], 0, 5) ?><?= $x['veces'] > 1 ? ' · ' . $x['veces'] . ' veces' : '' ?></small><?php endif; ?>
        </div>
        <div style="color: <?= $color ?>; font-weight: 600; font-size: 0.82rem;"><?= h($texto) ?></div>
        <?php if (!$x && $puedeServir && $fecha === date('Y-m-d')): ?>
            <a class="btn btn-primary btn-sm" style="margin-top:5px;" href="<?= BASE_URL ?>/alimentacion/registrar?corral_id=<?= $corral_id ?>&horario=<?= urlencode($horario) ?>">Servir</a>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
?>
<style>
.comida { border-left: 4px solid var(--linea); padding: 6px 10px; background: var(--suave); border-radius: 4px; min-width: 150px; }
.comida-kg strong { font-size: 1.15rem; }
.comida-kg span { color: var(--tenue); }
.comida-kg small { display: block; color: var(--tenue); }
</style>

<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px;">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver</a>
    <?php if ($puedeServir): ?><a href="<?= BASE_URL ?>/alimentacion/registrar" class="btn btn-primary">Registrar comida</a><?php endif; ?>
    <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-left:auto;">
        <input type="date" name="fecha" value="<?= h($fecha) ?>" max="<?= $hoy ?>" onchange="this.form.submit()" style="<?= ESTILO_INPUT ?> width:auto;">
        <select name="corral" onchange="this.form.submit()" style="<?= ESTILO_INPUT ?> width:auto;">
            <option value="">Todos los corrales (un dia)</option>
            <?php foreach ($raciones as $r): ?><option value="<?= $r['id'] ?>" <?= $corralSel === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['nombre']) ?> (ultimos 15 dias)</option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card green"><div class="stat-label">Correctas</div><div class="stat-value"><?= $cuenta['ok'] ?></div><div class="stat-desc">cantidad y hora bien</div></div>
    <div class="stat-card yellow"><div class="stat-label">Tarde o de mas</div><div class="stat-value"><?= $cuenta['tarde'] + $cuenta['mas'] ?></div><div class="stat-desc">despues de <?= $cfg['tolerancia'] ?> min de tolerancia</div></div>
    <div class="stat-card" style="border-left-color:#dc3545;"><div class="stat-label">Faltantes</div><div class="stat-value"><?= $cuenta['menos'] + $cuenta['falta'] ?></div><div class="stat-desc">no se dio o se dio de menos</div></div>
    <div class="stat-card"><div class="stat-label">Pendientes hoy</div><div class="stat-value"><?= $cuenta['pendiente'] ?></div>
        <div class="stat-desc">Mañana <?= h($cfg['hora']['mañana']) ?> (<?= $cfg['pct']['mañana'] ?>%) · Tarde <?= h($cfg['hora']['tarde']) ?> (<?= $cfg['pct']['tarde'] ?>%)</div></div>
</div>

<?php if ($corralSel): ?>
<div class="table-container">
    <div class="table-header"><h2>Corral <?= h($nombres[$corralSel] ?? '') ?> · ultimos 15 dias</h2>
        <?php if (isset($raciones[$corralSel])): $r = $raciones[$corralSel]; ?>
        <small style="color:var(--tenue);">Hoy: <?= $r['bovinos'] ?> bovinos · <?= number_format($r['peso_total'], 0) ?> kg x <?= rtrim(rtrim($r['dieta_usar']['porcentaje_peso'] ?? '3', '0'), '.') ?>% = <?= number_format($r['kg_dia'], 2) ?> kg/dia → mañana <?= $r['kg_manana'] ?> kg · tarde <?= $r['kg_tarde'] ?> kg</small>
        <?php endif; ?>
    </div>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>Fecha</th><th>Mañana (<?= h($cfg['hora']['mañana']) ?>)</th><th>Tarde (<?= h($cfg['hora']['tarde']) ?>)</th><th>Total del dia</th></tr></thead>
        <tbody>
        <?php foreach ($historial as $d): $tot = (float)($d['mañana']['x']['kg'] ?? 0) + (float)($d['tarde']['x']['kg'] ?? 0); ?>
            <tr><td><strong><?= fechaCorta($d['fecha']) ?></strong><?= $d['fecha'] === $hoy ? ' <small>(hoy)</small>' : '' ?></td>
                <td><?= celdaComida($d['mañana'], $d['fecha'], $corralSel, 'mañana', $puedeServir) ?></td>
                <td><?= celdaComida($d['tarde'], $d['fecha'], $corralSel, 'tarde', $puedeServir) ?></td>
                <td><?= number_format($tot, 1) ?> kg</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p style="padding:10px 20px;color:var(--tenue);font-size:0.82rem;">Las comidas registradas antes de este control no guardaron lo que tocaba servir; por eso salen con "?".</p>
</div>
<?php else: ?>
<div class="table-container">
    <div class="table-header"><h2>Comidas del <?= fechaCorta($fecha) ?><?= $fecha === $hoy ? ' (hoy)' : '' ?></h2>
        <small style="color:var(--tenue);">Servido / lo que tocaba · clic en el corral para ver sus ultimos 15 dias</small></div>
    <?php if ($filas): ?>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>Corral</th><th>Bovinos / peso</th><th>Kg al dia</th><th>Mañana (<?= h($cfg['hora']['mañana']) ?>)</th><th>Tarde (<?= h($cfg['hora']['tarde']) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): $r = $f['r']; ?>
            <tr>
                <td><a href="?corral=<?= $f['id'] ?>&fecha=<?= h($fecha) ?>"><strong><?= h($f['nombre']) ?></strong></a></td>
                <td><?= $r ? $r['bovinos'] . ' · ' . number_format($r['peso_total'], 0) . ' kg' : '-' ?></td>
                <td><?= $r ? number_format($r['kg_dia'], 2) . ' kg' : '-' ?></td>
                <td><?= celdaComida($f['mañana'], $fecha, $f['id'], 'mañana', $puedeServir) ?></td>
                <td><?= celdaComida($f['tarde'], $fecha, $f['id'], 'tarde', $puedeServir) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><p style="padding:30px;text-align:center;color:var(--tenue);">No hay comidas registradas ese dia.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
