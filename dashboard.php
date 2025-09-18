    <?php
    session_start();
    if (!isset($_SESSION["usuario"])) {
        header(header: "Location: Inicio_de_sesion.html?logout=1");
        exit;
    }

    $usuario = htmlspecialchars($_SESSION["usuario"]);
    $rol = htmlspecialchars($_SESSION["tipo"]);
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Dashboard | SmartAqua Pro</title>
        <link rel="stylesheet" href="css/estilos.css">
    </head>
    <body>
        <nav class="navegacion">
            <a href="INDEX.html">Inicio</a> |
            <a href="Conocenos.html">Conócenos</a> |
            <a href="Lo_mas_nuevo.html">Lo más nuevo</a>
        </nav>

        <main>
            <section class="bienvenida">
                <h1>Bienvenido, <?php echo $usuario; ?> a SmartAqua Pro</h1>
                <p>Tu rol es: <strong><?php echo $rol; ?></strong></p>
                <a href="cerrar_sesion.php" class="boton-salir">Cerrar sesión</a>
            </section>
        </main>
    </body>
    </html>
