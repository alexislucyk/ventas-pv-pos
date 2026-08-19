<?php
// ajax/obtener_detalle_presupuesto_json.php
// Devuelve los productos de un presupuesto ya emitido en formato JSON para copiarlos en uno nuevo.
include '../pages/infosesion.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID de presupuesto inválido']);
    exit;
}

// Validar que el presupuesto pertenezca a la empresa actual
$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$stmtCheck = $pdo->prepare("SELECT id FROM presupuestos WHERE id = ? AND empresa_id = ?");
$stmtCheck->execute([$id, $empresa_id]);
if (!$stmtCheck->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Presupuesto no encontrado.']);
    exit;
}

$stmtItems = $pdo->prepare("SELECT cod_prod, descripcion, cantidad, precio_unitario
                            FROM presupuestos_detalle
                            WHERE id_presupuesto = ?
                            ORDER BY id ASC");
$stmtItems->execute([$id]);
$rows = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo json_encode(['success' => false, 'error' => 'El presupuesto no tiene productos cargados.']);
    exit;
}

// Consultar el precio actual (p_venta) de cada producto en la BD para detectar variaciones
$stmtProd = $pdo->prepare("SELECT p_venta FROM productos WHERE cod_prod = ? AND empresa_id = ? LIMIT 1");

$resultado = [];
foreach ($rows as $it) {
    $cod = $it['cod_prod'];
    $precioPresupuesto = (float)$it['precio_unitario'];

    $precioActual = null;
    $stmtProd->execute([$cod, $empresa_id]);
    $filaProd = $stmtProd->fetch();
    if ($filaProd) {
        $precioActual = (float)$filaProd['p_venta'];
    }

    $cambio = ($precioActual !== null && abs($precioActual - $precioPresupuesto) > 0.001);

    $resultado[] = [
        'codigo'        => $cod,
        'descripcion'   => $it['descripcion'],
        'cantidad'      => (float)$it['cantidad'],
        // Precio guardado en el presupuesto original
        'precio'        => $precioPresupuesto,
        // Precio actual del producto en la BD (p_venta)
        'precio_actual' => $precioActual,
        // Indica si hubo variación de precio (true cuando el precio cambió)
        'variacion'     => $cambio
    ];
}

echo json_encode(['success' => true, 'items' => $resultado]);