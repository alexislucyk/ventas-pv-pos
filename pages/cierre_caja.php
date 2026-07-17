<?php
// pages/cierre_caja.php
include 'infosesion.php';
// VALIDACIÓN CRÍTICA:
require_once '../config/validar_permisos.php';
//restringirPagina('developer');
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Calculamos los totales actuales para mostrar como "Esperado"
try {
    $sql_sistema = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') THEN monto ELSE 0 END) as ingresos_efectivo,
        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as ingresos_transf,
        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
    FROM movimientos 
    WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
    
    $stmt = $pdo->prepare($sql_sistema);
    $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    $sistema = $stmt->fetch(PDO::FETCH_ASSOC);

    $ingresos_efectivo = $sistema['ingresos_efectivo'] ?: 0;
    $ingresos_transf = $sistema['ingresos_transf'] ?: 0;
    $egresos = $sistema['egresos'] ?: 0;
    
    // Saldo que DEBERÍA haber en efectivo
    $saldo_esperado = $ingresos_efectivo - $egresos;

} catch (Exception $e) {
    die("Error al calcular totales: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Caja | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .cierre-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .billetes-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .billete-input { display: flex; align-items: center; justify-content: space-between; background: #1a1a1a; padding: 10px; border-radius: 5px; }
        .billete-input input { width: 80px; text-align: center; }
        .total-real-box { font-size: 2rem; color: #ffc107; text-align: center; margin-top: 20px; border: 2px dashed #ffc107; padding: 10px; }
        .diferencia-box { text-align: center; font-weight: bold; padding: 10px; margin-top: 10px; border-radius: 5px; }
        .bg-ok { background: #28a745; color: white; }
        .bg-error { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Cierre de Caja Diario</h1>
        
        <form action="procesar_cierre.php" method="POST">
            <div class="cierre-container">
                <div class="card">
                    <h3>Conteo de Billetes</h3>
                    <div class="billetes-grid">
                        <?php 
                        $denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];
                        foreach ($denominaciones as $valor): ?>
                        <div class="billete-input">
                            <span>$<?php echo number_format($valor, 0, '', '.'); ?></span>
                            <input type="number" class="cant-billete input-field" data-valor="<?php echo $valor; ?>" name="b_<?php echo $valor; ?>" value="0" min="0">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="total-real-box">
                        TOTAL REAL: $<span id="total_real_display">0,00</span>
                    </div>
                    <input type="hidden" name="saldo_real_efectivo" id="saldo_real_input" value="0">
                </div>

                <div class="card">
                    <h3>Resumen del Sistema</h3>
                    <?php if ($ingresos_efectivo == 0 && $ingresos_transf == 0 && $egresos == 0): ?>
                        <div class="alert alert-info" style="background-color: #004a54; border-left: 4px solid #00bcd4;">
                            <i class="fas fa-info-circle"></i> No hay movimientos pendientes de cierre.
                        </div>
                    <?php endif; ?>
                    <div class="info-line">
                        <span>Ingresos Efectivo:</span>
                        <strong>$ <?php echo number_format($ingresos_efectivo, 2, ',', '.'); ?></strong>
                    </div>
                    <div class="info-line">
                        <span>(-) Egresos/Gastos:</span>
                        <strong style="color: #ff6b6b;">$ <?php echo number_format($egresos, 2, ',', '.'); ?></strong>
                    </div>
                    <hr>
                    <div class="info-line" style="font-size: 1.2rem;">
                        <span>Saldo Esperado:</span>
                        <strong id="saldo_esperado_val" data-valor="<?php echo $saldo_esperado; ?>">
                            $ <?php echo number_format($saldo_esperado, 2, ',', '.'); ?>
                        </strong>
                    </div>

                    <div id="box_diferencia" class="diferencia-box">
                        Diferencia: $ <span id="dif_val">0,00</span>
                    </div>

                    <hr>
                    <label>Observaciones (Opcional)</label>
                    <textarea name="observaciones" class="input-field" rows="4" placeholder="Ej: Faltó cobrar una entrega de cta cte..."></textarea>
                    <hr>
                    <div class="card" style="margin-top: 20px; border: 1px solid #17a2b8;">
                        <h3><i class="fas fa-cash-register"></i> Fondo para Mañana</h3>
                        <label>¿Cuánto dinero queda en caja para vuelto?</label>
                        <input type="number" name="fondo_vuelto" class="input-field" step="0.01" value="0" placeholder="Ej: 5000">
                        <p class="small text-muted">Este monto se restará del efectivo total y será tu saldo inicial de mañana.</p>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: 20px;">
                        CONFIRMAR Y CERRAR CAJA
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        const inputs = document.querySelectorAll('.cant-billete');
        const displayTotalReal = document.getElementById('total_real_display');
        const inputHiddenReal = document.getElementById('saldo_real_input');
        const displayDif = document.getElementById('dif_val');
        const boxDif = document.getElementById('box_diferencia');
        const esperado = parseFloat(document.getElementById('saldo_esperado_val').dataset.valor);

        inputs.forEach(input => {
            input.addEventListener('input', calcularTotales);
        });

        function calcularTotales() {
            let totalReal = 0;
            inputs.forEach(input => {
                const valor = parseFloat(input.dataset.valor);
                const cant = parseInt(input.value) || 0;
                totalReal += (valor * cant);
            });

            displayTotalReal.innerText = totalReal.toLocaleString('es-AR', { minimumFractionDigits: 2 });
            inputHiddenReal.value = totalReal;

            let diferencia = totalReal - esperado;
            displayDif.innerText = diferencia.toLocaleString('es-AR', { minimumFractionDigits: 2 });

            if (diferencia === 0) {
                boxDif.className = 'diferencia-box bg-ok';
                boxDif.innerText = "CAJA OK";
            } else if (diferencia > 0) {
                boxDif.className = 'diferencia-box bg-ok';
                boxDif.innerHTML = "SOBRANTE: $ " + diferencia.toLocaleString('es-AR', { minimumFractionDigits: 2 });
            } else {
                boxDif.className = 'diferencia-box bg-error';
                boxDif.innerHTML = "FALTANTE: $ " + diferencia.toLocaleString('es-AR', { minimumFractionDigits: 2 });
            }
        }
    </script>
</body>
</html>