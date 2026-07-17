<?php
// Configuración de base de datos (incluye session_start())
require '../config/db_config.php';

// Guardia de sesión y permisos
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('admin');

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$tipo_mensaje = 'success';
$empresa_id = $_SESSION['empresa_id'] ?? null;

// LÓGICA DE GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Token de seguridad inválido.");
        }

        if (isset($_POST['guardar_empresa'])) {
            // Validaciones
            $cuit = trim($_POST['cuit']);
            if (!empty($cuit) && !preg_match('/^\d{2}-\d{8}-\d{1}$/', $cuit)) {
                throw new Exception("El CUIT debe tener formato XX-XXXXXXXX-X");
            }

            $telefono = trim($_POST['telefono']);
            if (!empty($telefono) && !preg_match('/^[\d\s\+\-\(\)]+$/', $telefono)) {
                throw new Exception("El teléfono contiene caracteres inválidos");
            }

            $sql = "UPDATE empresas SET 
                    nombre_fantasia = :nombre_fantasia,
                    razon_social = :razon_social,
                    cuit = :cuit,
                    condicion_iva = :condicion_iva,
                    ingresos_brutos = :ingresos_brutos,
                    inicio_actividades = :inicio_actividades,
                    direccion = :direccion,
                    localidad = :localidad,
                    telefono = :telefono
                    WHERE id = :empresa_id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nombre_fantasia' => $_POST['nombre_fantasia'],
                ':razon_social' => $_POST['razon_social'],
                ':cuit' => $cuit,
                ':condicion_iva' => $_POST['condicion_iva'],
                ':ingresos_brutos' => $_POST['ingresos_brutos'],
                ':inicio_actividades' => $_POST['inicio_actividades'] ?: null,
                ':direccion' => $_POST['direccion'],
                ':localidad' => $_POST['localidad'],
                ':telefono' => $telefono,
                ':empresa_id' => $empresa_id
            ]);
            $mensaje = "✅ Datos de la empresa guardados correctamente.";
        }

        if (isset($_POST['guardar_sucursal'])) {
            $id_suc = !empty($_POST['id_sucursal']) ? $_POST['id_sucursal'] : null;

            // Validaciones
            $email = trim($_POST['email']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El email no tiene formato válido");
            }

            $telefono = trim($_POST['telefono']);
            if (!empty($telefono) && !preg_match('/^[\d\s\+\-\(\)]+$/', $telefono)) {
                throw new Exception("El teléfono contiene caracteres inválidos");
            }

            // Iniciar transacción para evitar race conditions
            $pdo->beginTransaction();

            try {
                // Si se marca como principal, resetear las otras primero
                if ($_POST['es_principal'] == 1) {
                    $pdo->prepare("UPDATE sucursales SET es_principal = 0 WHERE empresa_id = :empresa_id")->execute([':empresa_id' => $empresa_id]);
                }

                $sql = "INSERT INTO sucursales (id, empresa_id, nombre_sucursal, direccion, telefono, email, web, es_principal) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        nombre_sucursal = VALUES(nombre_sucursal), 
                        direccion = VALUES(direccion), 
                        telefono = VALUES(telefono), 
                        email = VALUES(email), 
                        web = VALUES(web), 
                        es_principal = VALUES(es_principal)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $id_suc, 
                    $empresa_id,
                    $_POST['nombre_sucursal'], 
                    $_POST['direccion'], 
                    $telefono, 
                    $email, 
                    isset($_POST['web']) ? trim($_POST['web']) : '', 
                    $_POST['es_principal']
                ]);

                $pdo->commit();
                $mensaje = "✅ Sucursal guardada correctamente.";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // Eliminar sucursal
        if (isset($_POST['eliminar_sucursal'])) {
            $id_suc = (int)($_POST['id_sucursal'] ?? 0);
            
            if ($id_suc > 0) {
                // Verificar que no sea la única sucursal
                $count = $pdo->prepare("SELECT COUNT(*) FROM sucursales WHERE empresa_id = :empresa_id");
                $count->execute([':empresa_id' => $empresa_id]);
                $total = $count->fetchColumn();
                
                if ($total <= 1) {
                    $mensaje = "❌ No se puede eliminar la última sucursal";
                    $tipo_mensaje = 'error';
                } else {
                    // Verificar que no sea la principal
                    $suc = $pdo->prepare("SELECT es_principal FROM sucursales WHERE id = :id AND empresa_id = :empresa_id");
                    $suc->execute([':id' => $id_suc, ':empresa_id' => $empresa_id]);
                    $sucursal = $suc->fetch(PDO::FETCH_ASSOC);
                    
                    if ($sucursal && $sucursal['es_principal']) {
                        $mensaje = "❌ No se puede eliminar la sucursal principal. Marque otra como principal primero.";
                        $tipo_mensaje = 'error';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM sucursales WHERE id = :id AND empresa_id = :empresa_id");
                        $stmt->execute([':id' => $id_suc, ':empresa_id' => $empresa_id]);
                        $mensaje = "✅ Sucursal eliminada correctamente.";
                    }
                }
            }
        }

        // Manejo de upload de logo
        if (isset($_POST['guardar_empresa']) && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../img/logos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                $mensaje = "⚠️ Logo guardado, pero el formato debe ser JPG, PNG, GIF o WebP";
                $tipo_mensaje = 'warning';
            } else {
                $file_name = 'logo_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                    // Eliminar logo anterior si existe
                    $stmt_logo = $pdo->prepare("SELECT logo_path FROM empresas WHERE id = :empresa_id LIMIT 1");
                    $stmt_logo->execute([':empresa_id' => $empresa_id]);
                    $empresa_actual = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                    if ($empresa_actual && !empty($empresa_actual['logo_path']) && file_exists('../' . $empresa_actual['logo_path'])) {
                        unlink('../' . $empresa_actual['logo_path']);
                    }
                    
                    // Actualizar ruta en BD
                    $stmt = $pdo->prepare("UPDATE empresas SET logo_path = :logo_path WHERE id = :empresa_id");
                    $stmt->execute([':logo_path' => 'img/logos/' . $file_name, ':empresa_id' => $empresa_id]);
                    
                    if (empty($mensaje)) {
                        $mensaje = "✅ Datos y logo guardados correctamente.";
                    }
                } else {
                    $mensaje = "⚠️ Datos guardados, pero error al subir logo";
                    $tipo_mensaje = 'warning';
                }
            }
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// OBTENER DATOS (filtrar por empresa_id para multi-empresa)
$empresa = $pdo->prepare("SELECT * FROM empresas WHERE id = :empresa_id LIMIT 1");
$empresa->execute([':empresa_id' => $empresa_id]);
$empresa = $empresa->fetch(PDO::FETCH_ASSOC);

$sucursales = $pdo->prepare("SELECT * FROM sucursales WHERE empresa_id = :empresa_id ORDER BY es_principal DESC, id ASC");
$sucursales->execute([':empresa_id' => $empresa_id]);
$sucursales = $sucursales->fetchAll(PDO::FETCH_ASSOC);

// Regenerar token CSRF después de POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }
        
        .preview-header:hover {
            box-shadow: 0 6px 12px rgba(0, 188, 212, 0.2);
            border-left-color: #00acc1;
        }
        
        .info-group h2 { 
            color: #00bcd4; 
            margin: 0; 
            font-size: 1.8rem; 
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .info-group p { 
            margin: 5px 0; 
            color: #aaa; 
            font-size: 0.95rem;
        }
        
        .info-group strong {
            color: #e0e0e0;
            font-weight: 600;
        }
        
        .data-tag { 
            background: #2a2a2a; 
            padding: 4px 10px; 
            border-radius: 4px; 
            color: #00bcd4; 
            font-size: 0.8rem; 
            margin-right: 10px; 
            border: 1px solid #333;
            display: inline-block;
            margin-top: 5px;
            transition: all 0.2s ease;
        }
        
        .data-tag:hover {
            background: #333;
            border-color: #00bcd4;
            transform: translateY(-1px);
        }
        
        .preview-header img {
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
            transition: transform 0.3s ease;
        }
        
        .preview-header img:hover {
            transform: scale(1.05);
        }

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
        
        .input-field:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.2);
        }
        
        label { color: #00bcd4; font-size: 0.85rem; margin-top: 15px; display: block; font-weight: bold; }
        
        .btn-save { 
            background: #00bcd4; color: #000; border: none; padding: 12px; 
            width: 100%; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 20px;
        }
        
        .btn-save:hover { background: #008ba3; }

        .btn-secondary {
            background: #333; color: #00bcd4; border: 1px solid #00bcd4; padding: 8px 16px;
            border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: bold;
        }

        .btn-secondary:hover { background: #444; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; color: #00bcd4; border-bottom: 2px solid #333; padding: 10px; }
        td { padding: 10px; border-bottom: 1px solid #222; font-size: 0.9rem; }
        
        .badge-principal { background: #4caf50; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid;
        }

        .alert-success {
            background: #1b5e20;
            color: white;
            border-color: #2e7d32;
        }

        .alert-error {
            background: #b71c1c;
            color: white;
            border-color: #c62828;
        }

        .alert-warning {
            background: #f57c00;
            color: white;
            border-color: #ef6c00;
        }

        .logo-preview {
            max-width: 200px;
            max-height: 200px;
            border: 2px dashed #333;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            text-align: center;
        }

        .logo-preview img {
            max-width: 100%;
            max-height: 180px;
            border-radius: 4px;
        }

        .logo-upload {
            margin-top: 10px;
        }

        .help-text {
            font-size: 0.75rem;
            color: #888;
            margin-top: 3px;
        }

        .error-text {
            color: #f44336;
            font-size: 0.8rem;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1><i class="fas fa-industry"></i> Perfil del Negocio</h1>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>" style="background: <?php echo $tipo_mensaje === 'error' ? '#b71c1c' : ($tipo_mensaje === 'warning' ? '#f57c00' : '#1b5e20'); ?>; color: white; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid <?php echo $tipo_mensaje === 'error' ? '#c62828' : ($tipo_mensaje === 'warning' ? '#ef6c00' : '#2e7d32'); ?>;">
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
                <?php if (!empty($empresa['logo_path']) && file_exists('../' . $empresa['logo_path'])): ?>
                    <img src="../<?php echo htmlspecialchars($empresa['logo_path']); ?>" alt="Logo" style="max-width: 150px; max-height: 150px;">
                <?php else: ?>
                    <i class="fas fa-store-alt" style="font-size: 3.5rem; color: #333;"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-config">
            <div class="card">
                <h3 style="color: #fff; margin-top:0;"><i class="fas fa-edit"></i> Editar Información General</h3>
                <form method="POST" enctype="multipart/form-data" id="formEmpresa">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <label>Nombre de Fantasía (Sale en Ticket) *</label>
                    <input type="text" name="nombre_fantasia" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['nombre_fantasia']) ? $empresa['nombre_fantasia'] : ''); ?>" required>
                    
                    <label>Razón Social *</label>
                    <input type="text" name="razon_social" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['razon_social']) ? $empresa['razon_social'] : ''); ?>" required>
                    
                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>CUIT (XX-XXXXXXXX-X)</label>
                            <input type="text" name="cuit" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['cuit']) ? $empresa['cuit'] : ''); ?>" placeholder="00-00000000-0" maxlength="13">
                            <div class="help-text">Formato: XX-XXXXXXXX-X</div>
                        </div>
                        <div style="flex: 1;">
                            <label>Inicio Actividades</label>
                            <input type="date" name="inicio_actividades" class="input-field" value="<?php echo isset($empresa['inicio_actividades']) ? $empresa['inicio_actividades'] : ''; ?>">
                        </div>
                    </div>

                    <label>Condición frente al IVA *</label>
                    <select name="condicion_iva" class="input-field" required>
                        <option value="">Seleccione...</option>
                        <option value="Responsable Inscripto" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'Responsable Inscripto' ? 'selected' : ''; ?>>Responsable Inscripto</option>
                        <option value="Responsable Monotributo" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'Responsable Monotributo' ? 'selected' : ''; ?>>Responsable Monotributo</option>
                        <option value="IVA Exento" <?php echo (isset($empresa['condicion_iva']) ? $empresa['condicion_iva'] : '') == 'IVA Exento' ? 'selected' : ''; ?>>IVA Exento</option>
                    </select>

                    <label>Ingresos Brutos</label>
                    <input type="text" name="ingresos_brutos" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['ingresos_brutos']) ? $empresa['ingresos_brutos'] : ''); ?>">

                    <label>Dirección *</label>
                    <input type="text" name="direccion" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['direccion']) ? $empresa['direccion'] : ''); ?>" required>

                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>Localidad *</label>
                            <input type="text" name="localidad" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['localidad']) ? $empresa['localidad'] : ''); ?>" required>
                        </div>
                        <div style="flex: 1;">
                            <label>Teléfono de Contacto</label>
                            <input type="text" name="telefono" class="input-field" value="<?php echo htmlspecialchars(isset($empresa['telefono']) ? $empresa['telefono'] : ''); ?>">
                        </div>
                    </div>

                    <label>Logo de la Empresa</label>
                    <div class="logo-preview" id="logoPreview">
                        <?php if (!empty($empresa['logo_path']) && file_exists('../' . $empresa['logo_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($empresa['logo_path']); ?>" alt="Logo actual">
                            <div class="help-text">Logo actual</div>
                        <?php else: ?>
                            <i class="fas fa-image" style="font-size: 3rem; color: #444;"></i>
                            <div class="help-text">Sin logo</div>
                        <?php endif; ?>
                    </div>
                    <div class="logo-upload">
                        <input type="file" name="logo" id="logo" class="input-field" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="help-text">Formatos: JPG, PNG, GIF, WebP. Tamaño máximo: 2MB</div>
                    </div>

                    <button type="submit" name="guardar_empresa" class="btn-save" onclick="return confirm('¿Guardar cambios en datos de empresa?')">
                        <i class="fas fa-sync-alt"></i> ACTUALIZAR FICHA
                    </button>
                </form>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: #fff; margin: 0;"><i class="fas fa-map-marker-alt"></i> Sucursales y Contacto</h3>
                    
                    <button type="button" onclick="limpiarFormSucursal()" class="btn-secondary">
                        <i class="fas fa-plus"></i> NUEVA SUCURSAL
                    </button>
                </div>
                <form method="POST" id="formSucursal">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="id_sucursal" id="id_sucursal">
                    
                    <label>Nombre Sucursal *</label>
                    <input type="text" name="nombre_sucursal" id="nombre_sucursal" class="input-field" required placeholder="Ej: Casa Central">
                    <div id="error_nombre_sucursal" class="error-text"></div>

                    <label>Dirección Física</label>
                    <input type="text" name="direccion" id="direccion" class="input-field" placeholder="Calle, Número, Localidad">

                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label>Teléfono</label>
                            <input type="text" name="telefono" id="telefono" class="input-field">
                        </div>
                        <div style="flex: 1;">
                            <label>Principal? (Para tickets)</label>
                            <select name="es_principal" id="es_principal" class="input-field">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                    </div>

                    <label>Email de contacto</label>
                    <input type="email" name="email" id="email" class="input-field" placeholder="info@empresa.com">

                    <label>Sitio Web</label>
                    <input type="url" name="web" id="web" class="input-field" placeholder="https://www.empresa.com">

                    <button type="submit" name="guardar_sucursal" class="btn-save" style="background: #4caf50;" onclick="return confirm('¿Guardar sucursal?')">
                        <i class="fas fa-plus"></i> GUARDAR / EDITAR SUCURSAL
                    </button>
                </form>

                <table>
                    <thead>
                        <tr>
                            <th>Sucursal</th>
                            <th>Contacto</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sucursales)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: #777; padding: 20px;">
                                    No hay sucursales registradas
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sucursales as $s): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($s['nombre_sucursal']); ?></strong><br>
                                    <small style="color:#777;"><?php echo htmlspecialchars($s['direccion']); ?></small>
                                    <?php if($s['es_principal']) echo '<br><span class="badge-principal">Ticket Default</span>'; ?>
                                </td>
                                <td>
                                    <?php if ($s['telefono']): ?>
                                        <i class="fas fa-phone-alt" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($s['telefono']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($s['email']): ?>
                                        <i class="fas fa-envelope" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($s['email']); ?>
                                    <?php endif; ?>
                                    <?php if ($s['web']): ?>
                                        <br><i class="fas fa-globe" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($s['web']); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button onclick='editarSucursal(<?php echo json_encode($s); ?>)' class="btn-secondary" style="padding: 5px 10px; font-size: 0.9rem; margin-right: 5px;" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if (!$s['es_principal']): ?>
                                    <button onclick='eliminarSucursal(<?php echo $s['id']; ?>, "<?php echo htmlspecialchars($s['nombre_sucursal']); ?>")' class="btn-secondary" style="padding: 5px 10px; font-size: 0.9rem; background: #b71c1c; border-color: #f44336; color: white;" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Preview de logo antes de subir
        document.getElementById('logo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('logoPreview');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview"><div class="help-text">Vista previa</div>';
                }
                reader.readAsDataURL(file);
            }
        });

        // Formatear CUIT automáticamente
        document.querySelector('input[name="cuit"]').addEventListener('blur', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length === 11) {
                value = value.substring(0, 2) + '-' + value.substring(2, 10) + '-' + value.substring(10, 11);
                e.target.value = value;
            }
        });

        function editarSucursal(data) {
            document.getElementById('id_sucursal').value = data.id;
            document.getElementById('nombre_sucursal').value = data.nombre_sucursal;
            document.getElementById('direccion').value = data.direccion || '';
            document.getElementById('telefono').value = data.telefono || '';
            document.getElementById('email').value = data.email || '';
            document.getElementById('web').value = data.web || '';
            document.getElementById('es_principal').value = data.es_principal;
            
            // Scroll suave hacia el formulario
            document.getElementById('nombre_sucursal').scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.getElementById('nombre_sucursal').focus();
        }

        function limpiarFormSucursal() {
            document.getElementById('id_sucursal').value = '';
            document.getElementById('nombre_sucursal').value = '';
            document.getElementById('direccion').value = '';
            document.getElementById('telefono').value = '';
            document.getElementById('email').value = '';
            document.getElementById('web').value = '';
            document.getElementById('es_principal').value = '0';
            document.getElementById('nombre_sucursal').focus();
        }

        // Validación del formulario de sucursal
        document.getElementById('formSucursal').addEventListener('submit', function(e) {
            const nombre = document.getElementById('nombre_sucursal').value.trim();
            const errorDiv = document.getElementById('error_nombre_sucursal');
            
            if (nombre.length < 2) {
                e.preventDefault();
                errorDiv.textContent = 'El nombre debe tener al menos 2 caracteres';
                document.getElementById('nombre_sucursal').focus();
                return false;
            }
            
            errorDiv.textContent = '';
            return true;
        });

        // Eliminar sucursal
        function eliminarSucursal(id, nombre) {
            if (confirm('¿Está seguro de eliminar la sucursal "' + nombre + '"?\n\nEsta acción no se puede deshacer.')) {
                // Crear formulario dinámicamente
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = 'csrf_token';
                csrf.value = '<?php echo $_SESSION['csrf_token']; ?>';
                form.appendChild(csrf);
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id_sucursal';
                idInput.value = id;
                form.appendChild(idInput);
                
                const eliminar = document.createElement('input');
                eliminar.type = 'hidden';
                eliminar.name = 'eliminar_sucursal';
                eliminar.value = '1';
                form.appendChild(eliminar);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>