<?php
include 'infosesion.php';
require '../config/db_config.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_clave'])) {
    $actual = $_POST['pass_actual'];
    $nueva  = $_POST['pass_nueva'];
    $conf   = $_POST['pass_conf'];
    $id_user = $_SESSION['usuario_id'];

    if ($nueva !== $conf) {
        $mensaje = "<div class='alert alert-error'>❌ Las nuevas contraseñas no coinciden.</div>";
    } else {
        $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$id_user]);
        $user = $stmt->fetch();

        if (password_verify($actual, $user['password_hash'])) {
            $nuevo_hash = password_hash($nueva, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$nuevo_hash, $id_user]);
            $mensaje = "<div class='alert alert-success'>✅ Contraseña actualizada correctamente.</div>";
        } else {
            $mensaje = "<div class='alert alert-error'>❌ La contraseña actual es incorrecta.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Perfil | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/misc.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="card profile-card">
            <div class="profile-header">
                <i class="fas fa-user-circle"></i>
                <h2 style="margin-top:10px;"><?php echo $nombre_usuario; ?></h2>
                <span class="badge" style="background:#333; color:#00bcd4;"><?php echo strtoupper($rol); ?></span>
            </div>

            <?php echo $mensaje; ?>

            <form method="POST" id="cambiar-pass">
                <label>Contraseña Actual</label>
                <input type="password" name="pass_actual" class="input-field" required>
                
                <hr style="border:0; border-top:1px solid #444; margin:20px 0;">
                
                <label>Nueva Contraseña</label>
                <input type="password" name="pass_nueva" class="input-field" required minlength="4">
                
                <label>Confirmar Nueva Contraseña</label>
                <input type="password" name="pass_conf" class="input-field" required minlength="4">

                <button type="submit" name="cambiar_clave" class="btn btn-primary btn-block" style="margin-top:20px; height:45px;">
                    Actualizar Contraseña
                </button>
            </form>
        </div>
    </div>
</body>
</html>