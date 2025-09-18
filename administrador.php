<?php
session_start();

if (!isset($_SESSION['usuario']) || $_SESSION['tipo'] !== 'administrador') {
    header("Location: Inicio_de_sesion.html");
    exit;
}

require_once 'seguridad/conexion.php';
require_once 'seguridad/funciones.php';

$pdo = dbConnect();
if ($pdo === null) {
    $errorMensaje = 'Error de conexión a la base de datos.';
} elseif (is_array($pdo)) {
    // Modo ficticio: $pdo es un array asociativo
}

$mensaje = '';
// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function validar_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
// Manejo de acciones enviadas por POST (crear / eliminar / editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validar_csrf($token)) {
        $mensaje = 'Token CSRF inválido.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear') {
            $user = limpiar($_POST['username'] ?? '');
            $pass = $_POST['password'] ?? '';
            if ($user === '' || $pass === '') {
                $mensaje = 'Usuario y contraseña son obligatorios.';
            } else {
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    // Inicializar estructuras si no existen
                    if (!isset($_SESSION['sim_users']) || !is_array($_SESSION['sim_users'])) {
                        $_SESSION['sim_users'] = [];
                    }
                    if (!isset($_SESSION['sim_usuarios']) || !is_array($_SESSION['sim_usuarios'])) {
                        $_SESSION['sim_usuarios'] = [];
                    }

                    // Verificar existencia por username
                    $exists = false;
                    foreach ($_SESSION['sim_users'] as $u) {
                        if (($u['username'] ?? '') === $user) { $exists = true; break; }
                    }
                    if ($exists) {
                        $mensaje = 'El usuario ya existe (modo simulado).';
                    } else {
                        // Crear nuevo registro con id incremental
                        $max = 0; foreach ($_SESSION['sim_users'] as $u) { if (($u['id'] ?? 0) > $max) $max = $u['id']; }
                        $newId = $max + 1;
                        $new = ['id' => $newId, 'username' => $user, 'password' => $pass, 'rol' => 'operario'];
                        $_SESSION['sim_users'][] = $new;
                        // Añadir al mapa de login
                        $_SESSION['sim_usuarios'][$user] = ['clave' => $pass, 'rol' => 'operario'];
                        $mensaje = 'Modo ficticio: usuario simulado creado.';
                    }
                } else {
                    if (usuarioExiste($pdo, $user)) {
                        $mensaje = 'El usuario ya existe.';
                    } else {
                        $ok = registrarUsuario($pdo, $user, $pass);
                        $mensaje = $ok ? 'Usuario creado correctamente.' : 'Error al crear el usuario.';
                    }
                }
            }
        } elseif ($accion === 'eliminar') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $mensaje = 'ID de usuario inválido.';
            } else {
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    if (!isset($_SESSION['sim_users']) || !is_array($_SESSION['sim_users'])) {
                        $mensaje = 'No hay usuarios simulados.';
                    } else {
                        $idx = false;
                        foreach ($_SESSION['sim_users'] as $i => $u) { if ((int)($u['id'] ?? 0) === $id) { $idx = $i; break; } }
                        if ($idx === false) {
                            $mensaje = 'Usuario no encontrado (modo simulado).';
                        } else {
                            // evitar eliminar último admin
                            $adminCount = 0; foreach ($_SESSION['sim_users'] as $u) { if (($u['rol'] ?? '') === 'administrador') $adminCount++; }
                            if (($_SESSION['sim_users'][$idx]['rol'] ?? '') === 'administrador' && $adminCount <= 1) {
                                $mensaje = 'No se puede eliminar al último administrador (modo simulado).';
                            } else {
                                // impedir eliminar al usuario actual
                                if (($_SESSION['sim_users'][$idx]['username'] ?? '') === ($_SESSION['usuario'] ?? '')) {
                                    $mensaje = 'No puede eliminar su propia cuenta.';
                                } else {
                                    $delUser = $_SESSION['sim_users'][$idx]['username'];
                                    array_splice($_SESSION['sim_users'], $idx, 1);
                                    if (isset($_SESSION['sim_usuarios'][$delUser])) unset($_SESSION['sim_usuarios'][$delUser]);
                                    $mensaje = 'Modo ficticio: usuario simulado eliminado.';
                                }
                            }
                        }
                    }
                } else {
                    $ok = eliminarUsuario($pdo, $id);
                    $mensaje = $ok ? 'Usuario eliminado.' : 'No se pudo eliminar el usuario (¿último admin?).';
                }
            }
        } elseif ($accion === 'editar') {
            $id = intval($_POST['id'] ?? 0);
            $newRol = limpiar($_POST['rol'] ?? '');
            $newPass = $_POST['password_edit'] ?? null; // opcional
            if ($id <= 0) {
                $mensaje = 'ID de usuario inválido.';
            } else {
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    if (!isset($_SESSION['sim_users']) || !is_array($_SESSION['sim_users'])) {
                        $mensaje = 'No hay usuarios simulados.';
                    } else {
                        $idx = false;
                        foreach ($_SESSION['sim_users'] as $i => $u) { if ((int)($u['id'] ?? 0) === $id) { $idx = $i; break; } }
                        if ($idx === false) {
                            $mensaje = 'Usuario no encontrado (modo simulado).';
                        } else {
                            if ($newRol !== '') $_SESSION['sim_users'][$idx]['rol'] = $newRol;
                            if ($newPass !== null && $newPass !== '') $_SESSION['sim_users'][$idx]['password'] = $newPass;
                            // sync map
                            $uname = $_SESSION['sim_users'][$idx]['username'];
                            if (!isset($_SESSION['sim_usuarios']) || !is_array($_SESSION['sim_usuarios'])) $_SESSION['sim_usuarios'] = [];
                            if ($newPass !== null && $newPass !== '') $_SESSION['sim_usuarios'][$uname]['clave'] = $newPass;
                            if ($newRol !== '') $_SESSION['sim_usuarios'][$uname]['rol'] = $newRol;
                            $mensaje = 'Modo ficticio: usuario simulado editado.';
                        }
                    }
                } else {
                    if ($newPass === '') {
                        $newPass = null; // no cambiar contraseña si campo vacío
                    }
                    // Validar rol
                    if ($newRol !== '' && !validarRol($newRol)) {
                        $mensaje = 'Rol no válido.';
                    } else {
                        $ok = updateUsuario($pdo, $id, $newPass, $newRol !== '' ? $newRol : null);
                        $mensaje = $ok ? 'Usuario actualizado.' : 'No se pudo actualizar el usuario.';
                    }
                }
            }
        }
    }
}

$usuarios = [];
if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
    // Preferir sesion backed usuarios simulados o que las creaciones/ediciones persistan
    if (!isset($_SESSION['sim_users']) || !is_array($_SESSION['sim_users'])) {
        // If legacy $pdo returned an array of users, initialize session from it
        if (is_array($pdo)) {
            $_SESSION['sim_users'] = [];
            $_SESSION['sim_usuarios'] = [];
            foreach ($pdo as $k => $v) {
                $id = isset($v['id']) ? (int)$v['id'] : (count($_SESSION['sim_users']) + 1);
                $rol = $v['rol'] ?? 'usuario';
                $pass = $v['clave'] ?? ($v['password'] ?? '');
                $entry = ['id' => $id, 'username' => $k, 'password' => $pass, 'rol' => $rol];
                $_SESSION['sim_users'][] = $entry;
                $_SESSION['sim_usuarios'][$k] = ['clave' => $pass, 'rol' => $rol];
            }
        } else {
            // asegurar que existan los arrays
            $_SESSION['sim_users'] = [];
            $_SESSION['sim_usuarios'] = [];
        }
    }

    // Build $usuarios desde la sesión para que la UI refleje los usuarios creados
    foreach ($_SESSION['sim_users'] as $u) {
        $usuarios[] = ['id' => $u['id'] ?? 0, 'username' => $u['username'] ?? '', 'rol' => $u['rol'] ?? 'usuario'];
    }
} else {
    if ($pdo instanceof PDO) {
        $usuarios = obtenerUsuarios($pdo);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel del Administrador</title>
    <link rel="stylesheet" href="css/estilos.css">
    <!-- Bootstrap CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--accent:#2563eb;--accent-2:#7c3aed}
        body{background:linear-gradient(180deg, rgba(37,99,235,0.06), #fff);min-height:100vh}
        .app-container{max-width:1100px;margin:28px auto;padding:20px}
        .card-spot{box-shadow:0 6px 18px rgba(15,23,42,0.06);border-radius:12px}
        .brand {font-weight:700;color:var(--accent)}
        .role-badge.administrador{background:#ff6b6b;color:#fff}
        .role-badge.tecnico{background:#ffd166;color:#000}
        .role-badge.operario{background:#6ee7b7;color:#084c41}
        .msg{padding:10px;border-radius:8px}
        .create-input{max-width:260px}
    </style>
</head>
<body>
<div class="app-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:22px">A</div>
            <div>
                <div class="brand">Panel del Administrador</div>
                <div class="text-muted small">Sesión: <?php echo htmlspecialchars($_SESSION['usuario']); ?></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="cerrar_sesion.php" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
        </div>
    </div>

    <?php if (!empty($errorMensaje)): ?>
        <div class="msg" style="background:#ffe6e6;color:#a00"><?php echo htmlspecialchars($errorMensaje); ?></div>
    <?php endif; ?>

    <?php if (!empty($mensaje)): ?>
        <div class="msg mb-3" style="background:#eef2ff;color:#1e3a8a"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-spot p-3">
                <h5 class="mb-3"><i class="bi bi-person-plus-fill text-primary"></i> Crear nuevo usuario</h5>
                <form method="post" class="d-flex flex-column gap-2">
                    <input type="hidden" name="accion" value="crear">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input id="username" name="username" type="text" class="form-control create-input" placeholder="Usuario" required>
                    <input id="password" name="password" type="password" class="form-control create-input" placeholder="Contraseña" required>
                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Crear</button>
                        <button type="reset" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpiar</button>
                    </div>
                </form>
                <div class="text-muted small mt-3">Los usuarios creados en modo ficticio se almacenan en la sesión del navegador.</div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-spot p-3">
                <h5 class="mb-3"><i class="bi bi-people-fill text-success"></i> Usuarios registrados</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th style="width:8%">ID</th><th>Usuario</th><th style="width:18%">Rol</th><th style="width:30%">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php if (count($usuarios) === 0): ?>
                                <tr><td colspan="4" class="text-center py-4">No hay usuarios registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($usuarios as $u): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($u['id']); ?></td>
                                        <td><?php echo htmlspecialchars($u['username']); ?></td>
                                        <td>
                                            <?php $r = $u['rol'] ?? 'usuario'; ?>
                                            <span class="badge role-badge <?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($u['username'] !== $_SESSION['usuario']): ?>
                                                <button class="btn btn-sm btn-danger btn-eliminar me-2" data-id="<?php echo htmlspecialchars($u['id']); ?>" data-username="<?php echo htmlspecialchars($u['username']); ?>"><i class="bi bi-trash"></i> Eliminar</button>

                                                <form method="post" class="d-inline-block me-1">
                                                    <input type="hidden" name="accion" value="editar">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($u['id']); ?>">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <select name="rol" class="form-select form-select-sm" style="width:140px">
                                                            <?php
                                                                $roles = ['administrador','tecnico','operario'];
                                                                foreach ($roles as $r) {
                                                                    $sel = ($u['rol'] ?? 'usuario') === $r ? 'selected' : '';
                                                                    echo '<option value="'.htmlspecialchars($r).'" '.$sel.'>'.htmlspecialchars($r).'</option>';
                                                                }
                                                            ?>
                                                        </select>
                                                        <input name="password_edit" type="password" placeholder="Nueva contraseña" class="form-control form-control-sm" style="width:180px">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i> Guardar</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">— (su cuenta)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>
        <!-- Modal de confirmación para eliminar -->
        <div class="modal fade" id="confirmEliminarModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirmar eliminación</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="confirmEliminarText"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button id="confirmEliminarBtn" type="button" class="btn btn-danger">Eliminar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulario oculto para realizar la eliminación con CSRF -->
        <form id="formEliminar" method="post" style="display:none">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="id" value="">
        </form>

        <!-- Bootstrap JS y pequeño script para manejar modal -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        <script>
                document.addEventListener('DOMContentLoaded', function() {
                        var eliminarModal = new bootstrap.Modal(document.getElementById('confirmEliminarModal'));
                        var eliminarText = document.getElementById('confirmEliminarText');
                        var confirmBtn = document.getElementById('confirmEliminarBtn');
                        var formEliminar = document.getElementById('formEliminar');

                        document.querySelectorAll('.btn-eliminar').forEach(function(btn) {
                                btn.addEventListener('click', function() {
                                        var id = this.getAttribute('data-id');
                                        var username = this.getAttribute('data-username');
                                        eliminarText.textContent = '¿Eliminar al usuario "' + username + '"? Esta acción no se puede deshacer.';
                                        confirmBtn.onclick = function() {
                                                formEliminar.querySelector('input[name="id"]').value = id;
                                                formEliminar.submit();
                                        };
                                        eliminarModal.show();
                                });
                        });
                });
        </script>
</body>
</html>
