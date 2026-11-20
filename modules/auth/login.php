<?php
// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
	redirigirPorRol();
    exit;
}


require_once __DIR__ . '/../../includes/config.php';

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
    <meta name="theme-color" content="#0b4f86">
    <title>Iniciar sesión · Ganadería El Rosario</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favicon.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css?v=<?= @filemtime(BASE_PATH . '/assets/css/login.css') ?>">
</head>
<body>
    <main class="login">
        <section class="login-marca" aria-hidden="true">
            <img src="<?= BASE_URL ?>/assets/img/logo.png?v=<?= @filemtime(BASE_PATH . '/assets/img/logo.png') ?>" alt="">
            <p>Control de ganado, alimento, salud y ventas en un solo lugar.</p>
        </section>

        <section class="login-panel">
            <div class="login-caja">
                <img class="login-logo-movil" src="<?= BASE_URL ?>/assets/img/logo-horizontal.png" alt="Ganadería El Rosario">
                <h1>Bienvenido</h1>
                <p class="login-sub">Inicia sesión para continuar</p>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="aviso aviso-info">Tu sesión expiró. Vuelve a iniciar sesión.</div>
                <?php endif; ?>
                <?php if (isset($_GET['registered'])): ?>
                    <div class="aviso aviso-ok">Registro exitoso. Ya puedes iniciar sesión.</div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="aviso aviso-error" role="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <label class="campo" for="email">
                        <span>Correo</span>
                        <div class="entrada">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>"
                                   placeholder="usuario@ganaderia.com" autocomplete="username" required <?= $email ? '' : 'autofocus' ?>>
                        </div>
                    </label>

                    <label class="campo" for="password">
                        <span>Contraseña</span>
                        <div class="entrada">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                            <input type="password" id="password" name="password" placeholder="Tu contraseña" autocomplete="current-password" required <?= $email ? 'autofocus' : '' ?>>
                            <button type="button" class="ver-clave" id="togglePassword" aria-label="Mostrar contraseña" title="Mostrar contraseña">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <small class="mayus" id="avisoMayus" hidden>Bloq Mayús está activado</small>
                    </label>

                    <label class="recordar"><input type="checkbox" name="remember"> Mantener la sesión iniciada</label>

                    <button type="submit" class="btn-entrar" id="btnLogin">Iniciar sesión</button>
                </form>
            </div>
            <footer class="login-pie">© <?= date('Y') ?> Ganadería El Rosario</footer>
        </section>
    </main>

    <script>
    (function () {
        var f = document.getElementById('loginForm'), clave = document.getElementById('password'),
            email = document.getElementById('email'), btn = document.getElementById('btnLogin'), mayus = document.getElementById('avisoMayus');
        document.getElementById('togglePassword').addEventListener('click', function () {
            var ver = clave.type === 'password';
            clave.type = ver ? 'text' : 'password';
            this.classList.toggle('on', ver);
            this.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
            clave.focus();
        });
        clave.addEventListener('keyup', function (e) { mayus.hidden = !(e.getModifierState && e.getModifierState('CapsLock')); });
        f.addEventListener('submit', function (e) {
            var mal = null;
            [email, clave].forEach(function (i) { i.classList.remove('error'); if (!i.value.trim() || !i.checkValidity()) { i.classList.add('error'); mal = mal || i; } });
            if (mal) { e.preventDefault(); mal.focus(); return; }
            btn.disabled = true; btn.classList.add('cargando'); btn.textContent = 'Entrando...';
        });
    })();
    </script>
</body>
</html>
