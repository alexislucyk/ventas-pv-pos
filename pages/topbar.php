<?php
// topbar.php
// Barra superior fija - Extensión visual del sidebar
// Requiere que infosesion.php haya sido incluido previamente

$nombre_usuario_top = isset($_SESSION['usuario_nombre']) ? htmlspecialchars($_SESSION['usuario_nombre']) : 'Usuario';
$rol_usuario = isset($_SESSION['usuario_rol']) ? htmlspecialchars($_SESSION['usuario_rol']) : 'usuario';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    .topbar {
        position: fixed;
        top: 0;
        left: 250px;
        right: 0;
        height: 50px;
        background-color: #1a1a1a;
        border-bottom: 1px solid #333;
        border-top: 1px solid #0f172a;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding: 0 20px;
        z-index: 899;
        box-sizing: border-box;
    }

    .topbar__user {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        text-decoration: none;
        color: #fff;
        font-size: 0.95em;
    }

    .topbar__user:hover {
        color: #00bcd4;
    }

    .topbar__user i {
        color: #00bcd4;
        font-size: 1.2em;
    }

    .topbar__rol {
        font-size: 0.8em;
        color: #aaa;
        text-transform: capitalize;
    }

    /* Ajuste para móviles - cuando el sidebar está oculto */
    @media (max-width: 1100px) {
        .topbar {
            left: 0;
        }
    }
</style>

<div class="topbar">
    <a href="<?php echo URL_BASE; ?>pages/perfil.php" class="topbar__user" title="Ver Perfil">
        <i class="fas fa-user-circle"></i>
        <span><?php echo $nombre_usuario_top; ?></span>
        <span class="topbar__rol">(<?php echo $rol_usuario; ?>)</span>
    </a>
</div>