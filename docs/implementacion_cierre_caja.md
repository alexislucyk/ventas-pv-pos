# Plan de Implementación - Sistema de Cierres de Caja

> **Basado en:** `ANALISIS_CAJA_CIERRES.md`  
> **Versión:** 2.1.0  
> **Fecha:** 08/03/2026  
> **Estado:** Plan de Implementación  

---

## 1. OBJETIVOS

### 1.1 Objetivo General
Implementar un sistema robusto de cierres de caja que garantice la integridad de los datos, prevenga errores operativos y proporcione trazabilidad completa de las operaciones financieras.

### 1.2 Objetivos Específicos

1. ✅ Implementar estados de caja (Abierta/Cerrada) con control de acceso
2. ✅ Validar estado de caja antes de permitir operaciones (ventas, cobros, pagos)
3. ✅ Registrar saldo inicial al abrir caja (fondo de vuelto del día anterior)
4. ✅ Prevenir cierres duplicados en el mismo día
5. ✅ Unificar criterios de filtrado de movimientos
6. ✅ Implementar desglose de ventas mixtas
7. ✅ Crear reporte histórico de cierres
8. ✅ Agregar log de auditoría básico

---

## 2. CAMBIOS EN BASE DE DATOS

### 2.1 Migración SQL: `migrations/agregar_estado_caja.sql`

```sql
-- ============================================
-- MIGRACIÓN: Sistema de Estados de Caja
-- Versión: 2.1.0
-- Fecha: 08/03/2026
-- ============================================

-- 1. Agregar campo para estado de caja en tabla movimientos
ALTER TABLE `movimientos` 
ADD COLUMN `es_fondo_inicial` TINYINT(1) DEFAULT 0 COMMENT '1=Es fondo inicial de caja' 
AFTER `cerrado`;

-- 2. Agregar campo para tipo de cierre en tabla cierres_caja
ALTER TABLE `cierres_caja` 
ADD COLUMN `tipo_cierre` ENUM('DIARIO','PARCIAL') DEFAULT 'DIARIO' 
COMMENT 'Tipo de cierre realizado' 
AFTER `fondo_reservado_vuelto`;

-- 3. Agregar campo para número de cierre (para identificación única)
ALTER TABLE `cierres_caja` 
ADD COLUMN `numero_cierre` INT DEFAULT NULL 
COMMENT 'Número secuencial de cierre por sucursal' 
AFTER `tipo_cierre`;

-- 4. Crear tabla de estado de caja
CREATE TABLE `estado_caja` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `sucursal_id` INT NOT NULL,
  `fecha` DATE NOT NULL,
  `estado` ENUM('ABIERTA','CERRADA') DEFAULT 'CERRADA',
  `saldo_inicial` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Fondo con el que se abrió la caja',
  `usuario_apertura` VARCHAR(50) DEFAULT NULL,
  `fecha_apertura` DATETIME DEFAULT NULL,
  `usuario_cierre` VARCHAR(50) DEFAULT NULL,
  `fecha_cierre` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_caja_dia` (`empresa_id`, `sucursal_id`, `fecha`),
  FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- 5. Crear tabla de log de auditoría de cierres
CREATE TABLE `cierres_caja_audit` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `cierre_id` INT NOT NULL,
  `accion` ENUM('CREADO','MODIFICADO','ANULADO') NOT NULL,
  `usuario` VARCHAR(50) NOT NULL,
  `fecha_accion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `datos_anteriores` JSON DEFAULT NULL,
  `datos_nuevos` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`cierre_id`) REFERENCES `cierres_caja`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- 6. Agregar índices compuestos para mejorar rendimiento
ALTER TABLE `movimientos` 
ADD INDEX `idx_movimientos_empresa_sucursal_cerrado` (`empresa_id`, `sucursal_id`, `cerrado`);

ALTER TABLE `cierres_caja` 
ADD INDEX `idx_cierres_empresa_sucursal_fecha` (`empresa_id`, `sucursal_id`, `fecha_cierre`);

-- 7. Poblar tabla estado_caja con registros históricos (si existen cierres)
INSERT INTO `estado_caja` (`empresa_id`, `sucursal_id`, `fecha`, `estado`, `fecha_cierre`, `usuario_cierre`)
SELECT 
  DISTINCT c.empresa_id, 
  c.sucursal_id, 
  DATE(c.fecha_cierre) as fecha,
  'CERRADA' as estado,
  c.fecha_cierre,
  c.usuario
FROM `cierres_caja` c
WHERE NOT EXISTS (
  SELECT 1 FROM `estado_caja` ec 
  WHERE ec.empresa_id = c.empresa_id 
    AND ec.sucursal_id = c.sucursal_id 
    AND ec.fecha = DATE(c.fecha_cierre)
);

-- 8. Actualizar movimientos de fondo inicial
UPDATE `movimientos` 
SET `es_fondo_inicial` = 1 
WHERE `detalle` LIKE 'FONDO INICIAL%';

-- 9. Agregar campo saldo_inicial a cierres existentes (si hay datos)
UPDATE `cierres_caja` c
SET `saldo_inicial` = (
  SELECT COALESCE(SUM(m.monto), 0)
  FROM `movimientos` m
  WHERE m.empresa_id = c.empresa_id
    AND m.sucursal_id = c.sucursal_id
    AND m.es_fondo_inicial = 1
    AND DATE(m.fecha) = DATE(c.fecha_cierre)
    AND m.cerrado = 1
)
WHERE c.saldo_inicial IS NULL;

-- 10. Crear función para obtener número de cierre
DELIMITER //
CREATE FUNCTION `obtener_numero_cierre`(
  p_empresa_id INT,
  p_sucursal_id INT
) RETURNS INT
DETERMINISTIC
BEGIN
  DECLARE v_numero INT;
  SELECT COALESCE(MAX(numero_cierre), 0) + 1 
  INTO v_numero
  FROM `cierres_caja`
  WHERE empresa_id = p_empresa_id 
    AND sucursal_id = p_sucursal_id;
  RETURN v_numero;
END //
DELIMITER ;
```

**Instrucciones de ejecución:**
```bash
# Opción 1: Ejecutar manualmente en phpMyAdmin o MySQL Workbench
mysql -u root -p pos_dev < migrations/agregar_estado_caja.sql

# Opción 2: Ejecutar desde PHP (recomendado)
php procesos/ejecutar_migracion_21.php
```

---

## 3. ARCHIVOS A CREAR/MODIFICAR

### 3.1 Archivos Nuevos

| Archivo | Propósito |
|---------|-----------|
| `pages/abrir_caja.php` | Pantalla para abrir caja al inicio del día |
| `pages/reporte_cierres.php` | Reporte histórico de cierres |
| `ajax/verificar_estado_caja.php` | Verificar si la caja está abierta |
| `ajax/abrir_caja.php` | Procesar apertura de caja |
| `funciones/funciones_caja.php` | Funciones auxiliares para caja |
| `migrations/agregar_estado_caja.sql` | Migración de base de datos |
| `procesos/ejecutar_migracion_21.php` | Ejecutor de migración |

### 3.2 Archivos a Modificar

| Archivo | Cambios |
|---------|---------|
| `pages/ventas.php` | Validar estado de caja antes de crear venta |
| `pages/cobro_cuotas.php` | Validar estado de caja antes de procesar pago |
| `pages/compras.php` | Validar estado de caja antes de registrar compra |
| `pages/compras_rapidas.php` | Validar estado de caja antes de registrar compra |
| `pages/anulaciones.php` | Validar estado de caja antes de anular |
| `pages/movimiento_manual.php` | Validar estado de caja antes de crear movimiento |
| `pages/caja_dashboard.php` | Unificar filtrado, mostrar estado de caja |
| `pages/cierre_caja.php` | Agregar validación de cierre previo, mostrar estado |
| `pages/procesar_cierre.php` | Mejorar validaciones, agregar auditoría |
| `pages/infosesion.php` | Verificar estado de caja en guardia global |
| `config/validar_permisos.php` | Agregar permisos para nuevos módulos |
| `pages/sidebar.php` | Agregar acceso a "Abrir Caja" y "Reporte de Cierres" |

---

## 4. IMPLEMENTACIÓN PASO A PASO

### Fase 1: Migración y Funciones Base (Semana 1)

#### Paso 1.1: Ejecutar Migración SQL

**Acciones:**
1. Crear archivo `migrations/agregar_estado_caja.sql` (ver sección 2.1)
2. Crear archivo `procesos/ejecutar_migracion_21.php`
3. Ejecutar migración
4. Verificar que todas las tablas se crearon correctamente

**Criterio de éxito:**
- Tabla `estado_caja` creada
- Tabla `cierres_caja_audit` creada
- Campos nuevos agregados
- Índices creados
- Función `obtener_numero_cierre` disponible

#### Paso 1.2: Crear Funciones Auxiliares

**Archivo:** `funciones/funciones_caja.php`

```php
<?php
/**
 * Funciones auxiliares para el sistema de caja
 * Versión: 2.1.0
 */

/**
 * Obtener el estado de caja actual para una empresa/sucursal/fecha
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional, default: hoy)
 * @return array Estado de caja
 */
function obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    if (!$fecha) {
        $fecha = date('Y-m-d');
    }
    
    $sql = "SELECT * FROM estado_caja 
            WHERE empresa_id = :empresa_id 
              AND sucursal_id = :sucursal_id 
              AND fecha = :fecha";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':sucursal_id' => $sucursal_id,
        ':fecha' => $fecha
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verificar si la caja está abierta
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional)
 * @return bool True si está abierta, False si está cerrada o no existe
 */
function caja_esta_abierta($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    $estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha);
    return $estado && $estado['estado'] === 'ABIERTA';
}

/**
 * Abrir caja para el día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param float $saldo_inicial Saldo inicial de caja
 * @param string $usuario Usuario que abre la caja
 * @return array Resultado de la operación
 */
function abrir_caja($pdo, $empresa_id, $sucursal_id, $saldo_inicial, $usuario) {
    try {
        $fecha = date('Y-m-d');
        $fecha_apertura = date('Y-m-d H:i:s');
        
        // Verificar si ya existe un registro para hoy
        $estado_existente = obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha);
        
        if ($estado_existente) {
            if ($estado_existente['estado'] === 'ABIERTA') {
                return [
                    'success' => false,
                    'mensaje' => 'La caja ya está abierta para hoy.'
                ];
            } else {
                // Reabrir caja cerrada (caso especial)
                $sql = "UPDATE estado_caja 
                        SET estado = 'ABIERTA', 
                            saldo_inicial = :saldo_inicial,
                            usuario_apertura = :usuario,
                            fecha_apertura = :fecha_apertura,
                            updated_at = NOW()
                        WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':saldo_inicial' => $saldo_inicial,
                    ':usuario' => $usuario,
                    ':fecha_apertura' => $fecha_apertura,
                    ':id' => $estado_existente['id']
                ]);
                
                return [
                    'success' => true,
                    'mensaje' => 'Caja reabierta correctamente.'
                ];
            }
        }
        
        // Crear nuevo registro de caja abierta
        $sql = "INSERT INTO estado_caja 
                (empresa_id, sucursal_id, fecha, estado, saldo_inicial, usuario_apertura, fecha_apertura)
                VALUES (:empresa_id, :sucursal_id, :fecha, 'ABIERTA', :saldo_inicial, :usuario, :fecha_apertura)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha' => $fecha,
            ':saldo_inicial' => $saldo_inicial,
            ':usuario' => $usuario,
            ':fecha_apertura' => $fecha_apertura
        ]);
        
        // Si hay saldo inicial, crear movimiento de fondo inicial
        if ($saldo_inicial > 0) {
            $sql_mov = "INSERT INTO movimientos 
                        (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, es_fondo_inicial)
                        VALUES (:empresa_id, :sucursal_id, 'INGRESO', :monto, 'EFECTIVO', 'FONDO INICIAL (APERTURA)', :fecha, :usuario, 0, 1)";
            
            $stmt_mov = $pdo->prepare($sql_mov);
            $stmt_mov->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':monto' => $saldo_inicial,
                ':fecha' => $fecha_apertura,
                ':usuario' => $usuario
            ]);
        }
        
        return [
            'success' => true,
            'mensaje' => 'Caja abierta correctamente.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'mensaje' => 'Error al abrir caja: ' . $e->getMessage()
        ];
    }
}

/**
 * Cerrar caja del día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $usuario Usuario que cierra la caja
 * @param float $fondo_vuelto Fondo para el día siguiente (opcional)
 * @return array Resultado de la operación
 */
function cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo_vuelto = 0) {
    try {
        $fecha = date('Y-m-d');
        $fecha_cierre = date('Y-m-d H:i:s');
        
        // Verificar que la caja esté abierta
        $estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id, $fecha);
        
        if (!$estado || $estado['estado'] !== 'ABIERTA') {
            return [
                'success' => false,
                'mensaje' => 'La caja no está abierta. No se puede cerrar.'
            ];
        }
        
        // Verificar que no haya un cierre para hoy
        $sql_check_cierre = "SELECT id FROM cierres_caja 
                             WHERE empresa_id = :empresa_id 
                               AND sucursal_id = :sucursal_id 
                               AND DATE(fecha_cierre) = :fecha";
        
        $stmt_check = $pdo->prepare($sql_check_cierre);
        $stmt_check->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha' => $fecha
        ]);
        
        if ($stmt_check->fetch()) {
            return [
                'success' => false,
                'mensaje' => 'Ya existe un cierre para el día de hoy.'
            ];
        }
        
        // Iniciar transacción
        $pdo->beginTransaction();
        
        // Calcular totales
        $sql_totales = "SELECT 
            SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                     THEN monto ELSE 0 END) as ingresos_efectivo,
            SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                     THEN monto ELSE 0 END) as ingresos_transf,
            SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
        FROM movimientos 
        WHERE cerrado = 0 
          AND empresa_id = :empresa_id 
          AND sucursal_id = :sucursal_id";
        
        $stmt_totales = $pdo->prepare($sql_totales);
        $stmt_totales->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id
        ]);
        
        $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
        
        $ing_efectivo = (float)($totales['ingresos_efectivo'] ?? 0);
        $ing_transf = (float)($totales['ingresos_transf'] ?? 0);
        $egresos = (float)($totales['egresos'] ?? 0);
        
        $saldo_esperado = $ing_efectivo - $egresos;
        
        // Obtener número de cierre
        $numero_cierre = obtener_numero_cierre($pdo, $empresa_id, $sucursal_id);
        
        // Insertar en cierres_caja
        $sql_cierre = "INSERT INTO cierres_caja 
                       (empresa_id, sucursal_id, fecha_cierre, saldo_inicial, 
                        ingresos_efectivo, ingresos_transf, egresos, 
                        saldo_esperado_efectivo, saldo_real_efectivo, diferencia,
                        fondo_reservado_vuelto, tipo_cierre, numero_cierre, usuario)
                       VALUES (:empresa_id, :sucursal_id, :fecha_cierre, :saldo_inicial,
                               :ingresos_efectivo, :ingresos_transf, :egresos,
                               :saldo_esperado, :saldo_real, :diferencia,
                               :fondo_vuelto, 'DIARIO', :numero_cierre, :usuario)";
        
        // Por ahora usamos saldo_esperado como saldo_real (se debe actualizar con el conteo físico)
        $stmt_cierre = $pdo->prepare($sql_cierre);
        $stmt_cierre->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id,
            ':fecha_cierre' => $fecha_cierre,
            ':saldo_inicial' => $estado['saldo_inicial'],
            ':ingresos_efectivo' => $ing_efectivo,
            ':ingresos_transf' => $ing_transf,
            ':egresos' => $egresos,
            ':saldo_esperado' => $saldo_esperado,
            ':saldo_real' => $saldo_esperado, // Se actualiza en el formulario de cierre
            ':diferencia' => 0, // Se calcula en el formulario
            ':fondo_vuelto' => $fondo_vuelto,
            ':numero_cierre' => $numero_cierre,
            ':usuario' => $usuario
        ]);
        
        $cierre_id = $pdo->lastInsertId();
        
        // Marcar movimientos como cerrados
        $sql_update = "UPDATE movimientos SET cerrado = 1 
                       WHERE cerrado = 0 
                         AND empresa_id = :empresa_id 
                         AND sucursal_id = :sucursal_id";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            ':empresa_id' => $empresa_id,
            ':sucursal_id' => $sucursal_id
        ]);
        
        // Actualizar estado de caja
        $sql_estado = "UPDATE estado_caja 
                       SET estado = 'CERRADA', 
                           usuario_cierre = :usuario,
                           fecha_cierre = :fecha_cierre
                       WHERE id = :id";
        
        $stmt_estado = $pdo->prepare($sql_estado);
        $stmt_estado->execute([
            ':usuario' => $usuario,
            ':fecha_cierre' => $fecha_cierre,
            ':id' => $estado['id']
        ]);
        
        // Generar fondo de vuelto para mañana si aplica
        if ($fondo_vuelto > 0) {
            $mañana = date('Y-m-d 07:00:00', strtotime('+1 day'));
            $sql_fondo = "INSERT INTO movimientos 
                          (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, es_fondo_inicial)
                          VALUES (:empresa_id, :sucursal_id, 'INGRESO', :monto, 'EFECTIVO', 'FONDO INICIAL (VUELTO)', :fecha, :usuario, 0, 1)";
            
            $stmt_fondo = $pdo->prepare($sql_fondo);
            $stmt_fondo->execute([
                ':empresa_id' => $empresa_id,
                ':sucursal_id' => $sucursal_id,
                ':monto' => $fondo_vuelto,
                ':fecha' => $mañana,
                ':usuario' => $usuario
            ]);
        }
        
        // Registrar en log de auditoría
        $sql_audit = "INSERT INTO cierres_caja_audit 
                      (cierre_id, accion, usuario, datos_nuevos)
                      VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
        
        $datos_audit = json_encode([
            'empresa_id' => $empresa_id,
            'sucursal_id' => $sucursal_id,
            'fecha_cierre' => $fecha_cierre,
            'ingresos_efectivo' => $ing_efectivo,
            'ingresos_transf' => $ing_transf,
            'egresos' => $egresos,
            'saldo_esperado' => $saldo_esperado,
            'fondo_vuelto' => $fondo_vuelto
        ]);
        
        $stmt_audit = $pdo->prepare($sql_audit);
        $stmt_audit->execute([
            ':cierre_id' => $cierre_id,
            ':usuario' => $usuario,
            ':datos' => $datos_audit
        ]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'mensaje' => 'Caja cerrada correctamente.',
            'cierre_id' => $cierre_id
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'mensaje' => 'Error al cerrar caja: ' . $e->getMessage()
        ];
    }
}

/**
 * Validar que la caja esté abierta antes de permitir una operación
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @throws Exception Si la caja está cerrada
 */
function validar_caja_abierta($pdo, $empresa_id, $sucursal_id) {
    if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
        throw new Exception('ERROR: La caja está cerrada. Debe abrir la caja antes de realizar operaciones.');
    }
}

/**
 * Obtener resumen de caja del día
 * 
 * @param PDO $pdo Conexión a base de datos
 * @param int $empresa_id ID de la empresa
 * @param int $sucursal_id ID de la sucursal
 * @param string $fecha Fecha en formato Y-m-d (opcional)
 * @return array Resumen de caja
 */
function obtener_resumen_caja($pdo, $empresa_id, $sucursal_id, $fecha = null) {
    if (!$fecha) {
        $fecha = date('Y-m-d');
    }
    
    // Solo movimientos abiertos (cerrado = 0)
    $sql = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                 THEN monto ELSE 0 END) as efectivo,
        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                 THEN monto ELSE 0 END) as transferencia,
        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
    FROM movimientos 
    WHERE cerrado = 0 
      AND empresa_id = :empresa_id 
      AND sucursal_id = :sucursal_id
      AND DATE(fecha) = :fecha";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':sucursal_id' => $sucursal_id,
        ':fecha' => $fecha
    ]);
    
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['egresos'] = (float)($resumen['egresos'] ?? 0);
    $resumen['caja_fisica'] = $resumen['efectivo'] - $resumen['egresos'];
    
    return $resumen;
}
```

---

### Fase 2: Validación de Estado de Caja (Semana 1-2)

#### Paso 2.1: Modificar `pages/infosesion.php`

**Cambios:**
- Agregar verificación de estado de caja
- Redirigir a página de apertura si la caja está cerrada

```php
// Agregar después de la validación de sesión
require_once '../funciones/funciones_caja.php';

// Verificar estado de caja (solo para páginas que requieren caja abierta)
$paginas_requieren_caja = [
    'ventas.php',
    'compras.php',
    'compras_rapidas.php',
    'cobro_cuotas.php',
    'anulaciones.php',
    'movimiento_manual.php',
    'caja_dashboard.php',
    'cierre_caja.php'
];

$pagina_actual = basename($_SERVER['PHP_SELF']);

if (in_array($pagina_actual, $paginas_requieren_caja)) {
    $empresa_id = $_SESSION['empresa_id'] ?? null;
    $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
    
    if ($empresa_id && !caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
        // Redirigir a página de apertura de caja
        $_SESSION['error_caja'] = 'La caja está cerrada. Debe abrirla antes de continuar.';
        header("Location: " . URL_BASE . "pages/abrir_caja.php");
        exit();
    }
}
```

#### Paso 2.2: Modificar `pages/ventas.php`

**Cambios:**
- Validar estado de caja antes de crear venta

```php
// Agregar al inicio del archivo, después de infosesion.php
require_once '../funciones/funciones_caja.php';

// Validar estado de caja antes de procesar venta
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresa_id = $_SESSION['empresa_id'];
    $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
    
    try {
        validar_caja_abierta($pdo, $empresa_id, $sucursal_id);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: " . URL_BASE . "pages/abrir_caja.php");
        exit();
    }
    
    // ... resto del código de ventas
}
```

#### Paso 2.3: Modificar otros archivos de operaciones

**Archivos a modificar:**
- `pages/compras.php`
- `pages/compras_rapidas.php`
- `pages/cobro_cuotas.php`
- `pages/anulaciones.php`
- `pages/movimiento_manual.php`

**Cambio estándar para cada uno:**
```php
// Agregar al inicio, después de infosesion.php
require_once '../funciones/funciones_caja.php';

// Antes de procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresa_id = $_SESSION['empresa_id'];
    $sucursal_id = $_SESSION['sucursal_id'] ?? 1;
    
    try {
        validar_caja_abierta($pdo, $empresa_id, $sucursal_id);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: " . URL_BASE . "pages/abrir_caja.php");
        exit();
    }
    
    // ... resto del código
}
```

---

### Fase 3: Pantalla de Apertura de Caja (Semana 2)

#### Paso 3.1: Crear `pages/abrir_caja.php`

```php
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
    header("Location: caja_dashboard.php");
    exit();
}

// Obtener fondo de vuelto del día anterior (si existe)
$fecha_ayer = date('Y-m-d', strtotime('-1 day'));
$sql_fondo_ayer = "SELECT fondo_reservado_vuelto 
                   FROM cierres_caja 
                   WHERE empresa_id = :empresa_id 
                     AND sucursal_id = :sucursal_id 
                     AND DATE(fecha_cierre) = :fecha
                   ORDER BY id DESC LIMIT 1";

$stmt_fondo = $pdo->prepare($sql_fondo_ayer);
$stmt_fondo->execute([
    ':empresa_id' => $empresa_id,
    ':sucursal_id' => $sucursal_id,
    ':fecha' => $fecha_ayer
]);

$fondo_ayer = $stmt_fondo->fetchColumn();
$fondo_ayer = $fondo_ayer ? (float)$fondo_ayer : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Abrir Caja | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
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
                    <strong>Fondo de vuelto del día anterior: $<?php echo number_format($fondo_ayer, 2, ',', '.'); ?></strong>
                    <p class="small">Este monto se sugiere como saldo inicial para hoy.</p>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h3>Datos de Apertura</h3>
                <form action="ajax/abrir_caja.php" method="POST">
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
</body>
</html>
```

#### Paso 3.2: Crear `ajax/abrir_caja.php`

```php
<?php
// ajax/abrir_caja.php
include '../pages/infosesion.php';
require '../config/db_config.php';
require '../funciones/funciones_caja.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario = $_SESSION['usuario'] ?? 'Sistema';

if (!$empresa_id) {
    echo json_encode(['success' => false, 'mensaje' => 'Sesión expirada']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'mensaje' => 'Método no permitido']);
    exit();
}

$saldo_inicial = (float)($_POST['saldo_inicial'] ?? 0);
$observaciones = trim($_POST['observaciones'] ?? '');

if ($saldo_inicial < 0) {
    echo json_encode(['success' => false, 'mensaje' => 'El saldo inicial no puede ser negativo']);
    exit();
}

$resultado = abrir_caja($pdo, $empresa_id, $sucursal_id, $saldo_inicial, $usuario);

echo json_encode($resultado);
?>
```

#### Paso 3.3: Crear `ajax/verificar_estado_caja.php`

```php
<?php
// ajax/verificar_estado_caja.php
include '../pages/infosesion.php';
require '../config/db_config.php';
require '../funciones/funciones_caja.php';

header('Content-Type: application/json');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    echo json_encode(['abierta' => false, 'mensaje' => 'Sesión expirada']);
    exit();
}

$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);

if (!$estado) {
    echo json_encode([
        'abierta' => false, 
        'mensaje' => 'Caja no iniciada. Debe abrir la caja antes de continuar.'
    ]);
    exit();
}

if ($estado['estado'] === 'CERRADA') {
    echo json_encode([
        'abierta' => false, 
        'mensaje' => 'Caja cerrada. Debe abrir la caja para el día de hoy.'
    ]);
    exit();
}

echo json_encode([
    'abierta' => true,
    'mensaje' => 'Caja abierta',
    'datos' => $estado
]);
?>
```

---

### Fase 4: Mejoras en Cierre de Caja (Semana 2)

#### Paso 4.1: Modificar `pages/cierre_caja.php`

**Cambios:**
- Agregar validación de cierre previo
- Mostrar estado de caja actual
- Mejorar interfaz

```php
<?php
// pages/cierre_caja.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Verificar que la caja esté abierta
if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
    $_SESSION['error_caja'] = 'La caja está cerrada. Debe abrirla antes de cerrar.';
    header("Location: abrir_caja.php");
    exit();
}

// Verificar si ya existe un cierre para hoy
$sql_check_cierre = "SELECT id FROM cierres_caja 
                     WHERE empresa_id = :empresa_id 
                       AND sucursal_id = :sucursal_id 
                       AND DATE(fecha_cierre) = CURDATE()";

$stmt_check = $pdo->prepare($sql_check_cierre);
$stmt_check->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);

if ($stmt_check->fetch()) {
    die('<div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>ERROR:</strong> Ya se realizó el cierre de caja para el día de hoy.
            <br>No se puede cerrar la caja más de una vez por día.
         </div>');
}

// Obtener estado de caja
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);

// Calcular totales
try {
    $sql_sistema = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                 THEN monto ELSE 0 END) as ingresos_efectivo,
        SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                 THEN monto ELSE 0 END) as ingresos_transf,
        SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
    FROM movimientos 
    WHERE cerrado = 0 AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id";
    
    $stmt = $pdo->prepare($sql_sistema);
    $stmt->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
    $sistema = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $ingresos_efectivo = (float)($sistema['ingresos_efectivo'] ?? 0);
    $ingresos_transf = (float)($sistema['ingresos_transf'] ?? 0);
    $egresos = (float)($sistema['egresos'] ?? 0);
    
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
    <!-- ... estilos ... -->
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <h1>Cierre de Caja Diario</h1>
        
        <!-- Mostrar información de apertura -->
        <div class="card" style="background: #004a54; border-left: 4px solid #00bcd4; margin-bottom: 20px;">
            <h3><i class="fas fa-info-circle"></i> Información de Apertura</h3>
            <div class="info-line">
                <span>Saldo Inicial:</span>
                <strong>$ <?php echo number_format($estado['saldo_inicial'] ?? 0, 2, ',', '.'); ?></strong>
            </div>
            <div class="info-line">
                <span>Fecha Apertura:</span>
                <strong><?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?></strong>
            </div>
            <div class="info-line">
                <span>Usuario Apertura:</span>
                <strong><?php echo htmlspecialchars($estado['usuario_apertura']); ?></strong>
            </div>
        </div>
        
        <form action="procesar_cierre.php" method="POST">
            <!-- ... resto del formulario ... -->
        </form>
    </div>
</body>
</html>
```

#### Paso 4.2: Modificar `pages/procesar_cierre.php`

**Cambios:**
- Mejorar validaciones
- Agregar auditoría
- Usar funciones auxiliares

```php
<?php
// pages/procesar_cierre.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
$usuario = $_SESSION['usuario'] ?? 'Sistema';

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar que la caja esté abierta
        if (!caja_esta_abierta($pdo, $empresa_id, $sucursal_id)) {
            throw new Exception('La caja está cerrada. No se puede procesar el cierre.');
        }
        
        // Validar que no haya cierre previo
        $sql_check = "SELECT id FROM cierres_caja 
                      WHERE empresa_id = :empresa_id 
                        AND sucursal_id = :sucursal_id 
                        AND DATE(fecha_cierre) = CURDATE()";
        
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
        
        if ($stmt_check->fetch()) {
            throw new Exception('Ya existe un cierre para el día de hoy.');
        }
        
        // Obtener datos del POST
        $saldo_real_efectivo = (float)str_replace(',', '.', $_POST['saldo_real_efectivo'] ?? '0');
        $fondo_vuelto_manana = (float)str_replace(',', '.', $_POST['fondo_vuelto'] ?? '0');
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        // Validaciones
        if ($saldo_real_efectivo < 0) {
            throw new Exception('El saldo real no puede ser negativo.');
        }
        
        if ($fondo_vuelto_manana < 0) {
            throw new Exception('El fondo de vuelto no puede ser negativo.');
        }
        
        if ($fondo_vuelto_manana > $saldo_real_efectivo) {
            throw new Exception("El fondo reservado ($fondo_vuelto_manana) no puede ser mayor al efectivo contado ($saldo_real_efectivo).");
        }
        
        // Usar función auxiliar
        $resultado = cerrar_caja($pdo, $empresa_id, $sucursal_id, $usuario, $fondo_vuelto_manana);
        
        if (!$resultado['success']) {
            throw new Exception($resultado['mensaje']);
        }
        
        // Actualizar cierre con datos del formulario
        $cierre_id = $resultado['cierre_id'];
        
        $saldo_esperado = $saldo_real_efectivo; // Recalcular desde función
        $diferencia = $saldo_real_efectivo - $saldo_esperado;
        
        $sql_update = "UPDATE cierres_caja 
                       SET saldo_real_efectivo = :saldo_real,
                           diferencia = :diferencia,
                           observaciones = :observaciones
                       WHERE id = :id";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            ':saldo_real' => $saldo_real_efectivo,
            ':diferencia' => $diferencia,
            ':observaciones' => $observaciones,
            ':id' => $cierre_id
        ]);
        
        // Registrar en log de auditoría
        $sql_audit = "INSERT INTO cierres_caja_audit 
                      (cierre_id, accion, usuario, datos_nuevos)
                      VALUES (:cierre_id, 'CREADO', :usuario, :datos)";
        
        $datos_audit = json_encode([
            'saldo_real' => $saldo_real_efectivo,
            'diferencia' => $diferencia,
            'fondo_vuelto' => $fondo_vuelto_manana,
            'observaciones' => $observaciones
        ]);
        
        $stmt_audit = $pdo->prepare($sql_audit);
        $stmt_audit->execute([
            ':cierre_id' => $cierre_id,
            ':usuario' => $usuario,
            ':datos' => $datos_audit
        ]);
        
        // Mensaje de éxito
        $msj_tipo = ($diferencia == 0) ? "✅ Caja cerrada correctamente." : "⚠️ Caja cerrada con diferencia de $ " . number_format($diferencia, 2, ',', '.');
        $_SESSION['status_msj'] = $msj_tipo;
        
        header("Location: caja_dashboard.php");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error en cierre de caja: " . $e->getMessage());
        die("❌ Error crítico: " . $e->getMessage());
    }
} else {
    header("Location: cierre_caja.php");
    exit();
}
```

---

### Fase 5: Mejoras en Dashboard (Semana 2)

#### Paso 5.1: Modificar `pages/caja_dashboard.php`

**Cambios:**
- Unificar filtrado de movimientos (usar `cerrado = 0`)
- Mostrar estado de caja actual
- Mejorar visualización

```php
<?php
// pages/caja_dashboard.php
include 'infosesion.php';
require '../config/db_config.php';
require_once '../funciones/funciones_caja.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Obtener estado de caja
$estado = obtener_estado_caja($pdo, $empresa_id, $sucursal_id);
$caja_abierta = $estado && $estado['estado'] === 'ABIERTA';

// Si la caja está cerrada, redirigir
if (!$caja_abierta) {
    header("Location: abrir_caja.php");
    exit();
}

$hoy = date('Y-m-d');

try {
    // USAR cerrado = 0 en lugar de DATE(fecha) = ?
    $sql_resumen = "SELECT 
                        SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) as efectivo,
                        SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as transferencia,
                        SUM(CASE WHEN metodo_pago = 'MIXTO' THEN monto ELSE 0 END) as mixto
                    FROM movimientos 
                    WHERE tipo = 'INGRESO' 
                      AND cerrado = 0 
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    
    $stmt = $pdo->prepare($sql_resumen);
    $stmt->execute([$empresa_id, $sucursal_id]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumen['efectivo'] = (float)($resumen['efectivo'] ?? 0);
    $resumen['transferencia'] = (float)($resumen['transferencia'] ?? 0);
    $resumen['mixto'] = (float)($resumen['mixto'] ?? 0);
    
    $sql_egresos = "SELECT SUM(monto) as total_egresos 
                    FROM movimientos 
                    WHERE tipo = 'EGRESO' 
                      AND cerrado = 0 
                      AND empresa_id = ? 
                      AND sucursal_id = ?";
    
    $stmt_eg = $pdo->prepare($sql_egresos);
    $stmt_eg->execute([$empresa_id, $sucursal_id]);
    $egresos = $stmt_eg->fetchColumn() ?: 0;
    
    $sql_movs = "SELECT tipo, metodo_pago, detalle, monto, fecha, usuario 
                 FROM movimientos 
                 WHERE cerrado = 0 
                   AND empresa_id = ? 
                   AND sucursal_id = ?
                 ORDER BY id DESC LIMIT 10";
    
    $stmt_m = $pdo->prepare($sql_movs);
    $stmt_m->execute([$empresa_id, $sucursal_id]);
    $lista_movimientos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
    
    $total_caja_fisica = ($resumen['efectivo'] + $resumen['mixto']) - $egresos;
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja del Día | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <!-- ... estilos ... -->
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <!-- Mostrar estado de caja -->
        <div class="alert alert-success" style="background-color: #28a745; border-left: 4px solid #1e7e34;">
            <i class="fas fa-check-circle"></i>
            <strong>Caja Abierta</strong> - 
            Apertura: <?php echo date('d/m/Y H:i', strtotime($estado['fecha_apertura'])); ?> -
            Usuario: <?php echo htmlspecialchars($estado['usuario_apertura']); ?>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; gap: 10px;">
            <h1>Estado de Caja (Hoy)</h1>
            <div>
                <a href="movimiento_manual.php" class="btn" style="background: #6f42c1; color: white; padding: 15px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-right: 10px;">
                    <i class="fas fa-plus-circle"></i> Nuevo Movimiento
                </a>
                <a href="cierre_caja.php" class="btn-cierre">
                    <i class="fas fa-lock"></i> Cerrar Caja
                </a>
            </div>
        </div>
        
        <!-- ... resto del contenido ... -->
    </div>
</body>
</html>
```

---

### Fase 6: Reporte de Cierres (Semana 3)

#### Paso 6.1: Crear `pages/reporte_cierres.php`

```php
<?php
// pages/reporte_cierres.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;

if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Filtros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$sucursal_filtro = $_GET['sucursal'] ?? 0; // 0 = todas

// Obtener cierres
$sql = "SELECT c.*, s.nombre_sucursal
        FROM cierres_caja c
        LEFT JOIN sucursales s ON c.sucursal_id = s.id
        WHERE c.empresa_id = :empresa_id
          AND DATE(c.fecha_cierre) BETWEEN :fecha_desde AND :fecha_hasta";

if ($sucursal_filtro > 0) {
    $sql .= " AND c.sucursal_id = :sucursal_id";
}

$sql .= " ORDER BY c.fecha_cierre DESC";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':empresa_id', $empresa_id);
$stmt->bindValue(':fecha_desde', $fecha_desde);
$stmt->bindValue(':fecha_hasta', $fecha_hasta);

if ($sucursal_filtro > 0) {
    $stmt->bindValue(':sucursal_id', $sucursal_filtro);
}

$stmt->execute();
$cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener sucursales para filtro
$sql_sucursales = "SELECT id, nombre_sucursal FROM sucursales WHERE empresa_id = :empresa_id";
$stmt_suc = $pdo->prepare($sql_sucursales);
$stmt_suc->execute([':empresa_id' => $empresa_id]);
$sucursales = $stmt_suc->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Cierres | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content" style="padding-top: 70px;">
        <?php include 'topbar.php'; ?>
        
        <h1>Reporte de Cierres de Caja</h1>
        
        <!-- Filtros -->
        <div class="card" style="margin-bottom: 20px;">
            <form method="GET" class="form-inline">
                <div class="form-group">
                    <label>Desde:</label>
                    <input type="date" name="fecha_desde" value="<?php echo $fecha_desde; ?>" class="input-field">
                </div>
                <div class="form-group">
                    <label>Hasta:</label>
                    <input type="date" name="fecha_hasta" value="<?php echo $fecha_hasta; ?>" class="input-field">
                </div>
                <div class="form-group">
                    <label>Sucursal:</label>
                    <select name="sucursal" class="input-field">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?php echo $suc['id']; ?>" <?php echo $sucursal_filtro == $suc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($suc['nombre_sucursal']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <a href="generar_pdf_cierres.php?fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&sucursal=<?php echo $sucursal_filtro; ?>" 
                   class="btn" style="background: #dc3545; color: white;">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </a>
            </form>
        </div>
        
        <!-- Tabla de cierres -->
        <div class="card">
            <table class="table-full">
                <thead>
                    <tr>
                        <th>N° Cierre</th>
                        <th>Fecha</th>
                        <th>Sucursal</th>
                        <th>Ing. Efectivo</th>
                        <th>Ing. Transfer.</th>
                        <th>Egresos</th>
                        <th>Saldo Esperado</th>
                        <th>Saldo Real</th>
                        <th>Diferencia</th>
                        <th>Fondo Vuelto</th>
                        <th>Usuario</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cierres as $cierre): ?>
                    <tr>
                        <td><?php echo $cierre['numero_cierre']; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($cierre['fecha_cierre'])); ?></td>
                        <td><?php echo htmlspecialchars($cierre['nombre_sucursal'] ?? 'N/A'); ?></td>
                        <td>$ <?php echo number_format($cierre['ingresos_efectivo'], 2, ',', '.'); ?></td>
                        <td>$ <?php echo number_format($cierre['ingresos_transf'], 2, ',', '.'); ?></td>
                        <td>$ <?php echo number_format($cierre['egresos'], 2, ',', '.'); ?></td>
                        <td>$ <?php echo number_format($cierre['saldo_esperado_efectivo'], 2, ',', '.'); ?></td>
                        <td>$ <?php echo number_format($cierre['saldo_real_efectivo'], 2, ',', '.'); ?></td>
                        <td style="color: <?php echo $cierre['diferencia'] == 0 ? '#28a745' : ($cierre['diferencia'] > 0 ? '#ffc107' : '#dc3545'); ?>">
                            $ <?php echo number_format($cierre['diferencia'], 2, ',', '.'); ?>
                        </td>
                        <td>$ <?php echo number_format($cierre['fondo_reservado_vuelto'], 2, ',', '.'); ?></td>
                        <td><?php echo htmlspecialchars($cierre['usuario']); ?></td>
                        <td>
                            <a href="ver_cierre.php?id=<?php echo $cierre['id']; ?>" class="btn btn-sm" style="background: #17a2b8; color: white;">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
```

---

### Fase 7: Mejoras Adicionales (Semana 3-4)

#### Paso 7.1: Implementar desglose de ventas mixtas

**Modificar `pages/ventas.php`:**

```php
// En lugar de un solo movimiento para ventas mixtas, crear dos:

if ($metodo_pago === 'MIXTO') {
    // Movimiento de efectivo
    $sql_mov_efectivo = "INSERT INTO movimientos 
                         (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado)
                         VALUES (?, ?, 'INGRESO', ?, 'EFECTIVO', ?, NOW(), ?, 0)";
    
    $pdo->prepare($sql_mov_efectivo)->execute([
        $empresa_id, $sucursal_id, $pago_efectivo, 
        "Venta #$n_documento - Parte Efectivo", $usuario
    ]);
    
    // Movimiento de transferencia
    $sql_mov_transf = "INSERT INTO movimientos 
                       (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado)
                       VALUES (?, ?, 'INGRESO', ?, 'TRANSFERENCIA', ?, NOW(), ?, 0)";
    
    $pdo->prepare($sql_mov_transf)->execute([
        $empresa_id, $sucursal_id, $pago_transf, 
        "Venta #$n_documento - Parte Transferencia", $usuario
    ]);
} else {
    // Movimiento único (como antes)
    $sql_mov = "INSERT INTO movimientos 
                (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado)
                VALUES (?, ?, 'INGRESO', ?, ?, ?, NOW(), ?, 0)";
    
    $pdo->prepare($sql_mov)->execute([
        $empresa_id, $sucursal_id, $total_venta, $metodo_pago,
        "Venta #$n_documento", $usuario
    ]);
}
```

#### Paso 7.2: Agregar validación de billetes en backend

**Modificar `pages/procesar_cierre.php`:**

```php
// Validar cantidades de billetes
$denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];
$total_calculado = 0;

foreach ($denominaciones as $valor) {
    $cantidad = isset($_POST["b_$valor"]) ? (int)$_POST["b_$valor"] : 0;
    
    if ($cantidad < 0) {
        throw new Exception("La cantidad de billetes de $$valor no puede ser negativa.");
    }
    
    $total_calculado += ($valor * $cantidad);
}

// Validar que el total coincida con el envío
$saldo_real_efectivo = (float)str_replace(',', '.', $_POST['saldo_real_efectivo'] ?? '0');

if (abs($total_calculado - $saldo_real_efectivo) > 0.01) {
    throw new Exception("El total calculado ($ $total_calculado) no coincide con el saldo real ($ $saldo_real_efectivo).");
}
```

---

## 5. PRUEBAS

### 5.1 Casos de Prueba

#### CP01: Apertura de caja
**Precondiciones:**
- Caja cerrada del día anterior

**Pasos:**
1. Acceder a `abrir_caja.php`
2. Ingresar saldo inicial de $5000
3. Confirmar apertura

**Resultado esperado:**
- ✅ Caja abierta
- ✅ Movimiento de fondo inicial creado
- ✅ Redirección a `caja_dashboard.php`

#### CP02: Validación de caja cerrada
**Precondiciones:**
- Caja cerrada

**Pasos:**
1. Intentar acceder a `ventas.php`

**Resultado esperado:**
- ✅ Redirección a `abrir_caja.php`
- ✅ Mensaje de error en sesión

#### CP03: Cierre de caja sin diferencias
**Precondiciones:**
- Caja abierta
- Movimientos registrados

**Pasos:**
1. Acceder a `cierre_caja.php`
2. Ingresar conteo físico que coincide con saldo esperado
3. Confirmar cierre

**Resultado esperado:**
- ✅ Cierre registrado en `cierres_caja`
- ✅ Movimientos marcados como `cerrado = 1`
- ✅ Estado de caja actualizado a 'CERRADA'
- ✅ Mensaje "Caja cerrada correctamente"

#### CP04: Validación de cierre duplicado
**Precondiciones:**
- Caja ya cerrada para el día

**Pasos:**
1. Intentar acceder a `cierre_caja.php`

**Resultado esperado:**
- ✅ Mensaje de error: "Ya se realizó el cierre de caja para el día de hoy"

#### CP05: Fondo de vuelto
**Precondiciones:**
- Caja abierta
- Saldo real de $10000

**Pasos:**
1. Cerrar caja con fondo de vuelto de $2000
2. Verificar día siguiente

**Resultado esperado:**
- ✅ Fondo de vuelto registrado en `cierres_caja`
- ✅ Movimiento de "FONDO INICIAL (VUELTO)" creado para el día siguiente
- ✅ Saldo inicial del día siguiente es $2000

---

## 6. CHECKLIST DE IMPLEMENTACIÓN

### Antes de Implementar
- [ ] Backup de base de datos
- [ ] Leer documento `ANALISIS_CAJA_CIERRES.md`
- [ ] Revisar este plan con el equipo
- [ ] Aprobar cambios en base de datos

### Fase 1: Migración (Semana 1)
- [ ] Crear archivo `migrations/agregar_estado_caja.sql`
- [ ] Crear archivo `procesos/ejecutar_migracion_21.php`
- [ ] Ejecutar migración en ambiente de desarrollo
- [ ] Verificar tablas creadas
- [ ] Verificar índices creados
- [ ] Verificar función `obtener_numero_cierre`

### Fase 2: Funciones Base (Semana 1)
- [ ] Crear `funciones/funciones_caja.php`
- [ ] Probar función `obtener_estado_caja()`
- [ ] Probar función `abrir_caja()`
- [ ] Probar función `cerrar_caja()`
- [ ] Probar función `validar_caja_abierta()`
- [ ] Probar función `obtener_resumen_caja()`

### Fase 3: Validación (Semana 1-2)
- [ ] Modificar `pages/infosesion.php`
- [ ] Modificar `pages/ventas.php`
- [ ] Modificar `pages/compras.php`
- [ ] Modificar `pages/compras_rapidas.php`
- [ ] Modificar `pages/cobro_cuotas.php`
- [ ] Modificar `pages/anulaciones.php`
- [ ] Modificar `pages/movimiento_manual.php`
- [ ] Probar cada módulo con caja cerrada
- [ ] Probar cada módulo con caja abierta

### Fase 4: Apertura de Caja (Semana 2)
- [ ] Crear `pages/abrir_caja.php`
- [ ] Crear `ajax/abrir_caja.php`
- [ ] Crear `ajax/verificar_estado_caja.php`
- [ ] Probar apertura de caja
- [ ] Probar fondo de vuelto del día anterior
- [ ] Probar reapertura de caja

### Fase 5: Mejoras en Cierre (Semana 2)
- [ ] Modificar `pages/cierre_caja.php`
- [ ] Modificar `pages/procesar_cierre.php`
- [ ] Agregar validación de cierre duplicado
- [ ] Agregar validación de billetes en backend
- [ ] Probar cierre sin diferencias
- [ ] Probar cierre con sobrante
- [ ] Probar cierre con faltante
- [ ] Probar cierre con fondo de vuelto

### Fase 6: Dashboard (Semana 2)
- [ ] Modificar `pages/caja_dashboard.php`
- [ ] Unificar filtrado de movimientos
- [ ] Mostrar estado de caja
- [ ] Probar dashboard con caja abierta
- [ ] Probar dashboard con caja cerrada

### Fase 7: Reportes (Semana 3)
- [ ] Crear `pages/reporte_cierres.php`
- [ ] Crear `pages/ver_cierre.php`
- [ ] Crear `generar_pdf_cierres.php`
- [ ] Probar reporte con filtros
- [ ] Probar exportación a PDF

### Fase 8: Mejoras Adicionales (Semana 3-4)
- [ ] Implementar desglose de ventas mixtas
- [ ] Agregar selector de sucursal en UI
- [ ] Crear página de configuración de denominaciones
- [ ] Probar todas las mejoras

### Testing Final
- [ ] Prueba de flujo completo (apertura → ventas → cierre)
- [ ] Prueba de múltiples sucursales
- [ ] Prueba de múltiples empresas
- [ ] Prueba de cierres con diferencias
- [ ] Prueba de reportes
- [ ] Prueba de auditoría

### Documentación
- [ ] Actualizar `docs/estado.md`
- [ ] Actualizar manual de usuario
- [ ] Documentar procedimientos de caja
- [ ] Crear guía de troubleshooting

---

## 7. RIESGOS Y MITIGACIONES

### 7.1 Riesgos Identificados

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Pérdida de datos durante migración | Media | Alto | Backup completo antes de migrar |
| Errores en validaciones | Media | Alto | Testing exhaustivo en desarrollo |
| Inconsistencias en datos históricos | Media | Medio | Script de corrección post-migración |
| Resistencia al cambio por usuarios | Alta | Bajo | Capacitación y manuales |
| Rendimiento en consultas | Baja | Medio | Índices compuestos |

### 7.2 Plan de Rollback

**Si algo sale mal:**

1. Restaurar backup de base de datos
2. Revertir cambios en archivos PHP (git checkout)
3. Verificar funcionamiento del sistema anterior
4. Documentar el problema encontrado
5. Corregir y volver a intentar

---

## 8. CRONOGRAMA

### Semana 1
- Lunes: Migración SQL y funciones base
- Martes: Testing de funciones
- Miércoles: Modificar validaciones en archivos
- Jueves: Testing de validaciones
- Viernes: Crear pantalla de apertura

### Semana 2
- Lunes: Crear AJAX de apertura
- Martes: Mejoras en cierre de caja
- Miércoles: Mejoras en dashboard
- Jueves: Testing de flujo completo
- Viernes: Correcciones

### Semana 3
- Lunes: Crear reporte de cierres
- Martes: Crear PDF de cierres
- Miércoles: Implementar desglose de ventas mixtas
- Jueves: Agregar selector de sucursal
- Viernes: Testing integral

### Semana 4
- Lunes: Correcciones finales
- Martes: Pruebas de usuario
- Miércoles: Ajustes según feedback
- Jueves: Documentación
- Viernes: Deployment a producción

---

## 9. RESPONSABLES

| Rol | Responsable | Tareas |
|-----|-------------|--------|
| Líder Técnico | [Nombre] | Supervisión, arquitectura, decisiones técnicas |
| Backend Developer | [Nombre] | Migración, funciones, lógica de negocio |
| Frontend Developer | [Nombre] | Pantallas, formularios, JavaScript |
| Tester | [Nombre] | Pruebas, casos de prueba, validación |
| Documentación | [Nombre] | Manuales, guías, documentación técnica |

---

## 10. CONTACTO Y SEGUIMIENTO

- **Reuniones diarias:** 15 minutos, 9:00 AM
- **Revisión semanal:** Viernes, 5:00 PM
- **Herramienta de seguimiento:** [Definir]
- **Repositorio:** `https://github.com/alexislucyk/ventas-pv-pos`

---

*Documento de implementación generado el 08/03/2026*  
*Versión: 1.0*  
*Estado: Pendiente de aprobación*