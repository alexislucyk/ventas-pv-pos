# Guía de Implementación: Comprobantes Externos

## Resumen

Este documento describe paso a paso cómo implementar la funcionalidad de **asociación de comprobantes externos** a ventas internas en el sistema POS.

**Objetivo**: Permitir asociar múltiples ventas registradas en el sistema a un único comprobante/factura emitida externamente (ante ARCA/AFIP), manteniendo la integridad de los datos existentes.

---

## Pre-requisitos

- [ ] Backup completo de la base de datos actual
- [ ] Ambiente de desarrollo disponible (carpeta `pos_dev`)
- [ ] Acceso a la base de datos MySQL
- [ ] Permisos de administrador del sistema

---

## Paso 1: Crear la Migración SQL

**Archivo**: `migrations/44_comprobantes_externos.sql`

```sql
-- =============================================================================
-- Migración: Comprobantes Externos
-- Fecha: 2026-09-09
-- Propósito: Permitir asociar ventas internas a facturas emitidas externamente
-- =============================================================================

-- 1. TABLA: comprobantes_externos
CREATE TABLE IF NOT EXISTS `comprobantes_externos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `punto_venta` INT NOT NULL COMMENT 'Punto de venta del comprobante externo',
  `n_comprobante` INT NOT NULL COMMENT 'Número de comprobante externo',
  `tipo_comprobante` INT NOT NULL DEFAULT 11 COMMENT '1=Factura A, 6=Factura B, 11=Factura C, etc.',
  `fecha_emision` DATE NOT NULL,
  `total_comprobante` DECIMAL(15,2) NOT NULL,
  `cuit_cliente` VARCHAR(20) NOT NULL COMMENT 'CUIT del cliente facturado',
  `razon_social_cliente` VARCHAR(255) NOT NULL,
  `cond_iva` VARCHAR(50) DEFAULT NULL COMMENT 'Responsable inscripto, monotributo, etc.',
  `observaciones` TEXT NULL,
  `fecha_carga` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Cuándo se registró en el sistema',
  `usuario_carga` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ce_empresa` (`empresa_id`),
  KEY `idx_ce_comprobante` (`empresa_id`, `punto_venta`, `n_comprobante`, `tipo_comprobante`),
  KEY `idx_ce_fecha` (`empresa_id`, `fecha_emision`),
  KEY `idx_ce_cuit` (`empresa_id`, `cuit_cliente`),
  CONSTRAINT `fk_ce_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;

-- 2. TABLA: venta_comprobante_externo (relación N:1)
CREATE TABLE IF NOT EXISTS `venta_comprobante_externo` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `empresa_id` INT NOT NULL,
  `venta_id` INT NOT NULL COMMENT 'ID de la venta en tabla ventas',
  `comprobante_externo_id` INT NOT NULL COMMENT 'ID del comprobante externo',
  `monto_asignado` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto de esta venta asignado al comprobante',
  `fecha_asociacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `usuario_asociacion` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vce_venta` (`venta_id`, `empresa_id`) COMMENT 'Una venta solo puede estar asociada a un comprobante externo',
  KEY `idx_vce_comprobante` (`comprobante_externo_id`),
  KEY `idx_vce_empresa` (`empresa_id`),
  CONSTRAINT `fk_vce_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vce_comprobante` FOREIGN KEY (`comprobante_externo_id`) REFERENCES `comprobantes_externos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vce_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
```
```

```sql
-- 3. REGISTRO DE MÓDULOS PARA PERMISOS
INSERT INTO modulos (nombre, archivo, icono, seccion, tipo)
SELECT 'Comprobantes Externos', 'pages/comprobantes_externos.php', 'fas fa-file-invoice-dollar', 'Transacciones', 'pagina'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'pages/comprobantes_externos.php');

INSERT INTO modulos (nombre, archivo, icono, seccion, tipo)
SELECT 'Comprobantes: Asociar Ventas', 'comp_asociar_venta', 'fas fa-link', 'Transacciones', 'funcion'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'comp_asociar_venta');

INSERT INTO modulos (nombre, archivo, icono, seccion, tipo)
SELECT 'Comprobantes: Desasociar Ventas', 'comp_desasociar_venta', 'fas fa-unlink', 'Transacciones', 'funcion'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE archivo = 'comp_desasociar_venta');

-- 4. ASIGNACIÓN DE PERMISOS INICIALES (SOLO ADMIN)
INSERT INTO permisos_rol (empresa_id, rol, modulo_id)
SELECT 1, 'admin', id FROM modulos WHERE archivo IN (
    'pages/comprobantes_externos.php',
    'comp_asociar_venta',
    'comp_desasociar_venta'
)
AND NOT EXISTS (
    SELECT 1 FROM permisos_rol pr 
    WHERE pr.empresa_id = 1 
    AND pr.rol = 'admin' 
    AND pr.modulo_id = modulos.id
);

-- 5. TRIGGER DE VALIDACIÓN DE MONTOS
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `trg_validar_monto_comp_ext_bi`
BEFORE INSERT ON `venta_comprobante_externo`
FOR EACH ROW
BEGIN
    DECLARE v_total_comprobante DECIMAL(15,2);
    DECLARE v_suma_actual DECIMAL(15,2);
    
    SELECT total_comprobante INTO v_total_comprobante
    FROM comprobantes_externos
    WHERE id = NEW.comprobante_externo_id AND empresa_id = NEW.empresa_id;
    
    SELECT COALESCE(SUM(monto_asignado), 0) INTO v_suma_actual
    FROM venta_comprobante_externo
    WHERE comprobante_externo_id = NEW.comprobante_externo_id 
      AND empresa_id = NEW.empresa_id;
    
    IF (v_suma_actual + NEW.monto_asignado) > v_total_comprobante THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Error: La suma de montos asignados excede el total del comprobante';
    END IF;
END //

DELIMITER ;
```

---

## Paso 2: Ejecutar la Migración

### Opción A: Desde línea de comandos
```bash
mysql -u root -p pos_dev < migrations/44_comprobantes_externos.sql
```

### Opción B: Desde PHPMyAdmin
1. Abrir PHPMyAdmin
2. Seleccionar base de datos `pos_dev`
3. Ir a pestaña "Importar"
4. Seleccionar archivo `migrations/44_comprobantes_externos.sql`
5. Click en "Continuar"

### Verificar migración exitosa
```sql
SHOW TABLES LIKE '%comprobante%';
SELECT * FROM modulos WHERE archivo LIKE '%comprobante%';
SELECT m.nombre, pr.rol FROM permisos_rol pr JOIN modulos m ON pr.modulo_id = m.id WHERE m.archivo LIKE '%comprobante%';
```

---

## Paso 3: Crear Archivos PHP

### 3.1 Página Principal - `pages/comprobantes_externos.php`

```php
<?php
// pages/comprobantes_externos.php
include 'infosesion.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
require '../config/db_config.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

$empresa_id = $_SESSION['empresa_id'] ?? 1;
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Sistema';

// TODO: Verificar permiso de acceso a la página

$mensaje = '';
$mensaje_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    switch ($accion) {
        case 'guardar_comprobante':
            // TODO: Validar permiso 'comp_asociar_venta'
            break;
        case 'asociar_venta':
            // TODO: Validar permiso 'comp_asociar_venta'
            break;
        case 'desasociar_venta':
            // TODO: Validar permiso 'comp_desasociar_venta'
            break;
    }
}

// Obtener comprobantes externos
$comprobantes = [];
try {
    $sql = "SELECT ce.*, COUNT(vce.venta_id) as cant_ventas,
                   COALESCE(SUM(vce.monto_asignado), 0) as total_asignado
            FROM comprobantes_externos ce
            LEFT JOIN venta_comprobante_externo vce ON ce.id = vce.comprobante_externo_id
            WHERE ce.empresa_id = ?
            GROUP BY ce.id
            ORDER BY ce.fecha_emision DESC, ce.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id]);
    $comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = "Error al cargar comprobantes: " . $e->getMessage();
    $mensaje_tipo = "error";
}

// TODO: Incluir HTML de la página
?>
```

### 3.2 AJAX - Guardar Comprobante - `ajax/guardar_comprobante_externo.php`

```php
<?php
// ajax/guardar_comprobante_externo.php
session_start();
header('Content-Type: application/json');
require '../config/db_config.php';

$response = ['success' => false, 'error' => ''];

if (!isset($_SESSION['usuario_id'])) {
    $response['error'] = 'Sesión expirada';
    echo json_encode($response);
    exit;
}

// TODO: Verificar permiso 'comp_asociar_venta'

$empresa_id = $_SESSION['empresa_id'] ?? 1;
$punto_venta = intval($_POST['punto_venta'] ?? 0);
$n_comprobante = intval($_POST['n_comprobante'] ?? 0);
$tipo_comprobante = intval($_POST['tipo_comprobante'] ?? 11);
$fecha_emision = $_POST['fecha_emision'] ?? date('Y-m-d');
$total_comprobante = floatval($_POST['total_comprobante'] ?? 0);
$cuit_cliente = trim($_POST['cuit_cliente'] ?? '');
$razon_social = trim($_POST['razon_social_cliente'] ?? '');
$cond_iva = trim($_POST['cond_iva'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');

$errores = [];
if ($punto_venta <= 0) $errores[] = 'Punto de venta inválido';
if ($n_comprobante <= 0) $errores[] = 'Número de comprobante inválido';
if ($total_comprobante <= 0) $errores[] = 'Total debe ser mayor a 0';
if (empty($cuit_cliente)) $errores[] = 'CUIT requerido';
if (empty($razon_social)) $errores[] = 'Razón social requerida';

if (!empty($errores)) {
    $response['error'] = implode(', ', $errores);
    echo json_encode($response);
    exit;
}

try {
    $sql = "INSERT INTO comprobantes_externos 
            (empresa_id, punto_venta, n_comprobante, tipo_comprobante, fecha_emision, 
             total_comprobante, cuit_cliente, razon_social_cliente, cond_iva, observaciones, 
             usuario_carga) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $empresa_id, $punto_venta, $n_comprobante, $tipo_comprobante, $fecha_emision,
        $total_comprobante, $cuit_cliente, $razon_social, $cond_iva, $observaciones,
        $_SESSION['usuario_nombre'] ?? 'Sistema'
    ]);
    
    $response['success'] = true;
    $response['id'] = $pdo->lastInsertId();
    $response['mensaje'] = 'Comprobante registrado correctamente';
} catch (PDOException $e) {
    $response['error'] = 'Error al guardar: ' . $e->getMessage();
}

echo json_encode($response);
?>
```

### 3.3 AJAX - Asociar Venta - `ajax/asociar_venta_comprobante.php`

```php
<?php
session_start();
header('Content-Type: application/json');
require '../config/db_config.php';
$response = ['success' => false, 'error' => ''];
if (!isset($_SESSION['usuario_id'])) { $response['error'] = 'Sesión expirada'; echo json_encode($response); exit; }
// TODO: Verificar permiso 'comp_asociar_venta'
$empresa_id = $_SESSION['empresa_id'] ?? 1;
$venta_id = intval($_POST['venta_id'] ?? 0);
$comprobante_externo_id = intval($_POST['comprobante_externo_id'] ?? 0);
$monto_asignado = floatval($_POST['monto_asignado'] ?? 0);
if ($venta_id <= 0 || $comprobante_externo_id <= 0) { $response['error'] = 'Datos inválidos'; echo json_encode($response); exit; }
try {
    $sql_check = "SELECT id FROM venta_comprobante_externo WHERE venta_id = ? AND empresa_id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$venta_id, $empresa_id]);
    if ($stmt_check->fetch()) { $response['error'] = 'La venta ya está asociada a otro comprobante'; echo json_encode($response); exit; }
    if ($monto_asignado <= 0) {
        $sql_venta = "SELECT total_venta FROM ventas WHERE id = ? AND empresa_id = ?";
        $stmt_venta = $pdo->prepare($sql_venta);
        $stmt_venta->execute([$venta_id, $empresa_id]);
        $venta = $stmt_venta->fetch();
        if (!$venta) { $response['error'] = 'Venta no encontrada'; echo json_encode($response); exit; }
        $monto_asignado = $venta['total_venta'];
    }
    $sql = "INSERT INTO venta_comprobante_externo (empresa_id, venta_id, comprobante_externo_id, monto_asignado, usuario_asociacion) VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id, $venta_id, $comprobante_externo_id, $monto_asignado, $_SESSION['usuario_nombre'] ?? 'Sistema']);
    $response['success'] = true; $response['mensaje'] = 'Venta asociada correctamente';
} catch (PDOException $e) { $response['error'] = (strpos($e->getMessage(), 'excede el total') !== false) ? $e->getMessage() : 'Error al asociar: ' . $e->getMessage(); }
echo json_encode($response);
?>
```

### 3.4 AJAX - Desasociar Venta - `ajax/desasociar_venta_comprobante.php`

```php
<?php
session_start();
header('Content-Type: application/json');
require '../config/db_config.php';
$response = ['success' => false, 'error' => ''];
if (!isset($_SESSION['usuario_id'])) { $response['error'] = 'Sesión expirada'; echo json_encode($response); exit; }
// TODO: Verificar permiso 'comp_desasociar_venta'
$empresa_id = $_SESSION['empresa_id'] ?? 1;
$venta_id = intval($_POST['venta_id'] ?? 0);
if ($venta_id <= 0) { $response['error'] = 'ID de venta inválido'; echo json_encode($response); exit; }
try {
    $sql = "DELETE FROM venta_comprobante_externo WHERE venta_id = ? AND empresa_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$venta_id, $empresa_id]);
    $response = ($stmt->rowCount() > 0) ? ['success' => true, 'mensaje' => 'Asociación eliminada'] : ['success' => false, 'error' => 'No se encontró la asociación'];
} catch (PDOException $e) { $response['error'] = 'Error al desasociar: ' . $e->getMessage(); }
echo json_encode($response);
?>
```

### 3.5 AJAX - Buscar Ventas Sin Facturar - `ajax/buscar_ventas_sin_facturar.php`

```php
<?php
session_start();
header('Content-Type: application/json');
require '../config/db_config.php';
$response = ['success' => false, 'error' => ''];
$empresa_id = $_SESSION['empresa_id'] ?? 1;
$filtro = trim($_GET['q'] ?? '');
try {
    $sql = "SELECT v.id, v.n_documento, v.fecha_venta, v.total_venta, v.estado, CONCAT(c.apellido, ', ', c.nombre) AS cliente
            FROM ventas v
            LEFT JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = v.empresa_id
            LEFT JOIN ventas_afip va ON v.id = va.id_venta
            LEFT JOIN venta_comprobante_externo vce ON v.id = vce.venta_id
            WHERE v.empresa_id = ? AND v.estado = 'Finalizada' AND va.id IS NULL AND vce.id IS NULL";
    $params = [$empresa_id];
    if (!empty($filtro)) { $sql .= " AND (v.n_documento LIKE ? OR c.apellido LIKE ? OR c.nombre LIKE ?)"; $like = "%$filtro%"; $params = array_merge($params, [$like, $like, $like]); }
    $sql .= " ORDER BY v.fecha_venta DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $response = ['success' => true, 'ventas' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
} catch (PDOException $e) { $response['error'] = $e->getMessage(); }
echo json_encode($response);
?>
```

---

## Paso 4: Consultas Útiles para Reportes

```sql
-- Reporte: Ventas por estado de facturación
SELECT 
    v.empresa_id,
    COUNT(*) as total_ventas,
    SUM(CASE WHEN va.id IS NOT NULL THEN 1 ELSE 0 END) as facturadas_afip,
    SUM(CASE WHEN vce.id IS NOT NULL THEN 1 ELSE 0 END) as facturadas_externo,
    SUM(CASE WHEN va.id IS NULL AND vce.id IS NULL THEN 1 ELSE 0 END) as sin_facturar
FROM ventas v
LEFT JOIN ventas_afip va ON v.id = va.id_venta
LEFT JOIN venta_comprobante_externo vce ON v.id = vce.venta_id
WHERE v.estado = 'Finalizada'
GROUP BY v.empresa_id;

-- Reporte: Comprobantes externos con detalle
SELECT 
    ce.id, ce.punto_venta, ce.n_comprobante, ce.tipo_comprobante,
    ce.razon_social_cliente, ce.total_comprobante,
    COUNT(vce.venta_id) as cant_ventas,
    SUM(vce.monto_asignado) as total_asignado,
    (ce.total_comprobante - SUM(vce.monto_asignado)) as diferencia
FROM comprobantes_externos ce
LEFT JOIN venta_comprobante_externo vce ON ce.id = vce.comprobante_externo_id
GROUP BY ce.id;

-- Ventas sin facturar por cliente
SELECT 
    c.id as cliente_id,
    CONCAT(c.apellido, ', ', c.nombre) as cliente,
    COUNT(v.id) as ventas_sin_facturar,
    SUM(v.total_venta) as monto_total
FROM ventas v
JOIN clientes c ON v.id_cliente = c.id AND c.empresa_id = v.empresa_id
LEFT JOIN ventas_afip va ON v.id = va.id_venta
LEFT JOIN venta_comprobante_externo vce ON v.id = vce.venta_id
WHERE v.empresa_id = 1 AND v.estado = 'Finalizada' AND va.id IS NULL AND vce.id IS NULL
GROUP BY c.id
HAVING COUNT(v.id) > 0
ORDER BY monto_total DESC;
```

---

## Paso 5: Pruebas

### 5.1 Pruebas de Base de Datos

```sql
-- Prueba 1: Insertar comprobante externo
INSERT INTO comprobantes_externos 
(empresa_id, punto_venta, n_comprobante, tipo_comprobante, fecha_emision, 
 total_comprobante, cuit_cliente, razon_social_cliente) 
VALUES (1, 1, 99999, 11, '2026-09-09', 100000.00, '20301234567', 'Escuela Test');

-- Prueba 2: Asociar venta (usar un ID de venta existente)
INSERT INTO venta_comprobante_externo 
(empresa_id, venta_id, comprobante_externo_id, monto_asignado) 
VALUES (1, 100, LAST_INSERT_ID(), 50000.00);

-- Prueba 3: Verificar trigger (debe fallar si excede el total)
INSERT INTO venta_comprobante_externo 
(empresa_id, venta_id, comprobante_externo_id, monto_asignado) 
VALUES (1, 101, 1, 60000.00);  -- Debe dar error

-- Limpiar pruebas
DELETE FROM venta_comprobante_externo WHERE empresa_id = 1;
DELETE FROM comprobantes_externos WHERE empresa_id = 1;
```

### 5.2 Checklist de Pruebas Funcionales

- [ ] Acceder a la página de comprobantes externos con usuario admin
- [ ] Crear un nuevo comprobante externo
- [ ] Buscar ventas sin facturar
- [ ] Asociar una venta a un comprobante
- [ ] Verificar que un usuario sin permisos no puede acceder
- [ ] Desasociar una venta
- [ ] Verificar que el trigger impide exceder el monto

---

## Paso 6: Checklist de Finalización

- [ ] Migración SQL ejecutada sin errores
- [ ] Tablas `comprobantes_externos` y `venta_comprobante_externo` creadas
- [ ] Módulos registrados en tabla `modulos`
- [ ] Permisos asignados al rol admin
- [ ] Trigger de validación funcionando
- [ ] Página `comprobantes_externos.php` funcional
- [ ] AJAX endpoints funcionando correctamente
- [ ] Pruebas de seguridad pasadas
- [ ] Backup realizado antes de producción

---

## Notas Importantes

1. **NUNCA** modificar la tabla `ventas` - todas las relaciones son vía tablas separadas
2. **SIEMPRE** validar permisos antes de ejecutar acciones de asociación/desasociación
3. **VERIFICAR** que el trigger de validación esté activo antes de asociar ventas
4. **REALIZAR BACKUP** antes de ejecutar la migración en producción
5. La relación **N:1** se garantiza con el UNIQUE约束 en `venta_id`
6. El sistema respeta el patrón multi-empresa existente

---

## Archivos a Crear/Modificar

| Archivo | Acción | Descripción |
|---------|--------|-------------|
| `migrations/44_comprobantes_externos.sql` | Crear | Migración principal |
| `pages/comprobantes_externos.php` | Crear | Página principal |
| `ajax/guardar_comprobante_externo.php` | Crear | Guardar comprobante |
| `ajax/asociar_venta_comprobante.php` | Crear | Asociar venta |
| `ajax/desasociar_venta_comprobante.php` | Crear | Desasociar venta |
| `ajax/buscar_ventas_sin_facturar.php` | Crear | Buscar ventas |

---

*Documento creado: 2026-09-09 - Versión: 1.0*
```
```