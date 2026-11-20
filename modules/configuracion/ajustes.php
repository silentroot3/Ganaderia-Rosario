<?php
// ============================================
// CONFIGURACION - Datos del rancho y parametros del sistema (HU-018)
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

$db = Database::getInstance()->getConnection();

$campos = [
    'Datos del rancho' => [
        'empresa_nombre' => ['Nombre del rancho', 'text'], 'empresa_rfc' => ['RFC', 'text'],
        'empresa_direccion' => ['Direccion', 'text'], 'empresa_telefono' => ['Telefono', 'text'], 'empresa_email' => ['Correo', 'email'],
    ],
    'Parametros' => [
        'iva' => ['IVA por defecto en ventas (%)', 'number'],
        'alerta_dias_sin_pesar' => ['Dias sin pesar para alertar', 'number'],
        'alerta_dias_vacuna' => ['Dias de anticipacion para vacuna', 'number'],
        'alerta_dias_sin_medicina' => ['Dias de enfermo sin medicina para alerta urgente', 'number'],
        'alerta_dias_caducidad' => ['Dias antes de caducidad para alertar', 'number'],
    ],
];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Permisos::protegerPagina('configuracion', 'editar');
        exigirCSRF();
        $db->beginTransaction();
        foreach ($campos as $grupo) {
            foreach ($grupo as $clave => $def) {
                if (!isset($_POST[$clave])) continue;
                $valor = trim($_POST[$clave]);
                if ($def[1] === 'number' && (!is_numeric($valor) || $valor < 0)) throw new Exception("Valor invalido en: {$def[0]}");
                if ($def[1] === 'email' && $valor !== '' && !validarEmail($valor)) throw new Exception('Correo invalido');
                if ($clave === 'empresa_nombre' && $valor === '') throw new Exception('El nombre del rancho es obligatorio');
                $db->prepare("INSERT INTO configuracion (clave, valor, tipo) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
                   ->execute([$clave, $valor, $def[1] === 'number' ? 'numero' : 'texto']);
            }
        }
        $db->commit();
        registrarLog($_SESSION['user_id'], 'configuracion', 'Datos del rancho actualizados');
        header('Location: ' . BASE_URL . '/configuracion/empresa?msg=' . urlencode('Configuracion actualizada'));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$titulo = 'Datos del rancho';
$modulo = 'configuracion';
$accion = 'ver';
require_once __DIR__ . '/../../includes/header.php';

$valores = $db->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="table-container" style="max-width:750px;margin:0 auto;">
    <div class="table-header"><h2>Datos del rancho y parametros</h2><a href="<?= BASE_URL ?>/configuracion" class="btn btn-secondary">Volver</a></div>
    <?php if ($error): ?><div class="alert alert-error" style="margin:20px;"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" style="padding:25px;">
        <?= csrfField() ?>
        <?php foreach ($campos as $grupo => $lista): ?>
            <h3 style="margin:10px 0 15px;color:#0b4f86;"><?= h($grupo) ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:20px;">
            <?php foreach ($lista as $clave => $def): ?>
                <div><label style="<?= ESTILO_LABEL ?>"><?= h($def[0]) ?></label>
                    <input type="<?= $def[1] ?>" <?= $def[1] === 'number' ? 'step="any" min="0"' : '' ?> name="<?= $clave ?>" value="<?= h($_POST[$clave] ?? ($valores[$clave] ?? '')) ?>" style="<?= ESTILO_INPUT ?>"></div>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div style="text-align:right;"><button class="btn btn-primary" type="submit">Guardar cambios</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
