<?php
// pages/buscar_producto_ajax.php

header('Content-Type: application/json');

// 1. INCLUSIÓN DE CONFIGURACIÓN
require '../config/db_config.php'; 

// 2. VALIDACIÓN DEL PARÁMETRO DE BÚSQUEDA
if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    // Devolver array vacío si no hay búsqueda
    echo json_encode([]);
    exit;
}

// 3. DEFINICIÓN DE LA BÚSQUEDA
// Preparamos la cadena de búsqueda con wildcards para la consulta LIKE
$busqueda = trim($_GET['q']);
$param_busqueda = '%' . $busqueda . '%';

try {
    // 4. CONSULTA SQL CON SENTENCIA PREPARADA
    $sql = "SELECT cod_prod, descripcion, p_venta, stock 
            FROM productos 
            WHERE cod_prod LIKE ? OR descripcion LIKE ?
            LIMIT 10";

    $stmt = $pdo->prepare($sql);

    // 5. EJECUTAR
    // ¡CORRECCIÓN CLAVE! Pasar el parámetro DOS VECES (uno por cada ?)
    $stmt->execute([$param_busqueda, $param_busqueda]); 

    // 6. OBTENER Y DEVOLVER RESULTADOS (JSON)
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($productos);

} catch (Exception $e) {
    // 7. MANEJO DE ERRORES DE BASE DE DATOS
    error_log("Error en la búsqueda AJAX: " . $e->getMessage());
    http_response_code(500); // Indicamos un error interno del servidor
    // Devolvemos un mensaje de error específico para el cliente (útil para debug)
    echo json_encode(["error" => "Error interno al buscar productos."]); 
}

?>