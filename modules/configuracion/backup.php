<?php
// ============================================
// CONFIGURACION - Respaldo de la base de datos (HU-018)
// Genera y descarga un archivo .sql completo, escrito en PHP puro
// (no depende de que mysqldump este instalado en el servidor).
// ============================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/ui.php';

Permisos::protegerPagina('configuracion', 'ver');
if (!Permisos::esAdmin()) { header('Location: ' . BASE_URL . '/403'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $titulo = 'Respaldo de base de datos';
    $modulo = 'configuracion';
    $accion = 'ver';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="table-container" style="max-width:650px;margin:0 auto;">
        <div class="table-header"><h2>Respaldo de la base de datos</h2><a href="<?= BASE_URL ?>/configuracion" class="btn btn-secondary">Volver</a></div>
        <form method="POST" style="padding:30px;text-align:center;">
            <?= csrfField() ?>
            <p style="margin-bottom:20px;color:var(--texto-2);">Se descargara un archivo <strong>.sql</strong> con la estructura y todos los datos
                (animales, tratamientos, comidas, ventas, usuarios, etc.). Guardalo en un lugar seguro: contiene informacion sensible.</p>
            <button class="btn btn-primary" type="submit">Descargar respaldo ahora</button>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

if (!verificarCSRF($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/configuracion?error=' . urlencode('Solicitud no valida'));
    exit;
}

@set_time_limit(0);
$db = Database::getInstance()->getConnection();
$nombreArchivo = 'respaldo_' . DB_NAME . '_' . date('Ymd_His') . '.sql';

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: no-store');
while (ob_get_level() > 0) ob_end_flush();

echo "-- Respaldo Ganaderia El Rosario\n-- Fecha: " . date('Y-m-d H:i:s') . "\n-- Base: " . DB_NAME . "\n\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

$tablas = []; $vistas = [];
foreach ($db->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM) as $r) {
    if ($r[1] === 'VIEW') $vistas[] = $r[0]; else $tablas[] = $r[0];
}

foreach ($tablas as $t) {
    $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    echo "-- Tabla `$t`\nDROP TABLE IF EXISTS `$t`;\n$create;\n\n";

    $stmt = $db->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
    $lote = [];
    $columnas = null;
    while ($fila = $stmt->fetch(PDO::FETCH_NUM)) {
        if ($columnas === null) {
            $cols = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) $cols[] = '`' . $stmt->getColumnMeta($i)['name'] . '`';
            $columnas = implode(', ', $cols);
        }
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $fila);
        $lote[] = '(' . implode(', ', $vals) . ')';
        if (count($lote) >= 200) {
            echo "INSERT INTO `$t` ($columnas) VALUES\n" . implode(",\n", $lote) . ";\n";
            $lote = [];
        }
    }
    if ($lote) echo "INSERT INTO `$t` ($columnas) VALUES\n" . implode(",\n", $lote) . ";\n";
    echo "\n";
    flush();
}

foreach ($vistas as $v) {
    $create = $db->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1];
    echo "-- Vista `$v`\nDROP VIEW IF EXISTS `$v`;\n$create;\n\n";
}

echo "SET FOREIGN_KEY_CHECKS=1;\n-- Fin del respaldo\n";
registrarLog($_SESSION['user_id'], 'respaldo_bd', $nombreArchivo);
