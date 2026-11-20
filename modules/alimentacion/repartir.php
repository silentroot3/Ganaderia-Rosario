<?php
// ============================================
// ALIMENTACION - Repartir comida a todos los corrales de una vez
// Por cada dieta: cuanto toca servir en esta comida, cuanta mezcla hay (incluye lo que sobro)
// y boton para preparar solo lo que falta. Se marcan los corrales y se sirven todos juntos.
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
$puede = Permisos::puedeCrear('alimentacion');
$horario = in_array($_GET['horario'] ?? $_POST['horario'] ?? '', ['mañana', 'tarde'], true) ? ($_GET['horario'] ?? $_POST['horario']) : horarioActual();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$puede) throw new Exception('No tienes permiso para servir comida');
        exigirCSRF();
        if (($_POST['accion'] ?? '') === 'preparar') {
            $plan = planReparto($db, $horario);
            $did = (int)$_POST['dieta_id'];
            if (!isset($plan[$did])) throw new Exception('Dieta no valida');
            $r = prepararFaltante($db, $did, $plan[$did]['falta'], $_SESSION['user_id']);
            foreach ($r['bajos'] as $b) {
                crearAlerta('stock_bajo', "Stock bajo de {$b['nombre']}: quedan " . number_format($b['queda'], 1) . ' kg.', 'alimentador', null, null, $b['ingrediente_id']);
            }
            $msg = 'Mezcla #' . $r['id'] . ' de ' . $plan[$did]['dieta']['nombre'] . ': se prepararon ' . number_format($r['kg'], 0) . ' kg (lo que faltaba)';
        } else {
            $items = [];
            foreach ((array)($_POST['sel'] ?? []) as $cid => $_) $items[(int)$cid] = (float)($_POST['kg'][$cid] ?? 0);
            $r = servirLote($db, $horario, $items, $_SESSION['user_id']);
            $msg = "Comida de la $horario servida a {$r['corrales']} corral(es): " . number_format($r['kg'], 1) . ' kg';
        }
        header('Location: ' . BASE_URL . '/alimentacion/repartir?horario=' . urlencode($horario) . '&msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$plan = planReparto($db, $horario);
$cfg = configComidas();
$totPend = array_sum(array_column($plan, 'pendiente'));
$nPend = 0; $nServ = 0;
foreach ($plan as $p) foreach ($p['corrales'] as $c) $c['ya'] > 0 ? $nServ++ : $nPend++;

$titulo = 'Repartir comida';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="page-actions">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">&larr; Alimentación</a>
    <div class="segmentado">
        <?php foreach (['mañana', 'tarde'] as $h): ?>
            <a href="?horario=<?= urlencode($h) ?>" class="<?= $horario === $h ? 'on' : '' ?>"><?= ucfirst($h) ?> · <?= h($cfg['hora'][$h]) ?> · <?= $cfg['pct'][$h] ?>%</a>
        <?php endforeach; ?>
    </div>
    <a href="<?= BASE_URL ?>/alimentacion/corrales" class="btn btn-secondary">Dieta de cada corral</a>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Corrales por servir</div><div class="stat-value"><?= $nPend ?></div><div class="stat-desc"><?= $nServ ?> ya servidos esta <?= h($horario) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Kg por servir</div><div class="stat-value"><?= number_format($totPend, 0) ?></div><div class="stat-desc">comida de la <?= h($horario) ?></div></div>
    <div class="stat-card yellow"><div class="stat-label">Mezcla lista</div><div class="stat-value"><?= number_format(array_sum(array_column($plan, 'disponible')), 0) ?></div><div class="stat-desc">kg, incluye lo que sobró</div></div>
</div>

<?php if (!$plan): ?>
    <div class="vacio"><p>No hay corrales con animales y dieta asignada.</p><a class="btn btn-primary" href="<?= BASE_URL ?>/alimentacion/corrales">Asignar dietas</a></div>
<?php endif; ?>

<form method="POST" id="formReparto">
    <?= csrfField() ?><input type="hidden" name="horario" value="<?= h($horario) ?>">
<?php foreach ($plan as $did => $p): $alcanza = $p['falta'] <= 0; ?>
    <div class="table-container grupo-dieta" data-dieta="<?= $did ?>" data-disp="<?= $p['disponible'] ?>">
        <div class="table-header">
            <div>
                <h2>Dieta <?= h($p['dieta']['nombre']) ?></h2>
                <small class="muted"><?= count($p['corrales']) ?> corral(es) · por servir <strong><?= number_format($p['pendiente'], 0) ?> kg</strong> · mezcla lista <strong><?= number_format($p['disponible'], 0) ?> kg</strong></small>
            </div>
            <?php if ($p['pendiente'] > 0): ?>
                <?php if ($alcanza): ?>
                    <span class="chip chip-verde">La mezcla alcanza<?= $p['disponible'] - $p['pendiente'] > 0.5 ? ' · sobran ' . number_format($p['disponible'] - $p['pendiente'], 0) . ' kg' : '' ?></span>
                <?php else: ?>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <span class="chip chip-rojo">Faltan <?= number_format($p['falta'], 0) ?> kg</span>
                        <?php if ($puede): ?>
                        <button type="submit" name="accion" value="preparar" class="btn btn-primary btn-sm" form="prep<?= $did ?>">Preparar lo que falta (<?= number_format(ceil($p['falta'] / 10) * 10, 0) ?> kg)</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <span class="chip chip-azul">Todo servido</span>
            <?php endif; ?>
        </div>
        <table>
            <thead><tr><th style="width:36px;"><?php if ($puede): ?><input type="checkbox" class="sel-grupo" title="Todos"><?php endif; ?></th><th>Corral</th><th>Animales</th><th>Peso total</th><th>Toca (<?= h($horario) ?>)</th><th>Servir (kg)</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($p['corrales'] as $c): $hecho = $c['ya'] > 0; ?>
                <tr class="<?= $hecho ? 'fila-hecha' : '' ?>">
                    <td><?php if ($puede && !$hecho): ?><input type="checkbox" class="sel" name="sel[<?= $c['id'] ?>]" value="1" checked><?php endif; ?></td>
                    <td><strong><?= h($c['nombre']) ?></strong><?= $c['fijada'] ? '' : ' <span class="chip chip-gris" title="No tiene dieta fija; se usa la de su etapa">sugerida</span>' ?></td>
                    <td><?= (int)$c['bovinos'] ?></td>
                    <td><?= number_format($c['peso_total'], 0) ?> kg</td>
                    <td><?= number_format($c['kg'], 0) ?> kg</td>
                    <td><?php if ($hecho): ?><?= number_format($c['ya'], 1) ?> kg<?php elseif ($puede): ?>
                        <input type="number" class="kg" name="kg[<?= $c['id'] ?>]" value="<?= $c['kg'] ?>" step="0.5" min="0" style="width:95px;"><?php else: ?>—<?php endif; ?></td>
                    <td><?= $hecho ? '<span class="chip chip-verde">Servida</span>' : '<span class="chip chip-gris">Pendiente</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<?php if ($puede && $nPend): ?>
    <div class="barra-accion">
        <span id="resumenReparto" class="muted"></span>
        <button type="submit" name="accion" value="servir" class="btn btn-primary btn-lg">Servir comida de la <?= h($horario) ?></button>
    </div>
<?php endif; ?>
</form>

<?php foreach ($plan as $did => $p): if ($p['falta'] > 0 && $puede): ?>
    <form method="POST" id="prep<?= $did ?>" hidden><?= csrfField() ?><input type="hidden" name="horario" value="<?= h($horario) ?>"><input type="hidden" name="dieta_id" value="<?= $did ?>"><input type="hidden" name="accion" value="preparar"></form>
<?php endif; endforeach; ?>

<p class="nota-pie">La comida sale primero de la mezcla que ya estaba preparada (también lo que sobró de otra comida) y se reparte a todos los corrales con la misma dieta.
    "Preparar lo que falta" solo saca de la bodega lo necesario para completar esta comida.</p>

<style>
.segmentado { display: inline-flex; background: var(--superficie); border: 1px solid var(--linea-fuerte); border-radius: 8px; padding: 3px; gap: 3px; }
.segmentado a { padding: 6px 14px; border-radius: 6px; font-size: .86rem; font-weight: 500; color: var(--texto-2); }
.segmentado a:hover { text-decoration: none; background: var(--fondo); }
.segmentado a.on { background: var(--azul); color: #fff; }
.fila-hecha td { color: var(--tenue); }
.barra-accion { position: sticky; bottom: 0; display: flex; justify-content: flex-end; align-items: center; gap: 16px; padding: 14px 0; background: linear-gradient(transparent, var(--fondo) 30%); }
</style>
<script>
(function () {
    var f = document.getElementById('formReparto'); if (!f) return;
    function calc() {
        var n = 0, kg = 0, mal = [];
        f.querySelectorAll('.grupo-dieta').forEach(function (g) {
            var suma = 0;
            g.querySelectorAll('tbody tr').forEach(function (tr) {
                var c = tr.querySelector('.sel'), k = tr.querySelector('.kg');
                if (c && c.checked && k) { n++; suma += parseFloat(k.value) || 0; }
            });
            kg += suma;
            if (suma > +g.dataset.disp + 0.01) mal.push(g.querySelector('h2').textContent);
        });
        var r = document.getElementById('resumenReparto');
        if (r) r.innerHTML = n + ' corral(es) · ' + kg.toLocaleString('es-MX', {maximumFractionDigits: 1}) + ' kg'
            + (mal.length ? ' · <span style="color:var(--rojo);font-weight:600;">falta mezcla: ' + mal.join(', ') + '</span>' : '');
    }
    f.addEventListener('input', calc); f.addEventListener('change', calc);
    f.querySelectorAll('.sel-grupo').forEach(function (g) {
        g.checked = true;
        g.addEventListener('change', function () { g.closest('table').querySelectorAll('.sel').forEach(function (c) { c.checked = g.checked; }); calc(); });
    });
    calc();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
