<?php
// ============================================
// DASHBOARD DINAMICO POR ROL
// Cada usuario ve solo lo que le corresponde
// ============================================

$titulo = 'Dashboard';
$modulo = 'dashboard';

require_once __DIR__ . '/../../includes/header.php';

require_once __DIR__ . '/../../includes/alertas_lib.php';
generarAlertasAutomaticas();   // HU-017: avisos de comida baja, enfermos sin medicina, etc.

$db = Database::getInstance()->getConnection();
// Aviso anticipado de alimento por acabarse (una vez al dia), para admin y alimentador
if (in_array($_SESSION['rol'] ?? '', ['admin', 'alimentador'], true)) {
    try { require_once __DIR__ . '/../../includes/dietas_lib.php'; revisarAlertasAlimento($db); } catch (Exception $e) { error_log('pronostico alimento: ' . $e->getMessage()); }
}
$rol = $_SESSION['rol'] ?? 'invitado';
$userId = $_SESSION['user_id'];

$stats = [];
$alertas = [];
$accionesRapidas = [];

// ============================================
// ESTADISTICAS POR ROL
// ============================================

// --- ADMIN: ve todo ---
if ($rol === 'admin') {
    try {
        // Compras pendientes
        $stmt = $db->query("SELECT COUNT(*) FROM compras WHERE estado = 'pendiente'");
        $stats['compras_pendientes'] = $stmt->fetchColumn();
        
        // Bovinos activos
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')");
        $stats['bovinos_activos'] = $stmt->fetchColumn();
        
        // Bovinos en hospital
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE en_hospital = 1");
        $stats['en_hospital'] = $stmt->fetchColumn();
        
        // Recepciones del mes
        $stmt = $db->query("
            SELECT COUNT(*) FROM recepciones 
            WHERE MONTH(fecha_recepcion) = MONTH(CURRENT_DATE())
              AND YEAR(fecha_recepcion) = YEAR(CURRENT_DATE())
        ");
        $stats['recepciones_mes'] = $stmt->fetchColumn();
        
        // Ocupación de corrales
        $stmt = $db->query("SELECT SUM(ocupacion_actual) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
        $stats['en_corrales'] = $stmt->fetchColumn() ?: 0;
        
        // Tratamientos activos
        $stmt = $db->query("
            SELECT COUNT(DISTINCT bovino_id) FROM tratamientos 
            WHERE fecha_fin_retiro IS NULL OR fecha_fin_retiro >= CURRENT_DATE()
        ");
        $stats['tratamientos_activos'] = $stmt->fetchColumn();
        
        // Alertas sin leer
        $stmt = $db->query("SELECT COUNT(*) FROM alertas WHERE leida = 0");
        $stats['alertas_nuevas'] = $stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error dashboard admin: " . $e->getMessage());
    }
}

// --- RECEPCIONISTA ---
if ($rol === 'recepcionista') {
    try {
        // Compras pendientes (LO MAS IMPORTANTE)
        $stmt = $db->query("SELECT COUNT(*) FROM compras WHERE estado IN ('pendiente','en_transito')");
        $stats['compras_pendientes'] = $stmt->fetchColumn();
        
        // Bovinos por recibir
        $stmt = $db->query("
            SELECT COUNT(*) FROM compra_bovinos cb
            INNER JOIN compras c ON cb.compra_id = c.id
            WHERE c.estado IN ('pendiente','en_transito') AND cb.recibido = 0
        ");
        $stats['bovinos_por_recibir'] = $stmt->fetchColumn();
        
        // Recepciones del mes
        $stmt = $db->query("
            SELECT COUNT(*) FROM recepciones 
            WHERE usuario_id = ?
              AND MONTH(fecha_recepcion) = MONTH(CURRENT_DATE())
              AND YEAR(fecha_recepcion) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute([$userId]);
        $stats['recepciones_mes'] = $stmt->fetchColumn();
        
        // Bovinos activos
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')");
        $stats['bovinos_activos'] = $stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error dashboard recepcionista: " . $e->getMessage());
    }
}

// --- VETERINARIO ---
if ($rol === 'veterinario') {
    try {
        // Bovinos en hospital (LO MAS IMPORTANTE)
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE en_hospital = 1");
        $stats['en_hospital'] = $stmt->fetchColumn();
        
        // Tratamientos activos
        $stmt = $db->query("
            SELECT COUNT(DISTINCT bovino_id) FROM tratamientos 
            WHERE fecha_fin_retiro IS NULL OR fecha_fin_retiro >= CURRENT_DATE()
        ");
        $stats['tratamientos_activos'] = $stmt->fetchColumn();
        
        // Tratamientos esta semana
        $stmt = $db->query("
            SELECT COUNT(*) FROM tratamientos 
            WHERE YEARWEEK(fecha_aplicacion) = YEARWEEK(CURRENT_DATE())
        ");
        $stats['tratamientos_semana'] = $stmt->fetchColumn();
        
        // Bovinos activos
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')");
        $stats['bovinos_activos'] = $stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error dashboard veterinario: " . $e->getMessage());
    }
}

// --- CLASIFICADOR ---
if ($rol === 'clasificador') {
    try {
        // Bovinos sin clasificar (LO MAS IMPORTANTE)
        $stmt = $db->query("
            SELECT COUNT(*) FROM bovinos 
            WHERE estado IN ('recibido','revision') AND corral_id IS NULL
        ");
        $stats['sin_clasificar'] = $stmt->fetchColumn();
        
        // Ocupación de corrales
        $stmt = $db->query("SELECT SUM(ocupacion_actual) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
        $stats['en_corrales'] = $stmt->fetchColumn() ?: 0;
        
        // Bovinos activos
        $stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')");
        $stats['bovinos_activos'] = $stmt->fetchColumn();
        
        // Corrales disponibles
        $stmt = $db->query("
            SELECT COUNT(*) FROM corrales 
            WHERE es_hospital = 0 AND es_venta = 0 
              AND ocupacion_actual < capacidad_maxima
        ");
        $stats['corrales_disponibles'] = $stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error dashboard clasificador: " . $e->getMessage());
    }
}

// --- ALIMENTADOR ---
if ($rol === 'alimentador') {
    try {
        // Stock bajo (LO MAS IMPORTANTE)
        $stmt = $db->query("
            SELECT COUNT(*) FROM inventario_alimento 
            WHERE stock_actual <= stock_minimo
        ");
        $stats['stock_bajo'] = $stmt->fetchColumn();
        
        // Alimentaciones hoy
        $stmt = $db->query("
            SELECT COUNT(*) FROM alimentaciones 
            WHERE fecha = CURRENT_DATE()
        ");
        $stats['alimentaciones_hoy'] = $stmt->fetchColumn();
        
        // Corrales ocupados
        $stmt = $db->query("
            SELECT COUNT(*) FROM corrales 
            WHERE es_hospital = 0 AND es_venta = 0 AND ocupacion_actual > 0
        ");
        $stats['corrales_ocupados'] = $stmt->fetchColumn();
        
        // Bovinos en corrales
        $stmt = $db->query("SELECT SUM(ocupacion_actual) FROM corrales WHERE es_hospital = 0 AND es_venta = 0");
        $stats['en_corrales'] = $stmt->fetchColumn() ?: 0;
        
    } catch (Exception $e) {
        error_log("Error dashboard alimentador: " . $e->getMessage());
    }
}

// ============================================
// ALERTAS DEL USUARIO
// ============================================

try {
    $stmt = $db->prepare("
        SELECT * FROM alertas 
        WHERE leida = 0 
          AND (rol_destino = ? OR rol_destino IS NULL OR rol_destino = '')
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$rol]);
    $alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error alertas: " . $e->getMessage());
}

?>

<!-- Bienvenida -->
<h1 style="margin-bottom: 10px;">Bienvenido, <?= htmlspecialchars($_SESSION['nombre']) ?></h1>
<p style="color: var(--tenue); margin-bottom: 30px;">
    <strong>Rol:</strong> <?= htmlspecialchars(ucfirst($rol)) ?> |
    <strong>Ultimo acceso:</strong> <?= date('d/m/Y H:i') ?>
</p>

<!-- ============================================ -->
<!-- ALERTAS DESTACADAS (para todos los roles) -->
<!-- ============================================ -->
<?php if (count($alertas) > 0): ?>
<div class="alert alert-info" style="margin-bottom: 25px; border-left: 4px solid #17a2b8;">
    <h4 style="margin-bottom: 10px;">Tienes <?= count($alertas) ?> alerta(s) pendiente(s)</h4>
    <ul style="margin: 0; padding-left: 20px;">
        <?php foreach ($alertas as $al): ?>
            <li style="margin-bottom: 5px;">
                <strong><?= htmlspecialchars($al['tipo']) ?>:</strong>
                <?= htmlspecialchars($al['mensaje']) ?>
                <small style="color: var(--tenue);">
                    (<?= date('d/m/Y H:i', strtotime($al['created_at'])) ?>)
                </small>
            </li>
        <?php endforeach; ?>
    </ul>
    <a href="<?= BASE_URL ?>/alertas" style="color: var(--t-azul); text-decoration: underline; font-weight: 600; margin-top: 10px; display: inline-block;">
        Ver todas las alertas
    </a>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- TARJETAS DINAMICAS POR ROL -->
<!-- ============================================ -->
<div class="stats-grid" style="margin-bottom: 30px;">

    <?php if ($rol === 'admin'): ?>
    
        <div class="stat-card yellow">
            <div class="stat-label">Compras pendientes</div>
            <div class="stat-value"><?= $stats['compras_pendientes'] ?? 0 ?></div>
            <div class="stat-desc">Esperando recepcion</div>
        </div>
        
        <div class="stat-card blue">
            <div class="stat-label">Bovinos activos</div>
            <div class="stat-value"><?= $stats['bovinos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En el sistema</div>
        </div>
        
        <div class="stat-card" style="border-left-color: #dc3545;">
            <div class="stat-label">En hospital</div>
            <div class="stat-value"><?= $stats['en_hospital'] ?? 0 ?></div>
            <div class="stat-desc">Bajo atencion</div>
        </div>
        
        <div class="stat-card green">
            <div class="stat-label">En corrales</div>
            <div class="stat-value"><?= $stats['en_corrales'] ?? 0 ?></div>
            <div class="stat-desc">Actualmente</div>
        </div>
        
        <div class="stat-card purple">
            <div class="stat-label">Recepciones del mes</div>
            <div class="stat-value"><?= $stats['recepciones_mes'] ?? 0 ?></div>
            <div class="stat-desc">Este mes</div>
        </div>
        
        <div class="stat-card yellow">
            <div class="stat-label">Tratamientos activos</div>
            <div class="stat-value"><?= $stats['tratamientos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En periodo de retiro</div>
        </div>
        
    <?php elseif ($rol === 'recepcionista'): ?>
    
        <div class="stat-card yellow" style="border-left-width: 6px;">
            <div class="stat-label">Compras por recibir</div>
            <div class="stat-value"><?= $stats['compras_pendientes'] ?? 0 ?></div>
            <div class="stat-desc">
                <?php if (($stats['compras_pendientes'] ?? 0) > 0): ?>
                    <a href="<?= BASE_URL ?>/recepcion/compras" style="color: var(--t-amarillo); font-weight: 600;">
                        Ver compras pendientes
                    </a>
                <?php else: ?>
                    Sin pendientes
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card blue">
            <div class="stat-label">Bovinos por recibir</div>
            <div class="stat-value"><?= $stats['bovinos_por_recibir'] ?? 0 ?></div>
            <div class="stat-desc">En compras pendientes</div>
        </div>
        
        <div class="stat-card green">
            <div class="stat-label">Recepciones del mes</div>
            <div class="stat-value"><?= $stats['recepciones_mes'] ?? 0 ?></div>
            <div class="stat-desc">Registradas por ti</div>
        </div>
        
        <div class="stat-card purple">
            <div class="stat-label">Bovinos activos</div>
            <div class="stat-value"><?= $stats['bovinos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En el sistema</div>
        </div>
        
    <?php elseif ($rol === 'veterinario'): ?>
    
        <div class="stat-card" style="border-left-color: #dc3545; border-left-width: 6px;">
            <div class="stat-label">Bovinos en hospital</div>
            <div class="stat-value"><?= $stats['en_hospital'] ?? 0 ?></div>
            <div class="stat-desc">
                <?php if (($stats['en_hospital'] ?? 0) > 0): ?>
                    <a href="<?= BASE_URL ?>/sanidad" style="color: var(--t-rojo); font-weight: 600;">
                        Atender bovinos
                    </a>
                <?php else: ?>
                    Sin pacientes
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card yellow">
            <div class="stat-label">Tratamientos activos</div>
            <div class="stat-value"><?= $stats['tratamientos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En periodo de retiro</div>
        </div>
        
        <div class="stat-card blue">
            <div class="stat-label">Tratamientos esta semana</div>
            <div class="stat-value"><?= $stats['tratamientos_semana'] ?? 0 ?></div>
            <div class="stat-desc">Aplicados</div>
        </div>
        
        <div class="stat-card green">
            <div class="stat-label">Bovinos activos</div>
            <div class="stat-value"><?= $stats['bovinos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En el sistema</div>
        </div>
        
    <?php elseif ($rol === 'clasificador'): ?>
    
        <div class="stat-card yellow" style="border-left-width: 6px;">
            <div class="stat-label">Bovinos sin clasificar</div>
            <div class="stat-value"><?= $stats['sin_clasificar'] ?? 0 ?></div>
            <div class="stat-desc">
                <?php if (($stats['sin_clasificar'] ?? 0) > 0): ?>
                    <a href="<?= BASE_URL ?>/clasificacion" style="color: var(--t-amarillo); font-weight: 600;">
                        Clasificar ahora
                    </a>
                <?php else: ?>
                    Todo clasificado
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card green">
            <div class="stat-label">En corrales</div>
            <div class="stat-value"><?= $stats['en_corrales'] ?? 0 ?></div>
            <div class="stat-desc">Bovinos asignados</div>
        </div>
        
        <div class="stat-card blue">
            <div class="stat-label">Corrales disponibles</div>
            <div class="stat-value"><?= $stats['corrales_disponibles'] ?? 0 ?></div>
            <div class="stat-desc">Con espacio</div>
        </div>
        
        <div class="stat-card purple">
            <div class="stat-label">Bovinos activos</div>
            <div class="stat-value"><?= $stats['bovinos_activos'] ?? 0 ?></div>
            <div class="stat-desc">En el sistema</div>
        </div>
        
    <?php elseif ($rol === 'vendedor'):
        $listos = (int)$db->query("SELECT COUNT(*) FROM bovinos b WHERE b.estado = 'en_corral' AND b.en_hospital = 0
            AND NOT EXISTS (SELECT 1 FROM tratamientos t WHERE t.bovino_id = b.id AND t.fecha_fin_retiro >= CURDATE())")->fetchColumn();
        $enVenta = (int)$db->query("SELECT COUNT(*) FROM bovinos b JOIN corrales c ON c.id = b.corral_id WHERE c.es_venta = 1 AND b.estado = 'en_corral'")->fetchColumn();
        $descAut = (int)$db->query("SELECT COUNT(*) FROM solicitudes_descuento WHERE estado = 'aprobada' AND usuario_id = " . (int)$_SESSION['user_id'])->fetchColumn();
        $descPend = (int)$db->query("SELECT COUNT(*) FROM solicitudes_descuento WHERE estado = 'pendiente' AND usuario_id = " . (int)$_SESSION['user_id'])->fetchColumn(); ?>

        <div class="stat-card green" style="border-left-width: 6px;">
            <div class="stat-label">En corrales de venta</div>
            <div class="stat-value"><?= $enVenta ?></div>
            <div class="stat-desc"><a href="<?= BASE_URL ?>/ventas/precios" style="color:var(--t-verde);font-weight:600;">Ver precios por corral</a></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Listos para venta</div>
            <div class="stat-value"><?= $listos ?></div>
            <div class="stat-desc"><a href="<?= BASE_URL ?>/ventas/crear" style="font-weight:600;">Nueva venta</a></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-label">Descuentos</div>
            <div class="stat-value"><?= $descAut ?></div>
            <div class="stat-desc">autorizados · <?= $descPend ?> esperando respuesta · <a href="<?= BASE_URL ?>/ventas/descuentos">ver</a></div>
        </div>

    <?php elseif ($rol === 'alimentador'): ?>
    
        <div class="stat-card" style="border-left-color: #dc3545; border-left-width: 6px;">
            <div class="stat-label">Stock bajo</div>
            <div class="stat-value"><?= $stats['stock_bajo'] ?? 0 ?></div>
            <div class="stat-desc">
                <?php if (($stats['stock_bajo'] ?? 0) > 0): ?>
                    <a href="<?= BASE_URL ?>/alimentacion" style="color: var(--t-rojo); font-weight: 600;">
                        Revisar inventario
                    </a>
                <?php else: ?>
                    Todo en orden
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card green">
            <div class="stat-label">Alimentaciones hoy</div>
            <div class="stat-value"><?= $stats['alimentaciones_hoy'] ?? 0 ?></div>
            <div class="stat-desc">Registradas</div>
        </div>
        
        <div class="stat-card blue">
            <div class="stat-label">Corrales ocupados</div>
            <div class="stat-value"><?= $stats['corrales_ocupados'] ?? 0 ?></div>
            <div class="stat-desc">Con bovinos</div>
        </div>
        
        <div class="stat-card purple">
            <div class="stat-label">Bovinos en corrales</div>
            <div class="stat-value"><?= $stats['en_corrales'] ?? 0 ?></div>
            <div class="stat-desc">Para alimentar</div>
        </div>
        
    <?php endif; ?>

</div>

<!-- ============================================ -->
<!-- ACCIONES RAPIDAS POR ROL -->
<!-- ============================================ -->
<div class="table-container" style="margin-bottom: 25px;">
    <div class="table-header">
        <h2>Acciones Rapidas</h2>
    </div>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        
        <?php if ($rol === 'admin'): ?>
            <a href="<?= BASE_URL ?>/configuracion/compras/crear" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('camion', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Nueva Compra</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Registrar compra de ganado</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/usuarios" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('usuarios', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Gestionar Usuarios</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Crear y editar empleados</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/reportes" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('reporte', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Ver Reportes</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Estadisticas del rancho</div>
                </div>
            </a>
            
        <?php elseif ($rol === 'recepcionista'): ?>
            <a href="<?= BASE_URL ?>/recepcion/compras" 
               style="display: flex; align-items: center; padding: 20px; background: var(--f-amarillo); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('camion', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Recibir Compra</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Validar bovinos que llegan</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/recepcion" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('camion', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Ver Recepciones</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Historial de recepciones</div>
                </div>
            </a>
            
        <?php elseif ($rol === 'veterinario'): ?>
            <a href="<?= BASE_URL ?>/sanidad" 
               style="display: flex; align-items: center; padding: 20px; background: var(--f-rojo); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('salud', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Atender Bovinos</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Bovinos en hospital</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/tratamientos" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('medicina', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Tratamientos</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Ver todos los tratamientos</div>
                </div>
            </a>
            
        <?php elseif ($rol === 'clasificador'): ?>
            <a href="<?= BASE_URL ?>/clasificacion" 
               style="display: flex; align-items: center; padding: 20px; background: var(--f-amarillo); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('bascula', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Clasificar Bovinos</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Asignar corrales</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/seguimiento" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('grafica', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Registrar Pesajes</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Pesaje y GDP</div>
                </div>
            </a>
            
        <?php elseif ($rol === 'alimentador'): ?>
            <a href="<?= BASE_URL ?>/alimentacion" 
               style="display: flex; align-items: center; padding: 20px; background: var(--f-rojo); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('alimento', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Registrar Comida</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Servir alimento a corrales</div>
                </div>
            </a>
            
            <a href="<?= BASE_URL ?>/corrales" 
               style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
                <div class="accion-ico"><?= icono('corral', 22) ?></div>
                <div>
                    <div style="font-weight: 600;">Ver Corrales</div>
                    <div style="font-size: 0.85rem; color: var(--tenue);">Estado de corrales</div>
                </div>
            </a>
            
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
