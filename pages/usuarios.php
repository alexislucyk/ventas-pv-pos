<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
restringirPagina('developer');
require '../config/db_config.php';


$mensaje = '';

// PROCESAR ACCIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Crear nuevo usuario
    if (isset($_POST['nuevo_usuario'])) {
        $user = trim($_POST['usuario']);
        $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $rol  = $_POST['rol'];
        
        try {
            $sql = "INSERT INTO usuarios (usuario, password_hash, rol, estado) VALUES (?, ?, ?, 'ACTIVO')";
            $pdo->prepare($sql)->execute([$user, $pass, $rol]);
            $mensaje = "<div class='alert success'>✅ Usuario '$user' registrado correctamente.</div>";
        } catch (Exception $e) { 
            $mensaje = "<div class='alert danger'>❌ Error: El usuario ya existe.</div>"; 
        }
    }

    // 2. Cambiar Estado
    if (isset($_POST['toggle_estado'])) {
        $id_u = (int)$_POST['id_usuario'];
        $estado_actual = $_POST['estado_actual'];
        $nuevo_estado = ($estado_actual === 'ACTIVO') ? 'INACTIVO' : 'ACTIVO';

        if ($id_u !== 1) { // Protegemos al usuario principal
            $sql = "UPDATE usuarios SET estado = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([$nuevo_estado, $id_u]);
        }
    }
}

$usuarios = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seguridad | Electricidad Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --accent: #00bcd4;
            --bg-card: #1e1e1e;
            --border: #333;
        }

        .user-container { padding: 20px; max-width: 1200px; margin: 0 auto; }
        
        /* Card de Registro */
        .card-admin {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: flex-end;
        }

        .input-group { display: flex; flex-direction: column; gap: 8px; }
        .input-group label { color: #aaa; font-size: 0.9rem; font-weight: 500; }
        
        .input-dark {
            background: #2a2a2a;
            border: 1px solid #444;
            color: white;
            padding: 12px;
            border-radius: 6px;
            outline: none;
            transition: border 0.3s;
        }
        .input-dark:focus { border-color: var(--accent); }

        /* Tabla Estilizada */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .table-custom th { color: var(--accent); padding: 15px; text-align: left; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 1px; }
        .table-custom tr { background: var(--bg-card); transition: transform 0.2s; }
        .table-custom tr:hover { transform: scale(1.01); }
        .table-custom td { padding: 15px; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .table-custom td:first-child { border-left: 1px solid var(--border); border-radius: 8px 0 0 8px; }
        .table-custom td:last-child { border-right: 1px solid var(--border); border-radius: 0 8px 8px 0; text-align: right; }

        /* Badges y Botones */
        .badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; }
        .badge-admin { background: rgba(0, 188, 212, 0.2); color: var(--accent); border: 1px solid var(--accent); }
        .badge-vendedor { background: rgba(255, 255, 255, 0.1); color: #ddd; }
        
        .status-pill { display: flex; align-items: center; gap: 6px; font-weight: bold; font-size: 0.85rem; }
        .dot { height: 8px; width: 8px; border-radius: 50%; display: inline-block; }

        .btn-toggle {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.8rem;
            transition: 0.3s;
        }
        .btn-off { background: #3d1a1a; color: #ff5252; border: 1px solid #ff5252; }
        .btn-off:hover { background: #ff5252; color: white; }
        .btn-on { background: #1a3d21; color: #4caf50; border: 1px solid #4caf50; }
        .btn-on:hover { background: #4caf50; color: white; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
        .success { background: rgba(76, 175, 80, 0.2); color: #4caf50; border: 1px solid #4caf50; }
        .danger { background: rgba(244, 67, 54, 0.2); color: #f44336; border: 1px solid #f44336; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="user-container">
            <h1 style="color: var(--accent); margin-bottom: 30px;">
                <i class="fas fa-user-shield"></i> Seguridad y Usuarios
            </h1>

            <?php echo $mensaje; ?>

            <div class="card-admin">
                <form method="POST" class="form-grid">
                    <div class="input-group">
                        <label>Nombre de Usuario</label>
                        <input type="text" name="usuario" class="input-dark" required placeholder="Ej: usuario">
                    </div>
                    <div class="input-group">
                        <label>Contraseña</label>
                        <input type="password" name="password" class="input-dark" required placeholder="••••••••">
                    </div>
                    <div class="input-group">
                        <label>Nivel de Acceso (Rol)</label>
                        <select name="rol" class="input-dark">
                            <option value="vendedor">Vendedor (Básico)</option>
                            <option value="cajero">Cajero (Caja + Ventas)</option>
                            <option value="supervisor">Supervisor (Edición + Stock)</option>
                            <option value="admin">Administrador (Gestión)</option>
                            <?php if ($_SESSION['usuario_rol'] === 'developer'): ?>
                                <option value="developer">Developer (Sistema)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" name="nuevo_usuario" class="btn-toggle btn-on" style="height: 45px; width: 100%;">
                        <i class="fas fa-plus"></i> REGISTRAR USUARIO
                    </button>
                </form>
            </div>

            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rango / Permisos</th>
                        <th>Estado Actual</th>
                        <th>Gestión</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="background:#333; height:35px; width:35px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--accent);">
                                    <i class="fas fa-user"></i>
                                </div>
                                <strong><?php echo $u['usuario']; ?></strong>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo $u['rol']=='admin'?'badge-admin':'badge-vendedor'; ?>">
                                <?php echo strtoupper($u['rol']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="status-pill" style="color: <?php echo $u['estado']=='ACTIVO'?'#4caf50':'#f44336'; ?>">
                                <span class="dot" style="background: <?php echo $u['estado']=='ACTIVO'?'#4caf50':'#f44336'; ?>"></span>
                                <?php echo $u['estado']; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($u['id'] != 1): // Protegemos tu cuenta principal ?>
                            <form method="POST">
                                <input type="hidden" name="id_usuario" value="<?php echo $u['id']; ?>">
                                <input type="hidden" name="estado_actual" value="<?php echo $u['estado']; ?>">
                                <button type="submit" name="toggle_estado" class="btn-toggle <?php echo $u['estado']=='ACTIVO'?'btn-off':'btn-on'; ?>">
                                    <?php echo $u['estado']=='ACTIVO'?'DESACTIVAR':'ACTIVAR'; ?>
                                </button>
                            </form>
                            <?php else: ?>
                                <span style="color:#666; font-size:0.8rem; font-style:italic;">Sistema Protegido</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>