<?php
// ============================================
// MODULO VETERINARIO - Atender bovino
// Registrar diagnostico y tratamiento
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/categorizacion.php';
require_once __DIR__ . '/../../includes/tratamientos_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$bovino_id = (int)($_GET['id'] ?? 0);
// El veterinario puede medicar a cualquier bovino del rancho (este o no en hospital): buscar por arete
if (!$bovino_id && trim($_GET['arete'] ?? '') !== '') {
    $q = Database::getInstance()->getConnection()->prepare("SELECT id FROM bovinos WHERE id_siniiga = ? AND estado NOT IN ('vendido','muerto','devuelto')");
    $q->execute([trim($_GET['arete'])]);
    $bovino_id = (int)$q->fetchColumn();
    if (!$bovino_id) {
        header('Location: ' . BASE_URL . '/sanidad?error=' . urlencode('No hay un bovino activo con el arete ' . trim($_GET['arete'])));
        exit;
    }
}
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
        Permisos::protegerPagina('sanidad', 'crear');
        exigirCSRF();

        // Descuenta inventario, calcula costo y dias de retiro (ver includes/tratamientos_lib.php)
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
           COALESCE((SELECT pz.peso FROM pesajes pz WHERE pz.bovino_id = b.id ORDER BY pz.fecha DESC, pz.id DESC LIMIT 1), b.peso_entrada) AS peso_actual,
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
    SELECT m.id, m.nombre, m.tipo, m.dias_retiro, m.via_administracion, m.dosis_ml, m.dosis_cada_kg,
           COALESCE(i.stock_actual, 0) AS stock, COALESCE(i.unidad, 'ml') AS unidad
    FROM medicamentos m LEFT JOIN inventario_medicamentos i ON i.medicamento_id = m.id
    WHERE m.activo = 1 ORDER BY m.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$cat = categorizarBovino($bovino['sexo'], $bovino['peso_entrada']);
?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Atender Bovino: <?= htmlspecialchars($bovino['id_siniiga']) ?></h2>
            <p style="color: var(--tenue); font-size: 0.9rem; margin-top: 5px;">
                <?= $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra' ?> |
                <strong><?= number_format($bovino['peso_actual'], 1) ?> kg</strong> (peso actual) |
                <?= htmlspecialchars($cat['subcategoria']) ?> |
                <?= htmlspecialchars($bovino['raza'] ?? 'Sin raza') ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/sanidad" class="btn btn-secondary">Volver</a>
    </div>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <div style="padding: 12px; background: var(--f-rojo); border-radius: 6px; border-left: 4px solid #dc3545;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;"><?= $bovino['en_hospital'] ? 'Motivo en hospital' : 'Ubicacion' ?></div>
            <div style="font-size: 1rem; margin-top: 4px; color: var(--t-rojo); font-weight: 600;">
                <?= $bovino['en_hospital'] ? htmlspecialchars($bovino['motivo_hospital'] ?? 'Sin especificar') : 'En su corral (no esta en hospital)' ?>
            </div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Corral actual</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= htmlspecialchars($bovino['corral_nombre'] ?? 'N/A') ?>
            </div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Recepcion</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= htmlspecialchars($bovino['folio_recepcion'] ?? 'N/A') ?>
            </div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Fecha ingreso</div>
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
        <?= csrfField() ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <div style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Diagnostico *</label>
                <textarea name="diagnostico" rows="3" required
                          placeholder="Describe el diagnostico del bovino..."
                          style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;"><?= htmlspecialchars($_POST['diagnostico'] ?? '') ?></textarea>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Medicamento (del inventario) *</label>
                <select name="medicamento_id" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($medicamentos as $m): ?>
                        <option value="<?= $m['id'] ?>" data-ml="<?= h($m['dosis_ml']) ?>" data-cadakg="<?= h($m['dosis_cada_kg']) ?>" <?= (int)($_POST['medicamento_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nombre']) ?> (<?= number_format($m['stock'], 1) . ' ' . htmlspecialchars($m['unidad']) ?>, retiro <?= (int)$m['dias_retiro'] ?> d)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--tenue);">Si falta alguno: <a href="<?= BASE_URL ?>/tratamientos/inventario">inventario</a> o <a href="<?= BASE_URL ?>/solicitudes/crear">solicitar compra</a></small>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Cantidad por dosis (en la unidad del inventario) *</label>
                <input type="number" name="dosis" step="0.01" min="0.01" required placeholder="Ej: 5"
                       value="<?= htmlspecialchars($_POST['dosis'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <input type="hidden" id="pesoBovino" value="<?= (float)$bovino['peso_actual'] ?>">
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
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Via de administracion</label>
                <select name="via_administracion"
                        style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">-- Seleccionar --</option>
                    <option value="Intramuscular" <?= ($_POST['via_administracion'] ?? '') === 'Intramuscular' ? 'selected' : '' ?>>Intramuscular</option>
                    <option value="Subcutanea" <?= ($_POST['via_administracion'] ?? '') === 'Subcutanea' ? 'selected' : '' ?>>Subcutanea</option>
                    <option value="Intravenosa" <?= ($_POST['via_administracion'] ?? '') === 'Intravenosa' ? 'selected' : '' ?>>Intravenosa</option>
                    <option value="Oral" <?= ($_POST['via_administracion'] ?? '') === 'Oral' ? 'selected' : '' ?>>Oral</option>
                    <option value="Topica" <?= ($_POST['via_administracion'] ?? '') === 'Topica' ? 'selected' : '' ?>>Topica</option>
                </select>
            </div>
            
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Dias de retiro (vacio = el del medicamento)</label>
                <input type="number" name="dias_retiro" min="0" placeholder="Ej: 14"
                       value="<?= htmlspecialchars($_POST['dias_retiro'] ?? '') ?>"
                       style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <small style="color: var(--tenue); font-size: 0.75rem;">Dias de retiro para venta</small>
            </div>
            
            <div style="grid-column: span 2;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Observaciones</label>
                <textarea name="observaciones" rows="2" placeholder="Notas adicionales..."
                          style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;"><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
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

<script src="<?= BASE_URL ?>/assets/js/dosis-peso.js?v=<?= @filemtime(BASE_PATH . '/assets/js/dosis-peso.js') ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
