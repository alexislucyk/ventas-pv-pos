# Diseño: Sistema de Intereses por Mora en Cuentas Corrientes

## 📋 Índice
1. [Análisis del Sistema Actual](#análisis-del-sistema-actual)
2. [Propuesta de Solución](#propuesta-de-solución)
3. [Cambios en Base de Datos](#cambios-en-base-de-datos)
4. [Lógica de Cálculo](#lógica-de-cálculo)
5. [Implementación](#implementación)
6. [Criterios de Aplicación](#criterios-de-aplicación)

---

## 1. Análisis del Sistema Actual

### Estructura de `ctacte` (Cuentas Corrientes Clientes)
```sql
CREATE TABLE `ctacte` (
  `id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `movimiento` text NOT NULL,        -- "FACTURA #123", "PAGO", "ANULACIÓN"
  `n_documento` int NOT NULL,        -- Número de factura o referencia
  `debe` double NOT NULL,            -- Monto a pagar (positivo)
  `haber` double NOT NULL,           -- Monto pagado (positivo)
  `fecha` date NOT NULL,             -- Fecha del movimiento
  `usuario` varchar(100) DEFAULT 'Sistema',
  `empresa_id` int NOT NULL
)
```

### Problemas Identificados
❌ No hay fecha de vencimiento diferenciada de la fecha de emisión  
❌ No hay registro de intereses generados  
❌ No hay forma de rastrear qué facturas están en mora  
❌ No hay configuración de tasas de interés  

---

## 2. Propuesta de Solución

### Opción A: Interés Simple sobre Saldo Total (RECOMENDADA)
**Ventajas:**
- ✅ Más simple de implementar y mantener
- ✅ Menos propensa a errores
- ✅ Fácil de explicar al cliente
- ✅ Menos registros en la base de datos

**Funcionamiento:**
```
Interés = Saldo Deudor × (Tasa Mensual / 30) × Días de Mora
```

**Ejemplo:**
- Saldo: $10,000
- Tasa: 3% mensual
- Días de mora: 45 días
- Interés = 10,000 × (0.03 / 30) × 45 = $450

---

### Opción B: Interés por Factura Individual
**Ventajas:**
- ✅ Más preciso (cada factura tiene su propio interés)
- ✅ Permite diferentes tasas por factura

**Desventajas:**
- ❌ Más complejo
- ❌ Más registros en BD
- ❌ Dificulta pagos parciales

---

## 3. Cambios en Base de Datos

### 3.1 Modificar tabla `ctacte` (Agregar fecha de vencimiento)

```sql
-- Agregar campo fecha_vencimiento
ALTER TABLE `ctacte`
ADD COLUMN `fecha_vencimiento` date DEFAULT NULL 
COMMENT 'Fecha de vencimiento del movimiento (para cálculo de intereses)'
AFTER `fecha`;

-- Agregar índice para búsquedas por fecha
ALTER TABLE `ctacte`
ADD INDEX `idx_fecha_vencimiento` (`fecha_vencimiento`);
```

**Nota:** Para movimientos existentes, `fecha_vencimiento` = `fecha` + 30 días (por defecto)

---

### 3.2 Crear tabla de configuración de intereses

```sql
CREATE TABLE `configuracion_intereses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `tasa_mensual` decimal(5,2) DEFAULT '3.00' COMMENT 'Tasa de interés mensual (ej: 3.00 = 3%)',
  `dias_gracia` int DEFAULT '0' COMMENT 'Días de gracia antes de aplicar intereses',
  `aplicar_automatico` tinyint(1) DEFAULT '0' COMMENT '1 = Aplicar automáticamente, 0 = Manual',
  `frecuencia` enum('DIARIA','SEMANAL','MENSUAL') DEFAULT 'DIARIA' COMMENT 'Frecuencia de cálculo',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

**Valores por defecto:**
- Tasa mensual: 3%
- Días de gracia: 0
- Aplicación: Manual (para control del usuario)
- Frecuencia: DIARIA

---

### 3.3 (Opcional) Tabla de registro de intereses generados

```sql
CREATE TABLE `intereses_generados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `monto_interes` decimal(15,2) NOT NULL,
  `saldo_utilizado` decimal(15,2) NOT NULL COMMENT 'Saldo sobre el que se calculó',
  `dias_mora` int NOT NULL,
  `tasa_aplicada` decimal(5,2) NOT NULL,
  `fecha_calculo` date NOT NULL,
  `fecha_aplicacion` date DEFAULT NULL COMMENT 'Fecha en que se registró en ctacte',
  `usuario_id` int DEFAULT NULL,
  `observaciones` text,
  PRIMARY KEY (`id`),
  KEY `fk_intereses_cliente` (`id_cliente`),
  KEY `fk_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_intereses_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

---

## 4. Lógica de Cálculo

### 4.1 Fórmula de Interés Simple

```php
/**
 * Calcula interés por mora sobre un saldo
 * 
 * @param float $saldo - Saldo deudor actual
 * @param int $dias_mora - Días transcurridos desde el vencimiento
 * @param float $tasa_mensual - Tasa de interés mensual (ej: 3.00 para 3%)
 * @return float - Monto de interés calculado
 */
function calcularInteresMora($saldo, $dias_mora, $tasa_mensual = 3.00) {
    if ($saldo <= 0 || $dias_mora <= 0) {
        return 0;
    }
    
    // Fórmula: Saldo × (Tasa / 30) × Días
    $tasa_diaria = $tasa_mensual / 30 / 100;
    $interes = $saldo * $tasa_diaria * $dias_mora;
    
    return round($interes, 2);
}
```

### 4.2 Algoritmo de Cálculo para un Cliente

```php
/**
 * Calcula intereses de mora para un cliente
 * 
 * @param int $id_cliente
 * @param PDO $pdo
 * @param date $fecha_calculo - Fecha desde la cual calcular (por defecto: hoy)
 * @return array
 */
function calcularInteresesCliente($id_cliente, $pdo, $fecha_calculo = null) {
    $fecha_calculo = $fecha_calculo ?? date('Y-m-d');
    
    // 1. Obtener configuración de intereses
    $config = obtenerConfiguracionIntereses($pdo, $_SESSION['empresa_id']);
    
    if (!$config['activo']) {
        return ['interes_total' => 0, 'detalle' => []];
    }
    
    // 2. Obtener movimientos deudores vencidos
    $sql = "
        SELECT 
            c.id,
            c.fecha,
            c.fecha_vencimiento,
            c.debe,
            c.haber,
            c.movimiento,
            c.n_documento,
            DATEDIFF(:fecha_calculo, c.fecha_vencimiento) as dias_mora
        FROM ctacte c
        WHERE c.id_cliente = :id_cliente
        AND c.empresa_id = :empresa_id
        AND c.debe > 0
        AND c.fecha_vencimiento < :fecha_calculo
        AND c.id NOT IN (
            -- Excluir movimientos que ya tienen intereses generados
            SELECT n_documento FROM intereses_generados 
            WHERE id_cliente = :id_cliente2
            AND empresa_id = :empresa_id2
        )
        ORDER BY c.fecha_vencimiento ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_cliente' => $id_cliente,
        ':id_cliente2' => $id_cliente,
        ':empresa_id' => $_SESSION['empresa_id'],
        ':empresa_id2' => $_SESSION['empresa_id'],
        ':fecha_calculo' => $fecha_calculo
    ]);
    
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Calcular interés por cada movimiento
    $interes_total = 0;
    $detalle = [];
    
    foreach ($movimientos as $mov) {
        $dias_mora = max(0, $mov['dias_mora'] - $config['dias_gracia']);
        
        if ($dias_mora > 0) {
            $saldo_pendiente = $mov['debe'] - $mov['haber'];
            $interes = calcularInteresMora($saldo_pendiente, $dias_mora, $config['tasa_mensual']);
            
            if ($interes > 0) {
                $interes_total += $interes;
                $detalle[] = [
                    'id_movimiento' => $mov['id'],
                    'n_documento' => $mov['n_documento'],
                    'movimiento' => $mov['movimiento'],
                    'saldo_pendiente' => $saldo_pendiente,
                    'dias_mora' => $dias_mora,
                    'tasa_aplicada' => $config['tasa_mensual'],
                    'interes_calculado' => $interes
                ];
            }
        }
    }
    
    return [
        'interes_total' => round($interes_total, 2),
        'detalle' => $detalle,
        'config' => $config
    ];
}
```

### 4.3 Lógica de Aplicación de Intereses

```php
/**
 * Aplica intereses de mora a la cuenta corriente
 * Genera un movimiento en ctacte por el total de intereses
 * 
 * @param int $id_cliente
 * @param PDO $pdo
 * @param int $usuario_id
 * @return bool
 */
function aplicarInteresesMora($id_cliente, $pdo, $usuario_id = null) {
    try {
        $pdo->beginTransaction();
        
        // 1. Calcular intereses
        $resultado = calcularInteresesCliente($id_cliente, $pdo);
        
        if ($resultado['interes_total'] <= 0) {
            $pdo->rollBack();
            return false;
        }
        
        // 2. Obtener datos del cliente
        $sql_cliente = "SELECT CONCAT(apellido, ', ', nombre) as nombre FROM clientes WHERE id = :id";
        $stmt_cliente = $pdo->prepare($sql_cliente);
        $stmt_cliente->execute([':id' => $id_cliente]);
        $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        
        // 3. Generar número de documento único para el interés
        $n_documento = generarNumeroInteres($pdo, $_SESSION['empresa_id']);
        
        // 4. Insertar movimiento de interés en ctacte
        $sql_insert = "
            INSERT INTO ctacte 
            (id_cliente, movimiento, n_documento, debe, haber, fecha, fecha_vencimiento, usuario, empresa_id)
            VALUES
            (:id_cliente, :movimiento, :n_documento, :debe, 0, :fecha, :fecha_vencimiento, :usuario, :empresa_id)
        ";
        
        $movimiento_texto = "INTERÉS POR MORA - " . date('Y-m-d');
        $fecha_hoy = date('Y-m-d');
        $fecha_vencimiento = date('Y-m-d', strtotime('+30 days'));
        
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([
            ':id_cliente' => $id_cliente,
            ':movimiento' => $movimiento_texto,
            ':n_documento' => $n_documento,
            ':debe' => $resultado['interes_total'],
            ':fecha' => $fecha_hoy,
            ':fecha_vencimiento' => $fecha_vencimiento,
            ':usuario' => $usuario_id ?: 'Sistema',
            ':empresa_id' => $_SESSION['empresa_id']
        ]);
        
        $id_interes_generado = $pdo->lastInsertId();
        
        // 5. Registrar en tabla de intereses generados (si existe)
        $sql_registro = "
            INSERT INTO intereses_generados
            (empresa_id, id_cliente, monto_interes, saldo_utilizado, dias_mora, tasa_aplicada, fecha_calculo, fecha_aplicacion, usuario_id, observaciones)
            VALUES
            (:empresa_id, :id_cliente, :monto_interes, :saldo_utilizado, :dias_mora, :tasa_aplicada, :fecha_calculo, :fecha_aplicacion, :usuario_id, :observaciones)
        ";
        
        $saldo_total = array_sum(array_column($resultado['detalle'], 'saldo_pendiente'));
        $dias_promedio = count($resultado['detalle']) > 0 
            ? round(array_sum(array_column($resultado['detalle'], 'dias_mora')) / count($resultado['detalle']))
            : 0;
        
        $stmt_registro = $pdo->prepare($sql_registro);
        $stmt_registro->execute([
            ':empresa_id' => $_SESSION['empresa_id'],
            ':id_cliente' => $id_cliente,
            ':monto_interes' => $resultado['interes_total'],
            ':saldo_utilizado' => $saldo_total,
            ':dias_mora' => $dias_promedio,
            ':tasa_aplicada' => $resultado['config']['tasa_mensual'],
            ':fecha_calculo' => $fecha_hoy,
            ':fecha_aplicacion' => $fecha_hoy,
            ':usuario_id' => $usuario_id,
            ':observaciones' => 'Interés aplicado sobre ' . count($resultado['detalle']) . ' movimiento(s)'
        ]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'monto_aplicado' => $resultado['interes_total'],
            'id_movimiento' => $id_interes_generado,
            'detalle' => $resultado['detalle']
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error al aplicar intereses: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
```

---

## 5. Implementación

### 5.1 Archivos a Crear/Modificar

#### **Nuevos archivos:**
1. `procesos/calcular_intereses_mora.php` - Script para cálculo manual
2. `ajax/aplicar_interes_ajax.php` - AJAX para aplicar intereses
3. `pages/configuracion_intereses.php` - Página de configuración
4. `migrations/agregar_fecha_vencimiento_ctacte.sql` - Migración

#### **Archivos a modificar:**
1. `pages/cuentas_corrientes.php` - Agregar botón y visualización de intereses
2. `pages/cuentas_corrientes_detalle.php` - Mostrar detalle de intereses
3. `schema.sql` - Agregar nuevas tablas/campos
4. `ajax/obtener_movimientos_cc.php` - Incluir información de intereses

---

### 5.2 Flujo de Implementación

#### **Paso 1: Migración de Base de Datos**

```sql
-- migrations/agregar_fecha_vencimiento_ctacte.sql

-- 1. Agregar campo fecha_vencimiento
ALTER TABLE `ctacte`
ADD COLUMN `fecha_vencimiento` date DEFAULT NULL 
COMMENT 'Fecha de vencimiento del movimiento'
AFTER `fecha`;

-- 2. Actualizar registros existentes (fecha + 30 días)
UPDATE `ctacte`
SET `fecha_vencimiento` = DATE_ADD(`fecha`, INTERVAL 30 DAY)
WHERE `fecha_vencimiento` IS NULL;

-- 3. Crear tabla de configuración
CREATE TABLE IF NOT EXISTS `configuracion_intereses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `tasa_mensual` decimal(5,2) DEFAULT '3.00',
  `dias_gracia` int DEFAULT '0',
  `aplicar_automatico` tinyint(1) DEFAULT '0',
  `frecuencia` enum('DIARIA','SEMANAL','MENSUAL') DEFAULT 'DIARIA',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. Insertar configuración por defecto para cada empresa
INSERT INTO `configuracion_intereses` (empresa_id, tasa_mensual, dias_gracia, activar_automatico, frecuencia)
SELECT id, 3.00, 0, 0, 'DIARIA'
FROM `empresas`
WHERE id NOT IN (SELECT empresa_id FROM configuracion_intereses);

-- 5. Crear tabla de registro de intereses (opcional pero recomendada)
CREATE TABLE IF NOT EXISTS `intereses_generados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `id_cliente` int NOT NULL,
  `monto_interes` decimal(15,2) NOT NULL,
  `saldo_utilizado` decimal(15,2) NOT NULL,
  `dias_mora` int NOT NULL,
  `tasa_aplicada` decimal(5,2) NOT NULL,
  `fecha_calculo` date NOT NULL,
  `fecha_aplicacion` date DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `observaciones` text,
  PRIMARY KEY (`id`),
  KEY `fk_intereses_cliente` (`id_cliente`),
  KEY `fk_intereses_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

---

#### **Paso 2: Funciones Auxiliares**

Crear archivo: `funciones/funciones_intereses.php`

```php
<?php
/**
 * Calcula interés por mora
 */
function calcularInteresMora($saldo, $dias_mora, $tasa_mensual = 3.00) {
    if ($saldo <= 0 || $dias_mora <= 0) {
        return 0;
    }
    
    $tasa_diaria = $tasa_mensual / 30 / 100;
    $interes = $saldo * $tasa_diaria * $dias_mora;
    
    return round($interes, 2);
}

/**
 * Obtiene la configuración de intereses de la empresa
 */
function obtenerConfiguracionIntereses($pdo, $empresa_id) {
    $sql = "SELECT * FROM configuracion_intereses WHERE empresa_id = :empresa_id AND activo = 1 LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'tasa_mensual' => 3.00,
        'dias_gracia' => 0,
        'aplicar_automatico' => 0,
        'frecuencia' => 'DIARIA'
    ];
}

/**
 * Calcula intereses de todos los movimientos vencidos de un cliente
 */
function calcularInteresesCliente($id_cliente, $pdo, $empresa_id, $fecha_calculo = null) {
    $fecha_calculo = $fecha_calculo ?? date('Y-m-d');
    $config = obtenerConfiguracionIntereses($pdo, $empresa_id);
    
    if (!$config) {
        return ['interes_total' => 0, 'detalle' => []];
    }
    
    $sql = "
        SELECT 
            c.id,
            c.fecha,
            c.fecha_vencimiento,
            c.debe,
            c.haber,
            c.movimiento,
            c.n_documento,
            DATEDIFF(:fecha_calculo, c.fecha_vencimiento) as dias_mora
        FROM ctacte c
        WHERE c.id_cliente = :id_cliente
        AND c.empresa_id = :empresa_id
        AND c.debe > 0
        AND c.fecha_vencimiento < :fecha_calculo
        ORDER BY c.fecha_vencimiento ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_cliente' => $id_cliente,
        ':empresa_id' => $empresa_id,
        ':fecha_calculo' => $fecha_calculo
    ]);
    
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $interes_total = 0;
    $detalle = [];
    
    foreach ($movimientos as $mov) {
        $dias_mora = max(0, $mov['dias_mora'] - $config['dias_gracia']);
        
        if ($dias_mora > 0) {
            $saldo_pendiente = $mov['debe'] - $mov['haber'];
            $interes = calcularInteresMora($saldo_pendiente, $dias_mora, $config['tasa_mensual']);
            
            if ($interes > 0) {
                $interes_total += $interes;
                $detalle[] = [
                    'id_movimiento' => $mov['id'],
                    'n_documento' => $mov['n_documento'],
                    'movimiento' => $mov['movimiento'],
                    'saldo_pendiente' => $saldo_pendiente,
                    'dias_mora' => $dias_mora,
                    'tasa_aplicada' => $config['tasa_mensual'],
                    'interes_calculado' => $interes
                ];
            }
        }
    }
    
    return [
        'interes_total' => round($interes_total, 2),
        'detalle' => $detalle,
        'config' => $config
    ];
}

/**
 * Genera número único para interés
 */
function generarNumeroInteres($pdo, $empresa_id) {
    $prefijo = 'INT';
    $año = date('Y');
    
    $sql = "SELECT COUNT(*) as total FROM ctacte 
            WHERE empresa_id = :empresa_id 
            AND movimiento LIKE :prefijo 
            AND YEAR(fecha) = :año";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':prefijo' => "INTERÉS POR MORA%",
        ':año' => $año
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $numero = str_pad($result['total'] + 1, 6, '0', STR_PAD_LEFT);
    
    return $prefijo . '-' . $año . '-' . $numero;
}
?>
```

---

#### **Paso 3: AJAX para Aplicar Intereses**

Crear archivo: `ajax/aplicar_interes_ajax.php`

```php
<?php
// ajax/aplicar_interes_ajax.php
include '../infosesion.php';
require '../config/db_config.php';
require '../funciones/funciones_intereses.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$id_cliente = $_POST['id_cliente'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;
$usuario_id = $_SESSION['user_id'] ?? null;

if (!$id_cliente || !$empresa_id) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    $resultado = aplicarInteresesMora($id_cliente, $pdo, $usuario_id);
    
    if ($resultado['success']) {
        echo json_encode([
            'success' => true,
            'mensaje' => "Intereses aplicados: $" . number_format($resultado['monto_aplicado'], 2, ',', '.'),
            'monto' => $resultado['monto_aplicado'],
            'id_movimiento' => $resultado['id_movimiento']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $resultado['error'] ?? 'No se pudo aplicar intereses'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
```

---

#### **Paso 4: Modificar Página de Cuentas Corrientes**

En `pages/cuentas_corrientes.php`, agregar:

```php
<!-- En el botón de acciones de cada cliente -->
<td style="text-align: center;">
    <a href="cuentas_corrientes_detalle.php?id_cliente=<?php echo $c['id_cliente']; ?>" 
       class="btn-view" style="text-decoration: none; display: inline-block;">
        <i class="fas fa-eye"></i> Ver Detalle
    </a>
    
    <!-- NUEVO: Botón de aplicar intereses -->
    <button class="btn-aplicar-interes" 
            data-id-cliente="<?php echo $c['id_cliente']; ?>"
            data-nombre-cliente="<?php echo htmlspecialchars($c['nombre_completo']); ?>"
            title="Aplicar intereses por mora"
            style="background: #f39c12; color: white; border: none; padding: 6px 12px; 
                   border-radius: 4px; cursor: pointer; margin-left: 5px;">
        <i class="fas fa-percentage"></i> Intereses
    </button>
    
    <?php if (tiene_permiso('whatsapp_enviar')): ?>
        <button class="btn-whatsapp-nodered" ...>
            <i class="fab fa-whatsapp"></i> WhatsApp
        </button>
    <?php endif; ?>
</td>
```

Agregar JavaScript:

```javascript
// En el script de cuentas_corrientes.php

// Aplicar intereses por mora
document.querySelectorAll('.btn-aplicar-interes').forEach(btn => {
    btn.addEventListener('click', function() {
        const idCliente = this.dataset.idCliente;
        const nombreCliente = this.dataset.nombreCliente;
        
        if (!confirm(`¿Aplicar intereses por mora a ${nombreCliente}?`)) {
            return;
        }
        
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        const formData = new FormData();
        formData.append('id_cliente', idCliente);
        
        fetch('../ajax/aplicar_interes_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarToast(data.mensaje, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                mostrarToast('Error: ' + data.error, 'error');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-percentage"></i> Intereses';
            }
        })
        .catch(err => {
            mostrarToast('Error de conexión', 'error');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-percentage"></i> Intereses';
        });
    });
});
```

---

#### **Paso 5: Mostrar Intereses en Detalle de Cuenta**

En `pages/cuentas_corrientes_detalle.php`, agregar sección de intereses:

```php
<?php
// Calcular intereses pendientes
require '../funciones/funciones_intereses.php';
$intereses = calcularInteresesCliente($id_cliente, $pdo, $empresa_id);
?>

<!-- En el HTML, después de las estadísticas -->
<?php if ($intereses['interes_total'] > 0): ?>
<div class="card" style="background: #2c2c2c; border-left: 4px solid #f39c12; margin-bottom: 20px;">
    <h3 style="color: #f39c12; margin-bottom: 15px;">
        <i class="fas fa-percentage"></i> Intereses por Mora Pendientes
    </h3>
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <p style="margin: 5px 0; color: #fff;">
                <strong>Total Intereses:</strong> 
                <span style="color: #f39c12; font-size: 1.2rem; font-weight: bold;">
                    $ <?php echo number_format($intereses['interes_total'], 2, ',', '.'); ?>
                </span>
            </p>
            <p style="margin: 5px 0; color: #bbb; font-size: 0.85rem;">
                Tasa aplicada: <?php echo $intereses['config']['tasa_mensual']; ?>% mensual
            </p>
        </div>
        <button onclick="aplicarIntereses(<?php echo $id_cliente; ?>)"
                class="btn-primary"
                style="background: #f39c12; color: white; padding: 10px 20px; 
                       border: none; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-check"></i> Aplicar Intereses
        </button>
    </div>
    
    <!-- Detalle de cálculo -->
    <details style="margin-top: 15px;">
        <summary style="color: #00bcd4; cursor: pointer;">Ver detalle de cálculo</summary>
        <table style="margin-top: 10px; font-size: 0.8rem;">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Saldo</th>
                    <th>Días Mora</th>
                    <th>Interés</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intereses['detalle'] as $det): ?>
                <tr>
                    <td><?php echo htmlspecialchars($det['movimiento']); ?></td>
                    <td>$ <?php echo number_format($det['saldo_pendiente'], 2, ',', '.'); ?></td>
                    <td><?php echo $det['dias_mora']; ?> días</td>
                    <td style="color: #f39c12;">$ <?php echo number_format($det['interes_calculado'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</div>
<?php endif; ?>
```

---

## 6. Criterios de Aplicación

### 6.1 Cuándo se Aplican los Intereses

✅ **Se aplican cuando:**
- El saldo es mayor a $0 (deudor)
- Han pasado 30 días desde la fecha de vencimiento
- El movimiento original no ha sido pagado completamente
- La configuración de intereses está activa

❌ **NO se aplican cuando:**
- El cliente tiene saldo a favor
- El movimiento está dentro del período de gracia
- El movimiento ya tiene intereses generados
- La configuración está desactivada

---

### 6.2 Configuración por Empresa

Cada empresa puede configurar:

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `tasa_mensual` | decimal(5,2) | 3.00 | Porcentaje mensual (3% = 3.00) |
| `dias_gracia` | int | 0 | Días extras antes de aplicar intereses |
| `aplicar_automatico` | tinyint(1) | 0 | 1 = Automático, 0 = Manual |
| `frecuencia` | enum | DIARIA | Cada cuánto recalcular |

---

### 6.3 Ejemplos de Cálculo

#### **Ejemplo 1: Factura simple**
```
Factura #100: $5,000
Fecha emisión: 01/01/2026
Fecha vencimiento: 31/01/2026 (+30 días)
Fecha cálculo: 15/03/2026

Días de mora: 15/03 - 31/01 = 43 días
Días después de gracia (0): 43 días

Interés = 5,000 × (3.00 / 30 / 100) × 43 = $215.00
```

#### **Ejemplo 2: Múltiples facturas**
```
Factura #100: $5,000 (45 días mora) → $225.00
Factura #105: $3,000 (20 días mora) → $90.00
Factura #110: $2,000 (60 días mora) → $360.00

Total intereses: $675.00
```

#### **Ejemplo 3: Con días de gracia**
```
Configuración: 5 días de gracia
Factura: $10,000 (40 días mora)

Días efectivos: 40 - 5 = 35 días
Interés = 10,000 × (3.00 / 30 / 100) × 35 = $350.00
```

---

## 7. Consideraciones Adicionales

### 7.1 Pago de Intereses
- Los intereses se agregan como un nuevo movimiento en `ctacte` (tipo "INTERÉS POR MORA")
- El cliente paga los intereses junto con el saldo normal
- Se genera recibo separado o incluido en el recibo de pago

### 7.2 Anulaciones
- Si se anula una factura, también se deben anular los intereses generados sobre ella
- Crear función `anularInteresesFactura($id_factura)`

### 7.3 Reportes
- Agregar columna "Intereses" en reportes de cuentas corrientes
- Reporte mensual de intereses generados por cliente
- Reporte de intereses pendientes de aplicación

### 7.4 Auditoría
- Todos los cálculos quedan registrados en `intereses_generados`
- Se guarda: fecha cálculo, usuario que aplicó, tasa utilizada, detalle
- Permite revertir intereses si es necesario

---

## 8. Próximos Pasos

1. **Revisar y aprobar** este diseño
2. **Ejecutar migración** de base de datos
3. **Crear archivos** de funciones y AJAX
4. **Modificar interfaces** existentes
5. **Probar** con datos de prueba
6. **Documentar** para usuarios finales

---

## 📞 Contacto

Para consultas sobre este diseño, contactar al equipo de desarrollo.

**Fecha:** 08/03/2026  
**Versión:** 1.0  
**Estado:** Propuesta