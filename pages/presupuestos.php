<?php
// pages/presupuestos.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Presupuesto | Sistema de Gestión</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Estilos específicos para buscadores y tabla */
        .search-wrapper { position: relative; width: 100%; }
        
        .results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #2d2d2d;
            border: 1px solid #444;
            border-top: none;
            z-index: 1000;
            max-height: 250px;
            overflow-y: auto;
            display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
        }

        .results-dropdown div {
            padding: 12px;
            border-bottom: 1px solid #3d3d3d;
            cursor: pointer;
            transition: background 0.2s;
        }

        .results-dropdown div:hover {
            background: #3d3d3d;
            color: #00d1b2;
        }

        .header-presupuesto {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel-busqueda {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }

        .table-container {
            background: #252525;
            padding: 10px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .total-box {
            background: #34495e;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: right;
            margin-top: 20px;
        }

        #totalPresupuesto {
            font-size: 2.5rem;
            display: block;
            font-weight: bold;
            color: #2ecc71;
        }

        .form-control-custom {
            width: 100%;
            padding: 10px;
            background: #1a1a1a;
            border: 1px solid #444;
            color: white;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <!-- <h1 class="main-title">📑 Crear Nuevo Presupuesto</h1> -->
         <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 class="main-title" style="margin: 0;">📑 Crear Nuevo Presupuesto</h1>
            <a href="consultar_presupuestos.php" class="btn-secondary" style="text-decoration: none; padding: 10px 20px; background: #34495e; color: white; border-radius: 5px; font-weight: bold; border: 1px solid #444;">
                <i class="fas fa-search-dollar"></i> Consultar Presupuestos Emitidos
            </a>
        </div>

        <div class="header-presupuesto">
            <div class="panel-busqueda">
                <h3>👤 Datos del Cliente</h3>
                <div class="search-wrapper">
                    <input type="text" id="buscarCliente" class="form-control-custom" placeholder="Buscar por nombre o CUIT..." autocomplete="off">
                    <input type="hidden" id="id_cliente_seleccionado">
                    <div id="listaClientes" class="results-dropdown"></div>
                </div>
                <div id="datosCliente" style="margin-top:15px; min-height: 40px; border-top: 1px solid #444; padding-top:10px;">
                    <span style="color: #777;">Seleccione un cliente para el presupuesto</span>
                </div>
            </div>

            <div class="panel-busqueda" style="border-left-color: #2ecc71;">
                <h3>🔍 Buscar Productos</h3>
                <div class="search-wrapper">
                    <input type="text" id="buscarProducto" class="form-control-custom" placeholder="Código o nombre del artículo..." autocomplete="off">
                    <div id="listaProductos" class="results-dropdown"></div>
                </div>
                <p style="font-size: 0.8rem; color: #888; margin-top: 10px;">Los precios y descripciones pueden editarse en la tabla.</p>
            </div>
        </div>

        <div class="table-container">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1a1a1a; text-align: left;">
                        <th style="padding: 12px;">Código</th>
                        <th>Descripción</th>
                        <th style="width: 100px;">Cantidad</th>
                        <th style="width: 150px;">Precio Unit.</th>
                        <th style="width: 150px;">Subtotal</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody id="cuerpoPresupuesto">
                    </tbody>
            </table>
        </div>

        <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 30px; margin-top: 30px;">
            <div>
                <label>📌 Observaciones del Presupuesto:</label>
                <textarea id="comentarios" class="form-control-custom" rows="4" placeholder="Ej: Validez del presupuesto 7 días. Precios sujetos a cambios sin previo aviso."></textarea>
            </div>
            
            <div class="total-box">
                <span>TOTAL ESTIMADO</span>
                <span id="totalPresupuesto">$ 0.00</span>
                <button class="btn-success" onclick="guardarPresupuesto()" style="width: 100%; padding: 15px; font-size: 1.2rem; margin-top: 15px; cursor: pointer; border: none; border-radius: 5px;">
                    💾 Guardar y Generar PDF
                </button>
            </div>
        </div>
    </div>

    <script src="../js/presupuestos.js"></script>
</body>
</html>