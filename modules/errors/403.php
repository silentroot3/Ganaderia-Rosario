<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso Denegado</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container vh-100 d-flex align-items-center justify-content-center">
        <div class="text-center">
            <i class="fas fa-ban text-danger" style="font-size: 5rem;"></i>
            <h1 class="mt-3">403 - Acceso Denegado</h1>
            <p class="text-muted">No tienes permisos para acceder a esta sección.</p>
            <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary mt-3">
                <i class="fas fa-home me-2"></i> Volver al Dashboard
            </a>
        </div>
    </div>
</body>
</html>
