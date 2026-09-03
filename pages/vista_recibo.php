<?php
// pages/vista_recibo.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 1. Obtener ID del Movimiento (Recibo)
$id_movimiento = filter_input(INPUT_GET, 'id_mov', FILTER_VALIDATE_INT);
if (!$id_movimiento) {
    die("Error: ID de recibo no especificado o inválido.");
}

$formato = isset($_GET['formato']) ? $_GET['formato'] : 'ticket';

// 1.1. Obtener datos de la empresa dinámicamente
$empresa_id = $_SESSION['empresa_id'] ?? 1;
$stmt_emp = $pdo->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
$stmt_emp->execute([$empresa_id]);
$emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

// LOGO DE LA EMPRESA: mismo criterio que el ticket de ventas
// (ej. img/logos/logo_*.png guardado en "Perfil del Negocio")
$logo_url = '';
if (!empty($emp['logo_path'])) {
    $ruta_logo_disco = dirname(__DIR__) . '/' . ltrim($emp['logo_path'], '/');
    if (@is_file($ruta_logo_disco)) {
        $logo_url = (defined('URL_BASE') ? URL_BASE : '/') . ltrim($emp['logo_path'], '/');
    }
}

// Obtener configuración de ancho de papel
$stmt_cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ticket_ancho'");
$ancho_papel = $stmt_cfg->fetchColumn() ?: '80mm';
$ancho_px = ($ancho_papel === '58mm') ? '220px' : '320px';

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

// Detectar si es una devolución/anulación para cambiar etiquetas dinámicamente
$mov_txt = strtoupper($recibo['movimiento']);
$es_devolucion = (strpos($mov_txt, 'ANULACIÓN') !== false || strpos($mov_txt, 'DEVOLUCIÓN') !== false);
$titulo_doc = $es_devolucion ? "COMPROBANTE DE DEVOLUCIÓN" : "RECIBO DE PAGO";
$subtitulo_detalle = $es_devolucion ? "DETALLE DE OPERACIÓN" : "DETALLE DEL PAGO";
$label_total = $es_devolucion ? "TOTAL REINTEGRADO:" : "TOTAL ABONADO:";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo N° <?php echo $recibo['n_documento'] ?: $recibo['id']; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/print/temptocketprint.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/print/vista_recibo.css'); ?>">
    <style>
        /* Reglas con valores dinamicos calculados por PHP (se conservan inline) */
        .recibo-container.ticket { width: <?php echo $ancho_px; ?>; }
        @media print { body { width: <?php echo $ancho_papel; ?> !important; } }
    </style>
</head>
<body onload="window.print()">

    <!-- Contenedor del ticket: Fuera de .content para que la impresora lo tome limpio -->
    <div id="ticket-vista-previa" class="recibo-container <?php echo $formato; ?>">
        <?php if ($formato === 'ticket'): ?>
                <!-- DISEÑO TICKET 80mm -->
                <div class="center">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="logo" style="max-width:54mm; max-height:30mm; display:block; margin:0 auto 2px auto;" />
                    <?php endif; ?>
                    <h3><?php echo strtoupper($emp['nombre_fantasia']); ?></h3>
                    <p><?php echo htmlspecialchars($emp['direccion']); ?><br>Tel: <?php echo htmlspecialchars($emp['telefono']); ?></p>
                    <p><strong><?php echo $titulo_doc; ?></strong></p>
                </div>
                <div class="sep"></div>

                <div class="line"><span>Recibo N°:</span> <span><?php echo $recibo['n_documento'] ?: $recibo['id']; ?></span></div>
                <div class="line"><span>Fecha:</span> <span><?php echo $fecha_formateada; ?></span></div>
                <div class="line"><span>Cliente:</span> <span><?php echo $cliente_nombre; ?></span></div>
                <div class="line"><span>CUIT:</span> <span><?php echo $recibo['cuit'] ?: 'Consumidor Final'; ?></span></div>
            <?php else: ?>
                <!-- DISEÑO A5 PROFESSIONAL (Ya implementado arriba en el CSS) -->
                <div class="header-box">
                    <div class="box-x"><span>X</span></div>
                    <div class="box-x-text">DOC. NO VÁLIDO COMO FACTURA</div>
                    <div class="header-left">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="logo" style="max-width:25mm; max-height:20mm; display:block; margin-bottom:4px;" />
                        <?php endif; ?>
                        <h2 style="margin: 0; font-size: 1.3em;"><?php echo strtoupper($emp['nombre_fantasia']); ?></h2>
                        <p style="font-size: 0.85em; margin: 8px 0; line-height: 1.4;">
                            <?php echo htmlspecialchars($emp['direccion']); ?><br>
                            <?php echo htmlspecialchars($emp['localidad']); ?><br>
                            Tel: <?php echo htmlspecialchars($emp['telefono']); ?>
                        </p>
                    </div>
                    <div class="header-right">
                        <h2 style="margin: 0; font-size: 1.2em;"><?php echo $titulo_doc; ?></h2>
                        <p style="font-size: 1.1em; margin: 8px 0; font-weight: bold;">
                            N° <?php echo str_pad($recibo['n_documento'] ?: $recibo['id'], 8, "0", STR_PAD_LEFT); ?>
                        </p>
                        <p style="font-size: 1em; margin: 5px 0;">Fecha: <?php echo $fecha_formateada; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sep"></div>

            <div class="center" style="font-weight: bold;"><?php echo $subtitulo_detalle; ?></div>
            
            <div class="line">
                <span>Movimiento:</span> 
                <span><?php echo htmlspecialchars($recibo['movimiento']); ?></span>
            </div>
            
            <div class="sep"></div>
            
            <div class="line total-amount">
                <span style="font-weight: bold;">
                    <span><?php echo $label_total; ?></span> 
                </span>
                <span style="font-weight: bold;">
                    $<?php echo $monto_formateado; ?></span>
            </div>

            <div class="divider"></div>
            <div class="footer">
                <p>GRACIAS POR SU PAGO.</p>
            </div>
        </div>

    <div class="no-print" style="text-align: center; margin-top: 20px; padding-bottom: 30px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">Imprimir de nuevo</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; margin-left: 10px; background: #444; color: white; border: none; border-radius: 4px;">Cerrar Ventana</button>
        <a href="pagos_ctacte.php" style="display: block; margin-top: 15px; color: #ddd; text-decoration: none; font-size: 0.85rem;">Volver al Menú Principal</a>
    </div>
</body>
</html>