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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: radial-gradient(circle at top, #1a1a2e 0%, #121212 100%);
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
        }
        .not-found-container {
            text-align: center;
            max-width: 560px;
            padding: 40px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            color: transparent;
            background: linear-gradient(135deg, #e74c3c 0%, #ff9800 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .error-code i {
            font-size: 5rem;
            display: block;
            margin-bottom: 10px;
            opacity: 0.3;
        }
        h1 {
            font-size: 1.8rem;
            color: #fff;
            margin: 20px 0;
        }
        p {
            color: #888;
            font-size: 1.05rem;
            line-height: 1.7;
            margin-bottom: 30px;
        }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #00bcd4 0%, #008ba3 100%);
            color: #000;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: 1px solid #333;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 188, 212, 0.3);
        }
        .breadcrumb {
            margin-top: 30px;
            font-size: 0.85rem;
            color: #555;
        }
    </style>
</head>
<body>
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
