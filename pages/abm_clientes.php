<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? $_GET['id'] : null;
$mensaje = '';
$cliente_editar = array();

$tipos_iva = [
    99 => 'Consumidor Final',
    1  => 'Responsable Inscripto',
    6  => 'Monotributo',
    4  => 'Exento'
];

/**
 * Valida el dígito verificador de un CUIT (módulo 11 AFIP).
 * @param string $cuit CUIT con 11 dígitos (sin guiones)
 * @return bool
 */
function validar_cuit_digito_verificador($cuit)
{
    $cuit = preg_replace('/[^0-9]/', '', $cuit);
    if (strlen($cuit) !== 11) {
        return false;
    }
    $mult = array(5, 4, 3, 2, 7, 6, 5, 4, 3, 2);
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += intval($cuit[$i]) * $mult[$i];
    }
    $resto = $sum % 11;
    $dv = ($resto === 0) ? 0 : (($resto === 1) ? 4 : (11 - $resto));
    return intval($cuit[10]) === $dv;
}

$nuevo_id_sugerido = '';
if ($accion === 'crear') {
    try {
        $stmt_id = $pdo->query("SELECT id FROM clientes WHERE empresa_id = " . (int)$empresa_id . " ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt_id->fetch();
        $nuevo_id_sugerido = $ultimo ? (intval($ultimo['id']) + 1) : 1;
    } catch (Exception $e) {
        $nuevo_id_sugerido = '';
    }
}

// ── POST: Eliminar (solo por POST con confirmación, nunca por GET) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_post']) && $_POST['accion_post'] === 'eliminar') {
    try {
        $id_eliminar = isset($_POST['id_cliente']) ? intval($_POST['id_cliente']) : 0;
        $stmt = $pdo->prepare('DELETE FROM clientes WHERE id = ? AND empresa_id = ?');
        $stmt->execute(array($id_eliminar, $empresa_id));
        $mensaje = "🗑️ Cliente eliminado correctamente.";
    } catch (Exception $e) {
        $mensaje = "❌ No se puede eliminar: El cliente tiene registros asociados.";
    }
    $accion = 'listar';
}

// ── POST: Crear / Editar ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_post']) && in_array($_POST['accion_post'], array('crear', 'editar'))) {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido']);
        $dni = trim($_POST['dni']);
        $id_tipo_iva = isset($_POST['id_tipo_iva']) ? intval($_POST['id_tipo_iva']) : 99;
        $cuit = trim($_POST['cuit']);
        $telefono = trim($_POST['telefono']);
        $direccion = trim($_POST['direccion']);
        $estado = isset($_POST['estado']) ? $_POST['estado'] : 'Activo';
        $habilita_cta = isset($_POST['habilita_cta']) ? $_POST['habilita_cta'] : 'No';
        $relacion = trim($_POST['relacion'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $localidad = trim($_POST['localidad'] ?? '');
        
        $id_post = isset($_POST['id_cliente']) ? $_POST['id_cliente'] : null;
        $accion_post = $_POST['accion_post'];

        if (empty($apellido)) {
            throw new Exception("El Apellido es obligatorio.");
        }

        if (!empty($cuit)) {
            $cuit_clean = preg_replace('/[^0-9]/', '', $cuit);
            if (strlen($cuit_clean) !== 11) {
                throw new Exception("El CUIT debe tener 11 dígitos (ej: 20-12345678-9).");
            }
            if (!validar_cuit_digito_verificador($cuit_clean)) {
                throw new Exception("El dígito verificador del CUIT es inválido. Verificá la numeración.");
            }
        }

        if ($accion_post === 'crear') {
            $id_a_insertar = $_POST['id_visual'];
            
            if (!empty($cuit)) {
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE empresa_id = ? AND cuit = ?");
                $stmt_check->execute([$empresa_id, $cuit]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("Ya existe un cliente con ese CUIT en esta empresa.");
                }
            }
            
            $sql = "INSERT INTO clientes (id, nombre, apellido, dni, id_tipo_iva, cuit, telefono, direccion, email, localidad, estado, habilita_cta, relacion, empresa_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($id_a_insertar, $nombre, $apellido, $dni, $id_tipo_iva, $cuit, $telefono, $direccion, $email, $localidad, $estado, $habilita_cta, $relacion, $empresa_id));
            $mensaje = "✅ Cliente #$id_a_insertar registrado con éxito.";
            $accion = 'listar';
        } elseif ($accion_post === 'editar' && $id_post) {
            if (!empty($cuit)) {
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE empresa_id = ? AND cuit = ? AND id != ?");
                $stmt_check->execute([$empresa_id, $cuit, $id_post]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("Ya existe otro cliente con ese CUIT en esta empresa.");
                }
            }
            
            $sql = "UPDATE clientes SET nombre=?, apellido=?, dni=?, id_tipo_iva=?, cuit=?, telefono=?, direccion=?, email=?, localidad=?, estado=?, habilita_cta=?, relacion=? WHERE id=? AND empresa_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($nombre, $apellido, $dni, $id_tipo_iva, $cuit, $telefono, $direccion, $email, $localidad, $estado, $habilita_cta, $relacion, $id_post, $empresa_id));
            $mensaje = "✅ Datos del cliente actualizados.";
            $accion = 'listar';
        }
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

if ($accion === 'editar' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ? AND empresa_id = ?');
    $stmt->execute(array($id, $empresa_id));
    $cliente_editar = $stmt->fetch();
}

$clientes = array();
$clientes_count = 0;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$por_sel = isset($_GET['por']) ? intval($_GET['por']) : 50;
$por_pagina = in_array($por_sel, array(25, 50, 100)) ? $por_sel : 50;
$offset = ($pagina - 1) * $por_pagina;

if ($accion === 'listar') {
    $where = array();
    $params = array();
    
    $filtro_buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    $filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
    $filtro_iva = isset($_GET['iva']) ? $_GET['iva'] : '';
    $filtro_cta = isset($_GET['cta_cte']) ? $_GET['cta_cte'] : '';
    $filtro_letra = isset($_GET['letra']) ? strtoupper(trim($_GET['letra'])) : '';
    
    if ($filtro_buscar) {
        $where[] = "(apellido LIKE ? OR nombre LIKE ? OR cuit LIKE ? OR dni LIKE ? OR id = ?) AND empresa_id = ?";
        $like = "%$filtro_buscar%";
        $params = array_merge($params, [$like, $like, $like, $like, is_numeric($filtro_buscar) ? $filtro_buscar : 0, $empresa_id]);
    } else {
        $where[] = "empresa_id = ?";
        $params[] = $empresa_id;
    }
    if ($filtro_estado) {
        $where[] = "estado = ?";
        $params[] = $filtro_estado;
    }
    if ($filtro_iva) {
        $where[] = "id_tipo_iva = ?";
        $params[] = $filtro_iva;
    }
    if ($filtro_cta) {
        $where[] = "habilita_cta = ?";
        $params[] = $filtro_cta;
    }
    if ($filtro_letra && preg_match('/^[A-Z]$/', $filtro_letra)) {
        $where[] = "apellido LIKE ?";
        $params[] = $filtro_letra . '%';
    }
    
    $where_sql = 'WHERE ' . implode(' AND ', $where);
    
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM clientes $where_sql");
    $stmt_count->execute($params);
    $clientes_count = $stmt_count->fetchColumn();
    
    $total_paginas = max(1, ceil($clientes_count / $por_pagina));
    
    $stmt = $pdo->prepare("
        SELECT c.*, 
            (SELECT COALESCE(SUM(debe), 0) - COALESCE(SUM(haber), 0) FROM ctacte WHERE id_cliente = c.id AND empresa_id = ?) as saldo_cc
        FROM clientes c 
        $where_sql 
        ORDER BY c.id DESC 
        LIMIT $por_pagina OFFSET $offset
    ");
    $params[] = $empresa_id;
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();
}

$ventas_cliente = array();
if ($accion === 'listar' && $id && isset($_GET['accion']) && $_GET['accion'] === 'ver_ventas') {
    $stmt_v = $pdo->prepare("SELECT n_documento, total_venta, cond_pago, fecha_venta, estado FROM ventas WHERE id_cliente = ? AND empresa_id = ? ORDER BY fecha_venta DESC LIMIT 10");
    $stmt_v->execute(array($id, $empresa_id));
    $ventas_cliente = $stmt_v->fetchAll();
    $stmt_n = $pdo->prepare("SELECT apellido, nombre FROM clientes WHERE id = ? AND empresa_id = ?");
    $stmt_n->execute(array($id, $empresa_id));
    $nombre_cliente = $stmt_n->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Clientes | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css?v=' . time()); ?>">
    <style>
        /* ===== FILTROS COMPACTOS ===== */
        .filtros-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: stretch;
            margin-bottom: 20px;
            padding: 10px 14px;
            background: #1a1a1a;
            border-radius: 8px;
            border: 1px solid #2a2a2a;
        }
        .filtros-bar input,
        .filtros-bar select,
        .filtros-bar .btn-filtro,
        .filtros-bar .btn-export,
        .filtros-bar .view-toggle {
            border: 1px solid #3a3a3a;
            background: #222;
            color: #ccc;
            border-radius: 4px;
            font-size: 0.78em;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
            height: 30px;
            box-sizing: border-box;
        }
        .filtros-bar input:focus,
        .filtros-bar select:focus {
            border-color: #00bcd4;
        }
        .filtros-bar input[type="text"] { 
            flex: 1.3; 
            min-width: 180px; 
            max-width: 300px; 
            padding: 0 10px;
        }
        .filtros-bar select { 
            min-width: 90px; 
            max-width: 130px; 
            padding: 0 6px;
        }
        .filtros-bar .btn-filtro {
            padding: 0 10px;
            background: #2a2a2a;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            text-decoration: none;
            line-height: 30px;
        }
        .filtros-bar .btn-filtro:hover { 
            background: #333; 
            color: #fff;
            border-color: #555;
        }
        .filtros-bar .btn-export {
            padding: 0 10px;
            background: #27ae60;
            color: #fff;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            line-height: 30px;
            text-decoration: none;
        }
        .filtros-bar .btn-export:hover { 
            background: #2ecc71; 
        }
        .filtros-bar .view-toggle {
            display: inline-flex;
            align-items: center;
            padding: 2px;
            gap: 2px;
            background: #222;
            border: 1px solid #3a3a3a;
            height: 30px;
        }
        .filtros-bar .view-toggle button {
            height: 24px;
            padding: 0 8px;
            border: none;
            background: transparent;
            color: #888;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.78em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .filtros-bar .view-toggle button.active {
            background: #00bcd4;
            color: #fff;
        }
        .filtros-bar .view-toggle button:hover:not(.active) { 
            color: #ccc; 
        }

        /* ===== VISTA CARDS ===== */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #222;
            border-radius: 8px;
            padding: 3px;
        }
        .view-toggle button {
            padding: 6px 12px;
            background: transparent;
            border: none;
            color: #888;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.2s;
        }
        .view-toggle button.active {
            background: #00bcd4;
            color: #fff;
        }
        .view-toggle button:hover:not(.active) { color: #ccc; }

        /* ===== CARDS DE CLIENTES ===== */
        .clientes-cards {
            display: none;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .clientes-cards.active { display: grid; }
        .clientes-table.active { display: table; }
        .clientes-table { display: none; }
        .clientes-table.active { display: table; }

        .cliente-card {
            background: #1e1e1e;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 18px 20px;
            transition: all 0.25s ease;
            position: relative;
        }
        .cliente-card:hover {
            border-color: #444;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        .cliente-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .cliente-card .card-name {
            font-weight: 600;
            font-size: 1em;
            color: #fff;
        }
        .cliente-card .card-id {
            color: #666;
            font-size: 0.8em;
        }
        .cliente-card .card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            font-size: 0.82em;
            color: #aaa;
            margin: 10px 0;
        }
        .cliente-card .card-details span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .cliente-card .card-details i {
            width: 14px;
            color: #666;
        }
        .cliente-card .card-actions {
            display: flex;
            gap: 6px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #2a2a2a;
        }
        .cliente-card .card-actions a {
            flex: 1;
            text-align: center;
            padding: 6px;
            border-radius: 6px;
            font-size: 0.78em;
            text-decoration: none;
            transition: all 0.2s;
        }

        /* ===== SALDO CC ===== */
        .saldo-deudor { color: #e74c3c; font-weight: bold; }   /* deudor: negativo, en rojo */
        .saldo-acreedor { color: #3498db; font-weight: bold; } /* acreedor: positivo, en azul */
        .saldo-cero { color: #4caf50; font-weight: bold; }

        /* ===== BOTÓN WHATSAPP ===== */
        .btn-whatsapp {
            color: #25D366 !important;
            font-size: 1.1em;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-whatsapp:hover {
            background: rgba(37, 211, 102, 0.1);
            transform: scale(1.15);
        }

        /* ===== PAGINACIÓN ===== */
        .paginacion {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .paginacion a, .paginacion span {
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85em;
            background: #222;
            color: #aaa;
            transition: all 0.2s;
        }
        .paginacion a:hover { background: #333; color: #fff; }
        .paginacion .current { background: #00bcd4; color: #fff; }

        /* ===== MODAL VENTAS ===== */
        .modal-ventas-cliente .modal-content { 
            max-width: 600px; 
            max-height: 80vh;
            overflow-y: auto;
            margin: 5vh auto;
        }
        .modal-ventas-cliente table {
            font-size: 0.85em;
        }
        .modal-ventas-cliente table th {
            padding: 8px 10px;
            font-size: 0.8em;
        }
        .modal-ventas-cliente table td {
            padding: 6px 10px;
        }

        /* ===== FORMULARIO COMPACTO ===== */
        .form-seccion {
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #2a2a2a;
        }
        .form-seccion:last-child { border-bottom: none; margin-bottom: 0; }
        .form-seccion h3 {
            color: #00bcd4;
            font-size: 0.78em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .form-seccion h3 i { font-size: 0.9em; }
        .flex-row { display: flex; gap: 10px; margin-bottom: 8px; }
        .flex-row > div { flex: 1; }
        label { display: block; margin-bottom: 2px; color: #3498db; font-weight: 600; font-size: 0.75em; }
        input, select { width: 100%; padding: 6px 8px; border-radius: 4px; border: 1px solid #444; background: #222; color: #fff; box-sizing: border-box; font-size: 0.82em; }
        .input-readonly { background: #1a1a1a; color: #2ecc71; font-weight: bold; border: 1px dashed #27ae60; font-size: 0.82em; }
        .badge-cta { background: #f1c40f; color: #000; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }

        /* ===== BOTÓN EXPORTAR ===== */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #27ae60;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            transition: background 0.2s;
            text-decoration: none;
        }
        .btn-export:hover { background: #2ecc71; }
        
        /* ===== ESTILOS DE TABLA ALINEADOS CON REPORTE ===== */
        .card { background: #1e1e1e; border-radius: 12px; border: 1px solid #333; padding: 20px; }
        table { border-collapse: separate; border-spacing: 0 6px; width: 100%; }
        table thead th { color: var(--accent); text-transform: uppercase; font-size: 0.75em; letter-spacing: 1px; padding: 10px 8px; text-align: left; white-space: nowrap; font-weight: bold; }
        table tbody tr { background: #252525; transition: 0.3s; }
        table tbody tr:hover { background: #2a2a2a; }
        table tbody td { padding: 10px 8px; border-top: 1px solid #333; border-bottom: 1px solid #333; color: #ccc; font-size: 0.9em; }
        table tbody td:first-child { border-left: 1px solid #333; border-radius: 8px 0 0 8px; }
        table tbody td:last-child { border-right: 1px solid #333; border-radius: 0 8px 8px 0; }
        
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; color: #fff; }
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-warning { color: var(--warning); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h1>👥 Gestión de Clientes</h1>
            <div style="display: flex; gap: 8px; align-items: center;">
                <?php if ($accion === 'listar'): ?>
                    <a href="<?php echo URL_BASE; ?>clientes?accion=crear" class="btn btn-success">+ Nuevo Cliente</a>
                <?php endif; ?>
                <?php if (tiene_permiso('pages/cuentas_corrientes.php')): ?>
                    <a href="<?php echo route_file('pages/cuentas_corrientes.php'); ?>" class="btn btn-info" title="Ir a Cuenta Corriente de Clientes" style="display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-user-clock"></i> Cta. Cte. Clientes
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo strpos($mensaje, '❌') !== false ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <!-- ===== FILTROS AVANZADOS ===== -->
            <form method="GET" action="<?php echo URL_BASE; ?>clientes" class="filtros-bar">
                <input type="hidden" name="accion" value="listar">
                <input type="text" id="filtro-clientes" name="buscar" placeholder="🔍 Buscar por nombre, ID, CUIT..." value="<?php echo htmlspecialchars($filtro_buscar ?? ''); ?>" autocomplete="off">
                
                <select name="estado">
                    <option value="">Todos los estados</option>
                    <option value="Activo" <?php echo ($filtro_estado ?? '') === 'Activo' ? 'selected' : ''; ?>>Activos</option>
                    <option value="Inactivo" <?php echo ($filtro_estado ?? '') === 'Inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                </select>
                
                <select name="iva">
                    <option value="">Todos los IVA</option>
                    <?php foreach ($tipos_iva as $id_iva => $label_iva): ?>
                        <option value="<?php echo $id_iva; ?>" <?php echo ($filtro_iva ?? '') == $id_iva ? 'selected' : ''; ?>><?php echo $label_iva; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="cta_cte">
                    <option value="">Cta. Cte.</option>
                    <option value="Si" <?php echo ($filtro_cta ?? '') === 'Si' ? 'selected' : ''; ?>>Habilitada</option>
                    <option value="No" <?php echo ($filtro_cta ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                </select>

                <select name="letra" title="Filtrar por inicial del apellido">
                    <option value="">A-Z</option>
                    <?php foreach (range('A', 'Z') as $letra_i): ?>
                        <option value="<?php echo $letra_i; ?>" <?php echo ($filtro_letra ?? '') === $letra_i ? 'selected' : ''; ?>><?php echo $letra_i; ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="por" title="Clientes por página">
                    <option value="25" <?php echo (int)$por_pagina === 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo (int)$por_pagina === 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo (int)$por_pagina === 100 ? 'selected' : ''; ?>>100</option>
                </select>

                <button type="submit" class="btn-filtro"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="<?php echo URL_BASE; ?>clientes" class="btn-filtro" title="Limpiar filtros"><i class="fas fa-times"></i></a>

                <div style="margin-left: auto; display: flex; gap: 8px; align-items: center;">
                    <a href="<?php echo URL_BASE; ?>ajax/exportar_clientes_csv.php<?php echo !empty($_GET) ? '?' . http_build_query($_GET) : ''; ?>" class="btn-export" title="Exportar todos los filtrados a CSV"><i class="fas fa-file-csv"></i> CSV</a>
                    <div class="view-toggle">
                        <button type="button" class="active" id="viewTable" onclick="cambiarVista('table')" title="Vista tabla">
                            <i class="fas fa-table"></i>
                        </button>
                        <button type="button" id="viewCards" onclick="cambiarVista('cards')" title="Vista tarjetas">
                            <i class="fas fa-id-card"></i>
                        </button>
                    </div>
                </div>
            </form>

            <!-- ===== VISTA TABLA ===== -->
            <div class="card">
                <div style="overflow-x: auto;">
                    <table id="tablaClientes" class="clientes-table active">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Apellido y Nombre</th>
                                <th>CUIT/DNI</th>
                                <th>IVA</th>
                                <th>Teléfono</th>
                                <th>Cta. Cte.</th>
                                <th class="text-right">Saldo CC</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $c): 
                                $saldo = floatval($c['saldo_cc'] ?? 0);
                                $tiene_wsp = !empty($c['telefono']);
                            ?>
                            <tr>
                                <td><span style="color: #666;">#<?php echo $c['id']; ?></span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['apellido']); if(!empty($c['nombre'])) echo ', ' . htmlspecialchars($c['nombre']); ?></strong>
                                    <?php if ($c['estado'] === 'Inactivo'): ?>
                                        <span style="color: #e74c3c; font-size: 0.75em;">(Inactivo)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($c['cuit'] ?: ($c['dni'] ?: '---')); ?></td>
                                <td><small><?php echo $tipos_iva[$c['id_tipo_iva']] ?? 'CF'; ?></small></td>
                                <td>
                                    <?php echo htmlspecialchars($c['telefono'] ? $c['telefono'] : '---'); ?>
                                    <?php if ($tiene_wsp): ?>
                                        <a href="https://wa.me/54<?php echo preg_replace('/[^0-9]/', '', $c['telefono']); ?>" target="_blank" class="btn-whatsapp" title="Enviar WhatsApp">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(strtoupper(trim($c['habilita_cta'])) === 'SI'): ?>
                                        <span class="badge-cta">Habilitada</span>
                                    <?php else: ?>
                                        <span style="color: #666;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <?php if ($saldo > 0): ?>
                                        <span class="saldo-deudor">-$<?php echo number_format($saldo, 2, ',', '.'); ?></span>
                                    <?php elseif ($saldo < 0): ?>
                                        <span class="saldo-acreedor">+$<?php echo number_format(abs($saldo), 2, ',', '.'); ?></span>
                                    <?php else: ?>
                                        <span class="saldo-cero">$0,00</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <a href="<?php echo URL_BASE; ?>clientes?accion=editar&id=<?php echo (int)$c['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                    <a href="#" onclick='verVentas(<?php echo (int)$c['id']; ?>, <?php echo json_encode($c['apellido'] . ', ' . $c['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>); return false;' class="btn btn-info btn-sm" title="Ver ventas"><i class="fas fa-receipt"></i></a>
                                    <form method="POST" class="form-eliminar" style="display:inline;" onsubmit="return confirmarEliminar(this);">
                                        <input type="hidden" name="accion_post" value="eliminar">
                                        <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Eliminar cliente"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($clientes)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#666;">No se encontraron clientes.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- ===== VISTA CARDS ===== -->
                    <div class="clientes-cards" id="clientesCards">
                        <?php foreach ($clientes as $c): 
                            $saldo = floatval($c['saldo_cc'] ?? 0);
                            $tiene_wsp = !empty($c['telefono']);
                        ?>
                        <div class="cliente-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-name"><?php echo htmlspecialchars($c['apellido'] . ', ' . $c['nombre']); ?></div>
                                    <div class="card-id">#<?php echo $c['id']; ?></div>
                                </div>
                                <?php if ($c['estado'] === 'Inactivo'): ?>
                                    <span style="color: #e74c3c; font-size: 0.75em;">Inactivo</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-details">
                                <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($c['cuit'] ?: ($c['dni'] ?: '---')); ?></span>
                                <span><i class="fas fa-tag"></i> <?php echo $tipos_iva[$c['id_tipo_iva']] ?? 'CF'; ?></span>
                                                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($c['telefono'] ?: '---'); ?></span>
                                <span><i class="fa fa-envelope"></i> <?php echo htmlspecialchars($c['email'] ?: '---'); ?></span>
                                <span><i class="fa fa-map-marker-alt"></i> <?php echo htmlspecialchars($c['localidad'] ?: '---'); ?></span>
                                <span><i class="fas fa-credit-card"></i> <?php echo strtoupper(trim($c['habilita_cta'])) === 'SI' ? 'Cta. Cte.' : 'Contado'; ?></span>
                                <span style="grid-column: span 2;">
                                    <i class="fas fa-dollar-sign"></i> 
                                    <?php if ($saldo > 0): ?>
                                        <span class="saldo-deudor">Debe -$<?php echo number_format($saldo, 2, ',', '.'); ?></span>
                                    <?php elseif ($saldo < 0): ?>
                                        <span class="saldo-acreedor">A favor +$<?php echo number_format(abs($saldo), 2, ',', '.'); ?></span>
                                    <?php else: ?>
                                        <span class="saldo-cero">Sin deuda</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="card-actions">
                                <a href="<?php echo URL_BASE; ?>clientes?accion=editar&id=<?php echo (int)$c['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Editar</a>
                                <a href="#" onclick='verVentas(<?php echo (int)$c['id']; ?>, <?php echo json_encode($c['apellido'] . ', ' . $c['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>); return false;' class="btn btn-info btn-sm"><i class="fas fa-receipt"></i> Ventas</a>
                                <form method="POST" class="form-eliminar" style="display:inline;" onsubmit="return confirmarEliminar(this);">
                                    <input type="hidden" name="accion_post" value="eliminar">
                                    <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Eliminar cliente"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php if ($tiene_wsp): ?>
                                    <a href="https://wa.me/54<?php echo preg_replace('/[^0-9]/', '', $c['telefono']); ?>" target="_blank" class="btn btn-success btn-sm"><i class="fab fa-whatsapp"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ===== PAGINACIÓN ===== -->
            <?php if ($total_paginas > 1): ?>
            <div class="paginacion">
                <?php if ($pagina > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $pagina - 3); $i <= min($total_paginas, $pagina + 3); $i++): ?>
                    <?php if ($i == $pagina): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($pagina < $total_paginas): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="resultados-resumen" style="display:flex; justify-content:space-between; align-items:center; color:#888; font-size:0.85em; margin-top:8px;">
                <span>📋 <strong><?php echo $clientes_count; ?></strong> cliente(s)</span>
                <?php if ($total_paginas > 1): ?>
                    <span>Página <?php echo $pagina; ?>/<?php echo $total_paginas; ?> · Mostrando <?php echo count($clientes); ?></span>
                <?php endif; ?>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <div class="card" style="max-width: 700px; margin: 0 auto; padding: 15px 20px;">
                <h2 style="font-size:1.1em; margin:0;"><?php echo ($accion === 'crear') ? 'Registrar Nuevo Cliente' : 'Modificar Cliente'; ?></h2>
                <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                
                <form method="POST" action="<?php echo URL_BASE; ?>clientes">
                    <input type="hidden" name="accion_post" value="<?php echo $accion; ?>">
                    <input type="hidden" name="id_cliente" value="<?php echo isset($cliente_editar['id']) ? $cliente_editar['id'] : ''; ?>">

                    <!-- Datos Personales -->
                    <div class="form-seccion">
                        <h3><i class="fas fa-user"></i> Datos Personales</h3>
                        <div class="flex-row">
                            <div style="flex: 0.3;">
                                <label>N° Cliente</label>
                                <input type="text" name="id_visual" readonly class="input-readonly" 
                                    value="<?php echo ($accion === 'crear') ? $nuevo_id_sugerido : $cliente_editar['id']; ?>">
                            </div>
                            <div style="flex: 0.8;">
                                <label>Apellido*</label>
                                <input type="text" name="apellido" required value="<?php echo isset($cliente_editar['apellido']) ? htmlspecialchars($cliente_editar['apellido']) : ''; ?>" placeholder="Obligatorio">
                            </div>
                            <div style="flex: 0.8;">
                                <label>Nombre</label>
                                <input type="text" name="nombre" value="<?php echo isset($cliente_editar['nombre']) ? htmlspecialchars($cliente_editar['nombre']) : ''; ?>" placeholder="Nombre del cliente">
                            </div>
                        </div>
                        <div class="flex-row">
                            <div><label>Teléfono</label><input type="text" name="telefono" value="<?php echo isset($cliente_editar['telefono']) ? htmlspecialchars($cliente_editar['telefono']) : ''; ?>" placeholder="Ej: 11 1234-5678"></div>
                            <div><label>Dirección</label><input type="text" name="direccion" value="<?php echo isset($cliente_editar['direccion']) ? htmlspecialchars($cliente_editar['direccion']) : ''; ?>" placeholder="Calle y número"></div>
                        </div>
                        <div class="flex-row">
                            <div><label>Email</label><input type="email" name="email" value="<?php echo isset($cliente_editar['email']) ? htmlspecialchars($cliente_editar['email']) : ''; ?>" placeholder="cliente@ejemplo.com"></div>
                            <div><label>Localidad</label><input type="text" name="localidad" value="<?php echo isset($cliente_editar['localidad']) ? htmlspecialchars($cliente_editar['localidad']) : ''; ?>" placeholder="Ciudad / Localidad"></div>
                        </div>
                    </div>

                    <!-- Datos Fiscales -->
                    <div class="form-seccion">
                        <h3><i class="fas fa-file-invoice"></i> Datos Fiscales</h3>
                        <div class="flex-row">
                            <div style="flex: 0.6;">
                                <label>DNI</label>
                                <input type="text" name="dni" value="<?php echo isset($cliente_editar['dni']) ? htmlspecialchars($cliente_editar['dni']) : ''; ?>">
                            </div>
                            <div style="flex: 1;">
                                <label>Condición IVA (ARCA)</label>
                                <select name="id_tipo_iva">
                                    <?php foreach ($tipos_iva as $id_iva => $label_iva): ?>
                                        <option value="<?php echo $id_iva; ?>" <?php echo (isset($cliente_editar['id_tipo_iva']) && $cliente_editar['id_tipo_iva'] == $id_iva) ? 'selected' : ($id_iva == 99 ? 'selected' : ''); ?>>
                                            <?php echo $label_iva; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="flex-row">
                            <div style="flex: 0.6;">
                                <label>CUIT</label>
                                <input type="text" name="cuit" value="<?php echo isset($cliente_editar['cuit']) ? htmlspecialchars($cliente_editar['cuit']) : ''; ?>" placeholder="11-11111111-1" id="cuitInput">
                            </div>
                            <div style="flex: 1;">
                                <label>Relación / Nota</label>
                                <input type="text" name="relacion" value="<?php echo isset($cliente_editar['relacion']) ? htmlspecialchars($cliente_editar['relacion']) : ''; ?>" placeholder="Ej: Cliente frecuente">
                            </div>
                        </div>
                    </div>

                    <!-- Configuración -->
                    <div class="form-seccion">
                        <h3><i class="fas fa-cog"></i> Configuración</h3>
                        <div class="flex-row">
                            <div>
                                <label>Estado</label>
                                <select name="estado">
                                    <option value="Activo" <?php echo (isset($cliente_editar['estado']) && $cliente_editar['estado'] == 'Activo') ? 'selected' : ''; ?>>Activo</option>
                                    <option value="Inactivo" <?php echo (isset($cliente_editar['estado']) && $cliente_editar['estado'] == 'Inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                                </select>
                            </div>
                            <div>
                                <label>Cuenta Corriente</label>
                                <select name="habilita_cta" style="border-color: #f1c40f;">
                                    <option value="No" <?php echo (isset($cliente_editar['habilita_cta']) && strtoupper($cliente_editar['habilita_cta']) == 'NO') ? 'selected' : ''; ?>>Deshabilitada</option>
                                    <option value="Si" <?php echo (isset($cliente_editar['habilita_cta']) && strtoupper($cliente_editar['habilita_cta']) == 'SI') ? 'selected' : ''; ?>>Habilitada</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="flex: 2; padding: 14px;"><i class="fas fa-save"></i> Guardar Cliente</button>
                        <a href="<?php echo URL_BASE; ?>clientes" class="btn btn-secondary" style="flex: 1; text-align: center; padding: 14px;">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Historial de Ventas -->
    <div id="modalVentasCliente" class="modal modal-ventas-cliente" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <h2 id="modalVentasTitulo" style="color: #00bcd4; margin-top: 0;">Ventas del Cliente</h2>
            <div id="modalVentasBody" style="color: #eee;">
                <p style="color: #888;">Cargando ventas...</p>
            </div>
            <div style="margin-top: 20px; text-align: right;">
                <button onclick="document.getElementById('modalVentasCliente').style.display='none'" class="btn btn-secondary">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
    // ===== BÚSQUEDA EN VIVO (debounce) =====
    document.addEventListener('DOMContentLoaded', function() {
        var inputF = document.getElementById('filtro-clientes');
        if (inputF) {
            var timerBuscar = null;
            inputF.addEventListener('input', function() {
                clearTimeout(timerBuscar);
                var form = inputF.closest('form');
                if (form) {
                    timerBuscar = setTimeout(function() {
                        form.submit();
                    }, 600);
                }
            });
            inputF.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timerBuscar);
                    inputF.closest('form').submit();
                }
            });
        }
    });

    // ===== CAMBIAR VISTA =====
    function cambiarVista(vista) {
        document.getElementById('viewTable').classList.toggle('active', vista === 'table');
        document.getElementById('viewCards').classList.toggle('active', vista === 'cards');
        
        var tabla = document.getElementById('tablaClientes');
        var cards = document.getElementById('clientesCards');
        
        if (vista === 'table') {
            tabla.classList.add('active');
            cards.classList.remove('active');
        } else {
            tabla.classList.remove('active');
            cards.classList.add('active');
        }
        
        // Guardar preferencia
        localStorage.setItem('clientes_vista', vista);
    }

    // Cargar vista guardada
    document.addEventListener('DOMContentLoaded', function() {
        var vistaGuardada = localStorage.getItem('clientes_vista');
        if (vistaGuardada) {
            cambiarVista(vistaGuardada);
        }
    });

    // Variables para navegación del modal
    var _modalClienteId = null;
    var _modalClienteNombre = null;

    // ===== MODAL VENTAS =====
    function verVentas(clienteId, nombreCliente) {
        _modalClienteId = clienteId;
        _modalClienteNombre = nombreCliente;
        
        var modal = document.getElementById('modalVentasCliente');
        var titulo = document.getElementById('modalVentasTitulo');
        var body = document.getElementById('modalVentasBody');
        
        titulo.innerText = 'Ventas de ' + nombreCliente;
        body.innerHTML = '<p style="color: #888;">Cargando ventas...</p>';
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        
        // Fetch AJAX
        fetch('<?php echo URL_BASE; ?>ajax/buscar_ventas_cliente_ajax.php?id_cliente=' + clienteId)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    body.innerHTML = '<p style="color: #e74c3c;">' + data.error + '</p>';
                    return;
                }
                if (data.length === 0) {
                    body.innerHTML = '<p style="color: #888; text-align: center;">Este cliente no tiene ventas registradas.</p>';
                    return;
                }
                var html = '<table style="width:100%; border-collapse: collapse; font-size:0.85em;">';
                html += '<thead><tr style="border-bottom: 1px solid #333;"><th>Ticket</th><th>Fecha</th><th>Total</th><th>Pago</th><th>Estado</th><th></th></tr></thead><tbody>';
                data.forEach(function(v) {
                    var estadoColor = v.estado === 'Finalizada' ? '#4caf50' : '#e74c3c';
                    var pagoLabel = v.cond_pago ? v.cond_pago : 'Contado';
                    html += '<tr style="border-bottom: 1px solid #252525;">';
                    html += '<td>#' + v.n_documento + '</td>';
                    html += '<td style="font-size:0.8em;">' + v.fecha_venta + '</td>';
                    html += '<td><strong style="color: #00bcd4;">$' + parseFloat(v.total_venta).toLocaleString('es-AR', {minimumFractionDigits: 2}) + '</strong></td>';
                    html += '<td style="font-size:0.8em;">' + pagoLabel + '</td>';
                    html += '<td style="color:' + estadoColor + '; font-size:0.8em;">' + v.estado + '</td>';
                    html += '<td><a href="#" onclick="verDetalleVenta(' + v.n_documento + '); return false;" style="color:#00bcd4; font-size:0.8em; text-decoration:none;" title="Ver detalle"><i class="fas fa-search-plus"></i></a></td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            })
            .catch(err => {
                body.innerHTML = '<p style="color: #e74c3c;">Error al cargar ventas.</p>';
            });
    }

    // ===== VER DETALLE DE VENTA =====
    function verDetalleVenta(nDocumento) {
        var modal = document.getElementById('modalVentasCliente');
        var titulo = document.getElementById('modalVentasTitulo');
        var body = document.getElementById('modalVentasBody');
        
        titulo.innerText = 'Detalle de Venta #' + nDocumento;
        body.innerHTML = '<p style="color: #888;">Cargando detalle...</p>';
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        
        fetch('<?php echo URL_BASE; ?>ajax/obtener_detalle_venta.php?n_documento=' + nDocumento)
            .then(response => response.text())
            .then(html => {
                // Agregar botón volver después del contenido
                html += '<div style="margin-top:15px; display:flex; gap:8px;">';
                html += '<button onclick=\'verVentas(' + _modalClienteId + ', ' + JSON.stringify(_modalClienteNombre).replace(/'/g, "&#39;") + ')\' class="btn btn-secondary" style="flex:1;"><i class="fas fa-arrow-left"></i> Volver a ventas</button>';
                html += '<button onclick="document.getElementById(\'modalVentasCliente\').style.display=\'none\'" class="btn btn-secondary" style="flex:1;">Cerrar</button>';
                html += '</div>';
                body.innerHTML = html;
            })
            .catch(err => {
                body.innerHTML = '<p style="color: #e74c3c;">Error al cargar el detalle.</p>';
            });
    }

    // ===== CONFIRMAR ELIMINACIÓN (formulario POST seguro) =====
    function confirmarEliminar(form) {
        confirmarAccion(
            'Eliminar Cliente',
            '¿Estás seguro de eliminar a este cliente? Se perderán sus datos de contacto.',
            'ELIMINAR',
            'btn-danger',
            function() { form.submit(); }
        );
        return false;
    }

    // ===== AUTO-FORMATO CUIT =====
    document.addEventListener('DOMContentLoaded', function() {
        var cuitInput = document.getElementById('cuitInput');
        if (cuitInput) {
            cuitInput.addEventListener('input', function() {
                // Tomar solo dígitos y limitar a 11
                var digits = this.value.replace(/[^0-9]/g, '');
                if (digits.length > 11) {
                    digits = digits.substring(0, 11);
                }
                var formatted = digits;
                if (digits.length > 2) {
                    formatted = digits.substring(0, 2) + '-' + digits.substring(2);
                }
                if (digits.length > 10) {
                    formatted = digits.substring(0, 2) + '-' + digits.substring(2, 10) + '-' + digits.substring(10);
                }
                this.value = formatted;
            });
        }
    });
    </script>
</body>
</html>