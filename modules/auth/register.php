<?php
// Si ya está logueado, redirigir
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard');
    exit;
}

$csrf_token = generarCSRF();
$error = '';
$success = '';
$datos = [
    'nombre' => '',
    'apellido' => '',
    'email' => '',
    'telefono' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar CSRF
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $datos['nombre'] = sanitizar($_POST['nombre'] ?? '');
        $datos['apellido'] = sanitizar($_POST['apellido'] ?? '');
        $datos['email'] = sanitizar($_POST['email'] ?? '');
        $datos['telefono'] = sanitizar($_POST['telefono'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        
        // Validaciones
        if (empty($datos['nombre']) || empty($datos['apellido']) || empty($datos['email']) || empty($password)) {
            $error = 'Por favor, complete todos los campos obligatorios.';
        } elseif (!validarEmail($datos['email'])) {
            $error = 'Por favor, ingrese un email válido.';
        } elseif (!validarPassword($password)) {
            $error = 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula y un número.';
        } elseif ($password !== $password_confirm) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            // Verificar si el email ya existe
            $db = Database::getInstance();
            $existe = $db->fetchOne(
                "SELECT id FROM usuarios WHERE email = ?",
                [$datos['email']]
            );
            
            if ($existe) {
                $error = 'El email ya está registrado. Por favor, use otro.';
            } else {
                // Insertar usuario
                $password_hash = encriptarPassword($password);
                
                try {
                    $db->execute(
                        "INSERT INTO usuarios (nombre, apellido, email, password, telefono, ip_registro) 
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $datos['nombre'],
                            $datos['apellido'],
                            $datos['email'],
                            $password_hash,
                            $datos['telefono'],
                            obtenerIP()
                        ]
                    );
                    
                    $success = 'Registro exitoso. Ya puede iniciar sesión.';
                    
                    // Limpiar datos
                    $datos = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];
                    
                    // Redirigir al login después de 2 segundos
                    header('Refresh: 2; URL=' . BASE_URL . '/login?registered=1');
                    
                } catch(Exception $e) {
                    $error = 'Error al registrar usuario. Por favor, intente nuevamente.';
                    error_log("Error en registro: " . $e->getMessage());
                }
            }
        }
    }
}

$page_title = 'Registrarse';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Registrarse</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-7 col-lg-6 col-xl-5">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h4 class="fw-bold">Crear Cuenta</h4>
                            <p class="text-muted small">Regístrese para acceder al sistema</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i> <?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre" class="form-label fw-semibold">Nombre</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="nombre" 
                                           name="nombre" 
                                           value="<?= htmlspecialchars($datos['nombre']) ?>"
                                           placeholder="Nombre" 
                                           required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="apellido" class="form-label fw-semibold">Apellido</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="apellido" 
                                           name="apellido" 
                                           value="<?= htmlspecialchars($datos['apellido']) ?>"
                                           placeholder="Apellido" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label fw-semibold">Email</label>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       value="<?= htmlspecialchars($datos['email']) ?>"
                                       placeholder="usuario@ejemplo.com" 
                                       required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="telefono" class="form-label fw-semibold">Teléfono</label>
                                <input type="tel" 
                                       class="form-control" 
                                       id="telefono" 
                                       name="telefono" 
                                       value="<?= htmlspecialchars($datos['telefono']) ?>"
                                       placeholder="555-123-4567">
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label fw-semibold">Contraseña</label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           placeholder="Mínimo 8 caracteres" 
                                           required>
                                    <button class="btn btn-outline-secondary" 
                                            type="button" 
                                            id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text small">
                                    <i class="fas fa-info-circle"></i> 
                                    Mínimo 8 caracteres, con mayúscula, minúscula y número.
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password_confirm" class="form-label fw-semibold">Confirmar Contraseña</label>
                                <input type="password" 
                                       class="form-control" 
                                       id="password_confirm" 
                                       name="password_confirm" 
                                       placeholder="Confirme su contraseña" 
                                       required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-user-plus me-2"></i> Registrarse
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted small mb-0">
                                ¿Ya tiene cuenta? 
                                <a href="<?= BASE_URL ?>/login" class="text-decoration-none fw-bold">
                                    Iniciar Sesión
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
        });
    </script>
</body>
</html>
