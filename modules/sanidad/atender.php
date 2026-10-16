<?php
// ============================================
// MODULO VETERINARIO - Atender bovino
// Registrar diagnostico y tratamiento
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/categorizacion.php';

$bovino_id = (int)($_GET['id'] ?? 0);
if (!$bovino_id) {
    header('Location: ' . BASE_URL . '/sanidad');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        
        $diagnostico = trim($_POST['diagnostico'] ?? '');
        $medicamento = trim($_POST['medicamento'] ?? '');
        $dosis = trim($_POST['dosis'] ?? '');
        $viaAdministracion = trim($_POST['via_administracion'] ?? '');
        $diasTratamiento = (int)($_POST['dias_tratamiento'] ?? 0);
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        if (empty($diagnostico)) {
            throw new Exception('El diagnostico es obligatorio');
        }
        if (empty($medicamento)) {
            throw new Exception('El medicamento es obligatorio');
        }
        
        // Buscar o crear medicamento en inventario
        $stmt = $db->prepare("SELECT id FROM medicamentos WHERE nombre = ?");
        $stmt->execute([$medicamento]);
        $med = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$med) {
            // Crear medicamento si no existe
            $stmt = $db->prepare("
                INSERT INTO medicamentos (nombre, tipo, activo, dias_retiro)
                VALUES (?, 'otro', 1, 0)
            ");
            $stmt->execute([$medicamento]);
            $medicamento_id = $db->lastInsertId();
        } else {
            $medicamento_id = $med['id'];
        }
        
        // Calcular fecha fin de retiro
        $fechaFinRetiro = null;
        if ($diasTratamiento > 0) {
            $fecha = new DateTime();
            $fecha->modify("+{$diasTratamiento} days");
            $fechaFinRetiro = $fecha->format('Y-m-d');
        }
        
        // Insertar tratamiento
        $stmt = $db->prepare("
            INSERT INTO tratamientos 
            (bovino_id, medicamento_id, dosis, via_administracion, diagnostico, 
             dias_retiro, fecha_aplicacion, fecha_fin_retiro, veterinario_id, observaciones)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $bovino_id, $medicamento_id, $dosis, $viaAdministracion, 
            $diagnostico, $diasTratamiento, $fechaFinRetiro, 
            $_SESSION['user_id'], $observaciones
        ]);
        
        $exito = "Tratamiento registrado correctamente";
        
        // Redirigir despues de 2 segundos
        header('refresh:2;url=' . BASE_URL . '/sanidad/historial?id=' . $bovino_id);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Atender Bovino';
$modulo = 'sanidad';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Datos del bovino
$stmt = $db->prepare("
    SELECT b.*, 
           c.nombre AS corral_nombre,
           r.folio AS folio_recepcion,
           p.nombre AS proveedor_nombre
    FROM bovinos b
    LEFT JOIN corrales c ON b.corral_id = c.id
    LEFT JOIN recepciones r ON b.recepcion_id = r.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    WHERE b.id = ?
");
$stmt->execute([$bovino_id]);
$bovino = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bovino) {
    header('Location: ' . BASE_URL . '/sanidad');
    exit;
}

// Historial de tratamientos previos
$stmt = $db->prepare("
    SELECT t.*, m.nombre AS medicamento_nombre, u.nombre AS vet_nombre, u.apellido AS vet_apellido
    FROM tratamientos t
    LEFT JOIN medicamentos m ON t.medicamento_id = m.id
    LEFT JOIN usuarios u ON t.veterinario_id = u.id
    WHERE t.bovino_id = ?
    ORDER BY t.fecha_aplicacion DESC
");
$stmt->execute([$bovino_id]);
$tratamientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Medicamentos disponibles
$medicamentos = $db->query("
    SELECT id, nombre, tipo FROM medicamentos WHERE activo = 1 ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

$cat = categorizarBovino($bovino['sexo'], $bovino['peso_entrada']);
?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Atender Bovino: <?= htmlspecialchars($bovino['id_siniiga']) ?></h2>
            <p style="color: #666; font-size: 0.9rem; margin-top: 5px;">
                <?= $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra' ?> |
                <?= number_format($bovino['peso_entrada'], 1) ?> kg |
                <?= htmlspecialchars($cat['subcategoria']) ?> |
                <?= htmlspecialchars($bovino['raza'] ?? 'Sin raza') ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/sanidad" class="btn btn-secondary">Volver</a>
    </div>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <div style="padding: 12px; background: #f8d7da; border-radius: 6px; border-left: 4px solid #dc3545;">
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Motivo en hospital</div>
            <div style="font-size: 1rem; margin-top: 4px; color: #721c24; font-weight: 600;">
                <?= htmlspecialchars($bovino['motivo_hospital'] ?? 'Sin especificar') ?>
            </div>
        </div>
        <div style="padding: 12px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Corral actual</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= htmlspecialchars($bovino['corral_nombre'] ?? 'N/A') ?>
            </div>
        </div>
        <div style="padding: 12px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Recepcion</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= htmlspecialchars($bovino['folio_recepcion'] ?? 'N/A') ?>
            </div>
        </div>
        <div style="padding: 12px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Fecha ingreso</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= $bovino['fecha_entrada_hospital'] ? date('d/m/Y H:i', strtotime($bovino['fecha_entrada_hospital'])) : 'N/A' ?>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">ERROR: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($exito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($exito) ?></div>
<?php endif; ?>

<!-- Formulario de tratamiento -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Registrar Tratamiento</h2>
    </div>
    
    <form method="POST" style="padding: 25px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <div style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Diagnostico *</label>
                <textarea name="diagnostico" rows="3" required
                          placeholder="Describe el diagnostico del bovino..."
                          style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;"><?= htmlspecialchars($_POST['diagnostico'] ?? '') ?></textarea>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Medicamento *</label>
                <input type="text" name="medicamento" required list="medicamentos-list"
                       placeholder="Ej: Ivermectina"
                       value="<?= htmlspecialchars($_POST['medicamento'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                <datalist id="medicamentos-list">
                    <?php foreach ($medicamentos as $m): ?>
                        <option value="<?= htmlspecialchars($m['nombre']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Dosis</label>
                <input type="text" name="dosis" placeholder="Ej: 5 ml o 1 ml/50kg"
                       value="<?= htmlspecialchars($_POST['dosis'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Via de administracion</label>
                <select name="via_administracion"
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <option value="Intramuscular" <?= ($_POST['via_administracion'] ?? '') === 'Intramuscular' ? 'selected' : '' ?>>Intramuscular</option>
                    <option value="Subcutanea" <?= ($_POST['via_administracion'] ?? '') === 'Subcutanea' ? 'selected' : '' ?>>Subcutanea</option>
                    <option value="Intravenosa" <?= ($_POST['via_administracion'] ?? '') === 'Intravenosa' ? 'selected' : '' ?>>Intravenosa</option>
                    <option value="Oral" <?= ($_POST['via_administracion'] ?? '') === 'Oral' ? 'selected' : '' ?>>Oral</option>
                    <option value="Topica" <?= ($_POST['via_administracion'] ?? '') === 'Topica' ? 'selected' : '' ?>>Topica</option>
                </select>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Dias de tratamiento</label>
                <input type="number" name="dias_tratamiento" min="0" placeholder="Ej: 3"
                       value="<?= htmlspecialchars($_POST['dias_tratamiento'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                <small style="color: #666; font-size: 0.75rem;">Dias de retiro para venta</small>
            </div>
            
            <div style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Observaciones</label>
                <textarea name="observaciones" rows="2" placeholder="Notas adicionales..."
                          style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;"><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
        
        <div style="margin-top: 25px; display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/sanidad" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Registrar Tratamiento</button>
        </div>
    </form>
</div>

<!-- Historial de tratamientos -->
<?php if (count($tratamientos) > 0): ?>
<div class="table-container">
    <div class="table-header">
        <h2>Historial de Tratamientos (<?= count($tratamientos) ?>)</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Diagnostico</th>
                <th>Medicamento</th>
                <th>Dosis</th>
                <th>Via</th>
                <th>Dias retiro</th>
                <th>Veterinario</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tratamientos as $t): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($t['fecha_aplicacion'])) ?></td>
                <td><strong><?= htmlspecialchars($t['diagnostico'] ?? '-') ?></strong></td>
                <td><?= htmlspecialchars($t['medicamento_nombre'] ?? '-') ?></td>
                <td><?= htmlspecialchars($t['dosis'] ?? '-') ?></td>
                <td><?= htmlspecialchars($t['via_administracion'] ?? '-') ?></td>
                <td><?= $t['dias_retiro'] > 0 ? $t['dias_retiro'] . ' dias' : '-' ?></td>
                <td><?= htmlspecialchars(($t['vet_nombre'] ?? '') . ' ' . ($t['vet_apellido'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
