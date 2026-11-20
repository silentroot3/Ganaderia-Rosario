<?php
// ============================================
// ALIMENTACION - Preparar mezcla por toneladas
// Los ingredientes salen de bodega en kg segun la receta; las comidas se sirven
// de la mezcla y al cerrarla lo que sobro regresa a la bodega.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('alimentacion', 'ver');
$db = Database::getInstance()->getConnection();
$puede = Permisos::puedeCrear('alimentacion');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$puede) throw new Exception('No tienes permiso para preparar mezclas');
        exigirCSRF();
        if (($_POST['accion'] ?? '') === 'preparar') {
            $r = prepararMezcla($db, (int)$_POST['dieta_id'], (float)$_POST['toneladas'], $_SESSION['user_id']);
            foreach ($r['bajos'] as $b) {
                $msgB = "Stock bajo de {$b['nombre']}: quedan " . number_format($b['queda'], 1) . " kg. Solicite mas.";
                crearAlerta('stock_bajo', $msgB, 'alimentador', null, null, $b['ingrediente_id']);
                crearAlerta('stock_bajo', $msgB, 'admin', null, null, $b['ingrediente_id']);
            }
            $msg = "Mezcla #{$r['id']} preparada: " . number_format((float)$_POST['toneladas'] * 1000, 0) . ' kg listos para servir';
        } elseif (($_POST['accion'] ?? '') === 'cerrar') {
            $kg = cerrarMezcla($db, (int)$_POST['mezcla_id'], $_SESSION['user_id']);
            $msg = $kg > 0 ? 'Mezcla cerrada: ' . number_format($kg, 1) . ' kg que sobraron regresaron al inventario' : 'Mezcla cerrada (se sirvio completa)';
        } else {
            throw new Exception('Accion no valida');
        }
        header('Location: ' . BASE_URL . '/alimentacion/mezclas?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$dietas = dietasActivas($db);
// Lo que falta servir hoy (mañana + tarde, solo corrales que aun no comen) por dieta
$necesario = [];
foreach (['mañana', 'tarde'] as $hx) foreach (planReparto($db, $hx) as $did => $p) $necesario[$did] = ($necesario[$did] ?? 0) + $p['pendiente'];
$abiertas = mezclasAbiertas($db);
$preparado = kgMezclaPorDieta($db);
$historial = $db->query("
    SELECT m.*, d.nombre AS dieta, u.nombre AS un FROM mezclas m JOIN dietas d ON d.id = m.dieta_id
    LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.estado = 'cerrada' ORDER BY m.fecha_cierre DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
$recetasJs = [];
foreach ($dietas as $d) $recetasJs[$d['id']] = array_map(fn($r) => ['n' => $r['nombre'], 'p' => (float)$r['porcentaje'], 's' => (float)$r['stock']], $d['receta']);
$dietaSel = (int)($_POST['dieta_id'] ?? ($_GET['dieta'] ?? 0));

$titulo = 'Preparar mezcla';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success"><?= h($_GET['msg']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver</a>
    <a href="<?= BASE_URL ?>/alimentacion/dietas" class="btn btn-secondary">Ver dietas</a>
    <a href="<?= BASE_URL ?>/alimentacion/repartir" class="btn btn-primary">Repartir comida</a>
</div>

<div class="stats-grid" style="margin-bottom: 25px;">
    <?php foreach ($dietas as $d): $nec = $necesario[$d['id']] ?? 0; $prep = $preparado[$d['id']] ?? 0; ?>
    <div class="stat-card <?= $prep >= $nec ? 'green' : 'yellow' ?>">
        <div class="stat-label"><?= h($d['nombre']) ?></div>
        <div class="stat-value" style="font-size: 1.4rem;"><?= number_format($prep, 0) ?> kg</div>
        <div class="stat-desc">listos · faltan servir hoy <?= number_format($nec, 0) ?> kg<?= $prep > $nec && $prep > 0 ? ' · sobran ' . number_format($prep - $nec, 0) . ' kg' : ($nec > $prep ? ' · preparar ' . number_format($nec - $prep, 0) . ' kg' : '') ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($puede): ?>
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header"><h2>Preparar mezcla</h2></div>
    <form method="POST" style="padding: 20px;" id="formMezcla">
        <?= csrfField() ?><input type="hidden" name="accion" value="preparar">
        <div style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; align-items: end;">
            <div>
                <label style="<?= ESTILO_LABEL ?>">Dieta *</label>
                <select name="dieta_id" id="selDieta" required style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($dietas as $d): ?>
                        <option value="<?= $d['id'] ?>" data-falta="<?= max(0, ceil((($necesario[$d['id']] ?? 0) - ($preparado[$d['id']] ?? 0)) / 10) * 10 / 1000) ?>" data-hay="<?= round($preparado[$d['id']] ?? 0) ?>" <?= $dietaSel === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Toneladas *</label>
                <input type="number" name="toneladas" id="inpTon" step="0.01" min="0.01" required value="<?= h($_POST['toneladas'] ?? '1') ?>" style="<?= ESTILO_INPUT ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 11px 25px;">Preparar</button>
        </div>
        <small id="sugerencia" style="display:block; margin-top:6px; color:#0b4f86;"></small>
        <div id="desglose" style="margin-top: 15px;" hidden></div>
    </form>
</div>
<?php endif; ?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header"><h2>Mezclas abiertas (<?= count($abiertas) ?>)</h2></div>
    <?php if ($abiertas): ?>
    <table>
        <thead><tr><th>#</th><th>Dieta</th><th>Preparada</th><th>Preparado</th><th>Servido</th><th>Queda</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($abiertas as $m): ?>
            <tr>
                <td><strong><?= $m['id'] ?></strong></td>
                <td><?= h($m['dieta']) ?></td>
                <td><?= fechaHora($m['fecha']) ?></td>
                <td><?= number_format($m['kg_preparados'], 0) ?> kg</td>
                <td><?= number_format($m['kg_servidos'], 1) ?> kg</td>
                <td><strong><?= number_format($m['kg_restantes'], 1) ?> kg</strong></td>
                <td>
                    <?php if ($puede): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?><input type="hidden" name="accion" value="cerrar"><input type="hidden" name="mezcla_id" value="<?= $m['id'] ?>">
                        <button class="btn btn-secondary btn-sm" type="submit" onclick="return confirm('¿Cerrar la mezcla #<?= $m['id'] ?>? Los <?= number_format($m['kg_restantes'], 1) ?> kg que quedan regresan al inventario.')">Cerrar y regresar sobrante</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p style="padding: 20px; color: var(--tenue);">No hay mezcla preparada. Prepara una para poder servir comida.</p>
    <?php endif; ?>
</div>

<?php if ($historial): ?>
<div class="table-container">
    <div class="table-header"><h2>Mezclas cerradas</h2></div>
    <table>
        <thead><tr><th>#</th><th>Dieta</th><th>Preparada</th><th>Preparado</th><th>Servido</th><th>Regreso a bodega</th><th>Cerrada</th></tr></thead>
        <tbody>
        <?php foreach ($historial as $m): ?>
            <tr><td><?= $m['id'] ?></td><td><?= h($m['dieta']) ?></td><td><?= fechaHora($m['fecha']) ?></td>
                <td><?= number_format($m['kg_preparados'], 0) ?> kg</td><td><?= number_format($m['kg_servidos'], 1) ?> kg</td>
                <td><?= number_format($m['kg_devueltos'], 1) ?> kg</td><td><?= fechaHora($m['fecha_cierre']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($puede): ?>
<script>
(function () {
    var RECETAS = <?= json_encode($recetasJs, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    var sel = document.getElementById('selDieta'), ton = document.getElementById('inpTon'), des = document.getElementById('desglose'), sug = document.getElementById('sugerencia');
    function calc() {
        var r = RECETAS[sel.value], kg = (parseFloat(ton.value) || 0) * 1000;
        var op = sel.options[sel.selectedIndex];
        var hay = op ? +op.dataset.hay || 0 : 0;
        sug.textContent = !op || !op.value ? '' : (hay > 0 ? 'Ya hay ' + hay.toLocaleString('es-MX') + ' kg de esta dieta preparados (se reparten primero, sin perderse). ' : '')
            + (op.dataset.falta > 0 ? 'Para terminar el dia faltan ' + op.dataset.falta + ' t.' : 'Con lo que hay alcanza para hoy; no hace falta preparar mas.');
        if (op && op.value && op.dataset.falta > 0 && !ton.dataset.tocado) ton.value = op.dataset.falta;
        if (!r || !kg) { des.hidden = true; return; }
        des.hidden = false;
        var filas = Object.keys(r).map(function (k) {
            var x = r[k], n = kg * x.p / 100, falta = n > x.s;
            return '<tr style="' + (falta ? 'color:#dc3545;font-weight:700;' : '') + '"><td>' + x.n + '</td><td style="text-align:right;">' + x.p + ' %</td><td style="text-align:right;">'
                + n.toLocaleString('es-MX', {maximumFractionDigits: 1}) + ' kg</td><td style="text-align:right;">' + x.s.toLocaleString('es-MX') + ' kg' + (falta ? ' (no alcanza)' : '') + '</td></tr>';
        }).join('');
        des.innerHTML = '<table><thead><tr><th>Sale de bodega</th><th style="text-align:right;">%</th><th style="text-align:right;">Cantidad</th><th style="text-align:right;">En bodega</th></tr></thead><tbody>' + filas
            + '</tbody><tfoot><tr style="font-weight:700;"><td>Total</td><td></td><td style="text-align:right;">' + kg.toLocaleString('es-MX') + ' kg</td><td></td></tr></tfoot></table>';
    }
    ton.addEventListener('input', function () { ton.dataset.tocado = 1; });
    sel.addEventListener('change', calc); ton.addEventListener('input', calc); calc();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
