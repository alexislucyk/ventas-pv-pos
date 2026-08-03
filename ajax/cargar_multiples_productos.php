<?php
header('Content-Type: application/json');
include_once '../pages/infosesion.php';
global $pdo;

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Falta empresa_id en sesión.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$proveedor = trim($input['proveedor'] ?? '');
$rubro = trim($input['rubro'] ?? '');
$moneda = trim($input['moneda'] ?? 'pesos');
$productos = $input['productos'] ?? [];

if (empty($proveedor) || empty($rubro)) {
    echo json_encode(['success' => false, 'error' => 'Faltan datos obligatorios (proveedor o rubro)']);
    exit;
}

if (empty($productos) || !is_array($productos)) {
    echo json_encode(['success' => false, 'error' => 'No hay productos para cargar']);
    exit;
}

$insertados = 0;
$actualizados = 0;
$errores = [];
$codigos_procesados = []; // Para detectar duplicados dentro de la misma carga

// Primero: validar todos los productos antes de insertar nada
foreach ($productos as $index => $prod) {
    $cod_prod = trim($prod['cod'] ?? '');
    $descripcion = trim($prod['desc'] ?? '');
    $compra_raw = trim($prod['compra'] ?? '');
    $venta_raw = trim($prod['venta'] ?? '');
    $stock_raw = trim($prod['stock'] ?? '0');
    
    // Validar campos obligatorios
    if (empty($cod_prod)) {
        $errores[] = "Fila " . ($index + 1) . ": Código vacío";
        continue;
    }
    
    if (empty($descripcion)) {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Descripción vacía";
        continue;
    }
    
    // Validar que no haya duplicados dentro de la misma carga
    if (isset($codigos_procesados[$cod_prod])) {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Código duplicado en la misma carga";
        continue;
    }
    $codigos_procesados[$cod_prod] = true;
    
    // Validar y normalizar números
    $p_compra = str_replace(',', '.', $compra_raw);
    $p_venta = str_replace(',', '.', $venta_raw);
    $stock = str_replace(',', '.', $stock_raw);
    
    if (!is_numeric($p_compra) || $p_compra === '') {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Precio de compra inválido";
        continue;
    }
    
    if (!is_numeric($p_venta) || $p_venta === '') {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Precio de venta inválido";
        continue;
    }
    
    if (!is_numeric($stock)) {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Stock inválido";
        continue;
    }
    
    $p_compra = (float)$p_compra;
    $p_venta = (float)$p_venta;
    $stock = (float)$stock;
    
    // Validar que precio de venta sea mayor a precio de compra
    if ($p_venta < $p_compra) {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Precio de venta ($p_venta) es menor al precio de compra ($p_compra)";
        continue;
    }
    
    // Verificar si el producto ya existe
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE empresa_id = ? AND cod_prod = ?");
    $stmt_check->execute([$empresa_id, $cod_prod]);
    $existe = $stmt_check->fetchColumn() > 0;
    
    if ($existe) {
        $errores[] = "Fila " . ($index + 1) . " (Código: $cod_prod): Ya existe en la base de datos";
    }
}

// Si hay errores de validación, no procesar nada
if (!empty($errores)) {
    echo json_encode([
        'success' => false, 
        'error' => 'No se pudo procesar la carga. Errores: ' . implode('; ', $errores)
    ]);
    exit;
}

// Segundo: procesar todos los productos (ahora sabemos que son válidos)
foreach ($productos as $index => $prod) {
    $cod_prod = trim($prod['cod'] ?? '');
    $descripcion = trim($prod['desc'] ?? '');
    $p_compra = (float)str_replace(',', '.', $prod['compra']);
    $p_venta = (float)str_replace(',', '.', $prod['venta']);
    $stock = (float)str_replace(',', '.', $prod['stock'] ?? 0);
    
    try {
        // Verificar nuevamente si existe (para decidir si insertar o actualizar)
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE empresa_id = ? AND cod_prod = ?");
        $stmt_check->execute([$empresa_id, $cod_prod]);
        $existe = $stmt_check->fetchColumn() > 0;
        
        if ($existe) {
            // ACTUALIZAR producto existente
            $sql = "UPDATE productos 
                    SET descripcion = ?, p_compra = ?, p_venta = ?, fecha_ult_compra = CURDATE(), 
                        rubro = ?, proveedor = ?, moneda = ? 
                    WHERE empresa_id = ? AND cod_prod = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $descripcion, $p_compra, $p_venta, $rubro, $proveedor, $moneda,
                $empresa_id, $cod_prod
            ]);
            $actualizados++;
        } else {
            // INSERTAR nuevo producto
            $sql = "INSERT INTO productos (cod_prod, descripcion, p_compra, p_venta, fecha_ult_compra, rubro, proveedor, moneda, empresa_id, stock)
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 0)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $cod_prod, $descripcion, $p_compra, $p_venta, 
                $rubro, $proveedor, $moneda, $empresa_id
            ]);
            $insertados++;
        }
        
        // Guardar stock en tabla stocks (por sucursal) - CORREGIDO: reemplazo, no suma
        try {
            $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) 
                          VALUES (?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)";
            $stmt_stock = $pdo->prepare($sql_stock);
            $stmt_stock->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);
        } catch (Exception $e) {
            $errores[] = $cod_prod . ' (producto ' . ($existe ? 'actualizado' : 'insertado') . ', pero error en stock: ' . $e->getMessage() . ')';
        }
        
    } catch (Exception $e) {
        $errores[] = $cod_prod . ': ' . $e->getMessage();
    }
}

// Generar mensaje de respuesta
if ($insertados > 0 || $actualizados > 0) {
    $msg = [];
    if ($insertados > 0) $msg[] = "$insertados producto(s) nuevo(s) insertado(s)";
    if ($actualizados > 0) $msg[] = "$actualizados producto(s) actualizado(s)";
    
    $mensaje_exito = implode(', ', $msg);
    
    if (!empty($errores)) {
        $mensaje_final = $mensaje_exito . ". PERO hubo errores: " . implode('; ', $errores);
        echo json_encode([
            'success' => true, 
            'message' => $mensaje_final,
            'warning' => true,
            'insertados' => $insertados,
            'actualizados' => $actualizados,
            'errores' => $errores
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'message' => $mensaje_exito,
            'insertados' => $insertados,
            'actualizados' => $actualizados
        ]);
    }
} else {
    $error_msg = 'No se pudo insertar ni actualizar ningún producto';
    if (!empty($errores)) {
        $error_msg .= '. Errores: ' . implode('; ', $errores);
    }
    echo json_encode(['success' => false, 'error' => $error_msg]);
}