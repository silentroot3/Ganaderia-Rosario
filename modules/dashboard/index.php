<?php
// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #2c6b3f;">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard">
                <i class="fas fa-cow me-2"></i> <?= APP_NAME ?>
            </a>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-user-circle me-1"></i>
                    <?= htmlspecialchars($_SESSION['user_nombre']) ?>
                    <span class="badge bg-light text-dark ms-2"><?= $_SESSION['user_rol'] ?></span>
                </span>
                <a href="<?= BASE_URL ?>/logout" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </nav>

    <!-- Contenido -->
    <div class="container mt-4">
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <strong>¡Login exitoso!</strong> Bienvenido al sistema, <?= htmlspecialchars($_SESSION['user_nombre']) ?>.
        </div>

        <h1 class="mb-4">Dashboard</h1>

        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-cow"></i> Animales</h5>
                        <h2>0</h2>
                        <small>Total registrados</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-syringe"></i> Vacunas</h5>
                        <h2>0</h2>
                        <small>Aplicadas este mes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-boxes"></i> Inventario</h5>
                        <h2>0</h2>
                        <small>Productos</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-glass-whiskey"></i> Producción</h5>
                        <h2>0 L</h2>
                        <small>Agua</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-body">
                <h5>Información de la sesión</h5>
                <table class="table table-sm">
                    <tr><th>ID Usuario:</th><td><?= $_SESSION['user_id'] ?></td></tr>
                    <tr><th>Email:</th><td><?= htmlspecialchars($_SESSION['user_email']) ?></td></tr>
                    <tr><th>Nombre:</th><td><?= htmlspecialchars($_SESSION['user_nombre']) ?></td></tr>
                    <tr><th>Rol:</th><td><?= $_SESSION['user_rol'] ?></td></tr>
                    <tr><th>IP:</th><td><?= $_SESSION['ip'] ?? 'N/A' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
