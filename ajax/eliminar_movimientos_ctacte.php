<?php
// ajax/eliminar_movimientos_ctacte.php
// Elimina movimientos seleccionados de la cuenta corriente de un cliente.
// Recibe JSON: { id_cliente: int, ids: [int, ...] }
// Respuesta JSON: { success, eliminados, saldo } | { success:false, error }

include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Permiso de la página desde la que se ejecuta la acción
require_permiso('pages/cuentas_corrientes_detalle.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';

if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$id_cliente = filter_var($data['id_cliente'] ?? null, FILTER_VALIDATE_INT);
$ids_raw = $data['ids'] ?? [];

if (!$id_cliente) {
    echo json_encode(['success' => false, 'error' => 'Cliente no válido.']);
    exit;
}

if (!is_array($ids_raw) || empty($ids_raw)) {
    echo json_encode(['success' => false, 'error' => 'No hay movimientos seleccionados.']);
    exit;
}

// Sanear IDs (enteros positivos, sin repetidos)
$ids = [];
foreach ($ids_raw as $id) {
    $id = filter_var($id, FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $ids[$id] = $id;
    }
}
$ids = array_values($ids);

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No hay movimientos seleccionados.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Verificar que todos los movimientos pertenezcan al cliente y a la empresa en sesión
    $sql_sel = "SELECT id, movimiento, debe, haber
                FROM ctacte
                WHERE id IN ($placeholders) AND id_cliente = ? AND empresa_id = ?";
    $stmt_sel = $pdo->prepare($sql_sel);
    $stmt_sel->execute(array_merge($ids, [$id_cliente, $empresa_id]));
    $a_eliminar = $stmt_sel->fetchAll(PDO::FETCH_ASSOC);

    if (empty($a_eliminar)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Los movimientos seleccionados no existen o no pertenecen a este cliente.']);
        exit;
    }

    $ids_encontrados = array_column($a_eliminar, 'id');
    $placeholders2 = implode(',', array_fill(0, count($ids_encontrados), '?'));

    $stmt_del = $pdo->prepare("DELETE FROM ctacte WHERE id IN ($placeholders2) AND id_cliente = ? AND empresa_id = ?");
    $stmt_del->execute(array_merge($ids_encontrados, [$id_cliente, $empresa_id]));
    $eliminados = $stmt_del->rowCount();

    // Recalcular el saldo de la cuenta (misma fórmula que usa la página)
    $stmt_saldo = $pdo->prepare("SELECT COALESCE(SUM(debe - haber), 0) FROM ctacte WHERE id_cliente = ? AND empresa_id = ?");
    $stmt_saldo->execute([$id_cliente, $empresa_id]);
    $saldo = (float)$stmt_saldo->fetchColumn();

    $pdo->commit();

    error_log("ctacte: usuario '$usuario' eliminó $eliminados movimiento(s) del cliente #$id_cliente (empresa #$empresa_id) - IDs: " . implode(',', $ids_encontrados));

    echo json_encode([
        'success'    => true,
        'eliminados' => $eliminados,
        'saldo'      => $saldo
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al eliminar movimientos ctacte: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al eliminar los movimientos: ' . $e->getMessage()]);
}
?>
