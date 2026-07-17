<?php
/**
 * Archivo: /ajax/importar_catalogo_csv.php
 * Descripción: Importación masiva de catálogo externo por proveedor.
 */

include '../pages/infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'message' => 'Falta empresa_id en sesión.']);
    exit();
}

if (ob_get_level()) ob_end_clean();
ob_start();

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] !== 'developer' && !tiene_permiso('prov_importar_catalogo'))) {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar importaciones.']);
    exit();
}

ini_set('auto_detect_line_endings', true);
set_time_limit(900);
ini_set('memory_limit', '512M');

try {
    $cod_prov = !empty($_POST['cod_prov']) ? trim($_POST['cod_prov']) : '';
    if (empty($cod_prov) || !isset($_FILES['archivo_csv'])) {
        throw new Exception("Faltan datos requeridos (proveedor o archivo).");
    }

    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir el archivo al servidor.");
    }

    $archivo = $_FILES['archivo_csv']['tmp_name'];
    $handle = fopen($archivo, "r");
    
    if ($handle === FALSE) {
        throw new Exception("No se pudo abrir el archivo CSV.");
    }

    $linea_test = fgets($handle);
    $separador = (strpos($linea_test, ';') !== false) ? ';' : ',';
    rewind($handle);

    $pdo->beginTransaction();

    $stmtDel = $pdo->prepare("DELETE FROM proveedores_catalogos WHERE cod_prov = ?");
    $stmtDel->execute([$cod_prov]);

    $stmtIns = $pdo->prepare("INSERT INTO proveedores_catalogos (cod_prov, codigo, descripcion, precio, empresa_id) VALUES (?, ?, ?, ?, ?)");

    $contador = 0;
    $omitidos = 0;

    while (($data = fgetcsv($handle, 0, $separador)) !== FALSE) {
        if (count($data) < 3) { $omitidos++; continue; }

        $codigo      = trim($data[0] ?? '');
        $descripcion = trim($data[1] ?? '');
        
        if ($codigo === '' || $descripcion === '') { $omitidos++; continue; }

        $precio_raw = preg_replace('/[^0-9,.]/', '', $data[2] ?? '0');
        
        if (strpos($precio_raw, ',') !== false) {
            if (strpos($precio_raw, '.') !== false) {
                $precio_raw = str_replace('.', '', $precio_raw);
            }
            $precio_raw = str_replace(',', '.', $precio_raw);
        } elseif (substr_count($precio_raw, '.') > 1) {
            $precio_raw = str_replace('.', '', $precio_raw);
        }
        
        $precio = is_numeric($precio_raw) ? (float)$precio_raw : 0.00;

        $stmtIns->execute([$cod_prov, $codigo, $descripcion, $precio, $empresa_id]);
        $contador++;
    }

    fclose($handle);
    $pdo->commit();

    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => "Proceso finalizado: $contador cargados, $omitidos omitidos.",
        'contador' => $contador
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}