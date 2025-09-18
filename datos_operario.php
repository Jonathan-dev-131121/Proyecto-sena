<?php
session_start();
require_once 'seguridad/conexion.php';
require_once 'seguridad/funciones.php';

header('Content-Type: application/json; charset=utf-8');

// comprobación básica de sesión y rol
if (!isset($_SESSION['usuario']) || ($_SESSION['tipo'] ?? '') !== 'operario') {
    http_response_code(403);
    echo json_encode(['error' => 'acceso_denegado']);
    exit;
}

$pdo = dbConnect();
$modoFicticio = defined('MODO_FICTICIO') && MODO_FICTICIO;

// helper para clamp
$clamp = function($v, $min, $max) { return max($min, min($max, $v)); };

if ($modoFicticio) {
    // asegurar existencia
    if (!isset($_SESSION['sim_sensores'])) {
        $_SESSION['sim_sensores'] = ['nivel'=>50,'caudal'=>300,'ph'=>7.2];
    }
    if (!isset($_SESSION['sim_historia'])) {
        $_SESSION['sim_historia'] = [];
    }

    // simular cambios pequeños aleatorios
    $s = &$_SESSION['sim_sensores'];
    $s['nivel'] = $clamp($s['nivel'] + rand(-3,3), 0, 120); // permitir >100 para alarma
    $s['caudal'] = $clamp($s['caudal'] + rand(-30,30), 0, 2000);
    $s['ph'] = round($clamp($s['ph'] + (rand(-20,20)/100), 0, 14), 2);

    // append historia (mantener <= 48 puntos)
    $now = date('Y-m-d H:i:s');
    $_SESSION['sim_historia'][] = [
        'ts' => $now,
        'nivel' => (float)$s['nivel'],
        'caudal' => (float)$s['caudal'],
        'ph' => (float)$s['ph']
    ];
    if (count($_SESSION['sim_historia']) > 48) array_shift($_SESSION['sim_historia']);

    echo json_encode([
        'modo' => 'ficticio',
        'sensor' => $s,
        'historia' => array_slice($_SESSION['sim_historia'], -12)
    ]);
    exit;
}

// modo real: intentar leer últimas N filas de la BD
$result = [
    'modo' => 'real',
    'sensor' => ['nivel'=>0,'caudal'=>0,'ph'=>0],
    'historia' => []
];

if ($pdo instanceof PDO) {
    $tablesToTry = ['lecturas','sensores','sensor_lecturas'];
    $N = 12;
    foreach ($tablesToTry as $t) {
        try {
            // comprobar existencia
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :t LIMIT 1");
            $stmt->execute([':t' => $t]);
            if ($stmt->fetchColumn() === false) continue;

            $sql = "SELECT nivel, caudal, ph, COALESCE(timestamp, ts, created_at, now()) AS ts
                    FROM {$t} ORDER BY id DESC LIMIT :n";
            $q = $pdo->prepare($sql);
            $q->bindValue(':n', $N, PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($rows && count($rows)>0) {
                $rows = array_reverse($rows);
                foreach ($rows as $r) {
                    $result['historia'][] = [
                        'ts' => $r['ts'] ?? date('Y-m-d H:i:s'),
                        'nivel' => (float)($r['nivel'] ?? 0),
                        'caudal' => (float)($r['caudal'] ?? 0),
                        'ph' => (float)($r['ph'] ?? 0)
                    ];
                }
                $last = end($rows);
                $result['sensor'] = [
                    'nivel' => (float)($last['nivel'] ?? 0),
                    'caudal' => (float)($last['caudal'] ?? 0),
                    'ph' => (float)($last['ph'] ?? 0)
                ];
                break;
            }
        } catch (Exception $e) {
            // intentar siguiente tabla
        }
    }
}
echo json_encode($result);