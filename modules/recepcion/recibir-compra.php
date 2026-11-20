<?php
// ============================================
// RECEPCION - Recibir compra
// Cada bovino se revisa uno por uno (no hay nada marcado por defecto).
// Todos los que llegan vivos -> corral temporal RECEP (machos / hembras) sin clasificar.
// Los enfermos/heridos quedan marcados: el clasificador los clasifica y los envia a veterinaria.
// Muertos / no coinciden -> bajas.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/alertas_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$compra_id = (int)($_GET['id'] ?? 0);
if (!$compra_id) {
    header('Location: ' . BASE_URL . '/recepcion/compras');
    exit;
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM compras WHERE id = ?");
$stmt->execute([$compra_id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) {
    header('Location: ' . BASE_URL . '/recepcion/compras');
    exit;
}

if (!in_array($compra['estado'], ['pendiente', 'en_transito'])) {
    header('Location: ' . BASE_URL . '/recepcion/compras?msg=ya_recibida');
    exit;
}

// Datos del rancho de origen (por proveedor_id o, en compras viejas, por su UPP)
$stmt = $db->prepare("
    SELECT p.* FROM proveedores p
    WHERE p.id = ? OR (p.upp IS NOT NULL AND p.upp = ?)
    ORDER BY p.id = ? DESC LIMIT 1
");
$stmt->execute([$compra['proveedor_id'], $compra['origen_upp'], $compra['proveedor_id']]);
$rancho = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$comprasRancho = 0;
if (!empty($compra['origen_upp'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM compras WHERE origen_upp = ? AND estado = 'recibida'");
    $stmt->execute([$compra['origen_upp']]);
    $comprasRancho = (int)$stmt->fetchColumn();
}

// Corrales temporales de recepcion por sexo
$corralesRecep = [];
foreach ($db->query("SELECT * FROM corrales WHERE es_recepcion = 1 ORDER BY id") as $c) {
    $corralesRecep[$c['sexo_permitido']] = $c;
}

$stmt = $db->prepare("SELECT * FROM compra_bovinos WHERE compra_id = ? AND recibido = 0 ORDER BY numero_linea");
$stmt->execute([$compra_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

const MOTIVOS_MUERTE = ['Muerte en traslado', 'Muerte por enfermedad', 'Aplastado', 'Otro'];
const MOTIVOS_NO_COINCIDE = ['No coincide el arete', 'No llego', 'Diferente sexo', 'Otro'];
const MOTIVOS_HOSPITAL = ['Herida visible', 'Cojera', 'Debilidad', 'Diarrea', 'Problemas respiratorios', 'Fiebre', 'Desnutricion', 'Comportamiento anomalo', 'Otro'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $placas = strtoupper(trim($_POST['placas'] ?? ''));
        $chofer = trim($_POST['chofer'] ?? '');
        if ($placas === '' || $chofer === '') {
            throw new Exception('Captura las placas y el nombre del chofer');
        }

        // Todos los bovinos deben revisarse a mano antes de confirmar
        $datosPost = $_POST['bovinos'] ?? [];
        $sinRevisar = [];
        foreach ($bovinos as $cb) {
            $d = $datosPost[$cb['id']] ?? [];
            $estado = $d['estado_recibido'] ?? '';
            if (!in_array($estado, ['verificado', 'muerto', 'no_coincide'], true)) {
                $sinRevisar[] = $cb['numero_arete'];
            } elseif ($estado === 'verificado' && !empty($d['es_enfermo']) && trim($d['motivo_hospital'] ?? '') === '') {
                throw new Exception("Indica el motivo de hospital del bovino {$cb['numero_arete']}");
            }
        }
        if ($sinRevisar) {
            throw new Exception('Faltan ' . count($sinRevisar) . ' bovino(s) por revisar: ' . implode(', ', array_slice($sinRevisar, 0, 10))
                . (count($sinRevisar) > 10 ? '...' : ''));
        }

        $db->beginTransaction();

        $folio = 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $stmt = $db->prepare("
            INSERT INTO recepciones (
                folio, compra_id, proveedor_id, usuario_id, fecha_recepcion,
                cantidad_animales, placas, chofer, estado
            ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'borrador')
        ");
        $stmt->execute([
            $folio, $compra_id, $rancho['id'] ?? null, $_SESSION['user_id'],
            count($bovinos), $placas, $chofer
        ]);
        $recepcion_id = $db->lastInsertId();

        $cuenta = ['sanos' => 0, 'enfermos' => 0, 'muertos' => 0, 'no_coinciden' => 0];
        $pesoTotal = 0;

        foreach ($bovinos as $cb) {
            $datos = $datosPost[$cb['id']];
            $estadoRecibido = $datos['estado_recibido'];

            // NO LLEGO: muerto o no coincide
            if ($estadoRecibido !== 'verificado') {
                $esMuerto = $estadoRecibido === 'muerto';
                $motivos = $esMuerto ? MOTIVOS_MUERTE : MOTIVOS_NO_COINCIDE;
                $motivoBaja = in_array($datos['motivo_baja'] ?? '', $motivos, true) ? $datos['motivo_baja'] : $motivos[0];

                $db->prepare("
                    INSERT INTO bajas_recepcion (recepcion_id, compra_bovino_id, numero_arete, tipo, cantidad, motivo, usuario_id)
                    VALUES (?, ?, ?, ?, 1, ?, ?)
                ")->execute([
                    $recepcion_id, $cb['id'], $cb['numero_arete'],
                    $esMuerto ? 'muerte' : 'rechazo', $motivoBaja, $_SESSION['user_id']
                ]);
                $cuenta[$esMuerto ? 'muertos' : 'no_coinciden']++;
                continue;
            }

            // SI LLEGO: entra a RECEP segun su sexo; la categoria la decide el clasificador
            $sexo = $cb['sexo'] === 'MACHO' ? 'M' : 'H';
            $recep = $corralesRecep[$sexo] ?? null;
            if (!$recep) {
                throw new Exception('No existe el corral de recepcion para ' . ($sexo === 'M' ? 'machos' : 'hembras') . ' (RECEP). Crealo en Corrales.');
            }
            $peso = (float)($datos['peso_real'] ?? 0) ?: (float)($cb['peso_estimado'] ?? 0);
            $esEnfermo = !empty($datos['es_enfermo']);
            $motivoLlegada = $esEnfermo ? trim($datos['motivo_hospital']) : null;
            $corral_id = $recep['id'];

            $db->prepare("
                INSERT INTO bovinos (
                    compra_id, recepcion_id, id_siniiga, numero_arete_compra,
                    sexo, raza, peso_entrada, categoria, clasificacion_compra,
                    edad_meses, estado, corral_id, corral_original_id,
                    llego_enfermo, motivo_llegada
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Sin clasificar', ?, ?, 'recibido', ?, ?, ?, ?)
            ")->execute([
                $compra_id, $recepcion_id, $cb['numero_arete'], $cb['numero_arete'],
                $sexo, $cb['raza'] ?? null, $peso, $cb['clasificacion'] ?: null,
                $cb['meses'], $corral_id, $corral_id,
                $esEnfermo ? 1 : 0, $motivoLlegada
            ]);
            $bovino_id = $db->lastInsertId();

            $db->prepare("UPDATE corrales SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?")->execute([$corral_id]);
            $db->prepare("
                INSERT INTO movimientos_bovino
                (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha)
                VALUES (?, NULL, ?, ?, ?, ?, NOW())
            ")->execute([
                $bovino_id, $corral_id, 'recepcion',
                'Recepcion de compra ' . $compra['folio'] . ($esEnfermo ? " (llego enfermo: $motivoLlegada)" : ''), $_SESSION['user_id']
            ]);

            $db->prepare("UPDATE compra_bovinos SET recibido = 1, bovino_id = ?, fecha_recepcion = NOW() WHERE id = ?")
               ->execute([$bovino_id, $cb['id']]);

            $cuenta[$esEnfermo ? 'enfermos' : 'sanos']++;
            $pesoTotal += $peso;
        }

        $db->prepare("
            UPDATE recepciones
            SET cantidad_llegada = ?, cantidad_recibida = ?, cantidad_bajas = ?, peso_total_recibido = ?, estado = 'completado'
            WHERE id = ?
        ")->execute([
            $cuenta['sanos'] + $cuenta['enfermos'] + $cuenta['muertos'],
            $cuenta['sanos'] + $cuenta['enfermos'],
            $cuenta['muertos'] + $cuenta['no_coinciden'],
            $pesoTotal, $recepcion_id
        ]);

        $db->prepare("
            UPDATE compras
            SET estado = 'recibida', fecha_llegada_real = NOW(), usuario_recepcion_id = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $compra_id]);

        $db->prepare("
            UPDATE alertas
            SET leida = 1, leida_por = ?, fecha_lectura = NOW()
            WHERE compra_id = ? AND tipo = 'compra_pendiente'
        ")->execute([$_SESSION['user_id'], $compra_id]);

        $db->commit();

        $vivos = $cuenta['sanos'] + $cuenta['enfermos'];
        if ($vivos > 0) {
            crearAlerta('bovinos_por_clasificar',
                "Llegaron {$vivos} bovinos de {$compra['origen_nombre']} ({$folio}). Estan en RECEP esperando clasificacion."
                . ($cuenta['enfermos'] ? " {$cuenta['enfermos']} llegaron enfermos/heridos: clasificalos y envialos a veterinaria." : ''),
                'clasificador');
        }

        header('Location: ' . BASE_URL . '/recepcion/detalle?id=' . $recepcion_id . '&msg=recibida');
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$titulo = 'Recibir Compra';
$modulo = 'recepcion';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';

$dato = function ($etiqueta, $valor, $mono = false) {
    $v = trim((string)$valor) !== '' ? htmlspecialchars($valor) : '<span style="color:var(--tenue);">-</span>';
    return '<div class="rancho-dato"><div class="rancho-etq">' . $etiqueta . '</div><div class="rancho-val"' . ($mono ? ' style="font-family:monospace;"' : '') . '>' . $v . '</div></div>';
};
$opciones = function (array $lista, $sel = '') {
    return implode('', array_map(fn($m) => '<option value="' . htmlspecialchars($m) . '"' . ($m === $sel ? ' selected' : '') . '>' . htmlspecialchars($m) . '</option>', $lista));
};
$post = $_POST['bovinos'] ?? [];
?>
<style>
#formRecepcion [hidden] { display:none !important; }
.rancho-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; }
.rancho-dato { padding:10px 12px; background:var(--suave); border-radius:6px; }
.rancho-etq { font-size:0.72rem; color:var(--tenue); text-transform:uppercase; font-weight:600; }
.rancho-val { font-size:1rem; margin-top:3px; font-weight:600; color:var(--texto); }
.rv-estados { display:flex; gap:5px; flex-wrap:wrap; }
.rv-estados label { display:flex; align-items:center; gap:4px; padding:5px 9px; border:2px solid var(--linea); border-radius:6px; cursor:pointer; font-size:0.82rem; font-weight:600; background:var(--superficie); }
.rv-estados input { margin:0; }
.bovino-row.pendiente td:first-child { box-shadow: inset 4px 0 0 var(--tenue); }
.bovino-row.estado-verificado { background:var(--f-verde); }
.bovino-row.estado-enfermo { background:var(--f-amarillo); }
.bovino-row.estado-muerto { background:var(--f-rojo); }
.bovino-row.estado-no_coincide { background:var(--f-gris); }
.bovino-row.sin-revisar { outline:2px solid #dc3545; }
.bovino-row.escaneado { animation: rv-flash 1s; }
@keyframes rv-flash { from { box-shadow: 0 0 0 3px #0b4f86 inset; } to { box-shadow:none; } }
.rv-resumen { display:flex; gap:8px; flex-wrap:wrap; font-size:0.85rem; font-weight:600; }
.rv-resumen span { padding:4px 10px; border-radius:12px; background:var(--suave); }
.rv-sel { width:100%; padding:5px; font-size:0.82rem; border:1px solid var(--linea); border-radius:4px; margin-top:6px; }
</style>

<div class="table-container" style="margin-bottom: 20px;">
    <div class="table-header">
        <div>
            <h2>Recibir Compra <?= htmlspecialchars($compra['folio']) ?></h2>
            <p style="color: var(--tenue); font-size: 0.9rem; margin-top: 5px;">
                Comprada el <?= date('d/m/Y', strtotime($compra['fecha_compra'])) ?>
                <?php if ($compra['fecha_llegada_estimada']): ?>
                    · Llegada estimada <?= date('d/m/Y', strtotime($compra['fecha_llegada_estimada'])) ?>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/recepcion/compras" class="btn btn-secondary">Volver</a>
    </div>

    <div style="padding: 20px;">
        <h3 style="margin-bottom: 12px; color: #0b4f86;">
            Rancho de origen: <?= htmlspecialchars($rancho['nombre'] ?? $compra['origen_nombre'] ?? 'N/A') ?>
        </h3>
        <div class="rancho-grid">
            <?= $dato('UPP', $compra['origen_upp'], true) ?>
            <?= $dato('Localidad', $compra['origen_localidad']) ?>
            <?= $dato('Municipio', $compra['origen_municipio']) ?>
            <?= $dato('Estado', $compra['origen_estado']) ?>
            <?= $dato('Telefono', $rancho['telefono'] ?? '') ?>
            <?= $dato('Correo', $rancho['email'] ?? '') ?>
            <?= $dato('REEMO / Guia', $compra['numero_reemo'], true) ?>
            <?= $dato('Animales', $compra['cantidad_total']) ?>
            <?= $dato('Peso estimado', $compra['peso_total_compra'] > 0 ? number_format($compra['peso_total_compra'], 1) . ' kg' : '') ?>
            <?= $dato('Compras anteriores recibidas', $comprasRancho) ?>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">ERROR: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (count($corralesRecep) < 2): ?>
    <div class="alert alert-error">Faltan los corrales de recepcion RECEP-1 (machos) y RECEP-2 (hembras). Pide al administrador que los cree en Corrales.</div>
<?php endif; ?>

<form method="POST" id="formRecepcion">

    <div class="table-container" style="margin-bottom: 20px; padding: 20px;">
        <h3 style="margin-bottom: 15px; color: #0b4f86;">Datos del Transporte</h3>
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Placas *</label>
                <input type="text" name="placas" placeholder="Ej: ABC-123" required
                       value="<?= htmlspecialchars($_POST['placas'] ?? '') ?>"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px; text-transform: uppercase;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;">Nombre del chofer *</label>
                <input type="text" name="chofer" placeholder="Nombre del chofer" required
                       value="<?= htmlspecialchars($_POST['chofer'] ?? '') ?>"
                       style="width: 100%; padding: 8px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header" style="flex-wrap: wrap; gap: 10px;">
            <h2>Revision de Bovinos (<?= count($bovinos) ?>)</h2>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="text" id="escanearArete" inputmode="numeric" autocomplete="off"
                       placeholder="Escanea o escribe el arete + Enter"
                       style="padding: 8px 12px; border: 2px solid #0b4f86; border-radius: 6px; width: 280px; font-family: monospace;">
                <span id="avisoEscaneo" style="font-size: 0.85rem;"></span>
            </div>
        </div>
        <div style="padding: 10px 20px; border-bottom: 1px solid var(--linea);">
            <p style="color:var(--tenue); font-size:0.85rem; margin-bottom:8px;">
                Revisa cada animal: <strong>Llego</strong>, <strong>Muerto</strong> o <strong>No coincide</strong>. Al escanear un arete se marca como <strong>Llego</strong>.
                No se puede confirmar hasta revisarlos todos.
            </p>
            <div class="rv-resumen">
                <span id="rsPend">Sin revisar: 0</span>
                <span style="background:var(--f-verde);" id="rsSano">Sanos: 0</span>
                <span style="background:var(--f-amarillo);" id="rsEnf">Enfermos/heridos: 0</span>
                <span style="background:var(--f-rojo);" id="rsMuerto">Muertos: 0</span>
                <span style="background:var(--f-gris);" id="rsNc">No coinciden: 0</span>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="tablaBovinos">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>SINIIGA</th>
                        <th>Sexo</th>
                        <th>Edad</th>
                        <th>Peso est.</th>
                        <th style="width: 320px;">Revision</th>
                        <th>Peso real (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bovinos as $idx => $b):
                        $id = $b['id'];
                        $p = $post[$id] ?? [];
                        $est = $p['estado_recibido'] ?? '';
                        $enfermo = !empty($p['es_enfermo']);
                    ?>
                    <tr class="bovino-row" data-id="<?= $id ?>" data-siniiga="<?= htmlspecialchars($b['numero_arete']) ?>">
                        <td><?= $idx + 1 ?></td>
                        <td><strong style="font-family: monospace;"><?= htmlspecialchars($b['numero_arete']) ?></strong></td>
                        <td><?= $b['sexo'] === 'MACHO' ? 'Macho' : 'Hembra' ?></td>
                        <td><?= $b['meses'] ? (int)$b['meses'] . ' m' : '-' ?></td>
                        <td><?= $b['peso_estimado'] ? number_format($b['peso_estimado'], 1) . ' kg' : '-' ?></td>
                        <td>
                            <div class="rv-estados">
                                <?php foreach (['verificado' => 'Llego', 'muerto' => 'Muerto', 'no_coincide' => 'No coincide'] as $v => $txt): ?>
                                <label>
                                    <input type="radio" name="bovinos[<?= $id ?>][estado_recibido]" value="<?= $v ?>" <?= $est === $v ? 'checked' : '' ?>>
                                    <?= $txt ?>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="rv-llego" hidden>
                                <label style="display: flex; align-items: center; cursor: pointer; padding: 5px 8px; background: var(--f-amarillo); border: 1px solid #fbc02d; border-radius: 4px; font-size: 0.8rem; margin-top: 6px;">
                                    <input type="checkbox" class="rv-enfermo" name="bovinos[<?= $id ?>][es_enfermo]" value="1" <?= $enfermo ? 'checked' : '' ?> style="margin-right: 6px;">
                                    <strong>Enfermo / Herido</strong>&nbsp;<small>(el clasificador lo enviara a veterinaria)</small>
                                </label>
                                <select class="rv-sel rv-hospital" name="bovinos[<?= $id ?>][motivo_hospital]" hidden style="border-color:#dc3545;">
                                    <option value="">-- Motivo hospital --</option>
                                    <?= $opciones(MOTIVOS_HOSPITAL, $p['motivo_hospital'] ?? '') ?>
                                </select>
                            </div>
                            <select class="rv-sel rv-motivo-muerto" name="bovinos[<?= $id ?>][motivo_baja]" hidden style="border-color:#dc3545;">
                                <?= $opciones(MOTIVOS_MUERTE, $est === 'muerto' ? ($p['motivo_baja'] ?? '') : '') ?>
                            </select>
                            <select class="rv-sel rv-motivo-nc" name="bovinos[<?= $id ?>][motivo_baja]" hidden style="border-color:var(--t-amarillo);">
                                <?= $opciones(MOTIVOS_NO_COINCIDE, $est === 'no_coincide' ? ($p['motivo_baja'] ?? '') : '') ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" class="rv-peso" name="bovinos[<?= $id ?>][peso_real]"
                                   step="0.1" min="0"
                                   value="<?= htmlspecialchars($p['peso_real'] ?? $b['peso_estimado'] ?? '') ?>"
                                   style="width: 100px; padding: 6px; border: 1px solid var(--linea); border-radius: 4px;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; align-items: center;">
        <span id="avisoConfirmar" style="color: #dc3545; font-weight: 600;"></span>
        <a href="<?= BASE_URL ?>/recepcion/compras" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btnConfirmar" style="padding: 12px 30px;">
            Confirmar Recepcion
        </button>
    </div>
</form>

<script>
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('.bovino-row'));

    function pintar(row) {
        var sel = row.querySelector('input[type=radio]:checked');
        var est = sel ? sel.value : '';
        var enfermo = row.querySelector('.rv-enfermo');
        var llego = est === 'verificado';

        // Cada select/campo solo existe (y se envia) cuando aplica a lo elegido
        row.querySelector('.rv-llego').hidden = !llego;
        enfermo.disabled = !llego;
        var hosp = row.querySelector('.rv-hospital');
        hosp.hidden = !(llego && enfermo.checked);
        hosp.disabled = hosp.hidden;
        hosp.required = !hosp.hidden;
        [['.rv-motivo-muerto', 'muerto'], ['.rv-motivo-nc', 'no_coincide']].forEach(function (par) {
            var s = row.querySelector(par[0]);
            s.hidden = est !== par[1];
            s.disabled = s.hidden;
        });
        var peso = row.querySelector('.rv-peso');
        peso.disabled = !llego && est !== '';
        peso.style.opacity = peso.disabled ? '0.4' : '1';

        row.className = 'bovino-row' + (est === '' ? ' pendiente' : ' estado-' + (llego && enfermo.checked ? 'enfermo' : est));
    }

    function resumen() {
        var c = {pend: 0, sano: 0, enf: 0, muerto: 0, nc: 0};
        rows.forEach(function (r) {
            var sel = r.querySelector('input[type=radio]:checked');
            if (!sel) c.pend++;
            else if (sel.value === 'muerto') c.muerto++;
            else if (sel.value === 'no_coincide') c.nc++;
            else if (r.querySelector('.rv-enfermo').checked) c.enf++;
            else c.sano++;
        });
        document.getElementById('rsPend').textContent = 'Sin revisar: ' + c.pend;
        document.getElementById('rsPend').style.background = c.pend ? 'var(--f-rojo)' : 'var(--f-verde)';
        document.getElementById('rsSano').textContent = 'Sanos: ' + c.sano;
        document.getElementById('rsEnf').textContent = 'Enfermos/heridos: ' + c.enf;
        document.getElementById('rsMuerto').textContent = 'Muertos: ' + c.muerto;
        document.getElementById('rsNc').textContent = 'No coinciden: ' + c.nc;
        document.getElementById('avisoConfirmar').textContent = c.pend ? 'Faltan ' + c.pend + ' por revisar' : '';
        return c.pend;
    }

    rows.forEach(function (r) {
        r.addEventListener('change', function () { r.classList.remove('sin-revisar'); pintar(r); resumen(); });
        pintar(r);
    });
    resumen();

    // Escanear / escribir arete + Enter = Llego
    var scan = document.getElementById('escanearArete'), aviso = document.getElementById('avisoEscaneo');
    scan.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var arete = scan.value.trim();
        if (!arete) return;
        var row = rows.find(function (r) { return r.dataset.siniiga === arete; });
        if (!row) {
            aviso.textContent = '✗ El arete ' + arete + ' no esta en esta compra';
            aviso.style.color = '#dc3545';
        } else {
            row.querySelector('input[value=verificado]').checked = true;
            row.classList.remove('sin-revisar');
            pintar(row); resumen();
            row.classList.add('escaneado');
            row.scrollIntoView({block: 'center', behavior: 'smooth'});
            aviso.textContent = '✓ ' + arete + ' marcado como Llego';
            aviso.style.color = '#0b4f86';
        }
        scan.value = '';
    });

    document.getElementById('formRecepcion').addEventListener('submit', function (e) {
        if (!resumen()) return;
        e.preventDefault();
        var primero = null;
        rows.forEach(function (r) {
            if (!r.querySelector('input[type=radio]:checked')) { r.classList.add('sin-revisar'); primero = primero || r; }
        });
        primero.scrollIntoView({block: 'center', behavior: 'smooth'});
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
