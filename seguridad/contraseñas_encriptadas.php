<?php
/**
 * Utilidades para hash de contraseñas
 * SmartAqua Pro - Sistema de Encriptación
 */

class PasswordUtils {
    
    /**
     * Encripta una contraseña usando password_hash
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verifica una contraseña contra su hash
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Encripta las contraseñas de los usuarios simulados
     */
    public static function encryptSimUsersPasswords($jsonFile) {
        if (!file_exists($jsonFile)) {
            throw new Exception("Archivo de usuarios simulados no encontrado: $jsonFile");
        }
        
        $users = json_decode(file_get_contents($jsonFile), true);
        if (!$users) {
            throw new Exception("Error al leer el archivo JSON");
        }
        
        $updated = false;
        
        foreach ($users as $username => &$userData) {
            // Saltar metadata
            if ($username === '_metadata') {
                continue;
            }
            
            // Si la clave no está hasheada (no empieza con $2y$)
            if (isset($userData['clave']) && !str_starts_with($userData['clave'], '$2y$')) {
                $plainPassword = $userData['clave'];
                $hashedPassword = self::hashPassword($plainPassword);
                
                // Actualizar la estructura
                $userData['clave'] = $hashedPassword;
                $userData['clave_original'] = $plainPassword; // Mantener para referencia
                $userData['password_encrypted'] = true;
                $userData['encryption_date'] = date('Y-m-d H:i:s');
                
                $updated = true;
                
                echo "✓ Contraseña encriptada para usuario: $username\n";
            } else if (isset($userData['clave']) && str_starts_with($userData['clave'], '$2y$')) {
                echo "• Usuario $username ya tiene contraseña encriptada\n";
            }
        }
        
        if ($updated) {
            // Actualizar metadata
            if (isset($users['_metadata'])) {
                $users['_metadata']['updated'] = date('c');
                $users['_metadata']['version'] = '2.1';
                $users['_metadata']['password_encryption'] = 'enabled';
            }
            
            // Escribir archivo actualizado
            $jsonContent = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($jsonFile, $jsonContent);
            
            echo "✓ Archivo actualizado con contraseñas encriptadas\n";
        } else {
            echo "• No se necesitaron actualizaciones\n";
        }
        
        return $updated;
    }
    
    /**
     * Crea un archivo de backup antes de encriptar
     */
    public static function createBackup($jsonFile) {
        $backupFile = $jsonFile . '.backup.' . date('Y-m-d_H-i-s');
        if (copy($jsonFile, $backupFile)) {
            echo "✓ Backup creado: $backupFile\n";
            return $backupFile;
        }
        throw new Exception("No se pudo crear el backup");
    }
}

// Si se ejecuta directamente
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    try {
        $simUsersFile = __DIR__ . '/sim_users.json';
        
        echo "=== ENCRIPTACIÓN DE CONTRASEÑAS SIMULADAS ===\n";
        echo "Archivo: $simUsersFile\n\n";
        
        // Crear backup
        PasswordUtils::createBackup($simUsersFile);
        
        // Encriptar contraseñas
        $updated = PasswordUtils::encryptSimUsersPasswords($simUsersFile);
        
        if ($updated) {
            echo "\n✅ Proceso completado exitosamente\n";
            echo "Las contraseñas ahora están encriptadas y listas para usar en login\n";
        } else {
            echo "\nℹ️ No se requirieron cambios\n";
        }
        
    } catch (Exception $e) {
        echo "\n❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>