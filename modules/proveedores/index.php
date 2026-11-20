<?php
// ============================================
// PROVEEDORES - Con quien ya tenemos relacion
// Ranchos que nos venden ganado (UPP), proveedores de alimento, medicamento y otros.
// ============================================

$titulo = 'Proveedores';
$modulo = 'proveedores';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/proveedores_lib.php';

$db = Database::getInstance()->getConnection();
$tipo = isset(TIPOS_PROVEEDOR[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$q = trim($_GET['q'] ?? '');
$verInactivos = !empty($_GET['inactivos']);
$verMontos = puedeVerPrecios();

$where = [];
$params = [];
if (!$verInactivos) $where[] = 'p.activo = 1';
if ($tipo) { $where[] = 'FIND_IN_SET(?, p.tipo)'; $params[] = $tipo; }
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR p.upp LIKE ? OR p.rfc LIKE ? OR p.telefono LIKE ? OR p.contacto LIKE ? OR p.municipio LIKE ?)';
    array_push($params, ...array_fill(0, 6, "%$q%"));
}
$stmt = $db->prepare("SELECT p.* FROM proveedores p " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY p.activo DESC, p.nombre");
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
$resumen = resumenProveedores($db);

$conteo = ['' => 0];
foreach ($db->query("SELECT tipo FROM proveedores WHERE activo = 1") as $r) {
    $conteo['']++;
    foreach (explode(',', $r['tipo']) as $t) $conteo[$t] = ($conteo[$t] ?? 0) + 1;
}
// Ordenar: los que tienen relacion mas reciente primero
usort($proveedores, fn($a, $b) => [$b['activo'], $resumen[$b['id']]['ultima'] ?? ''] <=> [$a['activo'], $resumen[$a['id']]['ultima'] ?? '']);
?>

<div class="page-actions">
    <form method="GET" class="buscador">
        <?php if ($tipo): ?><input type="hidden" name="tipo" value="<?= h($tipo) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre, UPP, RFC, telefono, municipio...">
    </form>
    <?php if (Permisos::puedeCrear('proveedores')): ?><a href="<?= BASE_URL ?>/proveedores/editar" class="btn btn-primary">+ Nuevo proveedor</a><?php endif; ?>
</div>

<div class="tabs">
    <a href="?<?= $q !== '' ? 'q=' . urlencode($q) : '' ?>" class="tab <?= $tipo === '' ? 'on' : '' ?>">Todos <span><?= $conteo[''] ?></span></a>
    <?php foreach (TIPOS_PROVEEDOR as $k => $txt): ?>
        <a href="?tipo=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="tab <?= $tipo === $k ? 'on' : '' ?>"><?= $txt ?> <span><?= (int)($conteo[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
    <a href="?<?= http_build_query(array_filter(['tipo' => $tipo, 'q' => $q, 'inactivos' => $verInactivos ? null : 1])) ?>" class="tab tab-derecha"><?= $verInactivos ? 'Ocultar inactivos' : 'Ver inactivos' ?></a>
</div>

<?php if ($proveedores): ?>
<div class="lista-prov">
    <?php foreach ($proveedores as $p): $r = $resumen[$p['id']] ?? null; ?>
    <a class="prov-card <?= $p['activo'] ? '' : 'inactivo' ?>" href="<?= BASE_URL ?>/proveedores/ver?id=<?= $p['id'] ?>">
        <div class="prov-top">
            <div class="prov-avatar"><?= h(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></div>
            <div style="min-width:0;">
                <div class="prov-nombre"><?= h($p['nombre']) ?></div>
                <div class="prov-chips"><?= chipsTipoProveedor($p['tipo']) ?><?= $p['activo'] ? '' : '<span class="chip chip-gris">Inactivo</span>' ?></div>
            </div>
        </div>
        <div class="prov-datos">
            <?php if ($p['upp']): ?><div><span>UPP</span><?= h($p['upp']) ?></div><?php endif; ?>
            <div><span>Telefono</span><?= h($p['telefono'] ?: '—') ?></div>
            <?php if ($p['contacto']): ?><div><span>Contacto</span><?= h($p['contacto']) ?></div><?php endif; ?>
            <?php $lugar = trim(implode(', ', array_filter([$p['municipio'], $p['estado']]))); if ($lugar): ?><div><span>Lugar</span><?= h($lugar) ?></div><?php endif; ?>
        </div>
        <div class="prov-pie">
            <?php if ($r): ?>
                <span><?= (int)$r['ops'] ?> compra(s)</span>
                <span>Ultima: <?= fechaCorta($r['ultima']) ?></span>
                <?php if ($verMontos && $r['monto'] > 0): ?><strong><?= dinero($r['monto']) ?></strong><?php endif; ?>
            <?php else: ?>
                <span class="muted">Sin compras registradas</span>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div class="vacio">
        <p>No hay proveedores<?= $q !== '' ? ' que coincidan con "' . h($q) . '"' : '' ?>.</p>
        <?php if (Permisos::puedeCrear('proveedores')): ?><a href="<?= BASE_URL ?>/proveedores/editar" class="btn btn-primary">Agregar proveedor</a><?php endif; ?>
    </div>
<?php endif; ?>

<p class="nota-pie">Los ranchos se agregan solos al registrar una compra de ganado (por su UPP). Los de alimento y medicamento, al registrar una entrada a bodega o al pedir una solicitud.</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
