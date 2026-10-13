<?php
require_once __DIR__ . '/../../includes/config.php';
$titulo = 'Acceso Denegado';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>403 - Acceso Denegado</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css">
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
        }
        .error-card {
            background: white;
            padding: 3rem;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
        }
        .error-icon { font-size: 5rem; margin-bottom: 1rem; }
        .error-code { font-size: 4rem; font-weight: bold; color: #dc3545; margin: 0; }
        .error-title { font-size: 1.8rem; color: #333; margin: 1rem 0; }
        .error-message { color: #666; margin-bottom: 2rem; }
        .btn-home {
            background: #226633;
            color: white;
            padding: 12px 30px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }
        .btn-home:hover { background: #1a4d26; }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-card">
            <div class="error-icon">🚫</div>
            <h1 class="error-code">403</h1>
            <h2 class="error-title">Acceso Denegado</h2>
            <p class="error-message">
                No tienes permiso para acceder a esta sección.
                <br>
                Si crees que es un error, contacta al administrador.
            </p>
            <a href="<?= BASE_URL ?>/dashboard" class="btn-home">
                🏠 Volver al Dashboard
            </a>
        </div>
    </div>
</body>
</html>
