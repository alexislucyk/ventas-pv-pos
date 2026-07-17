<?php
// cambiar_sucursal.php - Cambiar sucursal activa en sesion
require_once '../config/db_config.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$sucursal_id = $_POST['sucursal_id'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

if ($sucursal_id === null || !is_numeric($sucursal_id)) {
    echo json_encode(['success' => false, 'error' => 'Sucursal inválida']);
    exit;
}

$sucursal_id = (int)$sucursal_id;

if (!$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'No hay empresa activa']);
    exit;
}

try {
    if ($sucursal_id === 0) {
        // "Central" = Todas las sucursales
        $_SESSION['sucursal_id'] = 0;
        
        session_write_close();
        
        echo json_encode([
            'success' => true, 
            'sucursal_id' => 0,
            'sucursal_nombre' => 'Central (Todas las sucursales)',
            'message' => 'Vista cambiada a Central'
        ]);
    } else {
        // Verificar que la sucursal existe y pertenece a la empresa del usuario
        $stmt = $pdo->prepare("SELECT id, nombre_sucursal FROM sucursales WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$sucursal_id, $empresa_id]);
        $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sucursal) {
            // Guardar nueva sucursal en sesión
            $_SESSION['sucursal_id'] = $sucursal_id;
            
            // Forzar escritura de sesión
            session_write_close();
            
            echo json_encode([
                'success' => true, 
                'sucursal_id' => $sucursal_id,
                'sucursal_nombre' => $sucursal['nombre_sucursal'],
                'message' => 'Sucursal cambiada correctamente'
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Sucursal no encontrada o sin acceso']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
