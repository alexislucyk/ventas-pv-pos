<?php
// pages/abrir_caja.php
include 'infosesion.php';
require_once '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

$mensaje = $_SESSION['error_caja'] ?? null;
unset($_SESSION['error_caja']);

// Obtener estado actual
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
$caja_abierta = $estado && $estado['estado'] === 'ABIERTA';

// Si la caja ya está abierta, redirigir al dashboard
if ($caja_abierta) {
    header("Location: " . url('caja-dashboard'));
    exit();
}

// Obtener el fondo reservado del último cierre (modelo por sesión: el cierre más reciente)
$sql_fondo_ultimo = "SELECT fondo_reservado_vuelto 
                   FROM cierres_caja 
                   WHERE empresa_id = :empresa_id 
                     AND sucursal_id = :sucursal_id 
                   ORDER BY fecha_cierre DESC, id DESC LIMIT 1";

$stmt_fondo = $pdo->prepare($sql_fondo_ultimo);
$stmt_fondo->execute([
    ':empresa_id' => $empresa_id,
    ':sucursal_id' => $sucursal_id
]);

$fondo_ayer = $stmt_fondo->fetchColumn();
$fondo_ayer = $fondo_ayer ? (float)$fondo_ayer : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Abrir Caja | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <style>
        .apertura-container {
            max-width: 600px;
            margin: 50px auto;
        }
        .fondo-info {
            background: #004a54;
            border-left: 4px solid #00bcd4;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <div class="apertura-container">
            <h1>Apertura de Caja</h1>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($fondo_ayer > 0): ?>
                <div class="fondo-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Fondo de vuelto del último cierre: $<?php echo number_format($fondo_ayer, 2, ',', '.'); ?></strong>
                    <p class="small">Este monto se sugiere como saldo inicial para esta apertura.</p>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h3>Datos de Apertura</h3>
                <form action="<?php echo url('ajax/abrir_caja.php'); ?>" method="POST">
                    <div class="form-group">
                        <label>Saldo Inicial en Efectivo ($)</label>
                        <input type="number" 
                               name="saldo_inicial" 
                               class="input-field" 
                               step="0.01" 
                               min="0" 
                               value="<?php echo $fondo_ayer; ?>" 
                               required>
                        <p class="small text-muted">
                            Dinero físico que hay en caja al iniciar el día.
                            <?php if ($fondo_ayer > 0): ?>
                                <br>Se sugiere usar $<?php echo number_format($fondo_ayer, 2, ',', '.'); ?> del día anterior.
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="form-group">
                        <label>Observaciones (Opcional)</label>
                        <textarea name="observaciones" 
                                  class="input-field" 
                                  rows="3" 
                                  placeholder="Ej: Fondo inicial para el día..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-lock-open"></i> ABRIR CAJA
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Deshabilitar botón y mostrar carga
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar mensaje de éxito con estilos de la app
                    const successDiv = document.createElement('div');
                    successDiv.className = 'alert alert-success';
                    successDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); animation: slideInRight 0.3s ease-out;';
                    successDiv.innerHTML = '<i class="fas fa-check-circle"></i> <strong>Éxito:</strong> ' + data.mensaje;
                    document.body.appendChild(successDiv);
                    
                    // Redirigir después de 1.5 segundos
                    setTimeout(() => {
                        window.location.href = 'caja_dashboard.php';
                    }, 1500);
                } else {
                    // Mostrar error con estilos de la app
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'alert alert-danger';
                    errorDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); animation: slideInRight 0.3s ease-out;';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> <strong>Error:</strong> ' + data.mensaje;
                    document.body.appendChild(errorDiv);
                    
                    // Rehabilitar botón
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    
                    // Ocultar mensaje después de 4 segundos
                    setTimeout(() => {
                        errorDiv.style.opacity = '0';
                        errorDiv.style.transition = 'opacity 0.5s';
                        setTimeout(() => errorDiv.remove(), 500);
                    }, 4000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarMensaje('Error', '❌ Error al procesar la solicitud.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    </script>
</body>
</html>
