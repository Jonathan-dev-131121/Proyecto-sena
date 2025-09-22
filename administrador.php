<?php
session_start();

if (!isset($_SESSION['usuario']) || $_SESSION['tipo'] !== 'administrador') {
    header("Location: Inicio_de_sesion.html");
    exit;
}

// API: acciones para frontend
if (isset($_GET['api']) && $_GET['api'] == '1') {
    $action = $_GET['action'] ?? 'status';
    header('Content-Type: application/json; charset=UTF-8');
    
    if ($action === 'status') {
        echo json_encode(['status' => 'ok', 'user' => $_SESSION['usuario'], 'role' => $_SESSION['tipo']]);
        exit;
    }
    
    if ($action === 'users') {
        // Obtener lista de usuarios
        require_once 'seguridad/conexion.php';
        require_once 'seguridad/funciones.php';
        
        $usuarios = [];
        if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
            ensureSimUsersInSession();
            foreach ($_SESSION['sim_users'] as $u) {
                $usuarios[] = [
                    'id' => $u['id'] ?? 0,
                    'usuario' => $u['username'] ?? ($u['usuario'] ?? ''),
                    'tipo_usuario' => $u['rol'] ?? ($u['tipo_usuario'] ?? 'usuario'),
                ];
            }
        } else {
            $pdo = dbConnect();
            if ($pdo instanceof PDO) {
                $usuarios = obtenerUsuarios($pdo);
            }
        }
        echo json_encode(['users' => $usuarios]);
        exit;
    }
    
    if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once 'seguridad/conexion.php';
        require_once 'seguridad/funciones.php';
        
        $json = json_decode(file_get_contents('php://input'), true);
        $user = limpiar($json['username'] ?? '');
        $pass = $json['password'] ?? '';
        $role = $json['role'] ?? '';
        
        if ($user === '' || $pass === '' || $role === '') {
            echo json_encode(['status' => 'error', 'message' => 'Usuario, contraseña y rol son obligatorios.']);
            exit;
        }
        
        if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
            ensureSimUsersInSession();
            
            $exists = false;
            foreach ($_SESSION['sim_users'] as $u) {
                if (($u['username'] ?? '') === $user) { 
                    $exists = true; 
                    break; 
                }
            }
            
            if ($exists) {
                echo json_encode(['status' => 'error', 'message' => 'El usuario ya existe.']);
            } else {
                $max = 0; 
                foreach ($_SESSION['sim_users'] as $u) { 
                    if (($u['id'] ?? 0) > $max) $max = $u['id']; 
                }
                $newId = $max + 1;
                $new = ['id' => $newId, 'username' => $user, 'password' => $pass, 'rol' => $role];
                $_SESSION['sim_users'][] = $new;
                $_SESSION['sim_usuarios'][$user] = ['clave' => $pass, 'rol' => $role];
                saveSimUsers($_SESSION['sim_usuarios']);
                echo json_encode(['status' => 'ok', 'message' => 'Usuario creado correctamente.']);
            }
        } else {
            $pdo = dbConnect();
            if (usuarioExiste($pdo, $user)) {
                echo json_encode(['status' => 'error', 'message' => 'El usuario ya existe.']);
            } else {
                $ok = registrarUsuario($pdo, $user, $pass, $role);
                echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Usuario creado correctamente.' : 'Error al crear el usuario.']);
            }
        }
        exit;
    }
    
    if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        require_once 'seguridad/conexion.php';
        require_once 'seguridad/funciones.php';
        
        $json = json_decode(file_get_contents('php://input'), true);
        $target = $json['id'] ?? $json['usuario'] ?? '';
        
        if ($target === '') {
            echo json_encode(['status' => 'error', 'message' => 'ID o usuario no proporcionado.']);
            exit;
        }
        
        $isId = is_numeric($target);
        
        if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
            ensureSimUsersInSession();
            
            $idx = false;
            foreach ($_SESSION['sim_users'] as $i => $u) {
                if ($isId) {
                    if ((int)($u['id'] ?? 0) === (int)$target) { $idx = $i; break; }
                } else {
                    if (($u['username'] ?? '') === (string)$target) { $idx = $i; break; }
                }
            }
            
            if ($idx === false) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado.']);
            } else {
                if (($_SESSION['sim_users'][$idx]['username'] ?? '') === ($_SESSION['usuario'] ?? '')) {
                    echo json_encode(['status' => 'error', 'message' => 'No puede eliminar su propia cuenta.']);
                } else {
                    $delUser = $_SESSION['sim_users'][$idx]['username'];
                    array_splice($_SESSION['sim_users'], $idx, 1);
                    if (isset($_SESSION['sim_usuarios'][$delUser])) unset($_SESSION['sim_usuarios'][$delUser]);
                    saveSimUsers($_SESSION['sim_usuarios']);
                    echo json_encode(['status' => 'ok', 'message' => 'Usuario eliminado correctamente.']);
                }
            }
        } else {
            $pdo = dbConnect();
            $ok = eliminarUsuario($pdo, $isId ? (int)$target : (string)$target);
            echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Usuario eliminado.' : 'No se pudo eliminar el usuario.']);
        }
        exit;
    }
    
    if ($action === 'update_user' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        require_once 'seguridad/conexion.php';
        require_once 'seguridad/funciones.php';
        
        $json = json_decode(file_get_contents('php://input'), true);
        $target = $json['id'] ?? $json['usuario'] ?? '';
        $newRol = limpiar($json['tipo_usuario'] ?? $json['rol'] ?? '');
        $newPass = $json['password'] ?? null;
        
        if ($target === '') {
            echo json_encode(['status' => 'error', 'message' => 'ID o usuario no proporcionado.']);
            exit;
        }
        
        $isId = is_numeric($target);
        
        if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
            ensureSimUsersInSession();
            
            $idx = false;
            foreach ($_SESSION['sim_users'] as $i => $u) {
                if ($isId) {
                    if ((int)($u['id'] ?? 0) === (int)$target) { $idx = $i; break; }
                } else {
                    if (($u['username'] ?? '') === (string)$target) { $idx = $i; break; }
                }
            }
            
            if ($idx === false) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado.']);
            } else {
                if ($newRol !== '') $_SESSION['sim_users'][$idx]['rol'] = $newRol;
                if ($newPass !== null && $newPass !== '') $_SESSION['sim_users'][$idx]['password'] = $newPass;
                
                $uname = $_SESSION['sim_users'][$idx]['username'];
                if (!isset($_SESSION['sim_usuarios']) || !is_array($_SESSION['sim_usuarios'])) $_SESSION['sim_usuarios'] = [];
                if ($newPass !== null && $newPass !== '') $_SESSION['sim_usuarios'][$uname]['clave'] = $newPass;
                if ($newRol !== '') $_SESSION['sim_usuarios'][$uname]['rol'] = $newRol;
                
                saveSimUsers($_SESSION['sim_usuarios']);
                echo json_encode(['status' => 'ok', 'message' => 'Usuario actualizado correctamente.']);
            }
        } else {
            $pdo = dbConnect();
            $idForUpdate = null;
            if ($isId) {
                $idForUpdate = (int)$target;
            } else {
                $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE usuario = :usuario');
                $stmt->bindParam(':usuario', $target, PDO::PARAM_STR);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $idForUpdate = $row['usuario'] ?? null;
            }
            
            if ($idForUpdate === null) {
                echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado.']);
            } else {
                if ($newPass === '') $newPass = null;
                if ($newRol !== '' && !validarRol($newRol)) {
                    echo json_encode(['status' => 'error', 'message' => 'Rol no válido.']);
                } else {
                    $ok = updateUsuario($pdo, $idForUpdate, $newPass, $newRol !== '' ? $newRol : null);
                    echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Usuario actualizado.' : 'No se pudo actualizar el usuario.']);
                }
            }
        }
        exit;
    }
    
    // Si no coincide ninguna acción válida
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
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
            $role = $_POST['role'] ?? ''; 
            if ($user === '' || $pass === '' || $role === '') {
                $mensaje = 'Usuario, contraseña y rol son obligatorios.';
            } else {
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    // Inicializar estructuras si no existen
                    ensureSimUsersInSession();

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
                        $new = ['id' => $newId, 'username' => $user, 'password' => $pass, 'rol' => $role];
                        $_SESSION['sim_users'][] = $new;
                        // Añadir al mapa de login
                        $_SESSION['sim_usuarios'][$user] = ['clave' => $pass, 'rol' => $role];
                        // Persistir en disco
                        saveSimUsers($_SESSION['sim_usuarios']);
                        $mensaje = 'Modo ficticio: usuario simulado creado y guardado.';
                    }
                } else {
                    if (usuarioExiste($pdo, $user)) {
                        $mensaje = 'El usuario ya existe.';
                    } else {
                        // pasar el rol seleccionado al registrarUsuario
                        $ok = registrarUsuario($pdo, $user, $pass, $role);
                        $mensaje = $ok ? 'Usuario creado correctamente.' : 'Error al crear el usuario.';
                    }
                }
            }
        } elseif ($accion === 'eliminar') {
            // aceptar tanto id numérico (input 'id') como usuario (input 'usuario')
            $target = $_POST['id'] ?? $_POST['usuario'] ?? '';
            if ($target === '') {
                $mensaje = 'ID o usuario no proporcionado.';
            } else {
                // si target es numérico usar id, si no usar username
                $isId = is_numeric($target);
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    ensureSimUsersInSession();
                    $idx = false;
                    foreach ($_SESSION['sim_users'] as $i => $u) {
                        if ($isId) {
                            if ((int)($u['id'] ?? 0) === (int)$target) { $idx = $i; break; }
                        } else {
                            if (($u['username'] ?? '') === (string)$target) { $idx = $i; break; }
                        }
                    }
                    if ($idx === false) {
                        $mensaje = 'Usuario no encontrado (modo simulado).';
                    } else {
                        // evitar eliminar al último admin
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
                                // Persistir cambios
                                saveSimUsers($_SESSION['sim_usuarios']);
                                $mensaje = 'Modo ficticio: usuario simulado eliminado y guardado.';
                            }
                        }
                    }
                } else {
                    // modo real: la función eliminarUsuario acepta usuario o id
                    $ok = eliminarUsuario($pdo, $isId ? (int)$target : (string)$target);
                    $mensaje = $ok ? 'Usuario eliminado.' : 'No se pudo eliminar el usuario (¿último admin?).';
                }
            }
        } elseif ($accion === 'editar') {
            // aceptar id o usuario
            $target = $_POST['id'] ?? $_POST['usuario'] ?? '';
            $newRol = limpiar($_POST['tipo_usuario'] ?? $_POST['rol'] ?? '');
            $newPass = $_POST['password_edit'] ?? null; // opcional
            if ($target === '') {
                $mensaje = 'ID o usuario no proporcionado.';
            } else {
                $isId = is_numeric($target);
                if (defined('MODO_FICTICIO') && MODO_FICTICIO) {
                    require_once __DIR__ . '/seguridad/funciones.php';
                    ensureSimUsersInSession();
                    $idx = false;
                    foreach ($_SESSION['sim_users'] as $i => $u) {
                        if ($isId) {
                            if ((int)($u['id'] ?? 0) === (int)$target) { $idx = $i; break; }
                        } else {
                            if (($u['username'] ?? '') === (string)$target) { $idx = $i; break; }
                        }
                    }
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
                        // Persistir cambios
                        saveSimUsers($_SESSION['sim_usuarios']);
                        $mensaje = 'Modo ficticio: usuario simulado editado y guardado.';
                    }
                } else {
                    // modo real: si target es usuario (string) obtener id antes de update
                    $idForUpdate = null;
                    if ($isId) {
                        $idForUpdate = (int)$target;
                    } else {
                        $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE usuario = :usuario');
                        $stmt->bindParam(':usuario', $target, PDO::PARAM_STR);
                        $stmt->execute();
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $idForUpdate = $row['usuario'] ?? null;
                    }

                    if ($idForUpdate === null) {
                        $mensaje = 'Usuario no encontrado (DB).';
                    } else {
                        if ($newPass === '') $newPass = null;
                        if ($newRol !== '' && !validarRol($newRol)) {
                            $mensaje = 'Rol no válido.';
                        } else {
                            $ok = updateUsuario($pdo, $idForUpdate, $newPass, $newRol !== '' ? $newRol : null);
                            $mensaje = $ok ? 'Usuario actualizado.' : 'No se pudo actualizar el usuario.';
                        }
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
                $rol = $v['rol'] ?? ($v['tipo_usuario'] ?? 'usuario');
                $pass = $v['clave'] ?? ($v['password'] ?? '');
                $entry = [
                    'id' => $id,
                    'username' => $k,
                    'usuario' => $k,
                    'password' => $pass,
                    'rol' => $rol,
                    'tipo_usuario' => $rol,
                ];
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
    // Normalizar claves para que la plantilla espere `usuario` y `tipo_usuario` (compatibilidad con DB)
    foreach ($_SESSION['sim_users'] as $u) {
        $usuarios[] = [
            'id' => $u['id'] ?? 0,
            'usuario' => $u['username'] ?? ($u['usuario'] ?? ''),
            'tipo_usuario' => $u['rol'] ?? ($u['tipo_usuario'] ?? 'usuario'),
            // mantener compatibilidad con otros lugares que usan 'rol' o 'username'
            'rol' => $u['rol'] ?? ($u['tipo_usuario'] ?? 'usuario'),
            'username' => $u['username'] ?? ($u['usuario'] ?? ''),
        ];
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
    <link rel="stylesheet" href="css/comun.css">
    <link rel="stylesheet" href="css/estilos.css">
    <link rel="stylesheet" href="css/administrador.css">

    <!-- Bootstrap CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
     

</head>
<body class="has-hero">
<div class="app-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:54px;height:54px;font-size:22px">A</div>
            <div>
                    <div class="brand">Panel del Administrador</div>
                    <div class="text-muted">Sesión: <?php echo htmlspecialchars($_SESSION['usuario']); ?></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="cerrar_sesion.php" class="btn btn-primary-ghost btn-primary"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
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
                    <select id="role" name="role" class="form-select create-input" required>
                        <option value="" disabled selected>Seleccionar rol</option>
                        <option value="administrador">administrador</option>
                        <option value="tecnico">técnico</option>
                        <option value="operario">operario</option>
                    </select>
                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Crear</button>
                        <button type="reset" class="btn btn-primary"><i class="bi bi-x-circle"></i> Limpiar</button>
                    </div>
                </form>
                <div class="texto">Gracias por registrarte en nuestro sistema.</div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-spot p-3">
                <h5 class="mb-3"><i class="bi bi-people-fill text-success"></i> Usuarios registrados</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th style="width:8%">Usuario</th><th style="width:18%">Rol</th><th style="width:30%">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php if (count($usuarios) === 0): ?>
                                <tr><td colspan="4" class="text-center py-4">No hay usuarios registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($usuarios as $u): ?>
                                    <tr>
                                        
                                        <td><?php echo htmlspecialchars($u['usuario']); ?></td>
                                        <td>
                                            <?php $r = $u['tipo_usuario'] ?? 'usuario'; ?>
                                            <span class="badge role-badge <?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></span>
                                        </td>
                                        <td>
                                            <?php if (($u['usuario'] ?? '') !== ($_SESSION['usuario'] ?? '')): ?>
                                                <button class="btn btn-sm btn-danger btn-eliminar me-2" data-id="<?php echo htmlspecialchars($u['usuario'] ?? ''); ?>" data-username="<?php echo htmlspecialchars($u['usuario'] ?? ''); ?>"><i class="bi bi-trash"></i> Eliminar</button>
                                                <form method="post" class="d-inline-block me-1">
                                                    <input type="hidden" name="accion" value="editar">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="usuario" value="<?php echo htmlspecialchars($u['usuario'] ?? ''); ?>">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <select name="tipo_usuario" class="form-select form-select-sm" style="width:140px">
                                                            <?php
                                                                $roles = ['administrador','tecnico','operario'];
                                                                foreach ($roles as $r) {
                                                                    $sel = ($u['tipo_usuario'] ?? 'usuario') === $r ? 'selected' : '';
                                                                    echo '<option value="'.htmlspecialchars($r).'" '.$sel.'>'.htmlspecialchars($r).'</option>';
                                                                }
                                                            ?>
                                                        </select>
                                                        <input name="password_edit" type="password" placeholder="Nueva contraseña" class="form-control form-control-sm" style="width:180px">
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <span class="texto"> --- (su cuenta)</span>
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