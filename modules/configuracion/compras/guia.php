<?php
// ============================================
// COMPRAS - Guia de transito imprimible (formato REEMO)
// Origen = rancho proveedor, destino = Ganaderia El Rosario, lista de bovinos.
// ============================================

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/ui.php';

if (!isset($_SESSION['user_id']) || !Permisos::puede('recepcion', 'ver') && !Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT c.*, p.telefono, p.email, u.nombre AS un, u.apellido AS ua
    FROM compras c
    LEFT JOIN proveedores p ON p.id = c.proveedor_id
    LEFT JOIN usuarios u ON u.id = c.usuario_compra_id
    WHERE c.id = ?
");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    header('Location: ' . BASE_URL . '/configuracion/compras');
    exit;
}
$stmt = $db->prepare("SELECT * FROM compra_bovinos WHERE compra_id = ? ORDER BY numero_linea, id");
$stmt->execute([$c['id']]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cfg = $db->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'empresa_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$machos = count(array_filter($bovinos, fn($b) => $b['sexo'] === 'MACHO'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Guia de transito <?= h($c['numero_reemo']) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #eee; font-size: 12px; }
    .hoja { width: 21.6cm; min-height: 27.9cm; margin: 20px auto; background: #fff; padding: 1.4cm 1.5cm; box-shadow: 0 0 8px rgba(0,0,0,.15); }
    .top { display: flex; justify-content: space-between; align-items: flex-start; }
    .reemo { font-size: 15px; font-weight: bold; text-align: right; }
    h1 { text-align: center; font-size: 15px; margin: 18px 0 4px; }
    h2 { text-align: center; font-size: 13px; margin: 14px 0 10px; }
    .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 40px; }
    .cols div { margin-bottom: 5px; }
    .sec { font-weight: bold; margin-top: 8px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { text-align: left; border-bottom: 1px solid #111; padding: 4px; font-size: 11px; }
    td { padding: 5px 4px; border-bottom: 1px solid #eee; }
    .arete { font-family: monospace; font-size: 13px; letter-spacing: 1px; }
    .firmas { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-top: 60px; text-align: center; }
    .firmas div { border-top: 1px solid #111; padding-top: 5px; }
    .acciones { text-align: center; margin: 15px; }
    .acciones button { padding: 10px 25px; font-size: 15px; background: #0b4f86; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
    @media print { body { background: #fff; } .hoja { margin: 0; box-shadow: none; width: auto; min-height: 0; } .acciones { display: none; } }
</style>
</head>
<body>
<div class="acciones"><button onclick="window.print()">Imprimir / Guardar PDF</button></div>
<div class="hoja">
    <div class="top">
        <div><strong><?= h($cfg['empresa_nombre'] ?? '') ?></strong><br>Compra <?= h($c['folio']) ?></div>
        <div class="reemo">No. REEMO: <?= h($c['numero_reemo'] ?: '-') ?></div>
    </div>

    <h1>GUIA DE TRANSITO</h1>
    <div><strong>Motivo: <?= h(strtoupper($c['motivo'] ?: 'ENGORDA')) ?></strong> &nbsp;·&nbsp; Fecha de compra: <?= fechaCorta($c['fecha_compra']) ?>
        <?php if ($c['fecha_llegada_estimada']): ?> &nbsp;·&nbsp; Llegada estimada: <?= fechaCorta($c['fecha_llegada_estimada']) ?><?php endif; ?></div>

    <h2>DATOS DE LOS INTERESADOS</h2>
    <div>Solicitante: <?= h(strtoupper(trim(($c['un'] ?? '') . ' ' . ($c['ua'] ?? '')))) ?> (<?= h($cfg['empresa_nombre'] ?? '') ?>)</div>
    <div>Domicilio: <?= h(strtoupper($cfg['empresa_direccion'] ?? '')) ?></div>

    <div class="cols" style="margin-top: 12px;">
        <div class="sec">ORIGEN</div>
        <div class="sec">DESTINO</div>
        <div><strong>UPP: <?= h($c['origen_upp'] ?: '-') ?></strong></div>
        <div><strong><?= h(strtoupper($cfg['empresa_nombre'] ?? '')) ?></strong></div>
        <div>Nombre: <?= h(strtoupper($c['origen_nombre'] ?? '')) ?></div>
        <div>RFC: <?= h($cfg['empresa_rfc'] ?? '-') ?></div>
        <div>Telefono: <?= h($c['telefono'] ?? '-') ?><?= $c['email'] ? ' · ' . h($c['email']) : '' ?></div>
        <div>Domicilio: <?= h(strtoupper($cfg['empresa_direccion'] ?? '')) ?></div>
        <div>Localidad: <?= h(strtoupper($c['origen_localidad'] ?? '-')) ?></div>
        <div>Telefono: <?= h($cfg['empresa_telefono'] ?? '-') ?></div>
        <div>Municipio: <?= h(strtoupper($c['origen_municipio'] ?? '-')) ?></div>
        <div></div>
        <div>Estado: <?= h(strtoupper($c['origen_estado'] ?? '-')) ?></div>
        <div></div>
    </div>

    <h2>DATOS DE LOS BOVINOS</h2>
    <div style="display: flex; justify-content: space-between;">
        <span>Especie: <strong>BOVINO</strong></span>
        <span>Cantidad: <strong><?= count($bovinos) ?></strong> (<?= $machos ?> machos, <?= count($bovinos) - $machos ?> hembras)</span>
    </div>
    <table>
        <thead><tr><th>#</th><th>Num. arete</th><th>Arete faltante</th><th>Sexo</th><th>Meses</th><th>Raza</th><th>Peso est.</th></tr></thead>
        <tbody>
        <?php foreach ($bovinos as $i => $b): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="arete"><?= h($b['numero_arete']) ?></td>
                <td><?= h($b['arete_faltante'] ?: '-') ?></td>
                <td><?= h($b['sexo']) ?></td>
                <td><?= $b['meses'] ? (int)$b['meses'] : '-' ?></td>
                <td><?= h(strtoupper($b['raza'] ?? '-')) ?></td>
                <td><?= $b['peso_estimado'] ? number_format($b['peso_estimado'], 0) . ' kg' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="firmas">
        <div>Entrega (origen)</div>
        <div>Transportista</div>
        <div>Recibe (destino)</div>
    </div>
</div>
</body>
</html>
