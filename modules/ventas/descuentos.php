<?php
// ============================================
// VENTAS - Solicitudes de descuento
// El vendedor pide vender abajo del precio recomendado y explica por que;
// el administrador tiene la ultima palabra: autoriza (ese u otro precio) o rechaza.
// Sin autorizacion no se puede vender mas barato.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/precios_venta.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('ventas', 'ver');
$db = Database::getInstance()->getConnection();
$esAdmin = Permisos::esAdmin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM solicitudes_descuento WHERE id = ?");
        $stmt->execute([$id]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new Exception('Solicitud no encontrada');
        $accionDesc = $_POST['accion'] ?? '';

        if ($accionDesc === 'retirar') {
            if ((int)$s['usuario_id'] !== (int)$_SESSION['user_id'] && !$esAdmin) throw new Exception('Solo quien la pidio puede retirarla');
            if (!in_array($s['estado'], ['pendiente', 'aprobada'], true)) throw new Exception('Esta solicitud ya no se puede retirar');
            $db->prepare("UPDATE solicitudes_descuento SET estado = 'rechazada', respuesta = CONCAT(COALESCE(respuesta, ''), ' (retirada por el vendedor)') WHERE id = ?")->execute([$id]);
            $msg = "Solicitud #$id retirada";
        } else {
            if (!$esAdmin) throw new Exception('Solo el administrador autoriza descuentos');
            if ($s['estado'] !== 'pendiente') throw new Exception('Esta solicitud ya fue contestada');
            $respuesta = trim($_POST['respuesta'] ?? '');
            if ($accionDesc === 'aprobar') {
                $precio = round((float)($_POST['precio_aprobado'] ?? 0), 2);
                if ($precio <= 0) throw new Exception('Escribe el precio autorizado');
                $db->prepare("UPDATE solicitudes_descuento SET estado = 'aprobada', precio_aprobado = ?, respuesta = ?, admin_id = ?, fecha_respuesta = NOW() WHERE id = ?")
                   ->execute([$precio, $respuesta ?: null, $_SESSION['user_id'], $id]);
                $txt = "Descuento #$id AUTORIZADO a " . dinero($precio) . "/kg" . ((float)$precio !== (float)$s['precio_solicitado'] ? ' (pediste ' . dinero($s['precio_solicitado']) . ')' : '')
                     . ($respuesta ? ". $respuesta" : '') . '. Ya puedes hacer la venta.';
                $msg = "Descuento #$id autorizado a " . dinero($precio) . '/kg';
            } elseif ($accionDesc === 'rechazar') {
                if ($respuesta === '') throw new Exception('Escribe por que se rechaza');
                $db->prepare("UPDATE solicitudes_descuento SET estado = 'rechazada', respuesta = ?, admin_id = ?, fecha_respuesta = NOW() WHERE id = ?")
                   ->execute([$respuesta, $_SESSION['user_id'], $id]);
                $txt = "Descuento #$id RECHAZADO: $respuesta. Se vende al precio recomendado.";
                $msg = "Descuento #$id rechazado";
            } else {
                throw new Exception('Accion no valida');
            }
            $rolV = $db->query("SELECT rol FROM usuarios WHERE id = " . (int)$s['usuario_id'])->fetchColumn();
            crearAlerta('solicitud_descuento', $txt, $rolV ?: 'vendedor');
            registrarLog($_SESSION['user_id'], 'descuento_' . $accionDesc, "#$id");
        }
        header('Location: ' . BASE_URL . '/ventas/descuentos?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$filtro = in_array($_GET['estado'] ?? '', ['pendiente', 'aprobada', 'rechazada', 'usada'], true) ? $_GET['estado'] : '';
$where = [];
if (!$esAdmin) $where[] = 's.usuario_id = ' . (int)$_SESSION['user_id'];
if ($filtro) $where[] = "s.estado = " . $db->quote($filtro);
$solicitudes = $db->query("
    SELECT s.*, u.nombre AS vendedor, u.apellido AS vendedor_ap, a.nombre AS admin, v.folio AS venta_folio,
           (SELECT GROUP_CONCAT(b.id_siniiga ORDER BY b.id_siniiga SEPARATOR ', ') FROM solicitud_descuento_bovinos sb JOIN bovinos b ON b.id = sb.bovino_id WHERE sb.solicitud_id = s.id) AS aretes,
           (SELECT COALESCE(SUM(" . sqlPesoActual('b') . "), 0) FROM solicitud_descuento_bovinos sb JOIN bovinos b ON b.id = sb.bovino_id WHERE sb.solicitud_id = s.id) AS kilos
    FROM solicitudes_descuento s
    JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN usuarios a ON a.id = s.admin_id
    LEFT JOIN ventas v ON v.id = s.venta_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY s.estado = 'pendiente' DESC, s.id DESC LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
$conteo = $db->query("SELECT estado, COUNT(*) FROM solicitudes_descuento" . ($esAdmin ? '' : ' WHERE usuario_id = ' . (int)$_SESSION['user_id']) . " GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);

$titulo = 'Descuentos';
$modulo = 'ventas';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';

$estilos = ['pendiente' => ['Pendiente', 'var(--f-amarillo)', 'var(--t-amarillo)'], 'aprobada' => ['Autorizado', 'var(--f-verde)', 'var(--t-verde)'],
            'rechazada' => ['Rechazado', 'var(--f-rojo)', 'var(--t-rojo)'], 'usada' => ['Usado en venta', 'var(--f-gris)', 'var(--texto-2)']];
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center;">
    <a href="<?= BASE_URL ?>/ventas" class="btn btn-secondary">Volver</a>
    <?php if (Permisos::puedeCrear('ventas')): ?><a href="<?= BASE_URL ?>/ventas/crear" class="btn btn-primary">Nueva venta / pedir descuento</a><?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
        <a href="?" class="btn btn-sm <?= $filtro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todas</a>
        <?php foreach ($estilos as $k => $e): ?>
            <a href="?estado=<?= $k ?>" class="btn btn-sm <?= $filtro === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= $e[0] ?> (<?= (int)($conteo[$k] ?? 0) ?>)</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$solicitudes): ?>
    <div class="table-container"><p style="padding:40px;text-align:center;color:var(--tenue);">No hay solicitudes de descuento.</p></div>
<?php endif; ?>

<div class="tarjetas-desc">
<?php foreach ($solicitudes as $s):
    $e = $estilos[$s['estado']];
    $bajaRec = $s['precio_recomendado'] > 0 ? ($s['precio_recomendado'] - $s['precio_solicitado']) / $s['precio_recomendado'] * 100 : 0;
    $bajoMin = (float)$s['precio_solicitado'] < (float)$s['precio_minimo']; ?>
    <div class="table-container tarjeta-desc" style="border-left:6px solid <?= $e[2] ?>;">
        <div class="table-header">
            <h2>#<?= $s['id'] ?> · <?= (int)$s['cantidad'] ?> animal(es)<?= $s['cliente'] ? ' · ' . h($s['cliente']) : '' ?></h2>
            <?= pill($e[0], $e[1], $e[2]) ?>
        </div>
        <div style="padding:0 20px 15px;">
            <div class="precios-desc">
                <div><small>Recomendado</small><strong><?= dinero($s['precio_recomendado']) ?></strong></div>
                <div><small>Minimo</small><strong><?= dinero($s['precio_minimo']) ?></strong></div>
                <div><small>Pide</small><strong style="color:<?= $bajoMin ? '#dc3545' : '#e67e22' ?>;"><?= dinero($s['precio_solicitado']) ?></strong>
                    <em><?= number_format($bajaRec, 1) ?>% menos<?= $bajoMin ? ' · abajo del minimo' : '' ?></em></div>
                <?php if ($s['precio_aprobado'] !== null): ?><div><small>Autorizado</small><strong style="color:#28a745;"><?= dinero($s['precio_aprobado']) ?></strong></div><?php endif; ?>
            </div>
            <p style="margin:10px 0 4px;"><strong><?= h(trim($s['vendedor'] . ' ' . $s['vendedor_ap'])) ?>:</strong> "<?= h($s['motivo']) ?>"</p>
            <small style="color:var(--tenue);"><?= fechaHora($s['created_at']) ?> · <?= number_format($s['kilos'], 0) ?> kg · <span title="<?= h($s['aretes']) ?>">aretes: <?= h(mb_strimwidth((string)$s['aretes'], 0, 90, '...')) ?></span></small>
            <?php if ($esAdmin && $s['estado'] === 'pendiente'): ?>
                <p style="margin-top:6px;color:var(--tenue);font-size:0.85rem;">Si se autoriza a <?= dinero($s['precio_solicitado']) ?>/kg se dejan de cobrar
                    <strong><?= dinero(max(0, $s['precio_recomendado'] - $s['precio_solicitado']) * $s['kilos']) ?></strong> contra el recomendado<?php if ($bajoMin): ?>
                    y se <strong style="color:#dc3545;">pierden <?= dinero(($s['precio_minimo'] - $s['precio_solicitado']) * $s['kilos']) ?></strong> contra lo invertido<?php endif; ?>.</p>
            <?php endif; ?>
            <?php if ($s['respuesta']): ?><p style="margin-top:8px;padding:8px 12px;background:var(--suave);border-radius:6px;"><strong><?= h($s['admin'] ?? 'Admin') ?>:</strong> <?= h($s['respuesta']) ?></p><?php endif; ?>
            <?php if ($s['venta_folio']): ?><p style="margin-top:6px;"><a href="<?= BASE_URL ?>/ventas/detalle?id=<?= (int)$s['venta_id'] ?>">Venta <?= h($s['venta_folio']) ?></a></p><?php endif; ?>

            <?php if ($esAdmin && $s['estado'] === 'pendiente'): ?>
            <form method="POST" class="grid-form" style="margin-top:12px;grid-template-columns:160px 1fr auto auto;align-items:end;">
                <?= csrfField() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <div><label style="<?= ESTILO_LABEL ?>">Precio autorizado</label>
                    <input type="number" step="0.01" min="0" name="precio_aprobado" value="<?= h($s['precio_solicitado']) ?>" style="<?= ESTILO_INPUT ?>"></div>
                <div><label style="<?= ESTILO_LABEL ?>">Respuesta al vendedor</label>
                    <input type="text" name="respuesta" maxlength="500" placeholder="Ej: te lo dejo a $88.50, a 87.20 perdemos" style="<?= ESTILO_INPUT ?>"></div>
                <button type="submit" name="accion" value="aprobar" class="btn btn-primary">Autorizar</button>
                <button type="submit" name="accion" value="rechazar" class="btn" style="background:#dc3545;color:#fff;">Rechazar</button>
            </form>
            <?php elseif ($s['estado'] === 'aprobada'): ?>
            <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
                <?php if (Permisos::puedeCrear('ventas')): ?><a href="<?= BASE_URL ?>/ventas/crear?descuento=<?= $s['id'] ?>" class="btn btn-primary">Vender con este precio</a><?php endif; ?>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button type="submit" name="accion" value="retirar" class="btn btn-secondary" onclick="return confirm('¿Retirar el descuento #<?= $s['id'] ?>?')">Ya no se usara</button></form>
            </div>
            <?php elseif ($s['estado'] === 'pendiente' && (int)$s['usuario_id'] === (int)$_SESSION['user_id']): ?>
            <form method="POST" style="margin-top:12px;"><?= csrfField() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" name="accion" value="retirar" class="btn btn-secondary btn-sm">Retirar solicitud</button></form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<style>
.tarjetas-desc { display: grid; gap: 18px; }
.tarjeta-desc { margin: 0; }
.precios-desc { display: flex; gap: 12px; flex-wrap: wrap; }
.precios-desc > div { background: var(--suave); border-radius: 8px; padding: 8px 14px; min-width: 120px; }
.precios-desc small { display: block; color: var(--tenue); font-size: 0.72rem; text-transform: uppercase; font-weight: 600; }
.precios-desc strong { font-size: 1.25rem; }
.precios-desc em { display: block; font-size: 0.75rem; color: var(--tenue); font-style: normal; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
