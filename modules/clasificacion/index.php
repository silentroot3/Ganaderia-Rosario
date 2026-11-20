<?php
// ============================================
// CLASIFICACION - Bovinos en RECEP esperando clasificacion
// El clasificador decide categoria y corral de cada animal (nada se clasifica solo)
// y puede mover cualquier bovino ya clasificado.
// ============================================

$titulo = 'Clasificacion';
$modulo = 'clasificacion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';

$db = Database::getInstance()->getConnection();

$filtroSexo = in_array($_GET['sexo'] ?? '', ['M', 'H'], true) ? $_GET['sexo'] : '';

// Bovinos por clasificar: en un corral de recepcion (RECEP) o sin corral, y fuera del hospital
$sql = "
    SELECT b.*, co.nombre AS corral_nombre,
           r.folio AS folio_recepcion,
           c.origen_nombre AS proveedor
    FROM bovinos b
    LEFT JOIN corrales co ON co.id = b.corral_id
    LEFT JOIN recepciones r ON b.recepcion_id = r.id
    LEFT JOIN compras c ON r.compra_id = c.id
    WHERE (b.corral_id IS NULL OR co.es_recepcion = 1)
      AND b.en_hospital = 0
      AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
";
if ($filtroSexo) $sql .= " AND b.sexo = " . $db->quote($filtroSexo);
$sql .= " ORDER BY b.llego_enfermo DESC, b.sexo, b.created_at ASC";
$bovinosSinClasificar = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$totalSinClasificar = count($bovinosSinClasificar);

$porSexo = $db->query("
    SELECT b.sexo, COUNT(*) FROM bovinos b LEFT JOIN corrales co ON co.id = b.corral_id
    WHERE (b.corral_id IS NULL OR co.es_recepcion = 1) AND b.en_hospital = 0
      AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
    GROUP BY b.sexo
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalEnHospital = $db->query("SELECT COUNT(*) FROM bovinos WHERE en_hospital = 1")->fetchColumn();

$clasificadosHoy = $db->query("
    SELECT COUNT(*) FROM movimientos_bovino
    WHERE tipo_movimiento IN ('clasificacion', 'traslado') AND DATE(fecha) = CURRENT_DATE()
")->fetchColumn();

// Buscar cualquier bovino para moverlo / reclasificarlo
$buscar = trim($_GET['buscar'] ?? '');
$encontrados = [];
if ($buscar !== '') {
    $stmt = $db->prepare("
        SELECT b.*, co.nombre AS corral_nombre FROM bovinos b
        LEFT JOIN corrales co ON co.id = b.corral_id
        WHERE b.id_siniiga LIKE ? AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
        ORDER BY b.id_siniiga LIMIT 30
    ");
    $stmt->execute(['%' . $buscar . '%']);
    $encontrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Corrales destino con espacio (sin hospital, venta ni recepcion)
$corralesDisponibles = $db->query("
    SELECT * FROM corrales
    WHERE estado != 'Mantenimiento' AND es_hospital = 0 AND es_venta = 0 AND es_recepcion = 0
      AND ocupacion_actual < capacidad_maxima
    ORDER BY sexo_permitido, peso_min
")->fetchAll(PDO::FETCH_ASSOC);
$totalCorralesDisponibles = count($corralesDisponibles);
?>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['msg'] === 'asignado' ? 'Bovino clasificado y movido de corral.' : $_GET['msg']) ?></div>
<?php endif; ?>

<?php
// Se pesan cada 15 dias: corrales con animales que ya les toca
$diasPesar = max(1, (int)configValor('alerta_dias_sin_pesar', 15));
$porPesar = $db->query("
    SELECT c.id, c.nombre, COUNT(*) AS n
    FROM bovinos b JOIN corrales c ON c.id = b.corral_id
    WHERE b.estado = 'en_corral' AND b.en_hospital = 0 AND c.es_recepcion = 0 AND c.es_hospital = 0
      AND DATEDIFF(CURDATE(), COALESCE((SELECT MAX(p.fecha) FROM pesajes p WHERE p.bovino_id = b.id), DATE(b.created_at))) >= $diasPesar
    GROUP BY c.id, c.nombre ORDER BY c.nombre
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="barra-pesar">
    <a href="<?= BASE_URL ?>/clasificacion/pesar" class="btn btn-primary">Volver a pesar un corral</a>
    <?php if ($porPesar): ?>
        <span><strong><?= array_sum(array_column($porPesar, 'n')) ?> animales</strong> llevan <?= $diasPesar ?> dias o mas sin pesarse:</span>
        <?php foreach ($porPesar as $pp): ?><a class="chip-pesar" href="<?= BASE_URL ?>/clasificacion/pesar?corral=<?= $pp['id'] ?>"><?= h($pp['nombre']) ?> · <?= $pp['n'] ?></a><?php endforeach; ?>
    <?php else: ?>
        <span style="color:#28a745;font-weight:600;">Todos los corrales estan pesados (cada <?= $diasPesar ?> dias)</span>
    <?php endif; ?>
</div>
<style>
.barra-pesar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
.chip-pesar { background:var(--f-amarillo); color:var(--t-amarillo); border:1px solid var(--linea); border-radius:14px; padding:4px 12px; font-weight:600; text-decoration:none; font-size:0.85rem; transition:transform .15s; }
.chip-pesar:hover { transform:translateY(-1px); background:var(--f-amarillo); }
</style>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card" style="border-left-color: #dc3545; border-left-width: 6px;">
        <div class="stat-label">Por clasificar</div>
        <div class="stat-value"><?= array_sum($porSexo) ?></div>
        <div class="stat-desc"><?= (int)($porSexo['M'] ?? 0) ?> machos (RECEP-1) / <?= (int)($porSexo['H'] ?? 0) ?> hembras (RECEP-2)</div>
    </div>

    <div class="stat-card yellow">
        <div class="stat-label">En hospital</div>
        <div class="stat-value"><?= $totalEnHospital ?></div>
        <div class="stat-desc">Solo entran ya clasificados</div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Corrales disponibles</div>
        <div class="stat-value"><?= $totalCorralesDisponibles ?></div>
        <div class="stat-desc">Con espacio</div>
    </div>

    <div class="stat-card blue">
        <div class="stat-label">Movidos hoy</div>
        <div class="stat-value"><?= $clasificadosHoy ?></div>
        <div class="stat-desc">Clasificaciones y traslados</div>
    </div>
</div>

<!-- Bovinos por clasificar -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header" style="flex-wrap: wrap; gap: 10px;">
        <h2>Por clasificar (<?= $totalSinClasificar ?>)</h2>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/clasificacion" class="btn btn-sm <?= $filtroSexo === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos</a>
            <a href="<?= BASE_URL ?>/clasificacion?sexo=M" class="btn btn-sm <?= $filtroSexo === 'M' ? 'btn-primary' : 'btn-secondary' ?>">Machos</a>
            <a href="<?= BASE_URL ?>/clasificacion?sexo=H" class="btn btn-sm <?= $filtroSexo === 'H' ? 'btn-primary' : 'btn-secondary' ?>">Hembras</a>
            <input type="text" id="buscarBovino" placeholder="Filtrar por SINIIGA..." onkeyup="filtrarBovinos()"
                   style="padding: 8px 12px; border: 1px solid var(--linea); border-radius: 6px; width: 220px;">
        </div>
    </div>

    <?php if ($totalSinClasificar > 0): ?>
    <table id="tablaBovinos">
        <thead>
            <tr>
                <th>SINIIGA</th>
                <th>Sexo</th>
                <th>Edad</th>
                <th>Peso</th>
                <th>Corral actual</th>
                <th>Proveedor</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bovinosSinClasificar as $b): ?>
            <tr class="bovino-row" data-siniiga="<?= htmlspecialchars($b['id_siniiga']) ?>">
                <td>
                    <strong style="font-family: monospace;"><?= htmlspecialchars($b['id_siniiga']) ?></strong>
                    <?php if ($b['llego_enfermo'] && !$b['fecha_entrada_hospital']): ?>
                        <br><span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:600;">Llego enfermo: <?= htmlspecialchars($b['motivo_llegada'] ?? '') ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-' ?></td>
                <td><?= $b['peso_entrada'] ? number_format($b['peso_entrada'], 1) . ' kg' : '-' ?></td>
                <td><?= htmlspecialchars($b['corral_nombre'] ?? 'Sin corral') ?></td>
                <td><?= htmlspecialchars($b['proveedor'] ?? '-') ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/clasificacion/asignar?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm">Clasificar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>No hay bovinos por clasificar</h3>
        <p>Cuando se reciba una compra, los animales llegaran a RECEP-1 (machos) y RECEP-2 (hembras).</p>
    </div>
    <?php endif; ?>
</div>

<!-- Mover cualquier bovino -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Mover o reclasificar un bovino</h2>
        <form method="GET" style="display: flex; gap: 8px;">
            <input type="text" name="buscar" value="<?= htmlspecialchars($buscar) ?>" placeholder="SINIIGA del bovino"
                   style="padding: 8px 12px; border: 1px solid var(--linea); border-radius: 6px; width: 220px; font-family: monospace;">
            <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
        </form>
    </div>
    <?php if ($buscar !== ''): ?>
        <?php if ($encontrados): ?>
        <table>
            <thead><tr><th>SINIIGA</th><th>Sexo</th><th>Categoria</th><th>Edad</th><th>Peso</th><th>Corral</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($encontrados as $b): ?>
                <tr>
                    <td><strong style="font-family: monospace;"><?= htmlspecialchars($b['id_siniiga']) ?></strong></td>
                    <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                    <td><?= ($b['categoria'] ?? 'Sin clasificar') === 'Sin clasificar' ? '<span style="color:#b26a00;font-weight:600;">Sin clasificar</span>' : htmlspecialchars($b['categoria']) ?></td>
                    <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-' ?></td>
                    <td><?= $b['peso_entrada'] ? number_format($b['peso_entrada'], 1) . ' kg' : '-' ?></td>
                    <td><?= htmlspecialchars($b['corral_nombre'] ?? 'Sin corral') ?><?= $b['en_hospital'] ? ' (hospital)' : '' ?></td>
                    <td>
                        <?php if ($b['en_hospital']): ?>
                            <small style="color: var(--tenue);">El veterinario debe darlo de alta</small>
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>/clasificacion/asignar?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm">Editar / mover</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div style="padding: 25px; text-align: center; color: var(--tenue);">No se encontro ningun bovino con "<?= htmlspecialchars($buscar) ?>".</div>
        <?php endif; ?>
    <?php else: ?>
        <p style="padding: 15px 20px; color: var(--tenue);">Busca por arete para cambiar de corral o de categoria a cualquier bovino. Para mover un corral completo usa <a href="<?= BASE_URL ?>/corrales">Corrales → Migrar</a>.</p>
    <?php endif; ?>
</div>

<!-- Corrales disponibles -->
<div class="table-container">
    <div class="table-header">
        <h2>Corrales con espacio disponible (<?= $totalCorralesDisponibles ?>)</h2>
    </div>

    <?php if ($totalCorralesDisponibles > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Corral</th>
                <th>Tipo</th>
                <th>Sexo</th>
                <th>Rango peso</th>
                <th>Espacio</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($corralesDisponibles as $c):
                $disponibles = $c['capacidad_maxima'] - $c['ocupacion_actual'];
                $porcentaje = ($c['ocupacion_actual'] / $c['capacidad_maxima']) * 100;
                $colorBarra = $porcentaje >= 90 ? '#dc3545' : ($porcentaje >= 70 ? '#fbc02d' : '#28a745');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
                <td><?= htmlspecialchars($c['tipo']) ?></td>
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
                        <span><?= $c['ocupacion_actual'] ?> / <?= $c['capacidad_maxima'] ?></span>
                        <span><?= $disponibles ?> libres</span>
                    </div>
                    <div style="background: var(--suave); height: 6px; border-radius: 3px; overflow: hidden;">
                        <div style="width: <?= $porcentaje ?>%; height: 100%; background: <?= $colorBarra ?>;"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
        <h3>No hay corrales disponibles</h3>
        <p>Todos los corrales estan llenos o en mantenimiento.</p>
        <a href="<?= BASE_URL ?>/corrales/crear" class="btn btn-primary" style="margin-top: 20px;">+ Crear corral</a>
    </div>
    <?php endif; ?>
</div>

<script>
function filtrarBovinos() {
    const busqueda = document.getElementById('buscarBovino').value.toLowerCase();
    document.querySelectorAll('.bovino-row').forEach(function (row) {
        row.style.display = row.getAttribute('data-siniiga').toLowerCase().includes(busqueda) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
