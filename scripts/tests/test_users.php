<?php
// Ejecutar solo en entorno de pruebas: RUN_TESTS=1 php scripts/tests/test_users.php
if (getenv('RUN_TESTS') !== '1') {
    echo "Tests disabled. Set RUN_TESTS=1 to run tests.\n";
    exit(1);
}

require_once __DIR__ . '/../../conf.php';
require_once __DIR__ . '/../../seguridad/conexion.php';
require_once __DIR__ . '/../../seguridad/funciones.php';

$pdo = conectarReal();
if ($pdo === null) {
    echo "No DB connection. Aborting tests.\n";
    exit(1);
}

$testUser = 'test_user_' . bin2hex(random_bytes(4));
$testPass = 'TestPass123!';

echo "Creando usuario: $testUser\n";
$created = registrarUsuario($pdo, $testUser, $testPass);
if (!$created) { echo "Fallo al crear usuario\n"; exit(1); }

// Obtener id del usuario creado
$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = :username');
$stmt->execute([':username' => $testUser]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$id = $row['id'] ?? null;
if (!$id) { echo "No se encontró el usuario creado\n"; exit(1); }

echo "Usuario creado con id=$id\n";

// Editar rol
$ok = updateUsuario($pdo, $id, null, 'tecnico');
if (!$ok) { echo "Fallo al actualizar rol\n"; exit(1); }

echo "Rol actualizado correctamente\n";

// Cambiar contraseña
$ok = updateUsuario($pdo, $id, 'NuevaPass123!', null);
if (!$ok) { echo "Fallo al actualizar contraseña\n"; exit(1); }

echo "Contraseña actualizada correctamente\n";

// Eliminar
$ok = eliminarUsuario($pdo, $id);
if (!$ok) { echo "Fallo al eliminar usuario\n"; exit(1); }

echo "Usuario eliminado correctamente\n";

// Verificar auditoría
$stmt = $pdo->prepare("SELECT accion, realizado_por FROM user_audit WHERE usuario_id = :id ORDER BY creado_at DESC LIMIT 5");
$stmt->execute([':id' => $id]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($logs) === 0) {
    echo "No se encontraron registros de auditoría para el usuario (esto puede ser esperado si no se creó user_audit).\n";
} else {
    echo "Registros de auditoría encontrados:\n";
    print_r($logs);
}

echo "Pruebas completadas.\n";
