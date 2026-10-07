<?php
require_once __DIR__ . '/../../includes/config.php';

// Cerrar sesión
cerrarSesion();

// Redirigir al login con mensaje
$_SESSION['flash_message'] = [
    'tipo' => 'success',
    'mensaje' => '¡Sesión cerrada correctamente!'
];

header('Location: ' . BASE_URL . '/login');
exit;
?>
