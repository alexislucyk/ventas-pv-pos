<?php
// ajax/exportar_compras_csv.php
// Exporta compras filtradas a CSV
include '../pages/infosesion.php';
require_once '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    http_response_code(403);
    exit('Acceso denegado');
}

$where = array('c.empresa_id = ?');
$params = array($empresa_id);

// Filtros opcionales
$proveedor_id = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
$fecha_desde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';

if ($proveedor_id > 0) {
    $where[] = 'c.cod_proveedor = ?';
    $params[] = $proveedor_id;
}

if ($fecha_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
    $where[] = 'c.fecha_compra >= ?';
    $params[] = $fecha_desde;
}

if ($fecha_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
    $where[] = 'c.fecha_compra <= ?';
    $params[] = $fecha_hasta;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT c.id, c.documento, c.n_documento, c.cond_pago, c.total_compra,
            c.fecha_compra, c.fecha_operacion, p.razon AS proveedor,
            c.observaciones, u.usuario AS usuario
      FROM compras c
      LEFT JOIN proveedores p ON c.cod_proveedor = p.cod_prov AND p.empresa_id = c.empresa_id
      LEFT JOIN usuarios u ON c.usuario_id = u.id
      $where_sql
      ORDER BY c.fecha_operacion DESC, c.id DESC
      LIMIT 1000"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="compras_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM para que Excel abra bien los acentos
fputcsv($out, array(
    'ID', 'Tipo Doc', 'N° Documento', 'Cond. Pago', 'Total',
    'Fecha Doc', 'Fecha Registro', 'Proveedor', 'Observaciones', 'Usuario'
));

foreach ($rows as $r) {
    fputcsv($out, array(
        $r['id'],
        $r['documento'],
        $r['n_documento'],
        $r['cond_pago'],
        $r['total_compra'],
        $r['fecha_compra'],
        $r['fecha_operacion'],
        $r['proveedor'] ?? 'S/D',
        $r['observaciones'] ?? '',
        $r['usuario'] ?? ''
    ));
}

fclose($out);
exit;
?>
