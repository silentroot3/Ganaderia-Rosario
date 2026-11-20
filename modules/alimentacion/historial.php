<?php
// ============================================
// ALIMENTACION - Historial
// ============================================

$titulo = 'Historial de Alimentacion';
$modulo = 'alimentacion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Filtros
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$corral_filtro = (int)($_GET['corral'] ?? 0);

$sql = "
    SELECT a.*, c.nombre AS corral_nombre, d.nombre AS dieta_nombre, u.nombre AS usuario_nombre
    FROM alimentaciones a
    LEFT JOIN corrales_todos c ON a.corral_id = c.id
    LEFT JOIN dietas d ON a.dieta_id = d.id
    LEFT JOIN usuarios u ON a.usuario_id = u.id
    WHERE a.fecha BETWEEN ? AND ?
";
$params = [$fecha_inicio, $fecha_fin];

if ($corral_filtro > 0) {
    $sql .= " AND a.corral_id = ?";
    $params[] = $corral_filtro;
}

$sql .= " ORDER BY a.fecha DESC, a.hora DESC LIMIT 200";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$historial = $stmt->fetchAll();

// Corrales para el filtro
$stmt = $db->query("SELECT id, nombre FROM corrales WHERE es_hospital = 0 AND es_venta = 0 ORDER BY nombre");
$corrales = $stmt->fetchAll();

// Totales
$totalKg = array_sum(array_column($historial, 'cantidad_kg'));
?>

<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Historial de Alimentacion</h2>
        <a href="<?= BASE_URL ?>/alimentacion" class="btn btn-secondary">Volver</a>
    </div>
    
    <form method="GET" style="padding: 20px; background: var(--suave);">
        <div style="display: grid; grid-template-columns: 1fr 1fr 2fr auto; gap: 10px; align-items: end;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Desde</label>
                <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Hasta</label>
                <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Corral</label>
                <select name="corral" style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
                    <option value="">Todos</option>
                    <?php foreach ($corrales as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $corral_filtro == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>
    
    <div style="padding: 15px 20px; background: var(--f-verde);">
        <strong>Total registros:</strong> <?= count($historial) ?> | 
        <strong>Total kg:</strong> <?= number_format($totalKg, 1) ?> kg
    </div>
    
    <?php if (count($historial) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Corral</th>
                <th>Dieta</th>
                <th>Cantidad</th>
                <th>Horario</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                <td><?= $h['hora'] ?></td>
                <td><strong><?= htmlspecialchars($h['corral_nombre'] ?? '-') ?></strong></td>
                <td><?= htmlspecialchars($h['dieta_nombre'] ?? '-') ?></td>
                <td><strong><?= number_format($h['cantidad_kg'], 1) ?> kg</strong></td>
                <td><?= htmlspecialchars($h['horario']) ?></td>
                <td><?= htmlspecialchars($h['usuario_nombre'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 40px; text-align: center; color: var(--tenue);">
        No hay alimentaciones en el rango seleccionado.
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
