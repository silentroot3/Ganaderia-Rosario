<?php
// ============================================
// MÓDULO DE SANIDAD
// Solo accesible para: admin, veterinario
// ============================================

$titulo = 'Sanidad';
$modulo = 'sanidad';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query("
    SELECT r.*, b.id_siniiga, u.nombre AS veterinario_nombre
    FROM revisiones r
    LEFT JOIN bovinos b ON r.bovino_id = b.id
    LEFT JOIN usuarios u ON r.veterinario_id = u.id
    ORDER BY r.fecha DESC
    LIMIT 20
");
$revisiones = $stmt->fetchAll();
?>

<div class="table-header">
    <h2>🩺 Revisiones veterinarias</h2>
    <?php if (Permisos::puedeCrear('sanidad')): ?>
        <a href="<?= BASE_URL ?>/sanidad/crear" class="btn btn-primary">
            ➕ Nueva Revisión
        </a>
    <?php endif; ?>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Arete SINIIGA</th>
                <th>Veterinario</th>
                <th>Fecha</th>
                <th>Temperatura</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($revisiones as $r): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['id_siniiga'] ?? 'N/A') ?></strong></td>
                    <td><?= htmlspecialchars($r['veterinario_nombre'] ?? 'N/A') ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></td>
                    <td><?= $r['temperatura'] ? $r['temperatura'] . '°C' : '—' ?></td>
                    <td>
                        <span class="badge-rol badge-<?= 
                            $r['estado'] === 'APTO' ? 'admin' : 
                            ($r['estado'] === 'RECHAZADO' ? 'recepcionista' : 'clasificador') 
                        ?>">
                            <?= htmlspecialchars($r['estado']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/sanidad/ver?id=<?= $r['id'] ?>" class="btn-icon">👁️</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
