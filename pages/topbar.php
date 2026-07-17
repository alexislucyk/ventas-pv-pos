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

// Margen del dólar operativo (configurable desde la pestaña Parámetros)
$dolar_margen = 2; // valor por defecto
try {
    $stmt_margen = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'dolar_margen' LIMIT 1");
    $stmt_margen->execute();
    $margen_db = $stmt_margen->fetchColumn();
    if ($margen_db !== false && $margen_db !== null && $margen_db !== '') {
        $dolar_margen = floatval($margen_db);
    }
} catch (Exception $e) {
    $dolar_margen = 2;
}
$factor_operativo = 1 + ($dolar_margen / 100);

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
        left: 0;
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

    /* ========== SELECTOR SUCURSAL ========== */
    .sucursal-dropdown {
        position: relative;
    }
    .topbar__sucursal {
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
    .topbar__sucursal:hover {
        color: #00bcd4;
        background: rgba(0, 188, 212, 0.08);
        border-color: #333;
    }
    .topbar__sucursal:active {
        transform: scale(0.97);
    }
    .topbar__sucursal i.fa-building {
        color: #00bcd4;
        font-size: 1.1em;
    }
    .topbar__sucursal .fa-chevron-down {
        font-size: 0.7em;
        color: #666;
        transition: transform 0.25s ease;
    }
    .sucursal-dropdown.open .topbar__sucursal .fa-chevron-down {
        transform: rotate(180deg);
    }
    .topbar__sucursal .sucursal-label {
        color: #aaa;
        font-size: 0.9em;
    }
    .topbar__sucursal .sucursal-nombre {
        font-weight: 500;
    }

    /* Menú desplegable sucursal */
    .sucursal-dropdown-menu {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
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
    .sucursal-dropdown.open .sucursal-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        pointer-events: all;
    }
    .sucursal-dropdown-menu .sucursal-header {
        padding: 12px 14px;
        border-bottom: 1px solid #2a2a2a;
        margin-bottom: 4px;
    }
    .sucursal-dropdown-menu .sucursal-header strong {
        display: block;
        color: #fff;
        font-size: 0.95em;
    }
    .sucursal-dropdown-menu .sucursal-header span {
        color: #888;
        font-size: 0.8em;
    }
    .sucursal-dropdown-menu .sucursal-option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        color: #ccc;
        text-decoration: none;
        border-radius: 8px;
        font-size: 0.88em;
        transition: all 0.2s;
        cursor: pointer;
    }
    .sucursal-dropdown-menu .sucursal-option:hover {
        background: rgba(0, 188, 212, 0.08);
        color: #fff;
    }
    .sucursal-dropdown-menu .sucursal-option i {
        width: 20px;
        text-align: center;
        font-size: 1em;
    }
    .sucursal-dropdown-menu .sucursal-option.principal {
        color: #00bcd4;
    }
    .sucursal-dropdown-menu .sucursal-option.selected {
        background: rgba(0, 188, 212, 0.15);
        color: #fff;
    }

    /* Flechita del dropdown sucursal */
    .sucursal-dropdown-menu::before {
        content: '';
        position: absolute;
        top: -6px;
        left: 20px;
        width: 12px;
        height: 12px;
        background: #1e1e1e;
        border-left: 1px solid #333;
        border-top: 1px solid #333;
        transform: rotate(45deg);
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
        <span class="operativo">Operativo (+ <?php echo $dolar_margen; ?>%): <strong><?php echo $dolar_venta * $factor_operativo; ?></strong></span>
        <?php if ($dolar_fecha): ?>
            <small style="color: #666; margin-left: 5px;">(<?php echo $dolar_fecha; ?>)</small>
        <?php endif; ?>
        <button class="topbar__refresh" onclick="refreshDolar()" title="Actualizar cotización">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <!-- Selector de Empresa / Sucursal -->
    <?php
    $empresa_id_top = $_SESSION['empresa_id'] ?? null;
    $sucursal_id_top = $_SESSION['sucursal_id'] ?? 1;
    
    // Obtener nombre de la empresa desde la tabla empresas (multi-empresa)
    $empresa_nombre_top = 'Empresa';
    if ($empresa_id_top) {
        try {
            $stmt_emp_top = $pdo->prepare("SELECT nombre_fantasia FROM empresas WHERE id = :empresa_id LIMIT 1");
            $stmt_emp_top->execute([':empresa_id' => $empresa_id_top]);
            $empresa_nombre_top = $stmt_emp_top->fetchColumn() ?: 'Empresa';
        } catch (Exception $e) {
            $empresa_nombre_top = 'Empresa';
        }
    }
    
    if ($empresa_id_top) {
        try {
            $stmt_suc_top = $pdo->prepare("SELECT id, nombre_sucursal, es_principal FROM sucursales WHERE empresa_id = :empresa_id ORDER BY es_principal DESC, nombre_sucursal ASC");
            $stmt_suc_top->execute([':empresa_id' => $empresa_id_top]);
            $sucursales_top = $stmt_suc_top->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $sucursales_top = [];
        }
    }
    ?>
    
    <?php if (!empty($sucursales_top)): ?>
    <?php 
    // Si el usuario tiene sucursal_id=0 (vista general), redirigir a la primera sucursal real
    if ($sucursal_id_top == 0) {
        $sucursal_id_top = (int)$sucursales_top[0]['id'];
        $_SESSION['sucursal_id'] = $sucursal_id_top;
    }
    
    $sucursal_actual = null;
    foreach ($sucursales_top as $s) {
        if ($s['id'] == $sucursal_id_top) {
            $sucursal_actual = $s;
            break;
        }
    }
    $sucursal_nombre_actual = $sucursal_actual ? $sucursal_actual['nombre_sucursal'] : ($sucursales_top[0]['nombre_sucursal'] ?? 'Principal');
    ?>
    <div class="sucursal-dropdown" id="sucursalDropdown">
        <div class="topbar__sucursal" id="sucursalDropdownToggle" title="Cambiar sucursal">
            <i class="fas fa-building"></i>
            <span class="sucursal-label"><?php echo htmlspecialchars($empresa_nombre_top); ?>:</span>
            <span class="sucursal-nombre"><?php echo htmlspecialchars($sucursal_nombre_actual); ?></span>
            <i class="fas fa-chevron-down"></i>
        </div>
        
        <div class="sucursal-dropdown-menu" id="sucursalDropdownMenu">
            <div class="sucursal-header">
                <strong><?php echo htmlspecialchars($empresa_nombre_top); ?></strong>
                <span>Sucursales</span>
            </div>
            <?php foreach ($sucursales_top as $suc): ?>
            <div class="sucursal-option <?php echo ($suc['id'] == $sucursal_id_top) ? 'selected' : ''; ?>" data-sucursal-id="<?php echo $suc['id']; ?>" onclick="cambiarSucursalTop(<?php echo $suc['id']; ?>)">
                <i class="fas fa-store" style="color: #4caf50;"></i> <?php echo htmlspecialchars($suc['nombre_sucursal']); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
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
                document.querySelector('.topbar__dolar .operativo strong').textContent = (data.venta * <?php echo $factor_operativo; ?>).toFixed(2);
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

    // ========== SUCURSAL DROPDOWN TOGGLE ==========
    const sucursalDropdown = document.getElementById('sucursalDropdown');
    const sucursalToggle = document.getElementById('sucursalDropdownToggle');
    const sucursalMenu = document.getElementById('sucursalDropdownMenu');

    if (sucursalDropdown && sucursalToggle && sucursalMenu) {
        sucursalToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sucursalDropdown.classList.toggle('open');
            if (dropdown) dropdown.classList.remove('open');
        });

        document.addEventListener('click', function(e) {
            if (!sucursalDropdown.contains(e.target)) {
                sucursalDropdown.classList.remove('open');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                sucursalDropdown.classList.remove('open');
            }
        });
    }
});

// ========== CAMBIAR SUCURSAL ==========
async function cambiarSucursalTop(sucursal_id) {
    const sucursalDropdown = document.getElementById('sucursalDropdown');
    if (sucursalDropdown) sucursalDropdown.classList.remove('open');

    const sucursalNombre = document.querySelector('.topbar__sucursal .sucursal-nombre');
    if (sucursalNombre) sucursalNombre.textContent = 'Cambiando...';

    try {
        const r = await fetch('../ajax/cambiar_sucursal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'sucursal_id=' + encodeURIComponent(sucursal_id)
        });
        const data = await r.json();
        if (data.success) {
            window.location.href = window.location.pathname + '?sucursal_cambiada=' + Date.now();
        } else {
            alert('❌ ' + (data.error || 'Error al cambiar sucursal'));
        }
    } catch (err) {
        console.error('Error:', err);
        alert('❌ Error de conexión al cambiar sucursal');
    }
}
</script>