<?php
/**
 * Formulario de compra compartido (crear y editar compra)
 * - Proveedor identificado por UPP: si ya existe se rellenan sus datos; si es nuevo se guarda al registrar la compra.
 * - Bovinos: una fila por animal con arete, sexo (botones), meses y peso. La raza siempre es Cruzado.
 * - El precio total se calcula con el precio por kg y el peso estimado.
 */

const COMPRA_RAZA_DEFAULT = 'Cruzado';

/** Siguiente numero de guia REEMO (6 digitos, consecutivo). Se asigna solo al guardar la compra. */
function siguienteReemo($db) {
    $max = (int)$db->query("SELECT COALESCE(MAX(CAST(numero_reemo AS UNSIGNED)), 0) FROM compras WHERE numero_reemo REGEXP '^[0-9]{6}$'")->fetchColumn();
    return (string)max(100001, $max + 1);
}

/** Valida las lineas capturadas; lanza Exception con el numero de bovino que falla */
function validarBovinosCompra($db, array $bovinos) {
    if (count($bovinos) === 0) throw new Exception('Debe agregar al menos un bovino');
    $vistos = [];
    $n = 0;
    foreach ($bovinos as $b) {
        $n++;
        $arete = trim($b['numero_arete'] ?? '');
        if ($arete === '') throw new Exception("El bovino #$n no tiene numero de arete");
        if (!in_array($b['sexo'] ?? '', ['MACHO', 'HEMBRA'], true)) throw new Exception("Selecciona el sexo del bovino #$n (arete $arete)");
        if (isset($vistos[$arete])) throw new Exception("El arete $arete esta duplicado");
        $vistos[$arete] = true;

        $stmt = $db->prepare("SELECT id FROM bovinos WHERE id_siniiga = ?");
        $stmt->execute([$arete]);
        if ($stmt->fetch()) throw new Exception("El SINIIGA $arete ya existe en el sistema");
    }
}

/** Inserta las lineas en compra_bovinos y devuelve [cantidad, peso_total] */
function guardarBovinosCompra($db, $compra_id, array $bovinos, $offset = 0) {
    $stmt = $db->prepare("
        INSERT INTO compra_bovinos (compra_id, numero_linea, numero_arete, sexo, raza, meses, peso_estimado)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $linea = (int)$offset; $peso = 0;
    foreach ($bovinos as $b) {
        $linea++;
        $p = (float)($b['peso_estimado'] ?? 0);
        $peso += $p;
        $stmt->execute([
            $compra_id, $linea, trim($b['numero_arete']), $b['sexo'],
            COMPRA_RAZA_DEFAULT,
            (int)($b['meses'] ?? 0) ?: null,
            $p ?: null,
        ]);
    }
    return [$linea, $peso];
}

/**
 * Lee los datos del proveedor del POST, lo da de alta (o actualiza) por su UPP
 * y devuelve ['id' => ..., 'nombre' => ..., 'upp' => ..., 'localidad' => ..., 'municipio' => ..., 'estado' => ...]
 */
function guardarProveedorCompra($db, array $post) {
    $p = [
        'upp'       => trim($post['proveedor_upp'] ?? ''),
        'nombre'    => trim($post['proveedor_nombre'] ?? ''),
        'localidad' => trim($post['proveedor_localidad'] ?? ''),
        'municipio' => trim($post['proveedor_municipio'] ?? ''),
        'estado'    => trim($post['proveedor_estado'] ?? ''),
        'telefono'  => trim($post['proveedor_telefono'] ?? ''),
        'email'     => strtolower(trim($post['proveedor_email'] ?? '')),
    ];
    if ($p['upp'] === '') throw new Exception('La UPP del proveedor es obligatoria');
    if ($p['nombre'] === '') throw new Exception('El nombre del rancho es obligatorio');
    if (strlen(preg_replace('/\D/', '', $p['telefono'])) < 10) throw new Exception('Captura el telefono del proveedor (10 digitos)');
    if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('El correo del proveedor no es valido');

    $stmt = $db->prepare("
        INSERT INTO proveedores (upp, nombre, localidad, municipio, estado, telefono, email)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            nombre = VALUES(nombre), localidad = VALUES(localidad),
            municipio = VALUES(municipio), estado = VALUES(estado),
            telefono = VALUES(telefono), email = VALUES(email), activo = 1
    ");
    $stmt->execute([$p['upp'], $p['nombre'], $p['localidad'] ?: null, $p['municipio'] ?: null, $p['estado'] ?: null,
                    $p['telefono'], $p['email'] ?: null]);
    $p['id'] = (int)$db->lastInsertId();
    return $p;
}

/** Dibuja la seccion "Proveedor (Origen)". $datos = valores actuales con claves upp, nombre, localidad, municipio, estado, telefono, email */
function compraProveedorCampos($db, array $datos) {
    $proveedores = [];
    foreach ($db->query("SELECT upp, nombre, localidad, municipio, estado, telefono, email FROM proveedores WHERE upp IS NOT NULL AND activo = 1 ORDER BY nombre") as $r) {
        $proveedores[$r['upp']] = $r;
    }
    $campo = 'width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;';
    $etiqueta = 'display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.85rem;';
    ?>
<h3 style="margin-bottom: 15px; color: #0b4f86; border-bottom: 2px solid #0b4f86; padding-bottom: 10px;">
    Proveedor (Origen)
</h3>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
    <div>
        <label style="<?= $etiqueta ?>">UPP *</label>
        <input type="text" name="proveedor_upp" id="prov-upp" required list="prov-upps" autocomplete="off"
               placeholder="Ej: 240351162001"
               value="<?= htmlspecialchars($datos['upp'] ?? '') ?>"
               style="<?= $campo ?> font-family: monospace; font-weight: 600;">
        <datalist id="prov-upps">
            <?php foreach ($proveedores as $upp => $r): ?>
                <option value="<?= htmlspecialchars($upp) ?>"><?= htmlspecialchars($r['nombre']) ?></option>
            <?php endforeach; ?>
        </datalist>
        <small id="prov-estado" style="display: block; margin-top: 5px; font-size: 0.8rem;"></small>
    </div>

    <div>
        <label style="<?= $etiqueta ?>">Nombre del rancho *</label>
        <input type="text" name="proveedor_nombre" id="prov-nombre" required
               placeholder="Ej: Rancho El Rosario"
               value="<?= htmlspecialchars($datos['nombre'] ?? '') ?>"
               style="<?= $campo ?>">
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
    <div>
        <label style="<?= $etiqueta ?>">Telefono *</label>
        <input type="tel" name="proveedor_telefono" id="prov-telefono" required inputmode="tel"
               placeholder="Ej: 487 111 2233"
               value="<?= htmlspecialchars($datos['telefono'] ?? '') ?>" style="<?= $campo ?>">
    </div>
    <div>
        <label style="<?= $etiqueta ?>">Correo electronico (opcional)</label>
        <input type="email" name="proveedor_email" id="prov-email"
               placeholder="Ej: rancho@correo.com"
               value="<?= htmlspecialchars($datos['email'] ?? '') ?>" style="<?= $campo ?>">
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 30px;">
    <div>
        <label style="<?= $etiqueta ?>">Localidad</label>
        <input type="text" name="proveedor_localidad" id="prov-localidad"
               value="<?= htmlspecialchars($datos['localidad'] ?? '') ?>" style="<?= $campo ?>">
    </div>
    <div>
        <label style="<?= $etiqueta ?>">Municipio</label>
        <input type="text" name="proveedor_municipio" id="prov-municipio"
               value="<?= htmlspecialchars($datos['municipio'] ?? '') ?>" style="<?= $campo ?>">
    </div>
    <div>
        <label style="<?= $etiqueta ?>">Estado</label>
        <input type="text" name="proveedor_estado" id="prov-estado-mx"
               value="<?= htmlspecialchars($datos['estado'] ?? '') ?>" style="<?= $campo ?>">
    </div>
</div>

<script>
(function () {
    var PROVEEDORES = <?= json_encode($proveedores, JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var upp = document.getElementById('prov-upp');
    var aviso = document.getElementById('prov-estado');
    var campos = {
        nombre: document.getElementById('prov-nombre'),
        localidad: document.getElementById('prov-localidad'),
        municipio: document.getElementById('prov-municipio'),
        estado: document.getElementById('prov-estado-mx'),
        telefono: document.getElementById('prov-telefono'),
        email: document.getElementById('prov-email')
    };
    var ultimo = null;

    function revisar(rellenar) {
        var clave = upp.value.trim(), p = PROVEEDORES[clave];
        if (!clave) { aviso.textContent = ''; ultimo = null; return; }
        if (p) {
            if (rellenar && ultimo !== clave) {
                Object.keys(campos).forEach(function (k) { campos[k].value = p[k] || ''; });
            }
            aviso.textContent = '✓ Proveedor registrado: ' + p.nombre;
            aviso.style.color = '#0b4f86';
            ultimo = clave;
        } else {
            if (ultimo !== null) { Object.keys(campos).forEach(function (k) { campos[k].value = ''; }); }
            aviso.textContent = 'Nuevo proveedor: sus datos se guardaran con la compra';
            aviso.style.color = '#b26a00';
            ultimo = null;
        }
    }

    upp.addEventListener('input', function () { revisar(true); });
    revisar(false);
})();
</script>
<?php
}

/** Dibuja el capturador. $filas = lineas ya guardadas (para editar) */
function compraBovinosWidget(array $filas = []) {
    $iniciales = [];
    foreach ($filas as $f) {
        $iniciales[] = [
            'numero_arete' => $f['numero_arete'] ?? '', 'sexo' => $f['sexo'] ?? '',
            'meses' => $f['meses'] ?? '', 'peso_estimado' => $f['peso_estimado'] ?? '',
        ];
    }
    ?>
<style>
.bv-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
.bv-resumen { margin-left:auto; font-weight:600; color:#0b4f86; text-align:right; }
.bv-head, .bv-row { display:grid; grid-template-columns:34px minmax(150px,1fr) 190px 110px 120px 38px; gap:8px; align-items:center; }
.bv-head { font-size:0.75rem; font-weight:700; color:var(--tenue); text-transform:uppercase; padding:0 8px 6px; }
.bv-row { background:var(--suave); border:1px solid var(--linea); border-radius:8px; padding:6px 8px; margin-bottom:6px; }
.bv-row.bv-error { border-color:#dc3545; background:var(--f-rojo); }
.bv-num { width:28px; height:28px; border-radius:50%; background:#0b4f86; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; }
.bv-arete, .bv-input { width:100%; padding:7px 9px; border:1px solid var(--linea); border-radius:6px; font-size:0.95rem; }
.bv-arete { font-family:monospace; font-weight:600; }
.bv-input { text-align:center; font-weight:600; }
.bv-sexo { display:flex; gap:6px; }
.bv-btn { flex:1; padding:6px 10px; border:2px solid var(--linea); background:var(--superficie); border-radius:6px; cursor:pointer; font-size:0.9rem; font-weight:600; color:var(--texto); }
.bv-btn:hover { border-color:#0b4f86; }
.bv-btn.macho.on { background:#1e88e5; border-color:#1e88e5; color:#fff; }
.bv-btn.hembra.on { background:#d81b60; border-color:#d81b60; color:#fff; }
.bv-del { width:34px; height:34px; border:none; border-radius:6px; background:#dc3545; color:#fff; cursor:pointer; font-weight:700; }
@media (max-width: 700px) {
    .bv-head { display:none; }
    .bv-row { grid-template-columns:28px 1fr 1fr 34px; }
    .bv-row .bv-sexo { grid-column:2 / 4; }
}
</style>

<div class="bv-bar">
    <button type="button" class="btn btn-primary" id="bv-add1">+ Agregar bovino</button>
    <span class="bv-resumen" id="bv-resumen"></span>
</div>
<p style="color:var(--tenue);font-size:0.85rem;margin-bottom:10px;">Raza: <strong><?= COMPRA_RAZA_DEFAULT ?></strong>. Los pesos son estimados; al recibir se pesan de nuevo. Presiona <strong>Enter</strong> en el peso para pasar al siguiente animal.</p>
<div class="bv-head"><span>#</span><span>Arete SINIIGA</span><span>Sexo</span><span>Meses</span><span>Peso (kg)</span><span></span></div>
<div id="bv-container"></div>

<script>
(function () {
    var INICIALES = <?= json_encode($iniciales, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var cont = document.getElementById('bv-container');
    var form = cont.closest('form');
    var precioKg = form ? form.querySelector('[name="precio_por_kg"]') : null;
    var seq = 0;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function dinero(n) { return n.toLocaleString('es-MX', {style: 'currency', currency: 'MXN'}); }

    function filaHTML(i, d) {
        return '<span class="bv-num"></span>' +
            '<input type="text" class="bv-arete" name="bovinos[' + i + '][numero_arete]" inputmode="numeric" maxlength="15" placeholder="Arete" required value="' + esc(d.numero_arete) + '">' +
            '<div class="bv-sexo">' +
                '<button type="button" class="bv-btn macho' + (d.sexo === 'MACHO' ? ' on' : '') + '" data-v="MACHO">Macho</button>' +
                '<button type="button" class="bv-btn hembra' + (d.sexo === 'HEMBRA' ? ' on' : '') + '" data-v="HEMBRA">Hembra</button>' +
                '<input type="hidden" class="bv-h-sexo" name="bovinos[' + i + '][sexo]" value="' + esc(d.sexo) + '">' +
            '</div>' +
            '<input type="number" min="0" class="bv-input bv-meses" name="bovinos[' + i + '][meses]" placeholder="meses" value="' + esc(d.meses) + '">' +
            '<input type="number" min="0" step="0.1" class="bv-input bv-peso" name="bovinos[' + i + '][peso_estimado]" placeholder="kg" value="' + esc(d.peso_estimado) + '">' +
            '<button type="button" class="bv-del" title="Quitar">✕</button>';
    }

    function agregar(d) {
        seq++;
        var row = document.createElement('div');
        row.className = 'bv-row';
        row.innerHTML = filaHTML(seq, d || {});
        cont.appendChild(row);
        refrescar();
        return row;
    }

    function refrescar() {
        var rows = cont.querySelectorAll('.bv-row'), kg = 0;
        rows.forEach(function (r, idx) {
            r.querySelector('.bv-num').textContent = idx + 1;
            kg += parseFloat(r.querySelector('.bv-peso').value) || 0;
        });
        var texto = rows.length + ' animal(es) · ' + kg.toLocaleString('es-MX', {maximumFractionDigits: 1}) + ' kg estimados';
        var precio = precioKg ? parseFloat(precioKg.value) || 0 : 0;
        if (precio > 0) texto += ' · Total estimado ' + dinero(kg * precio);
        document.getElementById('bv-resumen').textContent = texto;
        var h = document.getElementById('totalBovinos'); if (h) h.textContent = rows.length;
    }

    cont.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var row = b.closest('.bv-row'); if (!row) return;
        row.classList.remove('bv-error');

        if (b.closest('.bv-sexo')) {
            row.querySelectorAll('.bv-sexo .bv-btn').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            row.querySelector('.bv-h-sexo').value = b.dataset.v;
        } else if (b.classList.contains('bv-del')) {
            row.remove();
        }
        refrescar();
    });

    cont.addEventListener('input', refrescar);
    if (precioKg) precioKg.addEventListener('input', refrescar);

    // Enter en el peso del ultimo animal agrega otro y enfoca su arete
    cont.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.classList.contains('bv-peso')) return;
        e.preventDefault();
        var row = e.target.closest('.bv-row');
        var sig = row.nextElementSibling || agregar({});
        sig.querySelector('.bv-arete').focus();
    });

    document.getElementById('bv-add1').onclick = function () { agregar({}).querySelector('.bv-arete').focus(); };

    // No dejar guardar si falta el sexo de algun animal
    if (form) form.addEventListener('submit', function (e) {
        var falta = null;
        cont.querySelectorAll('.bv-row').forEach(function (r) {
            if (!r.querySelector('.bv-h-sexo').value) { r.classList.add('bv-error'); falta = falta || r; }
        });
        if (falta) { e.preventDefault(); alert('Falta elegir Macho o Hembra en los animales marcados en rojo.'); falta.scrollIntoView({behavior: 'smooth', block: 'center'}); }
    });

    if (INICIALES.length) INICIALES.forEach(function (d) { agregar(d); }); else agregar({});
})();
</script>
<?php
}
