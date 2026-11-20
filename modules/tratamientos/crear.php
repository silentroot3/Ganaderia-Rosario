<?php
// ============================================
// TRATAMIENTOS - Registrar medicina aplicada (HU-011)
// Descuenta inventario, calcula costo y dias de retiro
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/tratamientos_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('tratamientos', 'crear');
        exigirCSRF();

        $arete = trim($_POST['arete'] ?? '');
        $stmt = $db->prepare("SELECT id FROM bovinos WHERE id_siniiga = ?");
        $stmt->execute([$arete]);
        $bovino_id = (int)$stmt->fetchColumn();
        if (!$bovino_id) throw new Exception('No existe un bovino con el arete ' . $arete);

        registrarTratamiento(
            $bovino_id,
            (int)($_POST['medicamento_id'] ?? 0),
            $_POST['dosis'] ?? 0,
            trim($_POST['via_administracion'] ?? ''),
            trim($_POST['diagnostico'] ?? ''),
            trim($_POST['observaciones'] ?? ''),
            $_SESSION['user_id'],
            ($_POST['dias_retiro'] ?? '') === '' ? null : (int)$_POST['dias_retiro'],
            (int)($_POST['num_dosis'] ?? 1),
            (int)($_POST['intervalo_horas'] ?? 24)
        );
        registrarLog($_SESSION['user_id'], 'tratamiento', "Tratamiento a bovino $arete");
        header('Location: ' . BASE_URL . '/tratamientos?msg=' . urlencode('Tratamiento registrado y bovino bloqueado para venta si aplica'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Registrar tratamiento';
$modulo = 'tratamientos';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$arete_pre = $_POST['arete'] ?? ($_GET['arete'] ?? '');
// Cualquier bovino del rancho (este o no en veterinaria), con su peso actual para calcular la dosis
$pesos = $db->query("
    SELECT b.id_siniiga, COALESCE((SELECT p.peso FROM pesajes p WHERE p.bovino_id = b.id ORDER BY p.fecha DESC, p.id DESC LIMIT 1), b.peso_entrada) AS peso
    FROM bovinos b
    WHERE b.estado NOT IN ('vendido','muerto','devuelto') ORDER BY b.id_siniiga
")->fetchAll(PDO::FETCH_KEY_PAIR);
$bovinos = array_keys($pesos);

$meds = $db->query("
    SELECT m.id, m.nombre, m.tipo, m.dias_retiro, m.via_administracion, m.dosis_recomendada, m.dosis_ml, m.dosis_cada_kg,
           COALESCE(i.stock_actual, 0) AS stock, COALESCE(i.unidad, 'ml') AS unidad
    FROM medicamentos m
    LEFT JOIN inventario_medicamentos i ON i.medicamento_id = m.id
    WHERE m.activo = 1 ORDER BY m.nombre
")->fetchAll();
?>

<div class="table-container" style="max-width:760px;margin:0 auto;">
    <div class="table-header">
        <h2>Registrar tratamiento</h2>
        <a href="<?= BASE_URL ?>/tratamientos" class="btn btn-secondary">Volver</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>

    <form method="POST" style="padding:25px;">
        <?= csrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <div>
                <label style="<?= ESTILO_LABEL ?>">Arete SINIIGA *</label>
                <input type="text" name="arete" required list="lista-aretes" value="<?= h($arete_pre) ?>" style="<?= ESTILO_INPUT ?>">
                <datalist id="lista-aretes">
                    <?php foreach ($bovinos as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Medicamento *</label>
                <select name="medicamento_id" id="med" required style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($meds as $m): ?>
                        <option value="<?= $m['id'] ?>"
                                data-retiro="<?= (int)$m['dias_retiro'] ?>"
                                data-via="<?= h($m['via_administracion']) ?>"
                                data-dosis="<?= h($m['dosis_recomendada']) ?>"
                                data-ml="<?= h($m['dosis_ml']) ?>" data-cadakg="<?= h($m['dosis_cada_kg']) ?>"
                                <?= (int)($_POST['medicamento_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= h($m['nombre']) ?> (<?= number_format($m['stock'], 1) . ' ' . h($m['unidad']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small id="med-info" style="color:var(--tenue);"></small>
            </div>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Dosis (misma unidad del inventario) *</label>
                <input type="number" name="dosis" step="0.01" min="0.01" required value="<?= h($_POST['dosis'] ?? '') ?>" style="<?= ESTILO_INPUT ?>">
                <small id="dosisCalc" style="color:#0b4f86;font-weight:600;"></small>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Numero de dosis</label>
                <input type="number" name="num_dosis" id="numDosis" min="1" max="60" value="<?= htmlspecialchars($_POST['num_dosis'] ?? '1') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Cada cuanto</label>
                <select name="intervalo_horas" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <?php foreach ([12 => 'Cada 12 horas', 24 => 'Cada 24 horas (1 al dia)', 48 => 'Cada 48 horas', 72 => 'Cada 72 horas', 168 => 'Cada semana'] as $h => $txt): ?>
                        <option value="<?= $h ?>" <?= (int)($_POST['intervalo_horas'] ?? 24) === $h ? 'selected' : '' ?>><?= $txt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="grid-column: span 2; padding: 10px 14px; background: var(--f-azul); border-radius: 6px; font-size: 0.9rem;" id="resumenDosis">
                Se descuenta del inventario y queda apartado para este bovino: <strong id="totalDosis">-</strong>
            </div>
            <script>
            (function () {
                var dosis = document.querySelector('[name=dosis]'), n = document.getElementById('numDosis'), t = document.getElementById('totalDosis');
                function calc() {
                    var d = parseFloat(dosis.value) || 0, k = parseInt(n.value, 10) || 1;
                    t.textContent = d ? (d * k).toLocaleString('es-MX', {maximumFractionDigits: 2}) + ' (' + k + ' dosis de ' + d + ')' : '-';
                }
                dosis.addEventListener('input', calc); n.addEventListener('input', calc); calc();
            })();
            </script>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Via de administracion</label>
                <select name="via_administracion" id="via" style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach (['Intramuscular','Subcutanea','Intravenosa','Oral','Topica'] as $v): ?>
                        <option <?= ($_POST['via_administracion'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Dias de retiro (sin venta)</label>
                <input type="number" name="dias_retiro" id="retiro" min="0" placeholder="Por defecto el del medicamento"
                       value="<?= h($_POST['dias_retiro'] ?? '') ?>" style="<?= ESTILO_INPUT ?>">
            </div>
            <div style="grid-column:span 2;">
                <label style="<?= ESTILO_LABEL ?>">Diagnostico *</label>
                <textarea name="diagnostico" rows="3" required style="<?= ESTILO_INPUT ?>"><?= h($_POST['diagnostico'] ?? '') ?></textarea>
            </div>
            <div style="grid-column:span 2;">
                <label style="<?= ESTILO_LABEL ?>">Observaciones</label>
                <textarea name="observaciones" rows="2" style="<?= ESTILO_INPUT ?>"><?= h($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
            <a href="<?= BASE_URL ?>/tratamientos" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar tratamiento</button>
        </div>
    </form>
</div>

<script>
document.getElementById('med').addEventListener('change', function () {
    var o = this.options[this.selectedIndex];
    var info = document.getElementById('med-info');
    if (!o.value) { info.textContent = ''; return; }
    info.textContent = 'Retiro por defecto: ' + o.dataset.retiro + ' dias' +
        (o.dataset.dosis ? ' | Dosis recomendada: ' + o.dataset.dosis : '');
    if (o.dataset.via) document.getElementById('via').value = o.dataset.via;
});
document.getElementById('med').dispatchEvent(new Event('change'));
window.PESOS_BOVINOS = <?= json_encode(array_map('floatval', $pesos), JSON_FORCE_OBJECT) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/dosis-peso.js?v=<?= @filemtime(BASE_PATH . '/assets/js/dosis-peso.js') ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
