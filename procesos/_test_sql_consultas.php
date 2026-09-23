<?php
// Test temporal: valida que las consultas de las vistas ejecuten sin errores.
$_SERVER['SCRIPT_NAME'] = '/pos_dev/procesos/_test_sql_consultas.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/laragon/www';

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../funciones/funciones_caja.php';

$empresa_id = 1;
$sucursal_id = 1;
$apertura = '2026-08-12 00:00:00';
$fecha_desde = '2026-08-12 00:00:00';
$fecha_hasta = date('Y-m-d H:i:s');

function probar($titulo, $pdo, $sql, $params) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  [OK]    $titulo -> " . json_encode($fila, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Exception $e) {
        echo "  [FALLA] $titulo -> " . $e->getMessage() . "\n";
    }
}

echo "\n== Consultas de caja_dashboard.php ==\n";
probar('resumen ingresos', $pdo, "SELECT 
        " . SQL_INGRESO_EFECTIVO . " as efectivo,
        " . SQL_INGRESO_TRANSFERENCIA . " as transferencia
    FROM movimientos 
    WHERE tipo = 'INGRESO' 
      AND cerrado = 0 "
      . SQL_FILTRO_SIN_FONDO_INICIAL .
      "AND fecha >= ?
      AND empresa_id = ? 
      AND sucursal_id = ?", [$apertura, $empresa_id, $sucursal_id]);

probar('resumen egresos', $pdo, "SELECT " . SQL_EGRESO_EFECTIVO . " as egresos,
       " . SQL_EGRESO_NO_EFECTIVO . " as egresos_no_efectivo
    FROM movimientos 
    WHERE tipo = 'EGRESO' 
      AND cerrado = 0 
      AND fecha >= ?
      AND empresa_id = ? 
      AND sucursal_id = ?", [$apertura, $empresa_id, $sucursal_id]);

probar('listado de movimientos', $pdo, "SELECT tipo, metodo_pago, detalle, monto, fecha, usuario 
     FROM movimientos 
     WHERE cerrado = 0 
       AND fecha >= ?
       AND empresa_id = ? 
       AND sucursal_id = ?"
     . SQL_FILTRO_SIN_FONDO_INICIAL .
     "ORDER BY id DESC LIMIT 10", [$apertura, $empresa_id, $sucursal_id]);

echo "\n== Consultas de pages/cierre_caja.php ==\n";
probar('sql_sistema', $pdo, "SELECT 
        " . SQL_INGRESO_EFECTIVO . " as ingresos_efectivo,
        " . SQL_INGRESO_TRANSFERENCIA . " as ingresos_transf,
        " . SQL_EGRESO_EFECTIVO . " as egresos,
        " . SQL_EGRESO_NO_EFECTIVO . " as egresos_no_efectivo
    FROM movimientos 
    WHERE cerrado = 0 
      AND empresa_id = :empresa_id 
      AND sucursal_id = :sucursal_id"
      . SQL_FILTRO_SIN_FONDO_INICIAL .
      "AND fecha BETWEEN :fecha_desde AND :fecha_hasta",
    [':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id, ':fecha_desde' => $fecha_desde, ':fecha_hasta' => $fecha_hasta]);

probar('sql_metodos', $pdo, "SELECT 
    " . SQL_INGRESO_EFECTIVO . " as efectivo,
    " . SQL_INGRESO_TRANSFERENCIA . " as transferencia,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'CHEQUE' 
             THEN monto ELSE 0 END) as cheques,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TARJETA' 
             THEN monto ELSE 0 END) as tarjetas,
    SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'TARJETA', 'MIXTO') 
             THEN monto ELSE 0 END) as otros,
    SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos,
    " . SQL_EGRESO_EFECTIVO . " as egresos_caja
FROM movimientos 
WHERE cerrado = 0 
  AND empresa_id = :empresa_id 
  AND sucursal_id = :sucursal_id"
  . SQL_FILTRO_SIN_FONDO_INICIAL .
  "AND fecha BETWEEN :fecha_desde AND :fecha_hasta",
  [':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id, ':fecha_desde' => $fecha_desde, ':fecha_hasta' => $fecha_hasta]);

echo "\n== Consulta de la sugerencia de fondo (pages/abrir_caja.php) ==\n";
try {
    $stmt = $pdo->prepare("SELECT fondo_reservado_vuelto, fecha_cierre, usuario 
                   FROM cierres_caja 
                   WHERE empresa_id = :empresa_id 
                     AND sucursal_id = :sucursal_id 
                     AND usuario NOT LIKE 'Sistema (Cierre Histórico)%'
                   ORDER BY fecha_cierre DESC, id DESC LIMIT 1");
    $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    echo "  [OK]    fondo sugerido -> " . json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Exception $e) {
    echo "  [FALLA] fondo sugerido -> " . $e->getMessage() . "\n";
}

echo "\n== obtener_resumen_caja() ==\n";
$res = obtener_resumen_caja($pdo, $empresa_id, $sucursal_id, date('Y-m-d'));
echo "  [OK]    resumen -> " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
