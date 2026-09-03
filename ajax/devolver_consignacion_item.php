<?php
// ajax/devolver_consignacion_item.php
// Registra la devolución al proveedor de mercadería en consignación no vendida.
// Descuenta stock y acumula la cantidad devuelta del renglón del remito.
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$detalle_id = filter_var($_POST['detalle_id'] ?? 0, FILTER_VALIDATE_INT);
$cantidad   = (float)str_replace(',', '.', (string)($_POST['cantidad'] ?? '0'));

if (!$detalle_id || $cantidad <= 0) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos (detalle o cantidad).']);
    exit;
}

try {
    // Traer el renglón validando que la consignación pertenezca a la empresa y esté abierta
    $stmt = $pdo->prepare(
        "SELECT d.id, d.cod_prod, d.cantidad_recibida, d.cantidad_devuelta, c.estado, c.empresa_id
         FROM consignaciones_detalle d
         JOIN consignaciones c ON c.id = d.consignacion_id
         WHERE d.id = ? LIMIT 1"
    );
    $stmt->execute([$detalle_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['empresa_id'] !== (int)$empresa_id) {
        echo json_encode(['success' => false, 'error' => 'Renglón de consignación inexistente.']);
        exit;
    }
    if ($row['estado'] !== 'Abierta') {
        echo json_encode(['success' => false, 'error' => 'La consignación no está abierta.']);
        exit;
    }

    $disponible = (float)$row['cantidad_recibida'] - (float)$row['cantidad_devuelta'];
    if ($cantidad > $disponible) {
        echo json_encode(['success' => false, 'error' => 'La cantidad supera lo disponible para devolver (' . number_format($disponible, 2, ',', '.') . ').']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt_upd = $pdo->prepare("UPDATE consignaciones_detalle SET cantidad_devuelta = cantidad_devuelta + ? WHERE id = ?");
    $stmt_upd->execute([$cantidad, $detalle_id]);

    $stmt_stock = $pdo->prepare("UPDATE stocks SET stock_actual = stock_actual - ? WHERE empresa_id = ? AND sucursal_id = ? AND cod_prod COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci");
    $stmt_stock->execute([$cantidad, $empresa_id, $sucursal_id, $row['cod_prod']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'mensaje' => '✅ Devolución registrada: ' . number_format($cantidad, 2, ',', '.') . ' u. de ' . $row['cod_prod']]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}