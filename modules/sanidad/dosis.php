<?php
// ============================================
// MODULO VETERINARIO - Aplicar dosis programada / cancelar las que faltan
// El medicamento ya se desconto al recetar (estaba reservado); aqui solo se marca
// la aplicacion, o se devuelve al stock lo no aplicado.
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/tratamientos_lib.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('sanidad', 'crear');

$volver = BASE_URL . '/sanidad#dosis';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Solicitud no valida');
    exigirCSRF();

    if (($_POST['accion'] ?? '') === 'aplicar') {
        $d = aplicarDosis((int)($_POST['dosis_id'] ?? 0), $_SESSION['user_id']);
        $msg = "Dosis {$d['numero']} aplicada";
    } elseif (($_POST['accion'] ?? '') === 'cancelar') {
        [$n, $cant] = cancelarDosisPendientes((int)($_POST['tratamiento_id'] ?? 0), $_SESSION['user_id'], trim($_POST['motivo'] ?? ''));
        $msg = "$n dosis canceladas; " . number_format($cant, 2) . " regresaron al inventario";
    } else {
        throw new Exception('Accion no valida');
    }
    header('Location: ' . BASE_URL . '/sanidad?msg=' . urlencode($msg) . '#dosis');
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/sanidad?error=' . urlencode($e->getMessage()) . '#dosis');
}
exit;
