<?php
/**
 * Diagnóstico completo del modo real
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DIAGNÓSTICO MODO REAL ===\n";

// 1. Verificar configuración
echo "1. Verificando configuración...\n";
try {
    require_once __DIR__ . '/config_mejorado.php';
    echo "   ✓ config_mejorado.php cargado\n";
    echo "   MODO_FICTICIO: " . (defined('MODO_FICTICIO') ? (MODO_FICTICIO ? 'true' : 'false') : 'NO DEFINIDO') . "\n";
    echo "   DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NO DEFINIDO') . "\n";
    echo "   DB_PORT: " . (defined('DB_PORT') ? DB_PORT : 'NO DEFINIDO') . "\n";
    echo "   DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'NO DEFINIDO') . "\n";
    echo "   DB_USER: " . (defined('DB_USER') ? DB_USER : 'NO DEFINIDO') . "\n";
    echo "   DB_PASS: " . (defined('DB_PASS') ? (DB_PASS ? 'CONFIGURADO' : 'VACÍO') : 'NO DEFINIDO') . "\n";
} catch (Exception $e) {
    echo "   ✗ Error al cargar config: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Verificar conexión PostgreSQL
echo "\n2. Verificando conexión PostgreSQL...\n";
try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    echo "   DSN: $dsn\n";
    echo "   Usuario: " . DB_USER . "\n";
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);
    
    echo "   ✓ Conexión a PostgreSQL exitosa\n";
    
    // Verificar versión de PostgreSQL
    $stmt = $pdo->query("SELECT version()");
    $version = $stmt->fetchColumn();
    echo "   Versión PostgreSQL: " . substr($version, 0, 50) . "...\n";
    
} catch (PDOException $e) {
    echo "   ✗ Error de conexión: " . $e->getMessage() . "\n";
    echo "   Código de error: " . $e->getCode() . "\n";
    
    // Sugerencias basadas en el error
    if (strpos($e->getMessage(), 'could not connect') !== false) {
        echo "\n   💡 SUGERENCIAS:\n";
        echo "   - Verificar que PostgreSQL esté ejecutándose\n";
        echo "   - Verificar que el puerto 5432 esté disponible\n";
        echo "   - Verificar la configuración de pg_hba.conf\n";
    }
    
    return;
}

// 3. Verificar esquema
echo "\n3. Verificando esquema...\n";
try {
    $schema = defined('DB_SCHEMA') ? DB_SCHEMA : 'proyecto sena';
    echo "   Esquema configurado: '$schema'\n";
    
    // Verificar si el esquema existe
    $stmt = $pdo->prepare("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?");
    $stmt->execute([$schema]);
    
    if ($stmt->rowCount() > 0) {
        echo "   ✓ Esquema '$schema' existe\n";
        
        // Configurar search_path
        $schemaQuoted = '"' . str_replace('"', '""', $schema) . '"';
        $pdo->exec("SET search_path TO $schemaQuoted, public;");
        echo "   ✓ search_path configurado\n";
        
    } else {
        echo "   ✗ Esquema '$schema' NO existe\n";
        
        // Listar esquemas disponibles
        $stmt = $pdo->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema', 'pg_catalog', 'pg_toast')");
        $schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "   Esquemas disponibles: " . implode(', ', $schemas) . "\n";
        
        return;
    }
    
} catch (PDOException $e) {
    echo "   ⚠ Warning al configurar esquema: " . $e->getMessage() . "\n";
}

// 4. Verificar tabla de usuarios
echo "\n4. Verificando tabla de usuarios...\n";
try {
    $table = defined('DB_USERS_TABLE') ? DB_USERS_TABLE : 'usuarios';
    echo "   Tabla configurada: '$table'\n";
    
    // Verificar si la tabla existe
    $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_name = ? AND table_schema = CURRENT_SCHEMA()");
    $stmt->execute([$table]);
    
    if ($stmt->rowCount() > 0) {
        echo "   ✓ Tabla '$table' existe\n";
        
        // Verificar estructura de la tabla
        $stmt = $pdo->prepare("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = ? AND table_schema = CURRENT_SCHEMA()");
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll();
        
        echo "   Columnas encontradas:\n";
        foreach ($columns as $col) {
            echo "     - {$col['column_name']} ({$col['data_type']}) " . ($col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
        }
        
        // Verificar columnas requeridas
        $colUser = defined('DB_USER_COL_USERNAME') ? DB_USER_COL_USERNAME : 'usuario';
        $colPass = defined('DB_USER_COL_PASSWORD') ? DB_USER_COL_PASSWORD : 'clave';
        $colRol = defined('DB_USER_COL_ROL') ? DB_USER_COL_ROL : 'tipo_usuario';
        
        $columnNames = array_column($columns, 'column_name');
        
        echo "   Verificando columnas requeridas:\n";
        echo "     - $colUser: " . (in_array($colUser, $columnNames) ? '✓' : '✗') . "\n";
        echo "     - $colPass: " . (in_array($colPass, $columnNames) ? '✓' : '✗') . "\n";
        echo "     - $colRol: " . (in_array($colRol, $columnNames) ? '✓' : '✗') . "\n";
        
    } else {
        echo "   ✗ Tabla '$table' NO existe\n";
        
        // Listar tablas disponibles
        $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = CURRENT_SCHEMA()");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "   Tablas disponibles en el esquema: " . implode(', ', $tables) . "\n";
        
        return;
    }
    
} catch (PDOException $e) {
    echo "   ✗ Error al verificar tabla: " . $e->getMessage() . "\n";
    return;
}

// 5. Verificar datos de usuarios
echo "\n5. Verificando datos de usuarios...\n";
try {
    $table = defined('DB_USERS_TABLE') ? DB_USERS_TABLE : 'usuarios';
    $colUser = defined('DB_USER_COL_USERNAME') ? DB_USER_COL_USERNAME : 'usuario';
    $colRol = defined('DB_USER_COL_ROL') ? DB_USER_COL_ROL : 'tipo_usuario';
    
    $stmt = $pdo->prepare("SELECT $colUser, $colRol FROM $table LIMIT 10");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "   Total de usuarios encontrados: " . count($users) . "\n";
    
    if (count($users) > 0) {
        echo "   Usuarios disponibles:\n";
        foreach ($users as $user) {
            echo "     - {$user[$colUser]} (rol: {$user[$colRol]})\n";
        }
    } else {
        echo "   ⚠ No hay usuarios en la tabla\n";
    }
    
} catch (PDOException $e) {
    echo "   ✗ Error al consultar usuarios: " . $e->getMessage() . "\n";
}

// 6. Test de conexión con dbConnect()
echo "\n6. Probando función dbConnect()...\n";
try {
    require_once __DIR__ . '/seguridad/conexion.php';
    
    // Temporalmente cambiar a modo real
    if (defined('MODO_FICTICIO')) {
        echo "   Forzando modo real para el test...\n";
    }
    
    $result = conectarReal(); // Llamar directamente a la función real
    
    if ($result instanceof PDO) {
        echo "   ✓ conectarReal() retornó PDO correctamente\n";
    } else {
        echo "   ✗ conectarReal() retornó: " . gettype($result) . "\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Error en dbConnect: " . $e->getMessage() . "\n";
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
?>