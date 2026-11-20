<?php
/**
 * Proveedores: ranchos de ganado (por UPP), alimento, medicamento y otros.
 * Toda compra, entrada a bodega o pedido queda ligada a su proveedor (proveedor_id)
 * para ver con quien ya tenemos relacion al momento de pedir.
 */

const TIPOS_PROVEEDOR = [
    'ganado'      => 'Ganado',
    'alimento'    => 'Alimento',
    'medicamento' => 'Medicamento',
    'otro'        => 'Otro',
];

/**
 * Id del proveedor con ese nombre (sin importar mayusculas). Si no existe lo da de alta;
 * si existe pero no tenia ese tipo, se le agrega. Nombre vacio -> null.
 */
function proveedorId($db, $nombre, $tipo = 'otro') {
    $nombre = trim(preg_replace('/\s+/', ' ', (string)$nombre));
    if ($nombre === '') return null;
    if (!isset(TIPOS_PROVEEDOR[$tipo])) $tipo = 'otro';
    $stmt = $db->prepare("SELECT id, tipo FROM proveedores WHERE LOWER(nombre) = LOWER(?) ORDER BY activo DESC, id LIMIT 1");
    $stmt->execute([$nombre]);
    if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($tipo, explode(',', (string)$p['tipo']), true)) {
            $db->prepare("UPDATE proveedores SET tipo = CONCAT_WS(',', NULLIF(tipo, ''), ?) WHERE id = ?")->execute([$tipo, $p['id']]);
        }
        return (int)$p['id'];
    }
    $db->prepare("INSERT INTO proveedores (nombre, tipo) VALUES (?, ?)")->execute([$nombre, $tipo]);
    return (int)$db->lastInsertId();
}

/**
 * <datalist> con los proveedores activos; primero los del tipo pedido.
 * Uso: <input name="proveedor" list="dl-prov-alimento"> + datalistProveedores($db, 'alimento')
 */
function datalistProveedores($db, $tipo, $id = null) {
    $id = $id ?: 'dl-prov-' . $tipo;
    $stmt = $db->prepare("SELECT nombre, telefono, FIND_IN_SET(?, tipo) > 0 AS del_tipo FROM proveedores
                          WHERE activo = 1 ORDER BY del_tipo DESC, nombre");
    $stmt->execute([$tipo]);
    $html = '<datalist id="' . htmlspecialchars($id) . '">';
    foreach ($stmt as $p) {
        $html .= '<option value="' . htmlspecialchars($p['nombre']) . '">'
               . htmlspecialchars(($p['del_tipo'] ? '' : '(otro giro) ') . ($p['telefono'] ?: '')) . '</option>';
    }
    return $html . '</datalist>';
}

/** Chips de los tipos de un proveedor */
function chipsTipoProveedor($tipos) {
    $colores = ['ganado' => 'chip-azul', 'alimento' => 'chip-verde', 'medicamento' => 'chip-rojo', 'otro' => 'chip-gris'];
    $out = '';
    foreach (array_filter(explode(',', (string)$tipos)) as $t) {
        $out .= '<span class="chip ' . ($colores[$t] ?? 'chip-gris') . '">' . htmlspecialchars(TIPOS_PROVEEDOR[$t] ?? $t) . '</span> ';
    }
    return $out;
}

/**
 * Resumen de la relacion con cada proveedor: operaciones, ultima fecha y monto
 * (compras de ganado + entradas de alimento + entradas de medicamento).
 * @return array [proveedor_id => ['ops', 'ultima', 'monto']]
 */
function resumenProveedores($db) {
    $out = [];
    $sumar = function ($rows) use (&$out) {
        foreach ($rows as $r) {
            $o = &$out[(int)$r['pid']];
            $o['ops'] = ($o['ops'] ?? 0) + (int)$r['ops'];
            $o['monto'] = ($o['monto'] ?? 0) + (float)$r['monto'];
            $o['ultima'] = max((string)($o['ultima'] ?? ''), (string)$r['ultima']);
            unset($o);
        }
    };
    $sumar($db->query("SELECT proveedor_id pid, COUNT(*) ops, MAX(fecha_compra) ultima, SUM(COALESCE(precio_total, 0)) monto
                       FROM compras WHERE proveedor_id IS NOT NULL AND estado != 'cancelada' GROUP BY proveedor_id"));
    $sumar($db->query("SELECT proveedor_id pid, COUNT(*) ops, MAX(DATE(fecha)) ultima, SUM(cantidad * COALESCE(precio_unitario, 0)) monto
                       FROM movimientos_inventario WHERE proveedor_id IS NOT NULL AND tipo = 'entrada' GROUP BY proveedor_id"));
    $sumar($db->query("SELECT proveedor_id pid, COUNT(*) ops, MAX(DATE(fecha)) ultima, SUM(cantidad * COALESCE(precio_unitario, 0)) monto
                       FROM movimientos_medicamento WHERE proveedor_id IS NOT NULL AND tipo = 'entrada' GROUP BY proveedor_id"));
    return $out;
}

/**
 * Para cada producto (medicamento o ingrediente), a que proveedores se le ha comprado:
 * [item_id => [['nombre', 'telefono', 'ultima', 'precio', 'proveedor_id'], ...]]  (el mas reciente primero)
 */
function proveedoresPorProducto($db, $tipo) {
    $sql = $tipo === 'medicamento'
        ? "SELECT m.medicamento_id item, p.id pid, p.nombre, p.telefono, MAX(m.fecha) ultima,
                  (SELECT m2.precio_unitario FROM movimientos_medicamento m2 WHERE m2.proveedor_id = p.id AND m2.medicamento_id = m.medicamento_id AND m2.tipo = 'entrada' ORDER BY m2.fecha DESC LIMIT 1) precio
           FROM movimientos_medicamento m JOIN proveedores p ON p.id = m.proveedor_id
           WHERE m.tipo = 'entrada' GROUP BY m.medicamento_id, p.id ORDER BY ultima DESC"
        : "SELECT m.ingrediente_id item, p.id pid, p.nombre, p.telefono, MAX(m.fecha) ultima,
                  (SELECT m2.precio_unitario FROM movimientos_inventario m2 WHERE m2.proveedor_id = p.id AND m2.ingrediente_id = m.ingrediente_id AND m2.tipo = 'entrada' ORDER BY m2.fecha DESC LIMIT 1) precio
           FROM movimientos_inventario m JOIN proveedores p ON p.id = m.proveedor_id
           WHERE m.tipo = 'entrada' GROUP BY m.ingrediente_id, p.id ORDER BY ultima DESC";
    $out = [];
    foreach ($db->query($sql) as $r) {
        $out[(int)$r['item']][] = ['proveedor_id' => (int)$r['pid'], 'nombre' => $r['nombre'], 'telefono' => $r['telefono'],
                                    'ultima' => substr((string)$r['ultima'], 0, 10), 'precio' => $r['precio'] !== null ? (float)$r['precio'] : null];
    }
    return $out;
}
