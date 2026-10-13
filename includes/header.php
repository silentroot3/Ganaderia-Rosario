<?php
/**
 * Header común para todas las páginas autenticadas
 * 
 * Variables esperadas:
 * - $titulo: Título de la página
 * - $modulo: Módulo actual (para permisos)
 * - $accion: Acción requerida (por defecto 'ver')
 */

// Asegurar que config esté cargado
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permisos.php';

// Verificar sesión activa
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// Verificar permiso del módulo (si se especificó)
if (isset($modulo)) {
    Permisos::protegerPagina($modulo, $accion ?? 'ver');
}

$titulo = $titulo ?? 'Panel';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo) ?> - Ganadería El Rosario</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css">
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/assets/img/favicon.ico">
</head>
<body>
    <div class="app-container">
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="btn-toggle-sidebar" onclick="toggleSidebar()">☰</button>
                    <h1 class="page-title"><?= htmlspecialchars($titulo) ?></h1>
                </div>
                
                <div class="top-bar-right">
                    <!-- Notificaciones (alertas no leídas) -->
                    <a href="<?= BASE_URL ?>/alertas" class="btn-notif" title="Alertas">
                        🔔
                        <?php
                        // Contar alertas no leídas (si la tabla existe)
                        try {
                            $db = Database::getInstance()->getConnection();
                            $stmt = $db->prepare("
                                SELECT COUNT(*) FROM alertas 
                                WHERE leida = 0 
                                AND (rol_destino = ? OR rol_destino IS NULL)
                            ");
                            $stmt->execute([$_SESSION['rol'] ?? '']);
                            $totalAlertas = $stmt->fetchColumn();
                            if ($totalAlertas > 0) {
                                echo '<span class="badge">' . $totalAlertas . '</span>';
                            }
                        } catch (Exception $e) {
                            // Silencioso
                        }
                        ?>
                    </a>
                    
                    <!-- Info del usuario -->
                    <div class="user-menu">
                        <span class="user-menu-name">
                            <?= htmlspecialchars($_SESSION['nombre'] ?? 'Usuario') ?>
                        </span>
                        <span class="badge-rol badge-<?= $_SESSION['rol'] ?? '' ?>">
                            <?= htmlspecialchars($_SESSION['rol'] ?? '') ?>
                        </span>
                    </div>
                    
                    <a href="<?= BASE_URL ?>/logout" class="btn-logout-top">Cerrar Sesión</a>
                </div>
            </header>
            
            <!-- Contenido principal -->
            <div class="content">
                
                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($_GET['msg']) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($_GET['error']) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Aquí va el contenido de cada página -->
