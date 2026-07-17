<?php
// procesar_factura_arca.php
include 'infosesion.php';
require '../config/db_config.php';

// A) PARCHE CRÍTICO DE SEGURIDAD: validar permiso en el backend (no solo en la UI)
require_permiso('pages/facturacion_arca.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Falta empresa_id en sesión.']);
    exit();
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_venta'])) {
    $id_venta = $_POST['id_venta'];
    $cuit_vendedor = "20123456789";

    try {
        $stmt = $pdo->prepare("
            SELECT v.*, c.cuit, c.nombre, c.apellido, 
                   COALESCE(c.id_tipo_iva, 99) as id_tipo_iva, 
                   COALESCE(c.dni, '') as dni
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = ?
            WHERE v.id = ? AND v.empresa_id = ?
        ");
        $stmt->execute([$id_venta, $id_venta, $empresa_id]);
        $venta = $stmt->fetch();

        $check = $pdo->prepare("SELECT id FROM ventas_afip WHERE id_venta = ? AND empresa_id = ?");
        $check->execute([$id_venta, $empresa_id]);
        if ($check->fetch()) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Esta venta ya tiene un comprobante ARCA asociado.'
            ]);
            exit;
        }

        if (!$venta) throw new Exception("Venta no encontrada.");

        // 3. LÓGICA REAL CON EL SDK (Pre-configurada para Homologación)
        /*
        if (!class_exists('Afip')) {
            throw new Exception("El SDK de AFIP no está instalado correctamente. Ejecute 'composer require afipsdk/afip.php'");
        }

        $path_res = dirname(__DIR__) . '/afip_res/'; // Ruta absoluta a la carpeta de recursos
        $afip = new Afip([
            'CUIT' => $cuit_vendedor,
            'cert' => $path_res . 'tu_certificado.crt', 
            'key'  => $path_res . 'tu_llave.key',
            'res_folder' => $path_res, 
            'production' => false // Cambiar a true cuando pases a producción
        ]);

        $data = [
            'CantReg'    => 1,  // Cantidad de comprobantes a registrar
            'PtoVta'     => 1,  // Punto de venta
            'CbteTipo'   => 11, // 11 = Factura C, 6 = Factura B, 1 = Factura A
            'Concepto'   => 1,  // 1 = Productos, 2 = Servicios
            'DocTipo'    => ($venta['cuit'] != '') ? 80 : (($venta['dni'] != '') ? 96 : 99), 
            'DocNro'     => ($venta['cuit'] != '') ? $venta['cuit'] : 0,
            'CbteDesde'  => 1,  // Se calcula automáticamente con GetLastVoucher
            'CbteHasta'  => 1,
            'CbteFch'    => date('Ymd'),
            'ImpTotal'   => $venta['total_venta'],
            'ImpTotalConc' => 0,
            'ImpNeto'    => $venta['total_venta'],
            'ImpOpEx'    => 0,
            'ImpIVA'     => 0,
            'ImpTrib'    => 0,
            'MonId'      => 'PES',
            'MonCotiz'   => 1,
        ];

        $res = $afip->ElectronicBilling->CreateVoucher($data);
        $cae = $res['CAE'];
        $vto = $res['CAEVto'];
        $nro = $res['CbteDesde'];
        */

        // MOCK para pruebas de guardado hasta instalar SDK
        $cae_simulado = "74123456789012";
        $vto_simulado = date('Y-m-d', strtotime('+15 days'));
        $nro_comprobante = 125; 

        $sql = "INSERT INTO ventas_afip (id_venta, cae, cae_vto, punto_venta, n_comprobante, tipo_comprobante, empresa_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([
            $id_venta,
            $cae_simulado,
            $vto_simulado,
            1,
            $nro_comprobante,
            11,
            $empresa_id
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Factura generada con éxito',
            'cae' => $cae_simulado
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>