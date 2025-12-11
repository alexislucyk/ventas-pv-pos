<?php
// pages/vista_recibo.php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}
require '../config/db_config.php'; // Asegúrate que el path sea correcto

// 1. Obtener ID del Movimiento (Recibo)
$id_movimiento = filter_input(INPUT_GET, 'id_mov', FILTER_VALIDATE_INT);

if (!$id_movimiento) {
    die("Error: ID de recibo no especificado o inválido.");
}

// 2. Consulta de Datos (Movimiento y Cliente)
try {
    $sql = "
        SELECT 
            m.id, 
            m.fecha, 
            m.movimiento, 
            m.haber, 
            m.n_documento,
            c.nombre, 
            c.apellido,
            c.cuit
        FROM ctacte m
        INNER JOIN clientes c ON m.id_cliente = c.id
        WHERE m.id = :id_mov AND m.haber > 0 LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_mov' => $id_movimiento]);
    $recibo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recibo) {
        die("Error: Recibo no encontrado o no corresponde a un pago.");
    }

} catch (PDOException $e) {
    error_log("Error al cargar datos del recibo: " . $e->getMessage());
    die("Error al cargar la información del recibo.");
}

// 3. Formato de datos
$fecha_formateada = date('d/m/Y', strtotime($recibo['fecha']));
$monto_formateado = number_format($recibo['haber'], 2, ',', '.');
$cliente_nombre = htmlspecialchars($recibo['apellido'] . ', ' . $recibo['nombre']);

// Datos de tu empresa (Ejemplo, cámbialos por tus datos reales)
$empresa_nombre = "Electricidad Lucyck";
$empresa_direccion = "Av. San Martín 698";
$empresa_tel = "3491-438555";
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo N° <?php echo $id_movimiento; ?></title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Estilos específicos para la vista del recibo */
        .recibo-container {
            width: 300px; 
            margin: 20px auto; 
            padding: 15px; 
            background: #fff; 
            color: #000;
            border: 1px solid #ddd;
            font-family: 'Courier New', monospace; /* Fuente de ticket */
            font-size: 14px;
            /* line-height: 1.5; */
        }

        /* --- NUEVO ESTILO --- */
        .acciones-recibo-container {
            width: 300px; /* Igual al ancho del recibo */
            margin: 0 auto; /* Centrar y margen superior */
            text-align: center;
        }
        

        .header, .footer { text-align: center; margin-bottom: 10px; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .data-row { display: flex; justify-content: space-between; }
        .total { font-weight: bold; font-size: 16px; margin-top: 10px; }
        
        
        @media print {
            body { background: none; }
            .content { margin: 0; padding: 0; }
            .no-print { display: none; }
            .recibo-container { border: none; }
        }
    </style>
</head>
<body>
    <!-- <div class="content no-print" style="text-align: center; padding-top: 50px;">
        <p>Recibo N° <?php echo $id_movimiento; ?> generado con éxito.</p>
        <button onclick="window.print()" class="btn btn-green">Imprimir Recibo</button>
        <a href="pagos_ctacte.php" style="display: block; margin-top: 15px;">Volver al registro de pagos</a>
    </div> -->
    <div class="no-print" style="padding-top: 50px;">
        
        <div class="acciones-recibo-container">
            <p style="margin-bottom: 15px;">Recibo N° <?php echo $id_movimiento; ?> generado con éxito.</p>
            
            <button onclick="window.print()" class="btn btn-green">Imprimir Recibo</button> 
            
            <a href="pagos_ctacte.php" style="display: block;">Volver al registro de pagos</a>
        </div>
        
    </div>

    <div class="recibo-container">
        <div class="header">
            <h4><?php echo $empresa_nombre; ?></h4>
            <p><?php echo $empresa_direccion; ?></p>
            <p>Tel: <?php echo $empresa_tel; ?></p>
        </div>

        <div class="divider"></div>

        <p class="data-row"><span>Recibo N°:</span> <span><?php echo $recibo['n_documento'] ?: $recibo['id']; ?></span></p>
        <p class="data-row"><span>Fecha:</span> <span><?php echo $fecha_formateada; ?></span></p>
        <p class="data-row"><span>Cliente:</span> <span><?php echo $cliente_nombre; ?></span></p>
        <p class="data-row"><span>CUIT:</span> <span><?php echo $recibo['cuit'] ?: 'Consumidor Final'; ?></span></p>

        <div class="divider"></div>

        <p style="font-weight: bold; text-align: center;">DETALLE DEL PAGO</p>
        
        <p class="data-row">
            <span>Movimiento:</span> 
            <span><?php echo htmlspecialchars($recibo['movimiento']); ?></span>
        </p>
        
        <div class="divider"></div>
        
        <div class="total">
            <p class="data-row">
                <span>TOTAL ABONADO:</span> 
                <span>$<?php echo $monto_formateado; ?></span>
            </p>
        </div>

        <div class="divider"></div>
        <div class="footer">
            <p>GRACIAS POR SU PAGO.</p>
        </div>
    </div>
</body>
</html>