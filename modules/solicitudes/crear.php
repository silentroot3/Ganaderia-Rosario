<?php
// ============================================
// SOLICITUDES - Nueva solicitud de compra (medicamento o alimento)
// Genera una alerta para el administrador
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/proveedores_lib.php';

$db = Database::getInstance()->getConnection();
$error = '';

$rol = $_SESSION['rol'] ?? '';
// El veterinario pide medicamentos; el alimentador pide alimento; el admin ambos
$tiposPermitidos = $rol === 'veterinario' ? ['medicamento'] : ($rol === 'alimentador' ? ['alimento'] : ['medicamento', 'alimento']);
$tipo = $_POST['tipo'] ?? ($_GET['tipo'] ?? $tiposPermitidos[0]);
if (!in_array($tipo, $tiposPermitidos, true)) $tipo = $tiposPermitidos[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('solicitudes', 'crear');
        exigirCSRF();

        $item = (int)($_POST['item_id'] ?? 0);
        $cantidad = (float)($_POST['cantidad'] ?? 0);
        $urgencia = in_array($_POST['urgencia'] ?? '', ['normal','alta','urgente'], true) ? $_POST['urgencia'] : 'normal';
        if ($item <= 0 || $cantidad <= 0) throw new Exception('Selecciona el producto e indica la cantidad');

        if ($tipo === 'medicamento') {
            $q = $db->prepare("SELECT m.nombre, COALESCE(i.unidad,'ml') AS unidad FROM medicamentos m
                               LEFT JOIN inventario_medicamentos i ON i.medicamento_id = m.id WHERE m.id = ?");
        } else {
            $q = $db->prepare("SELECT i.nombre, COALESCE(inv.unidad, i.unidad, 'kg') AS unidad FROM ingredientes i
                               LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = i.id WHERE i.id = ?");
        }
        $q->execute([$item]);
        $prod = $q->fetch();
        if (!$prod) throw new Exception('Producto no encontrado');

        $db->prepare("
            INSERT INTO solicitudes_compra (folio, tipo, medicamento_id, ingrediente_id, cantidad, unidad, urgencia, motivo, proveedor_destino, proveedor_id, solicitante_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute(['TMP' . bin2hex(random_bytes(4)), $tipo,
                     $tipo === 'medicamento' ? $item : null, $tipo === 'alimento' ? $item : null,
                     $cantidad, $prod['unidad'], $urgencia, trim($_POST['motivo'] ?? '') ?: null,
                     trim($_POST['proveedor'] ?? '') ?: null, proveedorId($db, $_POST['proveedor'] ?? '', $tipo), $_SESSION['user_id']]);
        $id = (int)$db->lastInsertId();
        $folio = 'SOL-' . str_pad($id, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE solicitudes_compra SET folio = ? WHERE id = ?")->execute([$folio, $id]);

        crearAlerta('solicitud_compra',
            ($urgencia === 'urgente' ? 'URGENTE: ' : '') . "Solicitud $folio: se necesitan " . number_format($cantidad, 1)
            . " {$prod['unidad']} de {$prod['nombre']}.", 'admin');
        registrarLog($_SESSION['user_id'], 'solicitud_compra', "$folio {$prod['nombre']}");

        header('Location: ' . BASE_URL . '/solicitudes?msg=' . urlencode("Solicitud $folio enviada al administrador"));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Nueva solicitud de compra';
$modulo = 'solicitudes';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$medicamentos = $db->query("SELECT id, nombre FROM medicamentos WHERE activo = 1 ORDER BY nombre")->fetchAll();
$ingredientes = $db->query("SELECT id, nombre FROM ingredientes WHERE activo = 1 ORDER BY nombre")->fetchAll();
$lista = $tipo === 'medicamento' ? $medicamentos : $ingredientes;
$preId = (int)($_POST['item_id'] ?? ($_GET['id'] ?? 0));
$provPorItem = proveedoresPorProducto($db, $tipo);
$verPrecios = puedeVerPrecios();
?>

<div class="table-container" style="max-width:650px;margin:0 auto;">
    <div class="table-header"><h2>Nueva solicitud de compra</h2><a href="<?= BASE_URL ?>/solicitudes" class="btn btn-secondary">Volver</a></div>
    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>

    <?php if (count($tiposPermitidos) > 1): ?>
    <div style="padding:20px 25px 0;display:flex;gap:10px;">
        <?php foreach ($tiposPermitidos as $t): ?>
            <a class="btn btn-sm <?= $tipo === $t ? 'btn-primary' : 'btn-secondary' ?>" href="<?= BASE_URL ?>/solicitudes/crear?tipo=<?= $t ?>"><?= ucfirst($t) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" style="padding:25px;">
        <?= csrfField() ?><input type="hidden" name="tipo" value="<?= h($tipo) ?>">
        <div style="margin-bottom:15px;">
            <label style="<?= ESTILO_LABEL ?>"><?= $tipo === 'medicamento' ? 'Medicamento' : 'Alimento / ingrediente' ?> *</label>
            <select name="item_id" id="selItem" required style="<?= ESTILO_INPUT ?>">
                <option value="">-- Seleccionar --</option>
                <?php foreach ($lista as $i): ?><option value="<?= $i['id'] ?>" <?= $preId === (int)$i['id'] ? 'selected' : '' ?>><?= h($i['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
            <div><label style="<?= ESTILO_LABEL ?>">Cantidad requerida *</label><input type="number" name="cantidad" step="0.01" min="0.01" required value="<?= h($_POST['cantidad'] ?? ($_GET['cantidad'] ?? '')) ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Urgencia</label>
                <select name="urgencia" style="<?= ESTILO_INPUT ?>">
                    <?php $urg = $_POST['urgencia'] ?? ($_GET['urgencia'] ?? 'normal'); foreach (['normal' => 'Normal', 'alta' => 'Alta', 'urgente' => 'Urgente'] as $k => $t): ?><option value="<?= $k ?>" <?= $urg === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <div id="provConocidos" class="prov-conocidos" hidden></div>
        <div style="margin-bottom:15px;">
            <label style="<?= ESTILO_LABEL ?>">Proveedor sugerido <small class="muted">(opcional, el administrador decide)</small></label>
            <input type="text" name="proveedor" id="inpProv" list="dl-prov-<?= h($tipo) ?>" autocomplete="off" value="<?= h($_POST['proveedor'] ?? '') ?>" placeholder="Escribe o elige un proveedor" style="<?= ESTILO_INPUT ?>">
            <?= datalistProveedores($db, $tipo) ?>
            <small class="muted"><a href="<?= BASE_URL ?>/proveedores?tipo=<?= h($tipo) ?>" target="_blank">Ver todos los proveedores de <?= h($tipo) ?></a></small>
        </div>
        <div style="margin-bottom:20px;">
            <label style="<?= ESTILO_LABEL ?>">Motivo / comentarios</label>
            <textarea name="motivo" rows="3" style="<?= ESTILO_INPUT ?>"><?= h($_POST['motivo'] ?? ($_GET['motivo'] ?? '')) ?></textarea>
        </div>
        <div style="text-align:right;"><button class="btn btn-primary" type="submit">Enviar al administrador</button></div>
    </form>
</div>

<script>
(function () {
    var PROV = <?= json_encode($provPorItem, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, VER_PRECIO = <?= $verPrecios ? 'true' : 'false' ?>;
    var sel = document.getElementById('selItem'), box = document.getElementById('provConocidos'), inp = document.getElementById('inpProv');
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; };
    function pintar() {
        var l = PROV[sel.value];
        if (!sel.value) { box.hidden = true; return; }
        box.hidden = false;
        if (!l || !l.length) { box.innerHTML = '<span class="muted">Todavia no le hemos comprado este producto a ningun proveedor.</span>'; return; }
        box.innerHTML = '<div class="pc-titulo">Ya se lo hemos comprado a:</div>' + l.map(function (p) {
            return '<button type="button" class="pc-item" data-n="' + esc(p.nombre) + '"><strong>' + esc(p.nombre) + '</strong>'
                + '<span>' + (p.telefono ? esc(p.telefono) + ' · ' : '') + 'ultima vez ' + esc(p.ultima.split('-').reverse().join('/'))
                + (VER_PRECIO && p.precio ? ' · $' + p.precio.toFixed(2) : '') + '</span></button>';
        }).join('');
        box.querySelectorAll('.pc-item').forEach(function (b) { b.onclick = function () { inp.value = b.dataset.n; box.querySelectorAll('.pc-item').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    }
    sel.addEventListener('change', pintar); pintar();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
