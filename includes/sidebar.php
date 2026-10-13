<?php
/**
 * Sidebar dinámico según permisos del usuario
 */
if (!isset($_SESSION['user_id'])) {
    return;
}

$modulosVisibles = Permisos::modulosVisibles();
$moduloActual = basename(dirname($_SERVER['PHP_SELF']));

// Ajustar nombre según la ruta
$rutaActual = $_SERVER['REQUEST_URI'] ?? '';

$infoUsuario = [
    'nombre' => $_SESSION['nombre'] ?? 'Usuario',
    'apellido' => $_SESSION['apellido'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'rol' => $_SESSION['rol'] ?? 'invitado',
];

$inicial = strtoupper(substr($infoUsuario['nombre'], 0, 1));

$coloresRol = [
    'admin' => '#226633',
    'recepcionista' => '#1e88e5',
    'veterinario' => '#43a047',
    'clasificador' => '#fbc02d',
    'alimentador' => '#8e24aa',
];

$colorRol = $coloresRol[$infoUsuario['rol']] ?? '#666';
?>

<aside class="sidebar">
    <!-- Logo / Marca -->
    <div class="sidebar-brand">
        <span class="sidebar-logo">🐄</span>
        <div class="sidebar-brand-text">
            <h2>El Rosario</h2>
            <small>Sistema Ganadero</small>
        </div>
    </div>

    <!-- Info del usuario -->
    <div class="sidebar-user">
        <div class="user-avatar" style="background-color: <?= $colorRol ?>">
            <?= $inicial ?>
        </div>
        <div class="user-info">
            <span class="user-name">
                <?= htmlspecialchars($infoUsuario['nombre'] . ' ' . $infoUsuario['apellido']) ?>
            </span>
            <span class="user-rol" style="background-color: <?= $colorRol ?>">
                <?= htmlspecialchars($infoUsuario['rol']) ?>
            </span>
        </div>
    </div>

    <!-- Navegación -->
    <nav class="sidebar-nav">
        <?php foreach ($modulosVisibles as $clave => $info): ?>
            <?php
                // Detectar si estamos en ese módulo
                $activo = (strpos($rutaActual, $info['url']) !== false) ? 'active' : '';
            ?>
            <a href="<?= BASE_URL . $info['url'] ?>" class="nav-item <?= $activo ?>">
                <span class="nav-icon"><?= $info['icono'] ?></span>
                <span class="nav-text"><?= $info['titulo'] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Footer del sidebar -->
    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout" class="btn-logout-sidebar">
            <span>🚪</span> Cerrar Sesión
        </a>
    </div>
</aside>
