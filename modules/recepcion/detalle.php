<?php
// ============================================
// DETALLE DE RECEPCION
// ============================================

$titulo = 'Detalle de Recepcion';
$modulo = 'recepcion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/categorizacion.php';

$db = Database::getInstance()->getConnection();

$recepcion_id = (int)($_GET['id'] ?? 0);
if (!$recepcion_id) {
    header('Location: ' . BASE_URL . '/recepcion');
    exit;
}

$stmt = $db->prepare("
    SELECT r.*, p.nombre AS proveedor_nombre, 
           u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
    FROM recepciones r
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    LEFT JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.id = ?
");
$stmt->execute([$recepcion_id]);
$recepcion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recepcion) {
    header('Location: ' . BASE_URL . '/recepcion');
    exit;
}

$stmt = $db->prepare("
    SELECT b.*, c.nombre AS corral_nombre, c.tipo AS corral_tipo
    FROM bovinos b
    LEFT JOIN corrales c ON b.corral_id = c.id
    WHERE b.recepcion_id = ? 
    ORDER BY 
        CASE b.estado 
            WHEN 'muerto' THEN 1
            WHEN 'devuelto' THEN 2
            ELSE 0
        END,
        b.id
");
$stmt->execute([$recepcion_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales
$pesoTotal = 0;
$valorTotal = 0;
$machos = 0;
$hembras = 0;
$enHospital = 0;
$muertos = 0;
$devueltos = 0;
$vivos = 0;

foreach ($bovinos as $b) {
    if ($b['estado'] === 'muerto') {
        $muertos++;
        continue;
    }
    if ($b['estado'] === 'devuelto') {
        $devueltos++;
        continue;
    }
    $vivos++;
    $pesoTotal += $b['peso_entrada'];
    $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
    $valorTotal += $cat['precio_total'];
    if ($b['sexo'] === 'M') $machos++; else $hembras++;
    if (!empty($b['llego_enfermo'])) $enHospital++;
}

// Bajas capturadas al recibir la compra (muertos / no coinciden): no generan bovino
$stmt = $db->prepare("SELECT * FROM bajas_recepcion WHERE recepcion_id = ? ORDER BY tipo, id");
$stmt->execute([$recepcion_id]);
$bajas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$noCoinciden = 0;
foreach ($bajas as $bj) {
    if ($bj['tipo'] === 'muerte') $muertos += $bj['cantidad']; else $noCoinciden += $bj['cantidad'];
}
$sanos = $vivos - $enHospital;

$archivoGenerado = $_GET['archivo'] ?? null;
?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Recepcion: <?= htmlspecialchars($recepcion['folio']) ?></h2>
            <p style="color: var(--tenue); font-size: 0.9rem; margin-top: 5px;">
                Registrada el <?= date('d/m/Y H:i', strtotime($recepcion['fecha_recepcion'])) ?>
                por <?= htmlspecialchars(($recepcion['usuario_nombre'] ?? '') . ' ' . ($recepcion['usuario_apellido'] ?? '')) ?>
            </p>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/recepcion" class="btn btn-secondary">Volver</a>
            <?php if (Permisos::puedeCrear('recepcion') && $recepcion['estado'] !== 'completado'): ?>
                <a href="<?= BASE_URL ?>/recepcion/bovinos?id=<?= $recepcion_id ?>" class="btn btn-primary">
                    Registrar Bovinos
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($archivoGenerado): ?>
        <div class="alert alert-success" style="margin: 20px;">
            Archivo generado: <strong><?= htmlspecialchars($archivoGenerado) ?></strong>
            <a href="<?= BASE_URL ?>/uploads/archivos/<?= htmlspecialchars($archivoGenerado) ?>" 
               download style="color: var(--t-verde); text-decoration: underline; margin-left: 10px;">
                Descargar
            </a>
        </div>
    <?php endif; ?>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Proveedor</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($recepcion['proveedor_nombre'] ?? 'N/A') ?></div>
        </div>
        <?php if (!empty($recepcion['transporte'])): ?>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Transporte</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($recepcion['transporte']) ?></div>
        </div>
        <?php endif; ?>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Placas</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($recepcion['placas'] ?? 'N/A') ?></div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Chofer</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($recepcion['chofer'] ?? 'N/A') ?></div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Estado</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <span class="badge-rol badge-<?= $recepcion['estado'] === 'completado' ? 'admin' : 'clasificador' ?>">
                    <?= htmlspecialchars($recepcion['estado']) ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Resumen -->
<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card blue">
        <div class="stat-label">Cantidad esperada</div>
        <div class="stat-value"><?= $recepcion['cantidad_animales'] ?></div>
        <div class="stat-desc">Animales del lote</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Sanos</div>
        <div class="stat-value"><?= $sanos ?></div>
        <div class="stat-desc"><?= $vivos ?> vivos: <?= $machos ?> machos / <?= $hembras ?> hembras</div>
    </div>
    <div class="stat-card yellow">
        <div class="stat-label">Enfermos / heridos</div>
        <div class="stat-value"><?= $enHospital ?></div>
        <div class="stat-desc">Marcados al llegar</div>
    </div>
    <div class="stat-card" style="border-left-color: #dc3545;">
        <div class="stat-label">Muertos</div>
        <div class="stat-value"><?= $muertos ?></div>
        <div class="stat-desc">Bajas por muerte</div>
    </div>
    <div class="stat-card" style="border-left-color: var(--tenue);">
        <div class="stat-label">No coinciden</div>
        <div class="stat-value"><?= $noCoinciden + $devueltos ?></div>
        <div class="stat-desc">Arete, sexo o no llegaron</div>
    </div>
    <div class="stat-card yellow">
        <div class="stat-label">Peso total (vivos)</div>
        <div class="stat-value"><?= number_format($pesoTotal, 0) ?> kg</div>
    </div>


	<?php if (puedeVerPrecios()): ?>
	<div class="stat-card purple">
	    <div class="stat-label">Valor estimado</div>
	    <div class="stat-value">$<?= number_format($valorTotal, 0) ?></div>
	    <div class="stat-desc">Precios BECEMAR</div>
	</div>
	<?php endif; ?>


</div>

<!-- Bovinos registrados -->
<?php if (count($bovinos) > 0): ?>
<div class="table-container">
    <div class="table-header">
        <h2>Bovinos Registrados (<?= count($bovinos) ?>)</h2>
        <a href="<?= BASE_URL ?>/recepcion/exportar?id=<?= $recepcion_id ?>" class="btn btn-primary btn-sm">
            Exportar Acta
        </a>
    </div>
    
    <table>    
			<thead>
			    <tr>
			        <th>SINIIGA</th>
			        <th>Sexo</th>
			        <th>Raza</th>
			        <th>Edad</th>
			        <th>Peso</th>
			        <th>Categoria</th>
			        <th>Corral</th>
			        <th>Estado</th>
			        <?php if (puedeVerPrecios()): ?>
			            <th>Precio/kg</th>
			            <th>Valor est.</th>
			        <?php endif; ?>
			        <th>Observaciones</th>
			    </tr>
			</thead>
        <tbody>
            <?php foreach ($bovinos as $b): 
                $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
                $esBaja = in_array($b['estado'], ['muerto', 'devuelto']);
                $rowStyle = $esBaja ? 'background: var(--f-rojo); color: var(--t-rojo); font-weight: 500;' : '';
            ?>
            <tr style="<?= $rowStyle ?>">
                <td><strong><?= htmlspecialchars($b['id_siniiga']) ?></strong></td>
                <td><?= $b['sexo'] === 'M' ? 'M' : 'H' ?></td>
                <td><?= htmlspecialchars($b['raza'] ?? 'N/A') ?></td>
                <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : 'N/A' ?></td>
                <td><?= number_format($b['peso_entrada'], 1) ?> kg</td>
                <td>
                    <?php if (($b['categoria'] ?? 'Sin clasificar') === 'Sin clasificar'): ?>
                        <span style="color: var(--t-amarillo); font-weight: 600;">Por clasificar</span>
                    <?php else: ?>
                        <?= htmlspecialchars($b['categoria']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['estado'] === 'muerto'): ?>
                        <span style="color: #dc3545;">-</span>
                    <?php elseif ($b['estado'] === 'devuelto'): ?>
                        <span style="color: var(--tenue);">-</span>
                    <?php elseif (!empty($b['en_hospital'])): ?>
                        <span class="badge-rol badge-recepcionista">
                            <?= htmlspecialchars($b['corral_nombre'] ?? 'HOSPITAL') ?>
                        </span>
                    <?php else: ?>
                        <span class="badge-rol badge-admin">
                            <?= htmlspecialchars($b['corral_nombre'] ?? 'Sin corral') ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($b['estado'] === 'muerto'): ?>
                        <strong style="color: #dc3545;">MUERTO</strong>
                    <?php elseif ($b['estado'] === 'devuelto'): ?>
                        <strong style="color: var(--tenue);">DEVUELTO</strong>
                    <?php elseif (!empty($b['en_hospital'])): ?>
                        <strong style="color: #dc3545;">Hospital</strong>
                    <?php elseif (!empty($b['llego_enfermo'])): ?>
                        <strong style="color: #b26a00;">Llego enfermo</strong>
                    <?php else: ?>
                        <strong style="color: #28a745;">Sano</strong>
                    <?php endif; ?>
                </td>

                
				<?php if (puedeVerPrecios()): ?>
				    <td>
				        <?php if ($esBaja): ?>
				            -
				        <?php else: ?>
				            $<?= number_format($cat['precio_kg'], 2) ?>
				        <?php endif; ?>
				    </td>
				    <td>
				        <?php if ($esBaja): ?>
				            -
				        <?php else: ?>
				            <strong>$<?= number_format($cat['precio_total'], 0) ?></strong>
				        <?php endif; ?>
				    </td>
				<?php endif; ?>




                <td style="font-size: 0.85rem;">
                    <?php if ($esBaja): ?>
                        <strong><?= htmlspecialchars($b['motivo_baja'] ?? '') ?></strong>
                        <?php if (!empty($b['observaciones_baja'])): ?>
                            <br><small><?= htmlspecialchars($b['observaciones_baja']) ?></small>
                        <?php endif; ?>
                    <?php elseif (!empty($b['en_hospital']) || !empty($b['llego_enfermo'])): ?>
                        <?= htmlspecialchars($b['motivo_hospital'] ?: $b['motivo_llegada'] ?? '') ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="table-container">
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>No hay bovinos registrados en esta recepcion</h3>
        <a href="<?= BASE_URL ?>/recepcion/bovinos?id=<?= $recepcion_id ?>" class="btn btn-primary" style="margin-top: 20px;">
            Registrar Bovinos
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($bajas): ?>
<div class="table-container" style="margin-top: 25px;">
    <div class="table-header">
        <h2>Bajas en la recepcion (<?= count($bajas) ?>)</h2>
    </div>
    <table>
        <thead>
            <tr><th>Arete</th><th>Tipo</th><th>Motivo</th></tr>
        </thead>
        <tbody>
            <?php foreach ($bajas as $bj): ?>
            <tr style="background: <?= $bj['tipo'] === 'muerte' ? 'var(--f-rojo)' : 'var(--f-gris)' ?>;">
                <td><strong style="font-family: monospace;"><?= htmlspecialchars($bj['numero_arete'] ?? '-') ?></strong></td>
                <td><strong><?= $bj['tipo'] === 'muerte' ? 'MUERTO' : 'NO COINCIDE' ?></strong></td>
                <td><?= htmlspecialchars($bj['motivo'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
