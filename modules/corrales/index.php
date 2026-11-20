<?php
// ============================================
// CORRALES - Lista de corrales
// ============================================

$titulo = 'Corrales';
$modulo = 'corrales';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';

$sql = "
    SELECT c.*,
           (SELECT COUNT(*) FROM bovinos WHERE corral_id = c.id AND estado NOT IN ('vendido', 'muerto', 'devuelto')) AS bovinos_actuales
    FROM corrales c
    WHERE 1=1
";
$params = [];

if ($filtro_tipo) {
    $sql .= " AND c.tipo = ?";
    $params[] = $filtro_tipo;
}

if ($filtro_estado) {
    $sql .= " AND c.estado = ?";
    $params[] = $filtro_estado;
}

$sql .= " ORDER BY c.es_recepcion DESC, c.es_hospital DESC, c.es_venta DESC, c.nombre";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$corrales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadisticas
$stmt = $db->query("SELECT COUNT(*) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
$totalNormales = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM corrales WHERE es_hospital = 1");
$totalHospital = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM corrales WHERE es_venta = 1");
$totalVenta = $stmt->fetchColumn();

$stmt = $db->query("SELECT IFNULL(SUM(capacidad_maxima), 0) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
$capacidadTotal = $stmt->fetchColumn();

$stmt = $db->query("SELECT IFNULL(SUM(ocupacion_actual), 0) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
$ocupacionTotal = $stmt->fetchColumn();

$porcentajeGlobal = $capacidadTotal > 0 ? ($ocupacionTotal / $capacidadTotal) * 100 : 0;
?>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['msg'] === 'estado_cambiado' ? 'Estado del corral actualizado' : $_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card blue">
        <div class="stat-label">Corrales normales</div>
        <div class="stat-value"><?= $totalNormales ?></div>
        <div class="stat-desc"><?= $ocupacionTotal ?> / <?= $capacidadTotal ?> bovinos</div>
    </div>

    <div class="stat-card" style="border-left-color: #dc3545;">
        <div class="stat-label">Hospital</div>
        <div class="stat-value"><?= $totalHospital ?></div>
        <div class="stat-desc">Corrales de cuarentena</div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Corrales de venta</div>
        <div class="stat-value"><?= $totalVenta ?></div>
        <div class="stat-desc">Para clientes</div>
    </div>

    <div class="stat-card yellow">
        <div class="stat-label">Ocupacion global</div>
        <div class="stat-value"><?= number_format($porcentajeGlobal, 1) ?>%</div>
        <div class="stat-desc"><?= $ocupacionTotal ?> / <?= $capacidadTotal ?> bovinos</div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <h2>Corrales</h2>
        <?php if (Permisos::puedeCrear('corrales')): ?>
            <a href="<?= BASE_URL ?>/corrales/crear" class="btn btn-primary">
                + Nuevo Corral
            </a>
        <?php endif; ?>
    </div>

    <!-- Filtros -->
    <form method="GET" style="padding: 20px; background: var(--suave); border-bottom: 1px solid var(--linea);">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 150px; gap: 10px;">
            <select name="tipo" style="padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">Todos los tipos</option>
                <option value="Hembras" <?= $filtro_tipo === 'Hembras' ? 'selected' : '' ?>>Hembras</option>
                <option value="Machos" <?= $filtro_tipo === 'Machos' ? 'selected' : '' ?>>Machos</option>
                <option value="Castrados" <?= $filtro_tipo === 'Castrados' ? 'selected' : '' ?>>Castrados</option>
                <option value="Engorda" <?= $filtro_tipo === 'Engorda' ? 'selected' : '' ?>>Engorda</option>
                <option value="Cuarentena" <?= $filtro_tipo === 'Cuarentena' ? 'selected' : '' ?>>Cuarentena</option>
                <option value="Mixto" <?= $filtro_tipo === 'Mixto' ? 'selected' : '' ?>>Mixto</option>
            </select>

            <select name="estado" style="padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <option value="">Todos los estados</option>
                <option value="Disponible" <?= $filtro_estado === 'Disponible' ? 'selected' : '' ?>>Disponible</option>
                <option value="Ocupado" <?= $filtro_estado === 'Ocupado' ? 'selected' : '' ?>>Ocupado</option>
                <option value="Mantenimiento" <?= $filtro_estado === 'Mantenimiento' ? 'selected' : '' ?>>Mantenimiento</option>
            </select>

            <div></div>

            <button type="submit" class="btn btn-secondary">Filtrar</button>
        </div>
    </form>

    <?php if (count($corrales) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Corral</th>
                <th>Tipo</th>
                <th>Sexo</th>
                <th>Rango peso</th>
                <th>Ocupacion</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($corrales as $c):
                $porcentaje = $c['capacidad_maxima'] > 0 ? ($c['bovinos_actuales'] / $c['capacidad_maxima']) * 100 : 0;
                $colorBarra = $porcentaje >= 90 ? '#dc3545' : ($porcentaje >= 70 ? '#fbc02d' : '#28a745');

                $tipoLabel = $c['es_recepcion'] ? 'RECEPCION ' . strtoupper($c['tipo']) : ($c['es_hospital'] ? 'HOSPITAL' : ($c['es_venta'] ? 'VENTA' : $c['tipo']));
                $tipoColor = $c['es_recepcion'] ? '#8e44ad' : ($c['es_hospital'] ? '#dc3545' : ($c['es_venta'] ? '#43a047' : '#1e88e5'));
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($c['nombre']) ?></strong>
                </td>
                <td>
                    <span style="background: <?= $tipoColor ?>; color: white; padding: 3px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">
                        <?= htmlspecialchars($tipoLabel) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($c['sexo_permitido']) ?></td>
                <td>
                    <?php if ($c['peso_min'] && $c['peso_max']): ?>
                        <?= number_format($c['peso_min'], 0) ?> - <?= number_format($c['peso_max'], 0) ?> kg
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td style="width: 200px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 3px;">
                        <span><strong><?= $c['bovinos_actuales'] ?></strong> / <?= $c['capacidad_maxima'] ?></span>
                        <span><?= number_format($porcentaje, 0) ?>%</span>
                    </div>
                    <div style="background: var(--suave); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="width: <?= $porcentaje ?>%; height: 100%; background: <?= $colorBarra ?>; transition: width 0.3s;"></div>
                    </div>
                </td>
                <td>
                    <?php
                    $estadoColors = [
                        'Disponible' => '#28a745',
                        'Ocupado' => '#fbc02d',
                        'Mantenimiento' => 'var(--tenue)',
                    ];
                    $color = $estadoColors[$c['estado']] ?? 'var(--tenue)';
                    ?>
                    <span style="color: <?= $color ?>; font-weight: 600;">
                        <?= htmlspecialchars($c['estado']) ?>
                    </span>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>/corrales/ver?id=<?= $c['id'] ?>" class="btn-icon" title="Ver bovinos">
                        Ver
                    </a>

                    <?php if (Permisos::puedeEditar('corrales')): ?>
                        <a href="<?= BASE_URL ?>/corrales/editar?id=<?= $c['id'] ?>" class="btn-icon" title="Editar">
                            Editar
                        </a>
                        <?php if (!$c['es_recepcion']): ?>
                        <a href="<?= BASE_URL ?>/corrales/migrar?id=<?= $c['id'] ?>" class="btn-icon" title="Mover sus bovinos a otro corral o eliminarlo">
                            <?= $c['bovinos_actuales'] > 0 ? 'Migrar' : 'Eliminar' ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/corrales/toggle?id=<?= $c['id'] ?>"
                           class="btn-icon"
                           title="Cambiar estado"
                           onclick="return confirm('Cambiar estado de este corral?')">
                            Estado
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>No hay corrales</h3>
        <p>Crea el primer corral para empezar a clasificar bovinos.</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
