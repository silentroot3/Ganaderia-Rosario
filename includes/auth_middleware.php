<?php
/**
 * Middleware de autenticación y roles
 */

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
    return $_SESSION;
}

function requireRole($roles) {
    requireLogin();
    if (is_string($roles)) $roles = [$roles];
    if (!in_array($_SESSION['user_rol'], $roles)) {
        http_response_code(403);
        include BASE_PATH . '/modules/errors/403.php';
        exit;
    }
    return $_SESSION;
}

function hasRole($rol) {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $rol;
}

function hasAnyRole($roles) {
    if (!isset($_SESSION['user_rol'])) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($_SESSION['user_rol'], $roles);
}

function redirigirPorRol() {
    header('Location: ' . BASE_URL . '/dashboard');
    exit;
}
