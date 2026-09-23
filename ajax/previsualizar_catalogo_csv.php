<?php
/**
 * Archivo: /ajax/previsualizar_catalogo_csv.php
 * Descripción: Analiza un CSV de catálogo ANTES de importarlo. Devuelve una
 *              previsualización de las primeras filas, detecta si la primera
 *              fila es un encabezado y sugiere qué columna es
 *              código / descripción (producto) / precio.
 */

include '../pages/infosesion.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'message' => 'Falta empresa_id en sesión.']);
    exit();
}

// Limpia cualquier salida previa (warnings, BOM, espacios) y fuerza JSON limpio
if (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] !== 'developer' && !tiene_permiso('prov_importar_catalogo'))) {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos para realizar importaciones.']);
    exit();
}

/**
 * Detecta el separador de un CSV de forma robusta, verificando consistencia
 * de columnas en las primeras filas.
 *
 * El criterio simple "si hay ';' usar ';' sino ','" falla cuando el archivo
 * usa ',' como separador de columna y los precios tienen ',' como decimal
 * (ej: "01,Art,1.234,56" → fgetcsv parte el precio). Verificamos consistencia.
 *
 * @param array $lineas Primeras líneas del archivo (incluyendo encabezado).
 * @return array ['separador' => string, 'inconsistencias' => array con métricas por separador]
 */
function detectar_separador_csv(array $lineas): array
{
    $lineas = array_filter($lineas, function ($l) {
        return trim($l) !== '';
    });
    if (empty($lineas)) {
        return ['separador' => ';', 'inconsistencias' => [';' => ['consistente' => false, 'avg' => 0, 'varianza' => 999], ',' => ['consistente' => false, 'avg' => 0, 'varianza' => 999]]];
    }

    $candidatos = [
        ';' => function ($linea) { return substr_count($linea, ';'); },
        ',' => function ($linea) { return substr_count($linea, ','); },
    ];

    $resultados = [];
    foreach ($candidatos as $sep => $contar) {
        $counts = array_map($contar, $lineas);
        $counts = array_filter($counts, function ($c) { return $c > 0; });
        if (empty($counts)) {
            $resultados[$sep] = ['consistente' => false, 'avg' => 0, 'varianza' => 999];
            continue;
        }
        $avg = array_sum($counts) / count($counts);
        $varianza = 0;
        foreach ($counts as $c) {
            $varianza += ($c - $avg) ** 2;
        }
        $varianza = $varianza / count($counts);
        $consistente = $varianza < 1.5;
        $resultados[$sep] = ['consistente' => $consistente, 'avg' => round($avg, 2), 'varianza' => round($varianza, 2)];
    }

    $separador = ';';
    if ($resultados[';']['consistente']) {
        $separador = ';';
    } elseif ($resultados[',']['consistente']) {
        $separador = ',';
    } elseif ($resultados[',']['varianza'] > $resultados[';']['varianza'] * 1.5) {
        $separador = ';';
    }

    return ['separador' => $separador, 'inconsistencias' => $resultados];
}

try {
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir el archivo al servidor.");
    }

    $archivo = $_FILES['archivo_csv']['tmp_name'];
    $nombre_original = !empty($_POST['archivo_original_nombre']) ? $_POST['archivo_original_nombre'] : $_FILES['archivo_csv']['name'];
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

    // ── Detección de tipo de archivo ──
    $tipo_archivo_map = [
        'csv' => 'CSV',
        'xls' => 'Excel 97-2003 (.xls)',
        'xlsx' => 'Excel moderno (.xlsx)',
        'xlsm' => 'Excel con macros (.xlsm)',
        'xlsb' => 'Excel binario (.xlsb)',
    ];
    $tipo_archivo = $tipo_archivo_map[$ext] ?? ('Desconocido (extensión: ' . $ext . ')');

    $handle = fopen($archivo, 'r');
    if ($handle === FALSE) {
        throw new Exception("No se pudo abrir el archivo CSV.");
    }

    $linea_test = fgets($handle);

    // ── Detección robusta de separador ──
    // El criterio simple "si hay ';' usar ';' sino ','" falla cuando el archivo
    // usa ',' como separador de columna y los precios tienen ',' como decimal
    // (ej: "01,Art,1.234,56" → fgetcsv parte el precio). Verificamos consistencia.
    $primeras_lineas = [];
    $tmp = $handle;
    $i = 0;
    while ($i < 5 && ($l = fgets($tmp)) !== false) {
        $primeras_lineas[] = $l;
        $i++;
    }
    rewind($handle);

    $deteccion = detectar_separador_csv($primeras_lineas);
    $separador = $deteccion['separador'];
    $resultados_separation = $deteccion['inconsistencias'];

    $MAX_FILAS = 8;
    $filas = array();
    $num_cols = 0;

    while (($data = fgetcsv($handle, 0, $separador)) !== FALSE) {
        if (!empty($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]); // BOM UTF-8
        }
        $num_cols = max($num_cols, count($data));
        $filas[] = $data;

        // ── Calcular precios parseados por columna ──
        // Para cada columna, ver si parece ser precio (tiene formato numérico argentino o internacional)
        $precio_parseado = null;
        if (is_array($data) && isset($data[$col_precio])) {
            $precio_raw = trim($data[$col_precio]);
            $precio_parseado = precio_ar_a_float($precio_raw);
        }
        if (count($filas) >= $MAX_FILAS) break;
    }
    fclose($handle);

    if (empty($filas)) {
        throw new Exception("El archivo está vacío o no se pudo leer.");
    }
    if ($num_cols < 3) {
        throw new Exception("El archivo debe tener al menos 3 columnas (código, producto y precio).");
    }

    // ── Normaliza un texto a número (formato argentino: "." miles, "," decimal) ──
    $normalizar_precio = function ($raw) {
        return precio_ar_a_float($raw);
    };

    // ── Palabras típicas de encabezado (sin acentos, minúsculas) ──
    $keywords = array(
        'codigo', 'cod', 'codprov', 'detalle', 'descripcion', 'producto',
        'articulo', 'precio', 'pventa', 'importe', 'costo', 'unitario',
        'lista', 'nombre'
    );
    $parece_encabezado = function ($fila, $col_precio) use ($normalizar_precio, $keywords) {
        $raw = isset($fila[$col_precio]) ? trim($fila[$col_precio]) : '';
        if ($normalizar_precio($raw) !== null) return false; // tiene número → es un dato
        $matches = 0;
        foreach ($fila as $cell) {
            $t = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$cell)), 'UTF-8');
            $t = str_replace(array('á', 'é', 'í', 'ó', 'ú', '.', ' '), array('a', 'e', 'i', 'o', 'u', '', ''), $t);
            if ($t === '') continue;
            // Coincidencia por prefijo: "precio lista" → empieza con "precio", "cod articulo" → "cod"
            foreach ($keywords as $kw) {
                if (strpos($t, $kw) === 0) { $matches++; break; }
            }
        }
        return $matches >= 2;
    };

    // ── Estadísticas por columna (numéricas, decimales, largo de texto) ──
    $calcular_stats = function ($filas_set, $num_cols) use ($normalizar_precio) {
        $num = array_fill(0, $num_cols, 0);
        $dec = array_fill(0, $num_cols, 0);
        $len = array_fill(0, $num_cols, 0);
        $cnt = array_fill(0, $num_cols, 0);
        foreach ($filas_set as $f) {
            for ($c = 0; $c < $num_cols; $c++) {
                $cell = isset($f[$c]) ? trim($f[$c]) : '';
                if ($cell === '') continue;
                if ($normalizar_precio($cell) !== null) {
                    $num[$c]++;
                    if (preg_match('/[.,]/', $cell)) $dec[$c]++;
                }
                $len[$c] += mb_strlen($cell);
                $cnt[$c]++;
            }
        }
        return array('num' => $num, 'dec' => $dec, 'len' => $len, 'cnt' => $cnt);
    };

    // ── Detectar encabezado: se busca la columna más "numérica" y se prueba la fila 1 ──
    $stats_all = $calcular_stats($filas, $num_cols);

    // Columna precio candidata: mejor puntaje numérico (números + decimales), desempate: primera
    $col_precio = 0;
    $mejor_score = -1;
    for ($c = 0; $c < $num_cols; $c++) {
        $score = $stats_all['cnt'][$c] > 0
            ? ($stats_all['num'][$c] + $stats_all['dec'][$c]) / $stats_all['cnt'][$c]
            : 0;
        if ($score > $mejor_score) {
            $mejor_score = $score;
            $col_precio = $c;
        }
    }

    $has_header = $parece_encabezado($filas[0], $col_precio);

    // ── Sugerir mapeo usando solo filas de datos (sin encabezado) ──
    $filas_datos = $has_header ? array_slice($filas, 1) : $filas;
    if (empty($filas_datos)) $filas_datos = $filas;

    $stats = $calcular_stats($filas_datos, $num_cols);

    $restantes = array();
    for ($c = 0; $c < $num_cols; $c++) {
        if ($c !== $col_precio) $restantes[] = $c;
    }

    // Descripción: el texto más largo en promedio entre las columnas restantes
    $col_descripcion = null;
    $mejor_prom = -1;
    foreach ($restantes as $c) {
        $prom = $stats['cnt'][$c] > 0 ? $stats['len'][$c] / $stats['cnt'][$c] : 0;
        if ($prom > $mejor_prom) {
            $mejor_prom = $prom;
            $col_descripcion = $c;
        }
    }

    // Código: de las que quedan, la de texto más corto en promedio
    $col_codigo = null;
    $mejor_prom = PHP_INT_MAX;
    foreach ($restantes as $c) {
        if ($c === $col_descripcion) continue;
        $prom = $stats['cnt'][$c] > 0 ? $stats['len'][$c] / $stats['cnt'][$c] : 0;
        if ($prom < $mejor_prom) {
            $mejor_prom = $prom;
            $col_codigo = $c;
        }
    }

    if ($col_codigo === null || $col_descripcion === null) {
        throw new Exception("No se pudieron identificar 3 columnas (código / producto / precio) en el archivo.");
    }

    // ── Calcular precios parseados para cada fila ──
    // ── Detectar formato de precios (argentino vs internacional) ──
    // Revisar las filas de datos para determinar el formato predominante
    $formato_precios = 'desconocido';
    $con_coma_decimal = 0;
    $con_punto_decimal = 0;

    $filas_para_analizar = $has_header ? array_slice($filas, 1) : $filas;
    foreach ($filas_para_analizar as $fila) {
        if (!is_array($fila)) continue;
        $precio_raw = isset($fila[$col_precio]) ? trim($fila[$col_precio]) : '';
        if ($precio_raw === '' || !preg_match('/[0-9]/', $precio_raw)) continue;

        // Formato argentino: 1.234,56 (punto como miles, coma como decimal)
        if (preg_match('/^\d{1,3}(?:\.\d{3})*,\d{1,2}$/', $precio_raw)) {
            $con_coma_decimal++;
        }
        // Formato internacional: 1234.56 (punto como decimal) o 1,234.56 (coma miles, punto decimal)
        elseif (preg_match('/^\d{1,3}(?:,\d{3})*\.\d{1,2}$/', $precio_raw) || preg_match('/^\d+\.\d{1,2}$/', $precio_raw)) {
            $con_punto_decimal++;
        }
        // Número entero sin decimal (no contamos para ningún formato)
        elseif (preg_match('/^\d+$/', $precio_raw)) {
            // ignorar
        }
    }

    if ($con_coma_decimal > $con_punto_decimal && $con_coma_decimal > 0) {
        $formato_precios = 'argentino';
    } elseif ($con_punto_decimal > $con_coma_decimal && $con_punto_decimal > 0) {
        $formato_precios = 'internacional';
    }

    // ── Calcular precios parseados para cada fila ──
    // Usar el formato detectado para parsear cada precio
    $filas_con_precio_parseado = [];
    foreach ($filas as $idx => $fila) {
        if (!is_array($fila)) {
            $filas_con_precio_parseado[] = ['original' => $fila, 'precio_parseado' => null, 'formato_usado' => null];
            continue;
        }
        $precio_raw = isset($fila[$col_precio]) ? trim($fila[$col_precio]) : '';
        $precio_parseado = null;
        if ($precio_raw !== '') {
            if ($formato_precios === 'argentino') {
                $precio_parseado = precio_ar_a_float($precio_raw);
            } else {
                // Internacional: punto decimal, coma miles ("1234.56", "1,234.56")
                // Si no hay punto pero hay coma ("1234,56"), la coma es decimal.
                if (strpos($precio_raw, '.') === false && strpos($precio_raw, ',') !== false) {
                    $precio_parseado = floatval(str_replace(',', '.', $precio_raw));
                } else {
                    $precio_parseado = floatval(str_replace(',', '', $precio_raw));
                }
            }
        }
        $filas_con_precio_parseado[] = [
            'original' => $fila,
            'precio_original_raw' => $precio_raw,
            'precio_parseado' => $precio_parseado,
            'formato_usado' => $formato_precios,
        ];
    }

    ob_clean();
    echo json_encode([
        'success'    => true,
        'separador'  => $separador,
        'separador_inconsistencias' => $resultados_separation,
        'tipo_archivo' => $tipo_archivo,
        'columnas'   => $num_cols,
        'filas'      => $filas,
        'filas_con_precio_parseado' => $filas_con_precio_parseado,
        'formato_precios_detectado' => $formato_precios,
        'has_header' => $has_header,
        'mapa'       => array(
            'codigo'      => $col_codigo,
            'descripcion' => $col_descripcion,
            'precio'      => $col_precio
        )
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}