<?php
// ============================================
// CORRALES - Cambiar estado (Disponible/Ocupado/Mantenimiento)
// ============================================

require_once __DIR__ . '/../../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

if ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'clasificador') {
    header('Location: ' . BASE_URL . '/403');
    exit;
}

$corral_id = (int)($_GET['id'] ?? 0);
if (!$corral_id) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM corrales WHERE id = ?");
$stmt->execute([$corral_id]);
$corral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$corral) {
    header('Location: ' . BASE_URL . '/corrales');
    exit;
}

// Ciclo: Disponible -> Ocupado -> Mantenimiento -> Disponible
$estados = ['Disponible', 'Ocupado', 'Mantenimiento'];
$indiceActual = array_search($corral['estado'], $estados);
$nuevoIndice = ($indiceActual + 1) % count($estados);
$nuevoEstado = $estados[$nuevoIndice];

$stmt = $db->prepare("UPDATE corrales SET estado = ? WHERE id = ?");
$stmt->execute([$nuevoEstado, $corral_id]);

header('Location: ' . BASE_URL . '/corrales?msg=estado_cambiado');
exit;
