<?php
// ============================================
// ALIMENTACION - Inicio
// El dia en 3 pasos (dieta por corral -> preparar mezcla -> repartir) y
// pronostico de bodega: para cuantos dias alcanza cada ingrediente y cuando pedir.
// ============================================

$titulo = 'Alimentación';
$modulo = 'alimentacion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$puede = Permisos::puedeCrear('alimentacion');
$pedir = Permisos::puedeCrear('solicitudes');
$cfg = configComidas();
$hor = horarioActual();

$pron = pronosticoAlimento($db);
revisarAlertasAlimento($db, $pron);

$plan = ['mañana' => planReparto($db, 'mañana'), 'tarde' => planReparto($db, 'tarde')];
$estadoComida = [];
foreach ($plan as $h => $p) {
    $pend = 0; $serv = 0;
    foreach ($p as $g) foreach ($g['corrales'] as $c) $c['ya'] > 0 ? $serv++ : $pend++;
    $estadoComida[$h] = ['pend' => $pend, 'serv' => $serv, 'kg' => array_sum(array_column($p, 'pendiente'))];
}
$raciones = racionesCorrales($db);
$sinFijar = count(array_filter($raciones, fn($r) => !$r['dieta']));
$kgDia = array_sum(array_column($raciones, 'kg_servir'));
$porPedir = array_filter($pron, fn($p) => in_array($p['estado'], ['pedir', 'urgente'], true));
$mezcla = kgMezclaPorDieta($db);
$dietas = dietasActivas($db);
?>

<div class="pasos">
    <a class="paso" href="<?= BASE_URL ?>/alimentacion/corrales">
        <span class="paso-n">1</span>
        <div><strong>Dieta de cada corral</strong>
            <small><?= count($raciones) ?> corrales con animales<?= $sinFijar ? " · <b class='txt-rojo'>$sinFijar sin dieta fija</b>" : ' · todas fijas' ?></small></div>
    </a>
    <a class="paso" href="<?= BASE_URL ?>/alimentacion/mezclas">
        <span class="paso-n">2</span>
        <div><strong>Preparar mezcla</strong>
            <small><?= number_format(array_sum($mezcla), 0) ?> kg listos<?= array_sum($mezcla) > 0 ? ' (lo que sobra se reparte primero)' : '' ?></small></div>
    </a>
    <a class="paso <?= $estadoComida[$hor]['pend'] ? 'paso-activo' : '' ?>" href="<?= BASE_URL ?>/alimentacion/repartir">
        <span class="paso-n">3</span>
        <div><strong>Repartir comida de la <?= h($hor) ?></strong>
            <small><?= $estadoComida[$hor]['pend'] ? $estadoComida[$hor]['pend'] . ' corral(es) por servir · ' . number_format($estadoComida[$hor]['kg'], 0) . ' kg' : 'Todos los corrales servidos' ?></small></div>
    </a>
</div>

<div class="stats-grid">
    <?php foreach (['mañana', 'tarde'] as $h): $e = $estadoComida[$h]; ?>
    <div class="stat-card <?= $e['pend'] ? 'yellow' : 'green' ?>">
        <div class="stat-label">Comida de la <?= $h ?> · <?= h($cfg['hora'][$h]) ?></div>
        <div class="stat-value"><?= $e['serv'] ?>/<?= $e['serv'] + $e['pend'] ?></div>
        <div class="stat-desc">corrales servidos<?= $e['pend'] ? ' · faltan ' . number_format($e['kg'], 0) . ' kg' : '' ?></div>
    </div>
    <?php endforeach; ?>
    <div class="stat-card"><div class="stat-label">Comida al día</div><div class="stat-value"><?= number_format($kgDia, 0) ?></div><div class="stat-desc">kg para todos los corrales</div></div>
    <div class="stat-card <?= $porPedir ? '' : 'green' ?>" <?= $porPedir ? 'style="--acento:var(--rojo)"' : '' ?>>
        <div class="stat-label">Por pedir</div><div class="stat-value"><?= count($porPedir) ?></div>
        <div class="stat-desc"><?= $porPedir ? 'ingrediente(s) se acaban pronto' : 'la bodega alcanza' ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <div><h2>¿Para cuántos días alcanza la bodega?</h2>
            <small class="muted">Con las dietas asignadas y las 2 comidas de todos los corrales, contando hasta tu stock mínimo. Avisa cuando faltan <?= (int)reset($pron)['aviso'] ?> días o menos.</small></div>
        <a href="<?= BASE_URL ?>/alimentacion/inventario" class="btn btn-secondary btn-sm">Bodega y stock mínimo</a>
    </div>
    <table>
        <thead><tr><th>Ingrediente</th><th>En bodega</th><th>Ya en mezcla</th><th>Mínimo</th><th>Se usa al día</th><th>Alcanza para</th><th>Llega al mínimo</th><th>Pedido</th></tr></thead>
        <tbody>
        <?php foreach ($pron as $p):
            $color = ['urgente' => 'var(--rojo)', 'pedir' => '#d97706', 'ok' => 'var(--verde)', 'sin_uso' => 'var(--tenue)'][$p['estado']];
            $pct = $p['dias'] === null ? 0 : min(100, $p['dias'] / max(1, $p['cubrir']) * 100); ?>
            <tr>
                <td><strong><?= h($p['nombre']) ?></strong></td>
                <td><?= number_format($p['stock'], 0) ?> kg</td>
                <td class="muted"><?= $p['en_mezcla'] > 0 ? number_format($p['en_mezcla'], 0) . ' kg' : '—' ?></td>
                <td class="muted"><?= number_format($p['minimo'], 0) ?> kg</td>
                <td><?= $p['diario'] > 0 ? number_format($p['diario'], 1) . ' kg' : '<span class="muted">no se usa</span>' ?></td>
                <td style="min-width:160px;">
                    <?php if ($p['dias'] !== null): ?>
                        <strong style="color:<?= $color ?>"><?= $p['dias'] >= 365 ? 'más de un año' : number_format(floor($p['dias'])) . ' días' ?></strong>
                        <div class="barra-dias"><span style="width:<?= round($pct) ?>%;background:<?= $color ?>"></span></div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= $p['se_acaba'] && $p['dias'] < 365 ? fechaCorta($p['se_acaba']) : '—' ?></td>
                <td>
                    <?php if ($p['pedido']): ?>
                        <span class="chip chip-azul" title="<?= h($p['pedido']['folios']) ?>">Ya pedido: <?= number_format($p['pedido']['cant'], 0) ?> kg</span>
                    <?php elseif (in_array($p['estado'], ['pedir', 'urgente'], true)): ?>
                        <?php if ($pedir): ?>
                            <a class="btn btn-sm <?= $p['estado'] === 'urgente' ? 'btn-danger' : 'btn-primary' ?>"
                               href="<?= BASE_URL ?>/solicitudes/crear?tipo=alimento&id=<?= $p['id'] ?>&cantidad=<?= $p['pedir_kg'] ?>&urgencia=<?= $p['estado'] === 'urgente' ? 'urgente' : 'alta' ?>&motivo=<?= urlencode('Alcanza para ' . floor($p['dias']) . ' dias. Pedido para ' . $p['cubrir'] . ' dias de consumo.') ?>">
                               Pedir <?= number_format($p['pedir_kg'], 0) ?> kg</a>
                        <?php else: ?><span class="chip chip-rojo">Pedir <?= number_format($p['pedir_kg'], 0) ?> kg</span><?php endif; ?>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="table-container">
    <div class="table-header"><h2>Hoy por dieta</h2><a href="<?= BASE_URL ?>/alimentacion/dietas" class="btn btn-secondary btn-sm">Ver recetas</a></div>
    <table>
        <thead><tr><th>Dieta</th><th>Corrales</th><th>Kg al día</th><th>Mañana</th><th>Tarde</th><th>Mezcla lista</th></tr></thead>
        <tbody>
        <?php foreach ($dietas as $d):
            $rs = array_filter($raciones, fn($r) => $r['dieta_usar'] && $r['dieta_usar']['id'] == $d['id']);
            if (!$rs && empty($mezcla[$d['id']])) continue; ?>
            <tr><td><strong><?= h($d['nombre']) ?></strong></td><td><?= count($rs) ?></td>
                <td><?= number_format(array_sum(array_column($rs, 'kg_servir')), 0) ?> kg</td>
                <td><?= number_format(array_sum(array_column($rs, 'kg_manana')), 0) ?> kg</td>
                <td><?= number_format(array_sum(array_column($rs, 'kg_tarde')), 0) ?> kg</td>
                <td><?= number_format($mezcla[$d['id']] ?? 0, 0) ?> kg</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="accesos">
    <a href="<?= BASE_URL ?>/alimentacion/control"><?= icono('solicitud') ?> Control de comidas</a>
    <a href="<?= BASE_URL ?>/alimentacion/inventario"><?= icono('proveedor') ?> Bodega y entradas</a>
    <a href="<?= BASE_URL ?>/alimentacion/entradas"><?= icono('reporte') ?> Historial de entradas</a>
    <?php if ($puede): ?><a href="<?= BASE_URL ?>/alimentacion/registrar"><?= icono('alimento') ?> Servir un solo corral</a><?php endif; ?>
</div>

<style>
.pasos { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 20px; }
.paso { display: flex; gap: 12px; align-items: center; padding: 14px 16px; background: var(--superficie); border: 1px solid var(--linea); border-radius: var(--radio); color: var(--texto); transition: border-color .15s, box-shadow .15s; }
.paso:hover { text-decoration: none; border-color: var(--azul); box-shadow: var(--sombra-2); color: var(--texto); }
.paso small { display: block; color: var(--tenue); font-size: .8rem; }
.paso-n { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; background: var(--azul-50); color: var(--azul); font-weight: 700; }
.paso-activo { border-color: var(--azul); }
.paso-activo .paso-n { background: var(--azul); color: #fff; }
.txt-rojo { color: var(--rojo); }
.barra-dias { height: 5px; background: var(--linea); border-radius: 3px; margin-top: 4px; overflow: hidden; }
.barra-dias span { display: block; height: 100%; border-radius: 3px; }
.accesos { display: flex; gap: 10px; flex-wrap: wrap; }
.accesos a { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; background: var(--superficie); border: 1px solid var(--linea); border-radius: 8px; color: var(--texto-2); font-weight: 500; font-size: .88rem; }
.accesos a:hover { text-decoration: none; border-color: var(--azul); color: var(--azul); }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
