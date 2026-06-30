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

    @media (max-width: 1100px) {
        .topbar {
            left: 0;
        }
    }
</style>

<div class="topbar">
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
    
    <a href="<?php echo URL_BASE; ?>pages/perfil.php" class="topbar__user" title="Ver Perfil" style="margin-left: auto;">
        <i class="fas fa-user-circle"></i>
        <span><?php echo $nombre_usuario_top; ?></span>
        <span class="topbar__rol">(<?php echo $rol_usuario; ?>)</span>
    </a>
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
</script>