<?php
// ============================================
// CORRALES - Ver bovinos en un corral
// ============================================

$titulo = 'Ver Corral';
$modulo = 'corrales';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/asignacion_corrales.php';
require_once __DIR__ . '/../../includes/costos.php';

$db = Database::getInstance()->getConnection();

$corral_id = (int)($_GET['id'] ?? 0);
if (!$corral_id) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

$stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
$stmt->execute([$corral_id]);
$corral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$corral) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

// Bovinos en este corral
$stmt = $db->prepare("
    SELECT b.*, r.folio AS folio_recepcion, p.nombre AS proveedor, " . sqlPesoActual('b') . " AS peso_actual
    FROM bovinos b
    LEFT JOIN recepciones r ON b.recepcion_id = r.id
    LEFT JOIN compras c ON r.compra_id = c.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    WHERE b.corral_id = ? AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
    ORDER BY b.peso_entrada DESC
");
$stmt->execute([$corral_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Admin: costo por kilo de cada animal = lo invertido / su peso actual (precio minimo de venta)
$verCostos = puedeVerPrecios();
$costos = $verCostos && $bovinos ? costosBovinos(array_map(fn($x) => (int)$x['id'], $bovinos)) : [];

$porcentaje = $corral['capacidad_maxima'] > 0 ? (count($bovinos) / $corral['capacidad_maxima']) * 100 : 0;
?>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Corral: <?= htmlspecialchars($corral['nombre']) ?></h2>
            <p style="color: var(--tenue); font-size: 0.9rem; margin-top: 5px;">
                Tipo: <strong><?= htmlspecialchars($corral['tipo']) ?></strong> |
                Sexo: <strong><?= htmlspecialchars($corral['sexo_permitido']) ?></strong> |
                <?php if ($corral['peso_min'] && $corral['peso_max']): ?>
                    Rango: <strong><?= number_format($corral['peso_min'], 0) ?> - <?= number_format($corral['peso_max'], 0) ?> kg</strong> |
                <?php endif; ?>
                Estado: <strong><?= htmlspecialchars($corral['estado']) ?></strong>
            </p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if (Permisos::puedeEditar('clasificacion') && !$corral['es_recepcion'] && !$corral['es_hospital'] && count($bovinos) > 0): ?>
                <a href="<?= BASE_URL ?>/clasificacion/pesar?corral=<?= $corral_id ?>" class="btn btn-primary">Volver a pesar</a>
            <?php endif; ?>
            <?php if (Permisos::puedeEditar('corrales') && !$corral['es_recepcion'] && count($bovinos) > 0): ?>
                <a href="<?= BASE_URL ?>/corrales/migrar?id=<?= $corral_id ?>" class="btn btn-primary">Migrar bovinos</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/corrales" class="btn btn-secondary">Volver</a>
        </div>
    </div>

    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
            <div style="text-align: center; padding: 15px; background: var(--suave); border-radius: 8px;">
                <div style="font-size: 2rem; font-weight: bold; color: #0b4f86;"><?= count($bovinos) ?></div>
                <div style="color: var(--tenue); font-size: 0.85rem;">Bovinos actuales</div>
            </div>
            <div style="text-align: center; padding: 15px; background: var(--suave); border-radius: 8px;">
                <div style="font-size: 2rem; font-weight: bold; color: #1e88e5;"><?= $corral['capacidad_maxima'] ?></div>
                <div style="color: var(--tenue); font-size: 0.85rem;">Capacidad maxima</div>
            </div>
            <div style="text-align: center; padding: 15px; background: var(--suave); border-radius: 8px;">
                <div style="font-size: 2rem; font-weight: bold; color: <?= $porcentaje >= 90 ? '#dc3545' : '#28a745' ?>;">
                    <?= number_format($porcentaje, 0) ?>%
                </div>
                <div style="color: var(--tenue); font-size: 0.85rem;">Ocupacion</div>
            </div>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <h2>Bovinos en este corral (<?= count($bovinos) ?>)</h2>
    </div>

    <?php if (count($bovinos) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>SINIIGA</th>
                <th>Sexo</th>
                <th>Raza</th>
                <th>Categoria</th>
                <th>Peso entrada</th>
                <th>Peso actual</th>
                <?php if ($verCostos): ?><th title="Lo invertido en el animal entre su peso actual: precio por kilo del que no conviene bajar">Costo por kg</th><?php endif; ?>
                <th>Edad</th>
                <th>Recepcion</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bovinos as $b): ?>
            <tr>
                <td><strong><?= htmlspecialchars($b['id_siniiga']) ?></strong></td>
                <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                <td><?= htmlspecialchars($b['raza'] ?? '-') ?></td>
                <td><?= ($b['categoria'] ?? 'Sin clasificar') === 'Sin clasificar' ? '<span style="color:#b26a00;font-weight:600;">Sin clasificar</span>' : htmlspecialchars($b['categoria']) ?></td>
                <td><?= number_format($b['peso_entrada'], 1) ?> kg</td>
                <td><strong><?= number_format($b['peso_actual'], 1) ?> kg</strong></td>
                <?php if ($verCostos): ?><td><strong><?= $b['peso_actual'] > 0 ? dinero(($costos[(int)$b['id']]['total'] ?? 0) / $b['peso_actual']) : '-' ?></strong></td><?php endif; ?>
                <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-' ?></td>
                <td><?= htmlspecialchars($b['folio_recepcion'] ?? '-') ?></td>
                <td>
                    <?php if (Permisos::puedeCrear('clasificacion')): ?>
                        <a href="<?= BASE_URL ?>/clasificacion/asignar?id=<?= $b['id'] ?>&volver=corral" class="btn-icon"><?= $corral['es_recepcion'] ? 'Clasificar' : 'Editar / mover' ?></a>
                    <?php endif; ?>
                    <?php if (Permisos::puedeCrear('sanidad')): ?><a href="<?= BASE_URL ?>/sanidad/atender?id=<?= $b['id'] ?>" class="btn-icon" style="color:#43a047;">Medicar</a><?php endif; ?>
                    <?php if (Permisos::puedeCrear('clasificacion') && !$b['en_hospital'] && !$corral['es_hospital'] && ($b['categoria'] ?? 'Sin clasificar') !== 'Sin clasificar'): ?><a href="<?= BASE_URL ?>/clasificacion/enviar-vet?id=<?= $b['id'] ?>" class="btn-icon" style="color:#e67e22;">A veterinaria</a><?php endif; ?>
                    <?php if (Permisos::puedeCrear('sanidad') || Permisos::puedeCrear('clasificacion')): ?><a href="<?= BASE_URL ?>/bovinos/muerte?id=<?= $b['id'] ?>" class="btn-icon" style="color:#dc3545;">Registrar muerte</a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>Corral vacio</h3>
        <p>No hay bovinos en este corral.</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
