<?php
/**
 * ABM Empresas - Listado y gestión de empresas (multi-empresa)
 * Consulta la tabla `empresas` y permite crear, editar y cambiar entre empresas.
 * Muestra datos completos de cada empresa + sus sucursales.
 */
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('admin');   // Solo admin/developer pueden gestionar empresas y el toggle de Cierre de Caja
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensaje = '';
$tipo_mensaje = 'success';
$empresa_editar = [];

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CAMBIAR de empresa activa (via GET)
if ($accion === 'cambiar' && $id) {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre_fantasia FROM empresas WHERE id = ?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$emp) {
            throw new Exception("La empresa no existe.");
        }

        $_SESSION['empresa_id'] = $emp['id'];
        header("Location: " . url('abm-empresas') . "?msg=" . urlencode("✅ Cambiaste a la empresa: " . $emp['nombre_fantasia']));
        exit;
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'error';
        $accion = 'listar';
    }
}

// Mensaje desde redirección
if (isset($_GET['msg'])) {
    $mensaje = htmlspecialchars($_GET['msg']);
    $tipo_mensaje = strpos($mensaje, '✅') !== false ? 'success' : 'error';
}

// CREAR / EDITAR empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("Token de seguridad inválido.");
        }

        $nombre_fantasia = trim($_POST['nombre_fantasia']);
        $razon_social    = trim($_POST['razon_social']);
        $cuit            = trim($_POST['cuit']);
        $condicion_iva   = trim($_POST['condicion_iva']);
        $ingresos_brutos = trim($_POST['ingresos_brutos']);
        $inicio_actividades = $_POST['inicio_actividades'] ?: null;
        $direccion       = trim($_POST['direccion']);
        $localidad       = trim($_POST['localidad']);
        $telefono        = trim($_POST['telefono']);
                $activa          = isset($_POST['activa']) ? 1 : 0;
        $modulo_cierre_caja = isset($_POST['modulo_cierre_caja']) ? 1 : 0;

        if (empty($nombre_fantasia)) {
            throw new Exception("El nombre de fantasía es obligatorio.");
        }
        if (empty($razon_social)) {
            throw new Exception("La razón social es obligatoria.");
        }
        if (empty($direccion)) {
            throw new Exception("La dirección es obligatoria.");
        }
        if (empty($localidad)) {
            throw new Exception("La localidad es obligatoria.");
        }

        if (!empty($cuit) && !normalizar_cuit($cuit, $cuit)) {
            throw new Exception("El CUIT debe tener formato XX-XXXXXXXX-X");
        }

        if (!empty($telefono) && !preg_match('/^[\d\s\+\-\(\)]+$/', $telefono)) {
            throw new Exception("El teléfono contiene caracteres inválidos");
        }

        $accion_post = $_POST['accion_post'] ?? '';

        if ($accion_post === 'crear') {
            if (!empty($cuit)) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM empresas WHERE cuit = ?");
                $check->execute([$cuit]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Ya existe una empresa con ese CUIT.");
                }
            }

                        $sql = "INSERT INTO empresas 
                        (nombre_fantasia, razon_social, cuit, condicion_iva, ingresos_brutos, inicio_actividades, 
                         direccion, localidad, telefono, activa, modulo_cierre_caja, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nombre_fantasia, $razon_social, $cuit, $condicion_iva, $ingresos_brutos, $inicio_actividades,
                $direccion, $localidad, $telefono, $activa, $modulo_cierre_caja
            ]);
            $nuevo_id = $pdo->lastInsertId();
            $mensaje = "✅ Empresa \"$nombre_fantasia\" creada correctamente (ID: $nuevo_id).";
            $accion = 'listar';
        } elseif ($accion_post === 'editar' && $id) {
            if (!empty($cuit)) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM empresas WHERE cuit = ? AND id != ?");
                $check->execute([$cuit, $id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Ya existe otra empresa con ese CUIT.");
                }
            }

                        $sql = "UPDATE empresas SET 
                        nombre_fantasia = ?, razon_social = ?, cuit = ?, condicion_iva = ?, 
                        ingresos_brutos = ?, inicio_actividades = ?, direccion = ?, 
                        localidad = ?, telefono = ?, activa = ?, modulo_cierre_caja = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nombre_fantasia, $razon_social, $cuit, $condicion_iva, $ingresos_brutos, $inicio_actividades,
                $direccion, $localidad, $telefono, $activa, $modulo_cierre_caja, $id
            ]);
            $mensaje = "✅ Empresa \"$nombre_fantasia\" actualizada correctamente.";
            $accion = 'listar';
        }

        // Manejo de upload de logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../img/logos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($file_extension, $allowed_extensions)) {
                $mensaje .= " ⚠️ Logo no subido: formato no válido (JPG, PNG, GIF, WebP).";
                $tipo_mensaje = 'warning';
            } else {
                $id_logo = $id ?? $nuevo_id ?? 0;
                $file_name = 'logo_' . time() . '_' . $id_logo . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
                    $stmt_logo = $pdo->prepare("UPDATE empresas SET logo_path = ? WHERE id = ?");
                    $stmt_logo->execute(['img/logos/' . $file_name, $id_logo]);
                    $mensaje .= " 🖼️ Logo subido correctamente.";
                } else {
                    $mensaje .= " ⚠️ Error al subir el logo.";
                }
            }
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'error';
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ELIMINAR empresa
if ($accion === 'eliminar' && $id) {
    try {
        if ($id === $empresa_id) {
            throw new Exception("No puedes eliminar la empresa en la que estás trabajando actualmente. Cambia a otra empresa primero.");
        }

        $stmt = $pdo->prepare("DELETE FROM empresas WHERE id = ?");
        $stmt->execute([$id]);
        $mensaje = "🗑️ Empresa eliminada correctamente.";
        $tipo_mensaje = 'success';
        $accion = 'listar';
    } catch (Exception $e) {
        $mensaje = "❌ Error al eliminar: " . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Obtener empresa para editar
if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = ?");
    $stmt->execute([$id]);
    $empresa_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$empresa_editar) {
        $mensaje = "❌ Empresa no encontrada.";
        $tipo_mensaje = 'error';
        $accion = 'listar';
    }
}

// LISTADO de empresas
$empresas = [];
$sucursales_por_empresa = [];
if ($accion === 'listar') {
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    $filtro_activa = isset($_GET['activa']) ? $_GET['activa'] : '';

    $where = [];
    $params = [];

    if ($buscar) {
        $where[] = "(e.nombre_fantasia LIKE ? OR e.razon_social LIKE ? OR e.cuit LIKE ?)";
        $like = "%$buscar%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($filtro_activa !== '') {
        $where[] = "e.activa = ?";
        $params[] = (int)$filtro_activa;
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT e.*, 
                   (SELECT COUNT(*) FROM sucursales WHERE empresa_id = e.id) as total_sucursales,
                   (SELECT COUNT(*) FROM usuarios WHERE empresa_id = e.id) as total_usuarios
            FROM empresas e 
            $where_sql 
            ORDER BY e.nombre_fantasia ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener sucursales para cada empresa
    if (!empty($empresas)) {
        $ids = array_column($empresas, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_suc = $pdo->prepare("SELECT id, empresa_id, nombre_sucursal, direccion, telefono, email, es_principal 
                                    FROM sucursales WHERE empresa_id IN ($placeholders) 
                                    ORDER BY es_principal DESC, nombre_sucursal ASC");
        $stmt_suc->execute($ids);
        $sucursales = $stmt_suc->fetchAll(PDO::FETCH_ASSOC);
        
        // Indexar sucursales por empresa_id
        $sucursales_por_empresa = [];
        foreach ($sucursales as $s) {
            $sucursales_por_empresa[$s['empresa_id']][] = $s;
        }
    }
}

$total_empresas = count($empresas);

$condiciones_iva = [
    '' => 'Seleccione...',
    'Responsable Inscripto' => 'Responsable Inscripto',
    'Responsable Monotributo' => 'Responsable Monotributo',
    'IVA Exento' => 'IVA Exento',
    'IVA Sujeto Exento' => 'IVA Sujeto Exento',
    'Consumidor Final' => 'Consumidor Final',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Empresas | <?php echo htmlspecialchars($nombre_empresa_sistema ?? 'POS'); ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css?v=' . (file_exists(__DIR__ . '/../css/style.css') ? filemtime(__DIR__ . '/../css/style.css') : '1')); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/pages/abm_empresas.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <?php include 'topbar.php'; ?>

    <div class="content" style="padding-top: 70px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h1><i class="fas fa-building"></i> Gestión de Empresas</h1>
            <?php if ($accion === 'listar'): ?>
                <a href="?accion=crear" style="background: #00bcd4; color: #000; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold;">
                    <i class="fas fa-plus"></i> Nueva Empresa
                </a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <i class="fas fa-<?php echo $tipo_mensaje === 'error' ? 'times-circle' : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'check-circle'); ?>"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <!-- FILTROS -->
            <div class="filtros-bar">
                <form method="GET" style="display: contents;" id="filtrosForm">
                    <input type="hidden" name="accion" value="listar">
                    <input type="text" name="buscar" placeholder="🔍 Buscar por nombre, razón social o CUIT..." 
                           value="<?php echo htmlspecialchars($_GET['buscar'] ?? ''); ?>">
                    <select name="activa">
                        <option value="">Todos los estados</option>
                        <option value="1" <?php echo (isset($_GET['activa']) && $_GET['activa'] === '1') ? 'selected' : ''; ?>>Activas</option>
                        <option value="0" <?php echo (isset($_GET['activa']) && $_GET['activa'] === '0') ? 'selected' : ''; ?>>Inactivas</option>
                    </select>
                    <button type="submit" class="btn-filtro"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="<?php echo url('abm-empresas'); ?>" class="btn-filtro"><i class="fas fa-undo"></i> Limpiar</a>
                </form>
            </div>

            <!-- TOTAL -->
            <div style="margin-bottom: 16px; color: #888; font-size: 0.85rem;">
                <i class="fas fa-building"></i> Total empresas: <strong style="color: #00bcd4;"><?php echo $total_empresas; ?></strong>
            </div>

            <!-- LISTADO -->
            <?php if (empty($empresas)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #888;">
                    <i class="fas fa-building" style="font-size: 4rem; display: block; margin-bottom: 20px; color: #333;"></i>
                    <p style="font-size: 1.1rem;">No hay empresas registradas</p>
                    <p style="font-size: 0.9rem;">Crea tu primera empresa para comenzar</p>
                    <a href="?accion=crear" style="display: inline-block; margin-top: 16px; background: #00bcd4; color: #000; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">
                        <i class="fas fa-plus"></i> Crear Empresa
                    </a>
                </div>
            <?php else: ?>
                <div class="empresas-grid">
                    <?php foreach ($empresas as $emp): 
                        $es_actual = ($emp['id'] == $empresa_id);
                        $emp_sucursales = $sucursales_por_empresa[$emp['id']] ?? [];
                    ?>
                    <div class="empresa-card <?php echo (!($emp['activa'] ?? 1)) ? 'inactiva' : ''; ?>">
                        <div class="card-header">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($emp['logo_path']) && file_exists('../' . $emp['logo_path'])): ?>
                                    <img src="<?php echo url($emp['logo_path']); ?>" alt="Logo" class="logo-mini">
                                <?php else: ?>
                                    <div class="logo-placeholder"><i class="fas fa-store"></i></div>
                                <?php endif; ?>
                                <div>
                                    <h3><?php echo htmlspecialchars($emp['nombre_fantasia']); ?>
                                        <?php if ($es_actual): ?>
                                            <span class="empresa-actual-badge"><i class="fas fa-check"></i> ACTUAL</span>
                                        <?php endif; ?>
                                    </h3>
                                </div>
                            </div>
                            <span class="badge-activa <?php echo ($emp['activa'] ?? 1) ? 'si' : 'no'; ?>">
                                <?php echo ($emp['activa'] ?? 1) ? 'Activa' : 'Inactiva'; ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <p><i class="fas fa-signature"></i> <strong>Razón Social:</strong> <?php echo htmlspecialchars($emp['razon_social'] ?? '-'); ?></p>
                            <p><i class="fas fa-map-marker-alt"></i> <strong>Dirección:</strong> <?php echo htmlspecialchars($emp['direccion'] ?? ''); ?>, <?php echo htmlspecialchars($emp['localidad'] ?? ''); ?></p>
                            <?php if (!empty($emp['telefono'])): ?>
                                <p><i class="fas fa-phone-alt"></i> <strong>Teléfono:</strong> <?php echo htmlspecialchars($emp['telefono']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($emp['inicio_actividades'])): ?>
                                <p><i class="fas fa-calendar-alt"></i> <strong>Inicio Actividades:</strong> <?php echo date('d/m/Y', strtotime($emp['inicio_actividades'])); ?></p>
                            <?php endif; ?>
                            <div style="margin-top: 8px;">
                                <?php if (!empty($emp['cuit'])): ?>
                                    <span class="data-tag">CUIT: <?php echo htmlspecialchars(formatear_cuit($emp['cuit'] ?? '')); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($emp['condicion_iva'])): ?>
                                    <span class="data-tag">IVA: <?php echo htmlspecialchars($emp['condicion_iva']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($emp['ingresos_brutos'])): ?>
                                    <span class="data-tag">IIBB: <?php echo htmlspecialchars($emp['ingresos_brutos']); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- SUCURSALES -->
                            <?php if (!empty($emp_sucursales)): ?>
                            <div class="sucursales-section">
                                <div class="suc-header" onclick="toggleSucursales(this)">
                                    <i class="fas fa-chevron-right"></i>
                                    <i class="fas fa-store-alt"></i> Sucursales (<?php echo count($emp_sucursales); ?>)
                                </div>
                                <div class="sucursales-list">
                                    <?php foreach ($emp_sucursales as $suc): ?>
                                    <div class="suc-item">
                                        <div class="suc-nombre">
                                            <?php echo htmlspecialchars($suc['nombre_sucursal']); ?>
                                            <?php if ($suc['es_principal']): ?>
                                                <span class="badge-principal">PRINCIPAL</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="suc-detalle">
                                            <?php if (!empty($suc['direccion'])): ?>
                                                <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($suc['direccion']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($suc['telefono'])): ?>
                                                <?php if (!empty($suc['direccion'])): ?> | <?php endif; ?>
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($suc['telefono']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($suc['email'])): ?>
                                                <?php if (!empty($suc['direccion']) || !empty($suc['telefono'])): ?> | <?php endif; ?>
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($suc['email']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="sucursales-section">
                                <div style="color: #555; font-size: 0.78rem; margin-top: 4px;">
                                    <i class="fas fa-store-alt"></i> Sin sucursales registradas
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <div class="stats">
                                <i class="fas fa-store-alt"></i> <?php echo intval($emp['total_sucursales'] ?? 0); ?> sucursales &nbsp;
                                                                <i class="fas fa-users"></i> <?php echo intval($emp['total_usuarios'] ?? 0); ?> usuarios
                                &nbsp; <i class="fas fa-lock"></i>
                                <span class="badge-activa <?php echo ((int)($emp['modulo_cierre_caja'] ?? 1)) ? 'si' : 'no'; ?>">
                                    <?php echo ((int)($emp['modulo_cierre_caja'] ?? 1)) ? 'Cierres: Sí' : 'Cierres: No'; ?>
                                </span>
                                <?php if (!empty($emp['created_at'])): ?>
                                    &nbsp; <i class="fas fa-calendar-plus"></i> <?php echo date('d/m/Y', strtotime($emp['created_at'])); ?>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <?php if (!$es_actual): ?>
                                <a href="?accion=cambiar&id=<?php echo $emp['id']; ?>" class="btn-accion cambiar" 
                                   onclick="return confirmarNavegacion(this, 'Cambiar Empresa', '¿Cambiar a la empresa &quot;<?php echo htmlspecialchars($emp['nombre_fantasia'], ENT_QUOTES); ?>&quot;?', 'ACCEDER')">
                                    <i class="fas fa-exchange-alt"></i> Acceder
                                </a>
                                <?php endif; ?>
                                <a href="?accion=editar&id=<?php echo $emp['id']; ?>" class="btn-accion editar">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <?php if (!$es_actual): ?>
                                <a href="?accion=eliminar&id=<?php echo $emp['id']; ?>" class="btn-accion eliminar"
                                   onclick="return confirmarNavegacion(this, 'Eliminar Empresa', '¿Eliminar la empresa &quot;<?php echo htmlspecialchars($emp['nombre_fantasia'], ENT_QUOTES); ?>&quot;? Se eliminarán todos los datos asociados (clientes, productos, ventas, etc.). Esta acción no se puede deshacer.', 'ELIMINAR')">
                                    <i class="fas fa-trash"></i> Eliminar
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="pagination-info">
                    Mostrando <?php echo count($empresas); ?> empresa(s)
                </div>
            <?php endif; ?>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): 
            $editando = ($accion === 'editar' && !empty($empresa_editar));
            $emp = $editando ? $empresa_editar : [];
        ?>
            <a href="<?php echo url('abm-empresas'); ?>" class="back-link"><i class="fas fa-arrow-left"></i> Volver al listado</a>
            <div class="form-container">
                <h2><i class="fas fa-<?php echo $editando ? 'edit' : 'plus-circle'; ?>"></i> 
                    <?php echo $editando ? 'Editar Empresa: ' . htmlspecialchars($emp['nombre_fantasia']) : 'Nueva Empresa'; ?>
                </h2>
                <form method="POST" enctype="multipart/form-data" id="formEmpresa">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="accion_post" value="<?php echo $editando ? 'editar' : 'crear'; ?>">
                    <?php if ($editando): ?>
                        <input type="hidden" name="id_empresa" value="<?php echo $emp['id']; ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nombre de Fantasía *</label>
                            <input type="text" name="nombre_fantasia" class="input-field" required
                                   value="<?php echo htmlspecialchars($emp['nombre_fantasia'] ?? ''); ?>"
                                   placeholder="Ej: Mi Negocio">
                        </div>
                        <div class="form-group">
                            <label>Razón Social *</label>
                            <input type="text" name="razon_social" class="input-field" required
                                   value="<?php echo htmlspecialchars($emp['razon_social'] ?? ''); ?>"
                                   placeholder="Ej: Mi Negocio S.R.L.">
                        </div>

                        <div class="form-group">
                            <label>CUIT (XX-XXXXXXXX-X)</label>
                            <input type="text" name="cuit" class="input-field" 
                                   value="<?php echo htmlspecialchars(formatear_cuit($emp['cuit'] ?? '')); ?>"
                                   placeholder="00-00000000-0" maxlength="13">
                            <div class="help-text">Formato: XX-XXXXXXXX-X</div>
                        </div>
                        <div class="form-group">
                            <label>Inicio de Actividades</label>
                            <input type="date" name="inicio_actividades" class="input-field"
                                   value="<?php echo $emp['inicio_actividades'] ?? ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>Condición frente al IVA</label>
                            <select name="condicion_iva" class="input-field">
                                <?php foreach ($condiciones_iva as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo (isset($emp['condicion_iva']) && $emp['condicion_iva'] === $val) ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ingresos Brutos</label>
                            <input type="text" name="ingresos_brutos" class="input-field"
                                   value="<?php echo htmlspecialchars($emp['ingresos_brutos'] ?? ''); ?>"
                                   placeholder="Nº de inscripción">
                        </div>

                        <div class="form-group full-width">
                            <label>Dirección *</label>
                            <input type="text" name="direccion" class="input-field" required
                                   value="<?php echo htmlspecialchars($emp['direccion'] ?? ''); ?>"
                                   placeholder="Calle, Número, Piso">
                        </div>

                        <div class="form-group">
                            <label>Localidad *</label>
                            <input type="text" name="localidad" class="input-field" required
                                   value="<?php echo htmlspecialchars($emp['localidad'] ?? ''); ?>"
                                   placeholder="Ciudad / Localidad">
                        </div>
                        <div class="form-group">
                            <label>Teléfono</label>
                            <input type="text" name="telefono" class="input-field"
                                   value="<?php echo htmlspecialchars($emp['telefono'] ?? ''); ?>"
                                   placeholder="+54 11 1234-5678">
                        </div>

                                                <div class="form-group full-width checkbox-group">
                            <input type="checkbox" name="activa" id="activa" value="1"
                                   <?php echo (!isset($emp['activa']) || $emp['activa']) ? 'checked' : ''; ?>>
                            <label for="activa">Empresa activa</label>
                        </div>

                        <div class="form-group full-width checkbox-group">
                            <input type="checkbox" name="modulo_cierre_caja" id="modulo_cierre_caja" value="1"
                                   <?php echo (isset($emp['modulo_cierre_caja']) && (int)$emp['modulo_cierre_caja']) ? 'checked' : ''; ?>>
                            <label for="modulo_cierre_caja">Módulo "Cierre de Caja" habilitado</label>
                            <div class="help-text">Si está habilitado, la empresa opera con cierres de caja. Si NO, opera sin cierres.</div>
                        </div>

                        <div class="form-group full-width">
                            <label>Logo de la Empresa</label>
                            <div class="logo-preview" id="logoPreview">
                                <?php if ($editando && !empty($emp['logo_path']) && file_exists('../' . $emp['logo_path'])): ?>
                                    <img src="<?php echo url($emp['logo_path']); ?>" alt="Logo actual">
                                    <div class="help-text" style="margin-top: 6px;">Logo actual</div>
                                <?php else: ?>
                                    <i class="fas fa-image" style="font-size: 2.5rem; color: #444;"></i>
                                    <div class="help-text" style="margin-top: 6px;">Sin logo</div>
                                <?php endif; ?>
                            </div>
                            <div class="logo-upload">
                                <input type="file" name="logo" class="input-field" accept="image/jpeg,image/png,image/gif,image/webp">
                                <div class="help-text">Formatos: JPG, PNG, GIF, WebP</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-<?php echo $editando ? 'save' : 'plus'; ?>"></i>
                            <?php echo $editando ? 'GUARDAR CAMBIOS' : 'CREAR EMPRESA'; ?>
                        </button>
                        <a href="<?php echo url('abm-empresas'); ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> CANCELAR
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Preview de logo antes de subir
        const logoInput = document.querySelector('input[name="logo"]');
        if (logoInput) {
            logoInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                const preview = document.getElementById('logoPreview');
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview"><div class="help-text" style="margin-top: 6px;">Vista previa</div>';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // Formatear CUIT automáticamente (al cargar, al escribir y al salir del campo)
        const cuitField = document.querySelector('input[name="cuit"]');
        if (cuitField) {
            const formatearCuitCampo = function() {
                const v = cuitField.value.replace(/\D/g, '');
                if (v.length === 11) {
                    cuitField.value = v.substring(0, 2) + '-' + v.substring(2, 10) + '-' + v.substring(10, 11);
                }
            };
            // Al cargar la página, normaliza un CUIT que venga sin guiones
            formatearCuitCampo();
            cuitField.addEventListener('blur', formatearCuitCampo);
            cuitField.addEventListener('input', function(e) {
                const v = e.target.value.replace(/\D/g, '').substring(0, 11);
                e.target.value = v;
            });
        }

        // Toggle sucursales
        function toggleSucursales(el) {
            const icon = el.querySelector('i.fa-chevron-right');
            const list = el.nextElementSibling;
            if (list) {
                list.classList.toggle('visible');
                if (icon) icon.classList.toggle('rotated');
            }
        }
    </script>
</body>
</html>