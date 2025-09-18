<?php
// Uso: php scripts/seed_admin.php --username=admin --password=Secreta123 --force

require_once __DIR__ . '/../conf.php';
require_once __DIR__ . '/../seguridad/conexion.php';

$options = getopt('', ['username::', 'password::', 'force']);

$username = $options['username'] ?? null;
$password = $options['password'] ?? null;
$force = isset($options['force']);

if (php_sapi_name() !== 'cli') {
    echo "Este script debe ejecutarse desde la línea de comandos (CLI).\n";
    exit(1);
}

if (!$username) {
    echo "Usuario por defecto (admin) > ";
    $username = trim(fgets(STDIN));
    if ($username === '') $username = 'admin';
}

if (!$password) {
    echo "Contraseña para $username > ";
    // ocultar entrada en Windows puede no ser trivial; leer en claro
    $password = trim(fgets(STDIN));
    if ($password === '') {
        echo "Se requiere una contraseña.\n";
        exit(1);
    }
}

$pdo = conectarReal();
if ($pdo === null) {
    echo "No se pudo conectar a la base de datos. Revisa conf.php\n";
    exit(1);
}

// Verificar si existe
$stmt = $pdo->prepare('SELECT id, username FROM usuarios WHERE username = :username');
$stmt->execute([':username' => $username]);
if ($stmt->rowCount() > 0) {
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$force) {
        echo "El usuario '$username' ya existe (id={$row['id']}). Usa --force para reemplazar/actualizar.\n";
        exit(1);
    } else {
        // actualizar contraseña y rol
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $up = $pdo->prepare('UPDATE usuarios SET password = :password, rol = :rol WHERE id = :id');
        $up->execute([':password' => $hash, ':rol' => 'administrador', ':id' => $row['id']]);
        echo "Usuario existente actualizado a administrador. ID={$row['id']}\n";
        exit(0);
    }
}

// Insertar nuevo admin
$hash = password_hash($password, PASSWORD_DEFAULT);
$ins = $pdo->prepare('INSERT INTO usuarios (username, password, rol) VALUES (:username, :password, :rol)');
$ok = $ins->execute([':username' => $username, ':password' => $hash, ':rol' => 'administrador']);
if ($ok) {
    echo "Administrador '$username' creado correctamente.\n";
    exit(0);
} else {
    echo "Error al crear el administrador.\n";
    exit(1);
}
