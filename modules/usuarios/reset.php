<?php
// ============================================
// USUARIOS - Restablecer contraseña (solo admin)
// Genera una contraseña temporal, desbloquea la cuenta y cierra sus sesiones abiertas.
// ============================================

require_once __DIR__ . '/../../includes/config.php';

if (!Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

require_once __DIR__ . '/../../includes/ui.php';

// Solo por POST con token CSRF (antes se podia resetear con un simple enlace)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verificarCSRF($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/usuarios?error=' . urlencode('Solicitud no valida, recarga la pagina e intenta de nuevo'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT id, nombre, apellido, email FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$usuario = $stmt->fetch();
if (!$usuario) {
    header('Location: ' . BASE_URL . '/usuarios?error=' . urlencode('Usuario no encontrado'));
    exit;
}

/** Contraseña temporal de 10 caracteres con mayuscula, minuscula y numero (sin caracteres confusos) */
function generarPasswordTemporal() {
    $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789'];
    $todos = implode('', $grupos);
    $chars = [];
    foreach ($grupos as $g) $chars[] = $g[random_int(0, strlen($g) - 1)];
    while (count($chars) < 10) $chars[] = $todos[random_int(0, strlen($todos) - 1)];
    shuffle($chars);
    return implode('', $chars);
}

$nuevaPassword = generarPasswordTemporal();

$db->prepare("UPDATE usuarios SET password = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?")
   ->execute([encriptarPassword($nuevaPassword), $id]);
$db->prepare("UPDATE sesiones SET activo = 0 WHERE usuario_id = ? AND usuario_id != ?")
   ->execute([$id, $_SESSION['user_id']]);
registrarLog($_SESSION['user_id'], 'usuario_reset_password', 'Reset de contraseña de ' . $usuario['email']);

$titulo = 'Contraseña restablecida';
$modulo = 'usuarios';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 600px; margin: 50px auto;">
    <div class="table-header">
        <h2>Contraseña restablecida</h2>
    </div>
    <div style="padding: 30px; text-align: center;">
        <p style="margin-bottom: 6px;">Usuario: <strong><?= h($usuario['nombre'] . ' ' . $usuario['apellido']) ?></strong></p>
        <p style="margin-bottom: 20px;">Correo para entrar: <strong style="font-family: monospace;"><?= h($usuario['email']) ?></strong></p>
        <p style="font-size: 1.1rem; margin-bottom: 10px;">Nueva contraseña:</p>
        <div style="display: flex; gap: 10px; justify-content: center; margin-bottom: 20px;">
            <input type="text" id="nuevaPassword" readonly value="<?= h($nuevaPassword) ?>"
                   style="background: #0b4f86; color: #fff; border: none; padding: 16px; border-radius: 8px; font-size: 1.5rem; font-family: monospace; font-weight: bold; text-align: center; width: 260px;"
                   onclick="this.select()">
            <button type="button" class="btn btn-secondary" id="copiarPassword">Copiar</button>
        </div>
        <p style="color: var(--tenue); margin-bottom: 20px;">
            Compártela con el usuario. Respeta mayúsculas y minúsculas.<br>
            La cuenta quedó desbloqueada y sus sesiones abiertas se cerraron.
        </p>
        <a href="<?= BASE_URL ?>/usuarios" class="btn btn-primary">Volver a usuarios</a>
    </div>
</div>

<script>
document.getElementById('copiarPassword').addEventListener('click', function () {
    var campo = document.getElementById('nuevaPassword'), btn = this;
    campo.select();
    (navigator.clipboard ? navigator.clipboard.writeText(campo.value) : Promise.reject())
        .catch(function () { document.execCommand('copy'); })
        .finally(function () { btn.textContent = 'Copiada ✓'; });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
