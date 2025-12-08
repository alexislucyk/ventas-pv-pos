<?php
session_start();
// 1. Verificar Sesión de Seguridad
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

require '../config/db_config.php'; 

$mensaje = '';
$productos = array();

try {
    // ----------------------
    // LISTAR PRODUCTOS PARA CONSULTA
    // ----------------------
    // Obtenemos solo los campos necesarios para la consulta rápida
    $stmt = $pdo->query('SELECT cod_prod, descripcion, p_venta, stock FROM productos ORDER BY cod_prod ASC');
    $productos = $stmt->fetchAll();

} catch (Exception $e) {
    $mensaje = "❌ Error al cargar los productos: " . $e->getMessage();
}

## Vista (HTML y Diseño) ##
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Precios | Mi Negocio POS</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Estilos específicos para esta consulta (opcional) */
        .tabla-consulta td, .tabla-consulta th {
            padding: 10px;
            font-size: 1.1em;
        }
        .stock-bajo {
            color: #ff5757; /* Rojo para stock bajo */
            font-weight: bold;
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?> 
    <?php include 'infosesion.php'; ?> 

    <div class="content">
        <h1>Consulta Rápida de Precios y Stock</h1>
        
        <?php if ($mensaje): ?>
            <div class="alert alert-error">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card">   
            
            <input type="text" id="filtroConsulta" class="input-field" placeholder="🔍 Escriba Código o Descripción para buscar..." style="margin-bottom: 20px;">
            
            <?php if (empty($productos)): ?>
                <p style="margin-top: 20px;">No hay productos cargados en el sistema.</p>
            <?php else: ?>
                <table id="tablaConsulta" class="tabla-consulta">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Código</th>
                            <th>Descripción</th>
                            <th style="width: 15%; text-align: right;">P. Venta</th>
                            <th style="width: 10%; text-align: right;">Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $p): 
                            // Opcional: Resaltar stock bajo (ejemplo: stock < 5)
                            $clase_stock = ($p['stock'] < 5) ? 'stock-bajo' : '';
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['cod_prod']); ?></td>
                                <td><?php echo htmlspecialchars($p['descripcion']); ?></td>
                                <td style="text-align: right;">$<?php echo number_format($p['p_venta'], 2, ',', '.'); ?></td>
                                <td style="text-align: right;" class="<?php echo $clase_stock; ?>">
                                    <?php echo number_format($p['stock'], 2, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputFiltro = document.getElementById('filtroConsulta');
        const tabla = document.getElementById('tablaConsulta');
        const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (inputFiltro) {
            inputFiltro.addEventListener('keyup', function() {
                const filtro = inputFiltro.value.toUpperCase(); 

                for (let i = 0; i < filas.length; i++) {
                    let fila = filas[i];
                    // Celdas a buscar: Código (0) y Descripción (1)
                    let celdaCodigo = fila.getElementsByTagName('td')[0];
                    let celdaDescripcion = fila.getElementsByTagName('td')[1];
                    let textoFila = '';

                    // Concatena el texto de las celdas para buscar en ambas
                    if (celdaCodigo) {
                        textoFila += (celdaCodigo.textContent || celdaCodigo.innerText);
                    }
                    if (celdaDescripcion) {
                        textoFila += ' ' + (celdaDescripcion.textContent || celdaDescripcion.innerText);
                    }

                    if (textoFila.toUpperCase().indexOf(filtro) > -1) {
                        fila.style.display = "";
                    } else {
                        fila.style.display = "none";
                    }
                }
            });
        }
    });
</script>
</html>