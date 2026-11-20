<?php
// ============================================
// PROVEEDORES - Alta y edicion (solo administrador)
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/proveedores_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
$db = Database::getInstance()->getConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
Permisos::protegerPagina('proveedores', $id ? 'editar' : 'crear');
$error = '';

$p = ['nombre' => '', 'tipo' => 'alimento', 'upp' => '', 'rfc' => '', 'telefono' => '', 'email' => '', 'contacto' => '',
      'direccion' => '', 'localidad' => '', 'municipio' => '', 'estado' => '', 'notas' => '', 'activo' => 1];
if ($id) {
    $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$p) { header('Location: ' . BASE_URL . '/proveedores'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigirCSRF();
        $d = [];
        foreach (['nombre', 'upp', 'rfc', 'telefono', 'email', 'contacto', 'direccion', 'localidad', 'municipio', 'estado', 'notas'] as $k) {
            $d[$k] = trim(preg_replace('/[ \t]+/', ' ', (string)($_POST[$k] ?? '')));
        }
        $tipos = array_values(array_intersect(array_keys(TIPOS_PROVEEDOR), (array)($_POST['tipo'] ?? [])));
        $d['rfc'] = strtoupper($d['rfc']);
        $d['email'] = strtolower($d['email']);
        $d['activo'] = empty($_POST['activo']) ? 0 : 1;
        $p = array_merge($p, $d, ['tipo' => implode(',', $tipos)]);

        if ($d['nombre'] === '') throw new Exception('Escribe el nombre del proveedor');
        if (!$tipos) throw new Exception('Marca que nos vende (ganado, alimento, medicamento u otro)');
        if (in_array('ganado', $tipos, true) && $d['upp'] === '') throw new Exception('Un rancho que vende ganado necesita su UPP');
        if ($d['upp'] !== '' && !preg_match('/^\d{10,14}$/', $d['upp'])) throw new Exception('La UPP debe tener solo numeros (10 a 14 digitos)');
        if ($d['telefono'] !== '' && strlen(preg_replace('/\D/', '', $d['telefono'])) < 10) throw new Exception('El telefono debe tener 10 digitos');
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('El correo no es valido');
        if ($d['rfc'] !== '' && !preg_match('/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/u', $d['rfc'])) throw new Exception('El RFC no tiene el formato correcto');

        $dup = $db->prepare("SELECT id FROM proveedores WHERE id != ? AND (LOWER(nombre) = LOWER(?) AND COALESCE(upp, '') = ? OR (? != '' AND upp = ?))");
        $dup->execute([$id, $d['nombre'], $d['upp'], $d['upp'], $d['upp']]);
        if ($otro = $dup->fetchColumn()) throw new Exception('Ya existe un proveedor con ese nombre o UPP (#' . $otro . ')');

        $campos = ['nombre', 'tipo', 'upp', 'rfc', 'telefono', 'email', 'contacto', 'direccion', 'localidad', 'municipio', 'estado', 'notas', 'activo'];
        $vals = array_map(fn($k) => $k === 'tipo' ? implode(',', $tipos) : ($k === 'activo' ? $d['activo'] : ($d[$k] === '' ? null : $d[$k])), $campos);
        if ($id) {
            $db->prepare("UPDATE proveedores SET " . implode(', ', array_map(fn($k) => "$k = ?", $campos)) . " WHERE id = ?")->execute([...$vals, $id]);
        } else {
            $db->prepare("INSERT INTO proveedores (" . implode(', ', $campos) . ") VALUES (" . implode(', ', array_fill(0, count($campos), '?')) . ")")->execute($vals);
            $id = (int)$db->lastInsertId();
        }
        registrarLog($_SESSION['user_id'], 'proveedor_guardar', "#$id {$d['nombre']}");
        header('Location: ' . BASE_URL . '/proveedores/ver?id=' . $id . '&msg=' . urlencode('Proveedor guardado'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$titulo = $id ? 'Editar proveedor' : 'Nuevo proveedor';
$modulo = 'proveedores';
$accion = $id ? 'editar' : 'crear';
require_once __DIR__ . '/../../includes/header.php';
$tiposSel = explode(',', (string)$p['tipo']);
$campo = fn($name, $label, $attrs = '') => '<label class="campo"><span>' . $label . '</span><input name="' . $name . '" value="' . h($p[$name]) . '" ' . $attrs . '></label>';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<form method="POST" class="table-container form-card">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="table-header"><h2><?= $titulo ?></h2><a href="<?= BASE_URL ?>/proveedores<?= $id ? '/ver?id=' . $id : '' ?>" class="btn btn-secondary btn-sm">Cancelar</a></div>

    <div class="form-seccion">
        <h3>Datos generales</h3>
        <div class="form-grid">
            <?= $campo('nombre', 'Nombre o razon social *', 'required autofocus maxlength="150"') ?>
            <div class="campo"><span>¿Que nos vende? *</span>
                <div class="opciones">
                    <?php foreach (TIPOS_PROVEEDOR as $k => $txt): ?>
                        <label class="opcion"><input type="checkbox" name="tipo[]" value="<?= $k ?>" <?= in_array($k, $tiposSel, true) ? 'checked' : '' ?>> <?= $txt ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?= $campo('upp', 'UPP (si vende ganado)', 'inputmode="numeric" maxlength="14" placeholder="12 digitos"') ?>
            <?= $campo('rfc', 'RFC', 'maxlength="13" style="text-transform:uppercase"') ?>
        </div>
    </div>

    <div class="form-seccion">
        <h3>Contacto</h3>
        <div class="form-grid">
            <?= $campo('telefono', 'Telefono', 'type="tel" inputmode="tel" placeholder="10 digitos"') ?>
            <?= $campo('email', 'Correo', 'type="email"') ?>
            <?= $campo('contacto', 'Persona de contacto', 'maxlength="120" placeholder="Con quien hablar"') ?>
        </div>
    </div>

    <div class="form-seccion">
        <h3>Ubicacion</h3>
        <div class="form-grid">
            <?= $campo('localidad', 'Localidad') ?>
            <?= $campo('municipio', 'Municipio') ?>
            <?= $campo('estado', 'Estado') ?>
            <?= $campo('direccion', 'Direccion') ?>
        </div>
    </div>

    <div class="form-seccion">
        <label class="campo"><span>Notas</span><textarea name="notas" rows="3" placeholder="Condiciones de pago, dias de entrega, observaciones..."><?= h($p['notas']) ?></textarea></label>
        <label class="opcion" style="margin-top:12px;"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>> Activo (aparece al pedir y al capturar entradas)</label>
    </div>

    <div class="form-acciones">
        <button type="submit" class="btn btn-primary">Guardar proveedor</button>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
