<?php
// pages/movimiento_manual.php
include 'infosesion.php';
require '../config/db_config.php';

$mensaje = '';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'] ?? ''; // INGRESO o EGRESO
    $monto = (float)str_replace(',', '.', $_POST['monto'] ?? '0');
    $metodo = $_POST['metodo_pago'] ?? 'EFECTIVO';
    $detalle = trim($_POST['detalle'] ?? '');
    $usuario = $_SESSION['usuario'] ?? 'Sistema';

    if ($monto > 0 && !empty($detalle)) {
        try {
            $sql = "INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id) 
                    VALUES (?, ?, ?, ?, NOW(), ?, 0, ?, ?)";
            $pdo->prepare($sql)->execute([$tipo, $monto, $metodo, $detalle, $usuario, $empresa_id, $sucursal_id]);
            
            $mensaje = "✅ Movimiento registrado correctamente.";
        } catch (Exception $e) {
            $mensaje = "❌ Error al registrar: " . $e->getMessage();
        }
    } else {
        $mensaje = "❌ Por favor, complete todos los campos correctamente.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Movimiento Manual | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <style>
        .form-movimiento { max-width: 500px; margin: 20px auto; }
        .radio-group { display: flex; gap: 20px; margin-bottom: 20px; }
        .radio-option {
            flex: 1;
            padding: 15px;
            border: 2px solid #333;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: 0.3s;
        }
        .radio-option input { display: none; }
        .radio-option:hover { border-color: #007bff; }
        
        /* Estilos para el tipo de movimiento */
        input[value="INGRESO"]:checked + span { color: #28a745; font-weight: bold; }
        input[value="EGRESO"]:checked + span { color: #dc3545; font-weight: bold; }
        input:checked + span { font-size: 1.1rem; }
        .selected-ingreso { border-color: #28a745 !important; background: rgba(40, 167, 69, 0.1); }
        .selected-egreso { border-color: #dc3545 !important; background: rgba(220, 53, 69, 0.1); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        <h1>Registrar Movimiento Manual</h1>

        <?php if ($mensaje): ?>
            <div class="alert <?php echo str_contains($mensaje, '❌') ? 'alert-error' : 'alert-success'; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="card form-movimiento">
            <form method="POST" id="formMovimiento">
                <label>Tipo de Movimiento</label>
                <div class="radio-group">
                    <label class="radio-option" id="label-ingreso">
                        <input type="radio" name="tipo" value="INGRESO" required onclick="updateStyle('INGRESO')">
                        <span>➕ INGRESO</span>
                    </label>
                    <label class="radio-option" id="label-egreso">
                        <input type="radio" name="tipo" value="EGRESO" onclick="updateStyle('EGRESO')">
                        <span>➖ EGRESO</span>
                    </label>
                </div>

                <label>Monto ($)</label>
                <input type="number" step="0.01" name="monto" class="input-field" placeholder="0.00" required>

                <label>Método de Pago</label>
                <select name="metodo_pago" class="input-field">
                    <option value="EFECTIVO">EFECTIVO</option>
                    <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                </select>

                <label>Detalle / Concepto</label>
                <textarea name="detalle" class="input-field" rows="3" placeholder="Ej: Pago de flete, retiro de efectivo, etc." required></textarea>

                <button type="submit" class="btn btn-primary btn-block">Guardar Movimiento</button>
                <a href="caja_dashboard.php" class="btn btn-secondary btn-block" style="text-align:center; margin-top:10px; display:block;">Volver al Dashboard</a>
            </form>
        </div>
    </div>

    <script>
        function updateStyle(tipo) {
            const ing = document.getElementById('label-ingreso');
            const egr = document.getElementById('label-egreso');
            if (tipo === 'INGRESO') {
                ing.classList.add('selected-ingreso');
                egr.classList.remove('selected-egreso');
            } else {
                egr.classList.add('selected-egreso');
                ing.classList.remove('selected-ingreso');
            }
        }
    </script>
</body>
</html>