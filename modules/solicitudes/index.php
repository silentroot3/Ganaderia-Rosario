<?php
// ============================================
// SOLICITUDES DE COMPRA
// Veterinario / Alimentador piden -> Administrador aprueba y gestiona
// ============================================

$titulo = 'Solicitudes de compra';
$modulo = 'solicitudes';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/inventario_lib.php';
require_once __DIR__ . '/../../includes/proveedores_lib.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$esAdmin = Permisos::esAdmin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$esAdmin) throw new Exception('Solo el administrador gestiona solicitudes');
        exigirCSRF();
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['accion'] ?? '';

        $stmt = $db->prepare("SELECT s.*, u.rol AS rol_solicitante FROM solicitudes_compra s
                              INNER JOIN usuarios u ON u.id = s.solicitante_id WHERE s.id = ?");
        $stmt->execute([$id]);
        $s = $stmt->fetch();
        if (!$s) throw new Exception('Solicitud no encontrada');

        $nombreItem = '';
        if ($s['tipo'] === 'medicamento') {
            $q = $db->prepare("SELECT nombre FROM medicamentos WHERE id = ?"); $q->execute([$s['medicamento_id']]);
        } else {
            $q = $db->prepare("SELECT nombre FROM ingredientes WHERE id = ?"); $q->execute([$s['ingrediente_id']]);
        }
        $nombreItem = $q->fetchColumn();
        $respuesta = trim($_POST['respuesta'] ?? '');

        if ($act === 'aprobar' && $s['estado'] === 'pendiente') {
            $nuevo = 'aprobada';
        } elseif ($act === 'rechazar' && in_array($s['estado'], ['pendiente', 'aprobada'], true)) {
            $nuevo = 'rechazada';
        } elseif ($act === 'pedir' && in_array($s['estado'], ['pendiente', 'aprobada'], true)) {
            $nuevo = 'pedida';
            if (trim($_POST['proveedor'] ?? '') === '') throw new Exception('Indica a que proveedor/organizacion se hizo el pedido');
        } elseif ($act === 'recibir' && in_array($s['estado'], ['aprobada', 'pedida'], true)) {
            $nuevo = 'recibida';
        } else {
            throw new Exception('Accion no valida para el estado actual');
        }

        $db->beginTransaction();
        $precio = (float)($_POST['precio'] ?? 0);
        $cant = null;

        if ($nuevo === 'recibida') {
            $cant = (float)($_POST['cantidad_recibida'] ?? $s['cantidad']);
            if ($cant <= 0) throw new Exception('Cantidad recibida invalida');
            if ($s['tipo'] === 'medicamento') {
                entradaMedicamento($s['medicamento_id'], $cant, $precio, trim($_POST['lote'] ?? ''),
                    $_POST['caducidad'] ?? null, $s['proveedor_destino'] ?? '', null,
                    $_SESSION['user_id'], 'Solicitud ' . $s['folio']);
            } else {
                entradaAlimento($s['ingrediente_id'], $cant, $precio, $s['proveedor_destino'] ?? '',
                    $_SESSION['user_id'], 'Solicitud ' . $s['folio']);
            }
        }

        $db->prepare("
            UPDATE solicitudes_compra
            SET estado = ?, respuesta_admin = COALESCE(NULLIF(?, ''), respuesta_admin),
                proveedor_destino = COALESCE(NULLIF(?, ''), proveedor_destino),
                proveedor_id = COALESCE(?, proveedor_id),
                precio_unitario = IF(? > 0, ?, precio_unitario),
                resuelto_por = ?, fecha_resolucion = NOW(),
                cantidad_recibida = COALESCE(?, cantidad_recibida),
                fecha_recibida = IF(? IS NULL, fecha_recibida, NOW()),
                factura = COALESCE(NULLIF(?, ''), factura)
            WHERE id = ?
        ")->execute([$nuevo, $respuesta, trim($_POST['proveedor'] ?? ''), proveedorId($db, $_POST['proveedor'] ?? '', $s['tipo']), $precio, $precio, $_SESSION['user_id'],
                     $cant, $cant, trim($_POST['factura'] ?? ''), $id]);
        $db->commit();

        $verbos = ['aprobada' => 'aprobada', 'rechazada' => 'rechazada', 'pedida' => 'pedida al proveedor', 'recibida' => 'recibida (ya esta en inventario)'];
        crearAlerta('solicitud_compra',
            "Tu solicitud {$s['folio']} de {$nombreItem} fue {$verbos[$nuevo]}." . ($respuesta ? " Nota: $respuesta" : ''),
            $s['rol_solicitante']);

        header('Location: ' . BASE_URL . '/solicitudes?msg=' . urlencode('Solicitud ' . $s['folio'] . ': ' . $nuevo));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$estadoFiltro = $_GET['estado'] ?? 'abiertas';
$where = [];
$params = [];
if (!$esAdmin) { $where[] = "s.solicitante_id = ?"; $params[] = $_SESSION['user_id']; }
if ($estadoFiltro === 'abiertas') $where[] = "s.estado IN ('pendiente','aprobada','pedida')";
elseif (in_array($estadoFiltro, ['pendiente','aprobada','pedida','recibida','rechazada'], true)) { $where[] = "s.estado = ?"; $params[] = $estadoFiltro; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT s.*, COALESCE(m.nombre, i.nombre) AS item, u.nombre AS sol_nombre, u.apellido AS sol_apellido, u.rol AS sol_rol
    FROM solicitudes_compra s
    LEFT JOIN medicamentos m ON m.id = s.medicamento_id
    LEFT JOIN ingredientes i ON i.id = s.ingrediente_id
    INNER JOIN usuarios u ON u.id = s.solicitante_id
    $sqlWhere
    ORDER BY FIELD(s.urgencia,'urgente','alta','normal'), s.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$solicitudes = $stmt->fetchAll();

$colEstado = ['pendiente' => ['var(--f-amarillo)','var(--t-amarillo)'], 'aprobada' => ['var(--f-azul)','var(--t-azul)'], 'pedida' => ['var(--f-morado)','var(--t-morado)'],
              'recibida' => ['var(--f-verde)','var(--t-verde)'], 'rechazada' => ['var(--f-rojo)','var(--t-rojo)']];
?>

<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <?php if (Permisos::puedeCrear('solicitudes')): ?>
        <a href="<?= BASE_URL ?>/solicitudes/crear" class="btn btn-primary">+ Nueva solicitud</a>
    <?php endif; ?>
    <?php foreach (['abiertas' => 'Abiertas', 'recibida' => 'Recibidas', 'rechazada' => 'Rechazadas', 'todas' => 'Todas'] as $k => $l): ?>
        <a href="<?= BASE_URL ?>/solicitudes?estado=<?= $k ?>" class="btn <?= $estadoFiltro === $k ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="table-container">
    <div class="table-header"><h2>Solicitudes (<?= count($solicitudes) ?>)</h2></div>
    <?php if ($solicitudes): ?>
    <table>
        <thead><tr><th>Folio</th><th>Fecha</th><th>Producto</th><th>Cantidad</th><th>Urgencia</th><th>Solicita</th><th>Motivo</th><th>Estado</th><?php if ($esAdmin): ?><th>Gestionar</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($solicitudes as $s): $c = $colEstado[$s['estado']]; ?>
            <tr>
                <td><strong><?= h($s['folio']) ?></strong></td>
                <td><?= fechaCorta($s['created_at']) ?></td>
                <td><?= h($s['item']) ?> <small style="color:var(--tenue);">(<?= h($s['tipo']) ?>)</small></td>
                <td><?= number_format($s['cantidad'], 1) . ' ' . h($s['unidad']) ?></td>
                <td><?= $s['urgencia'] === 'urgente' ? pill('URGENTE', '#dc3545', '#fff') : ($s['urgencia'] === 'alta' ? pill('Alta', 'var(--f-amarillo)', 'var(--t-amarillo)') : 'Normal') ?></td>
                <td><?= h($s['sol_nombre'] . ' ' . $s['sol_apellido']) ?> <small style="color:var(--tenue);">(<?= h($s['sol_rol']) ?>)</small></td>
                <td><?= h($s['motivo']) ?><?php if ($s['respuesta_admin']): ?><br><small style="color:var(--tenue);">Admin: <?= h($s['respuesta_admin']) ?></small><?php endif; ?></td>
                <td><?= pill($s['estado'], $c[0], $c[1]) ?><?php if ($s['proveedor_destino']): ?><br><small style="color:var(--tenue);"><?= h($s['proveedor_destino']) ?></small><?php endif; ?></td>
                <?php if ($esAdmin): ?>
                <td>
                    <?php if (in_array($s['estado'], ['pendiente','aprobada','pedida'], true)): ?>
                    <form method="POST" style="display:flex;flex-direction:column;gap:5px;min-width:210px;">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <input type="text" name="respuesta" placeholder="Nota (opcional)" style="padding:6px;border:1px solid var(--linea);border-radius:6px;">
                        <?php if ($s['estado'] !== 'pedida'): ?>
                        <input type="text" name="proveedor" list="dl-prov-<?= h($s['tipo']) ?>" autocomplete="off" placeholder="Proveedor (escribe o elige)" value="<?= h($s['proveedor_destino']) ?>" style="padding:6px;border:1px solid var(--linea);border-radius:6px;">
                        <?php endif; ?>
                        <?php if (in_array($s['estado'], ['aprobada','pedida'], true)): ?>
                        <div style="display:flex;gap:5px;">
                            <input type="number" step="0.01" min="0.01" name="cantidad_recibida" value="<?= h($s['cantidad']) ?>" title="Cantidad recibida" style="padding:6px;border:1px solid var(--linea);border-radius:6px;width:50%;">
                            <input type="number" step="0.01" min="0" name="precio" placeholder="Precio/u" style="padding:6px;border:1px solid var(--linea);border-radius:6px;width:50%;">
                        </div>
                        <input type="text" name="factura" placeholder="No. de factura / nota (al recibir)" style="padding:6px;border:1px solid var(--linea);border-radius:6px;">
                        <?php endif; ?>
                        <div style="display:flex;gap:5px;flex-wrap:wrap;">
                            <?php if ($s['estado'] === 'pendiente'): ?><button name="accion" value="aprobar" class="btn btn-primary btn-sm">Aprobar</button><?php endif; ?>
                            <?php if ($s['estado'] !== 'pedida'): ?><button name="accion" value="pedir" class="btn btn-secondary btn-sm">Marcar pedida</button><?php endif; ?>
                            <?php if (in_array($s['estado'], ['aprobada','pedida'], true)): ?><button name="accion" value="recibir" class="btn btn-primary btn-sm" onclick="return confirm('Registrar recepcion y sumar al inventario?')">Recibida</button><?php endif; ?>
                            <button name="accion" value="rechazar" class="btn btn-danger btn-sm" onclick="return confirm('Rechazar solicitud?')">Rechazar</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <?php if (in_array($s['estado'], ['aprobada','pedida','recibida'], true)): ?>
                        <a href="<?= BASE_URL ?>/solicitudes/hoja?id=<?= $s['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="margin-top:6px;display:inline-block;">Hoja de compra</a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div style="padding:40px;text-align:center;color:var(--tenue);">No hay solicitudes.</div>
    <?php endif; ?>
</div>

<?php if ($esAdmin) echo datalistProveedores($db, 'medicamento') . datalistProveedores($db, 'alimento'); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
