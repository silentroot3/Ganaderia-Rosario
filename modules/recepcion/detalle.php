<?php
/**
 * Detalle de Recepción - Agregar bovinos individuales
 */

requireRole(['admin', 'recepcionista']);

$db = Database::getInstance();
$mensaje = '';
$error = '';

// ID de la recepción
$recepcion_id = (int)($_GET['id'] ?? 0);

if (!$recepcion_id) {
    header('Location: ' . BASE_URL . '/recepcion');
    exit;
}

// Obtener la recepción
$recepcion = $db->fetchOne(
    "SELECT r.*, p.nombre AS proveedor_nombre
     FROM recepciones r
     LEFT JOIN proveedores p ON r.proveedor_id = p.id
     WHERE r.id = ?",
    [$recepcion_id]
);

if (!$recepcion) {
    header('Location: ' . BASE_URL . '/recepcion');
    exit;
}

// Verificar permisos (recepcionista solo ve las suyas)
if (hasRole('recepcionista') && $recepcion['usuario_id'] != $_SESSION['user_id']) {
    http_response_code(403);
    include BASE_PATH . '/modules/errors/403.php';
    exit;
}

// ============================================
// PROCESAR ACCIONES
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Error de seguridad.';
    } else {
        $accion = $_POST['accion'] ?? '';
        
        // ============================================
        // AGREGAR BOVINO
        // ============================================
        if ($accion === 'agregar_bovino') {
            $id_siniiga = sanitizar($_POST['id_siniiga'] ?? '');
            $sexo = $_POST['sexo'] ?? '';
            $raza = sanitizar($_POST['raza'] ?? '');
            $peso_entrada = (float)($_POST['peso_entrada'] ?? 0);
            $edad_meses = (int)($_POST['edad_meses'] ?? 0);
            $color = sanitizar($_POST['color'] ?? '');
            $observaciones = sanitizar($_POST['observaciones'] ?? '');
            
            // Validaciones
            if (empty($id_siniiga)) {
                $error = 'El ID SINIIGA es obligatorio.';
            } elseif (!preg_match('/^[0-9]{12,15}$/', $id_siniiga)) {
                $error = 'El ID SINIIGA debe tener entre 12 y 15 dígitos.';
            } elseif (!in_array($sexo, ['M', 'H'])) {
                $error = 'Debe seleccionar el sexo.';
            } elseif ($peso_entrada <= 0) {
                $error = 'El peso debe ser mayor a 0.';
            } else {
                // Verificar que no exista el ID
                $existe = $db->fetchOne("SELECT id FROM bovinos WHERE id_siniiga = ?", [$id_siniiga]);
                
                if ($existe) {
                    $error = 'El ID SINIIGA ya está registrado.';
                } else {
                    try {
                        $db->insert(
                            "INSERT INTO bovinos 
                             (recepcion_id, id_siniiga, sexo, raza, peso_entrada, edad_meses, color, observaciones)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [$recepcion_id, $id_siniiga, $sexo, $raza, $peso_entrada, $edad_meses, $color, $observaciones]
                        );
                        
                        // Actualizar contador de la recepción
                        $db->execute(
                            "UPDATE recepciones SET cantidad_animales = 
                                (SELECT COUNT(*) FROM bovinos WHERE recepcion_id = ?)
                             WHERE id = ?",
                            [$recepcion_id, $recepcion_id]
                        );
                        
                        $mensaje = 'Bovino agregado correctamente.';
                    } catch (Exception $e) {
                        $error = 'Error al agregar: ' . $e->getMessage();
                    }
                }
            }
        }
        
        // ============================================
        // ELIMINAR BOVINO
        // ============================================
        elseif ($accion === 'eliminar_bovino') {
            $bovino_id = (int)($_POST['bovino_id'] ?? 0);
            
            $db->execute("DELETE FROM bovinos WHERE id = ? AND recepcion_id = ?", [$bovino_id, $recepcion_id]);
            
            $db->execute(
                "UPDATE recepciones SET cantidad_animales = 
                    (SELECT COUNT(*) FROM bovinos WHERE recepcion_id = ?)
                 WHERE id = ?",
                [$recepcion_id, $recepcion_id]
            );
            
            $mensaje = 'Bovino eliminado.';
        }
        
        // ============================================
        // COMPLETAR RECEPCIÓN
        // ============================================
        elseif ($accion === 'completar') {
            $total = $db->fetchOne("SELECT COUNT(*) as c FROM bovinos WHERE recepcion_id = ?", [$recepcion_id])['c'];
            
            if ($total == 0) {
                $error = 'Debe agregar al menos un bovino.';
            } else {
                $db->execute("UPDATE recepciones SET estado = 'completado' WHERE id = ?", [$recepcion_id]);
                $mensaje = 'Recepción completada correctamente.';
            }
        }
    }
}

// Obtener bovinos de esta recepción
$bovinos = $db->fetchAll(
    "SELECT * FROM bovinos WHERE recepcion_id = ? ORDER BY id DESC",
    [$recepcion_id]
);

$csrf_token = generarCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle Recepción - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #2c6b3f;">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard">
                <i class="fas fa-cow me-2"></i> <?= APP_NAME ?>
            </a>
            <div class="ms-auto">
                <a href="<?= BASE_URL ?>/recepcion" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <a href="<?= BASE_URL ?>/logout" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Info de la recepción -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h3>
                            <i class="fas fa-clipboard-list text-success"></i>
                            Folio: <?= htmlspecialchars($recepcion['folio']) ?>
                            <?php
                            $estados = [
                                'borrador' => ['secondary', 'Borrador'],
                                'completado' => ['success', 'Completado'],
                                'cancelado' => ['danger', 'Cancelado']
                            ];
                            $e = $estados[$recepcion['estado']];
                            ?>
                            <span class="badge bg-<?= $e[0] ?>"><?= $e[1] ?></span>
                        </h3>
                        <p class="mb-1"><strong>Proveedor:</strong> <?= htmlspecialchars($recepcion['proveedor_nombre'] ?? 'N/A') ?></p>
                        <p class="mb-1"><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($recepcion['fecha_recepcion'])) ?></p>
                        <?php if ($recepcion['transporte']): ?>
                            <p class="mb-1"><strong>Transporte:</strong> <?= htmlspecialchars($recepcion['transporte']) ?> (<?= htmlspecialchars($recepcion['placas']) ?>)</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <h1 class="text-success"><?= count($bovinos) ?></h1>
                        <p class="text-muted">Bovinos registrados</p>
                        
                        <?php if ($recepcion['estado'] === 'borrador' && count($bovinos) > 0): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="accion" value="completar">
                                <button type="submit" class="btn btn-success" onclick="return confirm('¿Completar esta recepción?')">
                                    <i class="fas fa-check me-2"></i> Completar Recepción
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mensajes -->
        <?php if ($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i> <?= $mensaje ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Formulario agregar bovino -->
            <?php if ($recepcion['estado'] === 'borrador'): ?>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i> Agregar Bovino</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="accion" value="agregar_bovino">
                            
                            <div class="mb-3">
                                <label class="form-label">ID SINIIGA <span class="text-danger">*</span></label>
                                <input type="text" name="id_siniiga" class="form-control" 
                                       pattern="[0-9]{12,15}" required
                                       placeholder="12-15 dígitos">
                                <small class="text-muted">Solo números</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Sexo <span class="text-danger">*</span></label>
                                <select name="sexo" class="form-select" required>
                                    <option value="">-- Seleccionar --</option>
                                    <option value="M">Macho</option>
                                    <option value="H">Hembra</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Peso (kg) <span class="text-danger">*</span></label>
                                <input type="number" name="peso_entrada" class="form-control" 
                                       step="0.01" min="1" required placeholder="Ej: 250.5">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Edad (meses)</label>
                                <input type="number" name="edad_meses" class="form-control" 
                                       min="0" placeholder="Ej: 8">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Raza</label>
                                <input type="text" name="raza" class="form-control" 
                                       placeholder="Ej: Angus, Brahman">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Color</label>
                                <input type="text" name="color" class="form-control" 
                                       placeholder="Ej: Negro, Café">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Observaciones</label>
                                <textarea name="observaciones" class="form-control" rows="2" 
                                          placeholder="Notas..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-plus me-2"></i> Agregar Bovino
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Lista de bovinos -->
            <div class="<?= $recepcion['estado'] === 'borrador' ? 'col-md-8' : 'col-12' ?>">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i> Bovinos Registrados</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($bovinos)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-cow fa-4x text-muted mb-3"></i>
                                <p class="text-muted">No hay bovinos registrados aún</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID SINIIGA</th>
                                            <th>Sexo</th>
                                            <th>Peso</th>
                                            <th>Edad</th>
                                            <th>Raza</th>
                                            <th>Estado</th>
                                            <?php if ($recepcion['estado'] === 'borrador'): ?>
                                                <th class="text-end">Acciones</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bovinos as $b): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($b['id_siniiga']) ?></strong></td>
                                                <td>
                                                    <?php if ($b['sexo'] === 'M'): ?>
                                                        <span class="badge bg-primary"><i class="fas fa-mars"></i> Macho</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger"><i class="fas fa-venus"></i> Hembra</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= number_format($b['peso_entrada'], 2) ?> kg</td>
                                                <td><?= $b['edad_meses'] ? $b['edad_meses'] . ' meses' : '-' ?></td>
                                                <td><?= htmlspecialchars($b['raza'] ?? '-') ?></td>
                                                <td><span class="badge bg-secondary"><?= $b['estado'] ?></span></td>
                                                <?php if ($recepcion['estado'] === 'borrador'): ?>
                                                    <td class="text-end">
                                                        <form method="POST" class="d-inline" 
                                                              onsubmit="return confirm('¿Eliminar este bovino?')">
                                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                            <input type="hidden" name="accion" value="eliminar_bovino">
                                                            <input type="hidden" name="bovino_id" value="<?= $b['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
