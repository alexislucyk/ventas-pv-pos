<?php
// pages/presupuestos.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

// Listar presupuestos ya emitidos para permitir copiar sus productos
$empresa_id_pres = $_SESSION['empresa_id'] ?? null;
$presupuestos_existentes = [];
if ($empresa_id_pres) {
    $stmtPres = $pdo->prepare("SELECT p.id, p.total_presupuesto, p.fecha_presupuesto,
                                      CONCAT(c.apellido, ' ', c.nombre) AS cliente_nombre
                               FROM presupuestos p
                               LEFT JOIN clientes c ON p.id_cliente = c.id AND c.empresa_id = ?
                               WHERE p.empresa_id = ?
                               ORDER BY p.id DESC LIMIT 50");
    $stmtPres->execute([$empresa_id_pres, $empresa_id_pres]);
    $presupuestos_existentes = $stmtPres->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Presupuesto | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/presupuestos.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 class="main-title" style="margin: 0;">📑 Crear Nuevo Presupuesto</h1>
            <a href="<?php echo route('presupuestos.consulta'); ?>" class="btn btn-primary" style="text-decoration: none; padding: 10px 20px; border-radius: 5px; font-weight: bold;">
                <i class="fas fa-search-dollar"></i> Consultar Presupuestos Emitidos
            </a>
        </div>

        <div class="presupuesto-grid">
            <div class="card">
                <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
                    <button class="btn-secondary-copiar" onclick="abrirModalCopiar()" style="display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 5px; font-weight: bold; cursor: pointer; background: #6c3483; color: #fff; border: 1px solid #8e44ad;">
                        <i class="fas fa-copy"></i> Copiar de Presupuesto Emitido
                    </button>
                </div>

                <div style="position:relative; margin-bottom: 25px;">
                    <label><i class="fas fa-search"></i> Buscar Producto</label>
                    <input type="text" id="buscarProducto" class="input-field" placeholder="Código o nombre del artículo..." autocomplete="off">
                    <div id="listaProductos" class="results-dropdown"></div>
                </div>

                <h3 style="color:#3498db;"><i class="fas fa-list-ul"></i> Detalle del Presupuesto</h3>
                <div id="avisoPrecios" style="display:none; background:#33251b; border-left:4px solid #e67e22; color:#f0c47f; padding:12px 15px; border-radius:5px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <strong><i class="fas fa-exclamation-triangle"></i> Variaciones de precio (vs. presupuesto original)</strong>
                        <span onclick="cerrarAvisoPrecios()" style="cursor:pointer; font-size:20px; line-height:1;">&times;</span>
                    </div>
                    <div id="avisoPreciosContenido" style="font-size:0.9rem; line-height:1.6;"></div>
                </div>
                <table id="tablaPresupuesto" class="table-full">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th style="width: 100px;">Cantidad</th>
                            <th style="width: 140px;">Precio Unit.</th>
                            <th style="width: 120px;">P. Actual</th>
                            <th style="width: 130px;">Subtotal</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoPresupuesto"></tbody>
                </table>

                <div style="margin-top: 30px;">
                    <label><i class="fas fa-sticky-note"></i> Observaciones del Presupuesto:</label>
                    <textarea id="comentarios" class="input-field" rows="4" placeholder="Ej: Validez del presupuesto 7 días. Precios sujetos a cambios sin previo aviso."></textarea>
                </div>
            </div>

            <div class="card">
                <div style="position:relative;">
                    <label><i class="fas fa-user"></i> Cliente</label>
                    <input type="text" id="buscarCliente" class="input-field" placeholder="Buscar por nombre o CUIT..." autocomplete="off">
                    <input type="hidden" id="id_cliente_seleccionado">
                    <div id="listaClientes" class="results-dropdown"></div>
                </div>

                <div id="datosCliente" style="margin-top:15px; background: #2a2a2a; padding: 10px; border-radius: 4px; border-left: 4px solid #3498db;">
                    <span style="color: #888;">No se ha seleccionado un cliente.</span>
                </div>

                <hr>

                <div class="total-box">
                    <p style="margin:0; color:#aaa; text-transform:uppercase; letter-spacing:1px; font-size:0.8rem;">Total Estimado</p>
                    <span id="totalPresupuesto">$ 0.00</span>
                </div>

                <button class="btn-success" onclick="guardarPresupuesto()" style="width: 100%; padding: 15px; font-size: 1.1rem; margin-top: 15px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fas fa-save"></i> Guardar y Generar PDF
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Copiar productos de un presupuesto emitido -->
    <div id="modalCopiarPresupuesto" style="display:none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8);">
        <div style="background-color: #252525; margin: 10% auto; padding: 20px; border: 1px solid #444; width: 70%; max-width: 700px; border-radius: 8px; color: white;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 20px;">
                <h2 style="margin: 0; color:#b07cc6;"><i class="fas fa-copy"></i> Copiar de Presupuesto Emitido</h2>
                <span onclick="cerrarModalCopiar()" style="cursor: pointer; font-size: 28px; font-weight: bold; color:#888;">&times;</span>
            </div>

            <label style="color:#aaa; font-size:0.9rem; margin-bottom:5px; display:block;">Selecciona un presupuesto:</label>
            <select id="selectPresupuesto" class="input-field" style="background: #2a2a2a; border: 1px solid #444; color: #fff; border-radius: 4px; padding: 10px; width: 100%; box-sizing: border-box;">
                <option value="">-- Seleccionar presupuesto --</option>
                <?php foreach ($presupuestos_existentes as $pres): ?>
                <option value="<?php echo $pres['id']; ?>">
                    #<?php echo $pres['id']; ?> - 
                    <?php echo htmlspecialchars($pres['cliente_nombre'] ?: 'Sin cliente'); ?> - 
                    $<?php echo number_format($pres['total_presupuesto'], 2, ',', '.'); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div style="margin-top: 15px; background:#2a2a2a; padding: 10px; border-radius: 5px; border-left: 3px solid #3498db;">
                <label style="color:#ccc; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="chkPrecioActual">
                    <i class="fas fa-tags"></i> Cargar con el precio actual de los productos (consulta en BD)
                </label>
                <small style="color:#888;">Si está desmarcado, se usará el precio guardado en el presupuesto original.</small>
            </div>

            <div style="margin-top: 25px; text-align: right; border-top: 1px solid #444; padding-top: 15px;">
                <button onclick="cerrarModalCopiar()" class="btn-secondary" style="padding: 10px 20px; cursor:pointer; background:#666; color:#fff; border:none; border-radius:5px; font-weight:bold;">Cancelar</button>
                <button onclick="copiarPresupuesto()" class="btn-success" style="padding: 10px 20px; cursor:pointer; margin-left:10px;">
                    <i class="fas fa-copy"></i> Copiar Productos
                </button>
            </div>
        </div>
    </div>

    <script src="<?php echo url('js/presupuestos.js'); ?>"></script>
    <script>
        /**
         * Muestra una notificación temporal tipo Toast en la esquina superior derecha.
         * Utiliza los estilos definidos en style.css.
         */
        function mostrarToast(mensaje, tipo = 'success') {
            const toast = document.createElement('div');
            toast.className = 'toast-notificacion';
            if (tipo === 'error') toast.style.background = '#e74c3c';
            toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('toast-fade-out');
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        /**
         * Sobrescribimos la función global mostrarMensaje definida en sidebar.php.
         * Esto intercepta las llamadas de presupuestos.js para que no abran modales.
         */
        window.mostrarMensaje = function(titulo, mensaje, tipo = 'success', callback = null) {
            mostrarToast(mensaje, tipo);
            // Si hay un callback (como abrir el PDF o limpiar la pantalla), lo ejecutamos tras un breve delay
            if (callback) setTimeout(callback, 1200);
        };
    </script>
</body>
</html>