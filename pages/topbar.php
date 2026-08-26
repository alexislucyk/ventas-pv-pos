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

<link rel="stylesheet" href="<?php echo function_exists('url') ? url('css/components/topbar.css') : (defined('URL_BASE') ? URL_BASE . 'css/components/topbar.css' : '../css/components/topbar.css'); ?>">

<div class="topbar">
    <!-- Botón menú hamburguesa para mobile -->
    <button id="menuToggle" onclick="toggleSidebarMobile()" title="Menú">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="topbar__dolar">
        <i class="fas fa-dollar-sign"></i>
        <span class="compra">Compra: <strong><?php echo $dolar_compra; ?></strong></span>
        <span class="venta">Venta: <strong><?php echo $dolar_venta; ?></strong></span>
        <span class="operativo">Operativo (+ <?php echo $dolar_margen; ?>%): <strong><?php echo is_numeric($dolar_venta) ? number_format($dolar_venta * $factor_operativo, 2) : '-'; ?></strong></span>
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
            <a href="<?php echo route_file('pages/perfil.php'); ?>"><i class="fas fa-user-cog" style="color: #00bcd4;"></i> Mi Perfil</a>
            <a href="<?php echo route_file('pages/perfil.php'); ?>#cambiar-pass"><i class="fas fa-key" style="color: #f1c40f;"></i> Cambiar Contraseña</a>
            <a href="<?php echo route_file('pages/infosesion.php'); ?>"><i class="fas fa-info-circle" style="color: #888;"></i> Información de Sesión</a>
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
                const operativo = parseFloat(data.venta) * <?php echo $factor_operativo; ?>;
                document.querySelector('.topbar__dolar .operativo strong').textContent = isNaN(operativo) ? '-' : operativo.toFixed(2);
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
        const r = await fetch('<?php echo URL_BASE; ?>ajax/cambiar_sucursal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'sucursal_id=' + encodeURIComponent(sucursal_id)
        });
        const data = await r.json();
        if (data.success) {
            window.location.href = window.location.pathname + '?sucursal_cambiada=' + Date.now();
        } else {
            notificarGlobal('Error', '❌ ' + (data.error || 'Error al cambiar sucursal'));
        }
    } catch (err) {
        console.error('Error:', err);
        notificarGlobal('Error', '❌ Error de conexión al cambiar sucursal');
    }
}

// Notificación con modal estilizado (con fallback si el sidebar no está presente)
function notificarGlobal(titulo, mensaje) {
    if (typeof mostrarMensaje === 'function') {
        mostrarMensaje(titulo, mensaje, 'error');
    } else {
        alert(mensaje);
    }
}
</script>