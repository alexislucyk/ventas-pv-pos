<?php
// Corregimos la ruta de infosesion ya que estamos en /procesos/
include '../pages/infosesion.php';
require_once '../funciones/funciones_pagos_ctacte.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id_cliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);
    $monto_pago = filter_input(INPUT_POST, 'monto_pago', FILTER_VALIDATE_FLOAT);
    
    $usuario = $_SESSION['usuario_nombre'] ?? 'Sistema';
    $condicion_pago = $_POST['condicion_pago'] ?? 'Efectivo';
    $n_recibo_raw = trim($_POST['n_recibo'] ?? '');

    $chq_nro = trim($_POST['chq_nro'] ?? '');
    $chq_vto = $_POST['chq_vto'] ?? '';
    $chq_emision = $_POST['chq_emision'] ?? '';

    if ($n_recibo_raw === '') {
        $stmt_max = $pdo->query("SELECT MAX(n_documento) FROM ctacte WHERE movimiento = 'Pago Cta.Cte.' AND empresa_id = " . (int)$empresa_id);
        $ultimo_n = (int)$stmt_max->fetchColumn();
        $n_recibo = ($ultimo_n > 0) ? $ultimo_n + 1 : 1;
    } else {
        $n_recibo = $n_recibo_raw;
    }

    $movimiento = "Pago Cta.Cte.";

    if (!$id_cliente || $monto_pago <= 0) {
        header('Location: /ruta/al/formulario.php?error=Datos inválidos.');
        exit();
    }

    if ($condicion_pago === 'Cheque' && (empty($chq_nro) || empty($chq_vto))) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Los datos del cheque (N° y Vencimiento) son obligatorios.']);
        } else {
            header('Location: ../pages/pagos_ctacte.php?error=Datos de cheque incompletos.');
        }
        exit();
    }
    
    $fecha_movimiento = date('Y-m-d H:i:s');
    $cero = 0;

    try {
        $detalle_mov = "PAGO RECIBIDO - CLIENTE #$id_cliente" . (!empty($n_recibo) ? " (Recibo $n_recibo)" : "");
        if ($condicion_pago === 'Cheque' && !empty($chq_nro)) {
            $detalle_mov .= " | CHQ N° $chq_nro (Vto: " . date('d/m/y', strtotime($chq_vto)) . ")";
        }

        $resultado = registrarPagoCuentaCorriente($pdo, [
            'id_cliente' => (int)$id_cliente,
            'empresa_id' => (int)$empresa_id,
            'monto_pago' => (float)$monto_pago,
            'n_recibo' => (string)$n_recibo,
            'fecha' => $fecha_movimiento,
            'usuario' => $usuario,
            'origen' => 'formulario'
        ], $_POST['imputaciones'] ?? [], function($pagoId) use ($pdo, $monto_pago, $condicion_pago, $detalle_mov, $fecha_movimiento, $usuario, $empresa_id, $sucursal_id) {
            $pdo->prepare("INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id)
                           VALUES ('INGRESO', ?, ?, ?, ?, ?, 0, ?, ?)")
                ->execute([$monto_pago, strtoupper($condicion_pago), $detalle_mov, $fecha_movimiento, $usuario, $empresa_id, $sucursal_id]);
        });

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        if ($isAjax) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id_movimiento' => $resultado['pago_id'], 'imputaciones' => $resultado]);
        } else {
            header('Location: ../pages/vista_recibo.php?id_mov=' . $resultado['pago_id']);
        }
        exit();
    } catch (Throwable $e) {
        error_log("Error al registrar pago CC: " . $e->getMessage());
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        if ($isAjax) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error en la base de datos: ' . $e->getMessage()]);
        } else {
            header('Location: ../pages/pagos_ctacte.php?error=Error en la base de datos: ' . urlencode($e->getMessage()));
        }
    }

} else {
    header('Location: /ruta/al/formulario.php');
    exit();
}
?>