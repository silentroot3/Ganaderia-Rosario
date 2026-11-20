<?php
/**
 * Front Controller - Sistema de Gestión Ganadera
 */

// Mostrar errores en desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Cargar configuración PRIMERO (define BASE_PATH, BASE_URL, etc.)
require_once __DIR__ . '/includes/config.php';

// 2. Obtener la ruta solicitada
$route = isset($_GET['route']) ? $_GET['route'] : '';
$route = trim($route, '/');

// 3. Si no hay ruta, redirigir
if (empty($route)) {
    if (isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/dashboard');
    } else {
        header('Location: ' . BASE_URL . '/login');
    }
    exit;
}

// 4. Mapa de rutas
$routes = array(
    'login'                => BASE_PATH . '/modules/auth/login.php',
    'logout'               => BASE_PATH . '/modules/auth/logout.php',
    'register'             => BASE_PATH . '/modules/auth/register.php',
    'recover'              => BASE_PATH . '/modules/auth/recover.php',
    'dashboard'            => BASE_PATH . '/modules/dashboard/index.php',
    'recepcion'            => BASE_PATH . '/modules/recepcion/index.php',
    'recepcion/detalle'    => BASE_PATH . '/modules/recepcion/detalle.php',
);


// 5. Verificar si la ruta existe
if (!isset($routes[$route])) {
    http_response_code(404);
    echo "<h1>404 - Página no encontrada</h1>";
    echo "<p>La ruta '<strong>" . htmlspecialchars($route) . "</strong>' no existe.</p>";
    echo "<a href='" . BASE_URL . "/login'>Ir al inicio</a>";
    exit;
}

// 6. Rutas públicas (no requieren login)
$publicRoutes = array('login', 'register', 'recover');

// 7. Verificar autenticación
if (!in_array($route, $publicRoutes) && !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// 8. Cargar el archivo de la ruta
require_once $routes[$route];
