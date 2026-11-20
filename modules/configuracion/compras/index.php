<?php
// ============================================
// MODULO CONFIGURACION - COMPRAS
// Lista de compras de ganado
// ============================================

$titulo = 'Compras de Ganado';
$modulo = 'configuracion';
$accion = 'ver';

require_once __DIR__ . '/../../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Filtros
$estado_filtro = $_GET['estado'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

// Query
$sql = "
    SELECT c.*, 
           u.nombre AS usuario_nombre, 
           u.apellido AS usuario_apellido,
           (SELECT COUNT(*) FROM compra_bovinos WHERE compra_id = c.id) AS total_bovinos_detalle,
           (SELECT COUNT(*) FROM compra_bovinos WHERE compra_id = c.id AND recibido = 1) AS total_recibidos
    FROM compras c
    LEFT JOIN usuarios u ON c.usuario_compra_id = u.id
    WHERE 1=1
";
$params = [];

if ($estado_filtro) {
    $sql .= " AND c.estado = ?";
    $params[] = $estado_filtro;
}

if ($buscar) {
    $sql .= " AND (c.folio LIKE ? OR c.numero_reemo LIKE ? OR c.origen_nombre LIKE ?)";
    $params[] = "%{$buscar}%";
    $params[] = "%{$buscar}%";
    $params[] = "%{$buscar}%";
}

$sql .= " ORDER BY c.fecha_compra DESC, c.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadisticas
$stmt = $db->query("SELECT COUNT(*) FROM compras WHERE estado = 'pendiente'");
$pendientes = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM compras WHERE estado = 'recibida'");
$recibidas = $stmt->fetchColumn();

$stmt = $db->query("SELECT IFNULL(SUM(cantidad_total), 0) FROM compras WHERE estado IN ('pendiente','en_transito')");
$animalesEnTransito = $stmt->fetchColumn();
?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card yellow">
        <div class="stat-label">Compras pendientes</div>
        <div class="stat-value"><?= $pendientes ?></div>
        <div class="stat-desc">Esperando llegada</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Compras recibidas</div>
        <div class="stat-value"><?= $recibidas ?></div>
        <div class="stat-desc">Historial</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Animales en transito</div>
        <div class="stat-value"><?= $animalesEnTransito ?></div>
        <div class="stat-desc">Por recibir</div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Total compras</div>
        <div class="stat-value"><?= count($compras) ?></div>
        <div class="stat-desc">Registradas</div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <h2>Compras de Ganado</h2>
        <a href="<?= BASE_URL ?>/configuracion/compras/crear" class="btn btn-primary">
            + Nueva Compra
        </a>
    </div>

    <form method="GET" style="padding: 20px; background: var(--suave); border-bottom: 1px solid var(--linea);">
        <div style="display: grid; grid-template-columns: 1fr 200px 150px; gap: 10px;">
            <input type="text" name="buscar" 
                   placeholder="Buscar por folio, REEMO o proveedor..."
                   value="<?= htmlspecialchars($buscar) ?>"
                   style="padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            
            <select name="estado" style="padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">Todos los estados</option>
                <option value="pendiente" <?= $estado_filtro === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                <option value="en_transito" <?= $estado_filtro === 'en_transito' ? 'selected' : '' ?>>En transito</option>
                <option value="recibida" <?= $estado_filtro === 'recibida' ? 'selected' : '' ?>>Recibida</option>
                <option value="cancelada" <?= $estado_filtro === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
            </select>
            
            <button type="submit" class="btn btn-secondary">Filtrar</button>
        </div>
    </form>

    <?php if (count($compras) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Folio</th>
                <th>REEMO</th>
                <th>Origen</th>
                <th>Fecha</th>
                <th>Animales</th>
                <th>Estado</th>
                <th>Registro</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($compras as $c): 
                $estadoColors = [
                    'pendiente' => ['bg' => 'var(--f-amarillo)', 'color' => 'var(--t-amarillo)', 'text' => 'Pendiente'],
                    'en_transito' => ['bg' => 'var(--f-azul)', 'color' => 'var(--t-azul)', 'text' => 'En transito'],
                    'recibida' => ['bg' => 'var(--f-verde)', 'color' => 'var(--t-verde)', 'text' => 'Recibida'],
                    'cancelada' => ['bg' => 'var(--f-rojo)', 'color' => 'var(--t-rojo)', 'text' => 'Cancelada'],
                ];
                $ec = $estadoColors[$c['estado']] ?? ['bg' => 'var(--linea)', 'color' => 'var(--texto)', 'text' => $c['estado']];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($c['folio']) ?></strong></td>
                <td><?= htmlspecialchars($c['numero_reemo'] ?? '-') ?></td>
                <td>
                    <?= htmlspecialchars($c['origen_nombre'] ?? '-') ?>
                    <?php if ($c['origen_estado']): ?>
                        <br><small style="color: var(--tenue);"><?= htmlspecialchars($c['origen_estado']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= date('d/m/Y', strtotime($c['fecha_compra'])) ?></td>
                <td>
                    <strong><?= $c['cantidad_total'] ?></strong>
                    <?php if ($c['total_bovinos_detalle'] > 0): ?>
                        <br><small style="color: var(--tenue);">
                            <?= $c['total_recibidos'] ?>/<?= $c['total_bovinos_detalle'] ?> recibidos
                        </small>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="background: <?= $ec['bg'] ?>; color: <?= $ec['color'] ?>; padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                        <?= $ec['text'] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars(($c['usuario_nombre'] ?? '') . ' ' . ($c['usuario_apellido'] ?? '')) ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $c['id'] ?>" class="btn-icon" title="Ver detalle">Ver</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3 style="margin-bottom: 10px;">No hay compras registradas</h3>
        <p style="margin-bottom: 25px;">Cuando compres ganado, registralo aqui para avisar al recepcionista.</p>
        <a href="<?= BASE_URL ?>/configuracion/compras/crear" class="btn btn-primary">
            + Registrar primera compra
        </a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
