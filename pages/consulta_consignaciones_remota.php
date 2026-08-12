<?php
// Página de consulta de consignaciones remota
// Acceso exclusivo por VPN Radmin - No requiere login del sistema
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Consignaciones Remota</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #1a1a1a;
            color: #fff;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #00bcd4;
        }
        .header h1 {
            margin: 0;
            color: #00bcd4;
            font-size: 1.8rem;
        }
        .header p {
            margin: 10px 0 0 0;
            color: #888;
            font-size: 0.9rem;
        }
        .config-box {
            background: #252525;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #444;
        }
        .config-box label {
            display: block;
            margin-bottom: 5px;
            color: #aaa;
            font-size: 0.9rem;
        }
        .config-box input {
            width: 100%;
            padding: 10px;
            background: #1a1a1a;
            border: 1px solid #444;
            color: #fff;
            border-radius: 4px;
            font-size: 1rem;
        }
        .filtros {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            grid-template-rows: auto auto;
            gap: 10px 15px;
            align-items: end;
        }
        .filtros .filtro-group input,
        .filtros .filtro-group select,
        .filtros > .btn {
            min-height: 42px;
            box-sizing: border-box;
        }
        .filtros .filtro-group input,
        .filtros .filtro-group select {
            /* Anula el margin-bottom: 15px !important de css/style.css
               para que los inputs queden alineados con el botón Consultar */
            margin-bottom: 0 !important;
        }
        .filtros .filtro-group {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            min-height: 56px;
        }
        .filtros .filtro-group label {
            margin-bottom: 5px;
        }
        .filtros .filtro-group:first-child {
            grid-column: 1;
            grid-row: 1;
        }
        .filtros .btn-reload-wrapper {
            grid-column: 1;
            grid-row: 2;
            align-self: start;
        }
        .filtros .filtro-group:nth-child(3) {
            grid-column: 2;
            grid-row: 1;
        }
        .filtros .filtro-group:nth-child(4) {
            grid-column: 3;
            grid-row: 1;
        }
        .filtros > .btn {
            grid-column: 4;
            grid-row: 1;
            margin-bottom: 0;
            height: 42px;
        }
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            color: #aaa;
            font-size: 0.9rem;
        }
        .filtro-group input, .filtro-group select {
            width: 100%;
            padding: 10px;
            background: #1a1a1a;
            border: 1px solid #444;
            color: #fff;
            border-radius: 4px;
            font-size: 1rem;
        }
        .btn {
            padding: 10px 20px;
            background: #00bcd4;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
        }
        .btn:hover {
            background: #00acc1;
        }
        .btn:disabled {
            background: #555;
            cursor: not-allowed;
        }
        .resumen {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .mini-card {
            background: #252525;
            padding: 15px;
            border-radius: 8px;
            border-top: 3px solid #00bcd4;
            text-align: center;
        }
        .mini-card h4 {
            margin: 0;
            color: #888;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .mini-card .monto {
            font-size: 1.4rem;
            font-weight: bold;
            margin-top: 10px;
            display: block;
            color: #fff;
        }
        .reparto-box {
            background: #1a1a1a;
            padding: 20px;
            border-radius: 8px;
            border: 1px dashed #444;
            margin-top: 20px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
        }
        .reparto-item {
            text-align: center;
            flex: 1;
            min-width: 200px;
        }
        .reparto-item span {
            font-size: 1.8rem;
            color: #2ecc71;
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        .tabla-container {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #444;
        }
        th {
            background: #1a1a1a;
            color: #00bcd4;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        td {
            color: #fff;
        }
        .text-right {
            text-align: right;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #e74c3c;
            color: #fff;
        }
        .alert-success {
            background: #2ecc71;
            color: #fff;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #888;
        }
        .no-data {
            text-align: center;
            padding: 50px;
            color: #666;
        }
        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            display: block;
        }
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #252525;
            padding: 25px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            border-left: 4px solid #00bcd4;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }
        .modal-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .modal-icon {
            font-size: 2rem;
            margin-right: 15px;
        }
        .modal-icon.error {
            color: #e74c3c;
        }
        .modal-icon.success {
            color: #2ecc71;
        }
        .modal-icon.info {
            color: #00bcd4;
        }
        .modal-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #fff;
            margin: 0;
        }
        .modal-message {
            color: #aaa;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        .modal-btn-primary {
            background: #00bcd4;
            color: #fff;
        }
        .modal-btn-primary:hover {
            background: #00acc1;
        }
        .modal-btn-secondary {
            background: #555;
            color: #fff;
        }
        .modal-btn-secondary:hover {
            background: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-handshake"></i> Consulta de Consignaciones Remota</h1>
            
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
        // La página y la API están en el mismo servidor, por lo que usamos
        // rutas relativas (../api/...). Esto funciona igual tanto si se accede
        // por localhost/127.0.0.1 como por la IP de VPN Radmin (ej: 26.223.130.54).

        let proveedores = [];

        // Inicializar IP guardada (solo informativa) y cargar proveedores
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

        // El campo de IP es solo informativo. La conexión usa rutas relativas,
        // por lo que no es necesario volver a conectar.
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
                // Ruta relativa desde pages/ hacia api/ (mismo servidor)
                let url = `../api/proveedores.php?token=consignaciones_remote_2024_vpn`;
                
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
                // Ruta relativa desde pages/ hacia api/ (mismo servidor)
                let url = `../api/consignaciones.php?token=consignaciones_remote_2024_vpn&proveedor=${encodeURIComponent(proveedor)}&desde=${desde}&hasta=${hasta}`;
                
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
