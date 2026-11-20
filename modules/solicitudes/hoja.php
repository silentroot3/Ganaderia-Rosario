<?php
// ============================================
// SOLICITUDES - Hoja de compra imprimible (comprobante interno)
// Deja antecedente de que se compro, a quien, cuanto y quien lo autorizo.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT s.*, COALESCE(m.nombre, i.nombre) AS item,
           us.nombre AS sol_nombre, us.apellido AS sol_apellido, us.rol AS sol_rol,
           ur.nombre AS aut_nombre, ur.apellido AS aut_apellido
    FROM solicitudes_compra s
    LEFT JOIN medicamentos m ON m.id = s.medicamento_id
    LEFT JOIN ingredientes i ON i.id = s.ingrediente_id
    JOIN usuarios us ON us.id = s.solicitante_id
    LEFT JOIN usuarios ur ON ur.id = s.resuelto_por
    WHERE s.id = ? AND s.estado IN ('aprobada', 'pedida', 'recibida')
");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    header('Location: ' . BASE_URL . '/solicitudes?error=' . urlencode('La hoja de compra solo existe para solicitudes aprobadas, pedidas o recibidas'));
    exit;
}

$cfg = $db->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'empresa_%' OR clave = 'iva'")->fetchAll(PDO::FETCH_KEY_PAIR);
$cantidad = (float)($s['cantidad_recibida'] ?: $s['cantidad']);
$precio = (float)$s['precio_unitario'];
$subtotal = round($cantidad * $precio, 2);
$ivaPct = (float)($cfg['iva'] ?? 0);
$iva = round($subtotal * $ivaPct / 100, 2);
$estados = ['aprobada' => 'APROBADA (por pedir)', 'pedida' => 'PEDIDA AL PROVEEDOR', 'recibida' => 'RECIBIDA'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hoja de compra <?= h($s['folio']) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; background: #eee; }
    .hoja { width: 21cm; min-height: 27cm; margin: 20px auto; background: #fff; padding: 1.6cm; box-shadow: 0 0 8px rgba(0,0,0,.15); }
    .enc { display: flex; justify-content: space-between; border-bottom: 3px solid #0b4f86; padding-bottom: 12px; margin-bottom: 20px; }
    .enc h1 { margin: 0; color: #0b4f86; font-size: 22px; }
    .enc .folio { text-align: right; }
    .enc .folio strong { font-size: 20px; display: block; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 30px; margin-bottom: 20px; font-size: 14px; }
    .grid div span { display: block; font-size: 11px; color: #666; text-transform: uppercase; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 14px; }
    th { background: #0b4f86; color: #fff; padding: 8px; text-align: left; }
    td { border-bottom: 1px solid #ddd; padding: 8px; }
    .num { text-align: right; }
    .totales { width: 45%; margin-left: auto; }
    .totales td { border: none; padding: 4px 8px; }
    .totales tr:last-child td { font-weight: bold; font-size: 16px; border-top: 2px solid #0b4f86; }
    .firmas { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-top: 70px; text-align: center; font-size: 13px; }
    .firmas div { border-top: 1px solid #222; padding-top: 6px; }
    .nota { font-size: 11px; color: #777; margin-top: 30px; }
    .acciones { text-align: center; margin: 15px; }
    .acciones button { padding: 10px 25px; font-size: 15px; background: #0b4f86; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
    @media print { body { background: #fff; } .hoja { margin: 0; box-shadow: none; width: auto; min-height: 0; } .acciones { display: none; } }
</style>
</head>
<body>
<div class="acciones"><button onclick="window.print()">Imprimir / Guardar PDF</button></div>
<div class="hoja">
    <div class="enc">
        <div>
            <h1><?= h($cfg['empresa_nombre'] ?? 'Ganaderia') ?></h1>
            <div style="font-size: 13px; margin-top: 4px;">
                RFC: <?= h($cfg['empresa_rfc'] ?? '-') ?><br>
                <?= h($cfg['empresa_direccion'] ?? '') ?><br>
                Tel. <?= h($cfg['empresa_telefono'] ?? '') ?> · <?= h($cfg['empresa_email'] ?? '') ?>
            </div>
        </div>
        <div class="folio">
            HOJA DE COMPRA
            <strong><?= h($s['folio']) ?></strong>
            <span style="font-size: 13px;">Emitida: <?= date('d/m/Y H:i') ?></span><br>
            <span style="font-size: 13px; font-weight: bold;"><?= $estados[$s['estado']] ?></span>
        </div>
    </div>

    <div class="grid">
        <div><span>Proveedor</span><?= h($s['proveedor_destino'] ?: 'Por definir') ?></div>
        <div><span>Factura / nota del proveedor</span><?= h($s['factura'] ?: '-') ?></div>
        <div><span>Solicitado por</span><?= h($s['sol_nombre'] . ' ' . $s['sol_apellido']) ?> (<?= h($s['sol_rol']) ?>) · <?= fechaCorta($s['created_at']) ?></div>
        <div><span>Autorizado por</span><?= h(trim(($s['aut_nombre'] ?? '') . ' ' . ($s['aut_apellido'] ?? '')) ?: '-') ?> · <?= fechaCorta($s['fecha_resolucion']) ?></div>
        <div><span>Motivo</span><?= h($s['motivo'] ?: '-') ?></div>
        <div><span>Recibido</span><?= $s['fecha_recibida'] ? fechaHora($s['fecha_recibida']) : 'Pendiente' ?></div>
    </div>

    <table>
        <thead>
            <tr><th>Concepto</th><th>Tipo</th><th class="num">Solicitado</th><th class="num">Recibido</th><th class="num">Precio unitario</th><th class="num">Importe</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><strong><?= h($s['item']) ?></strong></td>
                <td><?= h(ucfirst($s['tipo'])) ?></td>
                <td class="num"><?= number_format($s['cantidad'], 2) ?> <?= h($s['unidad']) ?></td>
                <td class="num"><?= $s['cantidad_recibida'] !== null ? number_format($s['cantidad_recibida'], 2) . ' ' . h($s['unidad']) : '-' ?></td>
                <td class="num"><?= $precio > 0 ? '$' . number_format($precio, 2) : 'Por definir' ?></td>
                <td class="num"><?= $precio > 0 ? '$' . number_format($subtotal, 2) : '-' ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($precio > 0): ?>
    <table class="totales">
        <tr><td>Subtotal</td><td class="num">$<?= number_format($subtotal, 2) ?></td></tr>
        <tr><td>IVA <?= rtrim(rtrim(number_format($ivaPct, 2), '0'), '.') ?>%</td><td class="num">$<?= number_format($iva, 2) ?></td></tr>
        <tr><td>Total</td><td class="num">$<?= number_format($subtotal + $iva, 2) ?></td></tr>
    </table>
    <?php endif; ?>

    <?php if ($s['respuesta_admin']): ?>
        <p style="font-size: 13px;"><strong>Observaciones:</strong> <?= h($s['respuesta_admin']) ?></p>
    <?php endif; ?>

    <div class="firmas">
        <div>Solicito<br><?= h($s['sol_nombre'] . ' ' . $s['sol_apellido']) ?></div>
        <div>Autorizo<br><?= h(trim(($s['aut_nombre'] ?? '') . ' ' . ($s['aut_apellido'] ?? ''))) ?></div>
        <div>Recibio</div>
    </div>

    <p class="nota">Documento interno de control de compras. No sustituye a la factura fiscal (CFDI) del proveedor.</p>
</div>
</body>
</html>
