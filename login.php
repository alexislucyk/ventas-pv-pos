<?php
// login.php: Página de inicio de sesión para el sistema de gestión
// 1. Iniciar la sesión al principio
session_start();

// Si el usuario YA está logueado, lo mandamos al index
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

// 2. Incluir la configuración de la base de datos
require 'config/db_config.php';

$error = '';

// 3. Procesar el formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($usuario) || empty($password)) {
        $error = 'Por favor, introduce usuario y contraseña.';
    } else {
        try {
            // 4. Buscar el usuario incluyendo la validación de ESTADO
            $stmt = $pdo->prepare('SELECT id, password_hash, rol, estado, empresa_id FROM usuarios WHERE usuario = ?');
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            // Verificamos: 1. Existe el usuario? 2. La clave coincide? 3. Está ACTIVO?
            if ($user && password_verify($password, $user['password_hash'])) {
                
                if ($user['estado'] !== 'ACTIVO') {
                    $error = 'Tu cuenta está desactivada. Contacta al administrador.';
                } else {
                    // 5. Autenticación exitosa: Crear variables de sesión
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['usuario_nombre'] = $usuario;
                    $_SESSION['usuario_rol'] = $user['rol'];
                    $_SESSION['empresa_id'] = $user['empresa_id'];

                    // --- NUEVO LOGIN: CARGAR PERMISOS SEPARADOS (PÁGINAS + FUNCIONES) ---
                    // Permisos de PÁGINAS (para acceso a vistas)
                    $stmt_paginas = $pdo->prepare("
                        SELECT DISTINCT m.archivo 
                        FROM modulos m 
                        LEFT JOIN permisos_rol p ON m.id = p.modulo_id AND p.rol = ? AND m.tipo = 'pagina'
                        LEFT JOIN permisos_usuario pu ON m.id = pu.modulo_id AND pu.usuario_id = ? AND m.tipo = 'pagina'
                        WHERE p.id IS NOT NULL OR pu.id IS NOT NULL
                    ");
                    $stmt_paginas->execute(array($user['rol'], $user['id']));
                    $_SESSION['permisos_paginas'] = $stmt_paginas->fetchAll(PDO::FETCH_COLUMN, 0);

                    // Permisos de FUNCIONES (para validar acciones específicas)
                    $stmt_funciones = $pdo->prepare("
                        SELECT DISTINCT m.archivo 
                        FROM modulos m 
                        LEFT JOIN permisos_rol p ON m.id = p.modulo_id AND p.rol = ? AND m.tipo = 'funcion'
                        LEFT JOIN permisos_usuario pu ON m.id = pu.modulo_id AND pu.usuario_id = ? AND m.tipo = 'funcion'
                        WHERE p.id IS NOT NULL OR pu.id IS NOT NULL
                    ");
                    $stmt_funciones->execute(array($user['rol'], $user['id']));
                    $_SESSION['permisos_funciones'] = $stmt_funciones->fetchAll(PDO::FETCH_COLUMN, 0);

                    // Mantener compatibilidad: permisos generales (páginas + funciones)
                    $_SESSION['permisos'] = array_unique(array_merge(
                        $_SESSION['permisos_paginas'] ?? [],
                        $_SESSION['permisos_funciones'] ?? []
                    ));

                    header('Location: index.php');
                    exit();
                }
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }

        } catch (PDOException $e) {
            $error = 'Ocurrió un error en la base de datos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Sistema de Gestión</title>
    <link rel="stylesheet" href="css/style_login.css">
</head>
<body>
    <div class="login-box">
        <h2>Acceso al Sistema</h2>
        
        <?php if ($error): ?>
            <p class="error" style="color: #ff4444; background: rgba(255,0,0,0.1); padding: 10px; border-radius: 5px; text-align: center;">
                <?php echo htmlspecialchars($error); ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="text" name="usuario" placeholder="Usuario" required autocomplete="off">
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Iniciar Sesión</button>
        </form>
    </div>
</body>
</html>