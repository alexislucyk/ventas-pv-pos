<?php
// ajax/guardar_consignacion.php
// Registra el ingreso de mercadería en consignación (remito del proveedor).
// Mueve stock (tabla stocks) y actualiza el costo acordado, SIN generar compra ni deuda.
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario_id = $_SESSION['usuario_id'] ?? 0;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$proveedor_id    = filter_var($_POST['proveedor_id'] ?? 0, FILTER_VALIDATE_INT);
$n_remito        = trim($_POST['n_remito'] ?? '');
$fecha_recepcion = $_POST['fecha_recepcion'] ?? date('Y-m-d');
$observaciones   = trim($_POST['observaciones'] ?? '');
$detalle_json    = $_POST['detalle'] ?? '[]';

if (!$proveedor_id) {
    echo json_encode(['success' => false, 'error' => 'Debe seleccionar un proveedor.']);
    exit;
}

$detalle = json_decode($detalle_json, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($detalle) || count($detalle) === 0) {
    echo json_encode(['success' => false, 'error' => 'El detalle de productos es inválido o está vacío.']);
    exit;
}

try {
    // Validar proveedor (PK: cod_prov + empresa_id)
    $stmt_prov = $pdo->prepare("SELECT razon FROM proveedores WHERE cod_prov = ? AND empresa_id = ?");
    $stmt_prov->execute([$proveedor_id, $empresa_id]);
    $razon_prov = $stmt_prov->fetchColumn();
    if ($razon_prov === false) {
        echo json_encode(['success' => false, 'error' => 'Proveedor inexistente para esta empresa.']);
        exit;
    }

    $pdo->beginTransaction();

    // 1. Cabecera del remito de consignación
    $stmt_cab = $pdo->prepare(
        "INSERT INTO consignaciones (empresa_id, proveedor_id, n_remito, fecha_recepcion, estado, observaciones, usuario_id)
         VALUES (?, ?, ?, ?, 'Abierta', ?, ?)"
    );
    $stmt_cab->execute([$empresa_id, $proveedor_id, ($n_remito !== '' ? $n_remito : null), $fecha_recepcion, ($observaciones !== '' ? $observaciones : null), $usuario_id]);
    $consignacion_id = $pdo->lastInsertId();

    // 2. Detalle + movimiento de stock + costo acordado
    $stmt_det = $pdo->prepare(
        "INSERT INTO consignaciones_detalle (consignacion_id, cod_prod, cantidad_recibida, cantidad_devuelta, p_costo_acordado)
         VALUES (?, ?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE cantidad_recibida = cantidad_recibida + VALUES(cantidad_recibida), p_costo_acordado = VALUES(p_costo_acordado)"
    );
    $stmt_stock = $pdo->prepare(
        "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)"
    );
    $stmt_prod = $pdo->prepare(
        "UPDATE productos SET p_compra = ?, es_consignacion = 1, proveedor = ? 
         WHERE cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND empresa_id = ?"
    );
    $stmt_check = $pdo->prepare("SELECT descripcion FROM productos WHERE cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND empresa_id = ?");

    $procesados = 0;
    foreach ($detalle as $item) {
        $cod     = trim((string)($item['cod_prod'] ?? ''));
        $cant    = (float)($item['cantidad'] ?? 0);
        $costo   = (float)str_replace(',', '.', (string)($item['costo'] ?? '0'));

        if ($cod === '' || $cant <= 0) continue;

        // El producto debe existir en el ABM de productos
        $stmt_check->execute([$cod, $empresa_id]);
        if ($stmt_check->fetchColumn() === false) {
            throw new Exception("El producto con código '$cod' no existe. Créalo primero en Productos.");
        }

        $stmt_det->execute([$consignacion_id, $cod, $cant, $costo]);
        $stmt_stock->execute([$empresa_id, $sucursal_id, $cod, $cant]);
        // El costo acordado pasa a ser el costo del producto (base de la liquidación)
        $stmt_prod->execute([$costo, $razon_prov, $cod, $empresa_id]);
        $procesados++;
    }

    if ($procesados === 0) {
        throw new Exception('No se procesó ningún renglón válido del remito.');
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'mensaje' => "✅ Remito de consignación #$consignacion_id registrado ($procesados productos). Stock actualizado.",
        'consignacion_id' => (int)$consignacion_id
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}