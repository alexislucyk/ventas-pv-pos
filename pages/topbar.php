<?php
// topbar.php
// Barra superior fija - Extensión visual del sidebar
// Requiere que infosesion.php haya sido incluido previamente

$nombre_usuario_top = isset($_SESSION['usuario_nombre']) ? htmlspecialchars($_SESSION['usuario_nombre']) : 'Usuario';
$rol_usuario = isset($_SESSION['usuario_rol']) ? htmlspecialchars($_SESSION['usuario_rol']) : 'usuario';

// Obtener cotización del dólar con caché de 1 hora
$dolar_compra = "-";
$dolar_venta = "-";
$dolar_fecha = "";
$cache_file = dirname(__FILE__) . '/../cache/dolar_cache.json';
$cache_tiempo = 3600; // 1 hora

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_tiempo) {
    $cache_data = json_decode(file_get_contents($cache_file), true);
    $dolar_compra = $cache_data['compra'] ?? "-";
    $dolar_venta = $cache_data['venta'] ?? "-";
    $dolar_fecha = date('H:i', filemtime($cache_file));
} else {
    $ch = curl_init("https://dolarapi.com/v1/dolares/oficial");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['compra']) && isset($data['venta'])) {
            $dolar_compra = $data['compra'];
            $dolar_venta = $data['venta'];
            $dolar_fecha = date('H:i');
            // Guardar en caché
            if (!is_dir(dirname($cache_file))) {
                mkdir(dirname($cache_file), 0755, true);
            }
            file_put_contents($cache_file, json_encode(['compra' => $dolar_compra, 'venta' => $dolar_venta]));
        }
    }
}
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
        gap: 15px;
        padding: 0 20px;
        z-index: 899;
        box-sizing: border-box;
        transition: left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Botón menú hamburguesa para mobile */
    #menuToggle {
        display: none;
        background: none;
        border: none;
        color: #f0f0f0;
        font-size: 1.3em;
        cursor: pointer;
        padding: 5px;
        transition: color 0.2s;
    }
    #menuToggle:hover {
        color: #00bcd4;
    }

    .topbar__dolar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        background: #1e1e24;
        border-radius: 6px;
        font-size: 0.85em;
        color: #fff;
    }

    .topbar__dolar i {
        color: #4caf50;
    }

    .topbar__dolar .compra {
        color: #6d6968;
    }

    .topbar__dolar .venta{
        color: #4caf50;
    }
    .topbar__dolar .operativo {
        color: #ff9800;
    }

    .topbar__refresh {
        background: none;
        border: none;
        color: #eee34e !important;
        cursor: pointer;
        font-size: 0.9em;
        padding: 5px;
        transition: transform 0.2s;
    }

    .topbar__refresh:hover {
        transform: rotate(180deg);
        color: #00bcd4 !important;
    }

    /* ========== USER DROPDOWN ========== */
    .user-dropdown {
        position: relative;
        margin-left: auto;
    }

    .topbar__user {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        text-decoration: none;
        color: #fff;
        font-size: 0.95em;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid transparent;
        user-select: none;
    }
    .topbar__user:hover {
        color: #00bcd4;
        background: rgba(0, 188, 212, 0.08);
        border-color: #333;
    }
    .topbar__user:active {
        transform: scale(0.97);
    }
    .topbar__user i.fa-user-circle {
        color: #00bcd4;
        font-size: 1.2em;
    }
    .topbar__user .fa-chevron-down {
        font-size: 0.7em;
        color: #666;
        transition: transform 0.25s ease;
    }
    .user-dropdown.open .topbar__user .fa-chevron-down {
        transform: rotate(180deg);
    }

    .topbar__rol {
        font-size: 0.8em;
        color: #aaa;
        text-transform: capitalize;
    }

    /* Menú desplegable */
    .user-dropdown-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        min-width: 220px;
        background: #1e1e1e;
        border: 1px solid #333;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        padding: 6px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1000;
        pointer-events: none;
    }
    .user-dropdown.open .user-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        pointer-events: all;
    }

    .user-dropdown-menu .user-header {
        padding: 12px 14px;
        border-bottom: 1px solid #2a2a2a;
        margin-bottom: 4px;
    }
    .user-dropdown-menu .user-header strong {
        display: block;
        color: #fff;
        font-size: 0.95em;
    }
    .user-dropdown-menu .user-header span {
        color: #888;
        font-size: 0.8em;
        text-transform: capitalize;
    }

    .user-dropdown-menu a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        color: #ccc;
        text-decoration: none;
        border-radius: 8px;
        font-size: 0.88em;
        transition: all 0.2s;
    }
    .user-dropdown-menu a:hover {
        background: rgba(0, 188, 212, 0.08);
        color: #fff;
    }
    .user-dropdown-menu a i {
        width: 20px;
        text-align: center;
        font-size: 1em;
    }
    .user-dropdown-menu a.logout-option {
        border-top: 1px solid #2a2a2a;
        margin-top: 4px;
        padding-top: 12px;
        color: #ff6b6b;
    }
    .user-dropdown-menu a.logout-option:hover {
        background: rgba(255, 82, 82, 0.1);
        color: #ff5252;
    }

    /* Flechita del dropdown */
    .user-dropdown-menu::before {
        content: '';
        position: absolute;
        top: -6px;
        right: 20px;
        width: 12px;
        height: 12px;
        background: #1e1e1e;
        border-left: 1px solid #333;
        border-top: 1px solid #333;
        transform: rotate(45deg);
    }

    @media (max-width: 1100px) {
        .topbar {
            left: 0;
        }
    }
</style>

<div class="topbar">
    <!-- Botón menú hamburguesa para mobile -->
    <button id="menuToggle" onclick="toggleSidebarMobile()" title="Menú">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="topbar__dolar">
        <i class="fas fa-dollar-sign"></i>
        <span class="compra">Compra: <strong><?php echo $dolar_compra; ?></strong></span>
        <span class="venta">Venta: <strong><?php echo $dolar_venta; ?></strong></span>
        <span class="operativo">Operativo (+ 2%): <strong><?php echo $dolar_venta * 1.02; ?></strong></span>
        <?php if ($dolar_fecha): ?>
            <small style="color: #666; margin-left: 5px;">(<?php echo $dolar_fecha; ?>)</small>
        <?php endif; ?>
        <button class="topbar__refresh" onclick="refreshDolar()" title="Actualizar cotización">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>
    
    <!-- User Dropdown -->
    <div class="user-dropdown" id="userDropdown">
        <div class="topbar__user" id="userDropdownToggle" title="Menú de usuario">
            <i class="fas fa-user-circle"></i>
            <span><?php echo $nombre_usuario_top; ?></span>
            <span class="topbar__rol">(<?php echo $rol_usuario; ?>)</span>
            <i class="fas fa-chevron-down"></i>
        </div>
        
        <div class="user-dropdown-menu" id="userDropdownMenu">
            <div class="user-header">
                <strong><?php echo $nombre_usuario_top; ?></strong>
                <span><?php echo $rol_usuario; ?></span>
            </div>
            <a href="<?php echo URL_BASE; ?>pages/perfil.php"><i class="fas fa-user-cog" style="color: #00bcd4;"></i> Mi Perfil</a>
            <a href="<?php echo URL_BASE; ?>pages/perfil.php#cambiar-pass"><i class="fas fa-key" style="color: #f1c40f;"></i> Cambiar Contraseña</a>
            <a href="<?php echo URL_BASE; ?>pages/infosesion.php"><i class="fas fa-info-circle" style="color: #888;"></i> Información de Sesión</a>
            <a href="<?php echo URL_BASE; ?>logout.php" class="logout-option"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </div>
    </div>
</div>

<script>
function refreshDolar() {
    const btn = document.querySelector('.topbar__refresh i');
    btn.style.opacity = '0.5';
    fetch('<?php echo URL_BASE; ?>funciones/actualizar_dolar.php', { cache: 'no-cache' })
        .then(response => response.json())
        .then(data => {
            if (data.compra && data.venta) {
                document.querySelector('.topbar__dolar .compra strong').textContent = data.compra;
                document.querySelector('.topbar__dolar .venta strong').textContent = data.venta;
                document.querySelector('.topbar__dolar .operativo strong').textContent = (data.venta * 1.02).toFixed(2);
                document.querySelector('.topbar__dolar small').textContent = '(' + String(new Date().getHours()).padStart(2, '0') + ':' + String(new Date().getMinutes()).padStart(2, '0') + ')';
            }
        })
        .catch(err => console.log('Error al actualizar dólar:', err))
        .finally(() => {
            btn.style.opacity = '1';
        });
}

// Auto-refresh cada 1 hora
setInterval(refreshDolar, 3600000);

// ========== USER DROPDOWN TOGGLE ==========
document.addEventListener('DOMContentLoaded', function() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.getElementById('userDropdownToggle');
    const menu = document.getElementById('userDropdownMenu');

    // Toggle al hacer clic en el usuario
    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    // Cerrar al hacer clic fuera
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });

    // Cerrar con Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            dropdown.classList.remove('open');
        }
    });

    // Cerrar al hacer clic en cualquier link del menú
    menu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function() {
            dropdown.classList.remove('open');
        });
    });
});
</script>