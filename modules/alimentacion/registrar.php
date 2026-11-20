<?php
// ============================================
// ALIMENTACION - Registrar comida servida
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';
require_once __DIR__ . '/../../includes/dietas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$db = Database::getInstance()->getConnection();
$error = '';

$corral_id_pre = (int)($_GET['corral_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $corral_id = (int)($_POST['corral_id'] ?? 0);
        $dieta_id = (int)($_POST['dieta_id'] ?? 0);
        $cantidad_kg = (float)($_POST['cantidad_kg'] ?? 0);
        $horario = $_POST['horario'] ?? '';
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        if ($corral_id <= 0 || $dieta_id <= 0 || $cantidad_kg <= 0 || empty($horario)) {
            throw new Exception('Todos los campos son obligatorios');
        }
        
        // Verificar que el corral existe y tiene bovinos
        $stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
        $stmt->execute([$corral_id]);
        $corral = $stmt->fetch();
        
        if (!$corral) {
            throw new Exception('Corral no encontrado');
        }
        
        if (!in_array($horario, ['mañana', 'tarde'], true)) throw new Exception('Solo hay 2 comidas al dia: mañana y tarde');

        // Lo que tocaba servir a esta hora (para el control de comidas)
        $esperado = null; $pesoCorral = null;
        foreach (racionesCorrales($db) as $rc) {
            if ((int)$rc['id'] === $corral_id) { $esperado = $horario === 'mañana' ? $rc['kg_manana'] : $rc['kg_tarde']; $pesoCorral = $rc['peso_total']; }
        }

        $db->beginTransaction();
        // La comida sale de la mezcla ya preparada de esa dieta (los ingredientes salieron de bodega al prepararla)
        $mezcla_id = servirDeMezcla($db, $dieta_id, $cantidad_kg);

        $db->prepare("
            INSERT INTO alimentaciones (corral_id, dieta_id, mezcla_id, cantidad_kg, kg_esperado, peso_corral, horario, usuario_id, fecha, hora, observaciones)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE(), CURRENT_TIME(), ?)
        ")->execute([$corral_id, $dieta_id, $mezcla_id, $cantidad_kg, $esperado, $pesoCorral, $horario, $_SESSION['user_id'], $observaciones]);

        $db->commit();

        header('Location: ' . BASE_URL . '/alimentacion/control?msg=' . urlencode('Comida registrada'));
        exit;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// Corrales con animales y su racion calculada (dieta asignada o sugerida, kg por comida)
$dietasActivas = dietasActivas($db);
$corrales = racionesCorrales($db, $dietasActivas);
$dietas = array_values($dietasActivas);
$datosCorral = [];
foreach ($corrales as $c) {
    $datosCorral[$c['id']] = [
        'dieta' => $c['dieta_usar']['id'] ?? null,
        'kg' => ['mañana' => $c['kg_manana'], 'tarde' => $c['kg_tarde']],
        'texto' => $c['dieta_usar']
            ? "{$c['bovinos']} bovinos · " . number_format($c['peso_total'], 0) . " kg de peso x " . rtrim(rtrim($c['dieta_usar']['porcentaje_peso'], '0'), '.') . "% = "
              . number_format($c['kg_dia'], 2) . " kg/dia ({$c['dieta_usar']['nombre']}) · mañana {$c['kg_manana']} kg · tarde {$c['kg_tarde']} kg"
            : "{$c['bovinos']} bovinos · sin dieta para este tipo",
    ];
}
$mezclaDisp = kgMezclaPorDieta($db);
// Horario sugerido: tarde despues de las 13:00 (o el que venga en la liga del control de comidas)
$horarioSug = in_array($_GET['horario'] ?? '', ['mañana', 'tarde'], true) ? $_GET['horario'] : ((int)date('H') >= 13 ? 'tarde' : 'mañana');
$cfgCom = configComidas();

$titulo = 'Registrar Comida';
$modulo = 'alimentacion';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 700px; margin: 0 auto;">
    <div class="table-header">
        <h2>Registrar Comida</h2>
        <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" style="padding: 30px;">
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Corral *</label>
            <select name="corral_id" id="selCorral" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">-- Seleccionar corral --</option>
                <?php foreach ($corrales as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $corral_id_pre ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nombre']) ?> (<?= $c['bovinos'] ?> bovinos)
                    </option>
                <?php endforeach; ?>
            </select>
            <small id="infoCorral" style="display: block; margin-top: 6px; color: #0b4f86; font-weight: 600;"></small>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Dieta * <small style="color:var(--tenue);font-weight:normal;">(se elige sola segun el corral)</small></label>
            <select name="dieta_id" id="selDieta" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">-- Seleccionar dieta --</option>
                <?php foreach ($dietas as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?> (<?= number_format($mezclaDisp[$d['id']] ?? 0, 0) ?> kg de mezcla lista)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Cantidad (kg) *</label>
                <input type="number" name="cantidad_kg" id="inpKg" step="0.1" min="0.1" required
                       placeholder="Ej: 50"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <small style="color: var(--tenue);">Racion de esta comida, redondeada al kilo de arriba.</small>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Horario *</label>
                <select name="horario" id="selHorario" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <option value="mañana" <?= $horarioSug === 'mañana' ? 'selected' : '' ?>>Mañana <?= h($cfgCom['hora']['mañana']) ?> (<?= $cfgCom['pct']['mañana'] ?>%)</option>
                    <option value="tarde" <?= $horarioSug === 'tarde' ? 'selected' : '' ?>>Tarde <?= h($cfgCom['hora']['tarde']) ?> (<?= $cfgCom['pct']['tarde'] ?>%)</option>
                </select>
            </div>
        </div>
        
        <div id="desglose" style="margin-bottom: 20px; padding: 12px 15px; background: var(--suave); border-radius: 6px; font-size: 0.88rem;" hidden></div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Observaciones</label>
            <textarea name="observaciones" rows="2"
                      style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;"></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Registrar Comida</button>
        </div>
    </form>
</div>

<script>
(function () {
    var CORRALES = <?= json_encode($datosCorral, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    var selC = document.getElementById('selCorral'), selD = document.getElementById('selDieta'),
        kg = document.getElementById('inpKg'), selH = null, info = document.getElementById('infoCorral'), des = document.getElementById('desglose');

    var MEZCLA = <?= json_encode($mezclaDisp, JSON_FORCE_OBJECT) ?>;
    function desglose() {
        var hay = MEZCLA[selD.value] || 0, total = parseFloat(kg.value) || 0;
        if (!selD.value) { des.hidden = true; return; }
        des.hidden = false;
        des.innerHTML = total > hay
            ? '<span style="color:#dc3545;font-weight:700;">Solo hay ' + hay.toFixed(1) + ' kg de mezcla lista de esta dieta.</span> <a href="<?= BASE_URL ?>/alimentacion/mezclas?dieta=' + selD.value + '">Preparar mezcla</a>'
            : 'Sale de la mezcla preparada: quedan ' + hay.toFixed(1) + ' kg, despues de servir quedaran ' + (hay - total).toFixed(1) + ' kg.';
    }
    function corral() {
        var c = CORRALES[selC.value];
        info.textContent = c ? c.texto : '';
        if (c && c.dieta) { selD.value = c.dieta; kg.value = c.kg[selH.value] || ''; }
        desglose();
    }
    var selH = document.getElementById('selHorario');
    selH.addEventListener('change', corral);
    selC.addEventListener('change', corral);
    selD.addEventListener('change', desglose);
    kg.addEventListener('input', desglose);
    if (selC.value) corral();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
