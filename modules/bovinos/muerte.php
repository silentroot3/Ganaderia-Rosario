<?php
// ============================================
// BOVINOS - Registrar muerte dentro del rancho
// Lo da de baja, libera su corral, regresa al stock las dosis apartadas
// y lo invertido en el queda como perdida en reportes.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/bajas_lib.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
if (!Permisos::puedeCrear('sanidad') && !Permisos::puedeCrear('clasificacion')) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$db = Database::getInstance()->getConnection();
$bovino_id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("
    SELECT b.*, c.nombre AS corral FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id
    WHERE b.id = ? AND b.estado NOT IN ('vendido', 'muerto', 'devuelto')
");
$stmt->execute([$bovino_id]);
$bovino = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$bovino) {
    header('Location: ' . BASE_URL . '/bovinos?error=' . urlencode('El bovino no existe o ya salio del rancho'));
    exit;
}

$costo = costoBovino($bovino_id);
$pendientes = dosisPendientesPorBovino($db)[$bovino_id] ?? 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        if (empty($_POST['confirmo'])) throw new Exception('Marca la casilla para confirmar la baja');
        $r = registrarMuerte($db, $bovino_id, $_POST['fecha'] ?? '', $_POST['causa'] ?? '', trim($_POST['observaciones'] ?? ''), $_SESSION['user_id']);
        registrarLog($_SESSION['user_id'], 'bovino_muerte', "{$r['arete']}: {$_POST['causa']}");
        crearAlerta('otro', "Murio {$r['arete']} ({$_POST['causa']}). Perdida: " . dinero($r['costo']) . '.', 'admin', $bovino_id);
        $msg = "Muerte de {$r['arete']} registrada. Perdida: " . dinero($r['costo'])
             . ($r['dosis'] ? ". {$r['dosis']} dosis apartadas regresaron al inventario" : '');
        header('Location: ' . BASE_URL . '/bovinos?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Registrar muerte';
// El header valida el permiso del modulo: veterinario (sanidad) o clasificador
$modulo = Permisos::puedeCrear('sanidad') ? 'sanidad' : 'clasificacion';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 750px; margin: 0 auto;">
    <div class="table-header">
        <h2>Registrar muerte: <span style="font-family: monospace;"><?= h($bovino['id_siniiga']) ?></span></h2>
        <a href="javascript:history.back()" class="btn btn-secondary">Volver</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error" style="margin: 20px;"><?= h($error) ?></div><?php endif; ?>

    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; background: var(--suave);">
        <div><small style="color:var(--tenue);">SEXO / CATEGORIA</small><div><strong><?= $bovino['sexo'] === 'M' ? 'Macho' : 'Hembra' ?> · <?= h($bovino['categoria']) ?></strong></div></div>
        <div><small style="color:var(--tenue);">CORRAL</small><div><strong><?= h($bovino['corral'] ?? '-') ?><?= $bovino['en_hospital'] ? ' (hospital)' : '' ?></strong></div></div>
        <div><small style="color:var(--tenue);">INVERTIDO HASTA HOY</small><div><strong style="color:#dc3545;"><?= dinero($costo['total']) ?></strong></div></div>
    </div>

    <form method="POST" style="padding: 25px;">
        <?= csrfField() ?>
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="<?= ESTILO_LABEL ?>">Fecha de muerte *</label>
                <input type="date" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= h($_POST['fecha'] ?? date('Y-m-d')) ?>" style="<?= ESTILO_INPUT ?>">
            </div>
            <div>
                <label style="<?= ESTILO_LABEL ?>">Causa probable *</label>
                <select name="causa" required style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach (CAUSAS_MUERTE as $c): ?>
                        <option value="<?= h($c) ?>" <?= ($_POST['causa'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="margin-bottom: 15px;">
            <label style="<?= ESTILO_LABEL ?>">Observaciones</label>
            <input type="text" name="observaciones" value="<?= h($_POST['observaciones'] ?? '') ?>" placeholder="Ej: encontrado en la manana, sin signos previos" style="<?= ESTILO_INPUT ?>">
        </div>

        <div class="alert alert-info" style="margin-bottom: 15px;">
            Al confirmar: el animal sale del corral, <?= dinero($costo['total']) ?> invertidos quedan como perdida en Reportes
            <?= $pendientes ? "y sus <strong>$pendientes dosis apartadas</strong> regresan al inventario" : '' ?>. No se puede deshacer.
        </div>
        <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 20px; font-weight: 600;">
            <input type="checkbox" name="confirmo" value="1" required> Confirmo que el bovino <?= h($bovino['id_siniiga']) ?> murio
        </label>

        <div style="text-align: right;">
            <button type="submit" class="btn" style="background: #dc3545; color: #fff; padding: 12px 30px;">Registrar muerte</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
