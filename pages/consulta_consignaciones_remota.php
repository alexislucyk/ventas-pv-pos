<?php
// Página de consulta de consignaciones remota
// Acceso exclusivo por VPN Radmin - No requiere login del sistema
// Si hay sesión activa del sistema, se integra sidebar/topbar para navegación.
$sesion_activa = (session_status() === PHP_SESSION_ACTIVE) && isset($_SESSION['usuario_id']) && isset($_SESSION['empresa_id']);
if ($sesion_activa) {
    include __DIR__ . '/infosesion.php';
}

// URL segura para el botón Volver (soporta acceso directo sin router)
$url_dashboard = (function_exists('route')) ? route('dashboard') : (defined('URL_BASE') ? URL_BASE . 'index.php' : 'index.php');

// Base de la API: funciona con acceso por clean URL (/ventas_dev/consulta-consignaciones)
// y con acceso directo al archivo por IP/VPN (pages/consulta_consignaciones_remota.php)
if (function_exists('url')) {
    $api_base = rtrim(url('api'), '/');
} elseif (defined('URL_BASE')) {
    $api_base = rtrim(URL_BASE, '/') . '/api';
} else {
    $api_base = '../api';
}
// Protocolo detectado para construir URLs absolutas cuando se usa la IP del servidor
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Consignaciones Remota</title>
    <link rel="stylesheet" href="<?php echo (function_exists('url') ? url('css/style.css') : '../css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/consignaciones.css'); ?>">
    <style>/* Dinamico: padding condicional por sesion PHP */ body { padding: <?php echo $sesion_activa ? '0' : '20px'; ?>; }</style>
</head>
<body>
    <?php if ($sesion_activa): ?>
        <?php include 'sidebar.php'; ?>
        <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
    <?php endif; ?>

    <div class="container">
        <div class="header">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <h1><i class="fas fa-handshake"></i> Consulta de Consignaciones Remota</h1>
                <a href="<?php echo $url_dashboard; ?>" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver al Dashboard</a>
            </div>
        </div>

        <div class="config-box">
            <label for="server_ip">IP del Servidor (accesible por VPN Radmin):</label>
            <input type="text" id="server_ip" placeholder="Ej: 192.168.1.100" value="">
            <button class="btn" onclick="guardarIP()" style="margin-top: 10px;">Guardar IP</button>
            <span id="ip_status" style="margin-left: 10px; color: #2ecc71;"></span>
        </div>

        <div class="filtros">
            <div class="filtro-group">
                <label for="proveedor">Proveedor en Consignación:</label>
                <select id="proveedor" required>
                    <option value="">-- Seleccionar Proveedor --</option>
                </select>
            </div>
            <div class="btn-reload-wrapper">
                <button type="button" class="btn btn-small" onclick="cargarProveedores()">
                    <i class="fas fa-sync-alt"></i> Recargar Proveedores
                </button>
            </div>
            <div class="filtro-group">
                <label for="desde">Desde:</label>
                <input type="date" id="desde" value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div class="filtro-group">
                <label for="hasta">Hasta:</label>
                <input type="date" id="hasta" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="filtro-group">
                <button class="btn" onclick="consultarConsignaciones()">
                        <i class="fas fa-search"></i> Consultar
                </button>
            </div>
            
        </div>

        <div id="resultados">
            <div class="no-data">
                <i>🔍</i>
                <p>Seleccione un proveedor y un rango de fechas para visualizar la liquidación.</p>
            </div>
        </div>
    </div>

    <?php if ($sesion_activa): ?>
        </div><!-- /.content -->
    <?php endif; ?>

    <!-- Modal System -->
    <div id="modalOverlay" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalIcon" class="modal-icon"></span>
                <h3 id="modalTitle" class="modal-title"></h3>
            </div>
            <p id="modalMessage" class="modal-message"></p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-primary" onclick="closeModal()">Aceptar</button>
            </div>
        </div>
    </div>

    <script>
        // Construir la URL base para las consultas a la API.
        // Si se ingresó una IP del servidor, las consultas se dirigen a esa IP.
        // Si no, se usan rutas relativas (comportamiento original).
        function getApiBaseUrl() {
            const serverIP = localStorage.getItem('server_ip') || document.getElementById('server_ip').value.trim();
            const phpApiBase = '<?php echo $api_base; ?>';
            const protocol = '<?php echo $protocol; ?>';

            if (!serverIP) {
                return phpApiBase;
            }

            let apiPath;
            if (phpApiBase.startsWith('http://') || phpApiBase.startsWith('https://')) {
                apiPath = new URL(phpApiBase).pathname;
            } else if (phpApiBase.startsWith('/')) {
                apiPath = phpApiBase;
            } else {
                // Ruta relativa (ej. ../api) - resolver respecto a la ubicación actual
                apiPath = new URL(phpApiBase, window.location.href).pathname;
            }

            return protocol + serverIP + apiPath;
        }

        let proveedores = [];
        // Inicializar IP guardada y cargar proveedores

        document.addEventListener('DOMContentLoaded', function() {
            const serverIP = localStorage.getItem('server_ip') || '';
            if (serverIP) {
                document.getElementById('server_ip').value = serverIP;
                document.getElementById('ip_status').textContent = '✓ IP guardada: ' + serverIP;
            } else {
                const host = window.location.hostname;
                document.getElementById('server_ip').value = host;
                document.getElementById('ip_status').textContent = '✓ Conectado al servidor: ' + host;
            }
            // Cargar proveedores automáticamente (funciona con localhost y con IP VPN)
            cargarProveedores();
        });

        // La IP guardada se usa como host para las consultas a la API.
        function guardarIP() {
            const ip = document.getElementById('server_ip').value.trim();
            if (ip) {
                localStorage.setItem('server_ip', ip);
                document.getElementById('ip_status').textContent = '✓ IP guardada: ' + ip;
                showModal('success', 'Éxito', 'IP del servidor guardada correctamente');
                // Recargar proveedores
                cargarProveedores();
            } else {
                showModal('error', 'Error', 'Por favor ingrese una IP válida');
            }
        }

        async function cargarProveedores() {
            console.log('Iniciando cargarProveedores...');
            console.log('Servidor actual:', window.location.hostname);

            try {
                // Usar IP del servidor si está configurada, o ruta relativa por defecto
                let url = getApiBaseUrl() + '/proveedores.php?token=consignaciones_remote_2024_vpn';
                
                console.log('Consultando URL:', url);
                
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Error response:', errorText);
                    throw new Error('Error HTTP: ' + response.status + ' - ' + response.statusText);
                }

                const data = await response.json();
                console.log('Datos recibidos:', data);
                
                if (data.success && data.proveedores && data.proveedores.length > 0) {
                    const select = document.getElementById('proveedor');
                    select.innerHTML = '<option value="">-- Seleccionar Proveedor --</option>';
                    data.proveedores.forEach(prov => {
                        const option = document.createElement('option');
                        option.value = prov;
                        option.textContent = prov;
                        select.appendChild(option);
                    });
                    console.log('✓ Proveedores cargados:', data.proveedores.length);
                } else {
                    const select = document.getElementById('proveedor');
                    select.innerHTML = '<option value="">-- No hay proveedores disponibles --</option>';
                    console.warn('⚠ No se encontraron proveedores en el sistema');
                }
                
            } catch (error) {
                console.error('✗ Error completo:', error);
                let mensaje = 'No se pudieron cargar los proveedores.\n\n';
                mensaje += 'Error: ' + error.message + '\n\n';
                mensaje += 'Verifique en la consola del navegador (F12) la URL exacta que se intentó consultar.';
                showModal('error', 'Error de Conexión', mensaje);
            }
        }

        async function consultarConsignaciones() {
            const proveedor = document.getElementById('proveedor').value;
            const desde = document.getElementById('desde').value;
            const hasta = document.getElementById('hasta').value;

            if (!proveedor) {
                showModal('info', 'Información', 'Por favor seleccione un proveedor');
                return;
            }

            const resultadosDiv = document.getElementById('resultados');
            resultadosDiv.innerHTML = '<div class="loading">⏳ Cargando datos...</div>';

            try {
                // Usar IP del servidor si está configurada, o ruta relativa por defecto
                let url = getApiBaseUrl() + '/consignaciones.php?token=consignaciones_remote_2024_vpn&proveedor=' + encodeURIComponent(proveedor) + '&desde=' + desde + '&hasta=' + hasta;
                
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.mensaje || errorData.error || 'Error en la consulta');
                }

                const data = await response.json();
                
                if (data.success) {
                    mostrarResultados(data);
                } else {
                    throw new Error(data.error || 'Error desconocido');
                }

            } catch (error) {
                resultadosDiv.innerHTML = `
                    <div class="alert alert-error">
                        ❌ Error: ${error.message}
                    </div>
                `;
            }
        }

        function mostrarResultados(data) {
            const resultadosDiv = document.getElementById('resultados');
            
            let html = `
                <div class="resumen">
                    <div class="mini-card">
                        <h4>Ventas Totales</h4>
                        <span class="monto">$ ${formatearNumero(data.totales.venta)}</span>
                    </div>
                    <div class="mini-card" style="border-top-color: #e74c3c;">
                        <h4>Costo de Mercadería</h4>
                        <span class="monto">$ ${formatearNumero(data.totales.costo)}</span>
                    </div>
                    <div class="mini-card" style="border-top-color: #2ecc71;">
                        <h4>Ganancia Líquida</h4>
                        <span class="monto">$ ${formatearNumero(data.totales.ganancia)}</span>
                    </div>
                </div>

                <div class="reparto-box">
                    <div class="reparto-item">
                        <h4>Total a Pagar al Proveedor</h4>
                        <span style="color: #f39c12;">$ ${formatearNumero(data.totales.pagar_proveedor)}</span>
                        <p style="margin-top: 10px; font-size: 0.9em; color: #aaa;">
                            Costo de Mercadería: <strong>$ ${formatearNumero(data.totales.costo)}</strong><br>
                            50% Ganancia Proveedor: <strong>$ ${formatearNumero(data.totales.ganancia / 2)}</strong>
                        </p>
                    </div>
                    <div style="border-left: 1px solid #333;"></div>
                    <div class="reparto-item">
                        <h4>Utilidad Neta para el Negocio</h4>
                        <span style="color: #00bcd4;">$ ${formatearNumero(data.totales.utilidad_negocio)}</span>
                    </div>
                </div>

                <div class="tabla-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-right">Cant.</th>
                                <th class="text-right">Venta Unit.</th>
                                <th class="text-right">Costo Unit.</th>
                                <th class="text-right">Venta Total</th>
                                <th class="text-right">Ganancia Bruta</th>
                                <th class="text-right" style="color: #2ecc71;">Mi Parte (50%)</th>
                                <th class="text-right" style="color: #f39c12;">Prov. (50%)</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            if (data.detalle.length === 0) {
                html += `<tr><td colspan="8" style="text-align: center; padding: 30px;">No se registraron ventas de este proveedor en el rango seleccionado.</td></tr>`;
            } else {
                data.detalle.forEach(item => {
                    html += `
                        <tr>
                            <td><strong>${item.descripcion}</strong><br><small>${item.cod_prod}</small></td>
                            <td class="text-right">${formatearNumero(item.total_cant)}</td>
                            <td class="text-right">$ ${formatearNumero(item.precio_venta)}</td>
                            <td class="text-right">$ ${formatearNumero(item.costo_unitario)}</td>
                            <td class="text-right">$ ${formatearNumero(item.subtotal_venta)}</td>
                            <td class="text-right" style="font-weight: bold;">$ ${formatearNumero(item.ganancia_total)}</td>
                            <td class="text-right" style="color: #2ecc71;">$ ${formatearNumero(item.mi_parte)}</td>
                            <td class="text-right" style="color: #f39c12;">$ ${formatearNumero(item.parte_proveedor)}</td>
                        </tr>
                    `;
                });
            }

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            resultadosDiv.innerHTML = html;
        }

        // Modal System Functions
        function showModal(type, title, message) {
            const modal = document.getElementById('modalOverlay');
            const icon = document.getElementById('modalIcon');
            const titleEl = document.getElementById('modalTitle');
            const messageEl = document.getElementById('modalMessage');
            
            // Set icon based on type
            icon.className = 'modal-icon ' + type;
            if (type === 'error') {
                icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
            } else if (type === 'success') {
                icon.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else if (type === 'info') {
                icon.innerHTML = '<i class="fas fa-info-circle"></i>';
            }
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('modalOverlay').classList.remove('active');
        }

        function formatearNumero(numero) {
            return numero.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    </script>
</body>
</html>
