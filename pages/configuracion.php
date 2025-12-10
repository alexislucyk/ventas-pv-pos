<?php
// Asegúrate de incluir tu db_config.php aquí
// require '../db_config.php'; 
require '../config/db_config.php'; 
$mensaje = '';

// 1. Manejar el POST para guardar la configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['impresora_nombre'])) {
    $nuevo_nombre = trim($_POST['impresora_nombre']);
    
    // La clave siempre es 'nombre_impresora_ticket' y se usa el valor de $nuevo_nombre
    $sql_update = "
        INSERT INTO configuracion (clave, valor) VALUES ('nombre_impresora_ticket', :valor_insert)
        ON DUPLICATE KEY UPDATE valor = :valor_update
    ";
    $stmt = $pdo->prepare($sql_update);
    
    // ------------------------------------------------------------------------------------------------
    // 🎯 LÍNEA CORREGIDA: VINCULAMOS CADA PARÁMETRO NOMBRADO (:valor_insert y :valor_update)
    // ------------------------------------------------------------------------------------------------
    if ($stmt->execute([
        ':valor_insert' => $nuevo_nombre, 
        ':valor_update' => $nuevo_nombre
    ])) {
        $mensaje = "✅ Nombre de impresora guardado correctamente.";
    } else {
    // ------------------------------------------------------------------------------------------------
        $mensaje = "❌ Error al guardar la configuración.";
    }
}

// 2. Obtener el valor actual de la configuración
$sql_select = "SELECT valor FROM configuracion WHERE clave = 'nombre_impresora_ticket'";
$stmt = $pdo->query($sql_select);
$impresora_actual = $stmt->fetchColumn() ?: 'Impresora no configurada';

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <title>Configuración de Impresora</title>
</head>
<body>
    <h2>Configuración de Impresora de Tickets</h2>

    <?php if ($mensaje): ?>
        <p style="color: green; font-weight: bold;"><?php echo $mensaje; ?></p>
    <?php endif; ?>

    <p>El nombre actual de la impresora de tickets es: <strong><?php echo htmlspecialchars($impresora_actual); ?></strong></p>

    <form method="POST">
        <label for="impresora_nombre">Nombre exacto de la impresora en el SO:</label><br>
        <input type="text" id="impresora_nombre" name="impresora_nombre" 
               value="<?php echo htmlspecialchars($impresora_actual); ?>" required style="width: 300px;"><br><br>
        
        <small>Nota: Este nombre debe coincidir exactamente con el nombre instalado en Windows/Linux para que el software de impresión local lo reconozca.</small>
        
        <br><br>
        <button type="submit">Guardar Configuración</button>
    </form>
</body>
</html>