<?php
// archivo: pages/generar_pdf_presupuesto.php
// Genera un PDF profesional del presupuesto con FPDF.

if (ob_get_contents()) ob_end_clean();

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require('../fpdf/fpdf.php');
require('../config/db_config.php');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

function to_iso($text) {
    return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id === 0) {
    die("ID de presupuesto no valido.");
}

$stmt = $pdo->prepare("SELECT p.*, c.* FROM presupuestos p 
                       JOIN clientes c ON p.id_cliente = c.id AND c.empresa_id = ?
                       WHERE p.id = ? AND p.empresa_id = ?");
$stmt->execute([$empresa_id, $id, $empresa_id]);
$presu = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presu) {
    die("Error: No se encontraron datos para el presupuesto ID: " . $id);
}

// ---------- Datos de la empresa ----------
try {
    $stmt_emp = $pdo->prepare("SELECT nombre_fantasia, razon_social, cuit, condicion_iva, ingresos_brutos, logo_path, direccion, localidad, telefono FROM empresas WHERE id = ? LIMIT 1");
    $stmt_emp->execute([$empresa_id]);
    $emp_d = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    $stmt_suc = $pdo->query("SELECT email FROM sucursales WHERE es_principal = 1 LIMIT 1");
    $suc_d = $stmt_suc->fetch(PDO::FETCH_ASSOC);

    $nombreEmpresa  = !empty($emp_d['nombre_fantasia']) ? $emp_d['nombre_fantasia'] : 'Mi Negocio';
    $dirEmpresa     = !empty($emp_d['direccion'])
        ? $emp_d['direccion'] . (!empty($emp_d['localidad']) ? ' - ' . $emp_d['localidad'] : '')
        : 'Dirección no configurada';
    $telEmpresa     = !empty($emp_d['telefono']) ? $emp_d['telefono'] : '';
    $emailEmpresa   = !empty($suc_d['email']) ? $suc_d['email'] : '';
    $cuitEmpresa    = !empty($emp_d['cuit']) ? $emp_d['cuit'] : '';
    $condIva        = !empty($emp_d['condicion_iva']) ? $emp_d['condicion_iva'] : '';
    $logoEmpresa    = (!empty($emp_d['logo_path']) && file_exists('../' . $emp_d['logo_path'])) ? '../' . $emp_d['logo_path'] : null;
} catch (Exception $e) {
    $nombreEmpresa = 'Mi Negocio';
    $dirEmpresa = 'Dirección no configurada';
    $telEmpresa = $emailEmpresa = $cuitEmpresa = $condIva = '';
    $logoEmpresa = null;
}

// ---------- Datos del cliente y presupuesto ----------
$clienteNombre = trim($presu['apellido'] . ' ' . $presu['nombre']);
$clienteDoc    = !empty($presu['cuit']) ? $presu['cuit'] : '-';
$clienteDir    = !empty($presu['direccion']) ? $presu['direccion'] : '-';
$fechaEmision  = $presu['fecha_presupuesto'];
$fechaStr      = date('d/m/Y', strtotime($fechaEmision));
$totalPresu    = (float)$presu['total_presupuesto'];

// ---------- Clase de PDF personalizada (header/footer en todas las páginas) ----------
class PresupuestoPDF extends FPDF
{
    protected $empresa;
    protected $logo;
    protected $numDoc;
    protected $fechaStr;

    public function setDatos($empresa, $logo, $numDoc, $fechaStr)
    {
        $this->empresa  = $empresa;
        $this->logo     = $logo;
        $this->numDoc   = $numDoc;
        $this->fechaStr = $fechaStr;
    }

    public function Header()
    {
        $this->SetTextColor(0, 0, 0);
        $x = ($this->logo) ? 32 : 14;

        if ($this->logo) {
            $this->Image($this->logo, 12, 4, 17, 17);
        }

        // Empresa
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY($x, 4);
        $this->Cell(120, 7, to_iso($this->empresa['nombre']), 0, 1, 'L');

        $this->SetFont('Arial', '', 8.5);
        $this->SetXY($x, 12);
        $this->Cell(120, 4, to_iso($this->empresa['dir']), 0, 1, 'L');

        $contacto = trim(implode(' | ', array_filter([
            $this->empresa['tel'],
            $this->empresa['email'],
            $this->empresa['cuit'] ? 'CUIT ' . $this->empresa['cuit'] : ''
        ])));
        $this->SetXY($x, 17);
        $this->Cell(120, 4, to_iso($contacto), 0, 1, 'L');

        if ($this->empresa['condiva']) {
            $this->SetXY($x, 21);
            $this->Cell(120, 3, to_iso('Condición IVA: ' . $this->empresa['condiva']), 0, 1, 'L');
        }

        // Etiqueta del documento (derecha)
        $this->SetFont('Arial', 'B', 15);
        $this->SetXY(130, 5);
        $this->Cell(68, 7, to_iso('PRESUPUESTO'), 0, 1, 'R');

        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(130, 13);
        $this->Cell(68, 5, 'N. ' . str_pad((string)$this->numDoc, 6, '0', STR_PAD_LEFT), 0, 1, 'R');

        $this->SetFont('Arial', '', 8);
        $this->SetXY(130, 19);
        $this->Cell(68, 4, 'Emitido: ' . to_iso($this->fechaStr), 0, 1, 'R');

        // Línea de cierre del encabezado
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.6);
        $this->Line(14, 27, 196, 27);
        $this->SetLineWidth(0.2);

        $this->SetY(31);
    }

    public function Footer()
    {
        $this->SetY(-14);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
        $this->Line(12, $this->GetY(), 198, $this->GetY());

        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, to_iso($this->empresa['nombre']), 0, 0, 'L');
        $this->Cell(0, 4, to_iso('Página ' . $this->PageNo() . ' de {nb}  |  ' . $this->fechaStr), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    protected function countLines($w, $txt)
    {
        $cw   = $this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s    = str_replace("\r", '', (string)$txt);
        $nb   = strlen($s);
        $nl   = 1;
        $l    = 0;
        for ($i = 0; $i < $nb; $i++) {
            $l += $cw[$s[$i]];
            if ($l > $wmax) {
                $nl++;
                $l = $cw[$s[$i]];
            }
        }
        return $nl;
    }

    public function itemsHeader()
    {
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8.5);
        $w = [96, 22, 30, 34]; // total 182
        $titulos = ['Descripción', 'Cant.', 'Precio U.', 'Subtotal'];
        foreach ($titulos as $i => $t) {
            $this->Cell($w[$i], 7, to_iso($t), 1, 0, ($i === 0) ? 'L' : 'C', true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    public function itemRow($desc, $cant, $precio, $subtotal, $alt)
    {
        $w  = [96, 22, 30, 34];
        $lh = 4.6;
        $this->SetFont('Arial', '', 9);
        $txt = to_iso($desc);
        $h   = max(8, $this->countLines($w[0], $txt) * $lh + 2.2);

        if ($this->GetY() + $h > 272) {
            $this->AddPage();
            $this->itemsHeader();
        }

        $x0 = $this->GetX();
        $y0 = $this->GetY();

        $this->SetDrawColor(180, 180, 180);
        $this->SetFillColor(243, 243, 243);
        $this->Rect($x0, $y0, array_sum($w), $h, $alt ? 'DF' : 'D');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(40, 40, 40);
        $this->SetXY($x0, $y0 + 1);
        $this->MultiCell($w[0], $lh, $txt, 0, 'L');

        $this->SetXY($x0 + $w[0], $y0);
        $this->SetFont('Arial', '', 9);
        $this->Cell($w[1], $h, number_format((float)$cant, 2, ',', '.'), 0, 0, 'C');
        $this->Cell($w[2], $h, '$ ' . number_format((float)$precio, 2, ',', '.'), 0, 0, 'R');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w[3], $h, '$ ' . number_format((float)$subtotal, 2, ',', '.'), 0, 1, 'R');

        $this->SetTextColor(0, 0, 0);
    }
}

// ---------- Construcción del PDF ----------
$pdf = new PresupuestoPDF('P', 'mm', 'A4');
$pdf->setDatos([
    'nombre'  => $nombreEmpresa,
    'dir'     => $dirEmpresa,
    'tel'     => $telEmpresa,
    'email'   => $emailEmpresa,
    'cuit'    => $cuitEmpresa,
    'condiva' => $condIva,
], $logoEmpresa, $id, $fechaStr);
$pdf->SetMargins(14, 32, 14);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AliasNbPages();
$pdf->AddPage();

// ---------- Caja de datos del presupuesto ----------
$yBox = $pdf->GetY();
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect(14, $yBox, 90, 36, 'D');
$pdf->Rect(104, $yBox, 92, 36, 'D');

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetXY(16, $yBox + 3);
$pdf->Cell(86, 4, to_iso('DATOS DEL CLIENTE'), 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetXY(16, $yBox + 8);
$pdf->Cell(86, 5, to_iso($clienteNombre ?: 'Público en general'), 0, 1, 'L');

$pdf->SetFont('Arial', '', 8.5);
$pdf->SetXY(16, $yBox + 14);
$pdf->Cell(86, 4, to_iso('CUIT/DNI: ' . $clienteDoc), 0, 1, 'L');
$pdf->SetXY(16, $yBox + 19);
$pdf->Cell(86, 4, to_iso('Dirección: ' . $clienteDir), 0, 1, 'L');

// Panel derecho del presupuesto
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(106, $yBox + 3);
$pdf->Cell(88, 4, to_iso('DETALLE'), 0, 1, 'L');

$pdf->SetFont('Arial', '', 9);
$pdf->SetXY(106, $yBox + 8);
$pdf->Cell(88, 5, to_iso('Número de presupuesto: ' . $id), 0, 1, 'L');
$pdf->SetXY(106, $yBox + 14);
$pdf->Cell(88, 4, to_iso('Fecha de emisión: ' . $fechaStr), 0, 1, 'L');

$pdf->SetY($yBox + 45);

// ---------- Tabla de productos ----------
$pdf->itemsHeader();

$stmtDetalle = $pdo->prepare("SELECT * FROM presupuestos_detalle WHERE id_presupuesto = ? AND empresa_id = ?");
$stmtDetalle->execute([$id, $empresa_id]);

$n = 0;
$sumaItems = 0.0;
$alt = false;
while ($row = $stmtDetalle->fetch(PDO::FETCH_ASSOC)) {
    $n++;
    $cant  = (float)$row['cantidad'];
    $prec  = (float)$row['precio_unitario'];
    $sub   = (float)($row['subtotal'] ?? ($cant * $prec));
    $sumaItems += $sub;
    $pdf->itemRow($row['descripcion'], $cant, $prec, $sub, $alt);
    $alt = !$alt;
}

$pdf->Ln(4);

// ---------- Totales ----------
$xT = 104;
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($xT, $pdf->GetY(), 92, 8, 'D');
$pdf->SetXY($xT, $pdf->GetY() + 2);
$pdf->Cell(58, 4, 'Subtotal', 0, 0, 'L');
$pdf->Cell(34, 4, '', 0, 0, 'R');
$pdf->Cell(0, 4, '$ ' . number_format($sumaItems, 2, ',', '.'), 0, 1, 'R');

$pdf->Ln(1);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Rect($xT, $pdf->GetY(), 92, 10, 'F');
$pdf->SetXY($xT, $pdf->GetY() + 2);
$pdf->Cell(58, 6, to_iso('TOTAL PRESUPUESTO'), 0, 0, 'L');
$pdf->Cell(34, 6, '', 0, 0, 'R');
$pdf->Cell(0, 6, '$ ' . number_format($totalPresu, 2, ',', '.'), 0, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

// ---------- Observaciones ----------
if (!empty($presu['observaciones'])) {
    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, to_iso('OBSERVACIONES'), 0, 1, 'L');

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->MultiCell(0, 5, to_iso($presu['observaciones']), 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
}

// Nota inferior
$pdf->Ln(6);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell(0, 4, to_iso('Los precios están expresados en pesos argentinos.'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

$pdf->Output('I', 'presupuesto_' . $id . '.pdf');
exit;