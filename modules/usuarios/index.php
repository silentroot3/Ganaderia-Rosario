<?php
$titulo = 'Usuarios';
$modulo = 'usuarios';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/ui.php';

if (!Permisos::esAdmin()) {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$db = Database::getInstance()->getConnection();

$stmt = $db->query("SELECT id, nombre, apellido, email, rol, activo, ultimo_acceso, intentos_fallidos, bloqueado_hasta, (bloqueado_hasta > NOW()) AS bloqueado FROM usuarios ORDER BY activo DESC, nombre");
$usuarios = $stmt->fetchAll();
$mensajes = ['editado' => 'Usuario actualizado'];
?>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($mensajes[$_GET['msg']] ?? $_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header">
        <h2>Usuarios (<?= count($usuarios) ?>)</h2>
        <a href="<?= BASE_URL ?>/usuarios/crear" class="btn btn-primary">+ Nuevo Usuario</a>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Ultimo acceso</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr style="<?= !$u['activo'] ? 'opacity: 0.5;' : '' ?>">
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></strong></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <span class="badge-rol badge-<?= $u['rol'] ?>">
                        <?= htmlspecialchars($u['rol']) ?>
                    </span>
                </td>
                <td>
                    <?php if ($u['activo'] && $u['bloqueado']): ?>
                        <span style="color: #dc3545; font-weight: 600;">Bloqueado</span>
                        <br><small style="color: var(--tenue);">hasta <?= date('H:i', strtotime($u['bloqueado_hasta'])) ?> · usa Reset</small>
                    <?php elseif ($u['activo']): ?>
                        <span style="color: #28a745; font-weight: 600;">Activo</span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-weight: 600;">Inactivo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca' ?>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>/usuarios/editar?id=<?= $u['id'] ?>" class="btn-icon">Editar</a>
                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" action="<?= BASE_URL ?>/usuarios/toggle" style="display:inline;">
                            <?= csrfField() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn-icon" style="background:none;border:none;cursor:pointer;" onclick="return confirm('Cambiar estado de este usuario?')"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" action="<?= BASE_URL ?>/usuarios/reset" style="display:inline;">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn-icon" style="background:none;border:none;cursor:pointer;" onclick="return confirm('¿Generar una contraseña nueva para <?= htmlspecialchars($u['email'], ENT_QUOTES) ?>?')">Reset</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
