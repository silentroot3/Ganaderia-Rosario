<?php
// ============================================
// MODULO VETERINARIO - Historial clinico del bovino
// ============================================

$titulo = 'Historial Clinico';
$modulo = 'sanidad';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/categorizacion.php';

$db = Database::getInstance()->getConnection();

$bovino_id = (int)($_GET['id'] ?? 0);
if (!$bovino_id) {
    header('Location: ' . BASE_URL . '/sanidad');
    exit;
}
// Datos del bovino
$stmt = $db->prepare("
    SELECT b.*, 
           c.nombre AS corral_nombre,
           co.nombre AS corral_original_nombre,
           r.folio AS folio_recepcion,
           p.nombre AS proveedor_nombre
    FROM bovinos b
    LEFT JOIN corrales c ON b.corral_id = c.id
    LEFT JOIN corrales co ON b.corral_original_id = co.id
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

// Tratamientos
$stmt = $db->prepare("
    SELECT t.*, m.nombre AS medicamento_nombre, m.tipo AS medicamento_tipo,
           u.nombre AS vet_nombre, u.apellido AS vet_apellido
    FROM tratamientos t
    LEFT JOIN medicamentos m ON t.medicamento_id = m.id
    LEFT JOIN usuarios u ON t.veterinario_id = u.id
    WHERE t.bovino_id = ?
    ORDER BY t.fecha_aplicacion DESC
");
$stmt->execute([$bovino_id]);
$tratamientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Movimientos
$stmt = $db->prepare("
    SELECT mb.*, 
           co.nombre AS corral_origen_nombre,
           cd.nombre AS corral_destino_nombre,
           u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
    FROM movimientos_bovino mb
    LEFT JOIN corrales co ON mb.corral_origen_id = co.id
    LEFT JOIN corrales cd ON mb.corral_destino_id = cd.id
    LEFT JOIN usuarios u ON mb.usuario_id = u.id
    WHERE mb.bovino_id = ?
    ORDER BY mb.fecha DESC
");
$stmt->execute([$bovino_id]);
$movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat = categorizarBovino($bovino['sexo'], $bovino['peso_entrada']);

$estadosLabels = [
    'recibido' => ['label' => 'Recibido', 'color' => '#1e88e5'],
    'revision' => ['label' => 'En revision', 'color' => '#fbc02d'],
    'apto' => ['label' => 'Apto', 'color' => '#28a745'],
    'observacion' => ['label' => 'En observacion', 'color' => '#fbc02d'],
    'rechazado' => ['label' => 'Rechazado', 'color' => '#dc3545'],
    'clasificado' => ['label' => 'Clasificado', 'color' => '#1e88e5'],
    'en_corral' => ['label' => 'En corral', 'color' => '#28a745'],
    'en_cuarentena' => ['label' => 'En cuarentena', 'color' => '#dc3545'],
    'vendido' => ['label' => 'Vendido', 'color' => '#6c757d'],
    'muerto' => ['label' => 'Muerto', 'color' => '#dc3545'],
    'devuelto' => ['label' => 'Devuelto', 'color' => '#6c757d'],
];

$estadoActual = $estadosLabels[$bovino['estado']] ?? ['label' => $bovino['estado'], 'color' => '#666'];

$tiposMovimiento = [
    'recepcion' => 'Recepcion',
    'clasificacion' => 'Clasificacion',
    'hospital' => 'Envio a hospital',
    'alta' => 'Alta medica',
    'venta' => 'Venta',
    'regreso' => 'Regreso',
    'muerte' => 'Muerte',
];
?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Historial Clinico: <?= htmlspecialchars($bovino['id_siniiga']) ?></h2>
            <p style="color: #666; font-size: 0.9rem; margin-top: 5px;">
                <?= $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra' ?> |
                <?= number_format($bovino['peso_entrada'], 1) ?> kg |
                <?= htmlspecialchars($cat['subcategoria']) ?> |
                <?= htmlspecialchars($bovino['raza'] ?? 'Sin raza') ?>
            </p>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/sanidad" class="btn btn-secondary">Volver</a>
            <?php if ($bovino['en_hospital']): ?>
                <a href="<?= BASE_URL ?>/sanidad/atender?id=<?= $bovino_id ?>" class="btn btn-primary">Atender</a>
                <a href="<?= BASE_URL ?>/sanidad/alta?id=<?= $bovino_id ?>" class="btn" style="background: #28a745; color: white;">Dar de alta</a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Datos del bovino -->
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
        <div style="padding: 12px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Estado actual</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <span class="badge-rol" style="background: <?= $estadoActual['color'] ?>;">
                    <?= $estadoActual['label'] ?>
                </span>
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
            <div style="font-size: 0.75rem; color: #666; text-transform: uppercase; font-weight: 600;">Proveedor</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= htmlspecialchars($bovino['proveedor_nombre'] ?? 'N/A') ?>
            </div>
        </div>
    </div>
    
    <?php if ($bovino['en_hospital']): ?>
    <div style="padding: 20px; background: #f8d7da; margin: 0 20px 20px; border-radius: 8px;">
        <div style="font-size: 0.75rem; color: #721c24; text-transform: uppercase; font-weight: 600;">Motivo en hospital</div>
        <div style="font-size: 1.1rem; margin-top: 4px; color: #721c24; font-weight: 600;">
            <?= htmlspecialchars($bovino['motivo_hospital'] ?? 'Sin especificar') ?>
        </div>
        <div style="margin-top: 8px; color: #721c24; font-size: 0.9rem;">
            Ingresado el <?= date('d/m/Y H:i', strtotime($bovino['fecha_entrada_hospital'])) ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tratamientos -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Tratamientos (<?= count($tratamientos) ?>)</h2>
    </div>
    
    <?php if (count($tratamientos) > 0): ?>
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
                <th>Observaciones</th>
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
                <td>
                    <?php if ($t['dias_retiro'] > 0): ?>
                        <?= $t['dias_retiro'] ?> dias
                        <?php if ($t['fecha_fin_retiro']): ?>
                            <br><small>hasta <?= date('d/m/Y', strtotime($t['fecha_fin_retiro'])) ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(($t['vet_nombre'] ?? '') . ' ' . ($t['vet_apellido'] ?? '')) ?></td>
                <td><?= htmlspecialchars($t['observaciones'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: #999;">
        No hay tratamientos registrados para este bovino.
    </div>
    <?php endif; ?>
</div>

<!-- Movimientos -->
<div class="table-container">
    <div class="table-header">
        <h2>Trazabilidad - Movimientos (<?= count($movimientos) ?>)</h2>
    </div>
    
    <?php if (count($movimientos) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Origen</th>
                <th>Destino</th>
                <th>Motivo</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movimientos as $m): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                <td>
                    <span class="badge-rol badge-<?= $m['tipo_movimiento'] === 'hospital' ? 'recepcionista' : ($m['tipo_movimiento'] === 'muerte' ? 'recepcionista' : 'admin') ?>">
                        <?= htmlspecialchars($tiposMovimiento[$m['tipo_movimiento']] ?? $m['tipo_movimiento']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($m['corral_origen_nombre'] ?? '-') ?></td>
                <td><?= htmlspecialchars($m['corral_destino_nombre'] ?? '-') ?></td>
                <td><?= htmlspecialchars($m['motivo'] ?? '-') ?></td>
                <td><?= htmlspecialchars(($m['usuario_nombre'] ?? '') . ' ' . ($m['usuario_apellido'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: #999;">
        No hay movimientos registrados para este bovino.
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
