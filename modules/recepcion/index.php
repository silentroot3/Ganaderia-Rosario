<?php
// ============================================
// MÓDULO DE RECEPCIÓN
// Solo accesible para: admin, recepcionista
// ============================================

$titulo = 'Recepción';
$modulo = 'recepcion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

// Obtener datos de recepciones
$db = Database::getInstance()->getConnection();
$stmt = $db->query("
    SELECT r.*, p.nombre AS proveedor_nombre, u.nombre AS usuario_nombre
    FROM recepciones r
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    LEFT JOIN usuarios u ON r.usuario_id = u.id
    ORDER BY r.fecha_recepcion DESC
    LIMIT 20
");
$recepciones = $stmt->fetchAll();
?>

<div class="table-container">
    <div class="table-header">
        <h2>📋 Recepciones recientes</h2>
        <?php if (Permisos::puedeCrear('recepcion')): ?>
            <a href="<?= BASE_URL ?>/recepcion/crear" class="btn btn-primary">
                ➕ Nueva Recepción
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($recepciones)): ?>
        <div style="padding: 60px 40px; text-align: center; color: #999;">
            <div style="font-size: 4rem; margin-bottom: 15px;">📭</div>
            <h3 style="margin-bottom: 10px;">No hay recepciones registradas aún</h3>
            <p style="margin-bottom: 25px;">Cuando llegue un camión con ganado, regístralo aquí.</p>
            
            <?php if (Permisos::puedeCrear('recepcion')): ?>
                <a href="<?= BASE_URL ?>/recepcion/crear" class="btn btn-primary">
                    ➕ Registrar primera recepción
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Proveedor</th>
                    <th>Fecha</th>
                    <th>Cantidad</th>
                    <th>Estado</th>
                    <th>Registró</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recepciones as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['folio']) ?></strong></td>
                        <td><?= htmlspecialchars($r['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($r['fecha_recepcion'])) ?></td>
                        <td><?= $r['cantidad_animales'] ?> animales</td>
                        <td>
                            <span class="badge-rol badge-<?= 
                                $r['estado'] === 'completado' ? 'admin' : 
                                ($r['estado'] === 'cancelado' ? 'recepcionista' : 'clasificador') 
                            ?>">
                                <?= htmlspecialchars($r['estado']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r['usuario_nombre'] ?? 'N/A') ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/recepcion/detalle?id=<?= $r['id'] ?>" class="btn-icon" title="Ver">👁️</a>
                            
                            <?php if (Permisos::puedeEditar('recepcion')): ?>
                                <a href="<?= BASE_URL ?>/recepcion/editar?id=<?= $r['id'] ?>" class="btn-icon" title="Editar">✏️</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
