<?php
// ============================================
// RECEPCION - Compras pendientes de recibir
// ============================================

$titulo = 'Compras Pendientes';
$modulo = 'recepcion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Compras pendientes o en transito
$stmt = $db->query("
    SELECT c.*, 
           u.nombre AS usuario_nombre, 
           u.apellido AS usuario_apellido,
           (SELECT COUNT(*) FROM compra_bovinos WHERE compra_id = c.id) AS total_bovinos,
           (SELECT COUNT(*) FROM compra_bovinos WHERE compra_id = c.id AND recibido = 1) AS total_recibidos
    FROM compras c
    LEFT JOIN usuarios u ON c.usuario_compra_id = u.id
    WHERE c.estado IN ('pendiente', 'en_transito')
    ORDER BY c.fecha_llegada_estimada ASC, c.fecha_compra ASC
");
$comprasPendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compras recibidas recientemente
$stmt = $db->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM compra_bovinos WHERE compra_id = c.id AND recibido = 1) AS total_recibidos
    FROM compras c
    WHERE c.estado = 'recibida'
    ORDER BY c.fecha_llegada_real DESC
    LIMIT 10
");
$comprasRecibidas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPendientes = count($comprasPendientes);
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'ya_recibida'): ?>
    <div class="alert alert-info" style="margin-bottom: 20px;">
        Esta compra ya fue recibida previamente. No puedes recibirla de nuevo.
    </div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'recibida'): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        Compra recibida correctamente.
    </div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card yellow">
        <div class="stat-label">Compras pendientes</div>
        <div class="stat-value"><?= $totalPendientes ?></div>
        <div class="stat-desc">Esperando recepcion</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Bovinos por recibir</div>
        <div class="stat-value">
            <?php 
            $totalBovinos = 0;
            foreach ($comprasPendientes as $c) {
                $totalBovinos += ($c['total_bovinos'] - $c['total_recibidos']);
            }
            echo $totalBovinos;
            ?>
        </div>
        <div class="stat-desc">En todas las compras</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Recibidas recientemente</div>
        <div class="stat-value"><?= count($comprasRecibidas) ?></div>
        <div class="stat-desc">Ultimas 10</div>
    </div>
</div>

<!-- Compras Pendientes -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Compras Pendientes de Recibir</h2>
    </div>
    
    <?php if ($totalPendientes > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Folio</th>
                <th>Proveedor</th>
                <th>Fecha compra</th>
                <th>Llegada estimada</th>
                <th>Bovinos</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comprasPendientes as $c): 
                $progreso = $c['total_bovinos'] > 0 ? ($c['total_recibidos'] / $c['total_bovinos']) * 100 : 0;
                
                $llegadaTarde = false;
                if ($c['fecha_llegada_estimada']) {
                    $fechaEst = new DateTime($c['fecha_llegada_estimada']);
                    $hoy = new DateTime();
                    $llegadaTarde = $hoy > $fechaEst;
                }
            ?>
            <tr <?= $llegadaTarde ? 'style="background: var(--f-amarillo);"' : '' ?>>
                <td><strong><?= htmlspecialchars($c['folio']) ?></strong></td>
                <td>
                    <strong><?= htmlspecialchars($c['origen_nombre'] ?? 'N/A') ?></strong>
                    <?php if ($c['origen_upp']): ?>
                        <br><small style="color: var(--tenue); font-family: monospace;">UPP <?= htmlspecialchars($c['origen_upp']) ?></small>
                    <?php endif; ?>
                    <?php $ubicacion = implode(', ', array_filter([$c['origen_municipio'], $c['origen_estado']])); ?>
                    <?php if ($ubicacion): ?>
                        <br><small style="color: var(--tenue);"><?= htmlspecialchars($ubicacion) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= date('d/m/Y', strtotime($c['fecha_compra'])) ?></td>
                <td>
                    <?= $c['fecha_llegada_estimada'] ? date('d/m/Y', strtotime($c['fecha_llegada_estimada'])) : '-' ?>
                    <?php if ($llegadaTarde): ?>
                        <br><small style="color: var(--t-amarillo);">Atrasada</small>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= $c['total_bovinos'] ?></strong>
                    <?php if ($c['total_recibidos'] > 0): ?>
                        <div style="background: var(--suave); height: 6px; border-radius: 3px; margin-top: 5px; overflow: hidden;">
                            <div style="width: <?= $progreso ?>%; height: 100%; background: #28a745;"></div>
                        </div>
                        <small><?= $c['total_recibidos'] ?> de <?= $c['total_bovinos'] ?> recibidos</small>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="background: var(--f-amarillo); color: var(--t-amarillo); padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                        <?= htmlspecialchars($c['estado']) ?>
                    </span>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>/recepcion/recibir-compra?id=<?= $c['id'] ?>" 
                       class="btn btn-primary btn-sm">
                        Recibir Compra
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>No hay compras pendientes de recibir</h3>
        <p>Todas las compras han sido recibidas o no hay compras registradas.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Compras Recibidas Recientemente -->
<?php if (count($comprasRecibidas) > 0): ?>
<div class="table-container">
    <div class="table-header">
        <h2>Compras Recibidas Recientemente</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>Folio</th>
                <th>Proveedor</th>
                <th>Fecha compra</th>
                <th>Fecha recepcion</th>
                <th>Bovinos</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comprasRecibidas as $c): ?>
            <tr>
                <td><strong><?= htmlspecialchars($c['folio']) ?></strong></td>
                <td><?= htmlspecialchars($c['origen_nombre'] ?? 'N/A') ?></td>
                <td><?= date('d/m/Y', strtotime($c['fecha_compra'])) ?></td>
                <td>
                    <?= $c['fecha_llegada_real'] ? date('d/m/Y H:i', strtotime($c['fecha_llegada_real'])) : '-' ?>
                </td>
                <td><?= $c['total_recibidos'] ?> / <?= $c['cantidad_total'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
