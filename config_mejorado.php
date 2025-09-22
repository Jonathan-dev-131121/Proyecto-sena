<?php
/**
 * Versión: 2.1 - Corregida
 * Fecha: 2025-09-20
 * Cambios: Solucionados problemas de constantes duplicadas, sesiones y headers
 */

// Evitar inclusión múltiple
if (defined('SMARTAQUA_CONFIG_LOADED')) {
    return;
}
define('SMARTAQUA_CONFIG_LOADED', true);

// Configurar errores al inicio (antes que cualquier otra cosa)
$modoDesarrollo = true; // Por defecto en desarrollo
if (isset($_ENV['MODO_DESARROLLO'])) {
    $modoDesarrollo = filter_var($_ENV['MODO_DESARROLLO'], FILTER_VALIDATE_BOOLEAN);
}

if ($modoDesarrollo) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/seguridad/php_errors.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/seguridad/php_errors.log');
}

// Definir constante de acceso principal (solo si no está definida)
if (!defined('SMARTAQUA_ACCESS')) {
    define('SMARTAQUA_ACCESS', true);
}

// Configuración de sesión ANTES de cualquier output o headers
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
}

// Configuración de zona horaria
date_default_timezone_set('America/Bogota');

// Límites de rendimiento
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 30);

/**
 * Función para obtener configuración del entorno
 */
if (!function_exists('getEnvConfig')) {
    function getEnvConfig(string $key, $default = null) {
        $envFile = __DIR__ . '/.env';
        static $envConfig = null;
        
        if ($envConfig === null) {
            $envConfig = [];
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '=') !== false && strpos(trim($line), '#') !== 0) {
                        list($envKey, $envValue) = explode('=', $line, 2);
                        $envConfig[trim($envKey)] = trim($envValue);
                    }
                }
            }
        }
        
        return $envConfig[$key] ?? $default;
    }
}

// Cargar configuración del archivo .env si existe
if (!defined('MODO_FICTICIO')) {
    $modoFicticio = getEnvConfig('MODO_FICTICIO', 'true'); // Por defecto en modo ficticio
    define('MODO_FICTICIO', filter_var($modoFicticio, FILTER_VALIDATE_BOOLEAN));
}

if (!defined('MODO_DESARROLLO')) {
    define('MODO_DESARROLLO', $modoDesarrollo);
}

// Configuración de seguridad (después de cargar .env)
if (!defined('API_TOKEN')) {
    define('API_TOKEN', 'smartaqua_pro_2025_' . hash('sha256', 'mi_secreto_super_seguro'));
}

if (!defined('SIM_USERS_TOKEN')) {
    define('SIM_USERS_TOKEN', hash('sha256', 'simulacion_token_' . date('Y-m-d')));
}

// Configuración de base de datos (mantener existente)
if (!defined('DB_USERS_TABLE')) define('DB_USERS_TABLE', 'usuarios');
if (!defined('DB_USER_COL_USERNAME')) define('DB_USER_COL_USERNAME', 'usuario');
if (!defined('DB_USER_COL_PASSWORD')) define('DB_USER_COL_PASSWORD', 'clave');
if (!defined('DB_USER_COL_ROL')) define('DB_USER_COL_ROL', 'tipo_usuario');

// Configuración de conexión a base de datos PostgreSQL
if (!defined('DB_HOST')) define('DB_HOST', getEnvConfig('DB_HOST', 'localhost'));
if (!defined('DB_PORT')) define('DB_PORT', getEnvConfig('DB_PORT', '5432'));
if (!defined('DB_NAME')) define('DB_NAME', getEnvConfig('DB_NAME', 'smartaqua_pro'));
if (!defined('DB_USER')) define('DB_USER', getEnvConfig('DB_USER', 'postgres'));
if (!defined('DB_PASS')) define('DB_PASS', getEnvConfig('DB_PASS', ''));
if (!defined('DB_SCHEMA')) define('DB_SCHEMA', getEnvConfig('DB_SCHEMA', 'proyecto sena'));

// Headers de seguridad (solo si no se han enviado headers previamente)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Incluir archivos de funciones mejoradas con manejo de errores
$archivos_requeridos = [
    'funciones.php' => __DIR__ . '/seguridad/funciones.php',
    'seguridad.php' => __DIR__ . '/seguridad/seguridad.php', 
    'controlador_API.php' => __DIR__ . '/seguridad/controlador_API.php'
];

foreach ($archivos_requeridos as $nombre => $ruta) {
    try {
        if (!file_exists($ruta)) {
            throw new Exception("Archivo requerido no encontrado: $nombre en $ruta");
        }
        
        if (!is_readable($ruta)) {
            throw new Exception("Archivo no legible: $nombre en $ruta");
        }
        
        // Verificar que no se incluya dos veces
        if (!in_array($ruta, get_included_files())) {
            require_once $ruta;
        }
        
    } catch (Error $e) {
        $error_msg = "Error fatal al cargar $nombre: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine();
        
        if (defined('MODO_DESARROLLO') && MODO_DESARROLLO) {
            die($error_msg);
        } else {
            error_log($error_msg);
            http_response_code(500);
            die('Error interno del servidor');
        }
        
    } catch (Exception $e) {                                            
        $error_msg = "Excepción al cargar $nombre: " . $e->getMessage();
        
        if (defined('MODO_DESARROLLO') && MODO_DESARROLLO) {
            die($error_msg);
        } else {
            error_log($error_msg);
            http_response_code(500);
            die('Error interno del servidor');
        }
    }
}

// Verificar que las clases principales estén disponibles
$clases_requeridas = ['SecurityUtils', 'ApiHandler'];
foreach ($clases_requeridas as $clase) {
    if (!class_exists($clase)) {
        $error_msg = "Clase requerida no encontrada: $clase";
        
        if (defined('MODO_DESARROLLO') && MODO_DESARROLLO) {
            die($error_msg);
        } else {
            error_log($error_msg);
            http_response_code(500);
            die('Error interno del servidor');
        }
    }
}

// Mensaje de éxito en modo desarrollo (solo en CLI y cuando no es request AJAX)
if (defined('MODO_DESARROLLO') && MODO_DESARROLLO && php_sapi_name() === 'cli' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    echo "✓ SmartAqua Pro configuración cargada exitosamente\n";
}
?>