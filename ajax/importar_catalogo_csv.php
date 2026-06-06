<?php
/**
 * Archivo: /ajax/importar_catalogo_csv.php
 * Descripción: Importación masiva de catálogo externo por proveedor.
 */

// Aseguramos que no haya salida previa de texto
if (ob_get_level()) ob_end_clean();
ob_start();

require '../config/db_config.php';
header('Content-Type: application/json');

// VALIDACIÓN DE PERMISOS
if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] !== 'developer' && !tiene_permiso('prov_importar_catalogo'))) {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar importaciones.']);
    exit();
}

// Configuración para procesos pesados
ini_set('auto_detect_line_endings', true);
set_time_limit(900); // 15 minutos
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

    // Leer primera línea para detectar separador y saltar posible BOM
    $linea_test = fgets($handle);
    $separador = (strpos($linea_test, ';') !== false) ? ';' : ',';
    rewind($handle);

    $pdo->beginTransaction();

    // 1. Limpiar catálogo anterior de este proveedor
    $stmtDel = $pdo->prepare("DELETE FROM proveedores_catalogos WHERE cod_prov = ?");
    $stmtDel->execute([$cod_prov]);

    // 2. Preparar el INSERT
    $stmtIns = $pdo->prepare("INSERT INTO proveedores_catalogos (cod_prov, codigo, descripcion, precio) VALUES (?, ?, ?, ?)");

    $contador = 0;
    $omitidos = 0;

    // 3. Procesamiento línea por línea
    while (($data = fgetcsv($handle, 0, $separador)) !== FALSE) {
        if (count($data) < 3) { $omitidos++; continue; }

        $codigo      = trim($data[0] ?? '');
        $descripcion = trim($data[1] ?? '');
        
        if ($codigo === '' || $descripcion === '') { $omitidos++; continue; }

        // --- LIMPIEZA DE PRECIO ---
        // Eliminamos $, espacios, NBSP y cualquier caracter que no sea número, coma o punto
        $precio_raw = preg_replace('/[^0-9,.]/', '', $data[2] ?? '0');
        
        // Lógica de conversión de Coma Decimal a Punto Decimal
        if (strpos($precio_raw, ',') !== false) {
            // Si tiene puntos y comas (ej 1.250,50), el punto es de miles
            if (strpos($precio_raw, '.') !== false) {
                $precio_raw = str_replace('.', '', $precio_raw);
            }
            // Reemplazamos la coma por punto para que PHP lo trate como float
            $precio_raw = str_replace(',', '.', $precio_raw);
        } elseif (substr_count($precio_raw, '.') > 1) {
            // Si tiene varios puntos (ej 1.250.00), son separadores de miles
            $precio_raw = str_replace('.', '', $precio_raw);
        }
        
        $precio = is_numeric($precio_raw) ? (float)$precio_raw : 0.00;

        $stmtIns->execute([$cod_prov, $codigo, $descripcion, $precio]);
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
    http_response_code(500); // Forzamos error para que el JS lo capture si falla
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}