<?php
// ============================================
// SEGUIMIENTO - Pesajes de control y ganancia diaria de peso (HU-012)
// ============================================

$titulo = 'Seguimiento de peso';
$modulo = 'seguimiento';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';

$db = Database::getInstance()->getConnection();
$corral_id = (int)($_GET['corral_id'] ?? 0);
$diasAlerta = (int)(function_exists('configValor') ? configValor('alerta_dias_sin_pesar', 14) : 14);

$params = [];
$filtro = '';
if ($corral_id) { $filtro = 'AND b.corral_id = ?'; $params[] = $corral_id; }

$stmt = $db->prepare("
    SELECT b.id, b.id_siniiga, b.sexo, b.categoria, b.peso_entrada, b.created_at, c.nombre AS corral,
           up.peso AS peso_actual, up.fecha AS fecha_ultimo,
           (SELECT COUNT(*) FROM pesajes p WHERE p.bovino_id = b.id) AS n_pesajes,
           (SELECT p2.gdp FROM pesajes p2 WHERE p2.bovino_id = b.id ORDER BY p2.fecha DESC, p2.id DESC LIMIT 1) AS gdp_ultimo,
           DATEDIFF(CURDATE(), COALESCE(up.fecha, DATE(b.created_at))) AS dias_sin_pesar
    FROM bovinos b
    LEFT JOIN corrales c ON c.id = b.corral_id
    LEFT JOIN pesajes up ON up.id = (SELECT p3.id FROM pesajes p3 WHERE p3.bovino_id = b.id ORDER BY p3.fecha DESC, p3.id DESC LIMIT 1)
    WHERE b.estado = 'en_corral' $filtro
    ORDER BY c.nombre, b.id_siniiga
");
$stmt->execute($params);
$bovinos = $stmt->fetchAll();

$corrales = $db->query("SELECT id, nombre FROM corrales WHERE es_hospital = 0 AND es_venta = 0 ORDER BY nombre")->fetchAll();

$sumGdp = 0; $nGdp = 0; $sinPesar = 0;
foreach ($bovinos as $b) {
    if ($b['gdp_ultimo'] !== null) { $sumGdp += (float)$b['gdp_ultimo']; $nGdp++; }
    if ($b['dias_sin_pesar'] >= $diasAlerta) $sinPesar++;
}
?>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card blue"><div class="stat-label">Bovinos en seguimiento</div><div class="stat-value"><?= count($bovinos) ?></div></div>
    <div class="stat-card green"><div class="stat-label">GDP promedio</div><div class="stat-value"><?= $nGdp ? number_format($sumGdp / $nGdp, 2) : '-' ?></div><div class="stat-desc">kg por dia (ultimo pesaje)</div></div>
    <div class="stat-card yellow"><div class="stat-label">Sin pesar (<?= $diasAlerta ?>+ d)</div><div class="stat-value"><?= $sinPesar ?></div></div>
</div>

<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <?php if (Permisos::puedeCrear('seguimiento')): ?>
        <a href="<?= BASE_URL ?>/seguimiento/registrar<?= $corral_id ? '?corral_id=' . $corral_id : '' ?>" class="btn btn-primary">+ Registrar pesajes</a>
    <?php endif; ?>
    <form method="GET" style="display:flex;gap:8px;">
        <select name="corral_id" onchange="this.form.submit()" style="padding:8px;border:1px solid var(--linea);border-radius:6px;">
            <option value="">Todos los corrales</option>
            <?php foreach ($corrales as $c): ?><option value="<?= $c['id'] ?>" <?= $corral_id == $c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="table-container">
    <div class="table-header"><h2>Peso y ganancia diaria</h2></div>
    <?php if ($bovinos): ?>
    <table>
        <thead><tr><th>Arete</th><th>Corral</th><th>Categoria</th><th>Peso entrada</th><th>Peso actual</th><th>Ganado</th><th>GDP (kg/dia)</th><th>Ultimo pesaje</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bovinos as $b):
            $actual = $b['peso_actual'] ?? $b['peso_entrada'];
            $ganado = $actual !== null && $b['peso_entrada'] !== null ? $actual - $b['peso_entrada'] : null; ?>
            <tr>
                <td><strong><?= h($b['id_siniiga']) ?></strong></td>
                <td><?= h($b['corral']) ?></td>
                <td><?= h($b['categoria']) ?></td>
                <td><?= $b['peso_entrada'] !== null ? number_format($b['peso_entrada'], 1) : '-' ?></td>
                <td><strong><?= $actual !== null ? number_format($actual, 1) : '-' ?></strong></td>
                <td style="color:<?= $ganado !== null && $ganado < 0 ? '#dc3545' : '#28a745' ?>;"><?= $ganado !== null ? ($ganado >= 0 ? '+' : '') . number_format($ganado, 1) : '-' ?></td>
                <td><?= $b['gdp_ultimo'] !== null ? number_format($b['gdp_ultimo'], 3) : '-' ?></td>
                <td>
                    <?php if ($b['fecha_ultimo']): ?><?= fechaCorta($b['fecha_ultimo']) ?><?php else: ?><span style="color:var(--tenue);">Nunca</span><?php endif; ?>
                    <?php if ($b['dias_sin_pesar'] >= $diasAlerta): ?> <?= pill($b['dias_sin_pesar'] . ' d', 'var(--f-amarillo)', 'var(--t-amarillo)') ?><?php endif; ?>
                </td>
                <td><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($b['id_siniiga']) ?>">Ficha</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">No hay bovinos en corrales.</div><?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
