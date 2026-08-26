<?php
// Página de administración de proveedores autorizados (lista GLOBAL)
// Solo accesible para administradores
// Administra la tabla proveedores_autorizados (sin vincular a usuario).
// Cualquier visitante de la consulta remota verá únicamente estos proveedores.

include __DIR__ . '/infosesion.php';
require_once '../config/validar_permisos.php';

// Verificar que el usuario tenga permisos de administrador
if (!tiene_permiso('pages/abm_permisos_usuarios.php')) {
    header("Location: " . URL_BASE . "?error=acceso_denegado");
    exit();
}

// Usar la conexión PDO global ya establecida en infosesion.php
global $pdo;

$empresa_id = $_SESSION['empresa_id'];

$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proveedor = isset($_POST['proveedor']) ? trim($_POST['proveedor']) : '';
    $accion = isset($_POST['accion']) ? $_POST['accion'] : '';

    if ($proveedor && $accion) {
        try {
            if ($accion === 'agregar') {
                // Validar que el proveedor exista en productos
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE TRIM(proveedor) = :proveedor AND empresa_id = :empresa_id");
                $stmt_check->execute([':proveedor' => $proveedor, ':empresa_id' => $empresa_id]);
                
                if ((int)$stmt_check->fetchColumn() === 0) {
                    $mensaje = 'El proveedor no existe en el sistema de productos';
                    $tipo_mensaje = 'error';
                } else {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO proveedores_autorizados (proveedor_nombre, empresa_id) VALUES (:proveedor, :empresa_id)");
                    $stmt->execute([
                        ':proveedor' => $proveedor,
                        ':empresa_id' => $empresa_id
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $mensaje = 'Proveedor autorizado correctamente';
                        $tipo_mensaje = 'success';
                    } else {
                        $mensaje = 'El proveedor ya estaba autorizado';
                        $tipo_mensaje = 'error';
                    }
                }
            } elseif ($accion === 'eliminar') {
                $stmt = $pdo->prepare("DELETE FROM proveedores_autorizados WHERE proveedor_nombre = :proveedor AND empresa_id = :empresa_id");
                $stmt->execute([
                    ':proveedor' => $proveedor,
                    ':empresa_id' => $empresa_id
                ]);
                $mensaje = 'Proveedor desautorizado correctamente';
                $tipo_mensaje = 'success';
            }
        } catch (Exception $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

// Obtener proveedores autorizados (lista global)
$stmt_aut = $pdo->prepare("SELECT proveedor_nombre FROM proveedores_autorizados WHERE empresa_id = :empresa_id ORDER BY proveedor_nombre ASC");
$stmt_aut->execute([':empresa_id' => $empresa_id]);
$proveedores_autorizados = $stmt_aut->fetchAll(PDO::FETCH_COLUMN);

// Obtener todos los proveedores disponibles (para el selector de agregar)
$stmt_prov = $pdo->prepare("SELECT DISTINCT TRIM(proveedor) as proveedor_nombre FROM productos WHERE empresa_id = :empresa_id AND proveedor IS NOT NULL AND TRIM(proveedor) != '' ORDER BY proveedor_nombre ASC");
$stmt_prov->execute([':empresa_id' => $empresa_id]);
$todos_proveedores = $stmt_prov->fetchAll(PDO::FETCH_COLUMN);

// Proveedores aún no autorizados (para el dropdown)
$no_autorizados = array_values(array_diff($todos_proveedores, $proveedores_autorizados));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores Autorizados (Consulta Remota)</title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #1a1a1a;
            color: #fff;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #00bcd4;
        }
        .header h1 {
            margin: 0;
            color: #00bcd4;
            font-size: 1.8rem;
        }
        .btn-volver {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            background: #252525;
            color: #00bcd4;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 8px 16px;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-volver:hover {
            background: #00bcd4;
            color: #000;
            border-color: #00bcd4;
        }
        .card {
            background: #252525;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #aaa;
            font-size: 0.9rem;
        }
        .form-group select, .form-group input {
            width: 100%;
            padding: 10px;
            background: #1a1a1a;
            border: 1px solid #444;
            color: #fff;
            border-radius: 4px;
            font-size: 1rem;
        }
        .btn {
            padding: 10px 20px;
            background: #00bcd4;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
        }
        .btn:hover {
            background: #00acc1;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #2ecc71;
            color: #fff;
        }
        .alert-error {
            background: #e74c3c;
            color: #fff;
        }
        .proveedor-tag {
            display: inline-block;
            background: #00bcd4;
            color: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            margin: 5px;
            font-size: 0.9rem;
        }
        .proveedor-tag .eliminar {
            margin-left: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        .proveedor-tag .eliminar:hover {
            color: #e74c3c;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
        .badge-count {
            display: inline-block;
            background: #e74c3c;
            color: #fff;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 0.75rem;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>

    <div class="container">
        <div class="header">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <h1><i class="fas fa-user-shield"></i> Proveedores Autorizados (Consulta Remota)</h1>
                <a href="<?php echo route('dashboard'); ?>" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver al Dashboard</a>
            </div>
            <p style="color: #888; margin: 10px 0 0 0;">Administra la lista global de proveedores visibles en la consulta remota de consignaciones</p>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
        <?php endif; ?>

        <div class="grid">
            <!-- Formulario de autorización -->
            <div class="card">
                <h2 style="margin-top: 0; color: #00bcd4;">Autorizar Proveedor</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="proveedor">Proveedor a autorizar:</label>
                        <select id="proveedor" name="proveedor" required>
                            <option value="">-- Seleccionar Proveedor --</option>
                            <?php foreach ($no_autorizados as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="accion" value="agregar" class="btn">
                        <i class="fas fa-plus"></i> Autorizar Proveedor
                    </button>
                </form>
            </div>

            <!-- Lista de proveedores autorizados -->
            <div class="card">
                <h2 style="margin-top: 0; color: #00bcd4;">
                    Proveedores Autorizados
                    <span class="badge-count"><?php echo count($proveedores_autorizados); ?></span>
                </h2>
                <?php if (!empty($proveedores_autorizados)): ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($proveedores_autorizados as $prov): ?>
                    <span class="proveedor-tag">
                        <?php echo htmlspecialchars($prov); ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="proveedor" value="<?php echo htmlspecialchars($prov); ?>">
                            <input type="hidden" name="accion" value="eliminar">
                            <span class="eliminar" onclick="var f=this.parentElement; var go=function(){ f.submit(); }; if (window.confirmarAccion) { window.confirmarAccion('Desautorizar Proveedor', '¿Desautorizar este proveedor?', 'DESAUTORIZAR', 'btn-danger', go); } else if (confirm('¿Desautorizar este proveedor?')) { go(); }">×</span>
                        </form>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="color: #666; text-align: center; padding: 20px;">No hay proveedores autorizados. La consulta remota no mostrará ningún proveedor.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="background: #1a1a1a; border: 1px solid #444;">
            <h3 style="color: #f39c12; margin-top: 0;"><i class="fas fa-info-circle"></i> Información</h3>
            <p style="color: #aaa; line-height: 1.6;">
                <strong>¿Cómo funciona?</strong><br>
                1. Autorice los proveedores que deben estar disponibles en la consulta remota de consignaciones<br>
                2. Cualquier visitante de la página de consulta remota verá únicamente esta lista de proveedores<br>
                3. Si no se autoriza ningún proveedor, la consulta remota no mostrará ningún proveedor<br>
                4. Solo los proveedores que existen en el sistema de productos pueden ser autorizados
            </p>
        </div>
    </div>
    </div><!-- /.content -->
</body>
</html>
