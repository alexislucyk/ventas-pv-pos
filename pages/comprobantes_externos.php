<?php
// pages/comprobantes_externos.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Sistema';

if (!$empresa_id) {
    die('ERROR: Falta empresa_id en sesion.');
}

$comprobantes = [];
try {
    $stmt = $pdo->prepare("SELECT ce.*, 
                            COALESCE(SUM(vce.monto_asignado), 0) as total_asignado,
                            COUNT(vce.id) as cant_ventas
                           FROM comprobantes_externos ce
                           LEFT JOIN venta_comprobante_externo vce ON ce.id = vce.comprobante_externo_id
                           WHERE ce.empresa_id = ?
                           GROUP BY ce.id
                           ORDER BY ce.fecha_emision DESC, ce.id DESC
                           LIMIT 100");
    $stmt->execute([$empresa_id]);
    $comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $comprobantes = [];
}

$tipos_comprobante = [1 => 'Factura A', 6 => 'Factura B', 11 => 'Factura C', 51 => 'Factura M', 81 => 'Factura T'];
$condiciones_iva = ['Responsable Inscripto', 'Monotributo', 'Exento', 'Consumidor Final', 'No Responsable'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobantes Externos - POS Dev</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .ce-container { max-width: 1400px; margin: 0 auto; padding: 20px; font-family: sans-serif; }
        .ce-card { background: #1a1a1a; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #333; }
        .ce-card h2 { color: #00bcd4; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 10px; }
        .ce-form-row { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .ce-form-group { flex: 1; min-width: 200px; }
        .ce-form-group label { display: block; color: #aaa; margin-bottom: 5px; font-size: 0.9em; }
        .ce-form-group input, .ce-form-group select, .ce-form-group textarea {
            width: 100%; padding: 10px; background: #2a2a2a; border: 1px solid #444; border-radius: 6px; color: #fff; box-sizing: border-box;
        }
        .ce-form-group input:focus, .ce-form-group select:focus { border-color: #00bcd4; outline: none; }
        .ce-btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.3s; }
        .ce-btn-primary { background: #00bcd4; color: #000; }
        .ce-btn-primary:hover { background: #00acc1; }
        .ce-btn-danger { background: #e74c3c; color: #fff; }
        .ce-btn-danger:hover { background: #c0392b; }
        .ce-btn-success { background: #27ae60; color: #fff; }
        .ce-btn-success:hover { background: #219a52; }
        .ce-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .ce-table th, .ce-table td { padding: 12px; text-align: left; border-bottom: 1px solid #333; color: #ddd; }
        .ce-table th { background: #2a2a2a; color: #00bcd4; font-weight: bold; }
        .ce-table tr:hover { background: #252525; }
        .ce-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; }
        .ce-badge-success { background: #27ae60; color: #fff; }
        .ce-badge-warning { background: #f39c12; color: #000; }
        .ce-badge-info { background: #3498db; color: #fff; }
        .ce-alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 15px; display: none; }
        .ce-alert-success { background: #27ae60; color: #fff; }
        .ce-alert-error { background: #e74c3c; color: #fff; }
        .ce-progress { height: 8px; background: #333; border-radius: 4px; overflow: hidden; margin-top: 5px; }
        .ce-progress-bar { height: 100%; background: #00bcd4; transition: width 0.3s; }
        .ce-search-results { max-height: 300px; overflow-y: auto; border: 1px solid #333; border-radius: 6px; }
        .ce-search-item { padding: 12px; border-bottom: 1px solid #333; cursor: pointer; transition: background 0.2s; color: #ddd; }
        .ce-search-item:hover { background: #2a2a2a; }
        .ce-search-item:last-child { border-bottom: none; }
        .ce-search-item.selected { background: #00bcd4; color: #000; }
        .ce-tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #333; }
        .ce-tab { padding: 12px 24px; cursor: pointer; border: none; background: transparent; color: #aaa; font-weight: bold; transition: all 0.3s; }
        .ce-tab:hover { color: #00bcd4; }
        .ce-tab.active { color: #00bcd4; border-bottom: 2px solid #00bcd4; margin-bottom: -2px; }
        .ce-tab-content { display: none; }
        .ce-tab-content.active { display: block; }
        .ce-stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .ce-stat-card { flex: 1; min-width: 150px; background: #2a2a2a; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #333; }
        .ce-stat-card .number { font-size: 2em; font-weight: bold; color: #00bcd4; }
        .ce-stat-card .label { color: #aaa; font-size: 0.9em; }
    </style>
</head>
<body style="background: #111; margin: 0;">
    <div class="ce-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 style="color: #00bcd4; margin: 0;"><i class="fas fa-file-invoice"></i> Comprobantes Externos</h1>
            <a href="index.php" class="ce-btn" style="background: #333; color: #fff; text-decoration: none;"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
        <div id="alertSuccess" class="ce-alert ce-alert-success"></div>
        <div id="alertError" class="ce-alert ce-alert-error"></div>
        <div class="ce-stats">
            <div class="ce-stat-card">
                <div class="number" id="statTotalComprobantes">0</div>
                <div class="label">Comprobantes Registrados</div>
            </div>
            <div class="ce-stat-card">
                <div class="number" id="statVentasAsociadas">0</div>
                <div class="label">Ventas Asociadas</div>
            </div>
            <div class="ce-stat-card">
                <div class="number" id="statMontoTotal">$0</div>
                <div class="label">Monto Total</div>
            </div>
            <div class="ce-stat-card">
                <div class="number" id="statSinFacturar">0</div>
                <div class="label">Ventas sin Facturar</div>
            </div>
        </div>
        <div class="ce-tabs">
            <button class="ce-tab active" onclick="showTab(\'listado\', this)"><i class="fas fa-list"></i> Listado</button>
            <button class="ce-tab" onclick="showTab(\'nuevo\', this)"><i class="fas fa-plus"></i> Nuevo Comprobante</button>
            <button class="ce-tab" onclick="showTab(\'asociar\', this)"><i class="fas fa-link"></i> Asociar Ventas</button>
        </div>
        <div id="tab-listado" class="ce-tab-content active">
            <div class="ce-card">
                <h2><i class="fas fa-file-alt"></i> Comprobantes Externos Registrados</h2>
                <div style="overflow-x: auto;">
                    <table class="ce-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Comprobante</th>
                                <th>Cliente</th>
                                <th>CUIT</th>
                                <th>Total</th>
                                <th>Asignado</th>
                                <th>Ventas</th>
                                <th>Estado</th>
                                <th>Accion</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyComprobantes">
                            <?php if (empty($comprobantes)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; color: #666; padding: 30px;">No hay comprobantes externos registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($comprobantes as $ce): 
                                    $porcentaje = $ce['total_comprobante'] > 0 ? ($ce['total_asignado'] / $ce['total_comprobante']) * 100 : 0;
                                    $estado = $porcentaje >= 100 ? 'Completo' : ($porcentaje > 0 ? 'Parcial' : 'Pendiente');
                                    $badge_class = $porcentaje >= 100 ? 'ce-badge-success' : ($porcentaje > 0 ? 'ce-badge-warning' : 'ce-badge-info');
                                ?>
                                    <tr data-id="<?php echo $ce['id']; ?>">
                                        <td><?php echo date('d/m/Y', strtotime($ce['fecha_emision'])); ?></td>
                                        <td><?php echo $tipos_comprobante[$ce['tipo_comprobante']] ?? 'Otro'; ?> <?php echo str_pad($ce['punto_venta'], 4, '0', STR_PAD_LEFT); ?>-<?php echo str_pad($ce['n_comprobante'], 8, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo htmlspecialchars($ce['razon_social_cliente']); ?></td>
                                        <td><?php echo htmlspecialchars($ce['cuit_cliente']); ?></td>
                                        <td>$<?php echo number_format($ce['total_comprobante'], 2, ',', '.'); ?></td>
                                        <td>$<?php echo number_format($ce['total_asignado'], 2, ',', '.'); ?><div class="ce-progress"><div class="ce-progress-bar" style="width: <?php echo min($porcentaje, 100); ?>%"></div></div></td>
                                        <td><?php echo $ce['cant_ventas']; ?></td>
                                        <td><span class="ce-badge <?php echo $badge_class; ?>"><?php echo $estado; ?></span></td>
                                        <td><button class="ce-btn ce-btn-danger" style="padding: 6px 12px; font-size: 0.85em;" onclick="eliminarComprobante(<?php echo $ce['id']; ?>)"><i class="fas fa-trash"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="tab-nuevo" class="ce-tab-content">
            <div class="ce-card">
                <h2><i class="fas fa-plus-circle"></i> Registrar Nuevo Comprobante Externo</h2>
                <form id="formNuevoComprobante" onsubmit="return guardarComprobante(event)">
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>Tipo de Comprobante *</label>
                            <select id="tipo_comprobante" required>
                                <?php foreach ($tipos_comprobante as $id => $nombre): ?>
                                    <option value="<?php echo $id; ?>" <?php echo $id == 11 ? 'selected' : ''; ?>><?php echo $nombre; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ce-form-group">
                            <label>Punto de Venta *</label>
                            <input type="number" id="punto_venta" min="1" value="1" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Numero de Comprobante *</label>
                            <input type="number" id="n_comprobante" min="1" required>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>Fecha de Emision *</label>
                            <input type="date" id="fecha_emision" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Total del Comprobante *</label>
                            <input type="number" id="total_comprobante" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>CUIT del Cliente *</label>
                            <input type="text" id="cuit_cliente" placeholder="20301234567" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Razon Social del Cliente *</label>
                            <input type="text" id="razon_social_cliente" required>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>Condicion IVA</label>
                            <select id="cond_iva"><option value="">-- Seleccionar --</option>
                                <?php foreach ($condiciones_iva as $nombre): ?>
                                    <option value="<?php echo $nombre; ?>"><?php echo $nombre; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group" style="flex: 100%;">
                            <label>Observaciones</label>
                            <textarea id="observaciones" rows="3"></textarea>
                        </div>
                    </div>
                    <div style="text-align: right; margin-top: 15px;">
                        <button type="submit" class="ce-btn ce-btn-primary"><i class="fas fa-save"></i> Guardar Comprobante</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="tab-asociar" class="ce-tab-content">
            <div class="ce-card">
                <h2><i class="fas fa-search"></i> Buscar Ventas sin Facturar</h2>
                <div class="ce-form-row">
                    <div class="ce-form-group">
                        <label>Fecha Desde</label>
                        <input type="date" id="buscar_fecha_desde">
                    </div>
                    <div class="ce-form-group">
                        <label>Fecha Hasta</label>
                        <input type="date" id="buscar_fecha_hasta">
                    </div>
                    <div class="ce-form-group">
                        <label>Cliente / N Doc</label>
                        <input type="text" id="buscar_cliente" placeholder="Buscar por nombre, CUIT o N documento">
                    </div>
                    <div class="ce-form-group" style="display: flex; align-items: flex-end;">
                        <button class="ce-btn ce-btn-primary" onclick="buscarVentasSinFacturar()"><i class="fas fa-search"></i> Buscar</button>
                    </div>
                </div>
                <div id="resultadosBusqueda" class="ce-search-results" style="display: none;"></div>
            </div>
            <div class="ce-card" id="cardAsociacion" style="display: none;">
                <h2><i class="fas fa-link"></i> Asociar Venta a Comprobante</h2>
                <div class="ce-form-row">
                    <div class="ce-form-group">
                        <label>Venta Seleccionada</label>
                        <input type="text" id="venta_seleccionada" readonly style="background: #333;">
                        <input type="hidden" id="venta_id_seleccionada">
                    </div>
                </div>
                <div class="ce-form-row">
                    <div class="ce-form-group">
                        <label>Comprobante Externo *</label>
                        <select id="comprobante_asociacion" required>
                            <option value="">-- Seleccionar Comprobante --</option>
                            <?php foreach ($comprobantes as $ce): ?>
                                <option value="<?php echo $ce['id']; ?>" data-disponible="<?php echo $ce['total_comprobante'] - $ce['total_asignado']; ?>">
                                    <?php echo $tipos_comprobante[$ce['tipo_comprobante']] ?? 'Otro'; ?> 
                                    <?php echo str_pad($ce['punto_venta'], 4, '0', STR_PAD_LEFT); ?>-<?php echo str_pad($ce['n_comprobante'], 8, '0', STR_PAD_LEFT); ?>
                                    (<?php echo htmlspecialchars($ce['razon_social_cliente']); ?>)
                                    - Disponible: $<?php echo number_format($ce['total_comprobante'] - $ce['total_asignado'], 2, ',', '.'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ce-form-group">
                        <label>Monto a Asignar *</label>
                        <input type="number" id="monto_asociacion" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div style="text-align: right; margin-top: 15px;">
                    <button class="ce-btn ce-btn-success" onclick="asociarVenta()"><i class="fas fa-link"></i> Asociar Venta</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        let ventaSeleccionada = null;

        function mostrarAlerta(tipo, mensaje) {
            const alertSuccess = document.getElementById("alertSuccess");
            const alertError = document.getElementById("alertError");
            if (tipo === "success") {
                alertSuccess.innerHTML = "&check; " + mensaje;
                alertSuccess.style.display = "block";
                alertError.style.display = "none";
            } else {
                alertError.innerHTML = "X " + mensaje;
                alertError.style.display = "block";
                alertSuccess.style.display = "none";
            }
            setTimeout(function() { alertSuccess.style.display = "none"; alertError.style.display = "none"; }, 5000);
        }

        function showTab(tabName, btn) {
            document.querySelectorAll(".ce-tab").forEach(function(t) { t.classList.remove("active"); });
            document.querySelectorAll(".ce-tab-content").forEach(function(c) { c.classList.remove("active"); });
            if (btn) { btn.classList.add("active"); }
            document.getElementById("tab-" + tabName).classList.add("active");
        }

        function guardarComprobante(e) {
            e.preventDefault();
            var datos = {
                punto_venta: document.getElementById("punto_venta").value,
                n_comprobante: document.getElementById("n_comprobante").value,
                tipo_comprobante: document.getElementById("tipo_comprobante").value,
                fecha_emision: document.getElementById("fecha_emision").value,
                total_comprobante: document.getElementById("total_comprobante").value,
                cuit_cliente: document.getElementById("cuit_cliente").value,
                razon_social_cliente: document.getElementById("razon_social_cliente").value,
                cond_iva: document.getElementById("cond_iva").value,
                observaciones: document.getElementById("observaciones").value
            };
            var formBody = Object.keys(datos).map(function(k) { return encodeURIComponent(k) + "=" + encodeURIComponent(datos[k]); }).join("&");
            fetch("<?php echo URL_BASE; ?>ajax/guardar_comprobante_externo.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: formBody
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data.status === "success") { mostrarAlerta("success", data.message); setTimeout(function() { location.reload(); }, 1500); }
                else { mostrarAlerta("error", data.message); }
            }).catch(function(err) { mostrarAlerta("error", "Error de conexion: " + err.message); });
            return false;
        }

        function buscarVentasSinFacturar() {
            var fecha_desde = document.getElementById("buscar_fecha_desde").value;
            var fecha_hasta = document.getElementById("buscar_fecha_hasta").value;
            var cliente = document.getElementById("buscar_cliente").value;
            var params = new URLSearchParams();
            if (fecha_desde) params.append("fecha_desde", fecha_desde);
            if (fecha_hasta) params.append("fecha_hasta", fecha_hasta);
            if (cliente) params.append("cliente", cliente);
            fetch("<?php echo URL_BASE; ?>ajax/buscar_ventas_sin_facturar.php?" + params.toString())
            .then(function(r) { return r.json(); }).then(function(data) {
                var contenedor = document.getElementById("resultadosBusqueda");
                if (data.status === "success") {
                    if (data.data.length === 0) {
                        contenedor.innerHTML = "<div style=\"padding:20px;text-align:center;color:#666;\">No se encontraron ventas sin facturar.</div>";
                    } else {
                        var html = "";
                        data.data.forEach(function(v) {
                            html += "<div class=\"ce-search-item\" onclick=\"seleccionarVenta(" + v.venta_id + ", '" + v.n_documento + "', " + v.total_venta + ", '" + v.cliente.replace(/'/g, "\\'") + "')\">";
                            html += "<div style=\"display:flex;justify-content:space-between;\"><div><strong>N " + v.n_documento + "</strong> - " + v.cliente + "<br><small style=\"color:#888;\">" + v.fecha_venta + " | " + v.cond_pago + "</small></div>";
                            html += "<div style=\"text-align:right;\"><strong style=\"color:#00bcd4;\">$" + parseFloat(v.total_venta).toLocaleString("es-AR", {minimumFractionDigits:2}) + "</strong></div></div></div>";
                        });
                        contenedor.innerHTML = html;
                    }
                    contenedor.style.display = "block";
                } else { mostrarAlerta("error", data.message); }
            }).catch(function(err) { mostrarAlerta("error", "Error: " + err.message); });
        }
        function seleccionarVenta(id, nDocumento, total, cliente) {
            ventaSeleccionada = { id: id, nDocumento: nDocumento, total: total, cliente: cliente };
            document.getElementById("venta_id_seleccionada").value = id;
            document.getElementById("venta_seleccionada").value = "N " + nDocumento + " - " + cliente + " ($" + parseFloat(total).toLocaleString("es-AR", {minimumFractionDigits:2}) + ")";
            document.getElementById("monto_asociacion").value = total;
            document.getElementById("cardAsociacion").style.display = "block";
            document.querySelectorAll(".ce-search-item").forEach(function(item) { item.classList.remove("selected"); });
            if (event && event.currentTarget) { event.currentTarget.classList.add("selected"); }
        }

        function asociarVenta() {
            var venta_id = document.getElementById("venta_id_seleccionada").value;
            var comprobante_id = document.getElementById("comprobante_asociacion").value;
            var monto = document.getElementById("monto_asociacion").value;
            if (!venta_id || !comprobante_id || !monto) { mostrarAlerta("error", "Complete todos los campos obligatorios."); return; }
            var datos = "venta_id=" + venta_id + "&comprobante_externo_id=" + comprobante_id + "&monto_asignado=" + monto;
            fetch("<?php echo URL_BASE; ?>ajax/asociar_venta_comprobante.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: datos
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data.status === "success") { mostrarAlerta("success", data.message); setTimeout(function() { location.reload(); }, 1500); }
                else { mostrarAlerta("error", data.message); }
            }).catch(function(err) { mostrarAlerta("error", "Error: " + err.message); });
        }

        function eliminarComprobante(id) {
            if (confirm("Esta seguro de eliminar este comprobante externo?")) {
                mostrarAlerta("error", "Funcion de eliminacion pendiente de implementar.");
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            var filas = document.querySelectorAll("#tbodyComprobantes tr[data-id]");
            var totalComp = 0, ventasAsoc = 0, montoTotal = 0;
            filas.forEach(function(fila) {
                totalComp++;
                var cant = parseInt(fila.querySelector("td:nth-child(7)").textContent) || 0;
                var montoTxt = fila.querySelector("td:nth-child(5)").textContent.replace(/[^\d,]/g, "").replace(",", ".");
                ventasAsoc += cant;
                montoTotal += parseFloat(montoTxt) || 0;
            });
            document.getElementById("statTotalComprobantes").textContent = totalComp;
            document.getElementById("statVentasAsociadas").textContent = ventasAsoc;
            document.getElementById("statMontoTotal").textContent = "$" + montoTotal.toLocaleString("es-AR", {minimumFractionDigits:2, maximumFractionDigits:2});
        });
    </script>
</body>
</html>
