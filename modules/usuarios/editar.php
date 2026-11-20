<?php
require_once __DIR__ . '/../../includes/config.php';

if (!Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_URL . '/usuarios');
    exit;
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    header('Location: ' . BASE_URL . '/usuarios');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = $_POST['rol'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($nombre) || empty($apellido) || empty($email) || empty($rol)) {
            throw new Exception('Todos los campos son obligatorios');
        }
        
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            throw new Exception('El email ya está en uso');
        }
        
        if ($password !== '' && !validarPassword($password)) {
            throw new Exception('La contraseña debe tener minimo 8 caracteres, mayuscula, minuscula y numero');
        }

        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, apellido=?, email=?, rol=? WHERE id=?");
        $stmt->execute([$nombre, $apellido, $email, $rol, $id]);

        // Nueva contraseña elegida por el admin: desbloquea la cuenta y cierra sus otras sesiones
        if ($password !== '') {
            $db->prepare("UPDATE usuarios SET password = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?")
               ->execute([encriptarPassword($password), $id]);
            $db->prepare("UPDATE sesiones SET activo = 0 WHERE usuario_id = ? AND usuario_id != ?")
               ->execute([$id, $_SESSION['user_id']]);
            registrarLog($_SESSION['user_id'], 'usuario_cambio_password', 'Nueva contraseña para ' . $email);
        }
        
        header('Location: ' . BASE_URL . '/usuarios?msg=editado');
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Editar Usuario';
$modulo = 'usuarios';
$accion = 'editar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width: 700px; margin: 0 auto;">
    <div class="table-header">
        <h2>Editar: <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?></h2>
        <a href="<?= BASE_URL ?>/usuarios" class="btn btn-secondary">Volver</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin: 20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" style="padding: 30px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nombre *</label>
                <input type="text" name="nombre" required value="<?= htmlspecialchars($usuario['nombre']) ?>" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Apellido *</label>
                <input type="text" name="apellido" required value="<?= htmlspecialchars($usuario['apellido']) ?>" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Email *</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($usuario['email']) ?>" style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Rol *</label>
            <select name="rol" required style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px;">
                <?php foreach (array_merge(['admin'], Permisos::getRoles()) as $r): ?>
                    <option value="<?= $r ?>" <?= $usuario['rol'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="margin-bottom: 20px; padding: 15px; background: var(--suave); border-radius: 6px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Nueva contraseña</label>
            <input type="text" name="password" autocomplete="new-password"
                   placeholder="Dejar vacío para no cambiarla"
                   style="width: 100%; padding: 10px; border: 1px solid var(--linea); border-radius: 6px; font-family: monospace;">
            <small style="color: var(--tenue);">Mínimo 8 caracteres, con mayúscula, minúscula y número. Al guardarla se desbloquea la cuenta.</small>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <a href="<?= BASE_URL ?>/usuarios" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
