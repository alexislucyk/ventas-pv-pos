<?php
// ajax/liquidar_consignacion.php
// Registra la liquidación (pago) de consignación a un proveedor por el período indicado.
// Genera: registro en consignaciones_liquidaciones + EGRESO en caja (movimientos).
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario = $_SESSION['usuario'] ?? 'Sistema';
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$proveedor   = trim($_POST['proveedor'] ?? '');
$desde       = $_POST['desde'] ?? date('Y-m-01');
$hasta       = $_POST['hasta'] ?? date('Y-m-d');
$metodo_pago = in_array($_POST['metodo_pago'] ?? 'EFECTIVO', ['EFECTIVO', 'TRANSFERENCIA']) ? $_POST['metodo_pago'] : 'EFECTIVO';

if ($proveedor === '') {
    echo json_encode(['success' => false, 'error' => 'Proveedor requerido.']);
    exit;
}

try {
    // 1. Obtener cod_prov del proveedor (por razón social, misma lógica que productos.proveedor)
    $stmt_prov = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE empresa_id = :e AND TRIM(razon) COLLATE utf8mb4_unicode_ci = TRIM(CONVERT(:p USING utf8mb4)) COLLATE utf8mb4_unicode_ci LIMIT 1");
    $stmt_prov->execute([':e' => $empresa_id, ':p' => $proveedor]);
    $proveedor_id = $stmt_prov->fetchColumn();
    if ($proveedor_id === false) {
        echo json_encode(['success' => false, 'error' => 'No se encontró el proveedor "' . $proveedor . '" en el ABM de proveedores.']);
        exit;
    }

    // 2. Recalcular los totales del período (solo productos EN CONSIGNACIÓN de ese proveedor)
    $sql = "SELECT 
                vd.cod_prod, 
                vd.descripcion, 
                SUM(vd.cant) as total_cant,
                vd.p_unit as precio_venta,
                COALESCE(p.p_compra, 0) as costo_unitario,
                COALESCE(p.comision_proveedor, 50) as comision,
                SUM(vd.total) as subtotal_venta,
                SUM(COALESCE(p.p_compra, 0) * vd.cant) as subtotal_costo,
                SUM(vd.total - (COALESCE(p.p_compra, 0) * vd.cant)) as ganancia_total
            FROM ventas_detalle vd
            JOIN ventas v ON vd.n_documento = v.n_documento AND v.empresa_id = :empresa_id1
            JOIN productos p ON vd.cod_prod COLLATE utf8mb4_unicode_ci = p.cod_prod COLLATE utf8mb4_unicode_ci AND p.empresa_id = :empresa_id2
            WHERE v.estado = 'Finalizada'
              AND p.es_consignacion = 1
              AND TRIM(p.proveedor) = :proveedor
              AND DATE(v.fecha_venta) BETWEEN :desde AND :hasta
            GROUP BY vd.cod_prod, vd.descripcion, vd.p_unit, p.p_compra, p.comision_proveedor
            ORDER BY vd.descripcion ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id1' => $empresa_id, ':empresa_id2' => $empresa_id, ':proveedor' => $proveedor, ':desde' => $desde, ':hasta' => $hasta]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($resultados)) {
        echo json_encode(['success' => false, 'error' => 'No hay ventas en consignación de este proveedor en el período indicado.']);
        exit;
    }

    $total_venta = 0; $total_costo = 0; $total_ganancia = 0; $pagar_proveedor = 0; $mi_utilidad = 0;
    foreach ($resultados as $r) {
        $comision = (float)$r['comision'];
        $total_venta += $r['subtotal_venta'];
        $total_costo += $r['subtotal_costo'];
        $total_ganancia += $r['ganancia_total'];
        $pagar_proveedor += $r['subtotal_costo'] + ($r['ganancia_total'] * $comision / 100);
        $mi_utilidad += $r['ganancia_total'] * (1 - $comision / 100);
    }

    $pdo->beginTransaction();

    // 3. EGRESO en caja (mismo patrón que compras_rapidas / movimiento_manual)
    $detalle_mov = "LIQUIDACION CONSIGNACION: $proveedor (" . date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta)) . ")";
    $stmt_mov = $pdo->prepare("INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) VALUES ('EGRESO', ?, ?, ?, NOW(), ?, 0, ?, ?)");
    $stmt_mov->execute([$pagar_proveedor, $metodo_pago, $detalle_mov, $usuario, $empresa_id, $sucursal_id]);
    $movimiento_id = $pdo->lastInsertId();

    // 4. Registrar la liquidación con snapshot del detalle
    $stmt_liq = $pdo->prepare(
        "INSERT INTO consignaciones_liquidaciones
            (empresa_id, proveedor_id, fecha_liquidacion, desde, hasta, total_venta, total_costo, total_ganancia, monto_pagar_proveedor, mi_utilidad, metodo_pago, movimientos_id, detalle_json)
         VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt_liq->execute([
        $empresa_id, $proveedor_id, $desde, $hasta,
        $total_venta, $total_costo, $total_ganancia,
        $pagar_proveedor, $mi_utilidad, $metodo_pago, $movimiento_id,
        json_encode($resultados, JSON_UNESCAPED_UNICODE)
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'mensaje' => 'Liquidación registrada por $ ' . number_format($pagar_proveedor, 2, ',', '.') . ' (' . $metodo_pago . '). Se generó el egreso en caja.',
        'liquidacion_id' => (int)$pdo->lastInsertId(),
        'monto' => round($pagar_proveedor, 2)
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}