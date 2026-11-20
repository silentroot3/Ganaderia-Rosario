<?php
// ============================================
// COSTOS - Gasto acumulado por animal (HU-013)
// compra + alimentacion + medicinas + otros, y costo por kilo
// ============================================

$titulo = 'Costos por animal';
$modulo = 'costos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$estado = $_GET['estado'] ?? 'activos';
$buscar = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($estado === 'activos') $where[] = "b.estado NOT IN ('vendido','muerto','devuelto')";
elseif ($estado === 'vendidos') $where[] = "b.estado = 'vendido'";
elseif ($estado === 'muertos') $where[] = "b.estado = 'muerto'";
if ($buscar !== '') { $where[] = "b.id_siniiga LIKE ?"; $params[] = "%$buscar%"; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT b.id, b.id_siniiga, b.categoria, b.estado, b.peso_entrada, c.nombre AS corral,
           COALESCE((SELECT p.peso FROM pesajes p WHERE p.bovino_id = b.id ORDER BY p.fecha DESC, p.id DESC LIMIT 1), b.peso_entrada) AS peso_actual
    FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id
    $sqlWhere ORDER BY b.id_siniiga LIMIT 500
");
$stmt->execute($params);
$bovinos = $stmt->fetchAll();

$costos = costosBovinos(array_map(fn($b) => (int)$b['id'], $bovinos));
$tot = ['compra' => 0, 'alimentacion' => 0, 'veterinario' => 0, 'otros' => 0, 'total' => 0];
foreach ($costos as $c) foreach ($tot as $k => $_) $tot[$k] += $c[$k];

$generales = (float)$db->query("SELECT COALESCE(SUM(monto),0) FROM costos WHERE bovino_id IS NULL")->fetchColumn();
$sinRepartir = gastosGeneralesSinRepartir();
?>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card blue"><div class="stat-label">Compra</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tot['compra']) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Alimentacion</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tot['alimentacion']) ?></div></div>
    <div class="stat-card yellow"><div class="stat-label">Veterinario</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tot['veterinario']) ?></div></div>
    <div class="stat-card purple"><div class="stat-label">Total invertido</div><div class="stat-value" style="font-size:1.5rem;"><?= dinero($tot['total']) ?></div><div class="stat-desc">Incluye <?= dinero($generales - $sinRepartir) ?> de gastos generales repartidos<?= $sinRepartir > 0 ? ' · ' . dinero($sinRepartir) . ' sin repartir' : '' ?></div></div>
</div>

<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <a href="<?= BASE_URL ?>/costos/gasto" class="btn btn-primary">+ Registrar gasto</a>
    <?php foreach (['activos' => 'En el rancho', 'vendidos' => 'Vendidos', 'muertos' => 'Muertos (perdida)', 'todos' => 'Todos'] as $k => $l): ?>
        <a href="<?= BASE_URL ?>/costos?estado=<?= $k ?>" class="btn btn-sm <?= $estado === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <form method="GET" style="display:flex;gap:6px;margin-left:auto;">
        <input type="hidden" name="estado" value="<?= h($estado) ?>">
        <input type="text" name="q" value="<?= h($buscar) ?>" placeholder="Buscar arete" style="padding:8px;border:1px solid var(--linea);border-radius:6px;">
        <button class="btn btn-secondary btn-sm">Buscar</button>
    </form>
</div>

<div class="table-container">
    <div class="table-header"><h2>Costo por bovino (<?= count($bovinos) ?>)</h2></div>
    <?php if ($bovinos): ?>
    <table>
        <thead><tr><th>Arete</th><th>Corral</th><th>Estado</th><th>Peso</th><th>Compra</th><th>Alimento</th><th>Medicina</th><th>Otros</th><th>Total</th><th>$/kg</th><th>$/kg ganado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bovinos as $b):
            $c = $costos[$b['id']] ?? ['compra'=>0,'alimentacion'=>0,'veterinario'=>0,'otros'=>0,'total'=>0];
            $peso = (float)$b['peso_actual'];
            $ganado = $peso - (float)$b['peso_entrada'];
            $costoKg = $peso > 0 ? $c['total'] / $peso : null;
            $produccion = $c['alimentacion'] + $c['veterinario'] + $c['otros'];
            $costoKgGanado = $ganado > 0 ? $produccion / $ganado : null; ?>
            <tr>
                <td><strong><?= h($b['id_siniiga']) ?></strong></td>
                <td><?= h($b['corral']) ?></td>
                <td><?= pillEstado($b['estado']) ?></td>
                <td><?= number_format($peso, 1) ?></td>
                <td><?= dinero($c['compra']) ?></td>
                <td><?= dinero($c['alimentacion']) ?></td>
                <td><?= dinero($c['veterinario']) ?></td>
                <td><?= dinero($c['otros']) ?></td>
                <td><strong><?= dinero($c['total']) ?></strong></td>
                <td><?= $costoKg !== null ? dinero($costoKg) : '-' ?></td>
                <td><?= $costoKgGanado !== null ? dinero($costoKgGanado) : '-' ?></td>
                <td><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/costos/animal?id=<?= $b['id'] ?>">Detalle</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">Sin resultados.</div><?php endif; ?>
</div>
<p style="color:var(--tenue);font-size:0.85rem;margin-top:10px;">
    $/kg = total invertido entre el peso actual. $/kg ganado = (alimento + medicina + otros) entre los kilos ganados desde la entrada.
    La comida servida a un corral se reparte entre los animales que estaban en el corral ese dia.
    Otros = gastos del animal + su parte de los gastos generales del rancho (repartidos entre los animales que habia ese dia).
</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
