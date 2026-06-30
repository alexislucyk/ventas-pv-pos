<?php
header('Content-Type: application/json');

$ch = curl_init("https://dolarapi.com/v1/dolares/oficial");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

$dolar_compra = "-";
$dolar_venta = "-";

if ($response !== false) {
    $data = json_decode($response, true);
    if (isset($data['compra']) && isset($data['venta'])) {
        $dolar_compra = $data['compra'];
        $dolar_venta = $data['venta'];
    }
}

// Guardar en caché
$cache_file = dirname(__FILE__) . '/../cache/dolar_cache.json';
if (!is_dir(dirname($cache_file))) {
    mkdir(dirname($cache_file), 0755, true);
}
file_put_contents($cache_file, json_encode(['compra' => $dolar_compra, 'venta' => $dolar_venta]));

echo json_encode(['compra' => $dolar_compra, 'venta' => $dolar_venta]);