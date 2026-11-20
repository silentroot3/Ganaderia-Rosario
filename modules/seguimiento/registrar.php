<?php
// ============================================
// SEGUIMIENTO - Registrar pesajes (por corral completo o un animal)
// GDP = (peso actual - peso anterior) / dias transcurridos
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$error = '';

/** GDP respecto al ultimo peso conocido (pesaje previo o peso de entrada) */
function calcularGdp($db, $bovino_id, $peso, $fecha) {
    $stmt = $db->prepare("SELECT peso, fecha FROM pesajes WHERE bovino_id = ? AND fecha < ? ORDER BY fecha DESC, id DESC LIMIT 1");
    $stmt->execute([$bovino_id, $fecha]);
    $prev = $stmt->fetch();
    if (!$prev) {
        $stmt = $db->prepare("SELECT peso_entrada, DATE(created_at) AS fecha FROM bovinos WHERE id = ?");
        $stmt->execute([$bovino_id]);
        $b = $stmt->fetch();
        if (!$b || $b['peso_entrada'] === null) return null;
        $prev = ['peso' => $b['peso_entrada'], 'fecha' => $b['fecha']];
    }
    $dias = (strtotime($fecha) - strtotime($prev['fecha'])) / 86400;
    if ($dias < 1) return null;
    return round(($peso - $prev['peso']) / $dias, 3);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('seguimiento', 'crear');
        exigirCSRF();
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) throw new Exception('Fecha invalida');

        $pesos = $_POST['peso'] ?? [];
        $guardados = 0;
        $db->beginTransaction();
        foreach ($pesos as $bovino_id => $peso) {
            $peso = trim((string)$peso);
            if ($peso === '') continue;
            $peso = (float)$peso;
            if ($peso <= 0 || $peso > 1500) throw new Exception('Peso fuera de rango para un bovino');
            $bovino_id = (int)$bovino_id;

            $chk = $db->prepare("SELECT estado FROM bovinos WHERE id = ?");
            $chk->execute([$bovino_id]);
            $est = $chk->fetchColumn();
            if (!$est || in_array($est, ['vendido','muerto','devuelto'], true)) continue;

            $gdp = calcularGdp($db, $bovino_id, $peso, $fecha);
            $db->prepare("INSERT INTO pesajes (bovino_id, peso, fecha, hora, gdp, usuario_id, observaciones)
                          VALUES (?, ?, ?, CURRENT_TIME(), ?, ?, ?)")
               ->execute([$bovino_id, $peso, $fecha, $gdp, $_SESSION['user_id'], trim($_POST['observaciones'] ?? '') ?: null]);
            $guardados++;
        }
        if ($guardados === 0) throw new Exception('No capturaste ningun peso');
        $db->commit();
        registrarLog($_SESSION['user_id'], 'pesaje', "$guardados pesaje(s) registrados");
        header('Location: ' . BASE_URL . '/seguimiento?msg=' . urlencode("$guardados pesaje(s) registrados"));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$titulo = 'Registrar pesajes';
$modulo = 'seguimiento';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$corral_id = (int)($_GET['corral_id'] ?? ($_POST['corral_id'] ?? 0));
$corrales = $db->query("SELECT id, nombre, ocupacion_actual FROM corrales WHERE es_hospital = 0 AND es_venta = 0 ORDER BY nombre")->fetchAll();
$bovinos = [];
if ($corral_id) {
    $stmt = $db->prepare("
        SELECT b.id, b.id_siniiga, b.categoria, b.peso_entrada,
               (SELECT p.peso FROM pesajes p WHERE p.bovino_id = b.id ORDER BY p.fecha DESC, p.id DESC LIMIT 1) AS ultimo
        FROM bovinos b WHERE b.corral_id = ? AND b.estado = 'en_corral' ORDER BY b.id_siniiga
    ");
    $stmt->execute([$corral_id]);
    $bovinos = $stmt->fetchAll();
}
?>

<div class="table-container" style="max-width:900px;margin:0 auto;">
    <div class="table-header"><h2>Registrar pesajes</h2><a href="<?= BASE_URL ?>/seguimiento" class="btn btn-secondary">Volver</a></div>
    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>

    <form method="GET" style="padding:20px 25px 0;display:flex;gap:10px;align-items:end;">
        <div style="flex:1;"><label style="<?= ESTILO_LABEL ?>">Corral</label>
            <select name="corral_id" onchange="this.form.submit()" style="<?= ESTILO_INPUT ?>">
                <option value="">-- Selecciona el corral a pesar --</option>
                <?php foreach ($corrales as $c): ?><option value="<?= $c['id'] ?>" <?= $corral_id == $c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?> (<?= (int)$c['ocupacion_actual'] ?>)</option><?php endforeach; ?>
            </select></div>
    </form>

    <?php if ($corral_id && $bovinos): ?>
    <form method="POST" style="padding:20px 25px 25px;">
        <?= csrfField() ?><input type="hidden" name="corral_id" value="<?= $corral_id ?>">
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:15px;margin-bottom:15px;">
            <div><label style="<?= ESTILO_LABEL ?>">Fecha del pesaje</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Observaciones</label><input type="text" name="observaciones" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <table>
            <thead><tr><th>Arete</th><th>Categoria</th><th>Peso entrada</th><th>Ultimo peso</th><th>Peso nuevo (kg)</th></tr></thead>
            <tbody>
            <?php foreach ($bovinos as $b): ?>
                <tr>
                    <td><strong><?= h($b['id_siniiga']) ?></strong></td>
                    <td><?= h($b['categoria']) ?></td>
                    <td><?= $b['peso_entrada'] !== null ? number_format($b['peso_entrada'], 1) : '-' ?></td>
                    <td><?= $b['ultimo'] !== null ? number_format($b['ultimo'], 1) : '-' ?></td>
                    <td><input type="number" step="0.1" min="1" max="1500" name="peso[<?= $b['id'] ?>]" style="width:120px;padding:6px;border:1px solid var(--linea);border-radius:6px;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:right;margin-top:15px;"><button class="btn btn-primary" type="submit">Guardar pesajes</button></div>
        <small style="color:var(--tenue);">Deja en blanco los animales que no pesaste. El sistema calcula solo los kilos ganados por dia.</small>
    </form>
    <?php elseif ($corral_id): ?>
        <div style="padding:30px;text-align:center;color:var(--tenue);">Este corral no tiene bovinos.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
