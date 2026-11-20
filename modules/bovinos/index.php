<?php
// ============================================
// BOVINOS - Inventario general de animales
// ============================================

$titulo = 'Bovinos';
$modulo = 'bovinos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$estado = $_GET['estado'] ?? 'activos';
$sexo = $_GET['sexo'] ?? '';
$corral_id = (int)($_GET['corral_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$where = []; $params = [];
if ($estado === 'activos') $where[] = "b.estado NOT IN ('vendido','muerto','devuelto')";
elseif ($estado !== 'todos' && $estado !== '') { $where[] = "b.estado = ?"; $params[] = $estado; }
if (in_array($sexo, ['M', 'H'], true)) { $where[] = "b.sexo = ?"; $params[] = $sexo; }
if ($corral_id) { $where[] = "b.corral_id = ?"; $params[] = $corral_id; }
if ($q !== '') { $where[] = "b.id_siniiga LIKE ?"; $params[] = "%$q%"; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT b.id, b.id_siniiga, b.sexo, b.raza, b.categoria, b.edad_meses, b.estado, b.peso_entrada, b.en_hospital, c.nombre AS corral,
           COALESCE((SELECT p.peso FROM pesajes p WHERE p.bovino_id = b.id ORDER BY p.fecha DESC, p.id DESC LIMIT 1), b.peso_entrada) AS peso_actual,
           EXISTS (SELECT 1 FROM tratamientos t WHERE t.bovino_id = b.id AND t.fecha_fin_retiro >= CURDATE()) AS en_retiro
    FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id
    $sqlWhere ORDER BY b.created_at DESC, b.id_siniiga LIMIT 500
");
$stmt->execute($params);
$bovinos = $stmt->fetchAll();

$corrales = $db->query("SELECT id, nombre FROM corrales ORDER BY nombre")->fetchAll();
$conteo = $db->query("SELECT estado, COUNT(*) n FROM bovinos GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$activos = 0; foreach ($conteo as $e => $n) if (!in_array($e, ['vendido','muerto','devuelto'], true)) $activos += $n;
?>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success" style="margin-bottom:20px;"><?= h($_GET['msg']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-error" style="margin-bottom:20px;"><?= h($_GET['error']) ?></div><?php endif; ?>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card green"><div class="stat-label">En el rancho</div><div class="stat-value"><?= $activos ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Vendidos</div><div class="stat-value"><?= (int)($conteo['vendido'] ?? 0) ?></div></div>
    <div class="stat-card" style="border-left-color:#343a40;"><div class="stat-label">Muertos / devueltos</div><div class="stat-value"><?= (int)($conteo['muerto'] ?? 0) + (int)($conteo['devuelto'] ?? 0) ?></div></div>
</div>

<form method="GET" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar arete" style="padding:8px;border:1px solid var(--linea);border-radius:6px;">
    <select name="estado" style="padding:8px;border:1px solid var(--linea);border-radius:6px;">
        <?php foreach (['activos' => 'En el rancho', 'todos' => 'Todos', 'en_corral' => 'En corral', 'en_cuarentena' => 'Cuarentena / hospital', 'recibido' => 'Sin corral', 'vendido' => 'Vendidos', 'muerto' => 'Muertos', 'devuelto' => 'Devueltos'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <select name="sexo" style="padding:8px;border:1px solid var(--linea);border-radius:6px;"><option value="">Ambos sexos</option><option value="M" <?= $sexo === 'M' ? 'selected' : '' ?>>Machos</option><option value="H" <?= $sexo === 'H' ? 'selected' : '' ?>>Hembras</option></select>
    <select name="corral_id" style="padding:8px;border:1px solid var(--linea);border-radius:6px;"><option value="">Todos los corrales</option>
        <?php foreach ($corrales as $c): ?><option value="<?= $c['id'] ?>" <?= $corral_id == $c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary btn-sm">Filtrar</button>
</form>

<div class="table-container">
    <div class="table-header"><h2>Bovinos (<?= count($bovinos) ?><?= count($bovinos) === 500 ? '+' : '' ?>)</h2></div>
    <?php if ($bovinos): ?>
    <table>
        <thead><tr><th>Arete</th><th>Sexo</th><th>Raza</th><th>Categoria</th><th>Edad</th><th>Corral</th><th>Peso actual</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bovinos as $b): ?>
            <tr>
                <td><strong><?= h($b['id_siniiga']) ?></strong><?php if (($b['categoria'] ?? 'Sin clasificar') === 'Sin clasificar'): ?><br><span style="background:var(--f-amarillo);color:var(--t-amarillo);padding:1px 7px;border-radius:10px;font-size:0.72rem;font-weight:600;">Sin clasificar</span><?php endif; ?></td>
                <td><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?></td>
                <td><?= h($b['raza']) ?></td>
                <td><?= h($b['categoria']) ?></td>
                <td><?= $b['edad_meses'] ? (int)$b['edad_meses'] . ' m' : '-' ?></td>
                <td><?= h($b['corral']) ?><?= $b['en_hospital'] ? ' ' . pill('Hospital', 'var(--f-rojo)', 'var(--t-rojo)') : '' ?></td>
                <td><?= $b['peso_actual'] !== null ? number_format($b['peso_actual'], 1) : '-' ?></td>
                <td><?= pillEstado($b['estado']) ?><?= $b['en_retiro'] ? ' ' . pill('Retiro', 'var(--f-amarillo)', 'var(--t-amarillo)') : '' ?></td>
                <td style="white-space:nowrap;"><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($b['id_siniiga']) ?>">Hoja de vida</a>
                    <?php if (!in_array($b['estado'], ['vendido', 'muerto', 'devuelto'], true)): ?> <?php if (Permisos::puedeCrear('sanidad') || Permisos::puedeCrear('clasificacion')): ?><a href="<?= BASE_URL ?>/bovinos/muerte?id=<?= $b['id'] ?>" class="btn-icon" style="color:#dc3545;">Registrar muerte</a><?php endif; ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">Sin resultados.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
