<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$cod_prov = isset($_GET['cod_prov']) ? $_GET['cod_prov'] : null;
$mensaje = '';
$proveedor_editar = array(); 

try {
    $rubros_list = $pdo->query("SELECT nombre FROM rubros ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $stmt_conf = $pdo->query("SELECT valor FROM configuracion WHERE clave = 'ganancia_global'");
    $ganancia_config = (float)($stmt_conf->fetchColumn() ?: 60);
} catch (Exception $e) {
    $rubros_list = [];
    $ganancia_config = 60;
}

$nuevo_codigo_sugerido = '';
if ($accion === 'crear') {
    try {
        $stmt_cod = $pdo->prepare("SELECT cod_prov FROM proveedores WHERE empresa_id = :empresa_id ORDER BY (cod_prov + 0) DESC LIMIT 1");
        $stmt_cod->execute([':empresa_id' => $empresa_id]);
        $ultimo = $stmt_cod->fetch();
        
        if ($ultimo) {
            $nuevo_codigo_sugerido = intval($ultimo['cod_prov']) + 1;
        } else {
            $nuevo_codigo_sugerido = 1;
        }
    } catch (Exception $e) {
        $nuevo_codigo_sugerido = '';
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $cod_prov_post = trim($_POST['cod_prov']); 
        $razon = trim($_POST['razon']);
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        
        $accion_post = isset($_POST['accion_post']) ? $_POST['accion_post'] : '';
        $cod_prov_original = isset($_POST['cod_prov_original']) ? $_POST['cod_prov_original'] : $cod_prov_post;

        if (empty($cod_prov_post) || empty($razon)) {
            throw new Exception("El Código y la Razón Social son obligatorios.");
        }

        if ($accion_post === 'crear') {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM proveedores WHERE empresa_id = ? AND cod_prov = ?");
            $stmt_check->execute([$empresa_id, $cod_prov_post]);
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Ya existe un proveedor con ese código en esta empresa.");
            }

            if (!empty($cuit)) {
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM proveedores WHERE empresa_id = ? AND cuit = ?");
                $stmt_check->execute([$empresa_id, $cuit]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("Ya existe un proveedor con ese CUIT en esta empresa.");
                }
            }
            
            $sql = "INSERT INTO proveedores (cod_prov, razon, cuit, telefono, empresa_id) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono, $empresa_id));
            $mensaje = "✅ Proveedor registrado con éxito.";
            $accion = 'listar'; 
        } elseif ($accion_post === 'editar' && $cod_prov_original) {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM proveedores WHERE empresa_id = ? AND cod_prov = ? AND cod_prov != ?");
            $stmt_check->execute([$empresa_id, $cod_prov_post, $cod_prov_original]);
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Ya existe otro proveedor con ese código en esta empresa.");
            }

            if (!empty($cuit)) {
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM proveedores WHERE empresa_id = ? AND cuit = ? AND cod_prov != ?");
                $stmt_check->execute([$empresa_id, $cuit, $cod_prov_original]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("Ya existe otro proveedor con ese CUIT en esta empresa.");
                }
            }
            
            $sql = "UPDATE proveedores SET cod_prov = ?, razon = ?, cuit = ?, telefono = ? WHERE cod_prov = ? AND empresa_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($cod_prov_post, $razon, $cuit, $telefono, $cod_prov_original, $empresa_id));
            $mensaje = "✅ Proveedor actualizado con éxito.";
            $accion = 'listar'; 
        }
    }

    if ($accion === 'eliminar' && $cod_prov) {
        $stmt = $pdo->prepare('DELETE FROM proveedores WHERE cod_prov = ? AND empresa_id = ?');
        $stmt->execute(array($cod_prov, $empresa_id));
        $mensaje = "🗑️ Proveedor eliminado.";
        $accion = 'listar';
    }

    if ($accion === 'editar' && $cod_prov) {
        $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE cod_prov = ? AND empresa_id = ?');
        $stmt->execute(array($cod_prov, $empresa_id));
        $proveedor_editar = $stmt->fetch();
    }

    $proveedores = array();
    if ($accion === 'listar') {
        $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE empresa_id = :empresa_id ORDER BY (cod_prov + 0) ASC, razon ASC');
        $stmt->execute([':empresa_id' => $empresa_id]);
        $proveedores = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $mensaje = "❌ Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proveedores | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <style>
        .flex-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 5px; color: #3498db; font-weight: bold; font-size: 0.9em; }
        input { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; }
        .input-auto { border-color: #27ae60 !important; color: #2ecc71 !important; font-weight: bold; }
        #filtro-proveedores { width: 100%; max-width: 450px; margin-bottom: 20px; background: #1a1a1a; border: 1px solid #333; height: 40px; color: white; padding-left: 10px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>🚚 Gestión de Proveedores</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="abm_proveedores.php?accion=crear" class="btn btn-success">+ Nuevo Proveedor</a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <div class="card">
                <input type="text" id="filtro-proveedores" placeholder="🔍 Buscar proveedor...">
                <table id="tablaProveedores">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Razón Social</th>
                            <th>CUIT</th>
                            <th>Teléfono</th>
                            <th>Lista</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proveedores as $p): ?>
                        <tr>
                            <td><span style="color: #3498db; font-weight: bold;"><?php echo htmlspecialchars($p['cod_prov']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($p['razon']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['cuit'] ? $p['cuit'] : '---'); ?></td>
                            <td><?php echo htmlspecialchars($p['telefono'] ? $p['telefono'] : '---'); ?></td>
                            <td>
                                <?php if (tiene_permiso('prov_ver_stock')): ?>
                                    <button class="btn btn-yellow btn-sm" onclick="verListaPrecios('<?php echo addslashes($p['razon']); ?>')" title="Productos en mi stock">Mi Stock</button>
                                <?php endif; ?>
                                <?php if (tiene_permiso('prov_ver_catalogo')): ?>
                                    <button class="btn btn-primary btn-sm" style="background-color: #6f42c1;" onclick="verCatalogoExterno('<?php echo $p['cod_prov']; ?>', '<?php echo addslashes($p['razon']); ?>')" title="Lista completa del proveedor">Catálogo</button>
                                <?php endif; ?>
                                <?php if (tiene_permiso('prov_importar_catalogo')): ?>
                                    <button class="btn btn-success btn-sm" onclick="abrirImportar('<?php echo $p['cod_prov']; ?>', '<?php echo addslashes($p['razon']); ?>')" title="Importar lista CSV">Importar</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="abm_proveedores.php?accion=editar&cod_prov=<?php echo $p['cod_prov']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                <a href="abm_proveedores.php?accion=eliminar&cod_prov=<?php echo $p['cod_prov']; ?>" 
                                   class="btn btn-danger btn-sm" 
                                   onclick="event.preventDefault(); const url=this.href; confirmarAccion('Eliminar Proveedor', '¿Estás seguro de eliminar este proveedor y sus listas de precios?', 'ELIMINAR', 'btn-danger', () => window.location.href=url);">
                                   Borrar
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 800px; margin: 0 auto;">
                <h2><?php echo ($accion === 'crear') ? 'Nuevo Proveedor' : 'Modificar Proveedor'; ?></h2>
                <form method="POST">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="cod_prov_original" value="<?php echo isset($proveedor_editar['cod_prov']) ? htmlspecialchars($proveedor_editar['cod_prov']) : ''; ?>">

                    <div class="flex-row">
                        <div style="flex: 0.5;">
                            <label>Código*</label>
                            <input type="text" name="cod_prov" required 
                                class="<?php echo ($accion === 'crear') ? 'input-auto' : ''; ?>"
                                value="<?php 
                                    if($accion === 'editar') {
                                        echo htmlspecialchars($proveedor_editar['cod_prov']);
                                    } else {
                                        echo htmlspecialchars($nuevo_codigo_sugerido);
                                    }
                                ?>">
                            <?php if($accion === 'crear'): ?>
                                <small style="color: #27ae60;">Sugerido automáticamente</small>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1.5;">
                            <label>Razón Social*</label>
                            <input type="text" name="razon" required value="<?php echo isset($proveedor_editar['razon']) ? htmlspecialchars($proveedor_editar['razon']) : ''; ?>">
                        </div>
                    </div>

                    <div class="flex-row">
                        <div><label>CUIT</label><input type="text" name="cuit" value="<?php echo isset($proveedor_editar['cuit']) ? htmlspecialchars($proveedor_editar['cuit']) : ''; ?>"></div>
                        <div><label>Teléfono</label><input type="text" name="telefono" value="<?php echo isset($proveedor_editar['telefono']) ? htmlspecialchars($proveedor_editar['telefono']) : ''; ?>"></div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2;">💾 Guardar</button>
                        <a href="abm_proveedores.php" class="btn btn-secondary" style="flex: 1; text-align: center;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var inputF = document.getElementById('filtro-proveedores');
        if(inputF) {
            inputF.addEventListener('keyup', function() {
                var v = this.value.toUpperCase();
                var filas = document.querySelectorAll('#tablaProveedores tbody tr');
                for(var i=0; i<filas.length; i++) {
                    filas[i].style.display = (filas[i].innerText.toUpperCase().indexOf(v) > -1) ? "" : "none";
                }
            });
        }
    });
    </script>

    <!-- Modal para ver Lista de Precios del Proveedor -->
    <div id="modalPreciosProveedor" class="modal">
        <div class="modal-content" style="max-width: 85%; border-top: 4px solid #ff9800;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #ff9800;"><i class="fas fa-list"></i> Lista de Precios: <span id="nombreProvModal"></span></h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalPrecios()">&times;</span>
            </div>
            
            <div style="margin-bottom: 15px;">
                <input type="text" id="filtroCatalogo" class="input-field" placeholder="🔍 Buscar por código o descripción..." style="width: 100%;">
            </div>

            <div id="contenedorTablaPrecios" style="max-height: 60vh; overflow-y: auto;">
                <!-- Aquí se carga la tabla vía AJAX -->
                <p style="text-align:center;">Cargando lista de productos...</p>
            </div>

            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalPrecios()">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
    function verListaPrecios(razonSocial) {
        const modal = document.getElementById('modalPreciosProveedor');
        const contenedor = document.getElementById('contenedorTablaPrecios');
        const titulo = document.getElementById('nombreProvModal');

        titulo.innerText = razonSocial;
        modal.style.display = 'block';
        contenedor.innerHTML = '<p style="text-align:center; padding: 20px;">Buscando productos vinculados...</p>';

        fetch('../ajax/obtener_precios_proveedor.php?proveedor=' + encodeURIComponent(razonSocial))
            .then(res => res.text())
            .then(html => {
                contenedor.innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                contenedor.innerHTML = '<p style="color:red; text-align:center;">Error al conectar con el servidor.</p>';
            })
            .finally(() => {
                // Una vez cargada la tabla, activamos el filtro
                const filtroInput = document.getElementById('filtroCatalogo');
                filtroInput.value = ''; // Limpiar filtro al cargar nuevo catálogo
                filtroInput.focus();
                filtroInput.onkeyup = function() {
                    const filtro = this.value.toUpperCase();
                    const tabla = document.getElementById('tablaCatalogo');
                    if (!tabla) return;
                    const filas = tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    for (let i = 0; i < filas.length; i++) {
                        const celdaCodigo = filas[i].getElementsByTagName('td')[0];
                        const celdaDescripcion = filas[i].getElementsByTagName('td')[1];
                        const textoFila = (celdaCodigo ? celdaCodigo.textContent : '') + ' ' + (celdaDescripcion ? celdaDescripcion.textContent : '');
                        filas[i].style.display = textoFila.toUpperCase().indexOf(filtro) > -1 ? '' : 'none';
                    }
                };
            });
    }

    function verCatalogoExterno(codProv, razonSocial) {
        const modal = document.getElementById('modalPreciosProveedor');
        const contenedor = document.getElementById('contenedorTablaPrecios');
        const titulo = document.getElementById('nombreProvModal');

        titulo.innerText = razonSocial + " (Catálogo Externo)";
        modal.style.display = 'block';
        contenedor.innerHTML = '<p style="text-align:center; padding: 20px;">Consultando catálogo externo del proveedor...</p>';

        fetch('../ajax/obtener_catalogo_proveedor.php?cod_prov=' + encodeURIComponent(codProv))
            .then(res => res.text())
            .then(html => {
                contenedor.innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                contenedor.innerHTML = '<p style="color:red; text-align:center;">Error al conectar con el servidor.</p>';
            })
            .finally(() => {
                // ACTIVAR BUSCADOR PARA CATÁLOGO
                const filtroInput = document.getElementById('filtroCatalogo');
                filtroInput.value = ''; 
                filtroInput.focus();
                filtroInput.onkeyup = function() {
                    const filtro = this.value.toUpperCase();
                    const tabla = document.getElementById('tablaCatalogo');
                    if (!tabla) return;
                    const filas = tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    for (let i = 0; i < filas.length; i++) {
                        const celdaCodigo = filas[i].getElementsByTagName('td')[0];
                        const celdaDescripcion = filas[i].getElementsByTagName('td')[1];
                        const textoFila = (celdaCodigo ? celdaCodigo.textContent : '') + ' ' + (celdaDescripcion ? celdaDescripcion.textContent : '');
                        filas[i].style.display = textoFila.toUpperCase().indexOf(filtro) > -1 ? '' : 'none';
                    }
                };
            });
    }

    function cerrarModalPrecios() {
        document.getElementById('modalPreciosProveedor').style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('modalPreciosProveedor');
        if (event.target == modal) modal.style.display = 'none';
    }

    // --- LÓGICA DE IMPORTACIÓN DE CATÁLOGO ---
    document.addEventListener('DOMContentLoaded', function() {
    function abrirImportar(codProv, razon) {
        document.getElementById('imp_cod_prov').value = codProv;
        document.getElementById('imp_nombre_prov').innerText = razon;
        document.getElementById('modalImportar').style.display = 'block';
    }
        window.abrirImportar = abrirImportar; // Hacerla global para el onclick

        document.getElementById('formImportar')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const prog = document.getElementById('importProgressBar');
            const msg = document.getElementById('importMessageArea');

            btn.disabled = true;
            btn.innerText = 'Procesando 10.000 registros...';
            prog.style.display = 'block';
            msg.style.display = 'none';

            fetch('../ajax/importar_catalogo_csv.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(response => {
                // Si el status no es 200, es un error de servidor (500, 404, etc)
                if (!response.ok) {
                    return response.text().then(t => { throw new Error(t || 'Error 500: Fallo en el servidor'); });
                }
                return response.text(); // Leemos como texto primero por si hay basura antes del JSON
            })
            .then(text => {
                try {
                    const data = JSON.parse(text.substring(text.indexOf('{'))); // Buscamos donde empieza el JSON
                    prog.style.display = 'none';
                    msg.style.display = 'block';

                    if (data.success) {
                        msg.style.background = "rgba(46, 204, 113, 0.2)";
                        msg.style.border = "1px solid #2ecc71";
                        msg.style.color = "#2ecc71";
                        msg.style.padding = "15px";
                        msg.innerHTML = "<b>✅ ¡Importación Exitosa!</b><br>" + data.message;
                        this.querySelector('input[type="file"]').value = "";
                    } else {
                        throw new Error(data.message);
                    }
                } catch (e) {
                    throw new Error("Respuesta inválida del servidor: " + text.substring(0, 100));
                }
            })
            .catch(error => {
                prog.style.display = 'none';
                msg.style.display = 'block';
                msg.style.background = "rgba(231, 76, 60, 0.2)";
                msg.style.border = "1px solid #e74c3c";
                msg.style.color = "#e74c3c";
                msg.style.padding = "15px";
                msg.innerHTML = "<b>❌ Error:</b><br>" + error.message;
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerText = 'Iniciar Importación';
            });
        });
    });

    // --- LÓGICA DE COPIA DE CATÁLOGO A STOCK REAL ---
    window.abrirModalCopia = function(codigo, descripcion, precio, razonProv) {
        document.getElementById('cp_cod_prod').value = codigo;
        document.getElementById('cp_descripcion').value = descripcion;
        document.getElementById('cp_p_compra').value = precio;
        document.getElementById('cp_proveedor').value = razonProv;
        
        // Calcular precio de venta sugerido inmediatamente
        const ganancia = <?php echo $ganancia_config; ?>;
        const sugerido = (precio * (1 + (ganancia / 100))).toFixed(2);
        document.getElementById('cp_p_venta').value = sugerido;

        document.getElementById('modalCopiarProducto').style.display = 'block';
    };

    window.cerrarModalCopia = function() {
        document.getElementById('modalCopiarProducto').style.display = 'none';
    };

    window.guardarProductoCopiado = function() {
        const btn = event.target;
        btn.disabled = true;
        btn.innerText = "Guardando...";

        const formData = new FormData();
        formData.append('cod_prod', document.getElementById('cp_cod_prod').value);
        formData.append('descripcion', document.getElementById('cp_descripcion').value);
        formData.append('p_compra', document.getElementById('cp_p_compra').value);
        formData.append('p_venta', document.getElementById('cp_p_venta').value);
        formData.append('rubro', document.getElementById('cp_rubro').value);
        formData.append('proveedor', document.getElementById('cp_proveedor').value);
        formData.append('stock', 0); // Empieza con stock 0 por defecto

        fetch('../ajax/agregar_producto_rapido.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarMensaje("Carga Exitosa", "✅ Producto '" + data.producto.descripcion + "' agregado a tu stock.", "success", () => {
                    cerrarModalCopia();
                });
            } else {
                mostrarMensaje("Error de Carga", "❌ " + data.error, "error");
            }
        })
        .catch(err => {
            console.error(err);
            mostrarMensaje("Error Técnico", "No se pudo conectar con el servidor.", "error");
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerText = "GUARDAR EN MI STOCK";
        });
    };

    function cerrarImportar() {
        document.getElementById('modalImportar').style.display = 'none';
        document.getElementById('formImportar').reset();
    }
    </script>

    <!-- Modal Importar Catálogo CSV -->
    <div id="modalImportar" class="modal">
        <div class="modal-content" style="max-width: 500px; border-top: 4px solid #27ae60;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #2ecc71;"><i class="fas fa-file-import"></i> Importar Catálogo: <span id="imp_nombre_prov"></span></h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarImportar()">&times;</span>
            </div>
            
            <form id="formImportar" enctype="multipart/form-data">
                <input type="hidden" name="cod_prov" id="imp_cod_prov">
                
                <div style="background: #333; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                    <p style="font-size: 0.9em; margin-top: 0;"><strong>Formato del archivo (.csv):</strong></p>
                    <code style="color: #00bcd4;">codigo ; descripcion ; precio</code> (sin encabezados)
                    <p style="font-size: 0.8em; color: #aaa; margin-bottom: 0;">(Use punto y coma como separador de campos. Para los decimales en el precio, puede usar coma o punto.)</p>
                </div>

                <label>Seleccionar Archivo CSV:</label>
                <input type="file" name="archivo_csv" accept=".csv" required style="margin-top: 10px;">

                <div id="importProgressBar" class="progress-bar-container" style="display: none; margin-top: 20px;">
                    <div class="progress-bar-indeterminate"></div>
                    <p style="text-align: center; margin-top: 10px; color: #00bcd4;">Procesando archivo...</p>
                </div>
                <div id="importMessageArea" style="margin-top: 20px; padding: 10px; border-radius: 5px; text-align: center; display: none;"></div>

                <div style="margin-top: 25px; display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-success" style="flex: 2;">Iniciar Importación</button>
                    <button type="button" class="btn btn-secondary" onclick="cerrarImportar()" style="flex: 1;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Confirmar Alta desde Catálogo -->
    <div id="modalCopiarProducto" class="modal">
        <div class="modal-content" style="max-width: 450px; border-top: 4px solid #00bcd4;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h3 style="margin: 0; color: #00bcd4;"><i class="fas fa-plus-circle"></i> Agregar a mi Stock</h3>
                <span style="cursor: pointer; font-size: 24px; color: #888;" onclick="cerrarModalCopia()">&times;</span>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Código</label>
                <input type="text" id="cp_cod_prod" class="input-field" readonly style="background: #111;">
                
                <label>Descripción</label>
                <input type="text" id="cp_descripcion" class="input-field">
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label>Costo Proveedor ($)</label>
                        <input type="number" step="0.01" id="cp_p_compra" class="input-field">
                    </div>
                    <div style="flex: 1;">
                        <label>Precio Venta ($)</label>
                        <input type="number" step="0.01" id="cp_p_venta" class="input-field" style="border-color: #2ecc71; color: #2ecc71; font-weight: bold;">
                    </div>
                </div>

                <label>Rubro / Categoría</label>
                <select id="cp_rubro" class="input-field">
                    <?php foreach ($rubros_list as $r): ?>
                        <option value="<?php echo $r['nombre']; ?>"><?php echo $r['nombre']; ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Proveedor Asignado</label>
                <input type="text" id="cp_proveedor" class="input-field" readonly style="background: #111;">
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn btn-primary" style="flex: 2; font-weight: bold; padding: 12px;" onclick="guardarProductoCopiado()">
                    GUARDAR EN MI STOCK
                </button>
                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="cerrarModalCopia()">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</body>
</html>