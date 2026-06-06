<?php
include 'infosesion.php';
require '../config/db_config.php';

// Solo 'developer' o usuarios con permiso específico pueden entrar
if (!tiene_permiso('pages/configuracion.php')) {
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}

$mensaje = '';

// 1. PROCESAR GUARDADO DE CONFIGURACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_config'])) {
    try {
        $pdo->beginTransaction();
        
        // Recogemos los valores del formulario
        $configs = array(
            'ticket_ancho'           => $_POST['ticket_ancho'],
            'ticket_footer_msg'      => $_POST['ticket_footer_msg']
        );

        // Insertamos o actualizamos cada clave en la tabla 'configuracion'
        $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        
        foreach ($configs as $clave => $valor) {
            $stmt->execute(array($clave, $valor));
        }

        $pdo->commit();
        $mensaje = "✅ Configuración actualizada correctamente.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "❌ Error al guardar: " . $e->getMessage();
    }
}

// 2. OBTENER CONFIGURACIONES ACTUALES
$stmt = $pdo->query("SELECT clave, valor FROM configuracion");
$config_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Valores por defecto si no existen en la base de datos
$ticket_ancho    = isset($config_raw['ticket_ancho']) ? $config_raw['ticket_ancho'] : '80mm';
$ticket_msg      = isset($config_raw['ticket_footer_msg']) ? $config_raw['ticket_footer_msg'] : 'Gracias por su compra!';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .config-section-title { color: #00bcd4; border-bottom: 1px solid #333; padding-bottom: 10px; margin-bottom: 20px; font-size: 1.1rem; text-transform: uppercase; }
        .helper-text { font-size: 0.8rem; color: #888; margin-top: -10px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1><i class="fas fa-cogs"></i> Configuración del Sistema</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <!-- SECCIÓN IMPRESIÓN (Ancho limitado para mejor estética) -->
            <div class="card" style="max-width: 800px;">
                <h3 class="config-section-title"><i class="fas fa-print"></i> Impresión de Tickets</h3>
                
                <label>Ancho del Papel Térmico</label>
                <select name="ticket_ancho" class="input-field">
                    <option value="80mm" <?php echo $ticket_ancho == '80mm' ? 'selected' : ''; ?>>80mm (Estándar)</option>
                    <option value="58mm" <?php echo $ticket_ancho == '58mm' ? 'selected' : ''; ?>>58mm (Mini)</option>
                </select>
                <p class="helper-text">Afecta el diseño de la vista previa y el ticket impreso.</p>

                <label>Mensaje al Pie del Ticket</label>
                <input type="text" name="ticket_footer_msg" class="input-field" value="<?php echo htmlspecialchars($ticket_msg); ?>">
            </div>

            <div style="margin-top: 30px; display: flex; justify-content: flex-start; max-width: 800px;">
                <button type="submit" name="guardar_config" class="btn btn-primary" style="padding: 15px 40px; font-weight: bold; font-size: 1rem;">
                    <i class="fas fa-save"></i> GUARDAR CAMBIOS
                </button>
            </div>
        </form>
    </div>

    <script src="../js/sidebar.js"></script>
</body>
</html>