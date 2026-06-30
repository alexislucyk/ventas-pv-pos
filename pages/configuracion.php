<?php
include 'infosesion.php';
require '../config/db_config.php';

// Solo 'developer' o usuarios con permiso específico pueden entrar
if (!tiene_permiso('pages/configuracion.php')) {
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}

$mensaje = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'configuracion';

// 1. PROCESAR GUARDADO DE CONFIGURACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_config'])) {
    try {
        $pdo->beginTransaction();
        
        // Recogemos los valores del formulario según la pestaña activa
        $configs = array();
        
        // Siempre guardamos todas las configs disponibles (las que vienen en POST)
        if (isset($_POST['ticket_ancho'])) {
            $configs['ticket_ancho'] = $_POST['ticket_ancho'];
        }
        if (isset($_POST['ticket_footer_msg'])) {
            $configs['ticket_footer_msg'] = $_POST['ticket_footer_msg'];
        }
        if (isset($_POST['ganancia_global'])) {
            $configs['ganancia_global'] = str_replace(',', '.', $_POST['ganancia_global']);
        }
        if (isset($_POST['nombre_empresa'])) {
            $configs['nombre_empresa'] = $_POST['nombre_empresa'];
        }
        if (isset($_POST['cuit_empresa'])) {
            $configs['cuit_empresa'] = $_POST['cuit_empresa'];
        }
        if (isset($_POST['direccion_empresa'])) {
            $configs['direccion_empresa'] = $_POST['direccion_empresa'];
        }

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
$ticket_ancho     = isset($config_raw['ticket_ancho']) ? $config_raw['ticket_ancho'] : '80mm';
$ticket_msg       = isset($config_raw['ticket_footer_msg']) ? $config_raw['ticket_footer_msg'] : 'Gracias por su compra!';
$ganancia_global  = isset($config_raw['ganancia_global']) ? $config_raw['ganancia_global'] : '60';
$nombre_empresa   = isset($config_raw['nombre_empresa']) ? $config_raw['nombre_empresa'] : '';
$cuit_empresa     = isset($config_raw['cuit_empresa']) ? $config_raw['cuit_empresa'] : '';
$direccion_empresa = isset($config_raw['direccion_empresa']) ? $config_raw['direccion_empresa'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ========== TABS ========== */
        .config-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 0;
            border-bottom: 2px solid #333;
            padding: 0;
            list-style: none;
            max-width: 800px;
        }
        .config-tabs li {
            margin: 0;
            padding: 0;
        }
        .config-tabs a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            color: #888;
            text-decoration: none;
            font-size: 0.9em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 10px 10px 0 0;
            transition: all 0.25s ease;
            border: 1px solid transparent;
            border-bottom: none;
            position: relative;
            bottom: -2px;
        }
        .config-tabs a i {
            font-size: 1em;
            opacity: 0.7;
        }
        .config-tabs a:hover {
            color: #ccc;
            background: rgba(255,255,255,0.03);
            border-color: #2a2a2a;
        }
        .config-tabs a.active {
            color: #00bcd4;
            background: #1e1e1e;
            border-color: #333;
            border-bottom-color: #1e1e1e;
        }
        .config-tabs a.active i {
            opacity: 1;
        }

        .tab-content {
            display: none;
            max-width: 800px;
            animation: fadeIn 0.3s ease;
        }
        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .config-section {
            background: #1e1e1e;
            border: 1px solid #333;
            border-top: none;
            border-radius: 0 0 12px 12px;
            padding: 25px 30px;
        }
        .config-section h3 {
            color: #00bcd4;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 1px solid #2a2a2a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .config-section h3 i {
            font-size: 1.1em;
        }
        .config-section label {
            display: block;
            color: #aaa;
            font-weight: 600;
            font-size: 0.85em;
            margin: 18px 0 6px 0;
        }
        .config-section label:first-of-type {
            margin-top: 0;
        }
        .helper-text {
            font-size: 0.78em;
            color: #666;
            margin-top: -8px;
            margin-bottom: 12px;
        }
        .config-section input[type="text"],
        .config-section input[type="number"],
        .config-section select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #444;
            background: #252525;
            color: #f0f0f0;
            border-radius: 8px;
            font-size: 0.9em;
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }
        .config-section input:focus,
        .config-section select:focus {
            border-color: #00bcd4;
            box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.15);
        }
        .config-section .input-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .config-section .input-group input {
            flex: 1;
        }
        .config-section .input-group span {
            color: #888;
            font-size: 0.85em;
            white-space: nowrap;
        }

        .btn-save {
            padding: 14px 40px;
            font-weight: bold;
            font-size: 1rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.3);
        }
        .btn-save:active {
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .config-tabs a {
                padding: 10px 14px;
                font-size: 0.75em;
            }
            .config-tabs a span.tab-label {
                display: none;
            }
            .config-section {
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1><i class="fas fa-cogs"></i> Configuración del Sistema</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>" style="max-width: 800px;">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="config-tabs">
            <li><a href="?tab=configuracion" class="<?php echo $active_tab === 'configuracion' ? 'active' : ''; ?>">
                <i class="fas fa-sliders-h"></i> <span class="tab-label">Configuración</span>
            </a></li>
            <li><a href="?tab=impresion" class="<?php echo $active_tab === 'impresion' ? 'active' : ''; ?>">
                <i class="fas fa-print"></i> <span class="tab-label">Impresiones</span>
            </a></li>
            <li><a href="?tab=parametros" class="<?php echo $active_tab === 'parametros' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> <span class="tab-label">Parámetros</span>
            </a></li>
        </ul>

        <form method="POST">
            <!-- ===== TAB: CONFIGURACIÓN ===== -->
            <div class="tab-content <?php echo $active_tab === 'configuracion' ? 'active' : ''; ?>" id="tab-configuracion">
                <div class="config-section">
                    <h3><i class="fas fa-store"></i> Datos del Negocio</h3>
                    
                    <label>Nombre de la Empresa</label>
                    <input type="text" name="nombre_empresa" value="<?php echo htmlspecialchars($nombre_empresa); ?>" placeholder="Ej: Electricidad Lucyk">
                    <p class="helper-text">Se usa en tickets, facturas y reportes.</p>

                    <label>CUIT</label>
                    <input type="text" name="cuit_empresa" value="<?php echo htmlspecialchars($cuit_empresa); ?>" placeholder="Ej: 20-12345678-9">
                    <p class="helper-text">Obligatorio para facturación electrónica (ARCA).</p>

                    <label>Dirección</label>
                    <input type="text" name="direccion_empresa" value="<?php echo htmlspecialchars($direccion_empresa); ?>" placeholder="Ej: Av. Siempre Viva 123">
                    <p class="helper-text">Dirección fiscal del comercio.</p>
                </div>
            </div>

            <!-- ===== TAB: IMPRESIÓN ===== -->
            <div class="tab-content <?php echo $active_tab === 'impresion' ? 'active' : ''; ?>" id="tab-impresion">
                <div class="config-section">
                    <h3><i class="fas fa-print"></i> Impresión de Tickets</h3>
                    
                    <label>Ancho del Papel Térmico</label>
                    <select name="ticket_ancho">
                        <option value="80mm" <?php echo $ticket_ancho == '80mm' ? 'selected' : ''; ?>>80mm (Estándar)</option>
                        <option value="58mm" <?php echo $ticket_ancho == '58mm' ? 'selected' : ''; ?>>58mm (Mini)</option>
                    </select>
                    <p class="helper-text">Afecta el diseño de la vista previa y el ticket impreso.</p>

                    <label>Mensaje al Pie del Ticket</label>
                    <input type="text" name="ticket_footer_msg" value="<?php echo htmlspecialchars($ticket_msg); ?>" placeholder="Gracias por su compra!">
                    <p class="helper-text">Texto personalizado que aparece al final de cada ticket.</p>
                </div>
            </div>

            <!-- ===== TAB: PARÁMETROS ===== -->
            <div class="tab-content <?php echo $active_tab === 'parametros' ? 'active' : ''; ?>" id="tab-parametros">
                <div class="config-section">
                    <h3><i class="fas fa-tachometer-alt"></i> Parámetros de Negocio</h3>
                    
                    <label>Ganancia Global (%)</label>
                    <div class="input-group">
                        <input type="number" name="ganancia_global" value="<?php echo htmlspecialchars($ganancia_global); ?>" min="0" max="1000" step="0.1">
                        <span>%</span>
                    </div>
                    <p class="helper-text">Porcentaje de ganancia aplicado automáticamente al cargar productos. Se usa para calcular precio de venta sugerido.</p>
                </div>
            </div>

            <div style="max-width: 800px;">
                <button type="submit" name="guardar_config" class="btn-save">
                    <i class="fas fa-save"></i> GUARDAR CAMBIOS
                </button>
            </div>
        </form>
    </div>

    <script>
    // Resaltar link activo del sidebar para esta página
    document.addEventListener('DOMContentLoaded', function() {
        const links = document.querySelectorAll('.sidebar-menu-container a');
        links.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href').includes('configuracion')) {
                link.classList.add('active');
            }
        });
    });
    </script>
</body>
</html>