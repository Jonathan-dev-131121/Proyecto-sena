<?php
session_start();
require_once __DIR__ . '/../conf.php';

$provided = $_GET['token'] ?? $_POST['token'] ?? null;
if (!defined('SIM_USERS_TOKEN') || SIM_USERS_TOKEN === '' || $provided !== SIM_USERS_TOKEN) {
    http_response_code(403);
    echo "Acceso denegado. Token inválido.";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Session ID: " . session_id() . "\n\n";
echo "\
_SESSION keys:\n";
foreach ($_SESSION as $k => $v) {
    echo "- $k => ";
    if (is_array($v) || is_object($v)) {
        print_r($v);
    } else {
        echo (string)$v . "\n";
    }
}

echo "\nSim_users (list):\n";
if (isset($_SESSION['sim_users'])) print_r($_SESSION['sim_users']); else echo "(no existe)\n";

echo "\nSim_usuarios (map):\n";
if (isset($_SESSION['sim_usuarios'])) print_r($_SESSION['sim_usuarios']); else echo "(no existe)\n";

?>