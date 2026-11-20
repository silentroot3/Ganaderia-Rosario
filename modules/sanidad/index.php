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
require_once __DIR__ . '/../../includes/tratamientos_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

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

// Medicamentos en stock (bajo = en o por debajo del minimo; por caducar = 30 dias)
$medicamentos = $db->query("
    SELECT m.id, m.nombre, m.tipo, m.dosis_recomendada,
           COALESCE(i.stock_actual, 0) AS stock, COALESCE(i.stock_minimo, 0) AS minimo,
           COALESCE(i.unidad, 'ml') AS unidad, i.fecha_caducidad
    FROM medicamentos m
    LEFT JOIN inventario_medicamentos i ON i.medicamento_id = m.id
    WHERE m.activo = 1
    ORDER BY (COALESCE(i.stock_actual, 0) <= COALESCE(i.stock_minimo, 0)) DESC, m.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$medBajos = array_filter($medicamentos, fn($m) => $m['stock'] <= $m['minimo']);
$reservado = medicamentoReservado();

// Dosis programadas que faltan por aplicar (el medicamento ya esta apartado)
$dosisPendientes = $db->query("
    SELECT d.*, t.bovino_id, t.num_dosis, t.diagnostico, b.id_siniiga, m.nombre AS medicamento,
           COALESCE(i.unidad, 'ml') AS unidad, c.nombre AS corral
    FROM tratamiento_dosis d
    JOIN tratamientos t ON t.id = d.tratamiento_id
    JOIN bovinos b ON b.id = t.bovino_id
    JOIN medicamentos m ON m.id = t.medicamento_id
    LEFT JOIN inventario_medicamentos i ON i.medicamento_id = t.medicamento_id
    LEFT JOIN corrales c ON c.id = b.corral_id
    WHERE d.estado = 'pendiente'
    ORDER BY d.fecha_programada, d.id
")->fetchAll(PDO::FETCH_ASSOC);
$dosisHoy = array_filter($dosisPendientes, fn($d) => strtotime($d['fecha_programada']) <= strtotime('tomorrow') - 1);
$dosisVisibles = [];
foreach ($dosisPendientes as $d) {
    // Una fila por tratamiento: la siguiente dosis pendiente
    if (!isset($dosisVisibles[$d['tratamiento_id']])) $dosisVisibles[$d['tratamiento_id']] = $d + ['restantes' => 0];
    $dosisVisibles[$d['tratamiento_id']]['restantes']++;
}
?>

<?php if ($medBajos): ?>
<div class="alert alert-error" style="margin-bottom: 20px;">
    <strong>Stock bajo de medicamentos (<?= count($medBajos) ?>):</strong>
    <?= htmlspecialchars(implode(', ', array_map(fn($m) => $m['nombre'] . ' (' . number_format($m['stock'], 0) . ' ' . $m['unidad'] . ')', $medBajos))) ?>.
    <a href="#medicamentos" style="font-weight: 600;">Ver y solicitar</a>
</div>
<?php endif; ?>

<?php if (Permisos::puedeCrear('sanidad')):
    $aretesActivos = $db->query("SELECT id_siniiga FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto') ORDER BY id_siniiga")->fetchAll(PDO::FETCH_COLUMN); ?>
<form method="GET" action="<?= BASE_URL ?>/sanidad/atender" class="barra-medicar">
    <strong>Medicar cualquier bovino</strong> <span style="color:var(--tenue);">(este o no en veterinaria)</span>
    <input type="text" name="arete" list="aretes-activos" required placeholder="Escribe o escanea el arete" autocomplete="off">
    <datalist id="aretes-activos"><?php foreach ($aretesActivos as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?></datalist>
    <button type="submit" class="btn btn-primary">Aplicar medicamento</button>
</form>
<style>
.barra-medicar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:var(--superficie); padding:14px 18px; border-radius:10px;
                 box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:20px; border-left:5px solid #43a047; }
.barra-medicar input { flex:1; min-width:200px; padding:10px; border:1px solid var(--linea); border-radius:6px; font-size:1rem; }
</style>
<?php endif; ?>

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
    <div class="stat-card" style="border-left-color: <?= $medBajos ? '#dc3545' : '#28a745' ?>;">
        <div class="stat-label">Medicamentos bajos</div>
        <div class="stat-value"><?= count($medBajos) ?></div>
        <div class="stat-desc">de <?= count($medicamentos) ?> en inventario</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Hospitales disponibles</div>
        <div class="stat-value"><?= count($hospitales) ?></div>
        <div class="stat-desc">Corrales de cuarentena</div>
    </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<!-- Dosis por aplicar -->
<div class="table-container" id="dosis" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Dosis por aplicar (<?= count($dosisVisibles) ?> tratamientos<?= $dosisHoy ? ', ' . count($dosisHoy) . ' para hoy' : '' ?>)</h2>
    </div>
    <?php if ($dosisVisibles): ?>
    <table>
        <thead>
            <tr><th>Bovino</th><th>Corral</th><th>Medicamento</th><th>Siguiente dosis</th><th>Programada</th><th>Quedan</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($dosisVisibles as $d):
            $atrasada = strtotime($d['fecha_programada']) < time() - 3600;
            $esHoy = strtotime($d['fecha_programada']) <= strtotime('tomorrow') - 1;
        ?>
            <tr style="<?= $atrasada ? 'background: var(--f-rojo);' : ($esHoy ? 'background: var(--f-amarillo);' : '') ?>">
                <td><strong style="font-family: monospace;"><?= htmlspecialchars($d['id_siniiga']) ?></strong><br><small style="color:var(--tenue);"><?= htmlspecialchars(mb_strimwidth($d['diagnostico'], 0, 40, '...')) ?></small></td>
                <td><?= htmlspecialchars($d['corral'] ?? '-') ?></td>
                <td><?= htmlspecialchars($d['medicamento']) ?></td>
                <td><strong><?= $d['numero'] ?> de <?= $d['num_dosis'] ?></strong> · <?= number_format($d['cantidad'], 2) ?> <?= htmlspecialchars($d['unidad']) ?></td>
                <td>
                    <?= date('d/m/Y H:i', strtotime($d['fecha_programada'])) ?>
                    <?php if ($atrasada): ?><br><small style="color:#dc3545;font-weight:600;">Atrasada</small><?php elseif ($esHoy): ?><br><small style="color:var(--t-amarillo);font-weight:600;">Hoy</small><?php endif; ?>
                </td>
                <td><?= $d['restantes'] ?> (<?= number_format($d['restantes'] * $d['cantidad'], 2) ?> <?= htmlspecialchars($d['unidad']) ?> apartados)</td>
                <td style="white-space: nowrap;">
                    <form method="POST" action="<?= BASE_URL ?>/sanidad/dosis" style="display:inline;">
                        <?= csrfField() ?><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="dosis_id" value="<?= $d['id'] ?>">
                        <button class="btn btn-primary btn-sm" type="submit">Aplicar</button>
                    </form>
                    <form method="POST" action="<?= BASE_URL ?>/sanidad/dosis" style="display:inline;">
                        <?= csrfField() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="tratamiento_id" value="<?= $d['tratamiento_id'] ?>">
                        <button class="btn btn-secondary btn-sm" type="submit" onclick="return confirm('¿Cancelar las <?= $d['restantes'] ?> dosis que faltan? El medicamento apartado regresa al inventario.')">Cancelar restantes</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p style="padding: 20px; color: var(--tenue);">No hay dosis pendientes. Al recetar varias dosis (ej. 5 dosis, 1 al dia) apareceran aqui para irlas aplicando.</p>
    <?php endif; ?>
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
                    <?php if (($b['categoria'] ?? 'Sin clasificar') === 'Sin clasificar'): ?><br><span style="background:var(--f-amarillo);color:var(--t-amarillo);padding:1px 7px;border-radius:10px;font-size:0.72rem;font-weight:600;">Sin clasificar</span><?php endif; ?>
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
                        <a href="<?= BASE_URL ?>/bovinos/muerte?id=<?= $b['id'] ?>" class="btn btn-sm" style="background: #dc3545; color: white;">Murio</a>
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
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
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
                    <div style="background: var(--suave); height: 6px; border-radius: 3px; margin-top: 5px; overflow: hidden;">
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

<!-- Medicamentos en stock -->
<div class="table-container" id="medicamentos" style="margin-top: 25px;">
    <div class="table-header">
        <h2>Medicamentos en stock (<?= count($medicamentos) ?>)</h2>
        <div style="display: flex; gap: 8px;">
            <a href="<?= BASE_URL ?>/tratamientos/inventario" class="btn btn-secondary btn-sm">Inventario completo</a>
            <a href="<?= BASE_URL ?>/solicitudes/crear?tipo=medicamento" class="btn btn-primary btn-sm">Solicitar compra</a>
        </div>
    </div>
    <table>
        <thead>
            <tr><th>Medicamento</th><th>Tipo</th><th>Dosis</th><th>Disponible</th><th>Apartado</th><th>Minimo</th><th>Caducidad</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($medicamentos as $m):
                $bajo = $m['stock'] <= $m['minimo'];
                $porCaducar = $m['fecha_caducidad'] && strtotime($m['fecha_caducidad']) <= strtotime('+30 days');
                $pct = $m['minimo'] > 0 ? min(100, $m['stock'] / ($m['minimo'] * 3) * 100) : 100;
            ?>
            <tr style="<?= $bajo ? 'background: var(--f-rojo);' : '' ?>">
                <td><strong><?= htmlspecialchars($m['nombre']) ?></strong></td>
                <td><?= htmlspecialchars(ucfirst($m['tipo'])) ?></td>
                <td><?= htmlspecialchars($m['dosis_recomendada'] ?? '-') ?></td>
                <td style="width: 160px; <?= $bajo ? 'color: #dc3545; font-weight: 700;' : '' ?>">
                    <?= number_format($m['stock'], 1) ?> <?= htmlspecialchars($m['unidad']) ?>
                    <div style="background: var(--suave); height: 5px; border-radius: 3px; margin-top: 4px; overflow: hidden;">
                        <div style="width: <?= $pct ?>%; height: 100%; background: <?= $bajo ? '#dc3545' : ($pct < 60 ? '#fbc02d' : '#28a745') ?>;"></div>
                    </div>
                </td>
                <td><?= !empty($reservado[$m['id']]) ? '<strong style="color:#1e88e5;">' . number_format($reservado[$m['id']], 1) . ' ' . htmlspecialchars($m['unidad']) . '</strong>' : '-' ?></td>
                <td><?= number_format($m['minimo'], 0) ?> <?= htmlspecialchars($m['unidad']) ?></td>
                <td style="<?= $porCaducar ? 'color: var(--t-amarillo); font-weight: 700;' : '' ?>">
                    <?= $m['fecha_caducidad'] ? date('d/m/Y', strtotime($m['fecha_caducidad'])) : '-' ?>
                </td>
                <td>
                    <?php if ($bajo): ?>
                        <span style="color: #dc3545; font-weight: 600;">Stock bajo</span>
                    <?php elseif ($porCaducar): ?>
                        <span style="color: var(--t-amarillo); font-weight: 600;">Por caducar</span>
                    <?php else: ?>
                        <span style="color: #28a745; font-weight: 600;">OK</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($bajo || $porCaducar): ?>
                        <a href="<?= BASE_URL ?>/solicitudes/crear?tipo=medicamento&id=<?= $m['id'] ?>" class="btn btn-sm btn-primary">Solicitar</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
