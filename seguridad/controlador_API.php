<?php
/**
 * Clase para manejo mejorado de APIs de SmartAqua Pro
 * Versión: 2.0
 * Fecha: 2025-09-20
 */

// Prevenir acceso directo
if (!defined('SMARTAQUA_ACCESS')) {
    http_response_code(403);
    exit('Acceso denegado');
}

class ApiHandler {
    
    private const API_VERSION = '2.0';
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE'];
    
    /**
     * Estructura estándar de respuesta API
     */
    public static function jsonResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-API-Version: ' . self::API_VERSION);
        header('X-Powered-By: SmartAqua-Pro');
        
        $response = [
            'timestamp' => date('Y-m-d\TH:i:s\Z'),
            'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'error',
            'api_version' => self::API_VERSION,
            'data' => $data
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Respuesta de error estandarizada
     */
    public static function errorResponse(string $message, int $statusCode = 400, array $details = []): void {
        $data = [
            'error' => [
                'message' => $message,
                'code' => $statusCode,
                'details' => $details
            ]
        ];
        
        self::jsonResponse($data, $statusCode);
    }
    
    /**
     * Valida método HTTP
     */
    public static function validateMethod(string $expectedMethod): bool {
        $currentMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return $currentMethod === strtoupper($expectedMethod);
    }
    
    /**
     * Valida token de API
     */
    public static function validateApiToken(): bool {
        $token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        
        if (!$token) {
            self::errorResponse('Token de API requerido', 401);
            return false;
        }
        
        // Verificar token contra configuración
        if (!defined('API_TOKEN') || $token !== API_TOKEN) {
            self::errorResponse('Token de API inválido', 403);
            return false;
        }
        
        return true;
    }
    
    /**
     * Valida permisos de usuario para una acción específica
     */
    public static function validatePermissions(string $requiredPermission): bool {
        if (!isset($_SESSION['usuario'], $_SESSION['tipo'])) {
            self::errorResponse('Sesión requerida', 401);
            return false;
        }
        
        $userRole = $_SESSION['tipo'];
        $permissions = self::getRolePermissions($userRole);
        
        if (!in_array($requiredPermission, $permissions)) {
            self::errorResponse('Permisos insuficientes', 403);
            return false;
        }
        
        return true;
    }
    
    /**
     * Obtiene permisos por rol
     */
    private static function getRolePermissions(string $role): array {
        $rolePermissions = [
            'administrador' => ['create', 'read', 'update', 'delete', 'manage_users', 'view_reports', 'manage_tickets'],
            'tecnico' => ['read', 'update', 'manage_tickets', 'view_reports'],
            'operario' => ['read', 'create_readings', 'view_sensors']
        ];
        
        return $rolePermissions[$role] ?? [];
    }
    
    /**
     * Valida parámetros requeridos
     */
    public static function validateRequiredParams(array $required, array $data): bool {
        $missing = [];
        
        foreach ($required as $param) {
            if (!isset($data[$param]) || $data[$param] === '') {
                $missing[] = $param;
            }
        }
        
        if (!empty($missing)) {
            self::errorResponse(
                'Parámetros requeridos faltantes',
                400,
                ['missing_parameters' => $missing]
            );
            return false;
        }
        
        return true;
    }
    
    /**
     * Limita la velocidad de peticiones por IP
     */
    public static function rateLimitCheck(int $maxRequests = 100, int $windowSeconds = 3600): bool {
        $ip = SecurityUtils::getClientIP();
        $rateLimitFile = __DIR__ . '/rate_limit.json';
        
        // Cargar datos existentes
        $data = [];
        if (file_exists($rateLimitFile)) {
            $json = file_get_contents($rateLimitFile);
            $data = json_decode($json, true) ?: [];
        }
        
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        // Limpiar registros antiguos
        if (isset($data[$ip])) {
            $data[$ip] = array_filter($data[$ip], function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            });
        } else {
            $data[$ip] = [];
        }
        
        // Verificar límite
        if (count($data[$ip]) >= $maxRequests) {
            self::errorResponse('Límite de velocidad excedido', 429, [
                'retry_after' => $windowSeconds,
                'limit' => $maxRequests,
                'window' => $windowSeconds
            ]);
            return false;
        }
        
        // Registrar petición actual
        $data[$ip][] = $now;
        
        // Guardar datos
        file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
        
        return true;
    }
    
    /**
     * Obtiene datos de entrada JSON de forma segura
     */
    public static function getJsonInput(): array {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::errorResponse('JSON inválido', 400, ['json_error' => json_last_error_msg()]);
            return [];
        }
        
        return $data ?: [];
    }
    
    /**
     * Registra actividad de la API
     */
    public static function logApiActivity(string $endpoint, array $params = []): void {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => SecurityUtils::getClientIP(),
            'user' => $_SESSION['usuario'] ?? 'anonymous',
            'endpoint' => $endpoint,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'params' => $params
        ];
        
        $logFile = __DIR__ . '/api_activity.log';
        $logLine = json_encode($logEntry) . PHP_EOL;
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Middleware para validaciones automáticas de API
 */
function inicializarApiSegura(?string $requiredPermission = null, bool $requireToken = false): bool {
    // Verificar rate limiting
    if (!ApiHandler::rateLimitCheck()) {
        return false;
    }
    
    // Verificar token si es requerido
    if ($requireToken && !ApiHandler::validateApiToken()) {
        return false;
    }
    
    // Verificar permisos si se especifican
    if ($requiredPermission && !ApiHandler::validatePermissions($requiredPermission)) {
        return false;
    }
    
    // Verificar sesión activa
    if (SecurityUtils::isSessionExpired()) {
        session_destroy();
        ApiHandler::errorResponse('Sesión expirada', 401);
        return false;
    }
    
    // Actualizar actividad de sesión
    SecurityUtils::updateSessionActivity();
    
    return true;
}