<?php
// ============================================
// MODULO CONFIGURACION
// Panel principal de administracion
// ============================================

$titulo = 'Configuracion';
$modulo = 'configuracion';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

$db = Database::getInstance()->getConnection();

// Estadisticas rapidas
$stmt = $db->query("SELECT COUNT(*) FROM compras WHERE estado = 'pendiente'");
$comprasPendientes = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1");
$usuariosActivos = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM corrales");
$totalCorrales = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM bovinos WHERE estado NOT IN ('vendido','muerto','devuelto')");
$bovinosActivos = $stmt->fetchColumn();
?>

<h2 style="margin-bottom: 25px;">Panel de Administracion</h2>

<!-- Accesos rapidos -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
    
    <!-- Compras -->
    <a href="<?= BASE_URL ?>/configuracion/compras" 
       style="text-decoration: none; color: inherit;">
        <div class="stat-card yellow" style="cursor: pointer; transition: transform 0.2s;" data-hover>
            <div style="font-size: 2.5rem; margin-bottom: 10px;">🛒</div>
            <div class="stat-label">Compras de Ganado</div>
            <div class="stat-value"><?= $comprasPendientes ?></div>
            <div class="stat-desc"><?= $comprasPendientes > 0 ? 'Pendientes de recibir' : 'Sin pendientes' ?></div>
        </div>
    </a>
    
    <!-- Usuarios -->
    <a href="<?= BASE_URL ?>/usuarios" 
       style="text-decoration: none; color: inherit;">
        <div class="stat-card blue" style="cursor: pointer; transition: transform 0.2s;" data-hover>
            <div style="font-size: 2.5rem; margin-bottom: 10px;">👥</div>
            <div class="stat-label">Usuarios</div>
            <div class="stat-value"><?= $usuariosActivos ?></div>
            <div class="stat-desc">Usuarios activos</div>
        </div>
    </a>
    
    <!-- Corrales -->
    <a href="<?= BASE_URL ?>/corrales" 
       style="text-decoration: none; color: inherit;">
        <div class="stat-card green" style="cursor: pointer; transition: transform 0.2s;" data-hover>
            <div style="font-size: 2.5rem; margin-bottom: 10px;">🏠</div>
            <div class="stat-label">Corrales</div>
            <div class="stat-value"><?= $totalCorrales ?></div>
            <div class="stat-desc">Corrales configurados</div>
        </div>
    </a>
    
    <!-- Bovinos activos -->
    <div class="stat-card purple">
        <div style="font-size: 2.5rem; margin-bottom: 10px;">🐄</div>
        <div class="stat-label">Bovinos Activos</div>
        <div class="stat-value"><?= $bovinosActivos ?></div>
        <div class="stat-desc">En el sistema</div>
    </div>
</div>

<!-- Menu de opciones -->
<div class="table-container">
    <div class="table-header">
        <h2>Opciones de Configuracion</h2>
    </div>
    
    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
        
        <a href="<?= BASE_URL ?>/configuracion/compras" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('camion', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Compras de Ganado</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Registrar compras y alertar al recepcionista</div>
            </div>
        </a>
        
        <a href="<?= BASE_URL ?>/usuarios" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('usuarios', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Gestion de Usuarios</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Crear, editar y desactivar empleados</div>
            </div>
        </a>
        
        <a href="<?= BASE_URL ?>/corrales" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('corral', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Gestion de Corrales</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Configurar corrales y capacidades</div>
            </div>
        </a>
        
        <a href="<?= BASE_URL ?>/configuracion/empresa" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('proveedor', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Datos de la Empresa</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Nombre, RFC, direccion, contacto</div>
            </div>
        </a>
        
        <a href="<?= BASE_URL ?>/configuracion/precios" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('dinero', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Precios de Referencia</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Precios BECEMAR por categoria</div>
            </div>
        </a>
        
        <a href="<?= BASE_URL ?>/tratamientos/inventario" 
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('medicina', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Inventario de Medicamentos</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Stock y precios de medicamentos</div>
            </div>
        </a>
        <a href="<?= BASE_URL ?>/configuracion/backup"
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('ajustes', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Respaldo de Base de Datos</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Descargar copia de seguridad (.sql)</div>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/solicitudes"
           style="display: flex; align-items: center; padding: 20px; background: var(--suave); border-radius: 8px; text-decoration: none; color: var(--texto); transition: all 0.2s;" data-hover>
            <div class="accion-ico"><?= icono('solicitud', 22) ?></div>
            <div>
                <div style="font-weight: 600; margin-bottom: 4px;">Solicitudes de Compra</div>
                <div style="font-size: 0.85rem; color: var(--tenue);">Medicamentos y alimento pedidos por el personal</div>
            </div>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
