<?php
// pages/perfil.php — Mi Cuenta: información del usuario logueado
// El cambio de contraseña se realiza desde el módulo Usuarios.
include 'infosesion.php';

$usuario_id = $_SESSION['usuario_id'];

// Datos frescos del usuario desde la BD
$mi_usuario = [
    'usuario' => $_SESSION['usuario_nombre'] ?? '',
    'rol'     => $_SESSION['usuario_rol'] ?? '',
    'estado'  => 'ACTIVO',
];
try {
    $stmt = $pdo->prepare("SELECT usuario, rol, estado FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $usuario_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mi_usuario = $row;
    }
} catch (Exception $e) {
    error_log("perfil.php (usuario): " . $e->getMessage());
}

// Sucursal actual de la sesión
$mi_sucursal = 'Sucursal principal';
try {
    $stmt = $pdo->prepare("SELECT nombre_sucursal FROM sucursales WHERE id = :id AND empresa_id = :empresa_id");
    $stmt->execute([
        ':id'         => $_SESSION['sucursal_id'] ?? 1,
        ':empresa_id' => $_SESSION['empresa_id']
    ]);
    $nombre_sucursal = $stmt->fetchColumn();
    if ($nombre_sucursal) {
        $mi_sucursal = $nombre_sucursal;
    }
} catch (Exception $e) {
    error_log("perfil.php (sucursal): " . $e->getMessage());
}

$es_activo = strtoupper($mi_usuario['estado'] ?? 'ACTIVO') === 'ACTIVO';
$puede_ver_usuarios = tiene_permiso('pages/usuarios.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Cuenta | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/misc.css'); ?>">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <div class="card profile-card">
            <div class="profile-header">
                <i class="fas fa-user-circle"></i>
                <h2 style="margin-top:10px;"><?php echo $nombre_usuario; ?></h2>
                <span class="badge" style="background:#333; color:#00bcd4;"><?php echo strtoupper($rol); ?></span>
                <span class="badge" style="background:<?php echo $es_activo ? 'rgba(46,204,113,0.15); color:#2ecc71;' : 'rgba(231,76,60,0.15); color:#e74c3c;'; ?> margin-left:5px;">
                    <?php echo $es_activo ? 'Activo' : 'Inactivo'; ?>
                </span>
            </div>

            <ul class="account-list">
                <li><i class="fas fa-at"></i><div><span>Usuario</span><strong><?php echo htmlspecialchars($mi_usuario['usuario']); ?></strong></div></li>
                <li><i class="fas fa-user-tag"></i><div><span>Rol</span><strong><?php echo htmlspecialchars(ucfirst($mi_usuario['rol'])); ?></strong></div></li>
                <li><i class="fas fa-store"></i><div><span>Empresa</span><strong><?php echo htmlspecialchars($nombre_empresa_sistema); ?></strong></div></li>
                <li><i class="fas fa-code-branch"></i><div><span>Sucursal</span><strong><?php echo htmlspecialchars($mi_sucursal); ?></strong></div></li>
            </ul>

            <p class="account-note">
                <i class="fas fa-key"></i>
                El cambio de contraseña se realiza desde el módulo de Usuarios.
                <?php if ($puede_ver_usuarios): ?>
                    <a href="<?php echo route_file('pages/usuarios.php'); ?>">Ir a Usuarios</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
</body>
</html>