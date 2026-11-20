<?php
// ============================================
// CORRALES - Eliminar corral vacio (borrado logico)
// Se marca como inactivo en corrales_todos: desaparece de todas las listas
// pero su historial de movimientos y alimentacion se conserva.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('corrales', 'eliminar');

$id = (int)($_POST['id'] ?? 0);
$volver = BASE_URL . '/corrales';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Solicitud no valida');
    exigirCSRF();

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
    $stmt->execute([$id]);
    $corral = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$corral) throw new Exception('Corral no encontrado');
    if ($corral['es_recepcion']) throw new Exception('Los corrales de recepcion (RECEP) no se pueden eliminar');

    $stmt = $db->prepare("SELECT COUNT(*) FROM bovinos WHERE corral_id = ? AND estado NOT IN ('vendido', 'muerto', 'devuelto')");
    $stmt->execute([$id]);
    $quedan = (int)$stmt->fetchColumn();
    if ($quedan > 0) throw new Exception("{$corral['nombre']} todavia tiene $quedan bovinos. Migralos primero.");

    // Libera el nombre para poder crear otro corral igual en el futuro
    $db->prepare("
        UPDATE corrales_todos
        SET activo = 0, fecha_eliminado = NOW(), ocupacion_actual = 0,
            nombre = LEFT(CONCAT(nombre, ' (eliminado ', id, ')'), 50)
        WHERE id = ?
    ")->execute([$id]);
    registrarLog($_SESSION['user_id'], 'corral_eliminar', "Corral {$corral['nombre']} eliminado");

    header('Location: ' . $volver . '?msg=' . urlencode("Corral {$corral['nombre']} eliminado"));
} catch (Exception $e) {
    header('Location: ' . $volver . '?error=' . urlencode($e->getMessage()));
}
exit;
