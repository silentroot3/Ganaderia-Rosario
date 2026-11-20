<?php
// ============================================
// CLASIFICACION - Volver a pesar los bovinos de un corral
// El clasificador captura el peso nuevo de cada animal; el sistema sugiere el
// corral que le toca por peso y el clasificador decide si lo mueve y si cambia
// su categoria. El admin ve ademas el costo por kilo (precio minimo de venta).
// ============================================

$titulo = 'Volver a pesar';
$modulo = 'clasificacion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/asignacion_corrales.php';
require_once __DIR__ . '/../../includes/costos.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
Permisos::protegerPagina('clasificacion', 'ver');
$db = Database::getInstance()->getConnection();
$puede = Permisos::puedeEditar('clasificacion');
$verCostos = puedeVerPrecios();
$corral_id = (int)($_GET['corral'] ?? $_POST['corral'] ?? 0);
$error = '';

// Corrales normales (sin hospital, venta ni recepcion)
$corrales = $db->query("
    SELECT * FROM corrales
    WHERE es_hospital = 0 AND es_recepcion = 0
    ORDER BY es_venta, sexo_permitido, peso_min, nombre
")->fetchAll(PDO::FETCH_ASSOC);
$porId = array_column($corrales, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$puede) throw new Exception('No tienes permiso para registrar pesajes');
        exigirCSRF();
        $filas = $_POST['b'] ?? [];
        $db->beginTransaction();
        $pesados = 0; $movidos = 0; $recat = 0;
        foreach ($filas as $bid => $f) {
            $bid = (int)$bid;
            $peso = (float)str_replace(',', '.', $f['peso'] ?? '');
            if ($peso <= 0) continue;   // no se peso
            if ($peso < 30 || $peso > 1500) throw new Exception("Peso no valido ($peso kg)");

            $stmt = $db->prepare("SELECT b.*, " . sqlPesoActual('b') . " AS peso_actual FROM bovinos b
                                  WHERE b.id = ? AND b.corral_id = ? AND b.en_hospital = 0 AND b.estado = 'en_corral' FOR UPDATE");
            $stmt->execute([$bid, $corral_id]);
            $b = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new Exception('Un bovino ya no esta en este corral; recarga la pagina');

            registrarPesaje($db, $bid, $peso, $_SESSION['user_id'], 'Repesaje en corral');
            $pesados++;

            $cat = $f['categoria'] ?? $b['categoria'];
            $cats = categoriasPorSexo($b['sexo']);
            if ($cat !== $b['categoria'] && isset($cats[$cat])) {
                $db->prepare("UPDATE bovinos SET categoria = ?, castrado = ? WHERE id = ?")->execute([$cat, $cat === 'Novillo' ? 1 : 0, $bid]);
                $recat++;
            }
            $db->prepare("INSERT INTO clasificaciones (bovino_id, sexo, peso, edad_meses, usuario_id) VALUES (?, ?, ?, ?, ?)")
               ->execute([$bid, $b['sexo'], $peso, $b['edad_meses'], $_SESSION['user_id']]);

            $destino = (int)($f['destino'] ?? 0);
            if ($destino && $destino !== (int)$b['corral_id']) {
                $stmt = $db->prepare("SELECT * FROM corrales WHERE id = ? AND es_hospital = 0 AND es_recepcion = 0 AND estado != 'Mantenimiento' FOR UPDATE");
                $stmt->execute([$destino]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$c) throw new Exception('Corral destino no valido');
                if ($c['sexo_permitido'] !== 'Mixto' && $c['sexo_permitido'] !== $b['sexo']) {
                    throw new Exception("{$b['id_siniiga']}: el corral {$c['nombre']} no es para " . ($b['sexo'] === 'M' ? 'machos' : 'hembras'));
                }
                if ($c['ocupacion_actual'] >= $c['capacidad_maxima']) throw new Exception("El corral {$c['nombre']} esta lleno");
                moverBovinoACorral($db, $bid, $destino, $_SESSION['user_id'], 'traslado',
                    "Repesaje: " . number_format($peso, 1) . " kg" . ($cat !== $b['categoria'] ? ", ahora $cat" : ''));
                $movidos++;
            }
        }
        if (!$pesados) throw new Exception('Captura el peso de al menos un bovino');
        $db->commit();
        registrarLog($_SESSION['user_id'], 'repesaje', "Corral $corral_id: $pesados pesados, $movidos movidos");
        $msg = "$pesados bovino(s) pesados" . ($movidos ? ", $movidos cambiados de corral" : '') . ($recat ? ", $recat con categoria nueva" : '');
        header('Location: ' . BASE_URL . '/clasificacion/pesar?corral=' . $corral_id . '&msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$bovinos = [];
$costos = [];
if ($corral_id && isset($porId[$corral_id])) {
    $stmt = $db->prepare("
        SELECT b.id, b.id_siniiga, b.sexo, b.categoria, b.edad_meses, b.peso_entrada,
               " . sqlPesoActual('b') . " AS peso_actual,
               COALESCE((SELECT MAX(p.fecha) FROM pesajes p WHERE p.bovino_id = b.id), DATE(b.created_at)) AS fecha_peso
        FROM bovinos b
        WHERE b.corral_id = ? AND b.en_hospital = 0 AND b.estado = 'en_corral'
        ORDER BY b.id_siniiga
    ");
    $stmt->execute([$corral_id]);
    $bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($verCostos && $bovinos) $costos = costosBovinos(array_map(fn($x) => (int)$x['id'], $bovinos));
}
$corral = $porId[$corral_id] ?? null;

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
    <a href="<?= BASE_URL ?>/clasificacion" class="btn btn-secondary">Volver</a>
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <label style="font-weight:600;">Corral:</label>
        <select name="corral" onchange="this.form.submit()" style="<?= ESTILO_INPUT ?> width:auto; min-width:220px;">
            <option value="">-- Elegir corral --</option>
            <?php foreach ($corrales as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $corral_id === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['nombre']) ?> (<?= (int)$c['ocupacion_actual'] ?> animales, <?= $c['es_venta'] ? 'corral de venta' : number_format((float)$c['peso_min'], 0) . '-' . ($c['peso_max'] >= 9999 ? 'mas' : number_format((float)$c['peso_max'], 0)) . ' kg' ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$corral): ?>
    <div class="table-container"><p style="padding:30px;color:var(--tenue);text-align:center;">Elige el corral que vas a pesar. Captura el peso de cada animal y el sistema te dice a que corral le toca pasar.</p></div>
<?php elseif (!$bovinos): ?>
    <div class="table-container"><p style="padding:30px;color:var(--tenue);text-align:center;">El corral <?= h($corral['nombre']) ?> no tiene animales para pesar.</p></div>
<?php else: ?>
<form method="POST" id="formPesar">
    <?= csrfField() ?><input type="hidden" name="corral" value="<?= $corral_id ?>">
    <div class="table-container">
        <div class="table-header">
            <h2><?= h($corral['nombre']) ?> &middot; <?= count($bovinos) ?> animales &middot; <?= $corral['es_venta'] ? 'corral de venta' : number_format((float)$corral['peso_min'], 0) . '-' . ($corral['peso_max'] >= 9999 ? 'mas' : number_format((float)$corral['peso_max'], 0)) . ' kg' ?></h2>
            <small style="color:var(--tenue);">Se pesan cada 15 dias o cuando se necesite. Deja vacio el peso de los que no pesaste. Enter pasa al siguiente.
                <?php if ($corral['es_venta']): ?><br><strong>Corral de venta:</strong> todos se venden al mismo precio por kg (Ventas → Corrales de venta).<?php endif; ?></small>
        </div>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr>
                <th>Arete</th><th>Peso anterior</th><th style="width:120px;">Peso nuevo (kg)</th><th>Cambio</th><th>Gana por dia</th>
                <th>Categoria</th><th>Corral</th>
                <?php if ($verCostos): ?><th title="Lo invertido en el animal entre su peso">Costo por kg</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($bovinos as $b):
                $cats = categoriasPorSexo($b['sexo']);
                $dias = max(1, (int)((strtotime('today') - strtotime($b['fecha_peso'])) / 86400));
                $inv = $costos[(int)$b['id']]['total'] ?? 0; ?>
                <tr data-ant="<?= (float)$b['peso_actual'] ?>" data-dias="<?= $dias ?>" data-sexo="<?= $b['sexo'] ?>" data-actual="<?= $corral_id ?>" data-inv="<?= round($inv, 2) ?>">
                    <td><a href="<?= BASE_URL ?>/trazabilidad/ver?arete=<?= h($b['id_siniiga']) ?>" target="_blank"><strong><?= h($b['id_siniiga']) ?></strong></a>
                        <br><small style="color:var(--tenue);"><?= $b['sexo'] === 'M' ? 'Macho' : 'Hembra' ?><?= $b['edad_meses'] ? ', ' . (int)$b['edad_meses'] . ' meses' : '' ?></small>
                        <?php if ($puede): ?><br><a href="<?= BASE_URL ?>/clasificacion/enviar-vet?id=<?= $b['id'] ?>" style="color:#dc3545;font-size:0.8rem;">Mandar a veterinaria</a><?php endif; ?></td>
                    <td><?= number_format($b['peso_actual'], 1) ?> kg<br><small style="color:var(--tenue);"><?= fechaCorta($b['fecha_peso']) ?> (hace <?= $dias ?> d)</small>
                        <?php if ($dias >= 15): ?><br><span class="toca-pesar">Toca pesar</span><?php endif; ?></td>
                    <td><input type="number" name="b[<?= $b['id'] ?>][peso]" class="inp-peso" step="0.1" min="30" max="1500" inputmode="decimal" <?= $puede ? '' : 'disabled' ?>
                               value="<?= h($_POST['b'][$b['id']]['peso'] ?? '') ?>" style="<?= ESTILO_INPUT ?> padding:8px;"></td>
                    <td class="c-dif">-</td>
                    <td class="c-gdp">-</td>
                    <td><select name="b[<?= $b['id'] ?>][categoria]" <?= $puede ? '' : 'disabled' ?> style="<?= ESTILO_INPUT ?> padding:7px;">
                        <?php foreach ($cats as $k => $desc): ?><option value="<?= $k ?>" <?= $b['categoria'] === $k ? 'selected' : '' ?> title="<?= h($desc) ?>"><?= $k ?></option><?php endforeach; ?>
                        <?php if (!isset($cats[$b['categoria']])): ?><option value="<?= h($b['categoria']) ?>" selected><?= h($b['categoria']) ?></option><?php endif; ?>
                    </select></td>
                    <td><select name="b[<?= $b['id'] ?>][destino]" class="sel-dest" <?= $puede ? '' : 'disabled' ?> style="<?= ESTILO_INPUT ?> padding:7px;">
                        <?php foreach ($corrales as $c):
                            if ($c['sexo_permitido'] !== $b['sexo'] && $c['sexo_permitido'] !== 'Mixto') continue;
                            if ($c['estado'] === 'Mantenimiento' && (int)$c['id'] !== $corral_id) continue;
                            $lleno = (int)$c['id'] !== $corral_id && $c['ocupacion_actual'] >= $c['capacidad_maxima']; ?>
                            <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $corral_id ? 'selected' : '' ?> <?= $lleno ? 'disabled' : '' ?>>
                                <?= (int)$c['id'] === $corral_id ? 'Se queda en ' : ($c['es_venta'] ? 'Pasar a venta: ' : 'Mover a ') ?><?= h($c['nombre']) ?><?= $lleno ? ' (lleno)' : '' ?></option>
                        <?php endforeach; ?>
                    </select><small class="c-sug" style="display:block;color:#e67e22;"></small></td>
                    <?php if ($verCostos): ?>
                    <td class="c-kg" style="font-weight:700;"><?= $b['peso_actual'] > 0 ? dinero($inv / $b['peso_actual']) : '-' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($puede): ?>
        <div style="padding:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <span id="resumen" style="color:var(--tenue);"></span>
            <button type="submit" class="btn btn-primary" style="padding:12px 30px;">Guardar pesajes</button>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php if ($verCostos): ?>
<p style="color:var(--tenue);font-size:0.85rem;margin-top:10px;">
    <strong>Costo por kg</strong> = todo lo invertido en el animal (compra, comida, medicina y gastos del rancho) entre su peso.
    Es el precio por kilo del que <strong>no conviene bajar</strong> al venderlo. Se recalcula con el peso nuevo.
</p>
<?php endif; ?>

<style>.toca-pesar { background:var(--f-amarillo); color:var(--t-amarillo); border-radius:10px; padding:1px 8px; font-size:0.75rem; font-weight:700; }</style>
<script>
(function () {
    var CORRALES = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'n' => $c['nombre'], 's' => $c['sexo_permitido'],
        'min' => (float)$c['peso_min'], 'max' => $c['peso_max'] === null ? 99999 : (float)$c['peso_max'], 'oc' => (int)$c['ocupacion_actual'], 'cap' => (int)$c['capacidad_maxima'],
        'm' => $c['estado'] === 'Mantenimiento', 'v' => (bool)$c['es_venta']], $corrales), JSON_HEX_TAG) ?>;
    var fmt = function (n, d) { return n.toLocaleString('es-MX', {minimumFractionDigits: d, maximumFractionDigits: d}); };
    function sugerido(sexo, peso, actual) {
        var mejor = null;
        CORRALES.forEach(function (c) {
            if (c.v || c.m || (c.s !== sexo && c.s !== 'Mixto') || peso < c.min || peso > c.max) return;
            if (c.id === actual) { mejor = c; mejor._q = true; return; }
            if (mejor && mejor._q) return;
            if (c.oc >= c.cap) return;
            if (!mejor || (c.s === sexo && mejor.s !== sexo) || ((c.s === sexo) === (mejor.s === sexo) && c.oc < mejor.oc)) mejor = c;
        });
        return mejor;
    }
    var filas = document.querySelectorAll('#formPesar tbody tr');
    function calc(tr) {
        var inp = tr.querySelector('.inp-peso'), p = parseFloat(inp.value), ant = +tr.dataset.ant, dias = +tr.dataset.dias;
        var dif = tr.querySelector('.c-dif'), gdp = tr.querySelector('.c-gdp'), sug = tr.querySelector('.c-sug'), sel = tr.querySelector('.sel-dest'), kg = tr.querySelector('.c-kg');
        var actual = +tr.dataset.actual;
        if (!(p > 0)) { dif.textContent = '-'; gdp.textContent = '-'; sug.textContent = ''; sel.value = actual; if (kg && ant > 0) kg.textContent = '$' + fmt(+tr.dataset.inv / ant, 2); return; }
        var d = p - ant;
        dif.textContent = (d >= 0 ? '+' : '') + fmt(d, 1) + ' kg'; dif.style.color = d >= 0 ? '#28a745' : '#dc3545';
        gdp.textContent = fmt(d / dias, 2) + ' kg';
        if (kg) kg.textContent = '$' + fmt(+tr.dataset.inv / p, 2);
        var s = sugerido(tr.dataset.sexo, p, actual);
        if (!s) { sug.textContent = 'Ningun corral con espacio para ' + fmt(p, 0) + ' kg'; }
        else if (s.id === actual) { sug.textContent = ''; sel.value = actual; }
        else { sug.textContent = 'Por su peso le toca ' + s.n; if (!sel.dataset.tocado) sel.value = s.id; }
        resumen();
    }
    function resumen() {
        var n = 0, m = 0;
        filas.forEach(function (tr) { if (parseFloat(tr.querySelector('.inp-peso').value) > 0) n++; if (+tr.querySelector('.sel-dest').value !== +tr.dataset.actual) m++; });
        var r = document.getElementById('resumen'); if (r) r.textContent = n + ' de ' + filas.length + ' pesados' + (m ? ' · ' + m + ' cambian de corral' : '');
    }
    filas.forEach(function (tr, i) {
        var inp = tr.querySelector('.inp-peso');
        inp.addEventListener('input', function () { calc(tr); });
        inp.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var sig = filas[i + 1] && filas[i + 1].querySelector('.inp-peso');
            if (sig) sig.focus(); else document.querySelector('#formPesar button[type=submit]').focus();
        });
        tr.querySelector('.sel-dest').addEventListener('change', function () { this.dataset.tocado = 1; resumen(); });
        if (inp.value) calc(tr);
    });
    resumen();
    var primero = document.querySelector('.inp-peso:not([disabled])'); if (primero) primero.focus();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
