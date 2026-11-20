<?php
// ============================================
// VENTAS - Corrales de venta y su precio por kg
// El clasificador pesa a los animales y los pasa a un corral de venta (Clasificacion > Volver a pesar).
// Cada corral de venta tiene un solo precio por kg: el minimo es lo invertido del corral
// entre sus kilos; el admin fija el precio (o se usa el sugerido = minimo + margen).
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
        if (!$esAdmin) throw new Exception('Solo el administrador fija precios');
        exigirCSRF();
        if (($_POST['accion'] ?? '') === 'margen') {
            $m = (float)($_POST['margen'] ?? 0);
            if ($m < 0 || $m > 100) throw new Exception('Margen no valido');
            $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'margen_venta'")->execute([$m]);
            $msg = "Margen sobre el costo: $m%";
        } else {
            $cid = (int)($_POST['corral_id'] ?? 0);
            $txt = trim($_POST['precio'] ?? '');
            $precio = $txt === '' ? null : round((float)$txt, 2);
            if ($precio !== null && $precio <= 0) throw new Exception('Precio no valido');
            $stmt = $db->prepare("UPDATE corrales SET precio_venta_kg = ?, precio_fecha = IF(? IS NULL, NULL, NOW()) WHERE id = ? AND es_venta = 1");
            $stmt->execute([$precio, $precio, $cid]);
            $nombre = $db->query("SELECT nombre FROM corrales WHERE id = $cid")->fetchColumn();
            registrarLog($_SESSION['user_id'], 'precio_corral', "$nombre: " . ($precio ?? 'automatico'));
            $msg = $precio === null ? "$nombre usa el precio sugerido" : "$nombre en venta a " . dinero($precio) . '/kg';
        }
        header('Location: ' . BASE_URL . '/ventas/precios?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$precios = preciosVenta($db);
$corrales = $precios['corrales'];
$animales = $db->query("
    SELECT b.id, b.id_siniiga, b.sexo, b.categoria, b.corral_id, " . sqlPesoActual('b') . " AS peso
    FROM bovinos b JOIN corrales c ON c.id = b.corral_id
    WHERE c.es_venta = 1 AND b.estado = 'en_corral' ORDER BY peso DESC
")->fetchAll(PDO::FETCH_ASSOC);
$porCorral = [];
foreach ($animales as $a) $porCorral[$a['corral_id']][] = $a;
$margen = margenVenta();

$titulo = 'Corrales de venta';
$modulo = 'ventas';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center;">
    <a href="<?= BASE_URL ?>/ventas" class="btn btn-secondary">Volver</a>
    <?php if (Permisos::puedeCrear('ventas')): ?><a href="<?= BASE_URL ?>/ventas/crear" class="btn btn-primary">Nueva venta</a><?php endif; ?>
    <?php if ($esAdmin): ?>
    <form method="POST" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <?= csrfField() ?><input type="hidden" name="accion" value="margen">
        <label style="font-weight:600;">Margen sobre el costo para el precio sugerido:</label>
        <input type="number" name="margen" step="0.5" min="0" max="100" value="<?= h($margen) ?>" style="<?= ESTILO_INPUT ?> width:90px;"> %
        <button class="btn btn-secondary btn-sm" type="submit">Guardar</button>
    </form>
    <?php endif; ?>
</div>

<div class="alert alert-info alert-fija" style="margin-bottom:20px;">
    El clasificador pesa a los animales y los pasa a un corral de venta (<strong>Clasificacion → Volver a pesar</strong>).
    Todo el corral se vende al mismo precio por kilo. <strong>Minimo</strong> = lo invertido en el corral entre sus kilos;
    <strong>sugerido</strong> = minimo + <?= h($margen) ?>%. <?= $esAdmin ? 'Tu puedes fijar otro precio.' : 'El precio lo fija el administrador.' ?>
</div>

<div class="grid-corrales-venta">
<?php foreach ($corrales as $c): $lista = $porCorral[$c['id']] ?? []; ?>
    <div class="table-container corral-venta">
        <div class="table-header">
            <h2><?= h($c['nombre']) ?></h2>
            <span class="pill-n"><?= $c['n'] ?> / <?= (int)$c['capacidad_maxima'] ?> animales</span>
        </div>
        <?php if ($c['n']): ?>
        <div class="precio-grande">
            <small><?= $c['fijo'] !== null ? 'Precio fijado' . ($c['precio_fecha'] ? ' el ' . fechaCorta(substr($c['precio_fecha'], 0, 10)) : '') : 'Precio sugerido' ?></small>
            <strong><?= dinero($c['recomendado']) ?><span>/kg</span></strong>
        </div>
        <div class="datos-corral">
            <div><small>Minimo</small><strong><?= dinero($c['minimo']) ?>/kg</strong></div>
            <div><small>Sugerido</small><strong><?= dinero($c['sugerido']) ?>/kg</strong></div>
            <div><small>Kilos</small><strong><?= number_format($c['peso'], 0) ?> kg</strong></div>
            <div><small>Peso por animal</small><strong><?= number_format($c['peso_min_animal'], 0) ?>-<?= number_format($c['peso_max_animal'], 0) ?></strong></div>
            <div><small>Valor del corral</small><strong><?= dinero($c['peso'] * $c['recomendado']) ?></strong></div>
            <?php if ($esAdmin): ?><div><small>Ganancia estimada</small><strong style="color:<?= $c['peso'] * $c['recomendado'] >= $c['costo'] ? '#28a745' : '#dc3545' ?>;"><?= dinero($c['peso'] * $c['recomendado'] - $c['costo']) ?></strong></div><?php endif; ?>
        </div>
        <?php if ($esAdmin && $c['fijo'] !== null && $c['fijo'] < $c['minimo']): ?>
            <p style="padding:0 20px;color:#dc3545;font-weight:600;">El precio fijado esta abajo del minimo: se pierde dinero.</p>
        <?php endif; ?>
        <?php if ($esAdmin): ?>
        <form method="POST" style="padding:0 20px 15px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
            <?= csrfField() ?><input type="hidden" name="corral_id" value="<?= $c['id'] ?>">
            <div style="flex:1;min-width:140px;"><label style="<?= ESTILO_LABEL ?>">Fijar precio $/kg (vacio = sugerido)</label>
                <input type="number" name="precio" step="0.5" min="0" value="<?= $c['fijo'] !== null ? h($c['fijo']) : '' ?>" placeholder="<?= h($c['sugerido']) ?>" style="<?= ESTILO_INPUT ?>"></div>
            <button type="submit" class="btn btn-primary">Guardar</button>
        </form>
        <?php endif; ?>
        <details style="padding:0 20px 15px;">
            <summary style="cursor:pointer;color:#0b4f86;font-weight:600;">Ver los <?= $c['n'] ?> animales</summary>
            <table style="margin-top:8px;"><thead><tr><th>Arete</th><th>Sexo</th><th>Categoria</th><th>Peso</th></tr></thead><tbody>
            <?php foreach ($lista as $a): ?>
                <tr><td><a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($a['id_siniiga']) ?>"><?= h($a['id_siniiga']) ?></a></td><td><?= $a['sexo'] ?></td><td><?= h($a['categoria']) ?></td><td><?= number_format($a['peso'], 1) ?> kg</td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </details>
        <?php else: ?>
            <p style="padding:30px 20px;color:var(--tenue);text-align:center;">Vacio. El clasificador pasa aqui a los animales listos para vender.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php if (!$corrales): ?><div class="table-container"><p style="padding:30px;text-align:center;color:var(--tenue);">No hay corrales de venta. Crea uno en Corrales marcandolo como corral de venta.</p></div><?php endif; ?>

<style>
.grid-corrales-venta { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }
.corral-venta { margin: 0; }
.pill-n { background: var(--f-verde); color: #0b4f86; border-radius: 12px; padding: 3px 10px; font-weight: 600; font-size: 0.85rem; }
.precio-grande { padding: 5px 20px 10px; }
.precio-grande small { display: block; color: var(--tenue); text-transform: uppercase; font-size: 0.72rem; font-weight: 600; }
.precio-grande strong { font-size: 2.2rem; color: #0b4f86; }
.precio-grande span { font-size: 1rem; color: var(--tenue); }
.datos-corral { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 0 20px 15px; }
.datos-corral > div { background: var(--suave); border-radius: 6px; padding: 6px 10px; }
.datos-corral small { display: block; color: var(--tenue); font-size: 0.7rem; text-transform: uppercase; font-weight: 600; }
@media (max-width: 500px) { .datos-corral { grid-template-columns: 1fr 1fr; } }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
