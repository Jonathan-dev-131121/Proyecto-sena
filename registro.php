<?php
session_start();
require_once 'seguridad/conexion.php';
require_once 'seguridad/funciones.php';

$usuario = trim($_POST['usuario'] ?? '');
$clave = trim($_POST['clave'] ?? '');
$tipo = trim($_POST['tipo_usuario'] ?? 'operario');

if ($usuario === '' || $clave === '') {
    echo "Usuario y contraseña son requeridos.";
    exit;
}

try {
    $dbresult = dbConnect();

    // MODO FICTICIO: si dbConnect devuelve array o ya existen usuarios en sesión
    if (is_array($dbresult) || isset($_SESSION['sim_usuarios'])) {
        // Asegurar que la sesión tenga cargados los usuarios desde archivo si corresponde
        ensureSimUsersInSession();

        if (isset($_SESSION['sim_usuarios'][$usuario])) {
            echo "El usuario ya existe (modo ficticio).";
            exit;
        }

        // Agregar usuario a la sesión y persistir en archivo JSON
        $_SESSION['sim_usuarios'][$usuario] = ['clave' => $clave, 'rol' => $tipo];

        // Reconstruir listado indexado para la UI
        $_SESSION['sim_users'] = [];
        $i = 1;
        foreach ($_SESSION['sim_usuarios'] as $k => $v) {
            $_SESSION['sim_users'][] = ['id' => $i++, 'username' => $k, 'password' => $v['clave'] ?? '', 'rol' => $v['rol'] ?? 'operario'];
        }

        saveSimUsers($_SESSION['sim_usuarios']);

        // Log y redirección a panel de simulación
        error_log(date('[Y-m-d H:i:s] ') . "Usuario ficticio agregado: {$usuario} (rol={$tipo})" . PHP_EOL, 3, __DIR__ . '/seguridad/db_error.log');
        $token = defined('SIM_USERS_TOKEN') ? SIM_USERS_TOKEN : '';
        header('Location: scripts/sim_usuarios.php?token=' . urlencode($token));
        exit;
    }

    // MODO REAL: dbConnect() devolvió un PDO
    if ($dbresult instanceof PDO) {
        $pdo = $dbresult;

        // Usar nombres configurables desde conf.php si existen
        $table = defined('DB_USERS_TABLE') ? DB_USERS_TABLE : 'usuarios';
        $colUser = defined('DB_USER_COL_USERNAME') ? DB_USER_COL_USERNAME : 'username';
        $colPass = defined('DB_USER_COL_PASSWORD') ? DB_USER_COL_PASSWORD : 'password';
        $colRol  = defined('DB_USER_COL_ROL') ? DB_USER_COL_ROL : 'rol';

        // Verificar existencia
        $check = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$colUser} = :usuario LIMIT 1");
        $check->execute([':usuario' => $usuario]);
        if ($check->fetch()) {
            echo "El usuario ya existe.";
            exit;
        }

        // Insertar usuario con password_hash
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$colUser}, {$colPass}, {$colRol}) VALUES (:usuario, :password, :rol)");
        $stmt->execute([':usuario' => $usuario, ':password' => $hash, ':rol' => $tipo]);

        echo "Registro exitoso. <a href='Inicio_de_sesion.html'>Iniciar sesion</a>";
        exit;
    }

    echo "Error: no se pudo conectar con la capa de persistencia.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

