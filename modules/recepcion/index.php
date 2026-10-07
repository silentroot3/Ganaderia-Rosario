<?php
/**
 * Módulo de Recepción
 * Solo admin y recepcionista
 */

// Verificar rol
requireRole(['admin', 'recepcionista']);

$db = Database::getInstance();
$mensaje = '';
$error = '';

// ============================================
// PROCESAR ACCIONES (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verificar CSRF
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Error de seguridad.';
    } else {
        $accion = $_POST['accion'] ?? '';
        
        // ============================================
        // CREAR NUEVA RECEPCIÓN
        // ============================================
        if ($accion === 'crear_recepcion') {
            $proveedor_id = (int)($_POST['proveedor_id'] ?? 0);
            $transporte = sanitizar($_POST['transporte'] ?? '');
            $placas = sanitizar($_POST['placas'] ?? '');
            $chofer = sanitizar($_POST['chofer'] ?? '');
            $observaciones = sanitizar($_POST['observaciones'] ?? '');
            
            // Generar folio único
            $folio = 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            
            try {
                $recepcion_id = $db->insert(
                    "INSERT INTO recepciones 
                     (folio, proveedor_id, usuario_id, transporte, placas, chofer, observaciones, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'borrador')",
                    [
                        $folio,
                        $proveedor_id ?: null,
                        $_SESSION['user_id'],
                        $transporte,
                        $placas,
                        $chofer,
                        $observaciones
                    ]
                );
                
                // Redirigir al detalle de la recepción
                header('Location: ' . BASE_URL . '/recepcion/detalle?id=' . $recepcion_id);
                exit;
                
            } catch (Exception $e) {
                $error = 'Error al crear la recepción: ' . $e->getMessage();
            }
        }
    }
}

// ============================================
// OBTENER DATOS PARA LA VISTA
// ============================================

// Proveedores
$proveedores = $db->fetchAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");

// Recepciones del usuario (o todas si es admin)
if (hasRole('admin')) {
    $recepciones = $db->fetchAll(
        "SELECT r.*, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido, 
                p.nombre AS proveedor_nombre,
                (SELECT COUNT(*) FROM bovinos WHERE recepcion_id = r.id) AS total_bovinos
         FROM recepciones r
         LEFT JOIN usuarios u ON r.usuario_id = u.id
         LEFT JOIN proveedores p ON r.proveedor_id = p.id
         ORDER BY r.fecha_recepcion DESC
         LIMIT 50"
    );
} else {
    $recepciones = $db->fetchAll(
        "SELECT r.*, p.nombre AS proveedor_nombre,
                (SELECT COUNT(*) FROM bovinos WHERE recepcion_id = r.id) AS total_bovinos
         FROM recepciones r
         LEFT JOIN proveedores p ON r.proveedor_id = p.id
         WHERE r.usuario_id = ?
         ORDER BY r.fecha_recepcion DESC
         LIMIT 50",
        [$_SESSION['user_id']]
    );
}

// Estadísticas
if (hasRole('admin')) {
    $stats = [
        'total_recepciones' => $db->fetchOne("SELECT COUNT(*) as c FROM recepciones")['c'],
        'total_bovinos' => $db->fetchOne("SELECT COUNT(*) as c FROM bovinos")['c'],
        'recepciones_hoy' => $db->fetchOne("SELECT COUNT(*) as c FROM recepciones WHERE DATE(fecha_recepcion) = CURDATE()")['c'],
        'bovinos_hoy' => $db->fetchOne("SELECT COUNT(*) as c FROM bovinos b JOIN recepciones r ON b.recepcion_id = r.id WHERE DATE(r.fecha_recepcion) = CURDATE()")['c'],
    ];
} else {
    $stats = [
        'total_recepciones' => $db->fetchOne("SELECT COUNT(*) as c FROM recepciones WHERE usuario_id = ?", [$_SESSION['user_id']])['c'],
        'total_bovinos' => $db->fetchOne("SELECT COUNT(*) as c FROM bovinos b JOIN recepciones r ON b.recepcion_id = r.id WHERE r.usuario_id = ?", [$_SESSION['user_id']])['c'],
        'recepciones_hoy' => $db->fetchOne("SELECT COUNT(*) as c FROM recepciones WHERE usuario_id = ? AND DATE(fecha_recepcion) = CURDATE()", [$_SESSION['user_id']])['c'],
        'bovinos_hoy' => $db->fetchOne("SELECT COUNT(*) as c FROM bovinos b JOIN recepciones r ON b.recepcion_id = r.id WHERE r.usuario_id = ? AND DATE(r.fecha_recepcion) = CURDATE()", [$_SESSION['user_id']])['c'],
    ];
}

$csrf_token = generarCSRF();
$page_title = 'Recepción';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #2c6b3f;">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard">
                <i class="fas fa-cow me-2"></i> <?= APP_NAME ?>
            </a>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-user-circle me-1"></i>
                    <?= htmlspecialchars($_SESSION['user_nombre']) ?>
                    <span class="badge bg-light text-dark ms-2"><?= $_SESSION['user_rol'] ?></span>
                </span>
                <a href="<?= BASE_URL ?>/dashboard" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-home"></i>
                </a>
                <a href="<?= BASE_URL ?>/logout" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-truck text-success"></i> Recepción de Ganado</h2>
                <p class="text-muted mb-0">Registra la llegada de nuevos lotes de bovinos</p>
            </div>
            <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#modalNuevaRecepcion">
                <i class="fas fa-plus me-2"></i> Nueva Recepción
            </button>
        </div>

        <!-- Mensajes -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-uppercase mb-1">Recepciones Totales</h6>
                                <h2 class="mb-0"><?= $stats['total_recepciones'] ?></h2>
                            </div>
                            <i class="fas fa-truck fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-uppercase mb-1">Bovinos Registrados</h6>
                                <h2 class="mb-0"><?= $stats['total_bovinos'] ?></h2>
                            </div>
                            <i class="fas fa-cow fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-uppercase mb-1">Recepciones Hoy</h6>
                                <h2 class="mb-0"><?= $stats['recepciones_hoy'] ?></h2>
                            </div>
                            <i class="fas fa-calendar-day fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-uppercase mb-1">Bovinos Hoy</h6>
                                <h2 class="mb-0"><?= $stats['bovinos_hoy'] ?></h2>
                            </div>
                            <i class="fas fa-clipboard-check fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de recepciones -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    <?= hasRole('admin') ? 'Todas las Recepciones' : 'Mis Recepciones' ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recepciones)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No hay recepciones registradas</h5>
                        <p class="text-muted">Haz clic en "Nueva Recepción" para empezar</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Folio</th>
                                    <th>Proveedor</th>
                                    <th>Fecha</th>
                                    <th class="text-center">Animales</th>
                                    <?php if (hasRole('admin')): ?>
                                        <th>Registró</th>
                                    <?php endif; ?>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recepciones as $r): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($r['folio']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($r['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($r['fecha_recepcion'])) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= $r['total_bovinos'] ?></span>
                                        </td>
                                        <?php if (hasRole('admin')): ?>
                                            <td>
                                                <?= htmlspecialchars(($r['usuario_nombre'] ?? '') . ' ' . ($r['usuario_apellido'] ?? '')) ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php
                                            $estados = [
                                                'borrador' => ['secondary', 'Borrador'],
                                                'completado' => ['success', 'Completado'],
                                                'cancelado' => ['danger', 'Cancelado']
                                            ];
                                            $e = $estados[$r['estado']] ?? ['secondary', $r['estado']];
                                            ?>
                                            <span class="badge bg-<?= $e[0] ?>"><?= $e[1] ?></span>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>/recepcion/detalle?id=<?= $r['id'] ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Nueva Recepción -->
    <div class="modal fade" id="modalNuevaRecepcion" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="accion" value="crear_recepcion">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-plus-circle me-2"></i> Nueva Recepción
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Proveedor</label>
                                <select name="proveedor_id" class="form-select">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach ($proveedores as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transporte</label>
                                <input type="text" name="transporte" class="form-control" placeholder="Ej: Camión 3 ton">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Placas</label>
                                <input type="text" name="placas" class="form-control" placeholder="Ej: ABC-1234">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Chofer</label>
                                <input type="text" name="chofer" class="form-control" placeholder="Nombre del chofer">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Observaciones</label>
                                <textarea name="observaciones" class="form-control" rows="3" 
                                          placeholder="Notas sobre la recepción..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i> Crear Recepción
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
