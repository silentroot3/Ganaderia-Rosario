<?php
// ============================================
// ALIMENTACION - Dietas por etapa (Inicio / Desarrollo / Engorda)
// Las define el veterinario: ingredientes en %, kg por tonelada y consumo diario.
// Abajo: racion de hoy por corral (2 comidas al dia) segun el peso de sus animales.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$db = Database::getInstance()->getConnection();
$error = '';
$editable = puedeEditarDietas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$editable) throw new Exception('Solo el veterinario puede cambiar las dietas');
        exigirCSRF();
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar_dieta') {
            $dieta_id = (int)$_POST['dieta_id'];
            $pct = (float)($_POST['porcentaje_peso'] ?? 0);
            $receta = array_filter(array_map('floatval', $_POST['receta'] ?? []), fn($v) => $v > 0);
            if ($pct <= 0 || $pct > 5) throw new Exception('El consumo debe estar entre 0.1 y 5 % del peso vivo');
            if (abs(array_sum($receta) - 100) > 0.5) throw new Exception('La receta debe sumar 100 % (suma ' . round(array_sum($receta), 2) . ' %)');

            $db->beginTransaction();
            $db->prepare("UPDATE dietas SET porcentaje_peso = ?, descripcion = ? WHERE id = ?")
               ->execute([$pct, trim($_POST['descripcion'] ?? '') ?: null, $dieta_id]);
            $db->prepare("DELETE FROM dieta_ingredientes WHERE dieta_id = ?")->execute([$dieta_id]);
            $ins = $db->prepare("INSERT INTO dieta_ingredientes (dieta_id, ingrediente_id, porcentaje) VALUES (?, ?, ?)");
            foreach ($receta as $ing => $p) $ins->execute([$dieta_id, (int)$ing, $p]);
            $db->commit();
            registrarLog($_SESSION['user_id'], 'dieta_editar', "Dieta #$dieta_id actualizada");
            $msg = 'Dieta actualizada. Las mezclas nuevas usaran esta receta.';

        } elseif ($accion === 'asignar') {
            asignarDietaCorral($db, (int)$_POST['corral_id'], (int)$_POST['dieta_id'], $_SESSION['user_id']);
            $msg = 'Dieta asignada al corral';

        } elseif ($accion === 'asignar_sugeridas') {
            $n = 0;
            foreach (racionesCorrales($db) as $r) {
                if ($r['sugerida'] && (!$r['dieta'] || $r['dieta']['id'] != $r['sugerida']['id'])) {
                    asignarDietaCorral($db, $r['id'], $r['sugerida']['id'], $_SESSION['user_id']);
                    $n++;
                }
            }
            $msg = "$n corrales actualizados con la dieta de su etapa";
        } else {
            throw new Exception('Accion no valida');
        }

        header('Location: ' . BASE_URL . '/alimentacion/dietas?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$dietas = dietasActivas($db);
$raciones = racionesCorrales($db, $dietas);
$etapas = $db->query("SELECT id, nombre, duracion_meses FROM etapas_alimentacion ORDER BY orden")->fetchAll(PDO::FETCH_UNIQUE);
$ingredientes = $db->query("
    SELECT i.id, i.nombre, COALESCE(inv.stock_actual, 0) AS stock
    FROM ingredientes i LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = i.id
    WHERE i.activo = 1 ORDER BY i.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$necesarioDieta = [];
foreach ($raciones as $r) if ($r['dieta_usar']) $necesarioDieta[$r['dieta_usar']['id']] = ($necesarioDieta[$r['dieta_usar']['id']] ?? 0) + $r['kg_servir'];
$preparado = kgMezclaPorDieta($db);
$pendientesSugerir = count(array_filter($raciones, fn($r) => $r['sugerida'] && (!$r['dieta'] || $r['dieta']['id'] != $r['sugerida']['id'])));

$titulo = 'Dietas';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.dt-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:25px; }
.dt-card table td, .dt-card table th { padding:6px 10px; font-size:0.88rem; white-space:nowrap; }
.dt-pct { width:80px; padding:5px; border:1px solid var(--linea); border-radius:4px; text-align:right; }
</style>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success"><?= h($_GET['msg']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver al dashboard</a>
    <?php if (Permisos::puedeCrear('alimentacion')): ?>
        <a href="<?= BASE_URL ?>/alimentacion/mezclas" class="btn btn-primary">Preparar mezcla (toneladas)</a>
        <a href="<?= BASE_URL ?>/alimentacion/repartir" class="btn btn-primary">Repartir comida</a>
        <a href="<?= BASE_URL ?>/alimentacion/corrales" class="btn btn-secondary">Dieta de cada corral</a>
    <?php endif; ?>
</div>

<?php $cfgC = configComidas(); $pctEj = (float)(reset($dietas)['porcentaje_peso'] ?? 3); $ej = 300 * $pctEj / 100; ?>
<div class="explica">
    <div class="explica-n"><?= rtrim(rtrim(number_format($pctEj, 1), '0'), '.') ?>%</div>
    <div>
        <strong>¿Cuánto come cada animal al día?</strong> El <?= rtrim(rtrim(number_format($pctEj, 1), '0'), '.') ?>% de lo que pesa.
        Por ejemplo, un bovino de <strong>300 kg</strong> come <strong><?= rtrim(rtrim(number_format($ej, 1), '0'), '.') ?> kg</strong> de comida al día:
        <?= rtrim(rtrim(number_format($ej * $cfgC['pct']['mañana'] / 100, 1), '0'), '.') ?> kg en la mañana (<?= $cfgC['pct']['mañana'] ?>%) y
        <?= rtrim(rtrim(number_format($ej * $cfgC['pct']['tarde'] / 100, 1), '0'), '.') ?> kg en la tarde (<?= $cfgC['pct']['tarde'] ?>%).
        Para un corral se suman los kilos de todos sus animales.
        <br><span class="muted">Abajo, cada tarjeta es una <b>receta</b>: de qué ingredientes se hace 1 tonelada de esa dieta. <?= $editable ? 'Tú puedes ajustarla.' : 'Las recetas las define el veterinario.' ?></span>
    </div>
</div>
<style>
.explica { display:flex; gap:16px; align-items:center; padding:16px 18px; margin-bottom:20px; background:var(--superficie); border:1px solid var(--linea); border-left:3px solid var(--dorado); border-radius:var(--radio); line-height:1.6; }
.explica-n { font-size:1.9rem; font-weight:700; color:var(--azul); min-width:72px; text-align:center; }
</style>

<div class="dt-grid">
<?php foreach ($dietas as $d):
    $suma = array_sum(array_column($d['receta'], 'porcentaje'));
    $pctIng = array_column($d['receta'], 'porcentaje', 'ingrediente_id');
    $etapa = $etapas[$d['etapa_id']] ?? null; ?>
    <div class="table-container dt-card">
        <div class="table-header">
            <h2><?= h($d['nombre']) ?></h2>
            <?php if ($etapa): ?><small style="color:var(--tenue);">Etapa <?= h($etapa['nombre']) ?> · <?= (int)$etapa['duracion_meses'] ?> meses</small><?php endif; ?>
        </div>
        <div style="padding: 10px 15px; font-size: 0.88rem; color: var(--texto-2);">
            <strong>Para:</strong> <?= h(str_replace(',', ', ', $d['categorias'] ?? '-')) ?><br>
            <?= h($d['descripcion'] ?? '') ?><br>
            Hoy se necesitan <strong><?= number_format(($necesarioDieta[$d['id']] ?? 0), 0) ?> kg</strong>
            · preparados <strong><?= number_format($preparado[$d['id']] ?? 0, 0) ?> kg</strong>
        </div>
        <form method="POST" style="padding: 0 15px 15px;">
            <?= csrfField() ?><input type="hidden" name="accion" value="guardar_dieta"><input type="hidden" name="dieta_id" value="<?= $d['id'] ?>">
            <table>
                <thead><tr><th>Ingrediente</th><th style="text-align:right;">%</th><th style="text-align:right;">kg por tonelada</th></tr></thead>
                <tbody>
                <?php foreach ($ingredientes as $i): $p = (float)($pctIng[$i['id']] ?? 0); if (!$editable && $p <= 0) continue; ?>
                    <tr>
                        <td><?= h($i['nombre']) ?></td>
                        <td style="text-align:right;">
                            <?php if ($editable): ?>
                                <input type="number" class="dt-pct" name="receta[<?= $i['id'] ?>]" step="0.5" min="0" max="100" value="<?= $p ? rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') : '' ?>">
                            <?php else: ?><?= rtrim(rtrim(number_format($p, 2), '0'), '.') ?> %<?php endif; ?>
                        </td>
                        <td style="text-align:right;" class="dt-kg"><?= number_format($p * 10, 1) ?> kg</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:700;"><td>Total</td><td style="text-align:right;" class="dt-suma"><?= rtrim(rtrim(number_format($suma, 2), '0'), '.') ?> %</td><td style="text-align:right;">1,000 kg</td></tr></tfoot>
            </table>
            <?php if ($editable): ?>
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:10px; margin-top:10px;">
                <div><label style="<?= ESTILO_LABEL ?>" title="Cuanto come cada animal al dia, en % de su peso">Come al día (% de su peso)</label><input type="number" name="porcentaje_peso" step="0.1" min="0.1" max="5" value="<?= h($d['porcentaje_peso']) ?>" style="<?= ESTILO_INPUT ?>"></div>
                <div><label style="<?= ESTILO_LABEL ?>">Descripcion</label><input type="text" name="descripcion" value="<?= h($d['descripcion'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;">Guardar dieta</button>
            <?php else: ?>
                <p style="margin-top:8px; font-size:0.85rem;">Cada animal come al día el <strong><?= rtrim(rtrim($d['porcentaje_peso'], '0'), '.') ?>% de su peso</strong></p>
            <?php endif; ?>
        </form>
    </div>
<?php endforeach; ?>
</div>

<!-- Racion por corral -->
<div class="table-container">
    <div class="table-header">
        <h2>Cuánto come hoy cada corral</h2>
        <?php if ($editable && $pendientesSugerir): ?>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="accion" value="asignar_sugeridas">
            <button class="btn btn-primary btn-sm" type="submit">Asignar dieta segun etapa (<?= $pendientesSugerir ?>)</button>
        </form>
        <?php endif; ?>
    </div>
    <p style="padding: 0 20px 10px; color: var(--tenue); font-size: 0.85rem;">Kg al dia = lo que pesan todos los animales del corral &times; el % de su dieta. Se reparte <?= configComidas()['pct']['mañana'] ?>% en la mañana y <?= configComidas()['pct']['tarde'] ?>% en la tarde, redondeando cada comida al kilo de arriba. Se ajusta solo cuando entran, salen o engordan los animales.</p>
    <?php if ($raciones): ?>
    <table>
        <thead><tr><th>Corral</th><th>Bovinos</th><th>Peso total</th><th>Tipo predominante</th><th>Dieta</th><th>Kg al dia (3%)</th><th>Mañana <?= h(configComidas()['hora']['mañana']) ?></th><th>Tarde <?= h(configComidas()['hora']['tarde']) ?></th></tr></thead>
        <tbody>
        <?php foreach ($raciones as $r): ?>
            <tr>
                <td><strong><?= h($r['nombre']) ?></strong></td>
                <td><?= $r['bovinos'] ?></td>
                <td><?= number_format($r['peso_total'], 0) ?> kg</td>
                <td><?= h($r['categoria'] ?? '-') ?></td>
                <td>
                    <?php if ($r['dieta']): ?><?= pill($r['dieta']['nombre'], 'var(--f-verde)', 'var(--t-verde)') ?>
                    <?php elseif ($r['sugerida']): ?><?= pill('Sugerida: ' . $r['sugerida']['nombre'], 'var(--f-amarillo)', 'var(--t-amarillo)') ?>
                    <?php else: ?><span style="color:#dc3545;">Sin dieta</span><?php endif; ?>
                </td>
                <td><?= number_format($r['kg_dia'], 2) ?> kg</td>
                <td><strong><?= $r['kg_manana'] ?> kg</strong></td>
                <td><strong><?= $r['kg_tarde'] ?> kg</strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="padding:14px 20px;border-top:1px solid var(--linea);"><a href="<?= BASE_URL ?>/alimentacion/corrales" class="btn btn-secondary btn-sm">Cambiar la dieta de un corral</a></div>
    <?php else: ?>
        <div style="padding: 40px; text-align: center; color: var(--tenue);">No hay corrales con animales.</div>
    <?php endif; ?>
</div>

<?php if ($editable): ?>
<script>
document.querySelectorAll('.dt-card form').forEach(function (f) {
    function calc() {
        var s = 0;
        f.querySelectorAll('.dt-pct').forEach(function (x) {
            var v = parseFloat(x.value) || 0; s += v;
            x.closest('tr').querySelector('.dt-kg').textContent = (v * 10).toLocaleString('es-MX', {maximumFractionDigits: 1}) + ' kg';
        });
        var t = f.querySelector('.dt-suma'); t.textContent = s + ' %'; t.style.color = Math.abs(s - 100) < 0.5 ? '#28a745' : '#dc3545';
    }
    f.addEventListener('input', calc); calc();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
