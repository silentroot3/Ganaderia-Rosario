<?php
// Protección contra XSS
function protegerXSS() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($_POST as $key => $value) {
            $_POST[$key] = sanitizar($value);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        foreach ($_GET as $key => $value) {
            $_GET[$key] = sanitizar($value);
        }
    }
}

// Protección contra CSRF (excepto en login y páginas públicas)
function protegerCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verificarCSRF($_POST['csrf_token'])) {
            http_response_code(403);
            die('Error de seguridad: Token CSRF inválido.');
        }
    }
}

// Headers de seguridad
function enviarHeadersSeguridad() {
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com;");
}

// Rate limiting para login
function verificarRateLimiting($email) {
    $db = Database::getInstance();
    $ip = obtenerIP();
    
    // Verificar intentos desde esta IP
    $result = $db->fetchOne(
        "SELECT COUNT(*) as intentos FROM logs_actividad 
         WHERE accion = 'login_fallido' 
         AND ip = ? 
         AND fecha > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
        [$ip]
    );
    
    if ($result && $result['intentos'] >= 10) {
        return false; // Demasiados intentos desde esta IP
    }
    
    // Verificar intentos para este email
    $result = $db->fetchOne(
        "SELECT intentos_fallidos, bloqueado_hasta 
         FROM usuarios 
         WHERE email = ?",
        [$email]
    );
    
    if ($result && $result['bloqueado_hasta']) {
        $bloqueadoHasta = new DateTime($result['bloqueado_hasta']);
        $ahora = new DateTime();
        if ($ahora < $bloqueadoHasta) {
            return false; // Usuario bloqueado
        } else {
            // Desbloquear si ya pasó el tiempo
            $db->execute(
                "UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE email = ?",
                [$email]
            );
        }
    }
    
    return true;
}

// Registrar intento de login fallido
function registrarIntentoFallido($email) {
    $db = Database::getInstance();
    
    // Actualizar intentos en usuarios
    $db->execute(
        "UPDATE usuarios 
         SET intentos_fallidos = intentos_fallidos + 1 
         WHERE email = ?",
        [$email]
    );
    
    // Verificar si debe bloquearse
    $result = $db->fetchOne(
        "SELECT intentos_fallidos FROM usuarios WHERE email = ?",
        [$email]
    );
    
    if ($result && $result['intentos_fallidos'] >= MAX_LOGIN_ATTEMPTS) {
        $bloqueo = new DateTime();
        $bloqueo->modify('+' . BLOCK_TIME . ' minutes');
        $db->execute(
            "UPDATE usuarios SET bloqueado_hasta = ? WHERE email = ?",
            [$bloqueo->format('Y-m-d H:i:s'), $email]
        );
    }
    
    // Registrar log
    registrarLog(null, 'login_fallido', "Intento fallido para email: $email");
}

// Función para crear sesión de usuario
function crearSesionUsuario($usuario) {
    // Generar token de sesión
    $token = generarToken();
    
    // Guardar sesión en BD
    $db = Database::getInstance();
    $db->execute(
        "INSERT INTO sesiones (usuario_id, token, ip, user_agent) 
         VALUES (?, ?, ?, ?)",
        [
            $usuario['id'],
            $token,
            obtenerIP(),
            obtenerUserAgent()
        ]
    );
    
    // Actualizar último acceso
    $db->execute(
        "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
        [$usuario['id']]
    );
    
    // Resetear intentos fallidos
    $db->execute(
        "UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?",
        [$usuario['id']]
    );
    
    // Guardar en sesión
    $_SESSION['user_id'] = $usuario['id'];
    $_SESSION['user_email'] = $usuario['email'];
    $_SESSION['user_nombre'] = $usuario['nombre'] . ' ' . $usuario['apellido'];
    $_SESSION['user_rol'] = $usuario['rol'];
    $_SESSION['session_token'] = $token;
    $_SESSION['last_activity'] = time();
    $_SESSION['ip'] = obtenerIP();
    $_SESSION['user_agent'] = obtenerUserAgent();
    
    // Registrar log de login
    registrarLog($usuario['id'], 'login', 'Inicio de sesión');
}
?>
