<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Sistema de Gestión</title>
    <link rel="stylesheet" href="css\style_login.css">
</head>
<body>
    <div class="login-box">
        <h2>Acceso al Sistema</h2>
        <form method="POST" action="login.php">
            <input type="text" name="usuario" placeholder="Usuario" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Iniciar Sesión</button>
        </form>
    </div>
</body>
</html>
<?php
// 1. Iniciar la sesión al principio del script
session_start();

// Si el usuario ya está logueado, redirigir a la página principal
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

// 2. Incluir la configuración de la base de datos
require 'config\db_config.php'; // Asegúrate que esta ruta sea correcta

$error = '';

// 3. Procesar el formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// Solución (compatible con versiones anteriores)
$usuario = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
$password = isset($_POST['password']) ? $_POST['password'] : '';

    // Validar entradas básicas
    if (empty($usuario) || empty($password)) {
        $error = 'Por favor, introduce usuario y contraseña.';
    } else {
        try {
            // 4. Buscar el usuario
            $stmt = $pdo->prepare('SELECT id, password_hash, rol FROM usuarios WHERE usuario = ?');
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // 5. Autenticación exitosa: Crear variables de sesión
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nombre'] = $usuario;
                $_SESSION['usuario_rol'] = $user['rol'];

                // 6. Redirigir al panel principal
                header('Location: index.php');
                exit();
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }

        } catch (PDOException $e) {
            $error = 'Ocurrió un error en la base de datos.';
            // En un entorno de desarrollo, podrías mostrar $e->getMessage();
        }
    }
}
// El código HTML del Paso 3 va después de esta lógica PHP
?>
<?php if ($error): ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>