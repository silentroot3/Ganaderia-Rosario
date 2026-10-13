<?php
// ============================================
// MÓDULO: Costos
// ============================================

$titulo = 'Costos';
$modulo = 'costos';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="table-container">
    <div class="table-header">
        <h2>💰 Costos</h2>
    </div>
    
    <div style="padding: 60px 40px; text-align: center;">
        <div style="font-size: 4rem; margin-bottom: 20px;">🚧</div>
        <h3 style="color: #666; margin-bottom: 15px;">Módulo en construcción</h3>
        <p style="color: #999; max-width: 500px; margin: 0 auto;">
            Este módulo estará disponible próximamente. 
            Se está trabajando en su desarrollo según el calendario de entregas.
        </p>
        <p style="margin-top: 20px; color: #bbb; font-size: 0.9rem;">
            Módulo: <strong>costos</strong> | 
            Rol con acceso: <strong>admin</strong>
        </p>
        <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary" style="margin-top: 25px;">
            ← Volver al Dashboard
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
