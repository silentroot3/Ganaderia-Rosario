<?php
$csrf_token = generarCSRF();
$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $email = sanitizar($_POST['email'] ?? '');
        
        if (empty($email) || !validarEmail($email)) {
            $error = 'Por favor, ingrese un email válido.';
        } else {
            $db = Database::getInstance();
            $usuario = $db->fetchOne(
                "SELECT id, email, nombre FROM usuarios WHERE email = ? AND activo = 1",
                [$email]
            );
            
            if ($usuario) {
                // Generar token de recuperación
                $token = generarToken();
                $expiracion = new DateTime();
                $expiracion->modify('+1 hour');
                
                $db->execute(
                    "UPDATE usuarios SET token_recuperacion = ?, token_expiracion = ? WHERE id = ?",
                    [$token, $expiracion->format('Y-m-d H:i:s'), $usuario['id']]
                );
                // Por ahora, simulamos el envío
                $success = "Se ha enviado un enlace de recuperación a su correo electrónico.";
                
                // Redirigir al login
                header('Refresh: 3; URL=' . BASE_URL . '/login');
                
            } else {
                // No revelar si el email existe o no por seguridad
                $success = "Si el email existe en nuestro sistema, recibirá un enlace de recuperación.";
                // Redirigir al login
                header('Refresh: 3; URL=' . BASE_URL . '/login');
            }
        }
    }
}

$page_title = 'Recuperar Contraseña';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Recuperar Contraseña</title>
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
                        <div class="text-center mb-4">
                            <h4 class="fw-bold">Recuperar Contraseña</h4>
                            <p class="text-muted small">Ingrese su email para recibir instrucciones</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            
                            <div class="mb-4">
                                <label for="email" class="form-label fw-semibold">Email</label>
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
                                           required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-paper-plane me-2"></i> Enviar Instrucciones
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted small mb-0">
                                <a href="<?= BASE_URL ?>/login" class="text-decoration-none">
                                    <i class="fas fa-arrow-left me-1"></i> Volver al inicio de sesión
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
</body>
</html>
