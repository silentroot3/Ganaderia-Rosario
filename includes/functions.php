<?php
// Función para obtener la IP del usuario
function obtenerIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// Función para obtener el User Agent
function obtenerUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

// Función para generar token CSRF
function generarCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Función para verificar token CSRF
function verificarCSRF($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Función para sanitizar entrada
function sanitizar($input) {
    if (is_array($input)) {
        return array_map('sanitizar', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Función para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Función para validar contraseña (mínimo 8 caracteres, mayúscula, minúscula, número)
function validarPassword($password) {
    $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/';
    return preg_match($pattern, $password) === 1;
}

// Función para encriptar contraseña
function encriptarPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

// Función para verificar contraseña
function verificarPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Función para generar token seguro
function generarToken($longitud = 64) {
    return bin2hex(random_bytes($longitud / 2));
}

// Función para cerrar sesión
function cerrarSesion() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
        // Eliminar sesión de la base de datos
        $db = Database::getInstance();
        $db->execute(
            "DELETE FROM sesiones WHERE usuario_id = ? AND token = ?",
            [$_SESSION['user_id'], $_SESSION['session_token']]
        );
        
        // Registrar logout
        registrarLog($_SESSION['user_id'], 'logout', 'Cierre de sesión');
    }
    
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}

// Función para registrar logs de actividad
function registrarLog($usuario_id, $accion, $descripcion = '') {
    try {
        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO logs_actividad (usuario_id, accion, descripcion, ip, user_agent) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $usuario_id,
                $accion,
                $descripcion,
                obtenerIP(),
                obtenerUserAgent()
            ]
        );
    } catch(Exception $e) {
        error_log("Error al registrar log: " . $e->getMessage());
    }
}

// Función para verificar si la sesión está activa
function verificarSesionActiva($usuario_id, $token) {
    try {
        $db = Database::getInstance();
        $result = $db->fetchOne(
            "SELECT id FROM sesiones 
             WHERE usuario_id = ? AND token = ? AND activo = 1 
             AND fecha_ultima_actividad > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$usuario_id, $token, SESSION_TIMEOUT]
        );
        return $result !== false;
    } catch(Exception $e) {
        return false;
    }
}

// Función para verificar si el usuario está autenticado
function estaAutenticado() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

// Función para verificar permisos
function tienePermiso($permiso) {
    if (!estaAutenticado()) return false;
    
    // Roles: admin = todos los permisos
    $rolesPermisos = [
        'admin' => ['*'],
        'veterinario' => ['animales_ver', 'animales_editar', 'sanidad_ver', 'sanidad_crear', 'reportes_ver'],
        'encargado' => ['animales_ver', 'inventario_ver', 'inventario_editar', 'produccion_ver'],
        'usuario' => ['animales_ver']
    ];
    
    $rol = $_SESSION['user_rol'] ?? 'usuario';
    
    if (!isset($rolesPermisos[$rol])) return false;
    
    if (in_array('*', $rolesPermisos[$rol])) return true;
    
    return in_array($permiso, $rolesPermisos[$rol]);
}

// Función para redirigir con mensaje
function redirigirConMensaje($url, $tipo, $mensaje) {
    $_SESSION['flash_message'] = [
        'tipo' => $tipo,
        'mensaje' => $mensaje
    ];
    header('Location: ' . BASE_URL . $url);
    exit;
}

// Función para mostrar mensajes flash
function mostrarFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return "<div class='alert alert-{$msg['tipo']} alert-dismissible fade show' role='alert'>
                    {$msg['mensaje']}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}
?>
