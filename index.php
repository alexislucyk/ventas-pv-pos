<?php
session_start();

// 1. GUARDIA DE SEGURIDAD: Si no hay sesión, redirigir al login
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// --- LÓGICA PARA OBTENER EL VALOR DEL DÓLAR (cURL y sintaxis antigua) ---
$valores_dolar = array(
    'oficial_compra' => 'N/D',
    'oficial_venta' => 'N/D',
    'blue_compra' => 'N/D',
    'blue_venta' => 'N/D',
);
$api_url = 'https://www.dolarsi.com/api/api.php?type=dolar';

// Verificar si cURL está habilitado antes de usarlo
if (function_exists('curl_init')) {
    $ch = curl_init(); 

    // 1. Configurar cURL
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // IMPORTANTE: En PHP antiguo, a menudo es necesario deshabilitar la verificación SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $json_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 && $json_data !== false) {
        $data = json_decode($json_data, true);

        if ($data) {
            foreach ($data as $item) {
                
                // SINTAXIS CORREGIDA: Usamos isset() con el operador ternario
                $nombre = isset($item['casa']['nombre']) ? $item['casa']['nombre'] : null;
                $compra = isset($item['casa']['compra']) ? $item['casa']['compra'] : 'N/D';
                $venta = isset($item['casa']['venta']) ? $item['casa']['venta'] : 'N/D';
                
                // Limpiar valores (la API a menudo usa , como decimal)
                // Se usa str_replace para reemplazar el punto y la coma por un punto para floatval
                $compra_float = (float)str_replace(',', '.', str_replace('.', '', $compra));
                $venta_float = (float)str_replace(',', '.', str_replace('.', '', $venta));
                
                if ($nombre === 'Dolar Oficial') {
                    $valores_dolar['oficial_compra'] = number_format($compra_float, 2, ',', '.');
                    $valores_dolar['oficial_venta']  = number_format($venta_float, 2, ',', '.');
                } elseif ($nombre === 'Dolar Blue') {
                    $valores_dolar['blue_compra'] = number_format($compra_float, 2, ',', '.');
                    $valores_dolar['blue_venta']  = number_format($venta_float, 2, ',', '.');
                }
            }
        }
    } else {
        // En caso de error de cURL o HTTP
        error_log("Fallo al consultar API: HTTP {$http_code} | Error cURL: {$curl_error}");
    }
} else {
    // FALLBACK con file_get_contents si cURL no está habilitado
    // Este método es menos robusto, pero puede funcionar si la API está accesible.
    $json_data = @file_get_contents($api_url);
    // (La lógica de parsing con file_get_contents requeriría la misma revisión de sintaxis)
}
// ----------------------------------------------

// Datos de la sesión para mostrar
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre']);
$rol = htmlspecialchars($_SESSION['usuario_rol']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Electricidad Lucyk</title>
    <link rel="stylesheet" href="css\style.css">
</head>
<body>

<?php include 'pages/sidebar.php'; ?>

<div class="content">
    <h1>Dashboard Principal</h1>

    <div class="card" style="border-left: 5px solid #00bcd4;">
        <h2 style="color: #00bcd4; margin-top: 0;">🪙 Cotización del Dólar en Argentina (Tiempo Real)</h2>
        <div style="display: flex; justify-content: space-around; text-align: center;">
            
            <div style="padding: 10px; border-right: 1px solid #444; flex-grow: 1;">
                <h3 style="margin: 0 0 5px 0; color: #aaa;">Oficial</h3>
                <p style="margin: 0;">Compra: <strong style="color: #4CAF50;">$<?php echo $valores_dolar['oficial_compra']; ?></strong></p>
                <p style="margin: 0;">Venta: <strong style="color: #f44336;">$<?php echo $valores_dolar['oficial_venta']; ?></strong></p>
            </div>
            
            <div style="padding: 10px; flex-grow: 1;">
                <h3 style="margin: 0 0 5px 0; color: #aaa;">Blue (Informal)</h3>
                <p style="margin: 0;">Compra: <strong style="color: #4CAF50;">$<?php echo $valores_dolar['blue_compra']; ?></strong></p>
                <p style="margin: 0;">Venta: <strong style="color: #f44336;">$<?php echo $valores_dolar['blue_venta']; ?></strong></p>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Resumen de Operaciones</h2>
        <p>Aquí se mostrarán los indicadores clave de tu negocio (ej. Ventas del día, Stock crítico, Cuentas por Cobrar).</p>
    </div>

    <p>Ahora puedes hacer clic en **"Productos"** para empezar a crear tu primera tabla de gestión.</p>
</div>

</body>
</html>