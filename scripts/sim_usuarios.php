<?php
session_start();

require_once __DIR__ . '/../config_mejorado.php';
require_once __DIR__ . '/../seguridad/funciones.php';

// Verificar token de protección: puede venir por GET o POST
$provided = $_GET['token'] ?? $_POST['token'] ?? null;
if (!defined('SIM_USERS_TOKEN') || SIM_USERS_TOKEN === '' || $provided !== SIM_USERS_TOKEN) {
    // Acceso denegado
    http_response_code(403);
    echo "Acceso denegado. Token inválido.";
    exit;
}

// Asegurar que la sesión esté inicializada con usuarios desde archivo si corresponde
ensureSimUsersInSession();

$action = $_POST['action'] ?? null;

if ($action === 'add') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'operario');
    if ($username !== '') {
        $_SESSION['sim_usuarios'][$username] = ['clave' => $password, 'rol' => $role];
        // rebuild index list
        $_SESSION['sim_users'] = [];
        $i = 1;
        foreach ($_SESSION['sim_usuarios'] as $k => $v) {
            $_SESSION['sim_users'][] = ['id' => $i++, 'username' => $k, 'password' => $v['clave'] ?? '', 'rol' => $v['rol'] ?? 'operario'];
        }
        saveSimUsers($_SESSION['sim_usuarios']);
    }
    header('Location: sim_usuarios.php?token=' . urlencode(SIM_USERS_TOKEN));
    exit;
}

if ($action === 'delete') {
    $username = $_POST['username'] ?? '';
    if ($username !== '' && isset($_SESSION['sim_usuarios'][$username])) {
        unset($_SESSION['sim_usuarios'][$username]);
        // rebuild index list
        $_SESSION['sim_users'] = [];
        $i = 1;
        foreach ($_SESSION['sim_usuarios'] as $k => $v) {
            $_SESSION['sim_users'][] = ['id' => $i++, 'username' => $k, 'password' => $v['clave'] ?? '', 'rol' => $v['rol'] ?? 'operario'];
        }
        saveSimUsers($_SESSION['sim_usuarios']);
    }
    header('Location: sim_usuarios.php?token=' . urlencode(SIM_USERS_TOKEN));
    exit;
}

if ($action === 'clear') {
    $_SESSION['sim_usuarios'] = [];
    $_SESSION['sim_users'] = [];
    saveSimUsers($_SESSION['sim_usuarios']);
    header('Location: sim_usuarios.php?token=' . urlencode(SIM_USERS_TOKEN));
    exit;
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Simular usuarios (sesión)</title>
    <style>
        body{font-family: Arial, sans-serif; max-width:800px;margin:20px auto}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #ccc;padding:8px;text-align:left}
        form.inline{display:inline}
        .muted{color:#666;font-size:0.9em}
    </style>
</head>
<body>
<h1>Administrar usuarios simulados (sesión)</h1>
<p class="muted">Estos usuarios se almacenan sólo en la sesión. Úsalos para pruebas locales.</p>

<h2>Añadir usuario</h2>
<form method="post">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="token" value="<?= htmlspecialchars(SIM_USERS_TOKEN) ?>">
    <label>Usuario: <input name="username" required></label>
    <label>Contraseña: <input name="password"></label>
    <label>Rol:
        <select name="role">
            <option value="operario">operario</option>
            <option value="tecnico">tecnico</option>
            <option value="administrador">administrador</option>
        </select>
    </label>
    <button type="submit">Añadir</button>
</form>

<h2>Usuarios simulados en sesión</h2>
<?php if (empty($_SESSION['sim_usuarios'])): ?>
    <p>No hay usuarios simulados.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Usuario</th><th>Rol</th><th>Contraseña (texto)</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($_SESSION['sim_usuarios'] as $u => $info): ?>
            <tr>
                <td><?= htmlspecialchars($u) ?></td>
                <td><?= htmlspecialchars($info['rol'] ?? '') ?></td>
                <td><?= htmlspecialchars($info['clave'] ?? '') ?></td>
                <td>
                    <form method="post" class="inline">
                        <input type="hidden" name="token" value="<?= htmlspecialchars(SIM_USERS_TOKEN) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($u) ?>">
                        <button type="submit">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<form method="post" style="margin-top:12px">
    <input type="hidden" name="token" value="<?= htmlspecialchars(SIM_USERS_TOKEN) ?>">
    <input type="hidden" name="action" value="clear">
    <button type="submit">Borrar todos</button>
</form>

<p><a href="../Inicio_de_sesion.html">Volver al login</a> | <a href="sim_users.php?token=<?= urlencode(SIM_USERS_TOKEN) ?>">Refrescar (mantener token)</a></p>
</body>
</html>
