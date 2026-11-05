<?php
/**
 * Archivo: /ajax/obtener_catalogo_proveedor.php
 * Descripción: Renderiza la tabla de catálogo externo del proveedor seleccionado.
 */

include '../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    exit("<p style='padding:20px; color:red;'>Falta empresa_id en sesión.</p>");
}

if (!tiene_permiso('prov_ver_catalogo')) {
    exit("<p style='padding:20px; color:red;'>Acceso denegado.</p>");
}

$cod_prov = isset($_GET['cod_prov']) ? trim($_GET['cod_prov']) : '';

if (empty($cod_prov)) {
    echo "<p style='padding:20px; color:orange;'>ID de proveedor no especificado.</p>";
    exit;
}

// Dólar operativo (venta * factor)
$dolar_operativo = 0;
$cache_file = dirname(__FILE__) . '/../cache/dolar_cache.json';
$dolar_margen = 2;
try {
    $stmt_m = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'dolar_margen' LIMIT 1");
    $stmt_m->execute();
    $m = $stmt_m->fetchColumn();
    if ($m !== false && $m !== null && $m !== '') $dolar_margen = floatval($m);
} catch (Exception $e) {}
$factor = 1 + ($dolar_margen / 100);
if (file_exists($cache_file)) {
    $cd = json_decode(file_get_contents($cache_file), true);
    if (is_array($cd) && isset($cd['venta']) && is_numeric($cd['venta'])) {
        $dolar_operativo = (float)$cd['venta'] * $factor;
    }
}

try {
    $stmt_p = $pdo->prepare("SELECT razon FROM proveedores WHERE cod_prov = ? AND empresa_id = ?");
    $stmt_p->execute([$cod_prov, $empresa_id]);
    $razon_prov = $stmt_p->fetchColumn() ?: 'GENERAL';

    $stmt = $pdo->prepare("SELECT codigo, descripcion, precio 
                           FROM proveedores_catalogos 
                           WHERE cod_prov = ? 
                           ORDER BY descripcion ASC");
    $stmt->execute([$cod_prov]);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($productos)) {
        echo "<div style='padding:40px; text-align:center; color:#888;'>
                <i class='fas fa-info-circle' style='font-size:2em;'></i><br><br>
                No hay un catálogo externo cargado para este proveedor.<br>
                Use el botón 'Importar' para subir un archivo CSV.
              </div>";
        exit;
    }

    echo '<div id="catalogoMeta" data-dolar="' . htmlspecialchars((string)$dolar_operativo) . '" style="display:none;"></div>';

    echo '<table id="tablaCatalogo" class="table" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#222; color:#a29bfe; position: sticky; top: 0;">
                    <th style="width:15%;">Código</th>
                    <th style="width:40%;">Descripción</th>
                    <th style="width:15%; text-align:right;">Precio Lista (USD)</th>
                    <th style="width:15%; text-align:right;">En Pesos (ARS)</th>
                    <th style="width:5%;"></th>
                </tr>
            </thead>
            <tbody>';
    foreach ($productos as $p) {
        $js_cod = json_encode($p['codigo']);
        $js_desc = json_encode($p['descripcion']);
        $js_precio = (float)$p['precio'];
        $js_prov = json_encode($razon_prov);

        echo "<tr data-codigo=\"" . htmlspecialchars($p['codigo']) . "\" data-desc=\"" . htmlspecialchars($p['descripcion']) . "\" data-precio=\"" . $js_precio . "\" data-prov=\"" . htmlspecialchars($razon_prov) . "\">
                <td><code style='color:#00bcd4;'>" . htmlspecialchars($p['codigo'] ?? '') . "</code></td>
                <td style='font-size: 0.9em;'>" . htmlspecialchars($p['descripcion'] ?? '') . "</td>
                <td style='text-align:right; font-weight:bold; color:#f1c40f;'>$" . number_format((float)($p['precio'] ?? 0), 2, ',', '.') . "</td>
                <td class='col-ars' style='text-align:right; color:#2ecc71; font-weight:bold;'>-</td>
                <td style='text-align:center;'>
                    <button class='btn btn-success btn-sm' title='Añadir a mi stock' 
                        onclick='abrirModalCopiaCatalogo(this)'>
                        <i class='fas fa-plus'></i>
                    </button>
                </td>
              </tr>";
    }
    echo '</tbody></table>';
} catch (Exception $e) {
    echo "<p style='color:red; padding:20px;'>Error al cargar el catálogo: " . $e->getMessage() . "</p>";
}
