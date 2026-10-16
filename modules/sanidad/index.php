<?php
// ============================================
// MODULO VETERINARIO
// Lista de bovinos en hospital
// ============================================

$titulo = 'Modulo Veterinario';
$modulo = 'sanidad';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/categorizacion.php';

$db = Database::getInstance()->getConnection();

// Bovinos en hospital
$stmt = $db->query("
    SELECT b.*, 
           c.nombre AS corral_nombre,
           r.folio AS folio_recepcion,
           p.nombre AS proveedor_nombre,
           u.nombre AS usuario_registro_nombre,
           u.apellido AS usuario_registro_apellido
    FROM bovinos b
    LEFT JOIN corrales c ON b.corral_id = c.id
    LEFT JOIN recepciones r ON b.recepcion_id = r.id
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    LEFT JOIN usuarios u ON r.usuario_id = u.id
    WHERE b.en_hospital = 1
    ORDER BY b.fecha_entrada_hospital DESC
");
$bovinosHospital = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadisticas
$totalHospital = count($bovinosHospital);

// Corrales de hospital
$stmt = $db->query("
    SELECT id, nombre, ocupacion_actual, capacidad_maxima
    FROM corrales
    WHERE es_hospital = 1
    ORDER BY nombre
");
$hospitales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bovinos con tratamiento activo (conteo)
$stmt = $db->query("
    SELECT COUNT(DISTINCT bovino_id) AS total
    FROM tratamientos
    WHERE fecha_fin_retiro IS NULL OR fecha_fin_retiro >= CURRENT_DATE()
");
$conTratamiento = $stmt->fetchColumn();

// Bovinos dados de alta en la ultima semana
$stmt = $db->query("
    SELECT COUNT(*) FROM bovinos
    WHERE en_hospital = 0 
      AND fecha_salida_hospital IS NOT NULL
      AND fecha_salida_hospital >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$altasSemana = $stmt->fetchColumn();
?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card" style="border-left-color: #dc3545;">
        <div class="stat-label">En hospital</div>
        <div class="stat-value"><?= $totalHospital ?></div>
        <div class="stat-desc">Bovinos bajo atencion</div>
    </div>
    <div class="stat-card yellow">
        <div class="stat-label">Con tratamiento</div>
        <div class="stat-value"><?= $conTratamiento ?></div>
        <div class="stat-desc">Medicacion activa</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Altas esta semana</div>
        <div class="stat-value"><?= $altasSemana ?></div>
        <div class="stat-desc">Recuperados</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Hospitales disponibles</div>
        <div class="stat-value"><?= count($hospitales) ?></div>
        <div class="stat-desc">Corrales de cuarentena</div>
    </div>
</div>

<!-- Bovinos en hospital -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Bovinos en Hospital (<?= $totalHospital ?>)</h2>
    </div>
    
    <?php if (count($bovinosHospital) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>SINIIGA</th>
                <th>Sexo</th>
                <th>Peso</th>
                <th>Categoria</th>
                <th>Corral</th>
                <th>Motivo</th>
                <th>Fecha ingreso</th>
                <th>Recepcion</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bovinosHospital as $b): 
                $cat = categorizarBovino($b['sexo'], $b['peso_entrada']);
                $diasEnHospital = 0;
                if ($b['fecha_entrada_hospital']) {
                    $fecha = new DateTime($b['fecha_entrada_hospital']);
                    $hoy = new DateTime();
                    $diasEnHospital = $fecha->diff($hoy)->days;
                }
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($b['id_siniiga']) ?></strong>
                </td>
                <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                <td><?= number_format($b['peso_entrada'], 1) ?> kg</td>
                <td><?= htmlspecialchars($cat['subcategoria']) ?></td>
                <td>
                    <span class="badge-rol badge-recepcionista">
                        <?= htmlspecialchars($b['corral_nombre'] ?? 'HOSPITAL') ?>
                    </span>
                </td>
                <td>
                    <strong style="color: #dc3545;"><?= htmlspecialchars($b['motivo_hospital'] ?? 'Sin especificar') ?></strong>
                </td>
                <td>
                    <?= $b['fecha_entrada_hospital'] ? date('d/m/Y H:i', strtotime($b['fecha_entrada_hospital'])) : 'N/A' ?>
                    <?php if ($diasEnHospital > 0): ?>
                        <br><small>(<?= $diasEnHospital ?> dias)</small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($b['folio_recepcion'] ?? 'N/A') ?></td>
                <td>
                    <?php if (Permisos::puedeCrear('sanidad')): ?>
                        <a href="<?= BASE_URL ?>/sanidad/atender?id=<?= $b['id'] ?>" class="btn btn-sm btn-primary">
                            Atender
                        </a>
                        <a href="<?= BASE_URL ?>/sanidad/alta?id=<?= $b['id'] ?>" class="btn btn-sm" style="background: #28a745; color: white;">
                            Alta
                        </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/sanidad/historial?id=<?= $b['id'] ?>" class="btn btn-sm btn-secondary">
                        Historial
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: #999;">
        <h3>No hay bovinos en hospital</h3>
        <p>Todos los bovinos estan sanos.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Estado de hospitales -->
<div class="table-container">
    <div class="table-header">
        <h2>Estado de Hospitales</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>Corral</th>
                <th>Ocupacion</th>
                <th>Disponible</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($hospitales as $h): 
                $disponible = $h['capacidad_maxima'] - $h['ocupacion_actual'];
                $porcentaje = ($h['ocupacion_actual'] / $h['capacidad_maxima']) * 100;
                $color = $porcentaje >= 90 ? '#dc3545' : ($porcentaje >= 70 ? '#fbc02d' : '#28a745');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($h['nombre']) ?></strong></td>
                <td>
                    <?= $h['ocupacion_actual'] ?> / <?= $h['capacidad_maxima'] ?>
                    <div style="background: #e0e0e0; height: 6px; border-radius: 3px; margin-top: 5px; overflow: hidden;">
                        <div style="width: <?= $porcentaje ?>%; height: 100%; background: <?= $color ?>;"></div>
                    </div>
                </td>
                <td><?= $disponible ?> espacios</td>
                <td>
                    <?php if ($disponible > 0): ?>
                        <span style="color: #28a745; font-weight: 600;">Disponible</span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-weight: 600;">Lleno</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
