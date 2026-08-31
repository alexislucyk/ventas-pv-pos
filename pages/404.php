<?php
// pages/404.php - Página de error 404
// Se sirve cuando el router no encuentra una ruta coincidente.
// Requiere que helpers.php esté cargado (para usar url()).
if (!function_exists('route')) {
    require_once __DIR__ . '/../core/helpers.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página no encontrada</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/misc.css'); ?>">
</head>
<body class="page-404">
    <div class="not-found-container">
        <div class="error-code">
            <i class="fas fa-exclamation-triangle"></i>
            404
        </div>
        <h1>¡Ups! Página no encontrada</h1>
        <p>La URL solicitada no corresponde a ninguna ruta definida en el sistema.
           Verifica que la dirección sea correcta o utiliza el menú de navegación.</p>
        <a href="<?php echo url('/'); ?>" class="btn-home">
            <i class="fas fa-home"></i> Ir al Panel de Control
        </a>
        <div class="breadcrumb">
            URL solicitada: <code style="color:#ff5252;"><?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?></code>
        </div>
    </div>
</body>
</html>
