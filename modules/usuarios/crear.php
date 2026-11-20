<?php
// ============================================
// USUARIOS - Alta de empleado (HU-004)
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('usuarios', 'crear');

$db = Database::getInstance()->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $rol = $_POST['rol'] ?? '';
        $telefono = trim($_POST['telefono'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($nombre === '' || $apellido === '' || $email === '' || $rol === '') throw new Exception('Nombre, apellido, correo y puesto son obligatorios');
        if (!validarEmail($email)) throw new Exception('Correo invalido');
        if (!in_array($rol, Permisos::getRoles(), true)) throw new Exception('Puesto invalido');
        if (!validarPassword($password)) throw new Exception('La contraseña debe tener minimo 8 caracteres, mayuscula, minuscula y numero');

        $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) throw new Exception('Ese correo ya esta registrado');

        $db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, rol, telefono, activo) VALUES (?, ?, ?, ?, ?, ?, 1)")
           ->execute([$nombre, $apellido, $email, encriptarPassword($password), $rol, $telefono ?: null]);
        registrarLog($_SESSION['user_id'], 'usuario_crear', "Alta de $email ($rol)");

        header('Location: ' . BASE_URL . '/usuarios?msg=' . urlencode('Usuario creado'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = 'Nuevo usuario';
$modulo = 'usuarios';
$accion = 'crear';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container" style="max-width:700px;margin:0 auto;">
    <div class="table-header"><h2>Nuevo usuario</h2><a href="<?= BASE_URL ?>/usuarios" class="btn btn-secondary">Volver</a></div>
    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" style="padding:30px;" autocomplete="off">
        <?= csrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div><label style="<?= ESTILO_LABEL ?>">Nombre *</label><input type="text" name="nombre" required value="<?= h($_POST['nombre'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Apellido *</label><input type="text" name="apellido" required value="<?= h($_POST['apellido'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div><label style="<?= ESTILO_LABEL ?>">Correo *</label><input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
            <div><label style="<?= ESTILO_LABEL ?>">Telefono</label><input type="text" name="telefono" value="<?= h($_POST['telefono'] ?? '') ?>" style="<?= ESTILO_INPUT ?>"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">
            <div><label style="<?= ESTILO_LABEL ?>">Puesto *</label>
                <select name="rol" required style="<?= ESTILO_INPUT ?>">
                    <?php foreach (Permisos::getRoles() as $r): ?><option value="<?= $r ?>" <?= ($_POST['rol'] ?? '') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
                </select></div>
            <div><label style="<?= ESTILO_LABEL ?>">Contraseña inicial *</label><input type="password" name="password" required autocomplete="new-password" style="<?= ESTILO_INPUT ?>"><small style="color:var(--tenue);">Min. 8, mayuscula, minuscula y numero</small></div>
        </div>
        <div style="text-align:right;"><button class="btn btn-primary" type="submit">Crear usuario</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
