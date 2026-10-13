<?php
require_once __DIR__ . '/config.php';

$db = Database::getInstance();
$email = 'admin@ganaderia.com';
$password_ingresada = 'Admin2026*';

// 1. Intentar buscar al usuario en el puerto 3307
$user = $db->fetchOne("SELECT * FROM usuarios WHERE email = ?", [$email]);

if (!$user) {
    die("\n[ERROR] El usuario '$email' no existe en la base de datos local del puerto 3307.\n");
}

echo "\n[INFO] Usuario encontrado en la base de datos.";
echo "\n[INFO] Hash en BD: " . $user['password'];
echo "\n[INFO] Intentos fallidos: " . $user['intentos_fallidos'];
echo "\n[INFO] Estado Activo: " . $user['activo'];

// 2. Probar la verificación nativa de PHP
if (password_verify($password_ingresada, $user['password'])) {
    echo "\n[ÉXITO] PHP reconoce la contraseña 'Admin2026*' como CORRECTA.\n";
} else {
    echo "\n[FALLO] PHP dice que la contraseña es INCORRECTA para ese hash.\n";
    
    // Generar un hash fresco compatible con tu entorno actual
    $nuevo_hash = password_hash($password_ingresada, PASSWORD_BCRYPT);
    echo "[ACCIÓN] Generando un hash nuevo compatible: $nuevo_hash\n";
}
?>
