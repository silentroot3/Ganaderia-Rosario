<?php
// ============================================
// CORRALES - Crear nuevo corral
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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();

        $nombre = trim($_POST['nombre'] ?? '');
        $capacidad = (int)($_POST['capacidad_maxima'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $sexo = $_POST['sexo_permitido'] ?? 'Mixto';
        $peso_min = $_POST['peso_min'] !== '' ? (float)$_POST['peso_min'] : null;
        $peso_max = $_POST['peso_max'] !== '' ? (float)$_POST['peso_max'] : null;
        $es_hospital = isset($_POST['es_hospital']) ? 1 : 0;
        $es_venta = isset($_POST['es_venta']) ? 1 : 0;

        if (empty($nombre)) {
            throw new Exception('El nombre del corral es obligatorio');
        }

        if ($capacidad <= 0) {
            throw new Exception('La capacidad debe ser mayor a 0');
        }

        $stmt = $db->prepare("SELECT id FROM corrales WHERE nombre = ?");
        $stmt->execute([$nombre]);
        if ($stmt->fetch()) {
            throw new Exception('Ya existe un corral con ese nombre');
        }

        $stmt = $db->prepare("
            INSERT INTO corrales (
                nombre, capacidad_maxima, tipo, sexo_permitido,
                peso_min, peso_max, es_hospital, es_venta, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Disponible')
        ");

        $stmt->execute([
            $nombre, $capacidad, $tipo, $sexo,
            $peso_min, $peso_max, $es_hospital, $es_venta
        ]);

        header('Location: ' . BASE_URL . '/corrales?msg=creado');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Nuevo Corral';
$modulo = 'corrales';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 800px; margin: 0 auto;">
    <div class="table-header">
        <h2>Nuevo Corral</h2>
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
                       placeholder="Ej: M-1, H-2, HOSPITAL-5"
                       value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Capacidad maxima *</label>
                <input type="number" name="capacidad_maxima" required min="1"
                       placeholder="Ej: 50"
                       value="<?= htmlspecialchars($_POST['capacidad_maxima'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Tipo *</label>
                <select name="tipo" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <option value="Hembras" <?= ($_POST['tipo'] ?? '') === 'Hembras' ? 'selected' : '' ?>>Hembras</option>
                    <option value="Machos" <?= ($_POST['tipo'] ?? '') === 'Machos' ? 'selected' : '' ?>>Machos</option>
                    <option value="Castrados" <?= ($_POST['tipo'] ?? '') === 'Castrados' ? 'selected' : '' ?>>Castrados</option>
                    <option value="Engorda" <?= ($_POST['tipo'] ?? '') === 'Engorda' ? 'selected' : '' ?>>Engorda</option>
                    <option value="Cuarentena" <?= ($_POST['tipo'] ?? '') === 'Cuarentena' ? 'selected' : '' ?>>Cuarentena</option>
                    <option value="Mixto" <?= ($_POST['tipo'] ?? '') === 'Mixto' ? 'selected' : '' ?>>Mixto</option>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Sexo permitido</label>
                <select name="sexo_permitido" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="Mixto" <?= ($_POST['sexo_permitido'] ?? '') === 'Mixto' ? 'selected' : '' ?>>Mixto</option>
                    <option value="M" <?= ($_POST['sexo_permitido'] ?? '') === 'M' ? 'selected' : '' ?>>Macho</option>
                    <option value="H" <?= ($_POST['sexo_permitido'] ?? '') === 'H' ? 'selected' : '' ?>>Hembra</option>
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Peso minimo (kg)</label>
                <input type="number" name="peso_min" step="0.01" min="0"
                       placeholder="Ej: 120"
                       value="<?= htmlspecialchars($_POST['peso_min'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Peso maximo (kg)</label>
                <input type="number" name="peso_max" step="0.01" min="0"
                       placeholder="Ej: 200"
                       value="<?= htmlspecialchars($_POST['peso_max'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>

        <div style="background: var(--suave); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer;">
                <input type="checkbox" name="es_hospital" value="1"
                       <?= isset($_POST['es_hospital']) ? 'checked' : '' ?>
                       style="margin-right: 10px; transform: scale(1.3);">
                <span><strong>Corral de Hospital</strong> (cuarentena)</span>
            </label>

            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" name="es_venta" value="1"
                       <?= isset($_POST['es_venta']) ? 'checked' : '' ?>
                       style="margin-right: 10px; transform: scale(1.3);">
                <span><strong>Corral de Venta</strong> (para mostrar a clientes)</span>
            </label>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/corrales" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Crear Corral</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
