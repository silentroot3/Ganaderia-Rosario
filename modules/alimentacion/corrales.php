<?php
// ============================================
// ALIMENTACION - Dieta fija de cada corral
// Cada corral tiene su dieta y no cambia sola: solo cuando el alimentador la cambia aqui.
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
$puede = Permisos::puedeEditar('alimentacion') || puedeEditarDietas();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$puede) throw new Exception('No tienes permiso para cambiar dietas de corrales');
        exigirCSRF();
        $dietas = dietasActivas($db);
        $actual = $db->query("SELECT corral_id, dieta_id FROM corral_dietas WHERE activa = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        $n = 0;
        $db->beginTransaction();
        foreach ((array)($_POST['dieta'] ?? []) as $cid => $did) {
            $cid = (int)$cid; $did = (int)$did;
            if (!$did || !isset($dietas[$did]) || (int)($actual[$cid] ?? 0) === $did) continue;
            asignarDietaCorral($db, $cid, $did, $_SESSION['user_id']);
            $n++;
        }
        $db->commit();
        if ($n) registrarLog($_SESSION['user_id'], 'dieta_corral', "$n corrales con dieta nueva");
        header('Location: ' . BASE_URL . '/alimentacion/corrales?msg=' . urlencode($n ? "$n corral(es) con dieta nueva" : 'Sin cambios'));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$dietas = dietasActivas($db);
$raciones = [];
foreach (racionesCorrales($db, $dietas) as $r) $raciones[(int)$r['id']] = $r;
$corrales = $db->query("
    SELECT c.id, c.nombre, c.es_hospital, c.es_recepcion, c.es_venta, c.ocupacion_actual,
           (SELECT cd.dieta_id FROM corral_dietas cd WHERE cd.corral_id = c.id AND cd.activa = 1 ORDER BY cd.id DESC LIMIT 1) AS dieta_id,
           (SELECT cd.fecha_asignacion FROM corral_dietas cd WHERE cd.corral_id = c.id AND cd.activa = 1 ORDER BY cd.id DESC LIMIT 1) AS desde
    FROM corrales c ORDER BY c.ocupacion_actual = 0, c.es_recepcion DESC, c.es_hospital, c.es_venta, c.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$sinFijar = 0;
foreach ($corrales as $c) if ($c['ocupacion_actual'] > 0 && !$c['dieta_id']) $sinFijar++;

$titulo = 'Dieta de cada corral';
$modulo = 'alimentacion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="page-actions">
    <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">&larr; Alimentación</a>
    <a href="<?= BASE_URL ?>/alimentacion/repartir" class="btn btn-primary">Repartir comida</a>
</div>

<div class="alert alert-info alert-fija">
    Cada corral come <strong>siempre la misma dieta</strong> hasta que tú la cambies aquí. Así se prepara una sola mezcla por dieta y se reparte a todos sus corrales.
    <?php if ($sinFijar): ?><br><strong><?= $sinFijar ?> corral(es) con animales no tienen dieta fija</strong>: mientras tanto se usa la de su etapa (marcada como sugerida).<?php endif; ?>
</div>

<form method="POST" class="table-container">
    <?= csrfField() ?>
    <div class="table-header"><h2>Corrales</h2><?php if ($puede): ?><button class="btn btn-primary btn-sm" type="submit">Guardar cambios</button><?php endif; ?></div>
    <table>
        <thead><tr><th>Corral</th><th>Animales</th><th>Tipo que predomina</th><th>Dieta</th><th>Desde</th><th>Comida diaria</th></tr></thead>
        <tbody>
        <?php foreach ($corrales as $c): $r = $raciones[(int)$c['id']] ?? null; $sug = $r['sugerida'] ?? null; ?>
            <tr<?= $c['ocupacion_actual'] > 0 ? '' : ' class="fila-vacia"' ?>>
                <td><strong><?= h($c['nombre']) ?></strong>
                    <?= $c['es_hospital'] ? ' <span class="chip chip-rojo">Hospital</span>' : '' ?><?= $c['es_recepcion'] ? ' <span class="chip chip-azul">Recepción</span>' : '' ?><?= $c['es_venta'] ? ' <span class="chip chip-gris">Venta</span>' : '' ?></td>
                <td><?= (int)$c['ocupacion_actual'] ?: '<span class="muted">vacío</span>' ?></td>
                <td><?= h($r['categoria'] ?? '—') ?></td>
                <td>
                    <?php if ($puede): ?>
                    <select name="dieta[<?= $c['id'] ?>]" style="min-width:170px;">
                        <option value="">— Sin dieta fija —</option>
                        <?php foreach ($dietas as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)$c['dieta_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['nombre']) ?><?= $sug && !$c['dieta_id'] && $sug['id'] == $d['id'] ? ' (sugerida)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?><?= h($dietas[$c['dieta_id']]['nombre'] ?? '—') ?><?php endif; ?>
                    <?php if (!$c['dieta_id'] && $c['ocupacion_actual'] > 0 && $sug): ?><br><small class="muted">usa <?= h($sug['nombre']) ?> por su etapa</small><?php endif; ?>
                </td>
                <td class="muted"><?= $c['desde'] ? fechaCorta($c['desde']) : '—' ?></td>
                <td><?= $r ? number_format($r['kg_servir'], 0) . ' kg <small class="muted">(' . $r['kg_manana'] . ' + ' . $r['kg_tarde'] . ')</small>' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</form>
<style>.fila-vacia td { color: var(--tenue); }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
