<?php
// ============================================
// PLANTILLA DE CONFIGURACION
// Copia este archivo como includes/config.php y pon tus datos reales.
// includes/config.php NO se sube al repositorio (.gitignore).
// ============================================
// Bufer de salida propio y sin limite: permite redirigir (header Location) aunque el menu ya se haya
// dibujado. El de php.ini (output_buffering=4096) se llena con el sidebar y causaba "headers already sent".
ob_start();


// Si la cookie de sesión está corrupta, limpiarla
if (isset($_COOKIE['GANADERIA_SESSION']) && 
    !preg_match('/^[a-zA-Z0-9,-]+$/', $_COOKIE['GANADERIA_SESSION'])) {
    unset($_COOKIE['GANADERIA_SESSION']);
    setcookie('GANADERIA_SESSION', '', time() - 3600, '/');
}

// CONFIGURACION GENERAL
define('APP_NAME', 'Sistema de Gestión Ganadera');
define('APP_VERSION', '1.0.0');


// Rutas base
define('BASE_PATH', dirname(__DIR__));             
define('BASE_URL', 'http://ganaderia.local');       // dominio


// CONFIGURACIÓN DE BASE DE DATOS (ANTES de cargar database.php)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'Ganaderia_Rosario');
define('DB_USER', 'usuario_bd');
define('DB_PASS', 'cambia_esta_contrasena');                              

// CONFIGURACION DE SEGURIDAD
define('SALT', 'genera_una_cadena_larga_y_aleatoria');
define('SESSION_TIMEOUT', 3600);        // 1 hr aprox
define('MAX_LOGIN_ATTEMPTS', 3);        // Intentos antes de bloquear (HU-001: 3 fallos = 15 min)
define('BLOCK_TIME', 15);               // Minutos de bloqueo

// CONFIGURACIÓN DE PHP
date_default_timezone_set('America/Mexico_City');

// Configuración segura de sesiones
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('display_errors', 1);
ini_set('display_startup_erros', 1);
ini_set('session.cookie_secure', 0);  
ini_set('session.cookie_samesite', 'Strict');
session_name('GANADERIA_SESSION');
error_reporting(E_ALL);

// Iniciar sesion solo si no esta iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CARGAR ARCHIVOS NECESARIOS (DESPUÉS de las constantes DB)
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/permisos.php';   // clase Permisos disponible en todo el sistema (editar.php la usa antes del header)

// VERIFICAR EXPIRACIÓN DE SESIÓN
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        cerrarSesion();
        header('Location: ' . BASE_URL . '/login?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// VERIFICAR SESIÓN ACTIVA
if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
    if (!verificarSesionActiva($_SESSION['user_id'], $_SESSION['session_token'])) {
        cerrarSesion();
        header('Location: ' . BASE_URL . '/login?session_expired=1');
        exit;
    }
}
