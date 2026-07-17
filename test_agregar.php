<?php
session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['empresa_id'] = 1;
$_SESSION['sucursal_id'] = 1;
$_SESSION['usuario_nombre'] = 'Test';
$_SESSION['usuario_rol'] = 'developer';

$_POST = [
    'cod_prod' => 'TEST' . time(),
    'descripcion' => 'Test Product',
    'p_compra' => '10',
    'p_venta' => '20',
    'stock' => '5',
    'fecha_ult_compra' => '2026-07-04',
    'rubro' => 'VARIOS',
    'proveedor' => 'GENERAL'
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
include __DIR__ . '/ajax/agregar_producto_rapido.php';
$output = ob_get_clean();
echo "Output: $output\n";
