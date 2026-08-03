<?php
// pages/cuentas_corrientes_detalle.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php'; 
require '../funciones/funciones_intereses.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Validar que se recibió el ID del cliente
if (!isset($_GET['id_cliente']) || !is_numeric($_GET['id_cliente'])) {
    die('❌ Error: ID de cliente no válido.');
}

$id_cliente = (int)$_GET['id_cliente'];

    // Obtener datos del cliente
    try {
        $sql_cliente = "SELECT id, nombre, apellido, cuit, telefono FROM clientes WHERE id = :id AND empresa_id = :empresa_id";
        
        $stmt_cliente = $pdo->prepare($sql_cliente);
        $stmt_cliente->execute([':id' => $id_cliente, ':empresa_id' => $empresa_id]);
        $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        
        if (!$cliente) {
            die('❌ Error: Cliente no encontrado.');
        }
        
        $nombre_completo = $cliente['apellido'] . ', ' . $cliente['nombre'];
        
        // Obtener movimientos del cliente
        $sql_movimientos = "
            SELECT
                id,
                movimiento,
                n_documento,
                debe,
                haber,
                fecha
            FROM ctacte
            WHERE id_cliente = :id_cliente AND empresa_id = :empresa_id
            ORDER BY fecha ASC, id ASC
        ";
        
        $stmt_mov = $pdo->prepare($sql_movimientos);
        $stmt_mov->execute([':id_cliente' => $id_cliente, ':empresa_id' => $empresa_id]);
        $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular saldo actual
        $saldo_actual = 0;
        foreach ($movimientos as $mov) {
            $saldo_actual += (float)$mov['debe'] - (float)$mov['haber'];
        }
        
        // Calcular intereses pendientes
        try {
            $intereses = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);
        } catch (Exception $e2) {
            error_log("Error al calcular intereses: " . $e2->getMessage());
            // Si hay error en intereses, continuar sin ellos
            $intereses = [
                'interes_total' => 0,
                'detalle' => [],
                'config' => []
            ];
        }
    
} catch (Exception $e) {
    error_log("ERROR en detalle CC: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    die('❌ Error al cargar los datos: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle Cuenta Corriente | <?php echo htmlspecialchars($nombre_completo); ?> | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Reducción de Escala General */
        .content { 
            padding: 20px 30px; 
            min-height: 100vh;
        }
        
        .content h1 { font-size: 1.6rem; margin-bottom: 20px; padding-bottom: 8px; }
        .card { padding: 12px 15px; margin-bottom: 15px; }
        .card h2, .card h3 { font-size: 1.1rem; }
        .input-field { padding: 8px !important; font-size: 0.9rem; margin-bottom: 10px !important; }
        label { font-size: 0.85rem; margin-bottom: 4px; }
        .btn, .btn-primary, .btn-secondary, .btn-success, .btn-view, .btn-whatsapp-nodered { padding: 6px 12px; font-size: 0.85rem; }

        .btn-view { background: #3498db; color: white; border-radius: 4px; text-decoration: none; cursor: pointer; border:none; }
        .btn-whatsapp-nodered { background: #25d366; color: white; border-radius: 4px; text-decoration: none; margin-left: 5px; border: none; cursor: pointer; }
        
        /* Info del Cliente */
        .cliente-info {
            background: #2c2c2c;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #00bcd4;
            margin-bottom: 20px;
        }
        .cliente-info h2 {
            margin: 0 0 15px 0;
            color: #00bcd4;
            font-size: 1.3rem;
        }
        .cliente-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .cliente-info-item {
            background: #1f1f1f;
            padding: 10px;
            border-radius: 4px;
        }
        .cliente-info-item label {
            display: block;
            color: #888;
            font-size: 0.75rem;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .cliente-info-item value {
            display: block;
            color: #fff;
            font-size: 1rem;
            font-weight: bold;
        }
        
        /* Stats del Cliente */
        .cliente-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #2c2c2c;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #00bcd4;
            color: #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .stat-card h3 { margin: 0; font-size: 0.7rem; color: #bbb; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 1.4rem; font-weight: bold; margin-top: 5px; }
        
        .saldo-deudor { color: #ff5e5e; font-weight: bold; }
        .saldo-favor { color: #2ecc71; font-weight: bold; }
        
        /* Tabla de movimientos */
        #cuerpoHistorial tr { border-bottom: 1px solid #444; }
        #cuerpoHistorial td { padding: 8px 10px; }
        .saldo-cero { color: #bbb; }
        .saldo-negativo { color: #ff5e5e; font-weight: bold; }
        .saldo-positivo { color: #2ecc71; font-weight: bold; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #1f1f1f; color: #eee; font-size: 0.82rem; }
        th { background: #333; color: #fff; padding: 6px 10px !important; text-align: left; }
        td { padding: 6px 10px !important; border-bottom: 1px solid #444; }
        table .text-right { font-size: 0.85rem !important; }
        tr:hover { background: #292929; }
        
        .btn-action { padding: 6px 12px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; font-size: 0.8rem; color: white; display: inline-flex; align-items: center; gap: 5px; }
        .btn-detalle-v { background-color: #3498db; }
        .btn-ticket-v { background-color: #2ecc71; }
        .btn-pdf-v { background-color: #00bcd4; }
        
        /* Toast Notifications */
        .toast-notificacion {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #2ecc71;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 0.9rem;
            z-index: 2147483647;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            animation: toast-slide-in 0.3s ease;
        }
        .toast-fade-out {
            animation: toast-fade-out 0.5s ease forwards;
        }
        @keyframes toast-slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes toast-fade-out {
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* Ocultar botones de impresión en el historial */
        #cuerpoHistorial .btn-ticket-v, 
        #cuerpoHistorial .btn-pdf-v,
        #cuerpoHistorial .btn-success,
        #cuerpoHistorial .fa-print,
        #cuerpoHistorial .fa-file-pdf,
        #cuerpoHistorial .fa-file-invoice { display: none !important; }

        /* --- FIX DEFINITIVO MODALES --- */
        /* Forzamos el centrado absoluto sobre el viewport ignorando márgenes y flujos del sistema */
        #modalFactura, #modalWhatsApp {
            position: fixed !important;
            z-index: 2147483640 !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            height: 100vh !important;
            background-color: rgba(0, 0, 0, 0.98) !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        #modalFactura .modal-content-custom, #modalWhatsApp .modal-content-custom {
            background-color: #1e1e1e !important;
            margin: 20px auto !important;
            padding: 15px 20px !important;
            border: 1px solid #444 !important;
            border-radius: 12px !important;
            width: 95% !important;
            max-width: 900px !important;
            color: white !important;
            box-shadow: 0 15px 60px rgba(0,0,0,1) !important;
            position: relative !important;
            max-height: 85vh;
            overflow-y: auto;
        }

        /* Asegurar que el contenido del modal se vea correctamente */
        #modalFactura > div, #modalWhatsApp > div {
            position: relative !important;
            top: auto !important;
            left: auto !important;
            transform: none !important;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>📊 Detalle Cuenta Corriente</h1>
            <div style="display: flex; gap: 10px;">
                <a href="pagos_ctacte.php?id_cliente=<?php echo $id_cliente; ?>" class="btn-primary" style="padding: 8px 18px; text-decoration: none; border-radius: 5px;">
                    ➕ Registrar Pago / Cobro
                </a>
                <a href="cuentas_corrientes.php" class="btn btn-secondary" style="padding: 8px 18px; text-decoration: none; border-radius: 5px;">
                    ← Volver
                </a>
            </div>
        </div>

        <!-- Información del Cliente -->
        <div class="cliente-info">
            <h2><i class="fas fa-user"></i> <?php echo htmlspecialchars($nombre_completo); ?></h2>
            <div class="cliente-info-grid">
                <div class="cliente-info-item">
                    <label>CUIT / Documento</label>
                    <value><?php echo htmlspecialchars($cliente['cuit'] ?? 'N/A'); ?></value>
                </div>
                <div class="cliente-info-item">
                    <label>Teléfono</label>
                    <value><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></value>
                </div>
                <div class="cliente-info-item">
                    <label>ID Cliente</label>
                    <value>#<?php echo $cliente['id']; ?></value>
                </div>
            </div>
        </div>

        <!-- Estadísticas del Cliente -->
        <div class="cliente-stats">
            <div class="stat-card" style="border-left-color: <?php echo $saldo_actual > 0 ? '#e74c3c' : '#2ecc71'; ?>;">
                <h3><i class="fas fa-balance-scale"></i> Saldo Actual</h3>
                <div class="value <?php echo $saldo_actual > 0 ? 'saldo-deudor' : 'saldo-favor'; ?>">
                    $ <?php echo number_format(abs($saldo_actual), 2, ',', '.'); ?>
                    <small style="font-size: 0.8rem; color: #888;">
                        (<?php echo $saldo_actual > 0 ? 'Deudor' : 'A Favor'; ?>)
                    </small>
                </div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-exchange-alt"></i> Total Movimientos</h3>
                <div class="value"><?php echo count($movimientos); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-arrow-up"></i> Total Debe</h3>
                <div class="value">
                    $ <?php echo number_format(array_sum(array_column($movimientos, 'debe')), 2, ',', '.'); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-arrow-down"></i> Total Haber</h3>
                <div class="value">
                    $ <?php echo number_format(array_sum(array_column($movimientos, 'haber')), 2, ',', '.'); ?>
                </div>
            </div>
        </div>

        <!-- Sección de Intereses por Mora -->
        <?php if ($intereses['interes_total'] > 0): ?>
        <div class="card" style="background: #2c2c2c; border-left: 4px solid #f39c12; margin-bottom: 20px;">
            <h3 style="color: #f39c12; margin-bottom: 15px;">
                <i class="fas fa-percentage"></i> Intereses por Mora Pendientes
            </h3>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <p style="margin: 5px 0; color: #fff;">
                        <strong>Total Intereses:</strong> 
                        <span style="color: #f39c12; font-size: 1.2rem; font-weight: bold;">
                            $ <?php echo number_format($intereses['interes_total'], 2, ',', '.'); ?>
                        </span>
                    </p>
                    <p style="margin: 5px 0; color: #bbb; font-size: 0.85rem;">
                        Tasa aplicada: <?php echo $intereses['config']['tasa_mensual']; ?>% mensual
                        <?php if ($intereses['config']['dias_gracia'] > 0): ?>
                            | Días de gracia: <?php echo $intereses['config']['dias_gracia']; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <button onclick="aplicarIntereses(<?php echo $id_cliente; ?>)"
                        class="btn-primary"
                        style="background: #f39c12; color: white; padding: 10px 20px; 
                               border: none; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-check"></i> Aplicar Intereses
                </button>
            </div>
            
            <!-- Detalle de cálculo -->
            <details style="margin-top: 15px;">
                <summary style="color: #00bcd4; cursor: pointer; font-weight: bold;">Ver detalle de cálculo</summary>
                <table style="margin-top: 10px; font-size: 0.8rem;">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Fecha Venc.</th>
                            <th>Saldo</th>
                            <th>Días Mora</th>
                            <th>Interés</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($intereses['detalle'] as $det): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($det['movimiento']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($det['fecha_vencimiento'])); ?></td>
                            <td>$ <?php echo number_format($det['saldo_pendiente'], 2, ',', '.'); ?></td>
                            <td><?php echo $det['dias_mora']; ?> días</td>
                            <td style="color: #f39c12; font-weight: bold;">$ <?php echo number_format($det['interes_calculado'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
        <?php endif; ?>
        <!-- Fin Sección de Intereses -->

        <!-- Historial de Movimientos -->
        <div class="card">
            <h3 style="margin-bottom: 15px; color: #00bcd4;">
                <i class="fas fa-history"></i> Historial Completo de Movimientos
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>N° Doc.</th>
                        <th style="text-align: right;">Debe</th>
                        <th style="text-align: right;">Haber</th>
                        <th style="text-align: right;">Saldo Acu.</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="cuerpoHistorial">
                    <?php
                    if (empty($movimientos)) {
                        echo "<tr><td colspan='7' style='text-align: center;'>No hay movimientos registrados para este cliente.</td></tr>";
                    } else {
                        $saldo_acumulado = 0;
                        foreach ($movimientos as $mov) {
                            $id_mov = $mov['id'];
                            $debe_val = (float)$mov['debe']; 
                            $haber_val = (float)$mov['haber'];
                            $saldo_acumulado += $debe_val - $haber_val;
                            
                            // Lógica visual de saldos
                            $clase_saldo = 'saldo-cero';
                            $monto_final_a_mostrar = $saldo_acumulado; 

                            if ($saldo_acumulado > 0) {
                                $clase_saldo = 'saldo-negativo'; 
                                $monto_final_a_mostrar = -$saldo_acumulado; 
                            } elseif ($saldo_acumulado < 0) {
                                $clase_saldo = 'saldo-positivo'; 
                                $monto_final_a_mostrar = abs($saldo_acumulado); 
                            }

                            // --- DETERMINAR TIPO DE MOVIMIENTO ---
                            $texto_movimiento = htmlspecialchars($mov['movimiento']);
                            $mov_upper = strtoupper($mov['movimiento']);
                            $is_sale = (strpos($mov_upper, 'FACTURA') !== false);
                            $is_dev = (strpos($mov_upper, 'ANULACIÓN') !== false || strpos($mov_upper, 'DEVOLUCIÓN') !== false);
                            $is_payment = ($haber_val > 0 && !$is_dev);

                            $columna_acciones = "";

                            if ($is_sale) {
                                $n_doc_val = $mov['n_documento'];
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$n_doc_val\", \"VENTA\", \"#$n_doc_val\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirTicket(\"$n_doc_val\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='descargarPDFVenta(\"$n_doc_val\")' title='PDF A5' style='color:#00bcd4;'><i class='fas fa-file-pdf'></i></button>";
                            } elseif ($is_dev) {
                                $op_n = 0;
                                if (preg_match('/OP#(\d+)/', $mov['movimiento'], $matches)) { $op_n = $matches[1]; }
                                if ($op_n > 0) {
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$op_n\", \"DEVOLUCION\", \"OP#$op_n\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$op_n\", true)' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                    $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$op_n\", true)' title='PDF A5' style='color:#e67e22;'><i class='fas fa-file-pdf'></i></button>";
                                }
                            } elseif ($is_payment) {
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='abrirDetalleOperacion(\"$id_mov\", \"PAGO\", \"Recibo #$id_mov\")' title='Detalle' style='color:#3498db; margin-right:8px;'><i class='fas fa-search'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirRecibo(\"$id_mov\")' title='Ticket' style='color:#2ecc71; margin-right:8px;'><i class='fas fa-print'></i></button>";
                                $columna_acciones .= "<button type='button' class='btn-detalle' onclick='imprimirReciboPDF(\"$id_mov\")' title='PDF A5' style='color:#e74c3c;'><i class='fas fa-file-pdf'></i></button>";
                            }

                            // Si n_documento es 0 o está vacío, mostramos el ID interno como referencia sin el símbolo #
                            $n_doc_display = ($mov['n_documento'] && $mov['n_documento'] != "0") ? htmlspecialchars($mov['n_documento']) : $id_mov;
                            
                            echo "
                                <tr>
                                    <td>" . date('d/m/Y', strtotime($mov['fecha'])) . "</td>
                                    <td>" . $texto_movimiento . "</td>
                                    <td>" . $n_doc_display . "</td>
                                    <td class='text-right'>$" . number_format($debe_val, 2, ',', '.') . "</td> 
                                    <td class='text-right'>$" . number_format($haber_val, 2, ',', '.') . "</td> 
                                    <td class='text-right " . $clase_saldo . "'>$" . number_format($monto_final_a_mostrar, 2, ',', '.') . "</td>
                                    <td class='text-center'>" . $columna_acciones . "</td>
                                </tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de Detalle de Operación (Ventas/Pagos) -->
    <div id="modalFactura" class="modal-custom" style="display:none;">
        <div class="modal-content-custom">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 id="tituloModalFactura">Detalle de Operación <span id="detalleNdocumento"></span></h2>
                <button onclick="cerrarModalFactura()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            <div id="cuerpoDetalleFactura" style="margin-top: 20px;"></div>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn-action btn-ticket-v" id="btnTicketFactura" onclick="accionImprimirModal('ticket')">🖨️ Ticket</button>
                <button class="btn-action btn-pdf-v" id="btnPDFFactura" onclick="accionImprimirModal('pdf')">📄 PDF A5</button>
            </div>
        </div>
    </div>

    <!-- Modal Confirmación WhatsApp -->
    <div id="modalWhatsApp" class="modal-custom" style="display:none;">
        <div class="modal-content-custom" style="max-width: 500px; border-top: 4px solid #25d366;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 style="margin: 0; color: #25d366;"><i class="fab fa-whatsapp"></i> WhatsApp</h2>
                <button onclick="cerrarModalWhatsApp()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            <div style="margin-top: 20px;">
                <div id="mensajeWhatsAppPreview" style="background: #111; padding: 15px; border-radius: 6px; border-left: 3px solid #25d366; font-style: italic; color: #eee; line-height: 1.4; white-space: pre-wrap;"></div>
                <input type="hidden" id="wa_destino_tel">
                <input type="hidden" id="wa_destino_msg">
            </div>
            <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn" style="background: #444; color: white;" onclick="cerrarModalWhatsApp()">Cancelar</button>
                <button id="btnConfirmarWA" class="btn" style="background: #25d366; color: white; font-weight: bold; padding: 10px 20px;" onclick="ejecutarEnvioWhatsApp()">ENVIAR AHORA</button>
            </div>
        </div>
    </div>

    <script>
        let modalActualTipo = ''; 
        let modalActualId = 0;

        // --- FUNCIONES DE UTILIDAD ---

        // Función para mostrar notificaciones tipo "toast"
        function mostrarToast(mensaje, tipo = 'success') {
            const toast = document.createElement('div');
            toast.className = 'toast-notificacion';
            if (tipo === 'error') toast.style.background = '#e74c3c';
            toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('toast-fade-out');
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }

        // --- LÓGICA DE DETALLE Y PAGO ---

        window.abrirDetalleOperacion = function(id, tipo, etiqueta) {
            const modal = document.getElementById('modalFactura');
            const cuerpo = document.getElementById('cuerpoDetalleFactura');
            const labelDoc = document.getElementById('detalleNdocumento');
            modalActualTipo = tipo;
            modalActualId = id;
            modal.style.display = 'flex';
            if(labelDoc) labelDoc.textContent = etiqueta;
            cuerpo.innerHTML = '<p style="text-align:center; padding:20px;">Cargando...</p>';

            let url = '';
            if (tipo === 'VENTA') url = '../ajax/obtener_detalle_venta.php?n_documento=' + id;
            else if (tipo === 'DEVOLUCION') url = '../ajax/obtener_detalle_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE';
            else if (tipo === 'PAGO') url = '../ajax/obtener_detalle_pago.php?id=' + id;

            fetch(url).then(res => res.text()).then(html => { cuerpo.innerHTML = html; });
        }

        window.imprimirTicket = function(nDocumento) {
            const doc = String(nDocumento).replace(/[^\d]/g, '');
            if (!doc) { alert("Número de documento inválido."); return; }
            window.open('vista_previa_ticket.php?n_documento=' + doc, '_blank', 'width=400,height=700');
        }

        window.descargarPDFVenta = function(nDocumento) {
            const doc = String(nDocumento).replace(/[^\d]/g, '');
            if (!doc) { alert("Número de documento inválido."); return; }
            window.location.href = 'generar_pdf_ticket.php?n_documento=' + doc + '&download=1';
        }

        window.imprimirRecibo = function(idMovimiento, esDevolucion = false) {
            const id = String(idMovimiento).replace(/[^\d]/g, '');
            if (!id) { alert("ID de movimiento inválido."); return; }
            const url = esDevolucion 
                ? 'vista_previa_ticket_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE'
                : 'vista_recibo.php?id_mov=' + id + '&formato=ticket';
            window.open(url, '_blank', 'width=400,height=700');
        }

        window.imprimirReciboPDF = function(idMovimiento, esDevolucion = false) {
            const id = String(idMovimiento).replace(/[^\d]/g, '');
            if (!id) { alert("ID de movimiento inválido."); return; }
            const url = esDevolucion 
                ? 'generar_pdf_devolucion.php?id=' + id + '&tipo=CUENTA CORRIENTE&download=1'
                : 'generar_pdf_recibo.php?id_mov=' + id + '&download=1';
            window.location.href = url;
        }

        function accionImprimirModal(formato) {
            const cleanId = String(modalActualId).replace(/[^\d]/g, '');
            
            if (!cleanId) {
                alert("No se pudo obtener un ID de documento válido para imprimir.");
                return;
            }

            if (formato === 'ticket') {
                if (modalActualTipo === 'VENTA') window.open('vista_previa_ticket.php?n_documento=' + cleanId, '_blank', 'width=400,height=700');
                else if (modalActualTipo === 'PAGO') window.open('vista_recibo.php?id_mov=' + cleanId + '&formato=ticket', '_blank', 'width=400,height=700');
                else if (modalActualTipo === 'DEVOLUCION') window.open('vista_previa_ticket_devolucion.php?id=' + cleanId + '&tipo=CUENTA CORRIENTE', '_blank', 'width=400,height=700');
            } else { // Formato PDF
                if (modalActualTipo === 'VENTA') window.location.href = 'generar_pdf_ticket.php?n_documento=' + cleanId + '&download=1';
                else if (modalActualTipo === 'PAGO') window.location.href = 'generar_pdf_recibo.php?id_mov=' + cleanId + '&download=1';
                else if (modalActualTipo === 'DEVOLUCION') window.location.href = 'generar_pdf_devolucion.php?id=' + cleanId + '&tipo=CUENTA CORRIENTE&download=1';
            }
        }

        async function ejecutarEnvioWhatsApp() {
            const btn = document.getElementById('btnConfirmarWA');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ENVIANDO...';
            
            const tel = document.getElementById('wa_destino_tel').value;
            const msg = document.getElementById('wa_destino_msg').value;

            try {
                const response = await fetch('../ajax/enviar_whatsapp_nodered.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ telefono: tel, mensaje: msg })
                });

                const text = await response.text();
                const data = JSON.parse(text.substring(text.indexOf('{')));

                if (data.success) {
                    mostrarToast("¡Mensaje enviado con éxito!", "success");
                    cerrarModalWhatsApp();
                } else {
                    mostrarToast("Error: " + (data.error || "No se pudo enviar"), "error");
                }
            } catch (err) {
                console.error("Error en fetch de WhatsApp:", err);
                mostrarToast("Error de conexión con el servidor.", "error");
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'ENVIAR AHORA';
            }
        }

        function cerrarModalFactura() { document.getElementById('modalFactura').style.display = 'none'; }
        function cerrarModalWhatsApp() { document.getElementById('modalWhatsApp').style.display = 'none'; }

        // Cerrar modales con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('modalFactura').style.display = 'none';
                document.getElementById('modalWhatsApp').style.display = 'none';
            }
        });

        // Cerrar modal al hacer clic fuera
        window.onclick = function(e) {
            const modales = [document.getElementById('modalFactura'), document.getElementById('modalWhatsApp')];
            modales.forEach(m => { if (m && e.target == m) m.style.display = 'none'; });
        }

        // --- LÓGICA DE INTERESES POR MORA ---

        window.aplicarIntereses = function(idCliente) {
            if (!confirm('¿Aplicar intereses por mora a este cliente?\n\nSe calcularán los intereses según la configuración y se agregarán como un nuevo movimiento en la cuenta corriente.')) {
                return;
            }
            
            const btn = event.target.closest('button');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Aplicando...';
            
            const formData = new FormData();
            formData.append('id_cliente', idCliente);
            
            fetch('../ajax/aplicar_interes_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    mostrarToast(data.mensaje, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    mostrarToast('Error: ' + data.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Aplicar Intereses';
                }
            })
            .catch(err => {
                mostrarToast('Error de conexión', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Aplicar Intereses';
            });
        }
    </script>
</body>
</html>