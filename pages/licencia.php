<?php
// pages/licencia.php
include 'infosesion.php';
require_once '../config/db_config.php';
require_once '../config/validar_permisos.php';
require_once '../config/licencia_manager.php';

// --- CONTROL DE ACCESO ---
// El Developer siempre entra. Otros roles requieren el permiso 'gestionar_licencia'
$permiso_clave = 'gestionar_licencia';
$tiene_acceso = false;

if (isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'developer') {
    $tiene_acceso = true;
} elseif (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
    $tiene_acceso = in_array($permiso_clave, $_SESSION['permisos']);
}

if (!$tiene_acceso) {
    header("Location: index.php?error=acceso_denegado");
    exit();
}

$info = obtenerEstadoLicencia();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Licencia | Sistemas Lucyk</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .licencia-card { border-left: 5px solid #00bcd4; }
        .status-badge { padding: 5px 10px; border-radius: 4px; font-weight: bold; }
        .status-active { background: #28a745; color: white; }
        .status-blocked { background: #e74c3c; color: white; } /* Nuevo estilo para licencia bloqueada */
        .status-expired { background: #dc3545; color: white; }
        .info-grid { display: grid; grid-template-columns: 200px 1fr; gap: 10px; margin-top: 20px; }
        .grace-period { margin-top: 20px; padding: 15px; background: rgba(0, 188, 212, 0.1); border-radius: 8px; border: 1px solid #00bcd4; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>🔐 Sistema de Gestion de Licencias</h1>

        <div class="card licencia-card">
            <h3>Estado del Comprobante de Activación</h3>
            <p>Información técnica de la conexión con el SGL.</p>
            
            <div class="info-grid">
                <strong>ID de Licencia:</strong>
                <code><?php echo $info['key']; ?></code>

                <strong>Versión Instalada:</strong>
                <span style="color: var(--accent); font-weight: bold;"><?php echo defined('APP_VERSION') ? APP_VERSION : '---'; ?></span>

                <strong>Entorno del Servidor:</strong>
                <span style="font-size: 0.9em;"><?php echo PHP_OS . " | PHP " . phpversion(); ?></span>

                <strong>Host Local:</strong>
                <span style="font-family: monospace; font-size: 0.9em;"><?php echo $_SERVER['HTTP_HOST']; ?></span>

                <strong>IP del Servidor:</strong>
                <span><?php echo $info['server_ip']; ?></span>

                <strong>Estado de Activación:</strong>
                <span>
                    <?php
                    $status_class = '';
                    $display_status = '';
                    if ($info['status'] === 'active') {
                        $status_class = 'status-active';
                        $display_status = 'Activada';
                    } elseif ($info['status'] === 'blocked') {
                        $status_class = 'status-blocked';
                        $display_status = 'BLOQUEADA';
                    } else {
                        $status_class = 'status-expired';
                        $display_status = 'Expirada / Sin conexión';
                    }
                    ?>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo strtoupper($display_status); ?>
                    </span>
                </span>

                <strong>Última Validación Online:</strong>
                <span><?php echo $info['last_check']; ?></span>

                <strong>Modo de Conexión:</strong>
                <span>
                    <?php echo $info['is_offline'] ? '⚠️ TRABAJANDO EN MODO OFFLINE' : '✅ SINCRONIZADO CON EL SGL'; ?>
                </span>

                <strong>ID de Activación:</strong>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <code id="hwid_display" style="background: #000; padding: 5px 10px; color: #f1c40f; border-radius: 4px; border: 1px solid #444; min-width: 150px; text-align: center;">---</code>
                    <button class="btn btn-sm" style="background: #3498db; color: white; border: none; padding: 5px 10px; cursor: pointer;" onclick="generarIdentificador()">✨ Generar ID</button>
                </div>
            </div>

            <?php if ($info['last_error']): ?>
            <div style="margin-top: 15px; padding: 10px; background: rgba(220, 53, 69, 0.1); border: 1px solid #dc3545; color: #ffbcbc; border-radius: 4px; font-size: 0.85em;">
                <strong>⚠️ Error de Sincronización:</strong><br>
                <?php echo htmlspecialchars($info['last_error']); ?>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['usuario_rol'] === 'developer'): // Solo el developer puede cambiar la IP ?>
            <div class="card" style="margin-top: 20px; border-top: 3px solid #00bcd4;">
                <h3>⚙️ Configuración del Servidor de Licencias</h3>
                <label for="licenciaServerIp">Dirección IP del Servidor de Licencias:</label>
                <input type="text" id="licenciaServerIp" class="input-field" value="<?php echo htmlspecialchars($info['server_ip']); ?>" placeholder="Ej: 192.168.1.100">
                <button class="btn btn-primary" onclick="guardarNuevaIp()">
                    💾 Guardar IP
                </button>
                <div id="ipSaveMessage" style="margin-top: 10px; font-weight: bold;"></div>
            </div>
            <?php endif; ?>



            <?php if ($info['grace_days_left'] > 0): ?>
            <div class="grace-period">
                <strong>🕒 Periodo de Gracia por Internet:</strong> 
                El sistema permitirá operar sin conexión por <strong><?php echo $info['grace_days_left']; ?> días</strong> más antes de bloquearse.
            </div>
            <?php endif; ?>
        </div>

        <?php if ($_SESSION['usuario_rol'] === 'developer'): ?>
        <div class="card" style="border-top: 3px solid #f1c40f;">
            <h3>🛠️ Panel de Control Developer</h3>
            <p>Puedes otorgar permiso a los administradores para ver esta página asignando el permiso <b>gestionar_licencia</b> en el ABM de Usuarios.</p>
            <button class="btn btn-primary" onclick="window.location.href='licencia.php?force_sync=1';">
                🔄 Reintentar Sincronización Online ahora
            </button>
        </div>
        <?php endif; ?>

        <div class="card">
            <h4>Seguridad y Resiliencia</h4>
            <p class="small text-muted">La licencia se valida automáticamente cada 24 horas. El archivo de persistencia local garantiza que el comercio no se detenga ante cortes de fibra óptica o caídas momentáneas de su App Engine por un máximo de 20 días.</p>
        </div>
    </div>

    <script>
        function generarIdentificador() {
            const hwid = "<?php echo $info['hw_id']; ?>";
            document.getElementById('hwid_display').innerText = hwid;
            
            // Efecto visual de copiado al portapapeles
            navigator.clipboard.writeText(hwid).then(() => {
                alert("Identificador generado y copiado al portapapeles.\nEnvíe este código al administrador para activar su licencia.");
            });
        }

        function guardarNuevaIp() {
            const newIp = document.getElementById('licenciaServerIp').value;
            const messageDiv = document.getElementById('ipSaveMessage');
            messageDiv.innerHTML = 'Guardando...';
            messageDiv.style.color = '#00bcd4';

            fetch('../ajax/update_licencia_ip.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'new_ip=' + encodeURIComponent(newIp)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = '✅ ' + data.message;
                    messageDiv.style.color = '#28a745';
                    // Recargar la página para que la nueva IP se refleje en la validación
                    setTimeout(() => window.location.reload(), 1500); 
                } else {
                    messageDiv.innerHTML = '❌ ' + data.message;
                    messageDiv.style.color = '#dc3545';
                }
            })
            .catch(error => {
                messageDiv.innerHTML = '❌ Error de conexión al servidor.';
                messageDiv.style.color = '#dc3545';
            });
        }
    </script>
</body>
</html>