<?php
include '../pages/infosesion.php';

// VALIDACIÓN DE PERMISOS
if (!tiene_permiso('pages/cobro_cuotas.php')) {
    exit("<p style='padding:20px; color:red;'>Acceso denegado.</p>");
}

$id_venta = (int)($_GET['id_venta'] ?? 0);

if ($id_venta <= 0) exit("ID de venta no válido.");

try {
    $stmt = $pdo->prepare("SELECT * FROM cuotas_seguimiento WHERE id_venta = ? ORDER BY nro_cuota ASC");
    $stmt->execute([$id_venta]);
    $cuotas = $stmt->fetchAll();

    // Obtenemos todos los pagos parciales de estas cuotas para evitar consultas en bucle
    $stmt_p = $pdo->prepare("SELECT id_cuota, id, monto, fecha, metodo_pago, usuario FROM cuotas_pagos 
                             WHERE id_cuota IN (SELECT id FROM cuotas_seguimiento WHERE id_venta = ?) 
                             ORDER BY fecha DESC");
    $stmt_p->execute([$id_venta]);
    $todos_pagos = $stmt_p->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC); 
    // Nota: FETCH_GROUP agrupa por la primera columna (id_cuota)

    if (!$cuotas) exit("<p>No se encontró el plan de cuotas.</p>");

    echo '<table class="table-full">
            <thead>
                <tr>
                    <th>Cuota</th>
                    <th>Vencimiento</th>
                    <th class="text-right">Monto</th>
                    <th class="text-right">Pagado</th>
                    <th class="text-right">Saldo</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acción</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($cuotas as $c) {
        $saldo = $c['monto_original'] - $c['monto_pagado'];
        $clase_badge = ($c['estado'] == 'Pendiente') ? 'badge-pendiente' : (($c['estado'] == 'Parcial') ? 'badge-parcial' : 'badge-success');
        $deshabilitado = ($c['estado'] == 'Pagada') ? 'disabled style="opacity:0.5; cursor:default;"' : '';
        
        // Construir HTML de pagos parciales
        $historial_pagos = "";
        if (isset($todos_pagos[$c['id']])) {
            $historial_pagos = "<div style='font-size: 0.75rem; color: #888; margin-top: 5px; border-top: 1px solid #333; padding-top: 3px;'>";
            foreach ($todos_pagos[$c['id']] as $pago) {
                $fecha_p = date('d/m', strtotime($pago['fecha']));
                $historial_pagos .= "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;'>";
                $historial_pagos .= "<span>• {$fecha_p}: $" . number_format($pago['monto'], 2, ',', '.') . "</span>";
                $historial_pagos .= "<div style='display:flex; gap:8px;'>";
                $historial_pagos .= "<button type='button' class='btn-lupa' style='color:#00bcd4; font-size:0.8rem; margin:0; padding:0;' title='Reimprimir comprobante' onclick='reimprimirPago({$pago['id']})'>";
                $historial_pagos .= "<i class='fas fa-print'></i>";
                $historial_pagos .= "</button>";
                $historial_pagos .= "<button type='button' class='btn-lupa' style='color:#e74c3c; font-size:0.8rem; margin:0; padding:0;' title='Anular este pago' onclick='anularPagoParcial({$pago['id']}, {$id_venta})'>";
                $historial_pagos .= "<i class='fas fa-times-circle'></i>";
                $historial_pagos .= "</button>";
                $historial_pagos .= "</div>";
                $historial_pagos .= "</div>";
            }
            $historial_pagos .= "</div>";
        }

        echo "<tr>
                <td class='text-center'>{$c['nro_cuota']}</td>
                <td>" . date('d/m/Y', strtotime($c['fecha_vencimiento'])) . "</td>
                <td class='text-right'>$" . number_format($c['monto_original'], 2, ',', '.') . "</td>
                <td class='text-right' style='color:#2ecc71;'>
                    $" . number_format($c['monto_pagado'], 2, ',', '.') . "
                    $historial_pagos
                </td>
                <td class='text-right saldo-destacado'>$" . number_format($saldo, 2, ',', '.') . "</td>
                <td class='text-center'><span class='badge $clase_badge'>{$c['estado']}</span></td>
                <td class='text-center'>
                    <button class='btn btn-success btn-sm' onclick='abrirCobro({$c['id']}, {$c['id_venta']}, $saldo)' $deshabilitado>
                        <i class='fas fa-dollar-sign'></i> Cobrar
                    </button>
                </td>
              </tr>";
    }
    echo '</tbody></table>';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}