<?php
// ============================================
// ALERTAS - Notificaciones del sistema
// ============================================

$titulo = 'Alertas';
$modulo = 'alertas';
$accion = 'ver';

require_once __DIR__ . '/../../includes/header.php';

require_once __DIR__ . '/../../includes/alertas_lib.php';
generarAlertasAutomaticas();   // revisa stock, enfermos sin medicina, pesajes, vacunas, corrales llenos

$db = Database::getInstance()->getConnection();
$rol = $_SESSION['rol'] ?? 'invitado';
$userId = $_SESSION['user_id'];

// Marcar como leida
if (isset($_GET['marcar_leida'])) {
    $alerta_id = (int)$_GET['marcar_leida'];
    $stmt = $db->prepare("
        UPDATE alertas 
        SET leida = 1, leida_por = ?, fecha_lectura = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId, $alerta_id]);
    header('Location: ' . BASE_URL . '/alertas');
    exit;
}

// Marcar todas como leidas
if (isset($_GET['marcar_todas'])) {
    $stmt = $db->prepare("
        UPDATE alertas 
        SET leida = 1, leida_por = ?, fecha_lectura = NOW()
        WHERE leida = 0 
          AND (rol_destino = ? OR rol_destino IS NULL OR rol_destino = '')
    ");
    $stmt->execute([$userId, $rol]);
    header('Location: ' . BASE_URL . '/alertas?msg=todas_leidas');
    exit;
}

// Obtener alertas
$filtro = $_GET['filtro'] ?? 'no_leidas';

$sql = "
    SELECT a.*, 
           b.id_siniiga AS bovino_siniiga,
           c.nombre AS corral_nombre,
           co.folio AS compra_folio
    FROM alertas a
    LEFT JOIN bovinos b ON a.bovino_id = b.id
    LEFT JOIN corrales c ON a.corral_id = c.id
    LEFT JOIN compras co ON a.compra_id = co.id
    WHERE (a.rol_destino = ? OR a.rol_destino IS NULL OR a.rol_destino = '')
";
$params = [$rol];

if ($filtro === 'no_leidas') {
    $sql .= " AND a.leida = 0";
}

$sql .= " ORDER BY a.leida ASC, a.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar no leidas
$stmt = $db->prepare("
    SELECT COUNT(*) FROM alertas 
    WHERE leida = 0 
      AND (rol_destino = ? OR rol_destino IS NULL OR rol_destino = '')
");
$stmt->execute([$rol]);
$totalNoLeidas = $stmt->fetchColumn();

// Configuracion de colores por tipo (sin emojis)
$tipoInfo = [
    'compra_pendiente' => ['color' => 'var(--f-amarillo)', 'texto' => 'var(--t-amarillo)', 'label' => 'Compra pendiente'],
    'stock_bajo' => ['color' => 'var(--f-rojo)', 'texto' => 'var(--t-rojo)', 'label' => 'Stock bajo'],
    'animal_enfermo' => ['color' => 'var(--f-rojo)', 'texto' => 'var(--t-rojo)', 'label' => 'Animal enfermo'],
    'animal_sin_pesar' => ['color' => 'var(--f-amarillo)', 'texto' => 'var(--t-amarillo)', 'label' => 'Sin pesar'],
    'corral_lleno' => ['color' => 'var(--f-rojo)', 'texto' => 'var(--t-rojo)', 'label' => 'Corral lleno'],
    'vacuna_proxima' => ['color' => 'var(--f-azul)', 'texto' => 'var(--t-azul)', 'label' => 'Vacuna proxima'],
    'retiro_activo' => ['color' => 'var(--f-amarillo)', 'texto' => 'var(--t-amarillo)', 'label' => 'Retiro activo'],
    'solicitud_compra' => ['color' => 'var(--f-morado)', 'texto' => 'var(--t-morado)', 'label' => 'Solicitud de compra'],
    'medicamento_bajo' => ['color' => 'var(--f-rojo)', 'texto' => 'var(--t-rojo)', 'label' => 'Medicamento bajo'],
    'medicamento_caducado' => ['color' => 'var(--f-amarillo)', 'texto' => 'var(--t-amarillo)', 'label' => 'Medicamento por caducar'],
    'otro' => ['color' => 'var(--f-gris)', 'texto' => 'var(--texto-2)', 'label' => 'Otro'],
];
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'todas_leidas'): ?>
    <div class="alert alert-success">Todas las alertas han sido marcadas como leidas.</div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
    <div>
        <h1 style="margin-bottom: 5px;">Alertas</h1>
        <p style="color: var(--tenue);">
            <?php if ($totalNoLeidas > 0): ?>
                Tienes <strong><?= $totalNoLeidas ?></strong> alerta(s) sin leer
            <?php else: ?>
                No tienes alertas nuevas
            <?php endif; ?>
        </p>
    </div>
    
    <?php if ($totalNoLeidas > 0): ?>
        <a href="<?= BASE_URL ?>/alertas?marcar_todas=1" class="btn btn-secondary">
            Marcar todas como leidas
        </a>
    <?php endif; ?>
</div>

<div style="margin-bottom: 20px;">
    <a href="<?= BASE_URL ?>/alertas?filtro=no_leidas" 
       class="btn <?= $filtro === 'no_leidas' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        No leidas (<?= $totalNoLeidas ?>)
    </a>
    <a href="<?= BASE_URL ?>/alertas?filtro=todas" 
       class="btn <?= $filtro === 'todas' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Todas
    </a>
</div>

<?php if (count($alertas) > 0): ?>
    <div style="display: grid; gap: 10px;">
        <?php foreach ($alertas as $al): 
            $info = $tipoInfo[$al['tipo']] ?? $tipoInfo['otro'];
            $opacidad = $al['leida'] ? '0.6' : '1';
        ?>
        <div style="padding: 15px; background: <?= $al['leida'] ? 'var(--suave)' : $info['color'] ?>; border-radius: 8px; opacity: <?= $opacidad ?>;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <span style="background: <?= $info['color'] ?>; color: <?= $info['texto'] ?>; padding: 3px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">
                            <?= $info['label'] ?>
                        </span>
                        <?php if (!$al['leida']): ?>
                            <span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600;">
                                NUEVA
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div style="font-weight: 600; margin-bottom: 5px;">
                        <?= htmlspecialchars($al['mensaje']) ?>
                    </div>
                    
                    <?php if ($al['compra_folio']): ?>
                        <div style="font-size: 0.85rem; color: var(--tenue);">
                            Compra: <a href="<?= BASE_URL ?>/configuracion/compras/detalle?id=<?= $al['compra_id'] ?>" style="color: #1e88e5;">
                                <?= htmlspecialchars($al['compra_folio']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($al['bovino_siniiga']): ?>
                        <div style="font-size: 0.85rem; color: var(--tenue);">
                            Bovino: <strong><?= htmlspecialchars($al['bovino_siniiga']) ?></strong>
                        </div>
                    <?php endif; ?>
                    
                    <div style="font-size: 0.75rem; color: var(--tenue); margin-top: 8px;">
                        <?= date('d/m/Y H:i', strtotime($al['created_at'])) ?>
                    </div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php if ($al['tipo'] === 'compra_pendiente' && $al['compra_id']): ?>
                        <a href="<?= BASE_URL ?>/recepcion/recibir-compra?id=<?= $al['compra_id'] ?>" class="btn btn-primary btn-sm">
                            Recibir
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    // [modulo requerido, url, texto]
                    $accionesAlerta = [
                        'solicitud_compra'     => ['solicitudes', '/solicitudes', 'Ver solicitudes'],
                        'stock_bajo'           => ['solicitudes', '/solicitudes/crear?tipo=alimento', 'Solicitar alimento'],
                        'medicamento_bajo'     => ['tratamientos', '/tratamientos/inventario', 'Ver inventario'],
                        'medicamento_caducado' => ['tratamientos', '/tratamientos/inventario', 'Ver inventario'],
                        'animal_sin_pesar'     => ['seguimiento', '/seguimiento/registrar' . ($al['corral_id'] ? '?corral_id=' . $al['corral_id'] : ''), 'Pesar'],
                    ];
                    if ($al['tipo'] === 'animal_enfermo' && $al['bovino_id']) {
                        $accionesAlerta['animal_enfermo'] = ['sanidad', '/sanidad/atender?id=' . $al['bovino_id'], 'Atender'];
                    }
                    $ac = $accionesAlerta[$al['tipo']] ?? null;
                    if ($ac && Permisos::puedeVer($ac[0])): ?>
                        <a href="<?= BASE_URL . $ac[1] ?>" class="btn btn-primary btn-sm"><?= $ac[2] ?></a>
                    <?php endif; ?>

                    <?php if (!$al['leida']): ?>
                        <a href="<?= BASE_URL ?>/alertas?marcar_leida=<?= $al['id'] ?>" 
                           class="btn btn-secondary btn-sm">
                            Marcar leida
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="table-container">
        <div style="padding: 60px 40px; text-align: center; color: var(--tenue);">
            <h3>No hay alertas</h3>
            <p>Cuando haya eventos importantes, apareceran aqui.</p>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
