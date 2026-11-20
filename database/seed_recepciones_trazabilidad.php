<?php
// ============================================
// DATOS DE EJEMPLO: 4 compras recibidas con historia completa + 1 compra pendiente
// Cada bovino trae: recepcion, vacunas, desparasitacion, clasificacion,
// corrales, pesajes cada 14 dias, comida diaria (2 comidas), tratamientos.
// Uso:  php database/seed_recepciones_trazabilidad.php
// Se puede borrar todo lo insertado con:  php database/seed_recepciones_trazabilidad.php --borrar
// (todo lo de ejemplo usa aretes que empiezan con 24035 y folios que terminan en -EJ)
// ============================================

// Los datos de conexion salen de includes/config.php (no se escriben aqui)
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once __DIR__ . '/../includes/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$HOY = date('Y-m-d');

function q($sql, $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function ins($sql, $p = []) { global $pdo; q($sql, $p); return (int)$pdo->lastInsertId(); }
function dia($f, $n) { return date('Y-m-d', strtotime("$f $n day")); }

if (in_array('--borrar', $argv, true)) {
    $pdo->beginTransaction();
    $ids = q("SELECT id FROM bovinos WHERE id_siniiga LIKE '24035%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (q("SELECT corral_id, COUNT(*) n FROM bovinos WHERE id_siniiga LIKE '24035%' AND corral_id IS NOT NULL GROUP BY corral_id")->fetchAll() as $r)
        q("UPDATE corrales_todos SET ocupacion_actual = GREATEST(ocupacion_actual - ?, 0) WHERE id = ?", [$r['n'], $r['corral_id']]);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (q("SELECT medicamento_id, SUM(cantidad) c FROM movimientos_medicamento WHERE referencia LIKE '%(ejemplo)%' GROUP BY medicamento_id")->fetchAll() as $r)
            q("UPDATE inventario_medicamentos SET stock_actual = stock_actual + ? WHERE medicamento_id = ?", [$r['c'], $r['medicamento_id']]);
        q("DELETE FROM movimientos_medicamento WHERE referencia LIKE '%(ejemplo)%'");
        q("DELETE FROM alertas WHERE bovino_id IN ($in)");
        q("DELETE FROM tratamiento_dosis WHERE tratamiento_id IN (SELECT id FROM tratamientos WHERE bovino_id IN ($in))");
        foreach (['tratamientos', 'vacunaciones', 'pesajes', 'clasificaciones', 'revisiones', 'movimientos_bovino'] as $t) q("DELETE FROM $t WHERE bovino_id IN ($in)");
        q("UPDATE compra_bovinos SET bovino_id = NULL WHERE bovino_id IN ($in)");
        q("DELETE FROM bovinos WHERE id IN ($in)");
    }
    q("DELETE FROM alimentaciones WHERE observaciones = 'Datos de ejemplo'");
    $compras = q("SELECT id FROM compras WHERE folio LIKE '%-EJ'")->fetchAll(PDO::FETCH_COLUMN);
    if ($compras) {
        $in = implode(',', array_map('intval', $compras));
        q("DELETE FROM alertas WHERE compra_id IN ($in)");
        q("DELETE FROM bajas_recepcion WHERE recepcion_id IN (SELECT id FROM recepciones WHERE compra_id IN ($in))");
        q("DELETE FROM recepciones WHERE compra_id IN ($in)");
        q("DELETE FROM compra_bovinos WHERE compra_id IN ($in)");
        q("DELETE FROM compras WHERE id IN ($in)");
    }
    $pdo->commit();
    echo "Datos de ejemplo borrados\n";
    exit;
}

if (q("SELECT COUNT(*) FROM compras WHERE folio LIKE '%-EJ'")->fetchColumn() > 0) {
    exit("Los datos de ejemplo ya estan cargados (usa --borrar primero)\n");
}

mt_srand(2026);
$pdo->beginTransaction();

// ---------- catalogos ----------
$corrales = [];
foreach (q("SELECT id, nombre, peso_min, peso_max, sexo_permitido, es_hospital, es_recepcion FROM corrales")->fetchAll() as $c) $corrales[$c['id']] = $c;
$RECEP = ['M' => (int)q("SELECT id FROM corrales WHERE es_recepcion=1 AND sexo_permitido='M'")->fetchColumn(),
          'H' => (int)q("SELECT id FROM corrales WHERE es_recepcion=1 AND sexo_permitido='H'")->fetchColumn()];
$HOSP = (int)q("SELECT id FROM corrales WHERE es_hospital=1 ORDER BY ocupacion_actual, id LIMIT 1")->fetchColumn();
$med = [];
foreach (q("SELECT m.id, m.nombre, m.dias_retiro, COALESCE(i.precio_unitario,0) precio FROM medicamentos m LEFT JOIN inventario_medicamentos i ON i.medicamento_id=m.id")->fetchAll() as $m) $med[$m['nombre']] = $m;
$dietas = q("SELECT id, categorias, porcentaje_peso FROM dietas WHERE activo = 1")->fetchAll();
$U = ['admin' => 1, 'recep' => 2, 'vet' => 3, 'clasif' => 4, 'alim' => 5];

function corralPorPeso($sexo, $peso) {
    global $corrales;
    foreach ($corrales as $c) {
        if ($c['es_hospital'] || $c['es_recepcion'] || $c['sexo_permitido'] !== $sexo) continue;
        if (!preg_match('/^[MH]-\d$/', $c['nombre'])) continue;
        if ($peso >= $c['peso_min'] && $peso <= $c['peso_max']) return (int)$c['id'];
    }
    return null;
}
function categoria($sexo, $meses) {
    if ($sexo === 'M') return $meses < 12 ? 'Becerro' : 'Torete';
    return $meses < 12 ? 'Becerra' : 'Vaquilla';
}
function dietaDe($cat) {
    global $dietas;
    foreach ($dietas as $d) if (in_array($cat, explode(',', (string)$d['categorias']), true)) return $d;
    return $dietas[0];
}

// salida de medicamento (inventario + movimiento + costo)
function usarMedicamento($nombre, $cant, $fecha, $bovino_id, $ref) {
    global $med, $U;
    $m = $med[$nombre];
    q("UPDATE inventario_medicamentos SET stock_actual = stock_actual - ? WHERE medicamento_id = ?", [$cant, $m['id']]);
    q("INSERT INTO movimientos_medicamento (medicamento_id, tipo, cantidad, precio_unitario, referencia, bovino_id, usuario_id, fecha)
       VALUES (?, 'salida', ?, ?, ?, ?, ?, ?)", [$m['id'], $cant, $m['precio'], "$ref (ejemplo)", $bovino_id, $U['vet'], $fecha]);
    return round($cant * $m['precio'], 2);
}
// tratamiento con calendario de dosis; las dosis con fecha futura quedan pendientes
function tratamiento($bovino_id, $nombre, $dosis, $num, $cadaHoras, $inicio, $diag, $via = 'Intramuscular') {
    global $med, $U, $HOY;
    $m = $med[$nombre];
    $total = round($dosis * $num, 2);
    $ultima = strtotime($inicio) + ($num - 1) * $cadaHoras * 3600;
    $fin = $m['dias_retiro'] > 0 ? date('Y-m-d', $ultima + $m['dias_retiro'] * 86400) : null;
    $pend = $ultima > time();
    $tid = ins("INSERT INTO tratamientos (bovino_id, medicamento_id, dosis, num_dosis, intervalo_horas, cantidad_total, via_administracion, diagnostico,
                dias_retiro, fecha_aplicacion, fecha_fin_retiro, costo, veterinario_id, estado, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)",
        [$bovino_id, $m['id'], $dosis, $num, $cadaHoras, $total, $via, $diag, $m['dias_retiro'], $inicio, $fin, $U['vet'], $pend ? 'en_curso' : 'terminado', $inicio]);
    $costo = usarMedicamento($nombre, $total, $inicio, $bovino_id, "Tratamiento #$tid" . ($num > 1 ? " ($num dosis reservadas)" : ''));
    q("UPDATE tratamientos SET costo = ? WHERE id = ?", [$costo, $tid]);
    for ($n = 1; $n <= $num; $n++) {
        $t = strtotime($inicio) + ($n - 1) * $cadaHoras * 3600;
        $cuando = date('Y-m-d H:i:s', $t);
        $hecha = $t <= time();
        q("INSERT INTO tratamiento_dosis (tratamiento_id, numero, cantidad, fecha_programada, estado, fecha_aplicada, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
          [$tid, $n, $dosis, $cuando, $hecha ? 'aplicada' : 'pendiente', $hecha ? $cuando : null, $hecha ? $U['vet'] : null]);
    }
    if ($fin && $fin >= $HOY) {
        q("INSERT INTO alertas (tipo, mensaje, bovino_id, rol_destino, created_at) VALUES ('retiro_activo', ?, ?, 'admin', ?)",
          [q("SELECT id_siniiga FROM bovinos WHERE id=?", [$bovino_id])->fetchColumn() . " en retiro por $nombre hasta " . date('d/m/Y', strtotime($fin)) . '.', $bovino_id, $inicio]);
    }
    return $tid;
}
function vacuna($bovino_id, $nombre, $ml, $fecha, $proxima = null) {
    global $med, $U;
    q("INSERT INTO vacunaciones (bovino_id, medicamento_id, fecha_aplicacion, proxima_dosis, veterinario_id, observaciones, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
      [$bovino_id, $med[$nombre]['id'], substr($fecha, 0, 10), $proxima, $U['vet'], "$ml ml", $fecha]);
    usarMedicamento($nombre, $ml, $fecha, $bovino_id, "Vacuna $nombre");
}
function mover($bovino_id, $origen, $destino, $tipo, $motivo, $fecha, $usuario) {
    q("INSERT INTO movimientos_bovino (bovino_id, corral_origen_id, corral_destino_id, tipo_movimiento, motivo, usuario_id, fecha) VALUES (?, ?, ?, ?, ?, ?, ?)",
      [$bovino_id, $origen, $destino, $tipo, $motivo, $usuario, $fecha]);
}

// ---------- escenario ----------
$lotes = [
    ['prov' => 2, 'compra' => dia($HOY, -77), 'llega' => dia($HOY, -75), 'n' => 10, 'pkg' => 78, 'placas' => 'SLP-4821-B', 'chofer' => 'Jose Luis Martinez',
     'enfermo' => [3 => 'Tos y secrecion nasal'], 'muerto' => [], 'nocoincide' => []],
    ['prov' => 3, 'compra' => dia($HOY, -54), 'llega' => dia($HOY, -52), 'n' => 12, 'pkg' => 80, 'placas' => 'SLP-7730-C', 'chofer' => 'Ramon Hernandez',
     'enfermo' => [], 'muerto' => [7 => 'Murio en el traslado'], 'nocoincide' => [], 'cojera' => 5],
    ['prov' => 5, 'compra' => dia($HOY, -32), 'llega' => dia($HOY, -30), 'n' => 10, 'pkg' => 82, 'placas' => 'TAM-1102-A', 'chofer' => 'Pedro Sanchez',
     'enfermo' => [], 'muerto' => [], 'nocoincide' => [9 => 'El arete no coincide con la guia']],
    ['prov' => 6, 'compra' => dia($HOY, -10), 'llega' => dia($HOY, -8), 'n' => 8, 'pkg' => 85, 'placas' => 'TAM-5519-D', 'chofer' => 'Arturo Lopez',
     'enfermo' => [2 => 'Diarrea y deshidratacion'], 'muerto' => [], 'nocoincide' => [], 'hospital_actual' => 2],
];

$reemo = (int)q("SELECT COALESCE(MAX(CAST(numero_reemo AS UNSIGNED)), 100000) FROM compras WHERE numero_reemo REGEXP '^[0-9]{6}$'")->fetchColumn();
$arete = 1;
$animales = [];   // para la comida: [id, segmentos [[desde, corral]], llega, peso0, gdp, cat]
$resumen = [];

foreach ($lotes as $L) {
    $p = q("SELECT * FROM proveedores WHERE id = ?", [$L['prov']])->fetch();
    if (!preg_match('/^\d{10}$/', preg_replace('/\D/', '', (string)$p['telefono']))) {
        q("UPDATE proveedores SET telefono = ? WHERE id = ?", ['481' . mt_rand(1000000, 9999999), $p['id']]);
        $p['telefono'] = q("SELECT telefono FROM proveedores WHERE id=?", [$p['id']])->fetchColumn();
    }
    $folio = 'COMP-' . date('Ymd', strtotime($L['compra'])) . '-EJ';
    $reemo++;
    $lista = [];
    for ($i = 1; $i <= $L['n']; $i++) {
        $sexo = $i % 5 === 0 || $i % 3 === 0 ? 'H' : 'M';
        $meses = mt_rand(7, 16);
        $peso = mt_rand(160, 290);
        $lista[$i] = ['arete' => sprintf('24035%07d', 1000 + $arete++), 'sexo' => $sexo, 'meses' => $meses, 'peso' => $peso];
    }
    $pesoTotal = array_sum(array_column($lista, 'peso'));
    $cid = ins("INSERT INTO compras (folio, numero_reemo, proveedor_id, motivo, origen_upp, origen_nombre, origen_localidad, origen_municipio, origen_estado,
                cantidad_total, peso_total_compra, precio_por_kg, precio_total, fecha_compra, fecha_llegada_estimada, fecha_llegada_real, estado,
                usuario_compra_id, usuario_recepcion_id, created_at)
                VALUES (?, ?, ?, 'ENGORDA', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recibida', ?, ?, ?)",
        [$folio, (string)$reemo, $p['id'], $p['upp'], $p['nombre'], $p['localidad'], $p['municipio'], $p['estado'],
         $L['n'], $pesoTotal, $L['pkg'], $pesoTotal * $L['pkg'], $L['compra'], $L['llega'], $L['llega'] . ' 10:30:00',
         $U['admin'], $U['recep'], $L['compra'] . ' 12:00:00']);
    $llegaDT = $L['llega'] . ' 10:30:00';
    $rid = ins("INSERT INTO recepciones (folio, compra_id, proveedor_id, usuario_id, fecha_recepcion, cantidad_animales, placas, chofer, estado, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completado', ?)",
        ['REC-' . date('Ymd', strtotime($L['llega'])) . '-EJ', $cid, $p['id'], $U['recep'], $llegaDT, $L['n'], $L['placas'], $L['chofer'], $llegaDT]);

    $vivos = 0; $bajas = 0; $muertos = 0; $pesoRec = 0;
    foreach ($lista as $i => $a) {
        $cbid = ins("INSERT INTO compra_bovinos (compra_id, numero_linea, numero_arete, sexo, raza, meses, peso_estimado, created_at)
                     VALUES (?, ?, ?, ?, 'Cruzado', ?, ?, ?)", [$cid, $i, $a['arete'], $a['sexo'] === 'M' ? 'MACHO' : 'HEMBRA', $a['meses'], $a['peso'], $L['compra'] . ' 12:00:00']);
        if (isset($L['muerto'][$i]) || isset($L['nocoincide'][$i])) {
            $m = isset($L['muerto'][$i]);
            q("INSERT INTO bajas_recepcion (recepcion_id, compra_bovino_id, numero_arete, tipo, cantidad, motivo, usuario_id) VALUES (?, ?, ?, ?, 1, ?, ?)",
              [$rid, $cbid, $a['arete'], $m ? 'muerte' : 'rechazo', $m ? $L['muerto'][$i] : $L['nocoincide'][$i], $U['recep']]);
            $bajas++; if ($m) $muertos++;
            continue;
        }
        $vivos++;
        // peso real en bascula: un poco distinto al estimado de la compra
        $peso0 = $a['peso'] + mt_rand(-8, 4);
        $pesoRec += $peso0;
        $enfermo = $L['enfermo'][$i] ?? null;
        $recep = $RECEP[$a['sexo']];
        $bid = ins("INSERT INTO bovinos (compra_id, recepcion_id, id_siniiga, numero_arete_compra, sexo, raza, peso_entrada, categoria, edad_meses,
                    estado, corral_id, corral_original_id, llego_enfermo, motivo_llegada, created_at)
                    VALUES (?, ?, ?, ?, ?, 'Cruzado', ?, 'Sin clasificar', ?, 'recibido', ?, ?, ?, ?, ?)",
            [$cid, $rid, $a['arete'], $a['arete'], $a['sexo'], $peso0, $a['meses'], $recep, $recep, $enfermo ? 1 : 0, $enfermo, $llegaDT]);
        q("UPDATE compra_bovinos SET recibido = 1, bovino_id = ?, fecha_recepcion = ? WHERE id = ?", [$bid, $llegaDT, $cbid]);
        mover($bid, null, $recep, 'recepcion', "Recepcion de compra $folio" . ($enfermo ? " (llego enfermo: $enfermo)" : ''), $llegaDT, $U['recep']);
        $seg = [[$L['llega'], $recep]];

        // manejo de llegada: vacunas + desparasitacion + vitaminas
        $t0 = $L['llega'] . ' 12:00:00';
        q("INSERT INTO revisiones (bovino_id, veterinario_id, fecha, temperatura, condicion_corporal, peso, estado, diagnostico_preliminar, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
          [$bid, $U['vet'], $t0, $enfermo ? 39.8 : round(38.3 + mt_rand(0, 6) / 10, 1), $enfermo ? 2 : 3, $peso0, $enfermo ? 'OBSERVACION' : 'APTO', $enfermo ?: 'Sano a la llegada', $t0]);
        vacuna($bid, 'Vacuna Aftosa', 2, $t0);
        $ref = dia($L['llega'], 21);
        vacuna($bid, 'Vacuna Clostridial', 5, $t0, $ref);
        if ($ref <= $HOY) {
            vacuna($bid, 'Vacuna Clostridial', 5, $ref . ' 09:00:00');
            q("UPDATE vacunaciones SET proxima_dosis = NULL WHERE bovino_id = ? AND fecha_aplicacion = ?", [$bid, $L['llega']]);
        }
        tratamiento($bid, 'Ivermectina', max(1, round($peso0 / 50 * 2) / 2), 1, 24, $t0, 'Desparasitacion de llegada', 'Subcutanea');
        tratamiento($bid, 'Vitamina ADE', 5, 1, 24, $t0, 'Vitaminas de llegada');

        // clasificacion al dia siguiente
        $cat = categoria($a['sexo'], $a['meses']);
        $dC = dia($L['llega'], 1);
        $pesoC = $peso0 + mt_rand(-2, 2);
        $corral = corralPorPeso($a['sexo'], $pesoC);
        $gdp = mt_rand(105, 150) / 100;   // kg por dia
        $tC = $dC . ' 09:15:00';
        q("UPDATE bovinos SET categoria = ?, castrado = 0 WHERE id = ?", [$cat, $bid]);
        q("INSERT INTO pesajes (bovino_id, peso, fecha, hora, gdp, usuario_id, observaciones, created_at) VALUES (?, ?, ?, '09:15:00', ?, ?, 'Actualizado por clasificacion', ?)",
          [$bid, $pesoC, $dC, round($pesoC - $peso0, 3), $U['clasif'], $tC]);
        q("INSERT INTO clasificaciones (bovino_id, sexo, peso, edad_meses, usuario_id, fecha) VALUES (?, ?, ?, ?, ?, ?)", [$bid, $a['sexo'], $pesoC, $a['meses'], $U['clasif'], $tC]);

        $actual = $recep;
        $estado = 'en_corral';
        $hospitalHasta = null;
        if ($enfermo) {
            mover($bid, $actual, $HOSP, 'hospital', "Clasificado como $cat y enviado a veterinaria: Enfermo ($enfermo)", $tC, $U['clasif']);
            $seg[] = [$dC, $HOSP];
            $actual = $HOSP;
            $tH = $dC . ' 10:00:00';
            if (isset($L['hospital_actual']) && $L['hospital_actual'] === $i) {
                // sigue en hospital: primer antibiotico no funciono, el nuevo aun tiene dosis pendientes
                tratamiento($bid, 'Oxitetraciclina', 10, 3, 24, $tH, $enfermo);
                $ini = date('Y-m-d', strtotime($HOY . ' -2 day')) . ' 08:00:00';
                tratamiento($bid, 'Antibiotico Amplio Espectro', 10, 5, 24, $ini, $enfermo);
                q("UPDATE bovinos SET estado = 'en_cuarentena', en_hospital = 1, motivo_hospital = ?, fecha_entrada_hospital = ?, corral_id = ?, corral_original_id = NULL WHERE id = ?",
                  ["Enfermo: $enfermo", $tH, $HOSP, $bid]);
                q("INSERT INTO revisiones (bovino_id, veterinario_id, fecha, temperatura, condicion_corporal, peso, estado, diagnostico_preliminar, created_at) VALUES (?, ?, ?, 39.6, 2, ?, 'OBSERVACION', 'Recayo: sigue con diarrea, se cambia antibiotico', ?)",
                  [$bid, $U['vet'], $ini, $pesoC, $ini]);
                $estado = 'en_cuarentena';
                $hospitalHasta = '9999-12-31';
            } else {
                tratamiento($bid, 'Oxitetraciclina', 10, 5, 24, $tH, $enfermo);
                $alta = dia($dC, 6);
                $destino = corralPorPeso($a['sexo'], $pesoC + $gdp * 3);
                q("INSERT INTO revisiones (bovino_id, veterinario_id, fecha, temperatura, condicion_corporal, peso, estado, diagnostico_preliminar, created_at) VALUES (?, ?, ?, 38.6, 3, ?, 'APTO', 'Recuperado, se da de alta', ?)",
                  [$bid, $U['vet'], "$alta 11:00:00", $pesoC + 3, "$alta 11:00:00"]);
                mover($bid, $HOSP, $destino, 'alta', "Alta medica: recuperado de $enfermo", "$alta 11:00:00", $U['vet']);
                q("UPDATE bovinos SET fecha_entrada_hospital = ?, fecha_salida_hospital = ?, motivo_hospital = ? WHERE id = ?", [$tH, "$alta 11:00:00", "Enfermo: $enfermo", $bid]);
                $seg[] = [$alta, $destino];
                $actual = $destino;
            }
        } else {
            mover($bid, $actual, $corral, 'clasificacion', "Clasificado como $cat", $tC, $U['clasif']);
            $seg[] = [$dC, $corral];
            $actual = $corral;
        }

        // pesajes cada 14 dias; si ya paso el peso del corral se cambia al siguiente
        $pesoAnt = $pesoC; $fAnt = $dC;
        if ($hospitalHasta === null) {
            for ($f = dia($dC, 14); $f <= $HOY; $f = dia($f, 14)) {
                $dd = (strtotime($f) - strtotime($fAnt)) / 86400;
                $nuevo = round($pesoAnt + $gdp * $dd + mt_rand(-30, 30) / 10, 1);
                $t = "$f 08:" . sprintf('%02d', mt_rand(0, 59)) . ':00';
                q("INSERT INTO pesajes (bovino_id, peso, fecha, hora, gdp, usuario_id, observaciones, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Pesaje de control', ?)",
                  [$bid, $nuevo, $f, substr($t, 11), round(($nuevo - $pesoAnt) / $dd, 3), $U['clasif'], $t]);
                $pesoAnt = $nuevo; $fAnt = $f;
                $c = $corrales[$actual] ?? null;
                if ($c && !$c['es_hospital'] && $nuevo > $c['peso_max']) {
                    $sig = corralPorPeso($a['sexo'], $nuevo);
                    if ($sig && $sig !== $actual) {
                        mover($bid, $actual, $sig, 'traslado', "Cambio por peso ($nuevo kg)", $t, $U['clasif']);
                        $seg[] = [$f, $sig];
                        $actual = $sig;
                    }
                }
                if ($a['meses'] + (int)((strtotime($f) - strtotime($L['llega'])) / 2592000) >= 12 && in_array($cat, ['Becerro', 'Becerra'], true)) {
                    $cat = $a['sexo'] === 'M' ? 'Torete' : 'Vaquilla';
                    q("UPDATE bovinos SET categoria = ? WHERE id = ?", [$cat, $bid]);
                    q("INSERT INTO clasificaciones (bovino_id, sexo, peso, edad_meses, usuario_id, fecha) VALUES (?, ?, ?, ?, ?, ?)",
                      [$bid, $a['sexo'], $nuevo, $a['meses'] + (int)((strtotime($f) - strtotime($L['llega'])) / 2592000), $U['clasif'], $t]);
                }
            }
        }

        // cojera en el lote 2: penicilina en su corral (sin hospital)
        if (!empty($L['cojera']) && $L['cojera'] === $i) {
            tratamiento($bid, 'Penicilina', round($pesoAnt / 25), 3, 24, dia($HOY, -9) . ' 08:30:00', 'Cojera pata trasera derecha (pododermatitis)');
        }

        $edad = $a['meses'] + (int)((strtotime($HOY) - strtotime($L['llega'])) / 2592000);
        if ($estado === 'en_corral') {
            q("UPDATE bovinos SET estado = 'en_corral', corral_id = ?, corral_original_id = ?, edad_meses = ? WHERE id = ?", [$actual, $actual, $edad, $bid]);
        } else {
            q("UPDATE bovinos SET edad_meses = ? WHERE id = ?", [$edad, $bid]);
        }
        q("UPDATE corrales_todos SET ocupacion_actual = ocupacion_actual + 1 WHERE id = ?", [$actual]);
        $animales[] = ['id' => $bid, 'seg' => $seg, 'peso0' => $peso0, 'gdp' => $gdp, 'llega' => $L['llega'], 'cat' => $cat];
    }

    q("UPDATE recepciones SET cantidad_llegada = ?, cantidad_recibida = ?, cantidad_bajas = ?, peso_total_recibido = ? WHERE id = ?",
      [$vivos + $muertos, $vivos, $bajas, $pesoRec, $rid]);
    $resumen[] = "$folio (REEMO $reemo, {$p['nombre']}): $vivos recibidos" . ($bajas ? ", $bajas baja(s)" : '');
}

// ---------- comida: 2 comidas diarias por corral ocupado ----------
$ini = min(array_column($animales, 'llega'));
$existe = q("SELECT CONCAT(corral_id,'|',fecha,'|',horario) FROM alimentaciones")->fetchAll(PDO::FETCH_COLUMN);
$existe = array_flip($existe);
$nAlim = 0;
for ($d = $ini; $d < $HOY; $d = dia($d, 1)) {
    $porCorral = [];
    foreach ($animales as $a) {
        if ($d < $a['llega']) continue;
        $corral = null;
        foreach ($a['seg'] as $s) if ($s[0] <= $d) $corral = $s[1];
        if (!$corral) continue;
        $dias = (strtotime($d) - strtotime($a['llega'])) / 86400;
        $porCorral[$corral]['peso'] = ($porCorral[$corral]['peso'] ?? 0) + $a['peso0'] + $a['gdp'] * $dias;
        $cat = $corrales[$corral]['es_recepcion'] ? 'Sin clasificar' : $a['cat'];
        $porCorral[$corral]['cats'][$cat] = ($porCorral[$corral]['cats'][$cat] ?? 0) + 1;
    }
    foreach ($porCorral as $corral => $x) {
        arsort($x['cats']);
        $dieta = dietaDe(array_key_first($x['cats']));
        $kg = round($x['peso'] * $dieta['porcentaje_peso'] / 100 / 2, 1);
        foreach (['mañana' => '07:30:00', 'tarde' => '16:00:00'] as $h => $hora) {
            if (isset($existe["$corral|$d|$h"])) continue;
            q("INSERT INTO alimentaciones (corral_id, dieta_id, cantidad_kg, horario, usuario_id, fecha, hora, observaciones, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Datos de ejemplo', ?)",
              [$corral, $dieta['id'], $kg, $h, $U['alim'], $d, $hora, "$d $hora"]);
            $nAlim++;
        }
    }
}

// ---------- compra pendiente para probar la recepcion ----------
$p = q("SELECT * FROM proveedores WHERE id = 7")->fetch();
if (!preg_match('/^\d{10}$/', preg_replace('/\D/', '', (string)$p['telefono']))) q("UPDATE proveedores SET telefono = '4444445566' WHERE id = 7");
$reemo++;
$fc = dia($HOY, -2);
$lista = [];
$pesoTotal = 0;
for ($i = 1; $i <= 6; $i++) { $lista[$i] = [sprintf('24035%07d', 1000 + $arete++), $i % 2 ? 'MACHO' : 'HEMBRA', mt_rand(8, 13), mt_rand(170, 240)]; $pesoTotal += $lista[$i][3]; }
$cid = ins("INSERT INTO compras (folio, numero_reemo, proveedor_id, motivo, origen_upp, origen_nombre, origen_localidad, origen_municipio, origen_estado,
            cantidad_total, peso_total_compra, precio_por_kg, precio_total, fecha_compra, fecha_llegada_estimada, estado, usuario_compra_id, created_at)
            VALUES (?, ?, ?, 'ENGORDA', ?, ?, ?, ?, ?, 6, ?, 84, ?, ?, ?, 'pendiente', ?, ?)",
    ['COMP-' . date('Ymd', strtotime($fc)) . '-EJ', (string)$reemo, $p['id'], $p['upp'], $p['nombre'], $p['localidad'], $p['municipio'], $p['estado'],
     $pesoTotal, $pesoTotal * 84, $fc, dia($HOY, 1), $U['admin'], "$fc 13:00:00"]);
foreach ($lista as $i => $a) {
    q("INSERT INTO compra_bovinos (compra_id, numero_linea, numero_arete, sexo, raza, meses, peso_estimado, created_at) VALUES (?, ?, ?, ?, 'Cruzado', ?, ?, ?)",
      [$cid, $i, $a[0], $a[1], $a[2], $a[3], "$fc 13:00:00"]);
}
q("INSERT INTO alertas (tipo, mensaje, compra_id, rol_destino, created_at) VALUES ('compra_pendiente', ?, ?, 'recepcionista', ?)",
  ["Compra COMP-" . date('Ymd', strtotime($fc)) . "-EJ de {$p['nombre']}: llegan 6 bovinos el " . date('d/m/Y', strtotime(dia($HOY, 1))), $cid, "$fc 13:00:00"]);
$resumen[] = "COMP-" . date('Ymd', strtotime($fc)) . "-EJ (REEMO $reemo, {$p['nombre']}): PENDIENTE de recibir, 6 bovinos";

$pdo->commit();
echo implode("\n", $resumen), "\n", count($animales), " bovinos con historia, $nAlim comidas registradas\n";
