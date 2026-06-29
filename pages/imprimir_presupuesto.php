<?php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

require '../config/db_config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Presupuesto</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .search-container { position: relative; }
        .results-list { 
            position: absolute; z-index: 1000; width: 100%; 
            background: #444; color: white; border: 1px solid #666; 
            max-height: 200px; overflow-y: auto; display: none;
        }
        .results-list div { padding: 10px; cursor: pointer; border-bottom: 1px solid #555; }
        .results-list div:hover { background: #555; }
        .presupuesto-header { display: flex; gap: 20px; margin-bottom: 20px; }
        .cliente-info { flex: 1; background: #333; padding: 15px; border-radius: 8px; }
        .producto-search { flex: 2; background: #333; padding: 15px; border-radius: 8px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>📑 Nuevo Presupuesto</h1>

        <div class="presupuesto-header">
            <div class="cliente-info">
                <h3>👤 Cliente</h3>
                <div class="search-container">
                    <input type="text" id="buscarCliente" class="form-control" placeholder="Nombre o DNI del cliente...">
                    <input type="hidden" id="id_cliente_seleccionado">
                    <div id="listaClientes" class="results-list"></div>
                </div>
                <div id="datosCliente" style="margin-top:10px; font-size: 0.9em; color: #aaa;">
                    Sin cliente seleccionado
                </div>
            </div>

            <div class="producto-search">
                <h3>🔍 Agregar Productos</h3>
                <div class="search-container" style="display:flex; gap:10px;">
                    <input type="text" id="buscarProducto" class="form-control" placeholder="Código o nombre del producto...">
                    <button class="btn btn-primary" onclick="agregarProductoManual()">Agregar</button>
                    <div id="listaProductos" class="results-list"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <table id="tablaPresupuesto" style="width:100%;">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th style="width:100px;">Cant.</th>
                        <th>P. Unit</th>
                        <th>Subtotal</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="cuerpoPresupuesto">
                    </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <div class="observaciones" style="width: 50%;">
                <textarea id="obs" class="form-control" placeholder="Observaciones o validez del presupuesto (ej: Validez 15 días)"></textarea>
            </div>
            <div class="total-container" style="text-align: right;">
                <h2 style="margin:0;">TOTAL: <span id="totalPresupuesto">$0.00</span></h2>
                <button class="btn btn-success" onclick="guardarPresupuesto()" style="padding: 15px 30px; margin-top:10px;">
                    💾 Guardar y Generar PDF A4
                </button>
            </div>
        </div>
    </div>

    <script src="../js/presupuestos.js"></script>
</body>
</html>