<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer');
require '../config/db_config.php';

$mensaje = '';

// LÓGICA DE GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['guardar_empresa'])) {
            // Usamos una consulta que inserta si no existe o actualiza si existe
            $sql = "INSERT INTO datos_empresa (id, nombre_fantasia, razon_social, cuit, condicion_iva, ingresos_brutos, inicio_actividades, direccion, localidad, telefono) 
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    nombre_fantasia = VALUES(nombre_fantasia), 
                    razon_social = VALUES(razon_social), 
                    cuit = VALUES(cuit), 
                    condicion_iva = VALUES(condicion_iva), 
                    ingresos_brutos = VALUES(ingresos_brutos), 
                    inicio_actividades = VALUES(inicio_actividades),
                    direccion = VALUES(direccion),
                    localidad = VALUES(localidad),
                    telefono = VALUES(telefono)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['nombre_fantasia'], $_POST['razon_social'], $_POST['cuit'],
                $_POST['condicion_iva'], $_POST['ingresos_brutos'], $_POST['inicio_actividades'],
                $_POST['direccion'], $_POST['localidad'], $_POST['telefono']
            ]);
            $mensaje = "✅ Datos de la empresa guardados correctamente.";
        }

        if (isset($_POST['guardar_sucursal'])) {
            // Si el ID viene vacío, es una inserción nueva. Si viene con número, es edición.
            $id_suc = !empty($_POST['id_sucursal']) ? $_POST['id_sucursal'] : null;

            $sql = "INSERT INTO sucursales (id, nombre_sucursal, direccion, telefono, email, web, es_principal) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    nombre_sucursal = VALUES(nombre_sucursal), 
                    direccion = VALUES(direccion), 
                    telefono = VALUES(telefono), 
                    email = VALUES(email), 
                    web = VALUES(web), 
                    es_principal = VALUES(es_principal)";
            
            $stmt = $pdo->prepare($sql);
            
            // Si es nueva y queremos que sea principal, primero reseteamos las otras
            if ($_POST['es_principal'] == 1) {
                $pdo->query("UPDATE sucursales SET es_principal = 0");
            }

            $stmt->execute([
                $id_suc, 
                $_POST['nombre_sucursal'], 
                $_POST['direccion'], 
                $_POST['telefono'], 
                $_POST['email'], 
                isset($_POST['web']) ? $_POST['web'] : '', 
                $_POST['es_principal']
            ]);
            
            $mensaje = "✅ Sucursal guardada y sincronizada correctamente.";
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

// OBTENER DATOS
$empresa = $pdo->query("SELECT * FROM datos_empresa WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$sucursales = $pdo->query("SELECT * FROM sucursales ORDER BY es_principal DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración de Empresa</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; }
        .content h1 { color: #00bcd4; border-bottom: 1px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        
        /* FICHA DE VISTA PREVIA */
        .preview-header {
            background: linear-gradient(145deg, #1e1e1e, #141414);
            border: 1px solid #333;
            border-left: 5px solid #00bcd4;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .info-group h2 { color: #00bcd4; margin: 0; font-size: 1.8rem; }
        .info-group p { margin: 5px 0; color: #aaa; }
        .data-tag { background: #2a2a2a; padding: 4px 10px; border-radius: 4px; color: #00bcd4; font-size: 0.8rem; margin-right: 10px; border: 1px solid #333; }

        .grid-config { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 1100px) {
            .grid-config {
                grid-template-columns: 1fr;
            }
        }
        .card { background: #1e1e1e; border: 1px solid #333; border-radius: 8px; padding: 20px; }
        
        .input-field {
            background: #2a2a2a; border: 1px solid #444; color: #fff;
            width: 100%; padding: 10px; margin-top: 5px; border-radius: 4px; box-sizing: border-box;
        }
        
        label { color: #00bcd4; font-size: 0.85rem; margin-top: 15px; display: block; font-weight: bold; }
        
        .btn-save { 
            background: #00bcd4; color: #000; border: none; padding: 12px; 
            width: 100%; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 20px;
        }
        
        .btn-save:hover { background: #008ba3; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; color: #00bcd4; border-bottom: 2px solid #333; padding: 10px; }
        td { padding: 10px; border-bottom: 1px solid #222; font-size: 0.9rem; }
        
        .badge-principal { background: #4caf50; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <h1><i class="fas fa-industry"></i> Perfil del Negocio</h1>

        <?php if ($mensaje): ?>
            <div class="alert alert-success" style="background: #1b5e20; color: white; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #2e7d32;">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="preview-header">
            <div class="info-group">
                <p style="text-transform: uppercase; font-size: 0.7rem; letter-spacing: 1px;">Datos actuales del sistema</p>
                <h2><?php echo htmlspecialchars(isset($empresa['nombre_fantasia']) ? $empresa['nombre_fantasia'] : 'Nombre del Negocio'); ?></h2>
                <p><i class="fas fa-signature"></i> Razón Social: <strong><?php echo htmlspecialchars(isset($empresa['razon_social']) ? $empresa['razon_social'] : '-'); ?></strong></p>
                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(isset($empresa['direccion']) ? $empresa['direccion'] : '-'); ?>, <?php echo htmlspecialchars(isset($empresa['localidad']) ? $empresa['localidad'] : '-'); ?></p>
                <p><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars(isset($empresa['telefono']) ? $empresa['telefono'] : '-'); ?></p>
                <div style="margin-top: 10px;">
                    <span class="data-tag">CUIT: <?php echo htmlspecialchars(isset($empresa['cuit']) ? $empresa['cuit'] : '00-00000000-0'); ?></span>
                    <span class="data-tag">IVA: <?php echo htmlspecialchars(isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '-'); ?></span>
                    <span class="data-tag">IIBB: <?php echo htmlspecialchars(isset($empresa['ingresos_brutos']) ? $empresa['ingresos_brutos'] : '-'); ?></span>
                </div>
            </div>
            <div style="text-align: right; border-left: 1px solid #333; padding-left: 20px;">
                <i class="fas fa-store-alt" style="font-size: 3.5rem; color: #333;"></i>
            </div>
        </div>

        <div class="grid-config">
            <div class="card">
                <h3 style="color: #fff; margin-top:0;"><i class="fas fa-edit"></i> Editar Información General</h3>
                <form method="POST">
                    <label>Nombre de Fantasía (Sale en Ticket)</label>
                    <input type="text" name="nombre_fantasia" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['nombre_fantasia']) ? $empresa['nombre_fantasia'] : ''); ?>">
                    
                    <label>Razón Social</label>
                    <input type="text" name="razon_social" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['razon_social']) ? $empresa['razon_social'] : ''); ?>">
                    
                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>CUIT</label>
                            <input type="text" name="cuit" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['cuit']) ? $empresa['cuit'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Inicio Actividades</label>
                            <input type="date" name="inicio_actividades" class="input-field" value="<?php echo isset($empresa['inicio_actividades']) ? $empresa['inicio_actividades'] : ''; ?>">
                        </div>
                    </div>

                    <label>Condición frente al IVA</label>
                    <select name="condicion_iva" class="input-field">
                        <option value="Responsable Inscripto" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'Responsable Inscripto' ? 'selected' : ''; ?>>Responsable Inscripto</option>
                        <option value="Responsable Monotributo" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'Monotributista' ? 'selected' : ''; ?>>Monotributista</option>
                        <option value="IVA Exento" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'IVA Exento' ? 'selected' : ''; ?>>IVA Exento</option>
                    </select>

                    <label>Ingresos Brutos</label>
                    <input type="text" name="ingresos_brutos" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['ingresos_brutos']) ? $empresa['ingresos_brutos'] : ''); ?>">

                    <label>Dirección</label>
                    <input type="text" name="direccion" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['direccion']) ? $empresa['direccion'] : ''); ?>">

                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>Localidad</label>
                            <input type="text" name="localidad" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['localidad']) ? $empresa['localidad'] : ''); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Teléfono de Contacto</label>
                            <input type="text" name="telefono" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['telefono']) ? $empresa['telefono'] : ''); ?>">
                        </div>
                    </div>

                    <button type="submit" name="guardar_empresa" class="btn-save">
                        <i class="fas fa-sync-alt"></i> ACTUALIZAR FICHA
                    </button>
                </form>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: #fff; margin: 0;"><i class="fas fa-map-marker-alt"></i> Sucursales y Contacto</h3>
                    
                    <button type="button" onclick="limpiarFormSucursal()" style="background: #333; color: #00bcd4; border: 1px solid #00bcd4; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; font-weight: bold;">
                        <i class="fas fa-plus"></i> NUEVA SUCURSAL
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id_sucursal" id="id_sucursal">
                    
                    <label>Nombre Sucursal</label>
                    <input type="text" name="nombre_sucursal" id="nombre_sucursal" class="input-field" required placeholder="Ej: Casa Central">

                    <label>Dirección Física</label>
                    <input type="text" name="direccion" id="direccion" class="input-field" placeholder="Calle, Número, Localidad">

                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>Teléfono</label>
                            <input type="text" name="telefono" id="telefono" class="input-field">
                        </div>
                        <div style="flex: 1;">
                            <label>Principal?</label>
                            <select name="es_principal" id="es_principal" class="input-field">
                                <option value="0">No</option>
                                <option value="1">Sí (Para tickets)</option>
                            </select>
                        </div>
                    </div>

                    <label>Email de contacto</label>
                    <input type="email" name="email" id="email" class="input-field">

                    <button type="submit" name="guardar_sucursal" class="btn-save" style="background: #4caf50;">
                        <i class="fas fa-plus"></i> GUARDAR / EDITAR SUCURSAL
                    </button>
                </form>

                <table>
                    <thead>
                        <tr>
                            <th>Sucursal</th>
                            <th>Contacto</th>
                            <th>-</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sucursales as $s): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($s['nombre_sucursal']); ?></strong><br>
                                <small style="color:#777;"><?php echo htmlspecialchars($s['direccion']); ?></small>
                                <?php if($s['es_principal']) echo '<br><span class="badge-principal">Ticket Default</span>'; ?>
                            </td>
                            <td>
                                <i class="fas fa-phone-alt" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($s['telefono']); ?><br>
                                <i class="fas fa-envelope" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($s['email']); ?>
                            </td>
                            <td style="text-align: right;">
                                <button onclick='editarSucursal(<?php echo json_encode($s); ?>)' style="background:none; border:none; color:#00bcd4; cursor:pointer; font-size:1.1rem;">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function editarSucursal(data) {
            document.getElementById('id_sucursal').value = data.id;
            document.getElementById('nombre_sucursal').value = data.nombre_sucursal;
            document.getElementById('direccion').value = data.direccion;
            document.getElementById('telefono').value = data.telefono;
            document.getElementById('email').value = data.email;
            document.getElementById('es_principal').value = data.es_principal;
            
            // Hacer scroll suave hacia el formulario de sucursal
            document.getElementById('nombre_sucursal').focus();
        }

        function limpiarFormSucursal() {
            document.getElementById('id_sucursal').value = '';
            document.getElementById('nombre_sucursal').value = '';
            document.getElementById('direccion').value = '';
            document.getElementById('telefono').value = '';
            document.getElementById('email').value = '';
            document.getElementById('es_principal').value = '0';
        }
    </script>
</body>
</html>