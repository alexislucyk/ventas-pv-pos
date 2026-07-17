<?php
include '../pages/infosesion.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    exit("<p style='padding:20px; color:red;'>Falta empresa_id en sesión.</p>");
}

if (!tiene_permiso('prov_ver_stock')) {
    exit("<p style='padding:20px; color:red;'>Acceso denegado.</p>");
}

$proveedor = isset($_GET['proveedor']) ? trim($_GET['proveedor']) : '';

if (empty($proveedor)) {
    echo "<p style='padding:20px; color:orange;'>No se especificó un proveedor válido.</p>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT cod_prod, descripcion, p_compra, p_venta, stock, rubro 
                           FROM productos 
                           WHERE proveedor = ? AND empresa_id = ?
                           ORDER BY descripcion ASC");
    $stmt->execute([$proveedor, $empresa_id]);
    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($prods)) {
        echo "<p style='padding:20px; text-align:center;'>No hay productos vinculados a este proveedor en el sistema.</p>";
        exit;
    }

    echo '<table id="tablaCatalogo" class="table" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#333; color:#00bcd4;">
                    <th>Código</th>
                    <th>Descripción</th>
                    <th>Rubro</th>
                    <th style="text-align:right;">Costo ($)</th>
                    <th style="text-align:right;">Venta ($)</th>
                    <th style="text-align:center;">Stock</th>
                </tr>
            </thead>
            <tbody>';
    foreach ($prods as $p) {
        echo "<tr>
                <td><small>{$p['cod_prod']}</small></td>
                <td><strong>" . htmlspecialchars($p['descripcion']) . "</strong></td>
                <td><span style='font-size:0.8em; color:#aaa;'>{$p['rubro']}</span></td>
                <td style='text-align:right; color:#ff9800;'>$" . number_format($p['p_compra'], 2, ',', '.') . "</td>
                <td style='text-align:right; color:#2ecc71;'>$" . number_format($p['p_venta'], 2, ',', '.') . "</td>
                <td style='text-align:center;'>" . number_format($p['stock'], 0) . "</td>
              </tr>";
    }
    echo '</tbody></table>';
} catch (Exception $e) {
    echo "<p style='color:red;'>Error de base de datos: " . $e->getMessage() . "</p>";
}