<?php
// ============================================
// RECEPCION - Ya no se capturan recepciones sueltas:
// toda recepcion nace de una compra registrada por el administrador.
// ============================================

require_once __DIR__ . '/../../includes/config.php';

header('Location: ' . BASE_URL . '/recepcion/compras');
exit;
