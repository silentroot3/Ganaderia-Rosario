<?php
// ============================================
// TRATAMIENTOS - Historial general y animales en retiro (HU-011)
// ============================================

$titulo = 'Tratamientos';
$modulo = 'tratamientos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$filtro = $_GET['filtro'] ?? 'retiro';

$enRetiro = $db->query("
    SELECT COUNT(DISTINCT bovino_id) FROM tratamientos WHERE fecha_fin_retiro >= CURDATE()
")->fetchColumn();
$delMes = $db->query("
    SELECT COUNT(*), COALESCE(SUM(costo),0) FROM tratamientos
    WHERE YEAR(fecha_aplicacion) = YEAR(CURDATE()) AND MONTH(fecha_aplicacion) = MONTH(CURDATE())
")->fetch(PDO::FETCH_NUM);

$where = $filtro === 'retiro' ? "WHERE t.fecha_fin_retiro >= CURDATE()" : "";
$stmt = $db->query("
    SELECT t.*, b.id_siniiga, b.estado AS bovino_estado, m.nombre AS medicamento,
           u.nombre AS vet_nombre, u.apellido AS vet_apellido
    FROM tratamientos t
    INNER JOIN bovinos b ON b.id = t.bovino_id
    INNER JOIN medicamentos m ON m.id = t.medicamento_id
    LEFT JOIN usuarios u ON u.id = t.veterinario_id
    $where
    ORDER BY t.fecha_aplicacion DESC
    LIMIT 200
");
$tratamientos = $stmt->fetchAll();
$verCostos = puedeVerPrecios();
?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card yellow">
        <div class="stat-label">Bovinos en retiro</div>
        <div class="stat-value"><?= (int)$enRetiro ?></div>
        <div class="stat-desc">Bloqueados para venta</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Tratamientos del mes</div>
        <div class="stat-value"><?= (int)$delMes[0] ?></div>
        <?php if ($verCostos): ?><div class="stat-desc"><?= dinero($delMes[1]) ?> en medicamento</div><?php endif; ?>
    </div>
</div>

<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <?php if (Permisos::puedeCrear('tratamientos')): ?>
        <a href="<?= BASE_URL ?>/tratamientos/crear" class="btn btn-primary">+ Registrar tratamiento</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/tratamientos/inventario" class="btn btn-secondary">Inventario de medicamentos</a>
    <a href="<?= BASE_URL ?>/tratamientos?filtro=retiro" class="btn <?= $filtro === 'retiro' ? 'btn-primary' : 'btn-secondary' ?>">En retiro</a>
    <a href="<?= BASE_URL ?>/tratamientos?filtro=todos" class="btn <?= $filtro === 'todos' ? 'btn-primary' : 'btn-secondary' ?>">Historial completo</a>
</div>

<div class="table-container">
    <div class="table-header">
        <h2><?= $filtro === 'retiro' ? 'Animales con retiro activo' : 'Historial de tratamientos' ?> (<?= count($tratamientos) ?>)</h2>
    </div>
    <?php if ($tratamientos): ?>
    <table>
        <thead>
            <tr>
                <th>Fecha</th><th>Arete</th><th>Medicamento</th><th>Dosis</th><th>Diagnostico</th>
                <th>Retiro hasta</th><?php if ($verCostos): ?><th>Costo</th><?php endif; ?><th>Veterinario</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tratamientos as $t):
            $activo = $t['fecha_fin_retiro'] && $t['fecha_fin_retiro'] >= date('Y-m-d'); ?>
            <tr>
                <td><?= fechaHora($t['fecha_aplicacion']) ?></td>
                <td><a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($t['id_siniiga']) ?>"><strong><?= h($t['id_siniiga']) ?></strong></a></td>
                <td><?= h($t['medicamento']) ?></td>
                <td><?= $t['dosis'] !== null ? number_format($t['dosis'], 2) : '-' ?> <?= h($t['via_administracion']) ?></td>
                <td><?= h(mb_strimwidth((string)$t['diagnostico'], 0, 60, '...')) ?></td>
                <td>
                    <?php if ($activo): ?>
                        <?= pill(fechaCorta($t['fecha_fin_retiro']), 'var(--f-amarillo)', 'var(--t-amarillo)') ?>
                    <?php else: ?>
                        <span style="color:var(--tenue);"><?= $t['fecha_fin_retiro'] ? fechaCorta($t['fecha_fin_retiro']) : 'Sin retiro' ?></span>
                    <?php endif; ?>
                </td>
                <?php if ($verCostos): ?><td><?= dinero($t['costo']) ?></td><?php endif; ?>
                <td><?= h(trim($t['vet_nombre'] . ' ' . $t['vet_apellido'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div style="padding:40px;text-align:center;color:var(--tenue);">No hay tratamientos para mostrar.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
