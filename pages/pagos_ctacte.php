<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}
require '../config/db_config.php'; // Asegúrate que el path sea correcto

// Opcional: Manejo de mensajes de éxito/error después del POST
$mensaje = '';
if (isset($_GET['success'])) {
    $mensaje = '<p style="color: green; font-weight: bold;">✅ ' . htmlspecialchars($_GET['success']) . '</p>';
} elseif (isset($_GET['error'])) {
    $mensaje = '<p style="color: red; font-weight: bold;">❌ Error: ' . htmlspecialchars($_GET['error']) . '</p>';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Pagos a Cuenta Corriente</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Estilos básicos para el formulario */
        .card { padding: 20px; max-width: 600px; margin: 30px auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover { background-color: #45a049; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <h1>💵 Registrar Pago a Cuenta Corriente</h1>
        
        <?php echo $mensaje; // Mostrar mensaje de éxito/error ?>

        <div class="card">
            <form action="../procesos/registrar_pago_cc.php" method="POST">
                
                <div class="form-group">
                    <label for="id_cliente">Cliente:</label>
                    <select id="id_cliente" name="id_cliente" required>
                        <option value="">Cargando clientes...</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="monto_pago">Monto a Pagar ($):</label>
                    <input type="number" id="monto_pago" name="monto_pago" step="0.01" min="0.01" required>
                </div>

                <div class="form-group">
                    <label for="n_recibo">N° de Recibo / Documento (Opcional):</label>
                    <input type="text" id="n_recibo" name="n_recibo">
                </div>
                
                <div class="form-group">
                    <label for="condicion_pago">Condición de Pago:</label>
                    <select id="condicion_pago" name="condicion_pago" required>
                        <option value="Efectivo">Efectivo</option>
                        <option value="Transferencia">Transferencia</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Tarjeta">Tarjeta</option>
                    </select>
                </div>

                <button type="submit">Registrar Pago</button>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const clienteSelector = document.getElementById('id_cliente');

    // Función para cargar la lista de clientes con CC
    function cargarClientesCC() {
        // ⚠️ Asegúrate que la ruta al AJAX sea correcta (desde /pages/)
        const url = '../ajax/obtener_clientes_cc.php'; 

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error('Error de red al cargar clientes.');
                return response.json();
            })
            .then(clientes => {
                let options = '<option value="">-- Seleccione un Cliente --</option>';
                if (clientes.length === 0) {
                     options = '<option value="">No hay clientes con CC activa</option>';
                } else {
                    clientes.forEach(cliente => {
                        options += `<option value="${cliente.id_cliente}">
                            ${cliente.nombre_completo} (CUIT: ${cliente.cuit || 'S/N'})
                        </option>`;
                    });
                }
                clienteSelector.innerHTML = options;
            })
            .catch(error => {
                console.error('Error al cargar clientes:', error);
                clienteSelector.innerHTML = '<option value="">Error al cargar clientes</option>';
            });
    }

    cargarClientesCC();
});
</script>
</body>
</html>