<?php
// pages/consignaciones_ingreso.php
// Ingreso de mercadería en consignación (remitos), devoluciones y liquidaciones.
include 'infosesion.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$proveedores_list = [];
try {
    $stmt_prov = $pdo->prepare("SELECT id, razon FROM proveedores WHERE empresa_id = ? ORDER BY razon ASC");
    $stmt_prov->execute([$empresa_id]);
    $proveedores_list = $stmt_prov->fetchAll(PDO::FETCH_ASSOC);

    // Resumen global de stock en consignación (es activo del proveedor, no del negocio)
    $stmt_res = $pdo->prepare(
        "SELECT COUNT(DISTINCT p.id) AS productos,
                COALESCE(SUM(s.stock_actual), 0) AS unidades,
                COALESCE(SUM(s.stock_actual * p.p_compra), 0) AS valor_costo
         FROM productos p
         LEFT JOIN stocks s ON s.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci
         WHERE p.empresa_id = :e AND p.es_consignacion = 1"
    );
    $stmt_res->execute([':e' => $empresa_id]);
    $resumen = $stmt_res->fetch(PDO::FETCH_ASSOC) ?: ['productos' => 0, 'unidades' => 0, 'valor_costo' => 0];

    // Consignaciones abiertas con detalle (incluye vendido desde la fecha de recepción)
    $stmt_abiertas = $pdo->prepare(
        "SELECT c.id, c.n_remito, c.fecha_recepcion, c.observaciones, pr.razon AS proveedor,
                d.id AS detalle_id, d.cod_prod, d.cantidad_recibida, d.cantidad_devuelta, d.p_costo_acordado,
                pd.descripcion,
                COALESCE((SELECT SUM(vd.cant) 
                          FROM ventas_detalle vd 
                          JOIN ventas v ON v.n_documento = vd.n_documento AND v.empresa_id = c.empresa_id 
                          JOIN productos pp ON pp.cod_prod COLLATE utf8mb4_unicode_ci = vd.cod_prod COLLATE utf8mb4_unicode_ci AND pp.empresa_id = c.empresa_id
                          WHERE v.estado = 'Finalizada' 
                            AND vd.cod_prod COLLATE utf8mb4_unicode_ci = d.cod_prod COLLATE utf8mb4_unicode_ci
                            AND DATE(v.fecha_venta) >= c.fecha_recepcion), 0) AS cantidad_vendida
         FROM consignaciones c
         JOIN proveedores pr ON pr.cod_prov = c.proveedor_id AND pr.empresa_id = c.empresa_id
         JOIN consignaciones_detalle d ON d.consignacion_id = c.id
         LEFT JOIN productos pd ON pd.cod_prod COLLATE utf8mb4_unicode_ci = d.cod_prod COLLATE utf8mb4_unicode_ci AND pd.empresa_id = c.empresa_id
         WHERE c.empresa_id = :e AND c.estado = 'Abierta'
         ORDER BY c.fecha_recepcion DESC, c.id DESC, d.id ASC"
    );
    $stmt_abiertas->execute([':e' => $empresa_id]);
    $rows_abiertas = $stmt_abiertas->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por consignación
    $consignaciones_abiertas = [];
    foreach ($rows_abiertas as $r) {
        $cid = $r['id'];
        if (!isset($consignaciones_abiertas[$cid])) {
            $consignaciones_abiertas[$cid] = [
                'id' => $cid, 'n_remito' => $r['n_remito'], 'fecha_recepcion' => $r['fecha_recepcion'],
                'proveedor' => $r['proveedor'], 'observaciones' => $r['observaciones'], 'items' => []
            ];
        }
        $consignaciones_abiertas[$cid]['items'][] = $r;
    }

    // Últimas liquidaciones registradas
    $stmt_liq = $pdo->prepare(
        "SELECT l.*, pr.razon AS proveedor
         FROM consignaciones_liquidaciones l
         JOIN proveedores pr ON pr.cod_prov = l.proveedor_id AND pr.empresa_id = l.empresa_id
         WHERE l.empresa_id = :e
         ORDER BY l.id DESC LIMIT 5"
    );
    $stmt_liq->execute([':e' => $empresa_id]);
    $ultimas_liquidaciones = $stmt_liq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mensaje_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consignaciones: Ingreso de Mercadería | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/consignaciones.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1><i class="fas fa-handshake"></i> Consignaciones: Ingreso de Mercadería</h1>

        <?php if (isset($mensaje_error)): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($mensaje_error); ?></div>
        <?php endif; ?>

        <!-- RESUMEN -->
        <div class="resumen-consignacion">
            <div class="mini-card" style="border-top-color: #9b59b6;">
                <h4>Unidades en Local (Consignación)</h4>
                <span class="monto"><?php echo number_format($resumen['unidades'], 0, ',', '.'); ?></span>
                <small style="color: #888;"><?php echo number_format($resumen['productos'], 0); ?> productos</small>
            </div>
            <div class="mini-card" style="border-top-color: #e74c3c;">
                <h4>Deuda Potencial (Valor Costo)</h4>
                <span class="monto">$ <?php echo number_format($resumen['valor_costo'], 2, ',', '.'); ?></span>
                <small style="color: #888;">Si se vendiera todo, esto se le paga al proveedor</small>
            </div>
            <div class="mini-card" style="border-top-color: #00bcd4;">
                <h4>Remitos Abiertos</h4>
                <span class="monto"><?php echo count($consignaciones_abiertas); ?></span>
            </div>
        </div>

        <!-- NUEVO INGRESO -->
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-top: 0;"><i class="fas fa-truck-loading"></i> Nuevo Ingreso (Remito del Proveedor)</h3>
            <p style="color: #aaa; font-size: 0.9em;">La mercadería ingresa al stock para poder venderse, pero <strong>no genera compra ni deuda</strong>: la deuda nace cuando se vende.</p>
            <form id="formConsignacion" onsubmit="event.preventDefault(); guardarConsignacion();">
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                    <div style="flex: 2; min-width: 220px;">
                        <label>Proveedor *</label>
                        <select id="cons_proveedor" class="input-field" required>
                            <option value="">-- Seleccionar Proveedor --</option>
                            <?php foreach ($proveedores_list as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['razon']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <label>N° Remito</label>
                        <input type="text" id="cons_remito" class="input-field" placeholder="Ej: 0001-00012345">
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <label>Fecha de Recepción</label>
                        <input type="date" id="cons_fecha" class="input-field" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <table class="table-full" style="margin-bottom: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 200px;">Código</th>
                            <th>Cant.</th>
                            <th>Costo Acordado ($)</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoConsigna"></tbody>
                </table>
                <button type="button" class="btn btn-secondary btn-sm" onclick="agregarFilaConsigna()"><i class="fas fa-plus"></i> Agregar Producto</button>

                <div style="margin-top: 15px;">
                    <label>Observaciones</label>
                    <textarea id="cons_obs" class="input-field" rows="2" placeholder="Ej: acuerdo 50/50, devolver sobrante a fin de mes..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 15px; height: 45px; font-weight: bold;">📥 REGISTRAR INGRESO DE CONSIGNACIÓN</button>
                <div id="cons_resultado" style="margin-top: 15px; display: none;"></div>
            </form>
        </div>

        <!-- CONSIGNACIONES ABIERTAS -->
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-top: 0;"><i class="fas fa-clipboard-list"></i> Consignaciones Abiertas</h3>
            <?php if (empty($consignaciones_abiertas)): ?>
                <p style="color: #888; text-align: center; padding: 20px;">No hay remitos de consignación abiertos.</p>
            <?php else: ?>
                <?php foreach ($consignaciones_abiertas as $c): ?>
                    <div style="border: 1px solid #333; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #222;">
                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <div>
                                <strong style="color: #00bcd4;"><?php echo htmlspecialchars($c['proveedor']); ?></strong>
                                <small style="color: #888;"> · Remito: <?php echo htmlspecialchars($c['n_remito'] ?: 'S/N'); ?> · Recibido: <?php echo date('d/m/Y', strtotime($c['fecha_recepcion'])); ?></small>
                            </div>
                            <?php if ($c['observaciones']): ?><small style="color: #888;"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($c['observaciones']); ?></small><?php endif; ?>
                        </div>
                        <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                            <table class="table-full">
                                <thead>
                                    <tr>
                                        <th>Código</th>
                                        <th>Producto</th>
                                        <th class="text-right">Recibida</th>
                                        <th class="text-right">Vendida</th>
                                        <th class="text-right">Devuelta</th>
                                        <th class="text-right">Disponible</th>
                                        <th class="text-right">Costo Acord.</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($c['items'] as $it):
                                        $disponible = (float)$it['cantidad_recibida'] - (float)$it['cantidad_devuelta'];
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($it['cod_prod']); ?></td>
                                        <td><?php echo htmlspecialchars($it['descripcion'] ?? '(producto eliminado)'); ?></td>
                                        <td class="text-right"><?php echo number_format($it['cantidad_recibida'], 2, ',', '.'); ?></td>
                                        <td class="text-right" style="color: #2ecc71;"><?php echo number_format($it['cantidad_vendida'], 2, ',', '.'); ?></td>
                                        <td class="text-right" style="color: #e74c3c;"><?php echo number_format($it['cantidad_devuelta'], 2, ',', '.'); ?></td>
                                        <td class="text-right text-bold"><?php echo number_format($disponible, 2, ',', '.'); ?></td>
                                        <td class="text-right">$ <?php echo number_format($it['p_costo_acordado'], 2, ',', '.'); ?></td>
                                        <td>
                                            <?php if ($disponible > 0): ?>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="devolverItem(<?php echo $it['detalle_id']; ?>, '<?php echo htmlspecialchars(addslashes($it['cod_prod'])); ?>', <?php echo $disponible; ?>)" title="Devolver al proveedor">↩ Devolver</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ÚLTIMAS LIQUIDACIONES -->
        <div class="card" style="margin-top: 20px;">
            <h3 style="margin-top: 0;"><i class="fas fa-file-invoice-dollar"></i> Últimas Liquidaciones</h3>
            <?php if (empty($ultimas_liquidaciones)): ?>
                <p style="color: #888; text-align: center; padding: 20px;">Todavía no se registraron liquidaciones. Usá el botón "Pagar y Registrar Liquidación" en el <a href="<?php echo URL_BASE; ?>consignacion-reporte">Reporte de Consignación</a>.</p>
            <?php else: ?>
                <div class="table-container">
                    <table class="table-full">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Proveedor</th>
                                <th>Período</th>
                                <th class="text-right">Venta</th>
                                <th class="text-right">A Pagar Prov.</th>
                                <th class="text-right">Mi Utilidad</th>
                                <th>Método</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_liquidaciones as $l): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($l['fecha_liquidacion'])); ?></td>
                                <td><?php echo htmlspecialchars($l['proveedor']); ?></td>
                                <td><?php echo date('d/m/y', strtotime($l['desde'])) . ' - ' . date('d/m/y', strtotime($l['hasta'])); ?></td>
                                <td class="text-right">$ <?php echo number_format($l['total_venta'], 2, ',', '.'); ?></td>
                                <td class="text-right" style="color: #f39c12;">$ <?php echo number_format($l['monto_pagar_proveedor'], 2, ',', '.'); ?></td>
                                <td class="text-right" style="color: #2ecc71;">$ <?php echo number_format($l['mi_utilidad'], 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($l['metodo_pago']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function agregarFilaConsigna(cod, cant, costo) {
        const tbody = document.getElementById('cuerpoConsigna');
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td style="padding: 5px;"><input type="text" class="cons-cod input-field" style="margin-bottom:0;" value="' + (cod || '') + '" placeholder="Código de barras / interno"></td>' +
            '<td style="padding: 5px;"><input type="number" class="cons-cant input-field" style="margin-bottom:0;" step="0.01" min="0.01" value="' + (cant || '1') + '"></td>' +
            '<td style="padding: 5px;"><input type="number" class="cons-costo input-field" style="margin-bottom:0;" step="0.01" min="0" value="' + (costo || '0') + '"></td>' +
            '<td style="padding: 5px; text-align: center;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'tr\').remove()"><i class="fas fa-times"></i></button></td>';
        tbody.appendChild(tr);
    }
    agregarFilaConsigna();

    function guardarConsignacion() {
        const res = document.getElementById('cons_resultado');
        const proveedor_id = document.getElementById('cons_proveedor').value;
        if (!proveedor_id) {
            res.style.display = 'block';
            res.className = 'alert alert-error';
            res.innerHTML = '❌ Seleccione un proveedor.';
            return;
        }

        const items = [];
        document.querySelectorAll('#cuerpoConsigna tr').forEach(tr => {
            const cod = tr.querySelector('.cons-cod').value.trim();
            const cant = parseFloat(tr.querySelector('.cons-cant').value) || 0;
            const costo = parseFloat(tr.querySelector('.cons-costo').value) || 0;
            if (cod && cant > 0) items.push({ cod_prod: cod, cantidad: cant, costo: costo });
        });

        if (items.length === 0) {
            res.style.display = 'block';
            res.className = 'alert alert-error';
            res.innerHTML = '❌ Agregue al menos un producto con código y cantidad.';
            return;
        }

        const btn = document.querySelector('#formConsignacion button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Registrando...';

        const body = new URLSearchParams({
            proveedor_id: proveedor_id,
            n_remito: document.getElementById('cons_remito').value.trim(),
            fecha_recepcion: document.getElementById('cons_fecha').value,
            observaciones: document.getElementById('cons_obs').value.trim(),
            detalle: JSON.stringify(items)
        });

        fetch('<?php echo URL_BASE; ?>ajax/guardar_consignacion.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '📥 REGISTRAR INGRESO DE CONSIGNACIÓN';
            res.style.display = 'block';
            if (data.success) {
                res.className = 'alert alert-success';
                res.innerHTML = data.mensaje;
                setTimeout(() => { window.location.reload(); }, 2500);
            } else {
                res.className = 'alert alert-error';
                res.innerHTML = '❌ ' + (data.error || 'Error desconocido');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '📥 REGISTRAR INGRESO DE CONSIGNACIÓN';
            res.style.display = 'block';
            res.className = 'alert alert-error';
            res.innerHTML = '❌ Error de conexión: ' + err.message;
        });
    }

    function devolverItem(detalleId, cod, disponible) {
        const input = prompt('Devolver al proveedor el producto ' + cod + '\nDisponible: ' + disponible + '\nIngrese cantidad a devolver:', '1');
        if (input === null) return;
        const cant = parseFloat(String(input).replace(',', '.'));
        if (isNaN(cant) || cant <= 0 || cant > disponible) {
            alert('Cantidad inválida.');
            return;
        }

        const body = new URLSearchParams({ detalle_id: detalleId, cantidad: cant });
        fetch('<?php echo URL_BASE; ?>ajax/devolver_consignacion_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.mensaje);
                window.location.reload();
            } else {
                alert('❌ ' + (data.error || 'Error desconocido'));
            }
        })
        .catch(() => alert('❌ Error de conexión'));
    }
    </script>
</body>
</html>