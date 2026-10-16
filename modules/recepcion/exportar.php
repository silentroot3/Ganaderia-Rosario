<?php
// ============================================
// EXPORTAR LOTE A TXT
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/categorizacion.php';
require_once __DIR__ . '/../../includes/generar_archivos.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$db = Database::getInstance()->getConnection();
$recepcion_id = (int)($_GET['id'] ?? 0);

if (!$recepcion_id) {
    die('ID de recepción no válido');
}

// Obtener datos
$stmt = $db->prepare("
    SELECT r.*, p.nombre AS proveedor_nombre
    FROM recepciones r
    LEFT JOIN proveedores p ON r.proveedor_id = p.id
    WHERE r.id = ?
");
$stmt->execute([$recepcion_id]);
$recepcion = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT * FROM bovinos WHERE recepcion_id = ? ORDER BY id");
$stmt->execute([$recepcion_id]);
$bovinos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generar archivo
$archivo = generarArchivoLote($recepcion_id, $bovinos, $recepcion);

// Forzar descarga
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo['nombre'] . '"');
header('Content-Length: ' . filesize($archivo['ruta']));
readfile($archivo['ruta']);
exit;
