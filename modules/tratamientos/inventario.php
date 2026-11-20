<?php
// ============================================
// TRATAMIENTOS - Inventario de medicamentos del veterinario
// Entradas, catalogo y solicitud de compra al administrador
// ============================================

$titulo = 'Inventario de medicamentos';
$modulo = 'tratamientos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/inventario_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$error = '';
$puedeCrear = Permisos::puedeCrear('tratamientos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$puedeCrear) throw new Exception('Sin permiso para modificar el inventario');
        exigirCSRF();
        $accionPost = $_POST['accion'] ?? '';

        if ($accionPost === 'entrada') {
            entradaMedicamento(
                (int)($_POST['medicamento_id'] ?? 0), $_POST['cantidad'] ?? 0, $_POST['precio'] ?? 0,
                trim($_POST['lote'] ?? ''), $_POST['caducidad'] ?? null, trim($_POST['proveedor'] ?? ''),
                $_POST['stock_minimo'] ?? null, $_SESSION['user_id']
            );
            header('Location: ' . BASE_URL . '/tratamientos/inventario?msg=' . urlencode('Entrada registrada'));
            exit;
        }

        if ($accionPost === 'minimos') {
            $n = 0;
            $upd = $db->prepare("UPDATE inventario_medicamentos SET stock_minimo = ? WHERE medicamento_id = ?");
            $ins = $db->prepare("INSERT INTO inventario_medicamentos (medicamento_id, stock_actual, stock_minimo, unidad, precio_unitario) VALUES (?, 0, ?, 'ml', 0)");
            $hay = $db->prepare("SELECT COUNT(*) FROM inventario_medicamentos WHERE medicamento_id = ?");
            foreach ((array)($_POST['minimo'] ?? []) as $mid => $min) {
                if ($min === '' || !is_numeric($min) || $min < 0) continue;
                $hay->execute([(int)$mid]);
                if ($hay->fetchColumn()) $upd->execute([round((float)$min, 2), (int)$mid]); else $ins->execute([(int)$mid, round((float)$min, 2)]);
                $n++;
            }
            registrarLog($_SESSION['user_id'], 'stock_minimo_medicamento', "$n medicamentos");
            header('Location: ' . BASE_URL . '/tratamientos/inventario?msg=' . urlencode('Stock minimo guardado'));
            exit;
        }

        if ($accionPost === 'nuevo') {
            $nombre = trim($_POST['nombre'] ?? '');
            $tipo = $_POST['tipo'] ?? 'otro';
            if ($nombre === '') throw new Exception('El nombre es obligatorio');
            if (!in_array($tipo, ['vacuna','antibiotico','vitamina','desparasitante','otro'], true)) $tipo = 'otro';
            $stmt = $db->prepare("SELECT id FROM medicamentos WHERE nombre = ?");
            $stmt->execute([$nombre]);
            if ($stmt->fetchColumn()) throw new Exception('Ese medicamento ya existe en el catalogo');
            $db->prepare("INSERT INTO medicamentos (nombre, tipo, dosis_recomendada, via_administracion, dias_retiro, activo)
                          VALUES (?, ?, ?, ?, ?, 1)")
               ->execute([$nombre, $tipo, trim($_POST['dosis_recomendada'] ?? '') ?: null,
                          trim($_POST['via'] ?? '') ?: null, max(0, (int)($_POST['dias_retiro'] ?? 0))]);
            header('Location: ' . BASE_URL . '/tratamientos/inventario?msg=' . urlencode('Medicamento agregado al catalogo'));
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$inventario = $db->query("
    SELECT m.id, m.nombre, m.tipo, m.dias_retiro,
           COALESCE(i.stock_actual, 0) AS stock, COALESCE(i.stock_minimo, 0) AS minimo,
           COALESCE(i.unidad, 'ml') AS unidad, i.fecha_caducidad, i.lote, i.proveedor,
           COALESCE(i.precio_unitario, 0) AS precio
    FROM medicamentos m
    LEFT JOIN inventario_medicamentos i ON i.medicamento_id = m.id
    WHERE m.activo = 1 ORDER BY m.nombre
")->fetchAll();

$bajos = 0; $porCaducar = 0;
foreach ($inventario as $i) {
    if ($i['stock'] <= $i['minimo']) $bajos++;
    if ($i['fecha_caducidad'] && strtotime($i['fecha_caducidad']) <= strtotime('+30 days')) $porCaducar++;
}
$verPrecios = puedeVerPrecios();
?>

<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card blue"><div class="stat-label">Medicamentos</div><div class="stat-value"><?= count($inventario) ?></div></div>
    <div class="stat-card" style="border-left-color:#dc3545;"><div class="stat-label">Bajo minimo</div><div class="stat-value"><?= $bajos ?></div><div class="stat-desc">Pedir al administrador</div></div>
    <div class="stat-card yellow"><div class="stat-label">Por caducar (30 d)</div><div class="stat-value"><?= $porCaducar ?></div></div>
</div>

<div style="margin-bottom:20px;display:flex;gap:10px;">
    <a href="<?= BASE_URL ?>/tratamientos" class="btn btn-secondary">Volver</a>
    <a href="<?= BASE_URL ?>/solicitudes" class="btn btn-secondary">Mis solicitudes de compra</a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<?php if ($puedeCrear): ?>
<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Registrar entrada de medicamento</h2></div>
    <form method="POST" style="padding:20px;">
        <?= csrfField() ?><input type="hidden" name="accion" value="entrada">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:10px;align-items:end;">
            <div><label style="<?= ESTILO_LABEL ?>">Medicamento</label>
                <select name="medicamento_id" required style="<?= ESTILO_INPUT ?>">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($inventario as $i): ?><option value="<?= $i['id'] ?>"><?= h($i['nombre']) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="<?= ESTILO_LABEL ?>">Cantidad</label><input type="number" name="cantidad" step="0.01" min="0.01" required style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Precio por unidad</label><input type="number" name="precio" step="0.01" min="0" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Caducidad</label><input type="date" name="caducidad" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Stock minimo</label><input type="number" name="stock_minimo" step="0.01" min="0" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-top:10px;">
            <div><label style="<?= ESTILO_LABEL ?>">Lote</label><input type="text" name="lote" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Proveedor</label><input type="text" name="proveedor" list="dl-prov-medicamento" autocomplete="off" placeholder="Escribe o elige" style="<?= ESTILO_INPUT ?>"></div>
            <button class="btn btn-primary" type="submit">Registrar entrada</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-container" style="margin-bottom:25px;">
    <div class="table-header"><h2>Existencias</h2><?php if ($puedeCrear): ?><button type="submit" form="formMinimos" class="btn btn-primary btn-sm">Guardar stock mínimo</button><?php endif; ?></div>
    <form method="POST" id="formMinimos"><?= csrfField() ?><input type="hidden" name="accion" value="minimos"></form>
    <table>
        <thead><tr><th>Medicamento</th><th>Tipo</th><th>Stock</th><th>Minimo</th><th>Retiro</th><th>Caducidad</th><th>Lote</th>
            <?php if ($verPrecios): ?><th>Precio/u</th><?php endif; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($inventario as $i):
            $bajo = $i['stock'] <= $i['minimo'];
            $cad = $i['fecha_caducidad'] && strtotime($i['fecha_caducidad']) <= strtotime('+30 days'); ?>
            <tr>
                <td><strong><?= h($i['nombre']) ?></strong></td>
                <td><?= h($i['tipo']) ?></td>
                <td style="<?= $bajo ? 'color:#dc3545;font-weight:700;' : '' ?>"><?= number_format($i['stock'], 1) . ' ' . h($i['unidad']) ?></td>
                <td><?php if ($puedeCrear): ?><input type="number" form="formMinimos" name="minimo[<?= $i['id'] ?>]" value="<?= h(rtrim(rtrim(number_format((float)$i['minimo'], 2, '.', ''), '0'), '.')) ?>" min="0" step="1" style="width:95px;"> <?= h($i['unidad']) ?>
                    <?php else: ?><?= number_format($i['minimo'], 1) ?><?php endif; ?></td>
                <td><?= (int)$i['dias_retiro'] ?> d</td>
                <td style="<?= $cad ? 'color:var(--t-amarillo);font-weight:700;' : '' ?>"><?= fechaCorta($i['fecha_caducidad']) ?></td>
                <td><?= h($i['lote']) ?></td>
                <?php if ($verPrecios): ?><td><?= dinero($i['precio']) ?></td><?php endif; ?>
                <td><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/solicitudes/crear?tipo=medicamento&id=<?= $i['id'] ?>">Solicitar compra</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($puedeCrear): ?>
<div class="table-container">
    <div class="table-header"><h2>Agregar medicamento al catalogo</h2></div>
    <form method="POST" style="padding:20px;">
        <?= csrfField() ?><input type="hidden" name="accion" value="nuevo">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end;">
            <div><label style="<?= ESTILO_LABEL ?>">Nombre</label><input type="text" name="nombre" required style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Tipo</label>
                <select name="tipo" style="<?= ESTILO_INPUT ?>">
                    <?php foreach (['vacuna','antibiotico','vitamina','desparasitante','otro'] as $t): ?><option><?= $t ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="<?= ESTILO_LABEL ?>">Dosis recomendada</label><input type="text" name="dosis_recomendada" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Via</label><input type="text" name="via" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Dias de retiro</label><input type="number" name="dias_retiro" min="0" value="0" style="<?= ESTILO_INPUT ?>"></div>
            <button class="btn btn-primary" type="submit">Agregar</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?= datalistProveedores($db, 'medicamento') ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
