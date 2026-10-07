<?php
// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
	redirigirPorRol();
    exit;
}

// Generar token CSRF
$csrf_token = generarCSRF();

// Variables para mensajes
$error = '';
$email = '';

// Procesar el login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar CSRF
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $email = sanitizar($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        // Validaciones
        if (empty($email) || empty($password)) {
            $error = 'Por favor, complete todos los campos.';
        } elseif (!validarEmail($email)) {
            $error = 'Por favor, ingrese un email válido.';
        } else {
            // Verificar rate limiting
            if (!verificarRateLimiting($email)) {
                $error = 'Demasiados intentos fallidos. Por favor, espere 15 minutos.';
            } else {
                // Buscar usuario
                $db = Database::getInstance();
                $usuario = $db->fetchOne(
                    "SELECT * FROM usuarios WHERE email = ? AND activo = 1",
                    [$email]
                );
                
                if ($usuario && verificarPassword($password, $usuario['password'])) {
                    // Login exitoso
                    crearSesionUsuario($usuario);
                    
                    // Redirigir
                    header('Location: ' . BASE_URL . '/dashboard');
                    exit;
                } else {
                    // Login fallido
                    registrarIntentoFallido($email);
                    $error = 'Email o contraseña incorrectos.';
                }
            }
        }
    }
}

// Variables para la vista
$page_title = 'Iniciar Sesión';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Iniciar Sesión</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5 col-xl-4">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <!-- Logo -->
                       <!-- <div class="text-center mb-4">
                            <img src=" <?= BASE_URL ?>    /assets/img/logo.png" alt="Logo" class="img-fluid" style="max-height: 80px;">
                            <h4 class="mt-3 fw-bold"><?= APP_NAME ?></h4>
                        </div> -->
                        
                        <!-- Mensajes flash -->
                        <?php if (isset($_GET['timeout'])): ?>
                            <div class="alert alert-warning alert-dismissible fade show">
                                <i class="fas fa-clock me-2"></i> Su sesión ha expirado. Por favor, inicie sesión nuevamente.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['registered'])): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i> ¡Registro exitoso! Ya puede iniciar sesión.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Error -->
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Formulario de Login -->
                        <form method="POST" action="" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            
                            <div class="mb-3">
                                <label for="email" class="form-label fw-semibold">
                                    <i class="fas fa-envelope me-1"></i> Email
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-envelope text-muted"></i>
                                    </span>
                                    <input type="email" 
                                           class="form-control form-control-lg" 
                                           id="email" 
                                           name="email" 
                                           value="<?= htmlspecialchars($email) ?>"
                                           placeholder="usuario@ejemplo.com" 
                                           required 
                                           autofocus>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label fw-semibold">
                                    <i class="fas fa-lock me-1"></i> Contraseña
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-lock text-muted"></i>
                                    </span>
                                    <input type="password" 
                                           class="form-control form-control-lg" 
                                           id="password" 
                                           name="password" 
                                           placeholder="Ingrese su contraseña" 
                                           required>
                                    <button class="btn btn-outline-secondary" 
                                            type="button" 
                                           id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    <a href="<?= BASE_URL ?>/recover" class="text-decoration-none small">
                                        
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label" for="remember">
                                    Recordarme
                                </label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg w-100" id="btnLogin">
                                <i class="fas fa-sign-in-alt me-2"></i> Iniciar Sesión
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted small mb-0">
                                
                                <a href="<?= BASE_URL ?>/register" class="text-decoration-none fw-bold">
                                    
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Mostrar/ocultar contraseña
            $('#togglePassword').click(function() {
                const passwordInput = $('#password');
                const icon = $(this).find('i');
                
                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordInput.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Validación del formulario
            $('#loginForm').on('submit', function(e) {
                const email = $('#email').val().trim();
                const password = $('#password').val().trim();
                
                if (!email || !password) {
                    e.preventDefault();
                    alert('Por favor, complete todos los campos.');
                    return false;
                }
                
                // Deshabilitar botón para evitar doble envío
                $('#btnLogin').prop('disabled', true)
                              .html('<span class="spinner-border spinner-border-sm me-2"></span> Iniciando sesión...');
            });
        });
    </script>
</body>
</html>
