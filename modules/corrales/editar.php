<?php
// ============================================
// CORRALES - Editar corral
// ============================================

require_once __DIR__ . '/../../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

if ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'clasificador') {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$corral_id = (int)($_GET['id'] ?? 0);
if (!$corral_id) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
$stmt->execute([$corral_id]);
$corral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$corral) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $capacidad = (int)($_POST['capacidad_maxima'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $sexo = $_POST['sexo_permitido'] ?? 'Mixto';
        $peso_min = $_POST['peso_min'] !== '' ? (float)$_POST['peso_min'] : null;
        $peso_max = $_POST['peso_max'] !== '' ? (float)$_POST['peso_max'] : null;
        $es_hospital = isset($_POST['es_hospital']) ? 1 : 0;
        $es_venta = isset($_POST['es_venta']) ? 1 : 0;
        $estado = $_POST['estado'] ?? 'Disponible';

        if (empty($nombre)) {
            throw new Exception('El nombre del corral es obligatorio');
        }

        if ($capacidad <= 0) {
            throw new Exception('La capacidad debe ser mayor a 0');
        }

        if ($capacidad < $corral['ocupacion_actual']) {
            throw new Exception('No puedes reducir la capacidad por debajo de la ocupacion actual (' . $corral['ocupacion_actual'] . ')');
        }

        $stmt = $db->prepare("SELECT id FROM corrales WHERE nombre = ? AND id != ?");
        $stmt->execute([$nombre, $corral_id]);
        if ($stmt->fetch()) {
            throw new Exception('Ya existe otro corral con ese nombre');
        }

        $stmt = $db->prepare("
            UPDATE corrales
            SET nombre = ?, capacidad_maxima = ?, tipo = ?, sexo_permitido = ?,
                peso_min = ?, peso_max = ?, es_hospital = ?, es_venta = ?, estado = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $nombre, $capacidad, $tipo, $sexo,
            $peso_min, $peso_max, $es_hospital, $es_venta, $estado,
            $corral_id
        ]);

        header('Location: ' . BASE_URL . '/corrales?msg=editado');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Editar Corral';
$modulo = 'corrales';
$accion = 'editar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 800px; margin: 0 auto;">
    <div class="table-header">
        <h2>Editar Corral: <?= htmlspecialchars($corral['nombre']) ?></h2>
        <a href="<?= BASE_URL ?>/corrales" class="btn btn-secondary">Volver</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" style="padding: 30px;">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nombre del corral *</label>
                <input type="text" name="nombre" required
                       value="<?= htmlspecialchars($corral['nombre']) ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Capacidad maxima *</label>
                <input type="number" name="capacidad_maxima" required min="<?= $corral['ocupacion_actual'] ?>"
                       value="<?= htmlspecialchars($corral['capacidad_maxima']) ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <small style="color: var(--tenue);">Ocupacion actual: <?= $corral['ocupacion_actual'] ?> bovinos</small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Tipo *</label>
                <select name="tipo" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <?php
                    $tipos = ['Hembras', 'Machos', 'Castrados', 'Engorda', 'Cuarentena', 'Mixto'];
                    foreach ($tipos as $t):
                    ?>
                        <option value="<?= $t ?>" <?= $corral['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Sexo permitido</label>
                <select name="sexo_permitido" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="Mixto" <?= $corral['sexo_permitido'] === 'Mixto' ? 'selected' : '' ?>>Mixto</option>
                    <option value="M" <?= $corral['sexo_permitido'] === 'M' ? 'selected' : '' ?>>Macho</option>
                    <option value="H" <?= $corral['sexo_permitido'] === 'H' ? 'selected' : '' ?>>Hembra</option>
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Peso minimo (kg)</label>
                <input type="number" name="peso_min" step="0.01" min="0"
                       value="<?= htmlspecialchars($corral['peso_min'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Peso maximo (kg)</label>
                <input type="number" name="peso_max" step="0.01" min="0"
                       value="<?= htmlspecialchars($corral['peso_max'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Estado</label>
            <select name="estado" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="Disponible" <?= $corral['estado'] === 'Disponible' ? 'selected' : '' ?>>Disponible</option>
                <option value="Ocupado" <?= $corral['estado'] === 'Ocupado' ? 'selected' : '' ?>>Ocupado</option>
                <option value="Mantenimiento" <?= $corral['estado'] === 'Mantenimiento' ? 'selected' : '' ?>>Mantenimiento</option>
            </select>
        </div>

        <div style="background: var(--suave); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer;">
                <input type="checkbox" name="es_hospital" value="1"
                       <?= $corral['es_hospital'] ? 'checked' : '' ?>
                       style="margin-right: 10px; transform: scale(1.3);">
                <span><strong>Corral de Hospital</strong></span>
            </label>

            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" name="es_venta" value="1"
                       <?= $corral['es_venta'] ? 'checked' : '' ?>
                       style="margin-right: 10px; transform: scale(1.3);">
                <span><strong>Corral de Venta</strong></span>
            </label>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/corrales" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
