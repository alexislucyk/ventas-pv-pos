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
    <title>Comprobantes Externos | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/comprobantes_externos.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
    <div class="ce-container">
        <div class="page-head">
            <div class="page-title"><div class="icon"><i class="fas fa-file-invoice"></i></div><div><h1>Comprobantes Externos</h1><div class="sub">Facturaci&oacute;n externa (AFIP / otro sistema) asociada a ventas del POS</div></div></div>
            <div class="page-actions"><a href="<?php echo URL_BASE; ?>" class="btn-action secondary"><i class="fas fa-arrow-left"></i> Volver</a></div>
        </div>
        <div id="mensaje" class="alert-box"></div>
        
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div><div><div class="stat-label">Comprobantes Registrados</div><div class="stat-value accent" id="statTotalComprobantes">0</div></div></div>
            <div class="stat-card"><div class="stat-icon link"><i class="fas fa-link"></i></div><div><div class="stat-label">Ventas Asociadas</div><div class="stat-value" id="statVentasAsociadas">0</div></div></div>
            <div class="stat-card"><div class="stat-icon ok"><i class="fas fa-dollar-sign"></i></div><div><div class="stat-label">Monto Total</div><div class="stat-value ok" id="statMontoTotal">$0</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-exclamation-circle"></i></div><div><div class="stat-label">Ventas sin Facturar</div><div class="stat-value" id="statSinFacturar">0</div></div></div>
        </div>
        <div class="tabs-ce">
            <button class="tab-btn active" onclick="showTab('listado', this)"><i class="fas fa-list"></i> Listado</button>
            <button class="tab-btn" onclick="showTab('nuevo', this)"><i class="fas fa-plus"></i> Nuevo Comprobante</button>
            <button class="tab-btn" onclick="showTab('asociar', this)"><i class="fas fa-link"></i> Asociar Ventas</button>
        </div>
        <div id="tab-listado" class="tab-panel active">
            <div class="panel">
                <div class="panel-head"><h3 class="panel-title"><i class="fas fa-file-alt"></i> Comprobantes Externos Registrados</h3></div>
                <div class="table-wrap">
                    <table class="mov-table">
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
                                    <td colspan="9"><div class="empty-state"><i class="fas fa-file-invoice"></i>No hay comprobantes externos registrados.</div></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($comprobantes as $ce): 
                                    $porcentaje = $ce['total_comprobante'] > 0 ? ($ce['total_asignado'] / $ce['total_comprobante']) * 100 : 0;
                                    $estado = $porcentaje >= 100 ? 'Completo' : ($porcentaje > 0 ? 'Parcial' : 'Pendiente');
                                    $badge_class = $porcentaje >= 100 ? 'pill ok' : ($porcentaje > 0 ? 'pill pend' : 'pill info');
                                ?>
                                    <tr data-id="<?php echo $ce['id']; ?>">
                                        <td><?php echo date('d/m/Y', strtotime($ce['fecha_emision'])); ?></td>
                                        <td><?php echo $tipos_comprobante[$ce['tipo_comprobante']] ?? 'Otro'; ?> <?php echo str_pad($ce['punto_venta'], 4, '0', STR_PAD_LEFT); ?>-<?php echo str_pad($ce['n_comprobante'], 8, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo htmlspecialchars($ce['razon_social_cliente']); ?></td>
                                        <td><?php echo htmlspecialchars($ce['cuit_cliente']); ?></td>
                                        <td class="monto">$<?php echo number_format($ce['total_comprobante'], 2, ',', '.'); ?></td>
                                        <td class="cell-num">$<?php echo number_format($ce['total_asignado'], 2, ',', '.'); ?><div class="ce-progress"><div class="ce-progress-bar" style="width: <?php echo min($porcentaje, 100); ?>%"></div></div></td>
                                        <td><?php echo $ce['cant_ventas']; ?></td>
                                        <td><span class="<?php echo $badge_class; ?>"><?php echo $estado; ?></span></td>
                                        <td><button class="btn-mini del" onclick="eliminarComprobante(<?php echo $ce['id']; ?>)"><i class="fas fa-trash"></i> Eliminar</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="tab-nuevo" class="tab-panel">
            <div class="panel">
                <div class="panel-head"><h3 class="panel-title"><i class="fas fa-plus-circle"></i> Registrar Nuevo Comprobante Externo</h3></div>
                <form id="formNuevoComprobante" onsubmit="return guardarComprobante(event)">
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>Tipo de Comprobante <span class="req">*</span></label>
                            <select id="tipo_comprobante" required>
                                <?php foreach ($tipos_comprobante as $id => $nombre): ?>
                                    <option value="<?php echo $id; ?>" <?php echo $id == 11 ? 'selected' : ''; ?>><?php echo $nombre; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ce-form-group">
                            <label>Punto de Venta <span class="req">*</span></label>
                            <input type="number" id="punto_venta" min="1" value="1" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Numero de Comprobante <span class="req">*</span></label>
                            <input type="number" id="n_comprobante" min="1" required>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>Fecha de Emision <span class="req">*</span></label>
                            <input type="date" id="fecha_emision" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Total del Comprobante <span class="req">*</span></label>
                            <input type="number" id="total_comprobante" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="ce-form-row">
                        <div class="ce-form-group">
                            <label>CUIT del Cliente <span class="req">*</span></label>
                            <input type="text" id="cuit_cliente" placeholder="20301234567" required>
                        </div>
                        <div class="ce-form-group">
                            <label>Razon Social del Cliente <span class="req">*</span></label>
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
                        <div class="ce-form-group full">
                            <label>Observaciones</label>
                            <textarea id="observaciones" rows="3"></textarea>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                        <button type="submit" class="btn-action primary"><i class="fas fa-save"></i> Guardar Comprobante</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="tab-asociar" class="tab-panel">
            <div class="panel">
                <div class="panel-head"><h3 class="panel-title"><i class="fas fa-search"></i> Buscar Ventas sin Facturar</h3></div>
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
                    <div class="ce-form-group" style="display: flex; align-items: flex-end; min-width: auto;">
                        <button type="button" class="btn-action primary" onclick="buscarVentasSinFacturar()"><i class="fas fa-search"></i> Buscar</button>
                    </div>
                </div>
                <div id="resultadosBusqueda" class="ce-search-results"></div>
            </div>
            <div class="panel" id="cardAsociacion" style="display: none;">
                <div class="panel-head"><h3 class="panel-title"><i class="fas fa-link"></i> Asociar Venta a Comprobante</h3></div>
                <div class="ce-form-row">
                    <div class="ce-form-group">
                        <label>Venta Seleccionada</label>
                        <input type="text" id="venta_seleccionada" readonly placeholder="Seleccione una venta del listado de resultados">
                        <input type="hidden" id="venta_id_seleccionada">
                    </div>
                </div>
                <div class="ce-form-row">
                    <div class="ce-form-group">
                        <label>Comprobante Externo <span class="req">*</span></label>
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
                        <label>Monto a Asignar <span class="req">*</span></label>
                        <input type="number" id="monto_asociacion" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                    <button type="button" class="btn-action success" onclick="asociarVenta()"><i class="fas fa-link"></i> Asociar Venta</button>
                </div>
            </div>
        </div>
    </div>
</div>
    <script>
        let ventaSeleccionada = null;

        function mostrarAlerta(tipo, mensaje) {
            var box = document.getElementById("mensaje");
            box.className = "alert-box " + (tipo === "success" ? "success" : (tipo === "info" ? "info" : "error"));
            box.innerHTML = (tipo === "success" ? "<i class=\"fas fa-check-circle\"></i> " : "<i class=\"fas fa-exclamation-circle\"></i> ") + mensaje;
            box.style.display = "block";
            box.scrollIntoView({ behavior: "smooth", block: "nearest" });
            setTimeout(function() { box.style.display = "none"; }, 5000);
        }

        function showTab(tabName, btn) {
            document.querySelectorAll(".tab-btn").forEach(function(t) { t.classList.remove("active"); });
            document.querySelectorAll(".tab-panel").forEach(function(c) { c.classList.remove("active"); });
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
                        contenedor.innerHTML = "<div class=\"empty-state\"><i class=\"fas fa-search\"></i>No se encontraron ventas sin facturar.</div>";
                    } else {
                        var html = "";
                        data.data.forEach(function(v) {
                            html += "<div class=\"ce-search-item\" onclick=\"seleccionarVenta(" + v.venta_id + ", '" + v.n_documento + "', " + v.total_venta + ", '" + v.cliente.replace(/'/g, "\\'") + "')\">";
                            html += "<div class=\"row1\"><div><strong>N " + v.n_documento + "</strong> - " + v.cliente + "</div>";
                            html += "<div class=\"monto\">$" + parseFloat(v.total_venta).toLocaleString("es-AR", {minimumFractionDigits:2}) + "</div></div><div class=\"row2\">" + v.fecha_venta + " | " + v.cond_pago + "</div></div>";
                        });
                        contenedor.innerHTML = html;
                    }
                    contenedor.classList.add("visible");
                } else { mostrarAlerta("error", data.message); }
            }).catch(function(err) { mostrarAlerta("error", "Error: " + err.message); });
        }
        function seleccionarVenta(id, nDocumento, total, cliente) {
            ventaSeleccionada = { id: id, nDocumento: nDocumento, total: total, cliente: cliente };
            document.getElementById("venta_id_seleccionada").value = id;
            document.getElementById("venta_seleccionada").value = "N " + nDocumento + " - " + cliente + " ($" + parseFloat(total).toLocaleString("es-AR", {minimumFractionDigits:2}) + ")";
            document.getElementById("monto_asociacion").value = total;
            document.getElementById("cardAsociacion").style.display = "block"; document.getElementById("cardAsociacion").scrollIntoView({ behavior: "smooth", block: "nearest" });
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
            var msg = "Â¿EstÃ¡ seguro de eliminar este comprobante externo? Esta acciÃ³n no se puede deshacer.";
            if (typeof mostrarConfirmacion === "function") {
                mostrarConfirmacion("Eliminar comprobante", msg, function() {
                    mostrarAlerta("error", "FunciÃ³n de eliminaciÃ³n pendiente de implementar.");
                });
            } else if (confirm(msg)) {
                mostrarAlerta("error", "FunciÃ³n de eliminaciÃ³n pendiente de implementar.");
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
