<?php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
require '../config/db_config.php';


$mensaje = '';

// PROCESAR ACCIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Crear nuevo usuario
    if (isset($_POST['nuevo_usuario'])) {
        $user = trim($_POST['usuario']);
        $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $rol  = $_POST['rol'];
        $empresa_id = $_SESSION['empresa_id'] ?? 1;
        
        try {
            $sql = "INSERT INTO usuarios (usuario, password_hash, rol, estado, empresa_id) VALUES (?, ?, ?, 'ACTIVO', ?)";
            $pdo->prepare($sql)->execute([$user, $pass, $rol, $empresa_id]);
            $mensaje = "✅ Usuario '$user' registrado correctamente.";
        } catch (Exception $e) { 
            // 23000 / 1062 = violación de clave única (duplicado real)
            $es_duplicado = ($e->getCode() === '23000') || (stripos($e->getMessage(), 'Duplicate') !== false);
            if ($es_duplicado) {
                $mensaje = "❌ Error: El usuario '$user' ya existe.";
            } else {
                // Mostramos el error real en lugar de un mensaje engañoso
                $mensaje = "❌ Error al registrar: " . $e->getMessage();
            }
        }
    }

    // 2. Resetear Password (Acción de Developer)
    if (isset($_POST['reset_pass'])) {
        $id_u = (int)$_POST['id_usuario'];
        $pass_nueva = password_hash($_POST['nueva_password'], PASSWORD_BCRYPT);
        
        $sql = "UPDATE usuarios SET password_hash = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$pass_nueva, $id_u]);
        $u_nom = $_POST['u_nombre'];
        $mensaje = "✅ Password de '$u_nom' reseteada con éxito.";
    }

    // 3. Cambiar Estado
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
    <title>Seguridad | <?php echo $nombre_empresa_sistema; ?></title>
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
            grid-template-columns: repeat(3, 1fr);
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
            width: 100%;
            box-sizing: border-box;
        }
.input-dark:focus { border-color: var(--accent); }

        .btn-submit-container {
            grid-column: 3;
            justify-self: end;
        }

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

        /* Notificación Toast */
        .toast-notificacion {
            position: fixed; top: 20px; right: 20px; background: #2ecc71; color: white;
            padding: 15px 25px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.5);
            z-index: 10000; display: flex; align-items: center; gap: 10px; font-weight: bold;
            animation: slideInToast 0.3s ease-out forwards;
        }
        @keyframes slideInToast {
            from { transform: translateX(120%); }
            to { transform: translateX(0); }
        }
        .toast-fade-out {
            animation: fadeOutToast 0.5s ease-out forwards;
        }
        @keyframes fadeOutToast {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div class="user-container">
            <h1 style="color: var(--accent); margin-bottom: 30px;">
                <i class="fas fa-user-shield"></i> Seguridad y Usuarios
            </h1>

            <?php if ($mensaje): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const msg = "<?php echo str_replace(['✅', '❌'], '', $mensaje); ?>";
                        mostrarToast(msg, "<?php echo str_contains($mensaje, '❌') ? 'error' : 'success'; ?>");
                    });
                </script>
            <?php endif; ?>

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
                    <div class="btn-submit-container">
                        <button type="submit" name="nuevo_usuario" class="btn-toggle btn-on" style="height: 45px;">
                            <i class="fas fa-plus"></i> REGISTRAR USUARIO
                        </button>
                    </div>
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
                                <button type="button" class="btn-toggle btn-on" style="background:#f39c12; border-color:#f39c12; color:white;" 
                                        onclick="resetPassword(<?php echo $u['id']; ?>, '<?php echo $u['usuario']; ?>')">
                                    <i class="fas fa-key"></i> RESET
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

    <!-- Modal para Resetear Password -->
    <div id="modalReset" class="modal">
        <div class="modal-content" style="max-width: 450px; border-top: 4px solid #f39c12;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #444; padding-bottom: 10px;">
                <h2 style="margin: 0; color: #f39c12;"><i class="fas fa-key"></i> Resetear Clave</h2>
                <button onclick="cerrarModalReset()" style="background:none; border:none; color:white; font-size: 20px; cursor:pointer;">&times;</button>
            </div>
            
            <div style="margin-top: 20px;">
                <p style="margin-bottom: 15px;">Estás por resetear la contraseña del usuario: <strong id="reset_user_name" style="color: var(--accent);"></strong></p>
                <div class="input-group">
                    <label>Nueva Contraseña Genérica</label>
                    <input type="password" id="input_nueva_pass" class="input-dark" placeholder="Mínimo 4 caracteres" autocomplete="off">
                </div>
                <input type="hidden" id="reset_user_id">
            </div>
            
            <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-secondary" onclick="cerrarModalReset()">Cancelar</button>
                <button class="btn-toggle" style="background: #f39c12; color: white;" onclick="confirmarReset()">
                    <i class="fas fa-save"></i> GUARDAR NUEVA CLAVE
                </button>
            </div>
        </div>
    </div>

    <script>
    function mostrarToast(mensaje, tipo = 'success') {
        const toast = document.createElement('div');
        toast.className = 'toast-notificacion';
        if (tipo === 'error') toast.style.background = '#e74c3c';
        toast.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i> ${mensaje}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    function resetPassword(id, nombre) {
        document.getElementById('reset_user_id').value = id;
        document.getElementById('reset_user_name').innerText = nombre;
        document.getElementById('input_nueva_pass').value = '';
        
        const modal = document.getElementById('modalReset');
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        
        setTimeout(() => document.getElementById('input_nueva_pass').focus(), 100);
    }

    function cerrarModalReset() {
        document.getElementById('modalReset').style.display = 'none';
    }

    function confirmarReset() {
        const id = document.getElementById('reset_user_id').value;
        const nombre = document.getElementById('reset_user_name').innerText;
        const nueva = document.getElementById('input_nueva_pass').value;

        if (nueva && nueva.length >= 4) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="reset_pass" value="1">
                <input type="hidden" name="id_usuario" value="${id}">
                <input type="hidden" name="u_nombre" value="${nombre}">
                <input type="hidden" name="nueva_password" value="${nueva}">
            `;
            document.body.appendChild(form);
            form.submit();
        } else {
            mostrarToast("La contraseña debe tener al menos 4 caracteres.", "error");
        }
    }

    // Cerrar modal al hacer clic fuera del contenido
    window.onclick = function(event) {
        const modal = document.getElementById('modalReset');
        if (event.target == modal) cerrarModalReset();
    }

    // Permitir enviar con la tecla Enter
    document.getElementById('input_nueva_pass').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') confirmarReset();
    });
    </script>
</body>
</html>