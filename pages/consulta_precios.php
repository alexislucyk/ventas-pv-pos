<?php
include 'infosesion.php';
require '../config/db_config.php';

// Obtenemos la ganancia configurada para mostrar precios sugeridos si fuera necesario
$stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
$ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Precios | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* TEMA OSCURO REPORTES (Sincronizado con reportes_inventario.php y ventas.php) */
        body {
            background-color: #121212;
            color: #e0e0e0;
        }

        .content h1 {
            color: #00bcd4;
            font-weight: 700;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
        }
        
        .content h1::before {
            content: "\f02a"; /* Icono de código de barras */
            font-family: "Font Awesome 5 Free";
            margin-right: 15px;
            font-size: 1.5rem;
        }

        .card {
            background-color: #1e1e1e !important;
            border: 1px solid #333 !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3) !important;
            padding: 25px;
            margin-bottom: 20px;
        }

        .input-field {
            background-color: #2a2a2a !important;
            border: 1px solid #444 !important;
            color: #fff !important;
            border-radius: 4px;
            padding: 12px;
            font-size: 1.1rem;
            width: 100%;
            box-sizing: border-box;
        }

        .input-field:focus {
            border-color: #00bcd4 !important;
            outline: none;
            box-shadow: 0 0 8px rgba(0, 188, 212, 0.3);
        }

        .table-full {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table-full th {
            background-color: #181818;
            color: #00bcd4;
            text-transform: uppercase;
            font-size: 0.85rem;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #333;
        }

        .table-full td {
            padding: 15px;
            border-bottom: 1px solid #222;
            font-size: 1rem;
        }

        .table-full tr:hover {
            background-color: #252525;
        }

        .precio-destacado {
            color: #4caf50;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .stock-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        .stock-ok { background: rgba(76, 175, 80, 0.2); color: #4caf50; }
        .stock-low { background: rgba(244, 67, 54, 0.2); color: #f44336; }

        /* Estilo para los resultados del buscador */
        #contenedor-resultados {
            min-height: 200px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Consulta de Precios</h1>

        <div class="card">
            <label style="color: #aaa; margin-bottom: 10px; display: block;">Escanee el código o escriba el nombre del producto:</label>
            <div style="position: relative;">
                <i class="fas fa-search" style="position: absolute; right: 15px; top: 15px; color: #555;"></i>
                <input type="text" id="busqueda_precio" class="input-field" placeholder="Buscar por código o descripción..." autocomplete="off" autofocus>
            </div>
        </div>

        <div class="card">
            <div id="contenedor-resultados">
                <table class="table-full" id="tabla_precios">
                    <thead>
                        <tr>
                            <th>Cód. Barra</th>
                            <th>Descripción</th>
                            <th>Rubro</th>
                            <th style="text-align: right;">Precio Venta</th>
                            <th style="text-align: center;">Stock</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_resultados">
                        <tr>
                            <td colspan="5" style="text-align: center; color: #666; padding: 40px;">
                                <i class="fas fa-barcode" style="font-size: 3rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                Ingrese un criterio de búsqueda para ver los precios.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('busqueda_precio').addEventListener('input', function() {
        const q = this.value.trim();
        const tbody = document.getElementById('tbody_resultados');

        if (q.length < 2) {
            return;
        }

        fetch('buscar_producto_ajax.php?q=' + encodeURIComponent(q))
            .then(res => res.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 20px;">No se encontraron productos.</td></tr>';
                    return;
                }

                data.forEach(prod => {
                    const tr = document.createElement('tr');
                    const stockClass = prod.stock > 0 ? 'stock-ok' : 'stock-low';
                    
                    tr.innerHTML = `
                        <td><strong>${prod.cod_prod}</strong></td>
                        <td>${prod.descripcion}</td>
                        <td><span style="color: #aaa; font-size: 0.9rem;">${prod.rubro || 'VARIOS'}</span></td>
                        <td style="text-align: right;" class="precio-destacado">
                            $ ${parseFloat(prod.p_venta).toLocaleString('es-AR', {minimumFractionDigits: 2})}
                        </td>
                        <td style="text-align: center;">
                            <span class="stock-badge ${stockClass}">${prod.stock}</span>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(err => console.error("Error consultando precios:", err));
    });
    </script>
</body>
</html>