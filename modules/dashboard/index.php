<?php
// ============================================
// DASHBOARD PRINCIPAL
// Cada rol ve solo lo que le corresponde
// ============================================

$titulo = 'Dashboard';
$modulo = 'dashboard';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();
$rol = $_SESSION['rol'] ?? 'invitado';

// ============================================
// ESTADÍSTICAS SEGÚN ROL
// ============================================

$stats = [];

// Todos los roles ven: total de bovinos activos
try {
    $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido', 'muerto')");
    $stats['bovinos_activos'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $stats['bovinos_activos'] = 0;
}

// Admin y recepcionista: recepciones del mes
if (Permisos::puedeVer('recepcion')) {
    try {
        $stmt = $db->query("
            SELECT COUNT(*) FROM recepciones 
            WHERE MONTH(fecha_recepcion) = MONTH(CURRENT_DATE())
            AND YEAR(fecha_recepcion) = YEAR(CURRENT_DATE())
        ");
        $stats['recepciones_mes'] = $stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['recepciones_mes'] = 0;
    }
}

// Admin y veterinario: tratamientos activos
if (Permisos::puedeVer('tratamientos')) {
    try {
        $stmt = $db->query("
            SELECT COUNT(*) FROM tratamientos 
            WHERE fecha_fin_retiro >= CURRENT_DATE()
        ");
        $stats['tratamientos_activos'] = $stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['tratamientos_activos'] = 0;
    }
}

// Admin, clasificador: bovinos en corrales
if (Permisos::puedeVer('corrales')) {
    try {
        $stmt = $db->query("SELECT SUM(ocupacion_actual) FROM corrales");
        $stats['en_corrales'] = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        $stats['en_corrales'] = 0;
    }
}

// Admin, alimentador: stock de alimento
if (Permisos::puedeVer('alimentacion')) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM inventario_alimento WHERE stock_actual <= stock_minimo");
        $stats['stock_bajo'] = $stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['stock_bajo'] = 0;
    }
}
?>

<h1 style="margin-bottom: 20px;">Bienvenido, <?= htmlspecialchars($_SESSION['nombre']) ?></h1>
<p style="color: #666; margin-bottom: 30px;">
    Rol: <strong><?= htmlspecialchars($_SESSION['rol']) ?></strong> | 
    Último acceso: <?= date('d/m/Y H:i') ?>
</p>

<!-- Tarjetas de estadísticas -->
<div class="stats-grid">
    <div class="stat-card blue">
        <div class="stat-label">🐄 Bovinos Activos</div>
        <div class="stat-value"><?= $stats['bovinos_activos'] ?></div>
        <div class="stat-desc">En el sistema</div>
    </div>
    
    <?php if (isset($stats['recepciones_mes'])): ?>
    <div class="stat-card green">
        <div class="stat-label">🚚 Recepciones del Mes</div>
        <div class="stat-value"><?= $stats['recepciones_mes'] ?></div>
        <div class="stat-desc">Últimos 30 días</div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($stats['tratamientos_activos'])): ?>
    <div class="stat-card yellow">
        <div class="stat-label">💊 Tratamientos Activos</div>
        <div class="stat-value"><?= $stats['tratamientos_activos'] ?></div>
        <div class="stat-desc">En período de retiro</div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($stats['en_corrales'])): ?>
    <div class="stat-card purple">
        <div class="stat-label">🏠 Bovinos en Corrales</div>
        <div class="stat-value"><?= $stats['en_corrales'] ?></div>
        <div class="stat-desc">Actualmente</div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($stats['stock_bajo'])): ?>
    <div class="stat-card" style="border-left-color: #dc3545;">
        <div class="stat-label">⚠️ Stock Bajo</div>
        <div class="stat-value"><?= $stats['stock_bajo'] ?></div>
        <div class="stat-desc">Productos a reabastecer</div>
    </div>
    <?php endif; ?>
</div>

<!-- Info de la sesión -->
<div class="table-container" style="margin-top: 30px;">
    <div class="table-header">
        <h2>ℹ️ Información de la sesión</h2>
    </div>
    <table>
        <tbody>
            <tr>
                <td><strong>ID Usuario</strong></td>
                <td><?= htmlspecialchars($_SESSION['user_id']) ?></td>
            </tr>
            <tr>
                <td><strong>Email</strong></td>
                <td><?= htmlspecialchars($_SESSION['email']) ?></td>
            </tr>
            <tr>
                <td><strong>Nombre</strong></td>
                <td><?= htmlspecialchars($_SESSION['nombre'] . ' ' . ($_SESSION['apellido'] ?? '')) ?></td>
            </tr>
            <tr>
                <td><strong>Rol</strong></td>
                <td>
                    <span class="badge-rol badge-<?= $_SESSION['rol'] ?>">
                        <?= htmlspecialchars($_SESSION['rol']) ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>IP</strong></td>
                <td><?= htmlspecialchars($_SERVER['REMOTE_ADDR']) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
