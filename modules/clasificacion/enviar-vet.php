<?php
// ============================================
// CLASIFICACION - Enviar bovino a veterinaria
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// El clasificador (o el admin) manda a veterinaria a un bovino que se enfermo o se lastimo
Permisos::protegerPagina('clasificacion', 'crear');

$bovino_id = (int)($_GET['id'] ?? 0);
if (!$bovino_id) {
    header('Location: ' . BASE_URL . '/clasificacion');
    exit;
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM bovinos WHERE id = ?");
$stmt->execute([$bovino_id]);
$bovino = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bovino) {
    header('Location: ' . BASE_URL . '/clasificacion');
    exit;
}

if ($bovino['en_hospital'] || in_array($bovino['estado'], ['vendido', 'muerto', 'devuelto', 'en_cuarentena'], true)) {
    header('Location: ' . BASE_URL . '/clasificacion?msg=' . urlencode("{$bovino['id_siniiga']} ya esta en veterinaria o ya no esta en el rancho"));
    exit;
}

// Nadie entra a veterinaria sin clasificar: primero el clasificador decide su categoria
if (($bovino['categoria'] ?? 'Sin clasificar') === 'Sin clasificar') {
    header('Location: ' . BASE_URL . '/clasificacion/asignar?id=' . $bovino_id . '&aviso='
        . urlencode('Este bovino aun no esta clasificado. Elige su categoria y como destino "Veterinaria".'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        $motivo = trim($_POST['motivo'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        if (empty($motivo)) {
            throw new Exception('Debe indicar el motivo');
        }
        
        // Buscar hospital disponible
        $stmt = $db->query("
            SELECT * FROM corrales
            WHERE es_hospital = 1
              AND estado != 'Mantenimiento'
              AND ocupacion_actual < capacidad_maxima
            ORDER BY ocupacion_actual ASC
            LIMIT 1
        ");
        $hospital = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$hospital) {
            throw new Exception('No hay hospital con espacio disponible');
        }
        
        $db->beginTransaction();
        
        $corralAnterior = $bovino['corral_id'];
        
        // Actualizar bovino
        $db->prepare("
            UPDATE bovinos 
            SET corral_original_id = ?,
                corral_id = ?,
                en_hospital = 1,
                motivo_hospital = ?,
                fecha_entrada_hospital = NOW(),
                estado = 'en_cuarentena'
            WHERE id = ?
        ")->execute([
            $corralAnterior,
            $hospital['id'],
            $motivo . ($observaciones ? ': ' . $observaciones : ''),
            $bovino_id
        ]);
        
        // Actualizar ocupaciones
        $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")
           ->execute([$hospital['id']]);
        
        if ($corralAnterior) {
            $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")
               ->execute([$corralAnterior]);
        }
        
        // Registrar movimiento
        $db->prepare("
            INSERT INTO movimientos_bovino 
            (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
            VALUES (?, ?, ?, 'hospital', ?, ?, NOW())
        ")->execute([
            $bovino_id, $corralAnterior, $hospital['id'], 
            $motivo, $_SESSION['user_id']
        ]);
        
        // Crear alerta para el veterinario
        $db->prepare("
            INSERT INTO alertas (tipo, mensaje, bovino_id, rol_destino)
            VALUES ('animal_enfermo', ?, ?, 'veterinario')
        ")->execute([
            "Bovino " . $bovino['id_siniiga'] . " enviado a hospital: " . $motivo,
            $bovino_id
        ]);
        
        $db->commit();
        
        registrarLog($_SESSION['user_id'], 'enviar_vet', "{$bovino['id_siniiga']}: $motivo");
        header('Location: ' . BASE_URL . '/clasificacion?msg=' . urlencode("{$bovino['id_siniiga']} enviado a {$hospital['nombre']}. Se aviso al veterinario."));
        exit;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$titulo = 'Enviar a Veterinaria';
$modulo = 'clasificacion';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 700px; margin: 0 auto;">
    <div class="table-header">
        <h2>Enviar a Veterinaria: <?= htmlspecialchars($bovino['id_siniiga']) ?></h2>
        <a href="<?= BASE_URL ?>/clasificacion" class="btn btn-secondary">Volver</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div style="padding: 20px; background: var(--f-rojo); border-left: 4px solid #dc3545;">
        <p style="margin: 0; color: var(--t-rojo);">
            <strong>Atencion:</strong> Este bovino sera enviado al hospital. El veterinario lo atendera y decidira cuando darle de alta.
        </p>
    </div>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; background: var(--suave);">
        <div>
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Sexo</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></div>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Peso</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= $bovino['peso_entrada'] ? number_format($bovino['peso_entrada'], 1) . ' kg' : '-' ?></div>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Categoria</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($bovino['categoria'] ?? '-') ?></div>
        </div>
    </div>
    
    <form method="POST" style="padding: 30px;">
        <?= csrfField() ?>
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Motivo *</label>
            <select name="motivo" required
                    style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">-- Seleccionar motivo --</option>
                <option value="Golpe o lastimado">Golpe o lastimado</option>
                <option value="Herida visible">Herida visible</option>
                <option value="Cojera">Cojera</option>
                <option value="Debilidad">Debilidad</option>
                <option value="Diarrea">Diarrea</option>
                <option value="Problemas respiratorios">Problemas respiratorios</option>
                <option value="Fiebre">Fiebre</option>
                <option value="Desnutricion">Desnutricion</option>
                <option value="Comportamiento anomalo">Comportamiento anomalo</option>
                <option value="Otro">Otro</option>
            </select>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Observaciones</label>
            <textarea name="observaciones" rows="3"
                      placeholder="Describe lo que observaste en el bovino..."
                      style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;"></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/clasificacion" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn" style="background: #dc3545; color: white; padding: 12px 30px;">
                Enviar a Veterinaria
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
