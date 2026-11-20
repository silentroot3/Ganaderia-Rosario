<?php
// ============================================
// COSTOS - Registrar gasto manual (a un animal, a un corral o general)
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('costos', 'crear');
        exigirCSRF();

        $alcance = $_POST['alcance'] ?? 'animal';
        $tipo = in_array($_POST['tipo'] ?? '', ['veterinario','operativo','otro'], true) ? $_POST['tipo'] : 'otro';
        $monto = (float)($_POST['monto'] ?? 0);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $desc = trim($_POST['descripcion'] ?? '');
        if ($monto <= 0) throw new Exception('El monto debe ser mayor a 0');
        if ($desc === '') throw new Exception('Escribe una descripcion');

        $destinos = []; // lista de bovino_id (o null = gasto general)
        if ($alcance === 'animal') {
            $stmt = $db->prepare("SELECT id FROM bovinos WHERE id_siniiga = ?");
            $stmt->execute([trim($_POST['arete'] ?? '')]);
            $bid = (int)$stmt->fetchColumn();
            if (!$bid) throw new Exception('Arete no encontrado');
            $destinos = [$bid];
        } elseif ($alcance === 'corral') {
            $stmt = $db->prepare("SELECT id FROM bovinos WHERE corral_id = ? AND estado = 'en_corral'");
            $stmt->execute([(int)($_POST['corral_id'] ?? 0)]);
            $destinos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (!$destinos) throw new Exception('El corral no tiene bovinos');
        } else {
            $destinos = [null];
        }

        $db->beginTransaction();
        $porAnimal = round($monto / count($destinos), 2);
        foreach ($destinos as $bid) {
            $db->prepare("INSERT INTO costos (bovino_id, tipo, descripcion, monto, fecha, usuario_id) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$bid, $tipo, $desc . ($alcance === 'corral' ? ' (repartido en corral)' : ''), $porAnimal, $fecha, $_SESSION['user_id']]);
        }
        $db->commit();
        header('Location: ' . BASE_URL . '/costos?msg=' . urlencode('Gasto registrado'));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$titulo = 'Registrar gasto';
$modulo = 'costos';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$corrales = $db->query("SELECT id, nombre FROM corrales WHERE es_hospital = 0 AND es_venta = 0 ORDER BY nombre")->fetchAll();
$aretes = $db->query("SELECT id_siniiga FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto') ORDER BY id_siniiga")->fetchAll(PDO::FETCH_COLUMN);
$alc = $_POST['alcance'] ?? 'animal';
?>

<div class="table-container" style="max-width:700px;margin:0 auto;">
    <div class="table-header"><h2>Registrar gasto</h2><a href="<?= BASE_URL ?>/costos" class="btn btn-secondary">Volver</a></div>
    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" style="padding:25px;">
        <?= csrfField() ?>
        <div style="margin-bottom:15px;">
            <label style="<?= ESTILO_LABEL ?>">Aplicar a</label>
            <select name="alcance" id="alcance" style="<?= ESTILO_INPUT ?>">
                <option value="animal" <?= $alc === 'animal' ? 'selected' : '' ?>>Un animal</option>
                <option value="corral" <?= $alc === 'corral' ? 'selected' : '' ?>>Todos los animales de un corral (se reparte)</option>
                <option value="general" <?= $alc === 'general' ? 'selected' : '' ?>>Gasto general del rancho (sin asignar)</option>
            </select>
        </div>
        <div id="f-animal" style="margin-bottom:15px;">
            <label style="<?= ESTILO_LABEL ?>">Arete</label>
            <input type="text" name="arete" list="aretes" value="<?= h($_POST['arete'] ?? ($_GET['arete'] ?? '')) ?>" style="<?= ESTILO_INPUT ?>">
            <datalist id="aretes"><?php foreach ($aretes as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?></datalist>
        </div>
        <div id="f-corral" style="margin-bottom:15px;display:none;">
            <label style="<?= ESTILO_LABEL ?>">Corral</label>
            <select name="corral_id" style="<?= ESTILO_INPUT ?>"><?php foreach ($corrales as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['nombre']) ?></option><?php endforeach; ?></select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;margin-bottom:15px;">
            <div><label style="<?= ESTILO_LABEL ?>">Tipo</label>
                <select name="tipo" style="<?= ESTILO_INPUT ?>"><option value="operativo">Operativo</option><option value="veterinario">Veterinario</option><option value="otro">Otro</option></select></div>
            <div><label style="<?= ESTILO_LABEL ?>">Monto *</label><input type="number" name="monto" step="0.01" min="0.01" required style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div style="margin-bottom:20px;"><label style="<?= ESTILO_LABEL ?>">Descripcion *</label><input type="text" name="descripcion" required style="<?= ESTILO_INPUT ?>" placeholder="Flete, mano de obra, vacuna externa..."></div>
        <div style="text-align:right;"><button class="btn btn-primary" type="submit">Guardar gasto</button></div>
    </form>
</div>
<script>
(function () {
    var s = document.getElementById('alcance');
    function act() {
        document.getElementById('f-animal').style.display = s.value === 'animal' ? 'block' : 'none';
        document.getElementById('f-corral').style.display = s.value === 'corral' ? 'block' : 'none';
    }
    s.addEventListener('change', act); act();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
