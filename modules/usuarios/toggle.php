<?php
// ============================================
// USUARIOS - Activar / desactivar cuenta (HU-004)
// Solo por POST con token CSRF. Desactivar cierra sus sesiones.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('usuarios', 'editar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/usuarios');
    exit;
}

try {
    exigirCSRF();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)$_SESSION['user_id']) throw new Exception('No puedes desactivar tu propia cuenta');

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT activo, email FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) throw new Exception('Usuario no encontrado');

    $nuevo = $u['activo'] ? 0 : 1;
    $db->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
    if (!$nuevo) {
        $db->prepare("UPDATE sesiones SET activo = 0 WHERE usuario_id = ?")->execute([$id]);   // ya no puede seguir entrando
    }
    registrarLog($_SESSION['user_id'], $nuevo ? 'usuario_activar' : 'usuario_desactivar', $u['email']);
    header('Location: ' . BASE_URL . '/usuarios?msg=' . urlencode($nuevo ? 'Usuario activado' : 'Usuario desactivado'));
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/usuarios?error=' . urlencode($e->getMessage()));
}
exit;
