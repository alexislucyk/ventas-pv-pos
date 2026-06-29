<?php
// 1. Inicializamos cURL con el endpoint de tu captura
$ch = curl_init("https://dolarapi.com/v1/dolares/oficial");

// Configuramos para que devuelva el resultado como string
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Ejecutamos la petición
$response = curl_exec($ch);
curl_close($ch);

// 2. Definimos variables por defecto (por seguridad si la API no responde)
$dolar_compra = "-";
$dolar_venta  = "-";

if ($response !== false) {
    // Transformamos el JSON de tu imagen en un array asociativo de PHP
    $data = json_decode($response, true);
    
    // Verificamos que existan los datos antes de usarlos
    if (isset($data['compra']) && isset($data['venta'])) {
        $dolar_compra = $data['compra']; // Guardará: 1445
        $dolar_venta  = $data['venta'];  // Guardará: 1495
    }
}
?>

<div class="topbar" style="background: #1e1e24; color: #ffffff; padding: 12px; font-family: Arial, sans-serif; display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
    
    

    <div style="display: flex; gap: 15px;">
        <span style="background: #2b2b36; padding: 5px 10px; border-radius: 4px;">
            💵 <strong>U$S</strong>
        </span>
        <span style="color: #4caf50;">
            Compra: <strong>$<?php echo $dolar_compra; ?></strong>
        </span>
        <span style="color: #ff5722;">
            Venta: <strong>$<?php echo $dolar_venta; ?></strong>
        </span>
    </div>

</div>