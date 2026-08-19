<?php
// archivo: ajax/exportar_clientes_csv.php
// Exporta todos los clientes filtrados (no solo la página visible) a CSV.
include '../pages/infosesion.php';
require_once '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    http_response_code(403);
    exit('Acceso denegado');
}

$where = array('empresa_id = ?');
$params = array($empresa_id);

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
if ($buscar !== '') {
    $like = "%$buscar%";
    $where[] = "(apellido LIKE ? OR nombre LIKE ? OR cuit LIKE ? OR dni LIKE ? OR id = ?)";
    array_push($params, $like, $like, $like, $like, is_numeric($buscar) ? (int)$buscar : 0);
}

$estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
if ($estado === 'Activo' || $estado === 'Inactivo') {
    $where[] = 'estado = ?';
    $params[] = $estado;
}

$iva = isset($_GET['iva']) ? trim($_GET['iva']) : '';
if (in_array($iva, array('99', '1', '6', '4'), true)) {
    $where[] = 'id_tipo_iva = ?';
    $params[] = (int)$iva;
}

$cta = isset($_GET['cta_cte']) ? trim($_GET['cta_cte']) : '';
if (in_array($cta, array('Si', 'No'), true)) {
    $where[] = 'habilita_cta = ?';
    $params[] = $cta;
}

$letra = isset($_GET['letra']) ? strtoupper(trim($_GET['letra'])) : '';
if ($letra !== '' && preg_match('/^[A-Z]$/', $letra)) {
    $where[] = 'apellido LIKE ?';
    $params[] = $letra . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT c.id, c.apellido, c.nombre, c.cuit, c.dni, c.id_tipo_iva, c.telefono,
            c.email, c.direccion, c.localidad, c.estado, c.habilita_cta
     FROM clientes c
     $where_sql
     ORDER BY c.apellido ASC, c.nombre ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipos_iva = array(
    99 => 'Consumidor Final',
    1  => 'Responsable Inscripto',
    6  => 'Monotributo',
    4  => 'Exento'
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM para que Excel abra bien los acentos
fputcsv($out, array(
    'ID', 'Apellido', 'Nombre', 'CUIT', 'DNI', 'Condicion IVA', 'Telefono',
    'Email', 'Direccion', 'Localidad', 'Estado', 'Cta.Cte.'
));

foreach ($rows as $r) {
    fputcsv($out, array(
        $r['id'],
        $r['apellido'],
        $r['nombre'],
        $r['cuit'],
        $r['dni'],
        isset($tipos_iva[(int)$r['id_tipo_iva']]) ? $tipos_iva[(int)$r['id_tipo_iva']] : 'CF',
        $r['telefono'],
        $r['email'],
        $r['direccion'],
        $r['localidad'],
        $r['estado'],
        strtoupper(trim($r['habilita_cta'])) === 'SI' ? 'Habilitada' : 'No'
    ));
}
fclose($out);
exit;