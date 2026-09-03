<?php
// ajax/consignacion_masiva.php
// Función oculta de Productos: marca/desmarca como consignación los productos seleccionados.
// Acceso desde abm_productos.php (Ctrl+Alt+C o doble clic en el encabezado "Posesión").
include '../pages/infosesion.php';
require '../config/db_config.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];
$accion = $input['accion'] ?? '';
$comision = isset($input['comision']) && $input['comision'] !== null && $input['comision'] !== ''
    ? (float)str_replace(',', '.', (string)$input['comision'])
    : null;

// Validaciones
if (!is_array($ids) || count($ids) === 0) {
    echo json_encode(['success' => false, 'error' => 'No hay productos seleccionados.']);
    exit;
}
$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (count($ids) === 0) {
    echo json_encode(['success' => false, 'error' => 'IDs de productos inválidos.']);
    exit;
}
if (!in_array($accion, ['marcar', 'desmarcar'], true)) {
    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    exit;
}
if ($accion === 'marcar' && $comision !== null && ($comision <= 0 || $comision >= 100)) {
    echo json_encode(['success' => false, 'error' => 'La comisión del proveedor debe estar entre 1 y 99 (%).']);
    exit;
}

// Flag destino: marcar => 1 con comisión (NULL = usa 50/50 global); desmarcar => 0 y comisión NULL
$es_consignacion = ($accion === 'marcar') ? 1 : 0;
$comision_final = ($accion === 'marcar') ? $comision : null;

try {
    // Placeholder con nombre no puede reutilizarse: genero marcadores distintos para el IN
    $placeholders = [];
    $params = [
        ':flag' => $es_consignacion,
        ':com' => $comision_final,
        ':empresa_id' => $empresa_id
    ];
    foreach ($ids as $i => $id) {
        $ph = ':id' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }

    $sql = "UPDATE productos
            SET es_consignacion = :flag, comision_proveedor = :com
            WHERE empresa_id = :empresa_id AND id IN (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $actualizados = $stmt->rowCount();

    $verbo = ($accion === 'marcar') ? 'marcado(s) como CONSIGNACIÓN' : 'desmarcado(s): ahora son PROPIOS';
    echo json_encode([
        'success' => true,
        'mensaje' => "$actualizados producto(s) $verbo.",
        'actualizados' => $actualizados
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
}