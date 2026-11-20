<?php
// ============================================
// MODULO CONFIGURACION - COMPRAS
// Ver detalle de una compra
// ============================================

$titulo = 'Detalle de Compra';
$modulo = 'configuracion';
$accion = 'ver';

require_once __DIR__ . '/../../../includes/header.php';

$db = Database::getInstance()->getConnection();

$compra_id = (int)($_GET['id'] ?? 0);
if (!$compra_id) {
    header('Location: ' . BASE_URL . '/configuracion/compras');
    exit;
}

$stmt = $db->prepare("
    SELECT c.*, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
    FROM compras c
    LEFT JOIN usuarios u ON c.usuario_compra_id = u.id
    WHERE c.id = ?
");
$stmt->execute([$compra_id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) {
    header('Location: ' . BASE_URL . '/configuracion/compras');
    exit;
}

$stmt = $db->prepare("SELECT * FROM compra_bovinos WHERE compra_id = ? ORDER BY numero_linea");
$stmt->execute([$compra_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRecibidos = 0;
foreach ($bovinos as $b) {
    if ($b['recibido']) $totalRecibidos++;
}
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'creada'): ?>
    <div class="alert alert-success">
        Compra creada correctamente. Se alerto al recepcionista.
    </div>
<?php endif; ?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <div>
            <h2>Compra <?= 	htmlspecialchars($compra['folio']) ?></h2>
            <p style="color: var(--tenue); font-size: 0.9rem; margin-top: 5px;">
                Registrada el <?= date('d/m/Y H:i', strtotime($compra['created_at'])) ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/configuracion/compras" class="btn btn-secondary">Volver</a>
        <a href="<?= BASE_URL ?>/configuracion/compras/guia?id=<?= $compra['id'] ?>" target="_blank" class="btn btn-secondary">Guia de transito</a>
    </div>
<?php if ($compra['estado'] === 'pendiente'): ?>
	            <a href="<?= BASE_URL ?>/configuracion/compras/editar?id=<?= $compra_id ?>" 
	               class="btn btn-primary">Editar</a>
	            <a href="<?= BASE_URL ?>/configuracion/compras/cancelar?id=<?= $compra_id ?>" 
	               class="btn" style="background: #dc3545; color: white;">Cancelar</a>
	        <?php endif; ?>
	    </div>
	</div>


    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Proveedor</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($compra['origen_nombre'] ?? 'N/A') ?></div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">REEMO</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= htmlspecialchars($compra['numero_reemo'] ?? 'N/A') ?></div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Fecha compra</div>
            <div style="font-size: 1rem; margin-top: 4px;"><?= date('d/m/Y', strtotime($compra['fecha_compra'])) ?></div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Estado</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <span class="badge-rol badge-<?= $compra['estado'] === 'recibida' ? 'admin' : 'clasificador' ?>">
                    <?= htmlspecialchars($compra['estado']) ?>
                </span>
            </div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Animales</div>
            <div style="font-size: 1rem; margin-top: 4px;">
                <?= $compra['cantidad_total'] ?> (<?= $totalRecibidos ?> recibidos)
            </div>
        </div>
        <div style="padding: 12px; background: var(--suave); border-radius: 6px;">
            <div style="font-size: 0.75rem; color: var(--tenue); text-transform: uppercase; font-weight: 600;">Precio total</div>
            <div style="font-size: 1rem; margin-top: 4px;">$<?= number_format($compra['precio_total'], 2) ?></div>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <h2>Bovinos (<?= count($bovinos) ?>)</h2>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>SINIIGA</th>
                <th>Sexo</th>
                <th>Raza</th>
                <th>Meses</th>
                <th>Peso est.</th>
                <th>Recibido</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bovinos as $b): ?>
            <tr>
                <td><?= $b['numero_linea'] ?></td>
                <td><strong><?= htmlspecialchars($b['numero_arete']) ?></strong></td>
                <td><?= $b['sexo'] === 'MACHO' ? 'M' : 'H' ?></td>
                <td><?= htmlspecialchars($b['raza'] ?? '-') ?></td>
                <td><?= $b['meses'] ?? '-' ?></td>
                <td><?= $b['peso_estimado'] ? number_format($b['peso_estimado'], 1) . ' kg' : '-' ?></td>
                <td>
                    <?php if ($b['recibido']): ?>
                        <span style="color: #28a745; font-weight: 600;">Si</span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-weight: 600;">No</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
