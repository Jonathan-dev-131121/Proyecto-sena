<?php
/**
 * Utilidades de Seguridad para SmartAqua Pro
 * Versión: 2.0
 * Fecha: 2025-09-20
 */

// Prevenir acceso directo
if (!defined('SMARTAQUA_ACCESS')) {
    http_response_code(403);
    exit('Acceso denegado');
}

class SecurityUtils {
    
    // Configuración de seguridad
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutos en segundos
    private const PASSWORD_MIN_LENGTH = 8;
    private const SESSION_TIMEOUT = 3600; // 1 hora
    
    /**
     * Valida la complejidad de una contraseña
     * 
     * @param string $password
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validatePasswordStrength(string $password): array {
        $errors = [];
        
        // Longitud mínima
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = "La contraseña debe tener al menos " . self::PASSWORD_MIN_LENGTH . " caracteres";
        }
        
        // Al menos una mayúscula
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Debe contener al menos una letra mayúscula";
        }
        
        // Al menos una minúscula
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Debe contener al menos una letra minúscula";
        }
        
        // Al menos un número
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Debe contener al menos un número";
        }
        
        // Al menos un caracter especial
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:"\\|,.<>\?]/', $password)) {
            $errors[] = "Debe contener al menos un caracter especial (!@#$%^&*...)";
        }
        
        // No debe contener espacios
        if (preg_match('/\s/', $password)) {
            $errors[] = "No debe contener espacios";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Genera un hash seguro de contraseña
     * 
     * @param string $password
     * @return string
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iteraciones
            'threads' => 3          // 3 hilos
        ]);
    }
    
    /**
     * Verifica una contraseña contra su hash
     * 
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    /**
     * Registra un intento de login fallido
     * 
     * @param string $username
     * @param string $ip
     * @return void
     */
    public static function logFailedLogin(string $username, string $ip): void {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'username' => $username,
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'type' => 'failed_login'
        ];
        
        $logFile = __DIR__ . '/security.log';
        $logLine = json_encode($logEntry) . PHP_EOL;
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Verifica si una IP está bloqueada por intentos fallidos
     * 
     * @param string $ip
     * @return bool
     */
    public static function isIpBlocked(string $ip): bool {
        $logFile = __DIR__ . '/security.log';
        
        if (!file_exists($logFile)) {
            return false;
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $failedAttempts = 0;
        $cutoffTime = time() - self::LOCKOUT_DURATION;
        
        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            
            if (!$entry || $entry['ip'] !== $ip) {
                continue;
            }
            
            $entryTime = strtotime($entry['timestamp']);
            
            if ($entryTime < $cutoffTime) {
                break; // Entradas más antiguas que el período de bloqueo
            }
            
            if ($entry['type'] === 'failed_login') {
                $failedAttempts++;
            } elseif ($entry['type'] === 'successful_login') {
                break; // Login exitoso resetea el contador
            }
        }
        
        return $failedAttempts >= self::MAX_LOGIN_ATTEMPTS;
    }
    
    /**
     * Registra un login exitoso
     * 
     * @param string $username
     * @param string $ip
     * @return void
     */
    public static function logSuccessfulLogin(string $username, string $ip): void {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'username' => $username,
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'type' => 'successful_login'
        ];
        
        $logFile = __DIR__ . '/security.log';
        $logLine = json_encode($logEntry) . PHP_EOL;
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Limpia y valida entrada de usuario
     * 
     * @param string $input
     * @param int $maxLength
     * @return string
     */
    public static function sanitizeInput(string $input, int $maxLength = 255): string {
        // Remover espacios en blanco al inicio y final
        $input = trim($input);
        
        // Convertir caracteres especiales a entidades HTML
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        // Limitar longitud
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }
    
    /**
     * Valida formato de email
     * 
     * @param string $email
     * @return bool
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Genera un token CSRF seguro
     * 
     * @return string
     */
    public static function generateCSRFToken(): string {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Verifica un token CSRF
     * 
     * @param string $token
     * @param string $sessionToken
     * @return bool
     */
    public static function verifyCSRFToken(string $token, string $sessionToken): bool {
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Verifica si la sesión ha expirado
     * 
     * @return bool
     */
    public static function isSessionExpired(): bool {
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        
        return (time() - $_SESSION['last_activity']) > self::SESSION_TIMEOUT;
    }
    
    /**
     * Actualiza el timestamp de actividad de la sesión
     * 
     * @return void
     */
    public static function updateSessionActivity(): void {
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Obtiene la dirección IP del cliente de forma segura
     * 
     * @return string
     */
    public static function getClientIP(): string {
        // Verificar por IP desde conexión compartida de internet
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        // Verificar por IP pasada desde proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        // Verificar por IP desde conexión remota
        elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        return 'unknown';
    }
    
    /**
     * Valida formato de nombre de usuario
     * 
     * @param string $username
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateUsername(string $username): array {
        $errors = [];
        
        // Longitud
        if (strlen($username) < 3) {
            $errors[] = "El nombre de usuario debe tener al menos 3 caracteres";
        }
        
        if (strlen($username) > 50) {
            $errors[] = "El nombre de usuario no puede exceder 50 caracteres";
        }
        
        // Solo letras, números y guiones bajos
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "El nombre de usuario solo puede contener letras, números y guiones bajos";
        }
        
        // No puede empezar con número
        if (preg_match('/^[0-9]/', $username)) {
            $errors[] = "El nombre de usuario no puede empezar con un número";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valida rol de usuario
     * 
     * @param string $role
     * @return bool
     */
    public static function validateRole(string $role): bool {
        $validRoles = ['administrador', 'tecnico', 'operario'];
        return in_array($role, $validRoles, true);
    }
}

/**
 * Funciones auxiliares para mantener compatibilidad
 */

/**
 * Función mejorada para limpiar datos
 * 
 * @param string $dato
 * @return string
 */
function limpiar_seguro(string $dato): string {
    return SecurityUtils::sanitizeInput($dato);
}

/**
 * Función para generar contraseñas seguras aleatorias
 * 
 * @param int $length
 * @return string
 */
function generarPasswordSegura(int $length = 12): string {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    
    return $password;
}