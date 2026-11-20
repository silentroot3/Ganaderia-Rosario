<?php
// ============================================
// CORRALES - Migrar bovinos de un corral a otro
// Pensado para vaciar un corral y despues eliminarlo.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/asignacion_corrales.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('corrales', 'editar');

$corral_id = (int)($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
$stmt->execute([$corral_id]);
$corral = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$corral) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

$stmt = $db->prepare("
    SELECT * FROM bovinos
    WHERE corral_id = ? AND estado NOT IN ('vendido', 'muerto', 'devuelto')
    ORDER BY sexo, peso_entrada DESC
");
$stmt->execute([$corral_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hospital solo migra a otro hospital; los demas a corrales normales o de venta
$stmt = $db->prepare("
    SELECT * FROM corrales
    WHERE id != ? AND estado != 'Mantenimiento' AND es_hospital = ? AND es_recepcion = 0
    ORDER BY es_venta, nombre
");
$stmt->execute([$corral_id, (int)$corral['es_hospital']]);
$destinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        if ($corral['es_recepcion']) {
            throw new Exception('Los bovinos de RECEP se clasifican uno por uno desde Clasificacion');
        }
        $destino_id = (int)($_POST['destino_id'] ?? 0);
        $ids = array_map('intval', $_POST['bovinos'] ?? []);
        $validos = array_column($bovinos, null, 'id');
        $ids = array_values(array_filter($ids, fn($i) => isset($validos[$i])));
        if (!$ids) throw new Exception('Selecciona al menos un bovino');

        $destino = null;
        foreach ($destinos as $d) if ((int)$d['id'] === $destino_id) $destino = $d;
        if (!$destino) throw new Exception('Elige un corral destino valido');

        $libres = $destino['capacidad_maxima'] - $destino['ocupacion_actual'];
        if (count($ids) > $libres) {
            throw new Exception("{$destino['nombre']} solo tiene $libres lugares libres y seleccionaste " . count($ids));
        }

        $db->beginTransaction();
        foreach ($ids as $bid) {
            moverBovinoACorral($db, $bid, $destino_id, $_SESSION['user_id'], 'traslado',
                "Migracion de {$corral['nombre']} a {$destino['nombre']}", !$validos[$bid]['en_hospital']);
        }
        $db->commit();
        registrarLog($_SESSION['user_id'], 'corral_migrar', count($ids) . " bovinos de {$corral['nombre']} a {$destino['nombre']}");

        header('Location: ' . BASE_URL . '/corrales/migrar?id=' . $corral_id . '&msg=' . urlencode(count($ids) . " bovinos movidos a {$destino['nombre']}"));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$titulo = 'Migrar corral';
$modulo = 'corrales';
$accion = 'editar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 1000px; margin: 0 auto;">
    <div class="table-header">
        <h2>Migrar bovinos de <?= h($corral['nombre']) ?></h2>
        <a href="<?= BASE_URL ?>/corrales" class="btn btn-secondary">Volver</a>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-success" style="margin: 20px;"><?= h($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$bovinos): ?>
        <div style="padding: 40px; text-align: center;">
            <h3 style="color: #0b4f86;">El corral esta vacio</h3>
            <?php if (Permisos::puede('corrales', 'eliminar') && !$corral['es_recepcion']): ?>
                <p style="color: var(--tenue); margin: 10px 0 20px;">Ya puedes eliminarlo. Su historial (movimientos, alimentacion) se conserva.</p>
                <form method="POST" action="<?= BASE_URL ?>/corrales/eliminar" style="display: inline;">
                    <?= csrfField() ?><input type="hidden" name="id" value="<?= $corral_id ?>">
                    <button type="submit" class="btn" style="background: #dc3545; color: #fff;"
                            onclick="return confirm('¿Eliminar el corral <?= h($corral['nombre']) ?>?')">Eliminar corral</button>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif ($corral['es_recepcion']): ?>
        <div class="alert alert-info" style="margin: 20px;">
            Los <?= count($bovinos) ?> bovinos de <?= h($corral['nombre']) ?> todavia no estan clasificados.
            <a href="<?= BASE_URL ?>/clasificacion">Clasificalos uno por uno</a> para asignarles categoria y corral.
        </div>
    <?php else: ?>
    <form method="POST" style="padding: 25px;">
        <?= csrfField() ?>
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 15px; align-items: end; margin-bottom: 20px;">
            <div>
                <label style="<?= ESTILO_LABEL ?>">Mover a</label>
                <select name="destino_id" required style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Corral destino --</option>
                    <?php foreach ($destinos as $d): $libres = $d['capacidad_maxima'] - $d['ocupacion_actual']; ?>
                        <option value="<?= $d['id'] ?>" <?= $libres <= 0 ? 'disabled' : '' ?> <?= (int)($_POST['destino_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= h($d['nombre']) ?> · <?= h($d['tipo']) ?><?= $d['es_venta'] ? ' (venta)' : '' ?> · <?= $libres ?> libres
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 11px 25px;">Migrar seleccionados</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="todos" checked></th>
                    <th>SINIIGA</th><th>Sexo</th><th>Categoria</th><th>Edad</th><th>Peso</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bovinos as $b): ?>
                <tr>
                    <td><input type="checkbox" class="sel" name="bovinos[]" value="<?= $b['id'] ?>" checked></td>
                    <td><strong style="font-family: monospace;"><?= h($b['id_siniiga']) ?></strong></td>
                    <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                    <td><?= h($b['categoria'] ?? '-') ?></td>
                    <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-' ?></td>
                    <td><?= $b['peso_entrada'] ? number_format($b['peso_entrada'], 1) . ' kg' : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top: 12px; color: var(--tenue);"><span id="nSel"><?= count($bovinos) ?></span> de <?= count($bovinos) ?> seleccionados</p>
    </form>
    <script>
    (function () {
        var sels = document.querySelectorAll('.sel'), todos = document.getElementById('todos'), n = document.getElementById('nSel');
        function contar() { n.textContent = Array.prototype.filter.call(sels, function (s) { return s.checked; }).length; }
        todos.addEventListener('change', function () { sels.forEach(function (s) { s.checked = todos.checked; }); contar(); });
        sels.forEach(function (s) { s.addEventListener('change', contar); });
    })();
    </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
