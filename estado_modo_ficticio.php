<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');   

try {
    // Cargar configuración
    require_once __DIR__ . '/config_mejorado.php';
    
    $response = [
        'success' => true,
        'modo_ficticio' => MODO_FICTICIO,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (MODO_FICTICIO) {
        // Información del modo ficticio
        $response['modo'] = 'ficticio';
        $response['descripcion'] = 'Usando usuarios simulados desde JSON';
        
        // Cargar usuarios ficticios
        $simFile = __DIR__ . '/seguridad/sim_users.json';
        if (file_exists($simFile)) {
            $simUsers = json_decode(file_get_contents($simFile), true);
            if ($simUsers && isset($simUsers['users'])) {
                $response['usuarios_disponibles'] = count($simUsers['users']);
                $response['usuarios'] = array_map(function($user) {
                    return [
                        'usuario' => $user['usuario'],
                        'rol' => $user['rol']
                    ];
                }, $simUsers['users']);
            }
        }
        
        $response['credenciales_test'] = [
            'admin:1234 (administrador)',
            'tecnico:abcd (tecnico)', 
            'operario:xyz (operario)'
        ];
        
    } else {
        // Información del modo real  
        $response['modo'] = 'real';
        $response['descripcion'] = 'Usando base de datos PostgreSQL';
        $response['base_datos'] = [
            'host' => DB_HOST,
            'puerto' => DB_PORT,
            'base' => DB_NAME,
            'esquema' => DB_SCHEMA ?? 'proyecto sena'
        ];
        
        // Intentar obtener usuarios reales
        try {
            require_once __DIR__ . '/seguridad/conexion.php';
            $conexion = dbConnect();
            
            if ($conexion instanceof PDO) {
                $query = "SELECT usuario, tipo_usuario FROM usuarios ORDER BY tipo_usuario, usuario";
                $stmt = $conexion->prepare($query);
                $stmt->execute();
                $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response['usuarios_disponibles'] = count($usuarios);
                $response['usuarios'] = $usuarios;
                $response['conexion_db'] = 'exitosa';
                
                // Agrupar por rol
                $roles = [];
                foreach ($usuarios as $user) {
                    $rol = $user['tipo_usuario'];
                    if (!isset($roles[$rol])) {
                        $roles[$rol] = 0;
                    }
                    $roles[$rol]++;
                }
                $response['resumen_roles'] = $roles;
                
            } else {
                $response['conexion_db'] = 'fallida';
                $response['error'] = 'No se pudo establecer conexión con la base de datos';
            }
            
        } catch (Exception $e) {
            $response['conexion_db'] = 'error';
            $response['error'] = $e->getMessage();
        }
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>