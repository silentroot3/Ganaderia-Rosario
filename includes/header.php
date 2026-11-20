<?php
/**
 * Header común para todas las páginas autenticadas
 *
 * Variables esperadas:
 * - $titulo: Título de la página
 * - $modulo: Módulo actual (para permisos)
 * - $accion: Acción requerida (por defecto 'ver')
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permisos.php';
require_once __DIR__ . '/iconos.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

if (isset($modulo)) {
    Permisos::protegerPagina($modulo, $accion ?? 'ver');
}

$titulo = $titulo ?? 'Panel';
$totalAlertas = 0;
try {
    $stmt = Database::getInstance()->getConnection()->prepare("SELECT COUNT(*) FROM alertas WHERE leida = 0 AND (rol_destino = ? OR rol_destino IS NULL)");
    $stmt->execute([$_SESSION['rol'] ?? '']);
    $totalAlertas = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Silencioso
}
$cssV = @filemtime(BASE_PATH . '/assets/css/dashboard.css');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b4f86">
    <script>
    // Tema antes de pintar la pagina (sin parpadeo): lo que eligio el usuario o el del sistema
    (function () { try { var t = localStorage.getItem('tema'); if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'oscuro' : 'claro';
        if (t === 'oscuro') document.documentElement.setAttribute('data-tema', 'oscuro'); } catch (e) {} })();
    </script>
    <title><?= htmlspecialchars($titulo) ?> · Ganadería El Rosario</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css?v=<?= $cssV ?>">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/apple-touch-icon.png">
</head>
<body>
    <div class="app-container">
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="btn-toggle-sidebar" type="button" onclick="toggleSidebar()" aria-label="Menú"><?= icono('menu', 20) ?></button>
                    <h1 class="page-title"><?= htmlspecialchars($titulo) ?></h1>
                </div>
                <div class="top-bar-right">
                    <span class="fecha-hoy"><?= ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'][(int)date('w')] ?> <?= date('j') ?> de <?= ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][(int)date('n') - 1] ?></span>
                    <button type="button" class="btn-tema" id="btnTema" title="Cambiar a modo oscuro / claro" aria-label="Cambiar tema">
                        <span class="ico-luna"><?= icono('luna', 19) ?></span><span class="ico-sol"><?= icono('sol', 19) ?></span>
                    </button>
                    <a href="<?= BASE_URL ?>/alertas" class="btn-notif" title="Alertas<?= $totalAlertas ? " ($totalAlertas sin leer)" : '' ?>">
                        <?= icono('alerta', 20) ?>
                        <?php if ($totalAlertas > 0): ?><span class="badge"><?= $totalAlertas > 99 ? '99+' : $totalAlertas ?></span><?php endif; ?>
                    </a>
                </div>
            </header>

            <div class="content">
                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
                <?php endif; ?>
