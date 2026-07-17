<?php
// archivo: pages/generar_pdf_ticket.php

while (ob_get_level()) { ob_end_clean(); }
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// 1. Intento de carga robusta de la librería para generar el QR
$rutas_qr = [
    dirname(__DIR__) . '/libs/phpqrcode/qrlib.php',
    dirname(__DIR__) . '/libs/phpqrcode/phpqrcode/qrlib.php',
    dirname(__DIR__) . '/libs/phpqrcode/lib/qrlib.php'
];

foreach ($rutas_qr as $ruta) {
    if (file_exists($ruta)) {
        require_once($ruta);
        break;
    }
}

/**
 * Convierte strings de UTF-8 a ISO-8859-1 para compatibilidad con FPDF
 */
function to_iso($text) {
    if ($text === null) return '';
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

// Extender FPDF para manejar el pie de página de forma automática
class PDF extends FPDF {
    public $es_oficial = false; // Propiedad para controlar el texto del pie

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
        $txt_validez = $this->es_oficial ? "Comprobante Autorizado" : "Documento no válido como factura fiscal";
        $this->Cell(0, 4, mb_convert_encoding($txt_validez, 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        
        // Segunda línea: Agradecimiento centrado y numeración a la derecha
        $this->Cell(0, 4, mb_convert_encoding("Gracias por su preferencia", 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        $this->SetX($this->lMargin); // Volvemos al margen izquierdo para superponer la numeración
        $this->Cell(0, 4, mb_convert_encoding("Pág. " . $this->PageNo() . " / {nb}", 'ISO-8859-1', 'UTF-8'), 0, 1, 'R');
    }
}

$n_doc = isset($_GET['n_documento']) ? intval($_GET['n_documento']) : 0;
$id_venta = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($n_doc === 0 && $id_venta === 0) die("N° de documento no valido.");

try {
    // Consulta extendida para incluir datos de AFIP y campos fiscales del cliente
    $venta = null;
    try {
        // Buscar por id (prioritario, único y robusto) o por n_documento
        if ($id_venta > 0) {
            // El id es PRIMARY KEY global: no filtramos por empresa_id para evitar
            // fallos por diferencias de sesión/empresa entre páginas.
            $stmt = $pdo->prepare("SELECT v.*, c.nombre, c.apellido, c.cuit, c.direccion as dir_cliente, vf.cant_cuotas, vf.monto_interes,
                                          c.dni, c.id_tipo_iva,
                                          af.cae, af.cae_vto, af.punto_venta, af.n_comprobante, af.tipo_comprobante
                                  FROM ventas v
                                  LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = v.empresa_id
                                  LEFT JOIN ventas_financiacion vf ON v.id = vf.id_venta
                                  LEFT JOIN ventas_afip af ON v.id = af.id_venta
                                  WHERE v.id = :id
                                  LIMIT 1");
            $stmt->execute([':id' => $id_venta]);
        } else {
            $stmt = $pdo->prepare("SELECT v.*, c.nombre, c.apellido, c.cuit, c.direccion as dir_cliente, vf.cant_cuotas, vf.monto_interes,
                                          c.dni, c.id_tipo_iva,
                                          af.cae, af.cae_vto, af.punto_venta, af.n_comprobante, af.tipo_comprobante
                                  FROM ventas v
                                  LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = :empresa_id
                                  LEFT JOIN ventas_financiacion vf ON v.id = vf.id_venta
                                  LEFT JOIN ventas_afip af ON v.id = af.id_venta
                                  WHERE v.n_documento = :n_documento AND v.empresa_id = :empresa_id
                                  LIMIT 1");
            $stmt->execute([':n_documento' => $n_doc, ':empresa_id' => $empresa_id]);
        }
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignorar */ }
    if (!$venta) die("Venta no encontrada.");
    
    // Usar el número de documento real de la venta encontrada (importante cuando se busca por ID)
    $n_doc = (int)$venta['n_documento'];

    // Determinar si es una factura oficial o una orden interna
    $es_oficial = !empty($venta['cae']);
    $letra_comprobante = "X";
    if ($es_oficial) {
        $letra_comprobante = ($venta['tipo_comprobante'] == 11) ? "C" : (($venta['tipo_comprobante'] == 1) ? "A" : "B");
    }

    // Consulta de datos de la empresa
    $stmt_emp = $pdo->query("SELECT * FROM datos_empresa WHERE id = 1 LIMIT 1");
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    // --- CONFIGURACIÓN A5 ---
    $pdf = new PDF('P', 'mm', 'A5');
    $pdf->es_oficial = $es_oficial; // Seteamos la propiedad antes de agregar la página
    $pdf->AliasNbPages(); // Habilitar el contador total de páginas {nb}
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(5, 5, 5);
    $ancho_total = 138; // Ancho efectivo (148mm - 10mm margen)

    // --- ENCABEZADO: RECUADRO Y LÍNEA DIVISORIA ---
    $alto_enc = 32;
    $pdf->Rect(5, 5, $ancho_total, $alto_enc); 
    // Línea vertical justo al medio
    $pdf->Line(5 + ($ancho_total/2), 5, 5 + ($ancho_total/2), 5 + $alto_enc);

    // CUADRO DE LA "X" (Centrado exacto)
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY(5 + ($ancho_total/2) - 6, 5);
    $pdf->Cell(12, 12, '', 1, 0, 'C', true); // Dibujamos el recuadro contenedor

    $pdf->SetXY(5 + ($ancho_total/2) - 6, 5);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(12, 9, $letra_comprobante, 0, 0, 'C'); // Letra de factura (A, B o C)

    if ($es_oficial) {
        $pdf->SetXY(5 + ($ancho_total/2) - 6, 13);
        $pdf->SetFont('Arial', 'B', 6);
        $cod_afip = str_pad($venta['tipo_comprobante'], 3, "0", STR_PAD_LEFT);
        $pdf->Cell(12, 3, "COD. " . $cod_afip, 0, 0, 'C');
    }
    
    $pdf->SetXY(5 + ($ancho_total/2) - 15, 17);
    $pdf->SetFont('Arial', 'B', 6);
    $txt_validez = $es_oficial ? "COMPROBANTE ELECTRÓNICO" : "DOC. NO VÁLIDO COMO FACTURA";
    $pdf->Cell(30, 3, to_iso($txt_validez), 0, 0, 'C');

    // LADO IZQUIERDO: Datos Empresa
    $pdf->SetXY(7, 8);
    $pdf->SetFont('Arial', 'B', 11);
    // Limitamos el ancho para que el texto no toque el cuadro de la X
    $pdf->MultiCell(($ancho_total/2) - 8, 5, to_iso(strtoupper($emp['nombre_fantasia'])), 0, 'L');
    $pdf->Ln(2); // Espacio entre nombre de fantasía y razón social
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['razon_social']), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['direccion']), 0, 1, 'L');
    $pdf->SetX(7);
    $pdf->Cell(($ancho_total/2) - 8, 4, to_iso($emp['localidad'] . " - " . $emp['provincia']), 0, 1, 'L');
    
    if ($es_oficial) {
        $pdf->SetX(7);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell(($ancho_total/2) - 8, 4, to_iso(strtoupper($emp['condicion_iva'])), 0, 1, 'L');
    }

    // LADO DERECHO: Datos Comprobante
    $pdf->SetXY(5 + ($ancho_total/2) + 5, 8);
    $pdf->SetFont('Arial', 'B', 11);
    $titulo_doc = $es_oficial ? "FACTURA " . $letra_comprobante : "ORDEN DE VENTA";
    $pdf->Cell(($ancho_total/2) - 10, 6, to_iso($titulo_doc), 0, 1, 'R');

    $pdf->SetX(5 + ($ancho_total/2) + 5);
    $pdf->SetFont('Arial', 'B', 10);
    
    if ($es_oficial) {
        $n_final = str_pad($venta['punto_venta'], 5, "0", STR_PAD_LEFT) . "-" . str_pad($venta['n_comprobante'], 8, "0", STR_PAD_LEFT);
    } else {
        $n_final = str_pad($n_doc, 8, "0", STR_PAD_LEFT);
    }
    $pdf->Cell(($ancho_total/2) - 10, 6, "N" . chr(176) . " " . $n_final, 0, 1, 'R');

    // Si es oficial, agregamos el nro interno en pequeño como referencia
    if ($es_oficial) {
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->SetFont('Arial', 'I', 6);
        $pdf->Cell(($ancho_total/2) - 10, 3, to_iso("Ref. Interna: #" . $n_doc), 0, 1, 'R');
    }

    // Si es oficial, agregamos los campos fiscales achicados del lado derecho
    if ($es_oficial) {
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->Cell(($ancho_total/2) - 10, 3.5, "Fecha Emision: " . date("d/m/Y", strtotime($venta['fecha_venta'])), 0, 1, 'R');
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->Cell(($ancho_total/2) - 10, 3.5, to_iso("CUIT: " . $emp['cuit']), 0, 1, 'R');
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->Cell(($ancho_total/2) - 10, 3.5, to_iso("IIBB: " . $emp['ingresos_brutos']), 0, 1, 'R');
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->Cell(($ancho_total/2) - 10, 3.5, to_iso("Inicio Act: " . date('d/m/Y', strtotime($emp['inicio_actividades']))), 0, 1, 'R');
    }
    
    // Si no es oficial (Orden interna), mantenemos la fecha a la derecha de forma sencilla
    if (!$es_oficial) {
        $pdf->SetX(5 + ($ancho_total/2) + 5);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(($ancho_total/2) - 10, 6, "Fecha: " . date("d/m/Y", strtotime($venta['fecha_venta'])), 0, 1, 'R');
    }

    // --- SECCIÓN: DATOS DEL CLIENTE ---
    $pdf->SetY(42);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($ancho_total, 6, to_iso("  DATOS DEL CLIENTE"), 1, 1, 'L', true);
    
    $pdf->Rect(5, 48, $ancho_total, 18); // Recuadro inferior de datos
    $pdf->SetY(49);
    
    $ape = trim($venta['apellido'] ?? '');
    $nom = trim($venta['nombre'] ?? '');
    $cliente = ($ape !== '' || $nom !== '') ? trim($ape . ($nom !== '' ? ($ape !== '' ? ', ' : '') . $nom : '')) : "CONSUMIDOR FINAL";
    
    $condicion_iva = "Consumidor Final";
    if (!empty($venta['id_tipo_iva'])) {
        $tipos = [1 => 'Responsable Inscripto', 6 => 'Monotributo', 4 => 'Exento'];
        $condicion_iva = $tipos[$venta['id_tipo_iva']] ?? "Consumidor Final";
    }

    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "Cliente:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(62, 5, to_iso($cliente), 0, 0, 'L');
    
    if ($es_oficial) {
        $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(12, 5, "IVA:", 0, 0, 'L');
        $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($condicion_iva), 0, 1, 'L');
    } else {
        $pdf->Ln(5);
    }

    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(15, 5, "CUIT/DNI:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(62, 5, ($venta['cuit'] ?: "S/D"), 0, 0, 'L');
    
    // Lógica para mostrar "FINANCIADO EN X CUOTAS"
    $cond_pago_txt = $venta['cond_pago'];
    if (strtoupper($venta['cond_pago']) === 'FINANCIADO' && !empty($venta['cant_cuotas'])) {
        $cond_pago_txt = " FINANCIADO - " . $venta['cant_cuotas'] . " CUOTAS";
    }

    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(12, 5, "Pago:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($cond_pago_txt), 0, 1, 'L');
    
    $pdf->SetX(7);
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(18, 5, "Domicilio:", 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 5, to_iso($venta['dir_cliente'] ?: "ZONA URBANA"), 0, 1, 'L');

    $pdf->Ln(5);

    // --- TABLA DE DETALLE ---
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(230, 230, 230);
    // Anchos ajustados para 138mm: 12 + 81 + 22 + 23 = 138
    $pdf->Cell(12, 7, "CANT", 1, 0, 'C', true);
    $pdf->Cell(81, 7, to_iso("  DESCRIPCIÓN"), 1, 0, 'L', true); // Alineación a la izquierda con padding
    $pdf->Cell(22, 7, "P. UNIT", 1, 0, 'R', true);
    $pdf->Cell(23, 7, "TOTAL", 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 8);
    // Usar siempre el n_documento y empresa_id reales de la venta encontrada
    // (cuando se accede por id, $n_doc del GET puede ser 0)
    $n_doc_real = $venta['n_documento'] ?? $n_doc;
    $empresa_id_real = $venta['empresa_id'] ?? $empresa_id;
    $stmt_det = $pdo->prepare("SELECT descripcion, cant, p_unit, descuento_unitario, total FROM ventas_detalle WHERE n_documento = ? AND empresa_id = ?");
    $stmt_det->execute([$n_doc_real, $empresa_id_real]);
    
    $total_bruto_acumulado = 0;
    while ($row = $stmt_det->fetch(PDO::FETCH_ASSOC)) {
        $subtotal_bruto_item = $row['p_unit'] * $row['cant'];
        $total_bruto_acumulado += $subtotal_bruto_item;
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // 1. Medimos la altura de la descripción (invisible) para centrado vertical
        $pdf->SetTextColor(255, 255, 255); 
        $pdf->SetXY($x + 13, $y); // +1mm de padding
        $pdf->MultiCell(79, 4, to_iso($row['descripcion']), 0, 'L');
        $y_final = $pdf->GetY();
        $altura_texto = $y_final - $y;
        $alto_fila = max(7, $altura_texto);
        $pdf->SetTextColor(0, 0, 0); // Restauramos a negro

        // 2. Calculamos desplazamiento para centrado vertical
        $offset_y = ($alto_fila - $altura_texto) / 2;

        // 3. Dibujamos los bordes y el contenido de las otras columnas (que se centran solas)
        $pdf->SetXY($x, $y);
        $pdf->Cell(12, $alto_fila, number_format($row['cant'], 2), 1, 0, 'C');
        $pdf->Cell(81, $alto_fila, '', 1, 0, 'L'); // Solo dibujamos el borde
        $pdf->Cell(22, $alto_fila, "$ " . number_format($row['p_unit'], 2), 1, 0, 'R');
        $pdf->Cell(23, $alto_fila, "$ " . number_format($row['total'], 2), 1, 1, 'R');

        // 4. Imprimimos la descripción real centrada verticalmente y con padding horizontal
        $pdf->SetXY($x + 13, $y + $offset_y);
        $pdf->MultiCell(79, 4, to_iso($row['descripcion']), 0, 'L');

        // 5. Ajustamos el cursor para la siguiente fila
        $pdf->SetY($y + $alto_fila);

        // 6. Mostrar descuento por ítem si existe
        if ($row['descuento_unitario'] > 0) {
            $pdf->SetFont('Arial', 'I', 7);
            $pdf->SetTextColor(100, 100, 100);
            $monto_desc_item = $subtotal_bruto_item * ($row['descuento_unitario'] / 100);
            $pdf->Cell(12, 4, '', 0);
            $pdf->Cell(103, 4, to_iso("Descuento aplicado: " . (float)$row['descuento_unitario'] . "% (-$ " . number_format($monto_desc_item, 2, ',', '.') . ")"), 0, 0, 'L');
            $pdf->Cell(23, 4, '', 0, 1);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    // --- SECCIÓN DE TOTALES ---
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    
    $total_neto_venta = (float)($venta['total_venta'] ?? 0);
    $desc_global = (float)($venta['descuento_global'] ?? 0);
    $interes = 0;
    $entrega = 0;

    // Mostrar Subtotal Bruto
    $pdf->Cell(115, 6, to_iso("SUBTOTAL BRUTO: "), 0, 0, 'R');
    $pdf->Cell(23, 6, "$ " . number_format($total_bruto_acumulado, 2, ',', '.'), 1, 1, 'R');
    
    if (strtoupper($venta['cond_pago']) === 'FINANCIADO') {
        $interes = (float)($venta['monto_interes'] ?? 0);
        $entrega = (float)($venta['pago_efectivo'] ?? 0) + (float)($venta['pago_transf'] ?? 0);
        
        // Subtotal (Suma de los productos sin intereses)
        $pdf->Cell(115, 6, to_iso("SUBTOTAL PRODUCTOS: "), 0, 0, 'R');
        $pdf->Cell(23, 6, "$ " . number_format($total_base, 2, ',', '.'), 1, 1, 'R');
        
        // Entrega Inicial (Suma de efectivo y transferencia)
        if ($entrega > 0) {
            $pdf->Cell(115, 6, to_iso("ENTREGA INICIAL: "), 0, 0, 'R');
            $pdf->Cell(23, 6, "- $ " . number_format($entrega, 2, ',', '.'), 1, 1, 'R');
        }

        // Intereses de Financiación (Se muestran después de la entrega, según el flujo de cálculo)
        if ($interes > 0) {
            $pdf->Cell(115, 6, to_iso("INTERESES FINANCIACIÓN: "), 0, 0, 'R');
            $pdf->Cell(23, 6, "$ " . number_format($interes, 2, ',', '.'), 1, 1, 'R');
        }
    }

    // Mostrar Descuento Global si existe
    if ($desc_global > 0) {
        $pdf->Cell(115, 6, to_iso("DESCUENTO GLOBAL: "), 0, 0, 'R');
        $pdf->Cell(23, 6, "- $ " . number_format($desc_global, 2, ',', '.'), 1, 1, 'R');
    }

    // El saldo a financiar o total a pagar final
    $total_final = $total_neto_venta + $interes - $entrega;
    
    // Texto dinámico para el total según la condición
    $texto_total = (strtoupper($venta['cond_pago']) === 'FINANCIADO') ? "SALDO A FINANCIAR: " : "TOTAL A PAGAR: ";

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(115, 8, to_iso($texto_total), 0, 0, 'R');
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(23, 8, "$ " . number_format($total_final, 2, ',', '.'), 1, 1, 'R', true);

    // --- SECCIÓN DE FINANCIACIÓN (Solicitado en Manifiesto) ---
    if (strtoupper($venta['cond_pago']) === 'FINANCIADO') {
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(0, 92, 212); // Color azul para destacar
        $pdf->Cell(0, 6, to_iso("PLAN DE FINANCIACIÓN"), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        
        $stmt_plan = $pdo->prepare("SELECT nro_cuota, fecha_vencimiento, monto_original FROM cuotas_seguimiento WHERE id_venta = ? ORDER BY nro_cuota ASC");
        $stmt_plan->execute([$venta['id']]);
        $plan = $stmt_plan->fetchAll();

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(20, 6, to_iso("Cuota"), 1, 0, 'C', true);
        $pdf->Cell(30, 6, to_iso("Vencimiento"), 1, 0, 'C', true);
        $pdf->Cell(30, 6, to_iso("Importe"), 1, 1, 'C', true);

        foreach ($plan as $cuota) {
            $pdf->Cell(20, 6, $cuota['nro_cuota'], 1, 0, 'C');
            $pdf->Cell(30, 6, date('d/m/Y', strtotime($cuota['fecha_vencimiento'])), 1, 0, 'C');
            $pdf->Cell(30, 6, "$ " . number_format($cuota['monto_original'], 2, ',', '.'), 1, 1, 'R');
        }
        $pdf->Ln(5);
    }

    // --- SECCIÓN FISCAL (CAE) ---
    if ($es_oficial) {
        // Desactivamos el salto automático momentáneamente para controlar el pie manual
        $pdf->SetAutoPageBreak(false);

        // Posicionamiento absoluto al pie
        $pdf->SetY(-52); 
        $y_fiscal = $pdf->GetY();

        // Bajamos el cursor para alinear la base del bloque de texto con la base del QR (35mm de alto)
        $pdf->SetY($y_fiscal + 25);

        // CAE y Vencimiento alineados a la derecha
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetX(5 + ($ancho_total / 2)); 
        $pdf->Cell(($ancho_total / 2), 5, "CAE N" . chr(176) . ": " . $venta['cae'], 0, 1, 'R');
        $pdf->SetX(5 + ($ancho_total / 2));
        $pdf->Cell(($ancho_total / 2), 5, "Vto. CAE: " . date('d/m/Y', strtotime($venta['cae_vto'])), 0, 0, 'R');
        
        // --- GENERACIÓN DINÁMICA DE QR AFIP/ARCA ---
        if (class_exists('QRcode')) {
            // Construir el JSON según normativa ARCA
            $datos_qr = [
                "ver" => 1,
                "fecha" => date('Y-m-d', strtotime($venta['fecha_venta'])),
                "cuit" => (int)preg_replace('/[^0-9]/', '', (string)$emp['cuit']),
                "ptoVta" => (int)$venta['punto_venta'],
                "tipoCbte" => (int)$venta['tipo_comprobante'],
                "nroCbte" => (int)$venta['n_comprobante'],
                "importe" => (float)$venta['total_venta'],
                "moneda" => "PES",
                "ctz" => 1,
                "tipoDocRec" => ($venta['cuit'] != '') ? 80 : (($venta['dni'] != '') ? 96 : 99),
                "nroDocRec" => ($venta['cuit'] != '') ? (int)preg_replace('/[^0-9]/', '', (string)$venta['cuit']) : ((int)preg_replace('/[^0-9]/', '', (string)($venta['dni'] ?? '')) ?: 0),
                "tipoCodAut" => "E", // E para CAE
                "codAut" => (int)$venta['cae']
            ];

            // 2. Codificar en Base64 para la URL de AFIP
            $payload = base64_encode(json_encode($datos_qr));
            $afip_qr_url = "https://www.afip.gob.ar/fe/qr/?p=" . $payload;

            // 3. Generar imagen temporal del QR
            $img_dir = dirname(__DIR__) . '/img/';
            if (!is_dir($img_dir)) {
                @mkdir($img_dir, 0777, true);
            }
            
            $qr_temp_path = $img_dir . 'temp_qr_' . $venta['cae'] . '.png';
            @QRcode::png($afip_qr_url, $qr_temp_path, 'L', 4, 1); 

            // 4. Insertar en el PDF y eliminar temporal
            if (file_exists($qr_temp_path)) {
                $pdf->Image($qr_temp_path, 5, $y_fiscal, 35, 35);
                @unlink($qr_temp_path);
            }
        } else {
            // Fallback si no está la librería instalada
            $pdf->SetFont('Arial', 'I', 7);
            $pdf->Cell(0, 5, to_iso("(Código QR no disponible - Instalar phpqrcode)"), 0, 1, 'L');
        }
    }

    // Salida del PDF
    $dest = isset($_GET['download']) ? 'D' : 'I';
    $pdf->Output($dest, 'Orden_Venta_' . $n_doc . '.pdf');

} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}