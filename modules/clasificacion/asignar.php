<?php
// ============================================
// CLASIFICACION - Clasificar / editar datos / mover un bovino
// El clasificador decide categoria, edad y peso actual (el peso queda como pesaje)
// y el destino: un corral o veterinaria. A veterinaria solo se envia ya clasificado.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/asignacion_corrales.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('clasificacion', 'crear');

const MOTIVOS_VET = ['Herida visible', 'Cojera', 'Debilidad', 'Diarrea', 'Problemas respiratorios', 'Fiebre', 'Desnutricion', 'Comportamiento anomalo', 'Otro'];

$bovino_id = (int)($_GET['id'] ?? 0);
$volver = in_array($_GET['volver'] ?? '', ['corral'], true) ? 'corral' : 'clasificacion';
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("
    SELECT b.*, r.folio AS folio_recepcion, co.nombre AS corral_nombre, co.es_recepcion,
           " . sqlPesoActual('b') . " AS peso_actual
    FROM bovinos b
    LEFT JOIN recepciones r ON b.recepcion_id = r.id
    LEFT JOIN corrales co ON co.id = b.corral_id
    WHERE b.id = ? AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
");
$stmt->execute([$bovino_id]);
$bovino = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bovino) {
    header('Location: ' . BASE_URL . '/clasificacion');
    exit;
}

$urlVolver = $volver === 'corral' && $bovino['corral_id']
    ? BASE_URL . '/corrales/ver?id=' . $bovino['corral_id']
    : BASE_URL . '/clasificacion';
$categorias = categoriasPorSexo($bovino['sexo']);
$enHospital = (bool)$bovino['en_hospital'];
// Llego enfermo y aun no pasa por el hospital: se sugiere enviarlo a veterinaria
$pendienteVet = $bovino['llego_enfermo'] && !$bovino['fecha_entrada_hospital'] && !$enHospital;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $categoria = $_POST['categoria'] ?? '';
        $edad = (int)($_POST['edad_meses'] ?? 0);
        $peso = (float)($_POST['peso_actual'] ?? 0);
        $destino = $_POST['destino'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');

        if (!isset($categorias[$categoria])) throw new Exception('Elige la categoria del bovino');
        if ($peso <= 0) throw new Exception('Captura el peso actual');

        $db->beginTransaction();

        $db->prepare("UPDATE bovinos SET categoria = ?, castrado = ?, edad_meses = ? WHERE id = ?")
           ->execute([$categoria, $categoria === 'Novillo' ? 1 : 0, $edad ?: $bovino['edad_meses'], $bovino_id]);

        if (abs($peso - (float)$bovino['peso_actual']) >= 0.1) {
            registrarPesaje($db, $bovino_id, $peso, $_SESSION['user_id'], 'Actualizado por clasificacion');
        }

        $mensaje = 'Datos del bovino actualizados.';
        $esClasificacion = !empty($bovino['es_recepcion']) || !$bovino['corral_id'];

        if ($enHospital) {
            // En hospital solo se editan datos; el veterinario decide la salida
        } elseif ($destino === 'vet') {
            $motivoVet = in_array($_POST['motivo_vet'] ?? '', MOTIVOS_VET, true) ? $_POST['motivo_vet'] : '';
            if ($motivoVet === '') throw new Exception('Elige el motivo para veterinaria');
            $hospital = $db->query("
                SELECT * FROM corrales WHERE es_hospital = 1 AND estado != 'Mantenimiento'
                  AND ocupacion_actual < capacidad_maxima ORDER BY ocupacion_actual, id LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            if (!$hospital) throw new Exception('No hay hospital con espacio disponible');

            moverBovinoACorral($db, $bovino_id, $hospital['id'], $_SESSION['user_id'], 'hospital',
                "Clasificado como $categoria y enviado a veterinaria: $motivoVet" . ($motivo ? " ($motivo)" : ''), false);
            // corral_original_id en NULL: al dar de alta el veterinario elige corral segun su categoria y peso
            $db->prepare("
                UPDATE bovinos SET en_hospital = 1, motivo_hospital = ?, fecha_entrada_hospital = NOW(),
                       estado = 'en_cuarentena', corral_original_id = NULL
                WHERE id = ?
            ")->execute([$motivoVet . ($motivo ? ": $motivo" : ''), $bovino_id]);
            $mensaje = "Bovino clasificado como $categoria y enviado a {$hospital['nombre']}.";
            $avisoVet = "{$bovino['id_siniiga']} ($categoria) enviado a veterinaria por el clasificador: $motivoVet";
        } else {
            $corral_id = (int)$destino;
            $stmt = $db->prepare("SELECT * FROM corrales WHERE id = ? AND es_hospital = 0 AND es_venta = 0 AND estado != 'Mantenimiento'");
            $stmt->execute([$corral_id]);
            $corral = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$corral) throw new Exception('Elige el corral destino');
            if ($corral_id != $bovino['corral_id'] && $corral['ocupacion_actual'] >= $corral['capacidad_maxima']) {
                throw new Exception("El corral {$corral['nombre']} esta lleno");
            }
            if ($corral['es_recepcion'] && $corral_id != $bovino['corral_id']) {
                throw new Exception('Los corrales RECEP son solo para los que acaban de llegar');
            }
            moverBovinoACorral($db, $bovino_id, $corral_id, $_SESSION['user_id'],
                $esClasificacion ? 'clasificacion' : 'traslado', $motivo ?: "Clasificado como $categoria");
            $db->prepare("UPDATE bovinos SET estado = IF(? = 1, 'recibido', 'en_corral') WHERE id = ?")
               ->execute([(int)$corral['es_recepcion'], $bovino_id]);
            $mensaje = $corral_id == $bovino['corral_id'] ? 'Datos del bovino actualizados.' : "Bovino movido a {$corral['nombre']}.";
        }

        $db->prepare("INSERT INTO clasificaciones (bovino_id, sexo, peso, edad_meses, usuario_id) VALUES (?, ?, ?, ?, ?)")
           ->execute([$bovino_id, $bovino['sexo'], $peso, $edad ?: $bovino['edad_meses'], $_SESSION['user_id']]);

        $db->commit();

        if (!empty($avisoVet)) crearAlerta('animal_enfermo', $avisoVet, 'veterinario', $bovino_id);

        $destinoUrl = $volver === 'corral' && !empty($corral_id) ? BASE_URL . '/corrales/ver?id=' . $corral_id : $urlVolver;
        header('Location: ' . $destinoUrl . (strpos($destinoUrl, '?') ? '&' : '?') . 'msg=' . urlencode($mensaje));
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// Corrales destino: todos los normales; se recomiendan los que coinciden en sexo y peso
$pesoRef = (float)$bovino['peso_actual'];
$stmt = $db->prepare("
    SELECT * FROM corrales
    WHERE estado != 'Mantenimiento' AND es_hospital = 0 AND es_venta = 0
      AND (es_recepcion = 0 OR id = ?)
      AND (ocupacion_actual < capacidad_maxima OR id = ?)
    ORDER BY (sexo_permitido = ? OR sexo_permitido = 'Mixto') DESC,
             CASE WHEN ? BETWEEN peso_min AND peso_max THEN 0 ELSE 1 END, nombre
");
$stmt->execute([(int)$bovino['corral_id'], (int)$bovino['corral_id'], $bovino['sexo'], $pesoRef]);
$corralesDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catSel = $_POST['categoria'] ?? (isset($categorias[$bovino['categoria']]) ? $bovino['categoria'] : '');
$destinoSel = $_POST['destino'] ?? ($pendienteVet ? 'vet' : ($bovino['es_recepcion'] ? '' : (string)$bovino['corral_id']));
$motivoVetSel = $_POST['motivo_vet'] ?? (in_array($bovino['motivo_llegada'], MOTIVOS_VET, true) ? $bovino['motivo_llegada'] : '');
$sinClasificar = ($bovino['categoria'] ?? 'Sin clasificar') === 'Sin clasificar';

$titulo = 'Clasificar bovino';
$modulo = 'clasificacion';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.cl-cats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
.cl-cat { flex:1; min-width:140px; padding:12px; border:2px solid var(--linea); border-radius:8px; cursor:pointer; background:var(--superficie); }
.cl-cat input { margin-right:6px; }
.cl-cat:has(input:checked) { border-color:#0b4f86; background:var(--f-verde); }
.cl-corral:has(input:checked) { outline:3px solid #0b4f86; }
.cl-datos { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:15px; margin-bottom:25px; }
.cl-datos label { display:block; font-size:0.8rem; font-weight:600; margin-bottom:4px; }
.cl-datos input { width:100%; padding:10px; border:1px solid var(--linea); border-radius:6px; font-size:1rem; }
</style>

<div class="table-container" style="max-width: 980px; margin: 0 auto;">
    <div class="table-header">
        <h2><?= $sinClasificar ? 'Clasificar' : 'Editar datos' ?>: <span style="font-family: monospace;"><?= htmlspecialchars($bovino['id_siniiga']) ?></span></h2>
        <a href="<?= $urlVolver ?>" class="btn btn-secondary">Volver</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;">ERROR: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['aviso'])): ?>
        <div class="alert alert-info" style="margin: 20px;"><?= htmlspecialchars($_GET['aviso']) ?></div>
    <?php endif; ?>
    <?php if ($pendienteVet): ?>
        <div class="alert alert-error" style="margin: 20px;">
            <strong>Llego enfermo/herido:</strong> <?= htmlspecialchars($bovino['motivo_llegada'] ?? '') ?>. Clasificalo y envialo a veterinaria.
        </div>
    <?php endif; ?>

    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; background: var(--suave);">
        <?php foreach ([
            'Sexo' => $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra',
            'Categoria actual' => $bovino['categoria'] ?? '-',
            'Peso de entrada' => $bovino['peso_entrada'] ? number_format($bovino['peso_entrada'], 1) . ' kg' : '-',
            'Peso actual' => $bovino['peso_actual'] ? number_format($bovino['peso_actual'], 1) . ' kg' : '-',
            'Corral actual' => ($bovino['corral_nombre'] ?? 'Sin corral') . ($enHospital ? ' (hospital)' : ''),
        ] as $k => $v): ?>
        <div>
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;"><?= $k ?></div>
            <div style="font-size: 1.05rem; margin-top: 4px; font-weight: 600; <?= $k === 'Categoria actual' && $sinClasificar ? 'color:#b26a00;' : '' ?>"><?= htmlspecialchars($v) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <form method="POST" style="padding: 30px;">

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">1. Categoria</h3>
        <div class="cl-cats">
            <?php foreach ($categorias as $cat => $guia): ?>
            <label class="cl-cat">
                <input type="radio" name="categoria" value="<?= $cat ?>" required <?= $catSel === $cat ? 'checked' : '' ?>>
                <strong><?= $cat ?></strong>
                <div style="font-size: 0.8rem; color: var(--tenue); margin-top: 4px;"><?= $guia ?></div>
            </label>
            <?php endforeach; ?>
        </div>

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">2. Datos actuales</h3>
        <div class="cl-datos">
            <div>
                <label>Edad (meses)</label>
                <input type="number" name="edad_meses" min="0" value="<?= htmlspecialchars($_POST['edad_meses'] ?? $bovino['edad_meses'] ?? '') ?>">
            </div>
            <div>
                <label>Peso actual (kg) *</label>
                <input type="number" name="peso_actual" min="1" step="0.1" required
                       value="<?= htmlspecialchars($_POST['peso_actual'] ?? ($bovino['peso_actual'] ? round($bovino['peso_actual'], 1) : '')) ?>">
                <small style="color: var(--tenue);">Si cambia, se guarda como nuevo pesaje (el de entrada se conserva).</small>
            </div>
        </div>

        <h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">3. Destino</h3>

        <?php if ($enHospital): ?>
            <div class="alert alert-info" style="margin-bottom: 20px;">Esta en el hospital: solo se actualizan sus datos. El veterinario decide a que corral sale.</div>
        <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <label class="cl-corral" style="padding: 14px; background: var(--f-rojo); border-radius: 8px; border: 2px solid #dc3545; cursor: pointer; display: block;">
                <input type="radio" name="destino" value="vet" required <?= $destinoSel === 'vet' ? 'checked' : '' ?> style="margin-right: 8px;">
                <strong style="font-size: 1.05rem; color: #dc3545;">Veterinaria (hospital)</strong>
                <select name="motivo_vet" style="width: 100%; margin-top: 8px; padding: 6px; border: 1px solid #dc3545; border-radius: 4px;">
                    <option value="">-- Motivo --</option>
                    <?php foreach (MOTIVOS_VET as $m): ?>
                        <option value="<?= $m ?>" <?= $motivoVetSel === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach ($corralesDisponibles as $c):
                $disponibles = $c['capacidad_maxima'] - $c['ocupacion_actual'];
                $porcentaje = ($c['ocupacion_actual'] / $c['capacidad_maxima']) * 100;
                $coincidePeso = ($c['peso_min'] === null || $c['peso_max'] === null)
                                || ($pesoRef >= $c['peso_min'] && $pesoRef <= $c['peso_max']);
                $coincideSexo = ($c['sexo_permitido'] === 'Mixto' || $c['sexo_permitido'] === $bovino['sexo']);
                $esAdecuado = $coincidePeso && $coincideSexo && !$c['es_recepcion'];
                $esActual = $c['id'] == $bovino['corral_id'];
            ?>
            <label class="cl-corral" style="padding: 14px; background: <?= $esAdecuado ? 'var(--f-verde)' : 'var(--suave)' ?>; border-radius: 8px; border: 2px solid <?= $esAdecuado ? '#28a745' : 'var(--linea)' ?>; cursor: pointer; display: block;">
                <input type="radio" name="destino" value="<?= $c['id'] ?>" required <?= $destinoSel === (string)$c['id'] ? 'checked' : '' ?> style="margin-right: 8px;">
                <strong style="font-size: 1.05rem;"><?= htmlspecialchars($c['nombre']) ?></strong>
                <?php if ($esActual): ?><small style="color: var(--tenue);">(actual, sin mover)</small><?php endif; ?>
                <div style="font-size: 0.82rem; color: var(--tenue); margin-top: 6px;">
                    <?= htmlspecialchars($c['tipo']) ?>
                    <?php if ($c['peso_min'] && $c['peso_max']): ?>
                        | <?= number_format($c['peso_min'], 0) ?>-<?= number_format($c['peso_max'], 0) ?> kg
                    <?php endif; ?>
                </div>
                <div style="margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--tenue);">
                        <span><?= $c['ocupacion_actual'] ?> / <?= $c['capacidad_maxima'] ?></span>
                        <span><?= $disponibles ?> libres</span>
                    </div>
                    <div style="background: var(--suave); height: 4px; border-radius: 2px; margin-top: 3px; overflow: hidden;">
                        <div style="width: <?= $porcentaje ?>%; height: 100%; background: <?= $porcentaje >= 90 ? '#dc3545' : '#28a745' ?>;"></div>
                    </div>
                </div>
                <?php if ($esAdecuado): ?>
                    <div style="font-size: 0.75rem; color: #28a745; font-weight: 600; margin-top: 6px;">Coincide en sexo y peso</div>
                <?php elseif (!$coincideSexo): ?>
                    <div style="font-size: 0.75rem; color: #b26a00; font-weight: 600; margin-top: 6px;">Otro sexo</div>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Nota (opcional)</label>
            <input type="text" name="motivo" value="<?= htmlspecialchars($_POST['motivo'] ?? '') ?>"
                   placeholder="Ej: separado por talla"
                   style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= $urlVolver ?>" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" style="padding: 12px 30px;">Guardar</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
