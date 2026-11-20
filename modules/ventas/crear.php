<?php
// ============================================
// VENTAS - Nueva venta de un lote (HU-014)
//  - Solo se ofrecen animales en corral, sin hospital y SIN retiro de medicina activo (HU-011)
//  - Agrupados por corral: los de un corral de venta comparten precio por kg
//  - El vendedor ve precio minimo y recomendado (nunca lo invertido ni la ganancia)
//  - Abajo del recomendado solo se vende con un descuento autorizado por el admin;
//    desde aqui mismo el vendedor manda la solicitud de descuento
//  - Al confirmar: animales quedan 'vendido', liberan corral, se registra el movimiento y
//    las dosis apartadas que no se aplicaron regresan al inventario
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/tratamientos_lib.php';
require_once __DIR__ . '/../../includes/precios_venta.php';
require_once __DIR__ . '/../../includes/bajas_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();
$error = '';
$esAdmin = Permisos::esAdmin();

/** No. de factura: prefijo de la ganaderia + año, mes, dia y hora (ej. GER-20261112-153045) */
function generarNumeroFactura($db) {
    $prefijo = strtoupper(configValor('empresa_prefijo', 'GER') ?: 'GER');
    $base = $prefijo . '-' . date('Ymd-His');
    $num = $base;
    $stmt = $db->prepare("SELECT COUNT(*) FROM ventas WHERE factura = ?");
    for ($i = 2; $stmt->execute([$num]) && $stmt->fetchColumn(); $i++) $num = "$base-$i";
    return $num;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('ventas', 'crear');
        exigirCSRF();
        $seleccion = array_map('intval', array_keys($_POST['sel'] ?? []));
        if (!$seleccion) throw new Exception('Selecciona al menos un animal');
        $precios = preciosVenta($db);

        // ---------- Solicitud de descuento ----------
        if (($_POST['accion'] ?? '') === 'descuento') {
            $pedido = round((float)($_POST['desc_precio'] ?? 0), 2);
            $motivo = trim($_POST['desc_motivo'] ?? '');
            if ($pedido <= 0) throw new Exception('Escribe el precio por kilo que pide el cliente');
            if (mb_strlen($motivo) < 10) throw new Exception('Explica brevemente por que se pide el descuento');
            $kg = 0; $rec = 0; $min = 0;
            foreach ($seleccion as $bid) {
                $p = $precios['bovinos'][$bid] ?? null;
                if (!$p) throw new Exception('Un animal seleccionado ya no esta disponible');
                $q = $db->prepare("SELECT id_siniiga, en_hospital FROM bovinos WHERE id = ?");
                $q->execute([$bid]);
                $bx = $q->fetch();
                if ($bx['en_hospital']) throw new Exception("{$bx['id_siniiga']} esta en veterinaria");
                if (tieneRetiroActivo($bid)) throw new Exception("{$bx['id_siniiga']} tiene medicina en retiro (hasta " . fechaCorta(fechaFinRetiro($bid)) . "); no se puede vender todavia");
                $kg += $p['peso']; $rec += $p['recomendado'] * $p['peso']; $min += $p['minimo'] * $p['peso'];
            }
            $rec = $kg > 0 ? round($rec / $kg, 2) : 0;
            $min = $kg > 0 ? round($min / $kg, 2) : 0;
            if ($pedido >= $rec) throw new Exception('Ese precio no es descuento: el recomendado es ' . dinero($rec) . '/kg. Puedes vender directo.');
            $cliente = (int)($_POST['comprador_id'] ?? 0)
                ? $db->query("SELECT nombre FROM compradores WHERE id = " . (int)$_POST['comprador_id'])->fetchColumn()
                : trim($_POST['nuevo_nombre'] ?? '');

            $db->beginTransaction();
            $db->prepare("INSERT INTO solicitudes_descuento (usuario_id, cliente, cantidad, precio_recomendado, precio_minimo, precio_solicitado, motivo)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$_SESSION['user_id'], $cliente ?: null, count($seleccion), $rec, $min, $pedido, $motivo]);
            $sid = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO solicitud_descuento_bovinos (solicitud_id, bovino_id) VALUES (?, ?)");
            foreach ($seleccion as $bid) $ins->execute([$sid, $bid]);
            $db->commit();
            crearAlerta('solicitud_descuento', "Descuento #$sid: " . ($_SESSION['nombre'] ?? 'Vendedor') . " pide " . dinero($pedido) . "/kg (recomendado " . dinero($rec)
                . ") por " . count($seleccion) . " animal(es)" . ($cliente ? " para $cliente" : '') . ". Motivo: $motivo", 'admin');
            registrarLog($_SESSION['user_id'], 'descuento_solicitar', "#$sid " . count($seleccion) . " cabezas a $pedido/kg");
            header('Location: ' . BASE_URL . '/ventas/descuentos?msg=' . urlencode("Solicitud #$sid enviada al administrador. Te avisara si la autoriza."));
            exit;
        }

        // ---------- Venta ----------
        $comprador_id = (int)($_POST['comprador_id'] ?? 0);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) throw new Exception('Fecha invalida');

        // Descuento autorizado (opcional)
        $desc = null;
        if ((int)($_POST['descuento_id'] ?? 0)) {
            $desc = descuentosAprobados($db, $_SESSION['user_id'], $esAdmin)[(int)$_POST['descuento_id']] ?? null;
            if (!$desc) throw new Exception('El descuento elegido ya no esta autorizado o ya se uso');
        }

        $db->beginTransaction();

        // Comprador nuevo
        if ($comprador_id === 0) {
            $nombre = trim($_POST['nuevo_nombre'] ?? '');
            if ($nombre === '') throw new Exception('Selecciona un comprador o captura uno nuevo');
            $db->prepare("INSERT INTO compradores (nombre, rfc, telefono) VALUES (?, ?, ?)")
               ->execute([$nombre, trim($_POST['nuevo_rfc'] ?? '') ?: null, trim($_POST['nuevo_tel'] ?? '') ?: null]);
            $comprador_id = (int)$db->lastInsertId();
        } else {
            $q = $db->prepare("SELECT id FROM compradores WHERE id = ? AND activo = 1");
            $q->execute([$comprador_id]);
            if (!$q->fetchColumn()) throw new Exception('Comprador no valido');
        }

        $ivaPct = max(0, min(100, (float)($_POST['iva'] ?? 0)));
        $lineas = [];
        $subtotal = 0;
        $usoDescuento = false;
        foreach ($seleccion as $bid) {
            $peso = (float)($_POST['peso'][$bid] ?? 0);
            $precio = round((float)($_POST['precio'][$bid] ?? 0), 2);
            if ($peso <= 0 || $precio <= 0) throw new Exception('Cada animal necesita peso final y precio por kilo');

            // Revalidar en servidor (el formulario pudo quedar desactualizado)
            $q = $db->prepare("SELECT id, id_siniiga, estado, en_hospital, corral_id FROM bovinos WHERE id = ? FOR UPDATE");
            $q->execute([$bid]);
            $b = $q->fetch();
            if (!$b) throw new Exception('Un animal seleccionado ya no existe');
            if ($b['estado'] !== 'en_corral' || $b['en_hospital']) throw new Exception("{$b['id_siniiga']} ya no esta disponible para venta");
            if (tieneRetiroActivo($bid)) throw new Exception("{$b['id_siniiga']} tiene medicina en periodo de retiro (hasta " . fechaCorta(fechaFinRetiro($bid)) . ")");

            // Abajo del recomendado solo con descuento autorizado (el admin tiene la ultima palabra)
            $rec = $precios['bovinos'][$bid]['recomendado'] ?? 0;
            if (!$esAdmin && $precio + 0.001 < $rec) {
                if ($desc && in_array($bid, $desc['bovinos'], true) && $precio + 0.001 >= (float)$desc['precio_aprobado']) {
                    $usoDescuento = true;
                } else {
                    throw new Exception("{$b['id_siniiga']}: " . dinero($precio) . "/kg esta abajo del recomendado (" . dinero($rec)
                        . "/kg). Para venderlo mas barato manda una solicitud de descuento al administrador.");
                }
            } elseif ($desc && in_array($bid, $desc['bovinos'], true)) {
                $usoDescuento = true;
            }

            $sub = round($peso * $precio, 2);
            $subtotal += $sub;
            $lineas[] = ['b' => $b, 'peso' => $peso, 'precio' => $precio, 'sub' => $sub];
        }

        $iva = round($subtotal * $ivaPct / 100, 2);
        $total = $subtotal + $iva;

        $db->prepare("
            INSERT INTO ventas (folio, comprador_id, fecha, hora, subtotal, iva, total, factura, guia_senasica, usuario_id)
            VALUES (?, ?, ?, CURRENT_TIME(), ?, ?, ?, ?, ?, ?)
        ")->execute(['TMP' . bin2hex(random_bytes(4)), $comprador_id, $fecha, $subtotal, $iva, $total,
                     generarNumeroFactura($db), trim($_POST['guia_senasica'] ?? '') ?: null, $_SESSION['user_id']]);
        $venta_id = (int)$db->lastInsertId();
        $folio = 'VTA-' . date('Ymd', strtotime($fecha)) . '-' . str_pad($venta_id, 4, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE ventas SET folio = ? WHERE id = ?")->execute([$folio, $venta_id]);

        $dosisDevueltas = 0;
        foreach ($lineas as $l) {
            $b = $l['b'];
            $dosisDevueltas += cancelarDosisDeBovino($db, $b['id'], $_SESSION['user_id'], "Vendido en $folio");
            $db->prepare("INSERT INTO detalle_ventas (venta_id, bovino_id, peso_final, precio_kg, subtotal) VALUES (?, ?, ?, ?, ?)")
               ->execute([$venta_id, $b['id'], $l['peso'], $l['precio'], $l['sub']]);

            // Registrar el peso final como pesaje (queda en la hoja de vida)
            $db->prepare("INSERT INTO pesajes (bovino_id, peso, fecha, hora, usuario_id, observaciones)
                          VALUES (?, ?, ?, CURRENT_TIME(), ?, ?)")
               ->execute([$b['id'], $l['peso'], $fecha, $_SESSION['user_id'], 'Peso final de venta ' . $folio]);

            $db->prepare("UPDATE bovinos SET estado = 'vendido', corral_id = NULL, fecha_salida = NOW() WHERE id = ?")->execute([$b['id']]);
            if ($b['corral_id']) {
                $db->prepare("UPDATE corrales SET ocupacion_actual = GREATEST(ocupacion_actual - 1, 0) WHERE id = ?")->execute([$b['corral_id']]);
            }
            $db->prepare("INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
                          VALUES (?, ?, NULL, 'venta', ?, ?, NOW())")
               ->execute([$b['id'], $b['corral_id'], "Venta $folio", $_SESSION['user_id']]);
        }
        if ($desc && $usoDescuento) {
            $db->prepare("UPDATE solicitudes_descuento SET estado = 'usada', venta_id = ? WHERE id = ?")->execute([$venta_id, $desc['id']]);
        }

        $db->commit();
        registrarLog($_SESSION['user_id'], 'venta', "$folio: " . count($lineas) . " cabezas, total $total" . ($desc && $usoDescuento ? " (descuento #{$desc['id']})" : ''));
        header('Location: ' . BASE_URL . '/ventas/detalle?id=' . $venta_id . '&msg=' . urlencode('Venta registrada'
            . ($dosisDevueltas ? ". $dosisDevueltas dosis que no se aplicaron regresaron al inventario" : '')));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$titulo = 'Nueva venta';
$modulo = 'ventas';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$compradores = $db->query("SELECT id, nombre FROM compradores WHERE activo = 1 ORDER BY nombre")->fetchAll();

$disponibles = $db->query("
    SELECT b.id, b.id_siniiga, b.sexo, b.categoria, b.corral_id, c.nombre AS corral, c.es_venta,
           " . sqlPesoActual('b') . " AS peso
    FROM bovinos b LEFT JOIN corrales c ON c.id = b.corral_id
    WHERE b.estado = 'en_corral' AND b.en_hospital = 0 AND " . sqlSinRetiro('b') . "
    ORDER BY c.es_venta DESC, c.nombre, peso DESC
")->fetchAll();

// Cuantos animales del rancho no se ofrecen y por que (solo conteos, sin lista)
$bloqueados = $db->query("
    SELECT
        SUM(b.en_hospital = 1 OR b.estado = 'en_cuarentena') AS hospital,
        SUM(b.en_hospital = 0 AND b.estado = 'en_corral' AND NOT (" . sqlSinRetiro('b') . ")) AS retiro,
        SUM(b.en_hospital = 0 AND b.estado IN ('recibido', 'revision', 'observacion', 'apto', 'clasificado')) AS revision
    FROM bovinos b
    WHERE b.estado IN ('en_corral', 'en_cuarentena', 'recibido', 'revision', 'observacion', 'apto', 'clasificado')
")->fetch(PDO::FETCH_ASSOC);
$bloqueados = array_map('intval', $bloqueados ?: []);

$ivaDefault = (float)(configValor('iva', 0));
$precios = preciosVenta($db);
$dosisPend = dosisPendientesPorBovino($db);
$seleccionPost = $_POST['sel'] ?? [];
$descuentos = descuentosAprobados($db, $_SESSION['user_id'], $esAdmin);
$descSel = (int)($_POST['descuento_id'] ?? ($_GET['descuento'] ?? 0));
if ($descSel && !isset($descuentos[$descSel])) $descSel = 0;

// Agrupar por corral
$grupos = [];
foreach ($disponibles as $d) {
    $k = (int)$d['corral_id'];
    if (!isset($grupos[$k])) {
        $c = $precios['corrales'][$k] ?? null;
        $grupos[$k] = ['nombre' => $d['corral'] ?: 'Sin corral', 'es_venta' => (int)$d['es_venta'], 'c' => $c, 'filas' => []];
    }
    $grupos[$k]['filas'][] = $d;
}
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<form method="POST" id="form-venta">
    <?= csrfField() ?>
    <div class="table-container" style="margin-bottom:25px;">
        <div class="table-header"><h2>Datos de la venta</h2>
            <div style="display:flex;gap:8px;"><a href="<?= BASE_URL ?>/ventas/precios" class="btn btn-secondary btn-sm">Precios por corral</a><a href="<?= BASE_URL ?>/ventas" class="btn btn-secondary btn-sm">Cancelar</a></div></div>
        <div class="grid-form" style="padding:20px;grid-template-columns:2fr 1fr 1fr;">
            <div>
                <label style="<?= ESTILO_LABEL ?>">Comprador *</label>
                <select name="comprador_id" id="comprador" style="<?= ESTILO_INPUT ?>">
                    <option value="0">+ Capturar comprador nuevo</option>
                    <?php foreach ($compradores as $c): ?><option value="<?= $c['id'] ?>" <?= (int)($_POST['comprador_id'] ?? -1) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label style="<?= ESTILO_LABEL ?>">Fecha</label><input type="date" name="fecha" value="<?= h($_POST['fecha'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">IVA %</label><input type="number" step="0.01" min="0" max="100" name="iva" id="iva" value="<?= h($_POST['iva'] ?? $ivaDefault) ?>" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div id="nuevo-comprador" class="grid-form" style="padding:0 20px 20px;grid-template-columns:2fr 1fr 1fr;">
            <div><label style="<?= ESTILO_LABEL ?>">Nombre del comprador nuevo</label><input type="text" name="nuevo_nombre" value="<?= h($_POST['nuevo_nombre'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">RFC</label><input type="text" name="nuevo_rfc" maxlength="13" value="<?= h($_POST['nuevo_rfc'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Telefono</label><input type="text" name="nuevo_tel" value="<?= h($_POST['nuevo_tel'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div class="grid-form" style="padding:0 20px 20px;grid-template-columns:1fr 1fr 1fr;">
            <div><label style="<?= ESTILO_LABEL ?>">No. de factura</label>
                <div style="padding:10px;background:var(--suave);border-radius:6px;font-family:monospace;font-weight:600;"><?= h(strtoupper(configValor('empresa_prefijo', 'GER') ?: 'GER')) ?>-<?= date('Ymd') ?>-hhmmss</div>
                <small style="color:var(--tenue);">Se genera sola al confirmar</small></div>
            <div><label style="<?= ESTILO_LABEL ?>">Guia SENASICA / REEMO (opcional)</label><input type="text" name="guia_senasica" style="<?= ESTILO_INPUT ?>" value="<?= h($_POST['guia_senasica'] ?? '') ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Descuento autorizado</label>
                <select name="descuento_id" id="descuento" style="<?= ESTILO_INPUT ?>">
                    <option value="">Sin descuento (precio recomendado)</option>
                    <?php foreach ($descuentos as $ds): ?>
                        <option value="<?= $ds['id'] ?>" <?= $descSel === (int)$ds['id'] ? 'selected' : '' ?>>#<?= $ds['id'] ?> · <?= dinero($ds['precio_aprobado']) ?>/kg · <?= count($ds['bovinos']) ?> animales<?= $ds['cliente'] ? ' · ' . h($ds['cliente']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--tenue);"><?= $descuentos ? 'Al elegirlo se marcan sus animales con el precio autorizado' : 'No tienes descuentos autorizados' ?></small></div>
        </div>
    </div>

    <?php $totalBloq = array_sum($bloqueados); if ($totalBloq): ?>
    <div class="alert alert-info alert-fija" style="margin-bottom:20px;">
        <strong><?= $totalBloq ?> animal(es) no aparecen</strong> porque estan en observacion medica:
        <?= h(implode(' · ', array_filter([
            $bloqueados['retiro'] ? $bloqueados['retiro'] . ' con medicina en retiro' : '',
            $bloqueados['hospital'] ? $bloqueados['hospital'] . ' en veterinaria / cuarentena' : '',
            $bloqueados['revision'] ? $bloqueados['revision'] . ' en revision o por clasificar' : '',
        ]))) ?>
    </div>
    <?php endif; ?>

    <div class="table-container">
        <div class="table-header"><h2>Animales disponibles (<?= count($disponibles) ?>)</h2>
            <label style="font-size:0.9rem;"><input type="checkbox" id="todos"> Seleccionar todos</label></div>
        <?php if ($disponibles): ?>
        <div style="overflow-x:auto;">
        <table id="tabla-venta">
            <thead><tr><th></th><th>Arete</th><th>Categoria</th><?php if ($esAdmin): ?><th>Invertido</th><?php endif; ?><th>Peso final (kg)</th><th>Precio/kg</th><th>Minimo/kg</th><th>Recomendado/kg</th><th>Subtotal</th><?php if ($esAdmin): ?><th>Ganancia</th><?php endif; ?></tr></thead>
            <?php foreach ($grupos as $gid => $g): $c = $g['c']; ?>
            <tbody class="grupo">
                <tr class="grupo-head">
                    <td><input type="checkbox" class="sel-grupo" title="Seleccionar todo el corral"></td>
                    <td colspan="<?= $esAdmin ? 9 : 7 ?>">
                        <strong><?= h($g['nombre']) ?></strong> · <?= count($g['filas']) ?> animal(es)
                        <?php if ($g['es_venta'] && $c): ?>
                            · <span class="pill-venta">Corral de venta</span>
                            precio del corral <strong><?= dinero($c['recomendado']) ?>/kg</strong>
                            <span style="color:var(--tenue);">(minimo <?= dinero($c['minimo']) ?>, peso <?= number_format($c['peso_min_animal'] ?? 0, 0) ?>-<?= number_format($c['peso_max_animal'] ?? 0, 0) ?> kg)</span>
                        <?php else: ?>
                            <span style="color:var(--tenue);">· cada animal con su precio</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php foreach ($g['filas'] as $d):
                    $p = $precios['bovinos'][$d['id']] ?? ['minimo' => 0, 'recomendado' => 0, 'costo' => 0];
                    $chk = isset($seleccionPost[$d['id']]); ?>
                <tr class="fila" data-id="<?= $d['id'] ?>" data-costo="<?= $esAdmin ? round($p['costo'], 2) : 0 ?>" data-min="<?= $p['minimo'] ?>" data-rec="<?= $p['recomendado'] ?>">
                    <td><input type="checkbox" class="sel" name="sel[<?= $d['id'] ?>]" value="1" <?= $chk ? 'checked' : '' ?>></td>
                    <td><strong><?= h($d['id_siniiga']) ?></strong>
                        <?php if (!empty($dosisPend[$d['id']])): ?><br><small style="color:#b26a00;" title="Se cancelan al vender y el medicamento regresa al inventario"><?= $dosisPend[$d['id']] ?> dosis pendientes</small><?php endif; ?></td>
                    <td><?= h($d['categoria']) ?></td>
                    <?php if ($esAdmin): ?><td><?= dinero($p['costo']) ?></td><?php endif; ?>
                    <td><input type="number" step="0.1" min="1" class="peso" name="peso[<?= $d['id'] ?>]" value="<?= h($_POST['peso'][$d['id']] ?? round($d['peso'], 1)) ?>" style="width:95px;padding:6px;border:1px solid var(--linea);border-radius:6px;"></td>
                    <td><input type="number" step="0.01" min="0" class="precio" name="precio[<?= $d['id'] ?>]" value="<?= h($_POST['precio'][$d['id']] ?? $p['recomendado']) ?>" style="width:95px;padding:6px;border:1px solid var(--linea);border-radius:6px;">
                        <small class="aviso-precio" style="display:block;color:#dc3545;"></small></td>
                    <td style="color:var(--tenue);"><?= dinero($p['minimo']) ?></td>
                    <td style="font-weight:600;color:#0b4f86;"><?= dinero($p['recomendado']) ?></td>
                    <td class="subtotal">$0.00</td>
                    <?php if ($esAdmin): ?><td class="ganancia" style="font-weight:700;">-</td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
        </div>
        <div class="barra-venta">
            <div style="font-size:1.05rem;">
                <span id="resumen-n">0</span> cabeza(s) | <span id="resumen-kg">0</span> kg | promedio <strong id="resumen-prom">$0.00</strong>/kg |
                Subtotal <strong id="resumen-sub">$0.00</strong> | IVA <strong id="resumen-iva">$0.00</strong> |
                <?php if ($esAdmin): ?>Ganancia <strong id="resumen-gan">$0.00</strong> |<?php endif; ?>
                <span style="font-size:1.25rem;color:#0b4f86;">Total <strong id="resumen-total">$0.00</strong></span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php if (!$esAdmin): ?><button type="button" class="btn btn-secondary" id="btn-pedir">Solicitar descuento</button><?php endif; ?>
                <button type="submit" name="accion" value="vender" class="btn btn-primary" id="btn-vender" onclick="return confirmarVenta()">Confirmar venta</button>
            </div>
        </div>

        <?php if (!$esAdmin): ?>
        <div id="panel-descuento" class="panel-descuento" hidden>
            <h3 style="margin-bottom:10px;">Solicitar descuento al administrador</h3>
            <p style="color:var(--tenue);margin-bottom:12px;">Se manda con los <strong id="desc-n">0</strong> animales seleccionados (recomendado promedio <strong id="desc-rec">$0</strong>/kg).
                El administrador puede aceptarlo, rechazarlo o autorizar otro precio. Si no lo autoriza, no se pueden vender mas baratos.</p>
            <div class="grid-form" style="grid-template-columns:1fr 3fr;">
                <div><label style="<?= ESTILO_LABEL ?>">Precio que pide el cliente ($/kg) *</label>
                    <input type="number" step="0.01" min="0" name="desc_precio" id="desc-precio" value="<?= h($_POST['desc_precio'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
                <div><label style="<?= ESTILO_LABEL ?>">Explicacion rapida *</label>
                    <input type="text" name="desc_motivo" maxlength="500" value="<?= h($_POST['desc_motivo'] ?? '') ?>" placeholder="Ej: el cliente se lleva 500 bovinos y los pide a $87.20 en vez de $90" style="<?= ESTILO_INPUT ?>"></div>
            </div>
            <div style="margin-top:12px;text-align:right;"><button type="submit" name="accion" value="descuento" class="btn btn-primary" onclick="return pedirDescuento()">Enviar solicitud</button></div>
        </div>
        <?php endif; ?>
        <?php else: ?><div style="padding:40px;text-align:center;color:var(--tenue);">No hay animales listos para venta.</div><?php endif; ?>
    </div>
</form>

<style>
.grupo-head td { background: var(--f-verde); border-top: 2px solid var(--linea); }
.pill-venta { background: #0b4f86; color: #fff; border-radius: 10px; padding: 1px 8px; font-size: 0.75rem; }
.barra-venta { padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
               position: sticky; bottom: 0; background: var(--superficie); border-top: 1px solid var(--linea); box-shadow: 0 -4px 10px rgba(0,0,0,.05); }
.panel-descuento { margin: 0 20px 20px; padding: 18px; border: 2px dashed #e67e22; border-radius: 8px; background: var(--f-amarillo); }
tr.fila.bajo .precio { border-color: #dc3545 !important; background: var(--f-rojo); }
tr.fila.desc .precio { border-color: #e67e22 !important; background: var(--f-amarillo); }
</style>

<script>
(function () {
    var ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
    var DESCUENTOS = <?= json_encode(array_map(fn($d) => ['p' => (float)$d['precio_aprobado'], 'b' => $d['bovinos']], $descuentos), JSON_FORCE_OBJECT) ?>;
    var money = function (n) { return '$' + n.toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    var rows = document.querySelectorAll('tr.fila');
    var selDesc = document.getElementById('descuento');
    function descActual() { return selDesc && DESCUENTOS[selDesc.value] ? DESCUENTOS[selDesc.value] : null; }
    function calc() {
        var n = 0, kg = 0, sub = 0, gan = 0, recKg = 0, d = descActual();
        rows.forEach(function (tr) {
            var chk = tr.querySelector('.sel');
            var peso = parseFloat(tr.querySelector('.peso').value) || 0;
            var precio = parseFloat(tr.querySelector('.precio').value) || 0;
            var rec = +tr.dataset.rec, s = peso * precio, g = s - (+tr.dataset.costo || 0);
            tr.querySelector('.subtotal').textContent = money(s);
            var celG = tr.querySelector('.ganancia');
            if (celG) { celG.textContent = precio > 0 ? money(g) : '-'; celG.style.color = g >= 0 ? '#28a745' : '#dc3545'; }
            var cubierto = d && d.b.indexOf(+tr.dataset.id) >= 0 && precio + 0.001 >= d.p;
            var bajo = precio > 0 && precio + 0.001 < rec;
            tr.classList.toggle('bajo', bajo && !cubierto && !ES_ADMIN);
            tr.classList.toggle('desc', bajo && (cubierto || ES_ADMIN));
            tr.querySelector('.aviso-precio').textContent = bajo ? (ES_ADMIN ? (precio < +tr.dataset.min ? 'abajo del minimo' : 'abajo del recomendado') : (cubierto ? 'descuento autorizado' : 'necesita descuento')) : '';
            tr.dataset.perdida = ES_ADMIN && precio > 0 && g < 0 ? '1' : '';
            if (chk.checked) { n++; kg += peso; sub += s; gan += g; recKg += rec * peso; }
        });
        var iva = sub * (parseFloat(document.getElementById('iva').value) || 0) / 100;
        document.getElementById('resumen-n').textContent = n;
        document.getElementById('resumen-kg').textContent = kg.toLocaleString('es-MX', {maximumFractionDigits: 1});
        document.getElementById('resumen-prom').textContent = money(kg ? sub / kg : 0);
        document.getElementById('resumen-sub').textContent = money(sub);
        document.getElementById('resumen-iva').textContent = money(iva);
        document.getElementById('resumen-total').textContent = money(sub + iva);
        var rg = document.getElementById('resumen-gan'); if (rg) { rg.textContent = money(gan); rg.style.color = gan >= 0 ? '#28a745' : '#dc3545'; }
        var dn = document.getElementById('desc-n'); if (dn) { dn.textContent = n; document.getElementById('desc-rec').textContent = money(kg ? recKg / kg : 0); }
    }
    var form = document.getElementById('form-venta');
    form.addEventListener('input', calc);
    form.addEventListener('change', calc);
    var todos = document.getElementById('todos');
    if (todos) todos.addEventListener('change', function () {
        document.querySelectorAll('.sel, .sel-grupo').forEach(function (c) { c.checked = todos.checked; }); calc();
    });
    document.querySelectorAll('.sel-grupo').forEach(function (g) {
        g.addEventListener('change', function () {
            g.closest('tbody').querySelectorAll('.sel').forEach(function (c) { c.checked = g.checked; }); calc();
        });
    });
    if (selDesc) selDesc.addEventListener('change', function () {
        var d = descActual();
        rows.forEach(function (tr) {
            var en = d && d.b.indexOf(+tr.dataset.id) >= 0;
            if (en) { tr.querySelector('.sel').checked = true; tr.querySelector('.precio').value = d.p.toFixed(2); }
            else if (d === null) { tr.querySelector('.precio').value = (+tr.dataset.rec).toFixed(2); }
        });
        calc();
    });
    var comp = document.getElementById('comprador');
    function nuevo() { document.getElementById('nuevo-comprador').style.display = comp.value === '0' ? 'grid' : 'none'; }
    comp.addEventListener('change', nuevo); nuevo();
    var bp = document.getElementById('btn-pedir');
    if (bp) bp.addEventListener('click', function () {
        var p = document.getElementById('panel-descuento'); p.hidden = !p.hidden;
        if (!p.hidden) { p.scrollIntoView({behavior: 'smooth', block: 'center'}); document.getElementById('desc-precio').focus(); }
    });
    window.pedirDescuento = function () {
        if (!document.querySelectorAll('.sel:checked').length) { alert('Selecciona los animales del descuento'); return false; }
        return true;
    };
    window.confirmarVenta = function () {
        var bajos = 0, perdida = 0;
        rows.forEach(function (tr) {
            if (!tr.querySelector('.sel').checked) return;
            if (tr.classList.contains('bajo')) bajos++;
            if (tr.dataset.perdida) perdida++;
        });
        if (bajos) { alert(bajos + ' animal(es) estan abajo del precio recomendado sin descuento autorizado. Solicita un descuento al administrador.'); return false; }
        return confirm((perdida ? perdida + ' animal(es) se venden por debajo de lo invertido. ' : '') + 'Confirmar venta? Los animales saldran del inventario.');
    };
    if (selDesc && selDesc.value) selDesc.dispatchEvent(new Event('change'));
    <?php if (!empty($_POST['accion']) && $_POST['accion'] === 'descuento'): ?>document.getElementById('panel-descuento').hidden = false;<?php endif; ?>
    calc();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
