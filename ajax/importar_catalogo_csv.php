<?php
include '../pages/infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'message' => 'Falta empresa_id en sesi?n.']);
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

function normalizar_encabezado($valor) {
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', (string)$valor);
    $valor = trim($valor);
    $valor = mb_strtolower($valor, 'UTF-8');
    $buscar  = ['?','?','?','?','?','?','?','?','?','?','?'];
    $reempl  = ['a','e','i','o','u','n','a','e','i','o','u'];
    return str_replace($buscar, $reempl, $valor);
}

function es_encabezado($row) {
    if (empty($row) || !isset($row[0])) return false;
    $code = normalizar_encabezado($row[0]);
    $desc = isset($row[1]) ? normalizar_encabezado($row[1]) : '';
    $codeEsHeader = (strpos($code, 'sku') !== false || strpos($code, 'codigo') !== false || strpos($code, 'code') !== false || $code === 'cod');
    $descEsHeader = (strpos($desc, 'desc') !== false || strpos($desc, 'detalle') !== false);
    return $codeEsHeader && $descEsHeader;
}

function mapear_por_encabezado($row) {
    $code = -1; $desc = -1; $price = -1;
    foreach ($row as $i => $cell) {
        $h = normalizar_encabezado($cell);
        if ($code < 0 && (strpos($h, 'sku') !== false || strpos($h, 'codigo') !== false || strpos($h, 'code') !== false)) $code = $i;
        if ($desc < 0 && (strpos($h, 'desc') !== false || strpos($h, 'detalle') !== false)) $desc = $i;
        if ($price < 0 && (strpos($h, 'precio') !== false || strpos($h, 'price') !== false || strpos($h, 'lista') !== false)) $price = $i;
    }
    if ($code >= 0 && $desc >= 0 && $price >= 0) return [$code, $desc, $price];
    return null;
}

function mapear_posicional($row) {
    $n = count($row);
    if ($n < 3) return null;
    return [0, 1, $n - 1];
}

function parsear_precio($raw) {
    $precio_raw = preg_replace('/[^0-9,.]/', '', (string)$raw);
    if (strpos($precio_raw, ',') !== false) {
        if (strpos($precio_raw, '.') !== false) $precio_raw = str_replace('.', '', $precio_raw);
        $precio_raw = str_replace(',', '.', $precio_raw);
    } elseif (substr_count($precio_raw, '.') > 1) {
        $precio_raw = str_replace('.', '', $precio_raw);
    }
    return is_numeric($precio_raw) ? (float)$precio_raw : 0.00;
}

try {
    $cod_prov = !empty($_POST['cod_prov']) ? trim($_POST['cod_prov']) : '';
    if (empty($cod_prov) || !isset($_FILES['archivo_csv'])) {
        throw new Exception("Faltan datos requeridos (proveedor o archivo).");
    }

    $borrar_previo = isset($_POST['borrar_previo']) && $_POST['borrar_previo'] === '1';

    $files = [];
    if (is_array($_FILES['archivo_csv']['name'])) {
        $count = count($_FILES['archivo_csv']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['archivo_csv']['error'][$i] === UPLOAD_ERR_OK) {
                $files[] = [
                    'name' => $_FILES['archivo_csv']['name'][$i],
                    'tmp_name' => $_FILES['archivo_csv']['tmp_name'][$i],
                ];
            }
        }
    } else {
        if ($_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
            $files[] = [
                'name' => $_FILES['archivo_csv']['name'],
                'tmp_name' => $_FILES['archivo_csv']['tmp_name'],
            ];
        }
    }

    if (empty($files)) {
        throw new Exception("No se recibi? ning?n archivo v?lido.");
    }

    $pdo->beginTransaction();

    if ($borrar_previo) {
        $stmtDel = $pdo->prepare("DELETE FROM proveedores_catalogos WHERE cod_prov = ?");
        $stmtDel->execute([$cod_prov]);
    }

    $stmtIns = $pdo->prepare("INSERT INTO proveedores_catalogos (cod_prov, codigo, descripcion, precio) VALUES (?, ?, ?, ?)");

    $contador = 0;
    $omitidos = 0;
    $archivos_procesados = 0;
    foreach ($files as $f) {
        $archivo = $f['tmp_name'];
        $handle = fopen($archivo, "r");
        if ($handle === FALSE) { $omitidos++; continue; }

        $linea_test = fgets($handle);
        $separador = (strpos($linea_test, ';') !== false) ? ';' : ',';
        rewind($handle);

        $primera = fgetcsv($handle, 0, $separador);
        if ($primera === FALSE) { fclose($handle); continue; }

        $indices = null;
        $procesar_primera = false;

        if (es_encabezado($primera)) {
            $indices = mapear_por_encabezado($primera);
            if ($indices === null) $indices = mapear_posicional($primera);
        } else {
            $indices = mapear_posicional($primera);
            $procesar_primera = true;
        }

        if ($indices === null) { fclose($handle); continue; }

        $procesar_fila = function ($data) use (&$contador, &$omitidos, $indices, $stmtIns, $cod_prov) {
            if (!is_array($data) || count($data) < 3) { $omitidos++; return; }
            list($ci, $di, $pi) = $indices;
            $codigo      = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)($data[$ci] ?? '')));
            $descripcion = trim((string)($data[$di] ?? ''));
            if ($codigo === '' || $descripcion === '') { $omitidos++; return; }
            $precio = parsear_precio($data[$pi] ?? '0');
            $stmtIns->execute([$cod_prov, $codigo, $descripcion, $precio]);
            $contador++;
        };

        if ($procesar_primera) $procesar_fila($primera);

        while (($data = fgetcsv($handle, 0, $separador)) !== FALSE) {
            $procesar_fila($data);
        }

        fclose($handle);
        $archivos_procesados++;
    }

    $pdo->commit();

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Proceso finalizado: $contador cargados, $omitidos omitidos, $archivos_procesados archivo(s) procesado(s).",
        'contador' => $contador
    ]);

} catch (Exception $e) {
    if (isset($handle) && is_resource($handle)) fclose($handle);
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
