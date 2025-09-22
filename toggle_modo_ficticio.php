<?php
// toggle_modo_ficticio.php
// Endpoint para activar/desactivar el modo ficticio    

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    
    // Leer datos JSON
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['modo_ficticio'])) {
        throw new Exception('Datos inválidos');
    }
    
    $nuevoModo = (bool) $input['modo_ficticio'];
    
    // Actualizar archivo .env
    $envPath = __DIR__ . '/.env';
    $envUpdated = false;
    
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        $lineas = explode(PHP_EOL, $envContent);
        $found = false;
        
        foreach ($lineas as $i => $linea) {
            if (preg_match('/^\s*MODO_FICTICIO\s*=/', $linea)) {
                $lineas[$i] = 'MODO_FICTICIO=' . ($nuevoModo ? 'true' : 'false');
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $lineas[] = 'MODO_FICTICIO=' . ($nuevoModo ? 'true' : 'false');
        }
        
        if (file_put_contents($envPath, implode(PHP_EOL, $lineas))) {
            $envUpdated = true;
        }
    }
    
    // Actualizar archivo config_mejorado.php si existe
    $configPath = __DIR__ . '/config_mejorado.php';
    $configUpdated = false;
    
    if (file_exists($configPath) && is_writable($configPath)) {
        $configContent = file_get_contents($configPath);
        
        // Buscar y reemplazar la definición de MODO_FICTICIO
        $pattern = "/define\s*\(\s*['\"]MODO_FICTICIO['\"]\s*,\s*(true|false)\s*\)/i";
        $replacement = "define('MODO_FICTICIO', " . ($nuevoModo ? 'true' : 'false') . ")";
        
        if (preg_match($pattern, $configContent)) {
            $newContent = preg_replace($pattern, $replacement, $configContent);
            if (file_put_contents($configPath, $newContent)) {
                $configUpdated = true;
            }
        }
    }
    
    // Respuesta de éxito - IMPORTANTE: devolver el estado real
    $response = [
        'success' => true,
        'modo_ficticio' => $nuevoModo, // Estado que se aplicó realmente
        'mensaje' => $nuevoModo ? 'Modo ficticio activado' : 'Modo real activado',
        'descripcion' => $nuevoModo ? 
            'Usando datos simulados para desarrollo y pruebas' : 
            'Conectando a base de datos PostgreSQL',
        'archivos_actualizados' => [
            'env' => $envUpdated,
            'config' => $configUpdated
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Si es modo ficticio, incluir usuarios de prueba
    if ($nuevoModo) {
        $response['usuarios_test'] = [
            'operario:123456',
            'admin:admin123', 
            'supervisor:super123'
        ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'modo_ficticio' => false, // Estado seguro por defecto
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>