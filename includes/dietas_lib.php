<?php
/**
 * Dietas por etapa (Inicio / Desarrollo / Engorda) y racion de cada corral.
 * - El veterinario define cada dieta: ingredientes en % y consumo (% del peso vivo al dia).
 * - Se alimenta por corral, 2 comidas al dia: racion diaria = peso vivo total del corral x % de la dieta (3%).
 *   Se reparte 40% en la mañana y 60% en la tarde; cada comida se redondea al kilo de arriba.
 * - El alimentador prepara la mezcla por toneladas (los ingredientes salen de bodega en kg),
 *   sirve a los corrales de esa mezcla y lo que sobra regresa a la bodega al cerrarla.
 */

const COMIDAS_DIA = 2;

/** Reparto y horarios de las 2 comidas (configurables en la tabla configuracion) */
function configComidas() {
    static $c = null;
    if ($c === null) {
        $pm = max(0, min(100, (float)configValor('comida_pct_manana', 40)));
        $c = [
            'pct' => ['mañana' => $pm, 'tarde' => 100 - $pm],
            'hora' => ['mañana' => configValor('hora_comida_manana', '07:00') ?: '07:00', 'tarde' => configValor('hora_comida_tarde', '15:00') ?: '15:00'],
            'tolerancia' => max(0, (int)configValor('tolerancia_comida_min', 60)),
        ];
    }
    return $c;
}

/**
 * Racion de un corral: kg al dia = peso total x % / 100; mañana y tarde redondeadas al kilo de arriba.
 * Ej: 378 kg x 3% = 11.34 kg -> mañana 40% = 4.54 -> 5 kg, tarde 60% = 6.80 -> 7 kg
 */
function calcularRacion($pesoTotal, $porcentaje) {
    $cfg = configComidas();
    $dia = round($pesoTotal * $porcentaje / 100, 2);
    $m = $dia > 0 ? (int)ceil(round($dia * $cfg['pct']['mañana'] / 100, 4)) : 0;
    $t = $dia > 0 ? (int)ceil(round($dia * $cfg['pct']['tarde'] / 100, 4)) : 0;
    return ['kg_dia' => $dia, 'mañana' => $m, 'tarde' => $t, 'total' => $m + $t];
}

require_once __DIR__ . '/asignacion_corrales.php';
require_once __DIR__ . '/alertas_lib.php';

/** Dietas activas con su receta, indexadas por id */
function dietasActivas($db) {
    $dietas = [];
    foreach ($db->query("SELECT * FROM dietas WHERE activo = 1 ORDER BY id") as $d) {
        $d['lista_categorias'] = array_filter(array_map('trim', explode(',', (string)$d['categorias'])));
        $d['receta'] = [];
        $dietas[$d['id']] = $d;
    }
    $stmt = $db->query("
        SELECT di.dieta_id, di.porcentaje, i.id AS ingrediente_id, i.nombre, COALESCE(inv.stock_actual, 0) AS stock
        FROM dieta_ingredientes di
        JOIN ingredientes i ON i.id = di.ingrediente_id
        LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = i.id
        ORDER BY di.porcentaje DESC
    ");
    foreach ($stmt as $r) {
        if (isset($dietas[$r['dieta_id']])) $dietas[$r['dieta_id']]['receta'][] = $r;
    }
    return $dietas;
}

/** Dieta que corresponde a un corral segun su tipo de animal predominante (hospital -> dieta sin categorias "Hospital") */
function dietaSugerida(array $dietas, $categoria, $esHospital) {
    foreach ($dietas as $d) {
        if ($esHospital && stripos($d['nombre'], 'hospital') !== false) return $d;
    }
    foreach ($dietas as $d) {
        if (in_array($categoria, $d['lista_categorias'], true)) return $d;
    }
    return null;
}

/**
 * Racion de hoy para cada corral con animales:
 * bovinos, peso total, categoria predominante, dieta (asignada o sugerida), kg/dia y kg por comida.
 */
function racionesCorrales($db, ?array $dietas = null) {
    $dietas = $dietas ?? dietasActivas($db);
    $peso = sqlPesoActual('b');
    $filas = $db->query("
        SELECT c.id, c.nombre, c.es_hospital, c.es_recepcion,
               COUNT(b.id) AS bovinos, COALESCE(SUM($peso), 0) AS peso_total,
               (SELECT b2.categoria FROM bovinos b2
                WHERE b2.corral_id = c.id AND b2.estado NOT IN ('vendido','muerto','devuelto')
                GROUP BY b2.categoria ORDER BY COUNT(*) DESC LIMIT 1) AS categoria,
               (SELECT cd.dieta_id FROM corral_dietas cd WHERE cd.corral_id = c.id AND cd.activa = 1
                ORDER BY cd.id DESC LIMIT 1) AS dieta_asignada_id
        FROM corrales c
        JOIN bovinos b ON b.corral_id = c.id AND b.estado NOT IN ('vendido','muerto','devuelto')
        GROUP BY c.id
        ORDER BY c.es_recepcion DESC, c.es_hospital, c.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filas as &$f) {
        $f['sugerida'] = dietaSugerida($dietas, $f['categoria'], (bool)$f['es_hospital']);
        $f['dieta'] = $dietas[$f['dieta_asignada_id']] ?? null;
        $usar = $f['dieta'] ?? $f['sugerida'];
        $r = calcularRacion((float)$f['peso_total'], $usar ? (float)$usar['porcentaje_peso'] : 0);
        $f['kg_dia'] = $r['kg_dia'];
        $f['kg_manana'] = $r['mañana'];
        $f['kg_tarde'] = $r['tarde'];
        $f['kg_servir'] = $r['total'];          // lo que de verdad sale al dia (ya redondeado)
        $f['kg_comida'] = $r['mañana'];
        $f['dieta_usar'] = $usar;
    }
    return $filas;
}

/** Asigna una dieta a un corral (desactiva la anterior) */
function asignarDietaCorral($db, $corral_id, $dieta_id, $usuario_id) {
    $db->prepare("UPDATE corral_dietas SET activa = 0 WHERE corral_id = ? AND activa = 1")->execute([$corral_id]);
    $db->prepare("INSERT INTO corral_dietas (corral_id, dieta_id, activa, usuario_id) VALUES (?, ?, 1, ?)")
       ->execute([$corral_id, $dieta_id, $usuario_id]);
}

/** Puede editar dietas (las hace el veterinario; el admin tambien) */
function puedeEditarDietas() {
    return Permisos::esAdmin() || Permisos::esRol('veterinario');
}

/** Kg de cada ingrediente para preparar $kg de la dieta */
function desgloseMezcla(array $dieta, $kg) {
    return array_map(fn($r) => $r + ['kg' => round($kg * $r['porcentaje'] / 100, 2)], $dieta['receta']);
}

/** Mezclas abiertas con lo que les queda, indexadas por id */
function mezclasAbiertas($db, $dieta_id = null) {
    $sql = "SELECT m.*, d.nombre AS dieta, (m.kg_preparados - m.kg_servidos) AS kg_restantes
            FROM mezclas m JOIN dietas d ON d.id = m.dieta_id WHERE m.estado = 'abierta'";
    $params = [];
    if ($dieta_id) { $sql .= " AND m.dieta_id = ?"; $params[] = $dieta_id; }
    $stmt = $db->prepare($sql . " ORDER BY m.fecha, m.id");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt as $m) $out[$m['id']] = $m;
    return $out;
}

/** Kg disponibles de mezcla preparada por dieta [dieta_id => kg] */
function kgMezclaPorDieta($db) {
    return array_map('floatval', $db->query("
        SELECT dieta_id, SUM(kg_preparados - kg_servidos) FROM mezclas WHERE estado = 'abierta' GROUP BY dieta_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Prepara una mezcla de la dieta: descuenta cada ingrediente de la bodega segun su %.
 * @return array ['id' => mezcla_id, 'bajos' => ingredientes que quedaron en/bajo el minimo]
 */
function prepararMezcla($db, $dieta_id, $toneladas, $usuario_id) {
    $kg = round((float)$toneladas * 1000, 2);
    if ($kg <= 0) throw new Exception('Indica cuantas toneladas vas a preparar');
    $dietas = dietasActivas($db);
    $dieta = $dietas[$dieta_id] ?? null;
    if (!$dieta) throw new Exception('Dieta no valida');
    if (!$dieta['receta'] || abs(array_sum(array_column($dieta['receta'], 'porcentaje')) - 100) > 0.5) {
        throw new Exception("La receta de {$dieta['nombre']} no suma 100 %. El veterinario debe corregirla.");
    }

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $partes = desgloseMezcla($dieta, $kg);
        $stock = $db->prepare("SELECT stock_actual, stock_minimo, unidad FROM inventario_alimento WHERE ingrediente_id = ? FOR UPDATE");
        foreach ($partes as &$p) {
            $stock->execute([$p['ingrediente_id']]);
            $inv = $stock->fetch(PDO::FETCH_ASSOC) ?: ['stock_actual' => 0, 'stock_minimo' => 0, 'unidad' => 'kg'];
            if ((float)$inv['stock_actual'] < $p['kg']) {
                throw new Exception("No alcanza {$p['nombre']}: se necesitan " . number_format($p['kg'], 1)
                    . " kg y hay " . number_format($inv['stock_actual'], 1) . " kg. Prepara menos o solicita mas.");
            }
            $p['queda'] = (float)$inv['stock_actual'] - $p['kg'];
            $p['minimo'] = (float)$inv['stock_minimo'];
        }
        unset($p);

        $db->prepare("INSERT INTO mezclas (dieta_id, kg_preparados, usuario_id) VALUES (?, ?, ?)")->execute([$dieta_id, $kg, $usuario_id]);
        $mezcla_id = (int)$db->lastInsertId();
        $insIng = $db->prepare("INSERT INTO mezcla_ingredientes (mezcla_id, ingrediente_id, porcentaje, kg) VALUES (?, ?, ?, ?)");
        $precio = $db->prepare("SELECT COALESCE(precio_unitario, 0) FROM ingredientes WHERE id = ?");
        foreach ($partes as $p) {
            $insIng->execute([$mezcla_id, $p['ingrediente_id'], $p['porcentaje'], $p['kg']]);
            $db->prepare("UPDATE inventario_alimento SET stock_actual = stock_actual - ? WHERE ingrediente_id = ?")
               ->execute([$p['kg'], $p['ingrediente_id']]);
            $precio->execute([$p['ingrediente_id']]);
            $db->prepare("
                INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, precio_unitario, referencia, usuario_id)
                VALUES (?, 'salida', ?, ?, ?, ?)
            ")->execute([$p['ingrediente_id'], $p['kg'], $precio->fetchColumn(), "Mezcla #$mezcla_id {$dieta['nombre']}", $usuario_id]);
        }
        if ($propia) $db->commit();
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['id' => $mezcla_id, 'bajos' => array_values(array_filter($partes, fn($p) => $p['queda'] <= $p['minimo']))];
}

/**
 * Sirve $kg de la dieta tomandolos de sus mezclas abiertas (la mas vieja primero).
 * @return int id de la mezcla principal usada
 */
function servirDeMezcla($db, $dieta_id, $kg) {
    $abiertas = mezclasAbiertas($db, $dieta_id);
    $disponible = array_sum(array_column($abiertas, 'kg_restantes'));
    if ($disponible + 0.01 < $kg) {
        throw new Exception('Solo hay ' . number_format($disponible, 1) . ' kg de mezcla preparada de esta dieta y quieres servir '
            . number_format($kg, 1) . ' kg. Prepara mas mezcla primero.');
    }
    $falta = $kg; $principal = null;
    foreach ($abiertas as $m) {
        if ($falta <= 0) break;
        $toma = min($falta, (float)$m['kg_restantes']);
        if ($toma <= 0) continue;
        $db->prepare("UPDATE mezclas SET kg_servidos = kg_servidos + ? WHERE id = ?")->execute([$toma, $m['id']]);
        $principal = $principal ?? (int)$m['id'];
        $falta -= $toma;
    }
    return $principal;
}

/**
 * Cierra la mezcla: lo que no se sirvio regresa a la bodega, repartido por ingrediente segun su %.
 * @return float kg devueltos
 */
function cerrarMezcla($db, $mezcla_id, $usuario_id) {
    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM mezclas WHERE id = ? AND estado = 'abierta' FOR UPDATE");
        $stmt->execute([$mezcla_id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new Exception('La mezcla ya estaba cerrada');
        $sobra = round((float)$m['kg_preparados'] - (float)$m['kg_servidos'], 2);

        if ($sobra > 0) {
            $stmt = $db->prepare("SELECT mi.*, COALESCE(i.precio_unitario, 0) AS precio FROM mezcla_ingredientes mi JOIN ingredientes i ON i.id = mi.ingrediente_id WHERE mi.mezcla_id = ?");
            $stmt->execute([$mezcla_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                $regresa = round($sobra * $ing['porcentaje'] / 100, 2);
                if ($regresa <= 0) continue;
                $db->prepare("UPDATE inventario_alimento SET stock_actual = stock_actual + ? WHERE ingrediente_id = ?")
                   ->execute([$regresa, $ing['ingrediente_id']]);
                // 'ajuste' (no 'entrada'): no es una compra, es sobrante que regresa
                $db->prepare("
                    INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, precio_unitario, referencia, usuario_id)
                    VALUES (?, 'ajuste', ?, ?, ?, ?)
                ")->execute([$ing['ingrediente_id'], $regresa, $ing['precio'], "Sobrante mezcla #$mezcla_id", $usuario_id]);
            }
        }
        $db->prepare("UPDATE mezclas SET estado = 'cerrada', kg_devueltos = ?, cerrada_por = ?, fecha_cierre = NOW() WHERE id = ?")
           ->execute([max(0, $sobra), $usuario_id, $mezcla_id]);
        if ($propia) $db->commit();
        return max(0, $sobra);
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ======================================================================
// REPARTO POR LOTE, PREPARAR SOLO LO QUE FALTA Y PRONOSTICO DE BODEGA
// ======================================================================

/** Kg servidos hoy por corral y horario [corral_id => ['mañana' => kg, 'tarde' => kg]] */
function servidoHoy($db, $fecha = null) {
    $stmt = $db->prepare("SELECT corral_id, horario, SUM(cantidad_kg) kg FROM alimentaciones WHERE fecha = ? GROUP BY corral_id, horario");
    $stmt->execute([$fecha ?: date('Y-m-d')]);
    $out = [];
    foreach ($stmt as $r) $out[(int)$r['corral_id']][$r['horario']] = (float)$r['kg'];
    return $out;
}

/** Horario que toca ahora (tarde despues de las 13:00) */
function horarioActual() {
    return (int)date('H') >= 13 ? 'tarde' : 'mañana';
}

/**
 * Plan de reparto de una comida: corrales agrupados por su dieta, lo que le toca a cada uno,
 * lo que ya se sirvio hoy y cuanta mezcla hay. [dieta_id => ['dieta', 'disponible', 'pendiente', 'falta', 'corrales' => [...]]]
 */
function planReparto($db, $horario) {
    $servido = servidoHoy($db);
    $mezcla = kgMezclaPorDieta($db);
    $plan = [];
    foreach (racionesCorrales($db) as $r) {
        $d = $r['dieta_usar'];
        if (!$d) continue;
        $kg = $horario === 'mañana' ? $r['kg_manana'] : $r['kg_tarde'];
        $ya = $servido[(int)$r['id']][$horario] ?? 0;
        if (!isset($plan[$d['id']])) {
            $plan[$d['id']] = ['dieta' => $d, 'disponible' => (float)($mezcla[$d['id']] ?? 0), 'pendiente' => 0, 'servido' => 0, 'corrales' => []];
        }
        $plan[$d['id']]['corrales'][] = $r + ['kg' => $kg, 'ya' => $ya, 'fijada' => (bool)$r['dieta']];
        if ($ya <= 0) $plan[$d['id']]['pendiente'] += $kg; else $plan[$d['id']]['servido'] += $ya;
    }
    foreach ($plan as &$p) $p['falta'] = max(0, round($p['pendiente'] - $p['disponible'], 1));
    unset($p);
    ksort($plan);
    return $plan;
}

/**
 * Sirve varios corrales de una vez. $items = [corral_id => kg]. Todo o nada.
 * @return array ['corrales' => n, 'kg' => total]
 */
function servirLote($db, $horario, array $items, $usuario_id) {
    if (!in_array($horario, ['mañana', 'tarde'], true)) throw new Exception('Solo hay 2 comidas al dia: mañana y tarde');
    $raciones = [];
    foreach (racionesCorrales($db) as $r) $raciones[(int)$r['id']] = $r;
    $porDieta = [];
    foreach ($items as $cid => $kg) {
        $cid = (int)$cid; $kg = round((float)$kg, 1);
        if ($kg <= 0) continue;
        $r = $raciones[$cid] ?? null;
        if (!$r || !$r['dieta_usar']) throw new Exception('Un corral seleccionado no tiene animales o no tiene dieta');
        $porDieta[$r['dieta_usar']['id']][] = [$cid, $kg, $r];
    }
    if (!$porDieta) throw new Exception('Selecciona al menos un corral');
    $mezcla = kgMezclaPorDieta($db);
    $faltan = [];
    foreach ($porDieta as $did => $lista) {
        $total = array_sum(array_column($lista, 1));
        if ($total > ($mezcla[$did] ?? 0) + 0.01) {
            $faltan[] = $lista[0][2]['dieta_usar']['nombre'] . ' (faltan ' . number_format($total - ($mezcla[$did] ?? 0), 1) . ' kg)';
        }
    }
    if ($faltan) throw new Exception('No hay suficiente mezcla preparada de: ' . implode(', ', $faltan) . '. Usa "Preparar lo que falta".');

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT INTO alimentaciones (corral_id, dieta_id, mezcla_id, cantidad_kg, kg_esperado, peso_corral, horario, usuario_id, fecha, hora, observaciones)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE(), CURRENT_TIME(), 'Reparto por lote')");
        $n = 0; $kgT = 0;
        foreach ($porDieta as $did => $lista) {
            foreach ($lista as [$cid, $kg, $r]) {
                $mid = servirDeMezcla($db, $did, $kg);
                $ins->execute([$cid, $did, $mid, $kg, $horario === 'mañana' ? $r['kg_manana'] : $r['kg_tarde'], $r['peso_total'], $horario, $usuario_id]);
                $n++; $kgT += $kg;
            }
        }
        if ($propia) $db->commit();
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['corrales' => $n, 'kg' => $kgT];
}

/** Prepara solo lo que falta de una dieta (redondeado a 10 kg arriba), usando primero lo que sobro */
function prepararFaltante($db, $dieta_id, $kgFalta, $usuario_id) {
    $kg = ceil(max(0, (float)$kgFalta) / 10) * 10;
    if ($kg <= 0) throw new Exception('No falta mezcla de esta dieta');
    return prepararMezcla($db, $dieta_id, $kg / 1000, $usuario_id) + ['kg' => $kg];
}

/**
 * Pronostico de bodega: cuanto se gasta al dia de cada ingrediente con las dietas asignadas
 * (todos los corrales, 2 comidas) y para cuantos dias alcanza (bodega + mezcla ya preparada).
 */
function pronosticoAlimento($db) {
    $aviso = max(1, (int)configValor('dias_aviso_alimento', 10));
    $cubrir = max($aviso, (int)configValor('dias_cubrir_alimento', 30));
    $dietas = dietasActivas($db);
    $diarioDieta = [];
    foreach (racionesCorrales($db, $dietas) as $r) {
        if ($r['dieta_usar']) $diarioDieta[$r['dieta_usar']['id']] = ($diarioDieta[$r['dieta_usar']['id']] ?? 0) + $r['kg_servir'];
    }
    $diario = [];
    foreach ($diarioDieta as $did => $kg) {
        foreach ($dietas[$did]['receta'] ?? [] as $ing) {
            $diario[(int)$ing['ingrediente_id']] = ($diario[(int)$ing['ingrediente_id']] ?? 0) + $kg * $ing['porcentaje'] / 100;
        }
    }
    $enMezcla = [];
    foreach ($db->query("SELECT mi.ingrediente_id, SUM((m.kg_preparados - m.kg_servidos) * mi.porcentaje / 100) kg
                         FROM mezclas m JOIN mezcla_ingredientes mi ON mi.mezcla_id = m.id WHERE m.estado = 'abierta' GROUP BY mi.ingrediente_id") as $r) {
        $enMezcla[(int)$r['ingrediente_id']] = (float)$r['kg'];
    }
    $pedidos = [];
    foreach ($db->query("SELECT ingrediente_id, SUM(cantidad) cant, GROUP_CONCAT(folio ORDER BY id SEPARATOR ', ') folios
                         FROM solicitudes_compra WHERE tipo = 'alimento' AND estado IN ('pendiente','aprobada','pedida') GROUP BY ingrediente_id") as $r) {
        $pedidos[(int)$r['ingrediente_id']] = $r;
    }
    $out = [];
    foreach ($db->query("SELECT i.id, i.nombre, COALESCE(inv.unidad, i.unidad, 'kg') unidad, COALESCE(inv.stock_actual, 0) stock, COALESCE(inv.stock_minimo, 0) minimo
                         FROM ingredientes i LEFT JOIN inventario_alimento inv ON inv.ingrediente_id = i.id WHERE i.activo = 1 ORDER BY i.nombre") as $i) {
        $id = (int)$i['id'];
        $d = round($diario[$id] ?? 0, 2);
        $ef = (float)$i['stock'] + ($enMezcla[$id] ?? 0);
        // dias que faltan para llegar al stock minimo (lo que se quiere tener siempre guardado)
        $min = (float)$i['minimo'];
        $dias = $d > 0 ? max(0, ($ef - $min) / $d) : null;
        $estado = $d <= 0 ? 'sin_uso' : ($dias <= 3 ? 'urgente' : ($dias <= $aviso ? 'pedir' : 'ok'));
        $out[$id] = $i + [
            'diario' => $d, 'en_mezcla' => round($enMezcla[$id] ?? 0, 1), 'efectivo' => round($ef, 1),
            'dias' => $dias, 'se_acaba' => $dias !== null ? date('Y-m-d', strtotime('+' . (int)floor($dias) . ' day')) : null,
            'pedir_kg' => $d > 0 ? max(0, ceil(($d * $cubrir + $min - $ef) / 100) * 100) : 0,
            'estado' => $estado, 'pedido' => $pedidos[$id] ?? null, 'aviso' => $aviso, 'cubrir' => $cubrir,
        ];
    }
    uasort($out, fn($a, $b) => [$a['dias'] === null, $a['dias']] <=> [$b['dias'] === null, $b['dias']]);
    return $out;
}

/** Avisa (una vez al dia) al alimentador y al admin de lo que hay que pedir y aun no se ha pedido */
function revisarAlertasAlimento($db, ?array $pronostico = null) {
    $pronostico = $pronostico ?? pronosticoAlimento($db);
    $ya = $db->prepare("SELECT COUNT(*) FROM alertas WHERE tipo = 'stock_bajo' AND ingrediente_id = ? AND DATE(created_at) = CURDATE()");
    foreach ($pronostico as $p) {
        if (!in_array($p['estado'], ['pedir', 'urgente'], true) || $p['pedido']) continue;
        $ya->execute([$p['id']]);
        if ($ya->fetchColumn()) continue;
        $msg = ($p['estado'] === 'urgente' ? 'URGENTE: ' : '') . "{$p['nombre']}: en " . max(0, floor($p['dias'])) . " dia(s) llega al minimo de " . number_format($p['minimo'], 0) . " kg (el "
             . date('d/m', strtotime($p['se_acaba'])) . "). Pide unos " . number_format($p['pedir_kg'], 0) . " kg.";
        crearAlerta('stock_bajo', $msg, 'alimentador', null, null, $p['id']);
        crearAlerta('stock_bajo', $msg, 'admin', null, null, $p['id']);
    }
}
