<?php
/**
 * Entradas de inventario (medicamentos y alimentos)
 * Usado por: tratamientos/inventario, solicitudes (al recibir un pedido)
 * El proveedor se liga al modulo de proveedores (se da de alta si es nuevo).
 */
require_once __DIR__ . '/proveedores_lib.php';

/**
 * Entrada de medicamento. Si ya existe en inventario suma al stock y
 * recalcula el precio unitario como promedio ponderado.
 */
function entradaMedicamento($medicamento_id, $cantidad, $precio, $lote, $caducidad,
                            $proveedor, $stock_minimo, $usuario_id, $referencia = 'Entrada manual') {
    $db = Database::getInstance()->getConnection();
    $cantidad = (float)$cantidad;
    $precio = (float)$precio;
    if ($cantidad <= 0) throw new Exception('La cantidad debe ser mayor a 0');

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM inventario_medicamentos WHERE medicamento_id = ? FOR UPDATE");
        $stmt->execute([$medicamento_id]);
        $inv = $stmt->fetch();

        if ($inv) {
            $stockViejo = (float)$inv['stock_actual'];
            $precioNuevo = $precio > 0
                ? round((($stockViejo * (float)$inv['precio_unitario']) + ($cantidad * $precio)) / ($stockViejo + $cantidad), 2)
                : (float)$inv['precio_unitario'];
            $db->prepare("
                UPDATE inventario_medicamentos
                SET stock_actual = stock_actual + ?,
                    precio_unitario = ?,
                    lote = COALESCE(NULLIF(?, ''), lote),
                    fecha_caducidad = COALESCE(?, fecha_caducidad),
                    proveedor = COALESCE(NULLIF(?, ''), proveedor),
                    stock_minimo = COALESCE(?, stock_minimo)
                WHERE medicamento_id = ?
            ")->execute([$cantidad, $precioNuevo, $lote, $caducidad ?: null, $proveedor,
                         $stock_minimo !== null && $stock_minimo !== '' ? (float)$stock_minimo : null,
                         $medicamento_id]);
        } else {
            $unidad = 'ml';
            $db->prepare("
                INSERT INTO inventario_medicamentos
                    (medicamento_id, stock_actual, stock_minimo, unidad, fecha_caducidad, lote, proveedor, precio_unitario)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$medicamento_id, $cantidad, (float)($stock_minimo ?: 0), $unidad,
                         $caducidad ?: null, $lote ?: null, $proveedor ?: null, $precio]);
        }

        $db->prepare("
            INSERT INTO movimientos_medicamento (medicamento_id, tipo, cantidad, precio_unitario, referencia, usuario_id, proveedor_id)
            VALUES (?, 'entrada', ?, ?, ?, ?, ?)
        ")->execute([$medicamento_id, $cantidad, $precio, $referencia, $usuario_id, proveedorId($db, $proveedor, 'medicamento')]);

        if ($propia) $db->commit();
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Entrada de alimento (ingrediente) a la bodega */
function entradaAlimento($ingrediente_id, $cantidad, $precio, $proveedor, $usuario_id, $referencia = 'Entrada manual') {
    $db = Database::getInstance()->getConnection();
    $cantidad = (float)$cantidad;
    $precio = (float)$precio;
    if ($cantidad <= 0) throw new Exception('La cantidad debe ser mayor a 0');

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM inventario_alimento WHERE ingrediente_id = ? FOR UPDATE");
        $stmt->execute([$ingrediente_id]);
        if ($stmt->fetchColumn()) {
            $db->prepare("UPDATE inventario_alimento SET stock_actual = stock_actual + ?,
                          proveedor = COALESCE(NULLIF(?, ''), proveedor) WHERE ingrediente_id = ?")
               ->execute([$cantidad, $proveedor, $ingrediente_id]);
        } else {
            $db->prepare("INSERT INTO inventario_alimento (ingrediente_id, stock_actual, proveedor) VALUES (?, ?, ?)")
               ->execute([$ingrediente_id, $cantidad, $proveedor ?: null]);
        }
        if ($precio > 0) {
            $db->prepare("UPDATE ingredientes SET precio_unitario = ? WHERE id = ?")->execute([$precio, $ingrediente_id]);
        }
        $db->prepare("
            INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, precio_unitario, proveedor, proveedor_id, referencia, usuario_id)
            VALUES (?, 'entrada', ?, ?, ?, ?, ?, ?)
        ")->execute([$ingrediente_id, $cantidad, $precio, $proveedor ?: null, proveedorId($db, $proveedor, 'alimento'), $referencia, $usuario_id]);
        if ($propia) $db->commit();
    } catch (Exception $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
