<?php
$url = "https://www.bna.com.ar/Personas";
$options = [
    "http" => [
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($url, false, $context);

if ($html !== false) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); 
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $query = "//table[contains(@class, 'table cotizacion')]/tbody/tr[1]/td[3]";
    $entries = $xpath->query($query);

    if ($entries->length > 0) {
        $valorRaw = trim($entries->item(0)->nodeValue);
        $valorLimpio = (float)str_replace(',', '.', str_replace('.', '', $valorRaw));
        
        // --- GUARDAR EN CACHE ---
        // Guardamos el valor limpio y la hora actual en un archivo de texto en tu servidor
        $data_to_save = [
            "valor" => $valorLimpio,
            "actualizado" => date("Y-m-d H:i:s")
        ];
        
        // Guardamos en formato JSON para que sea fácil de leer después
        file_put_contents(__DIR__ . '/dolar_cache.txt', json_decode(json_encode($data_to_save)));
        echo "Cache del dólar actualizado con éxito: " . $valorLimpio;
    }
}
?>