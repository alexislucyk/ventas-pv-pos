<?php
include 'infosesion.php';
require '../config/db_config.php';

// Obtenemos la ganancia configurada para mostrar precios sugeridos si fuera necesario
$stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
$ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);

// Listas para filtros del PDF
$rubros_list = $pdo->query("SELECT nombre FROM rubros ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$proveedores_list = $pdo->prepare("SELECT DISTINCT TRIM(proveedor) as proveedor FROM productos WHERE empresa_id = :empresa_id AND proveedor IS NOT NULL AND TRIM(proveedor) != '' ORDER BY proveedor ASC");
$proveedores_list->execute([':empresa_id' => $_SESSION['empresa_id'] ?? 0]);
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
            content: "\f02a";
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

        #contenedor-resultados {
            min-height: 200px;
        }

        /* --- MODAL --- */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-box {
            background: #1e1e1e;
            border: 1px solid #444;
            border-radius: 10px;
            padding: 30px;
            width: 450px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .modal-box h2 {
            color: #00bcd4;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.3rem;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        .modal-box label {
            color: #aaa;
            display: block;
            margin-bottom: 5px;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        .modal-box label:first-of-type {
            margin-top: 0;
        }
        .modal-box select, .modal-box input {
            width: 100%;
            padding: 10px;
            background: #2a2a2a;
            border: 1px solid #444;
            color: #fff;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        .modal-box select:focus, .modal-box input:focus {
            border-color: #00bcd4;
            outline: none;
        }
        .modal-box .radio-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }
        .modal-box .radio-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #e0e0e0;
            margin: 0;
            cursor: pointer;
            font-size: 1rem;
        }
        .modal-box .radio-group input[type="radio"] {
            width: auto;
            accent-color: #00bcd4;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        .modal-actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-cancel {
            background: #444;
            color: #ccc;
        }
        .btn-cancel:hover {
            background: #555;
        }
        .btn-generate {
            background: #00bcd4;
            color: #fff;
        }
        .btn-generate:hover {
            background: #00acc1;
        }
        .filtro-condicional {
            display: none;
        }
        .filtro-condicional.active {
            display: block;
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
            <div style="display: flex; gap: 10px; align-items: stretch;">
                <div style="position: relative; flex: 1;">
                    <i class="fas fa-search" style="position: absolute; right: 15px; top: 15px; color: #555;"></i>
                    <input type="text" id="busqueda_precio" class="input-field" placeholder="Buscar por código o descripción..." autocomplete="off" autofocus>
                </div>
                <button id="btn_pdf_precios" class="btn btn-primary" style="padding: 12px 20px; white-space: nowrap; display: flex; align-items: center; gap: 8px; background: #00bcd4; border: none; border-radius: 4px; color: #fff; font-weight: bold; cursor: pointer; height: 100%; box-sizing: border-box;">
                    <i class="fas fa-file-pdf"></i> Listado PDF
                </button>
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

    <!-- MODAL PARA GENERAR PDF -->
    <div class="modal-overlay" id="modalPdf">
        <div class="modal-box">
            <h2><i class="fas fa-file-pdf" style="margin-right: 10px;"></i>Generar Listado de Precios</h2>
            
            <label>Seleccione el tipo de listado:</label>
            <div class="radio-group">
                <label>
                    <input type="radio" name="tipo_listado" value="todo" checked onchange="toggleFiltro()">
                    <i class="fas fa-list"></i> Listar todos los productos
                </label>
                <label>
                    <input type="radio" name="tipo_listado" value="busqueda" onchange="toggleFiltro()">
                    <i class="fas fa-search"></i> Según búsqueda actual
                </label>
                <label>
                    <input type="radio" name="tipo_listado" value="rubro" onchange="toggleFiltro()">
                    <i class="fas fa-tag"></i> Por Rubro / Categoría
                </label>
                <label>
                    <input type="radio" name="tipo_listado" value="proveedor" onchange="toggleFiltro()">
                    <i class="fas fa-truck"></i> Por Proveedor
                </label>
            </div>

            <div class="filtro-condicional" id="filtro_rubro">
                <label>Seleccione el Rubro:</label>
                <select id="select_rubro">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($rubros_list as $r): ?>
                        <option value="<?php echo htmlspecialchars($r['nombre']); ?>"><?php echo htmlspecialchars($r['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filtro-condicional" id="filtro_proveedor">
                <label>Seleccione el Proveedor:</label>
                <select id="select_proveedor">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($proveedores_list as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['proveedor']); ?>"><?php echo htmlspecialchars($p['proveedor']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-actions">
                <button class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
                <button class="btn-generate" onclick="generarPDF()"><i class="fas fa-file-pdf"></i> Generar PDF</button>
            </div>
        </div>
    </div>

    <script>
    const inputBusqueda = document.getElementById('busqueda_precio');
    const tbody = document.getElementById('tbody_resultados');

    // Búsqueda en vivo
    inputBusqueda.addEventListener('input', function() {
        const q = this.value.trim();

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

    // --- MODAL PDF ---
    const modal = document.getElementById('modalPdf');

    document.getElementById('btn_pdf_precios').addEventListener('click', function() {
        modal.classList.add('active');
    });

    function cerrarModal() {
        modal.classList.remove('active');
    }

    // Cerrar modal al hacer clic fuera
    modal.addEventListener('click', function(e) {
        if (e.target === modal) cerrarModal();
    });

    function toggleFiltro() {
        const seleccion = document.querySelector('input[name="tipo_listado"]:checked').value;
        document.getElementById('filtro_rubro').classList.toggle('active', seleccion === 'rubro');
        document.getElementById('filtro_proveedor').classList.toggle('active', seleccion === 'proveedor');
    }

    function generarPDF() {
        const seleccion = document.querySelector('input[name="tipo_listado"]:checked').value;
        let url = 'generar_pdf_lista_precios.php?tipo=' + seleccion;

        if (seleccion === 'rubro') {
            const rubro = document.getElementById('select_rubro').value;
            if (!rubro) {
                alert('Seleccione un rubro.');
                return;
            }
            url += '&valor=' + encodeURIComponent(rubro);
        } else if (seleccion === 'proveedor') {
            const proveedor = document.getElementById('select_proveedor').value;
            if (!proveedor) {
                alert('Seleccione un proveedor.');
                return;
            }
            url += '&valor=' + encodeURIComponent(proveedor);
        } else if (seleccion === 'busqueda') {
            const q = inputBusqueda.value.trim();
            if (q.length < 2) {
                alert('Ingrese al menos 2 caracteres en la búsqueda.');
                inputBusqueda.focus();
                cerrarModal();
                return;
            }
            url += '&q=' + encodeURIComponent(q);
        }

        window.open(url, '_blank');
        cerrarModal();
    }
    </script>
</body>
</html>