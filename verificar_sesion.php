<?php
// Inicia la sesión solo si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cambia "usuario" por el nombre de tu variable de sesión si es necesario
if (empty($_SESSION["usuario"])) {
    // Redirige al formulario de inicio de sesión con mensaje de logout
    header("Location: Inicio_de_sesion.html?logout=1");
    exit;
}
// Aquí continúa el código protegido por sesión
?>