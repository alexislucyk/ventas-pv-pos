# Implementación del Sistema de Intereses por Mora

## 📋 Resumen de Cambios

Este documento describe la implementación completa del sistema de intereses por mora en cuentas corrientes de clientes.

---

## 🎯 Funcionalidades Implementadas

### 1. **Cálculo Automático de Intereses**
   - Interés simple sobre saldo deudor
   - Fórmula: `Interés = Saldo × (Tasa / 30) × Días de Mora`
   - Configuración de días de gracia
   - Tasa mensual configurable por empresa

### 2. **Aplicación Manual de Intereses**
   - Botón "Intereses" en lista de clientes
   - Botón "Aplicar Intereses" en detalle de cuenta corriente
   - Confirmación antes de aplicar
   - Registro automático en tabla `intereses_generados`

### 3. **Configuración por Empresa**
   - Tasa mensual de interés (default: 3%)
   - Días de gracia (default: 0)
   - Frecuencia de cálculo (DIARIA/SEMANAL/MENSUAL)
   - Activación/desactivación del sistema
   - Aplicación automática opcional

### 4. **Interfaz de Usuario**
   - Visualización de intereses pendientes en detalle de cliente
   - Desglose por factura con días de mora
   - Estadísticas mensuales en página de configuración
   - Integración completa en menú lateral

---

## 📁 Archivos Creados

### Nuevos Archivos:
1. **`migrations/agregar_fecha_vencimiento_ctacte.sql`**
   - Migración de base de datos
   - Agrega campo `fecha_vencimiento` a tabla `ctacte`
   - Crea tablas `configuracion_intereses` e `intereses_generados`

2. **`funciones/funciones_intereses.php`**
   - Funciones de cálculo de intereses
   - Lógica de aplicación de intereses
   - Utilidades y helpers

3. **`ajax/aplicar_interes_ajax.php`**
   - Endpoint AJAX para aplicar intereses
   - Validación y manejo de errores

4. **`pages/configuracion_intereses.php`**
   - Página de configuración de parámetros
   - Estadísticas de intereses generados
   - Formulario de configuración

### Archivos Modificados:
1. **`pages/cuentas_corrientes.php`**
   - Botón "Intereses" por cliente en lista
   - JavaScript para aplicar intereses

2. **`pages/cuentas_corrientes_detalle.php`**
   - Sección de intereses pendientes
   - Detalle de cálculo por factura
   - Botón de aplicación

3. **`pages/sidebar.php`**
   - Enlace a "Config. Intereses" en menú

---

## 🚀 Instrucciones de Instalación

### Paso 1: Ejecutar Migración de Base de Datos

```bash
# Opción A: Usando phpMyAdmin
1. Abrir phpMyAdmin
2. Seleccionar la base de datos del sistema
3. Ir a la pestaña "SQL"
4. Copiar y pegar el contenido de: migrations/agregar_fecha_vencimiento_ctacte.sql
5. Ejecutar el script

# Opción B: Usando línea de comandos
mysql -u usuario -p nombre_base_datos < migrations/agregar_fecha_vencimiento_ctacte.sql
```

**Verificación:**
```sql
-- Verificar que el campo se agregó
DESCRIBE ctacte;

-- Verificar que las tablas se crearon
SHOW TABLES LIKE 'configuracion_intereses';
SHOW TABLES LIKE 'intereses_generados';

-- Verificar configuración por empresa
SELECT * FROM configuracion_intereses;
```

### Paso 2: Configurar Permisos

El sistema requiere el permiso `configuracion_ver` para acceder a la página de configuración.

```sql
-- Verificar permisos existentes
SELECT * FROM permisos WHERE nombre LIKE '%configuracion%';

-- Si es necesario, agregar permiso (consultar con el administrador del sistema)
```

### Paso 3: Configurar Parámetros de Intereses

1. Acceder a: **Cuentas Corrientes → Config. Intereses**
2. Configurar los parámetros:
   - **Tasa Mensual**: 3.00 (3% mensual)
   - **Días de Gracia**: 0 (sin días de gracia)
   - **Frecuencia**: DIARIA
   - **Aplicar Automáticamente**: No (para control manual)
   - **Sistema Activo**: Sí

3. Guardar configuración

### Paso 4: Verificar Funcionamiento

1. **Verificar fechas de vencimiento:**
```sql
-- Las facturas existentes deben tener fecha_vencimiento
SELECT id, n_documento, fecha, fecha_vencimiento 
FROM ctacte 
WHERE fecha_vencimiento IS NULL;
```

2. **Probar cálculo de intereses:**
   - Ir a "Cuentas Corrientes"
   - Buscar un cliente con saldo deudor
   - Hacer clic en "Ver Detalle"
   - Verificar que aparezca la sección "Intereses por Mora Pendientes"

3. **Probar aplicación de intereses:**
   - Hacer clic en "Aplicar Intereses"
   - Confirmar la acción
   - Verificar que se genere un nuevo movimiento en la cuenta corriente

---

## ⚙️ Configuración Recomendada

### Para la mayoría de los negocios:
- **Tasa Mensual**: 3.00% a 5.00%
- **Días de Gracia**: 0 a 5 días
- **Frecuencia**: DIARIA
- **Aplicación**: MANUAL (recomendado para control)

### Para negocios con clientes mayoristas:
- **Tasa Mensual**: 2.00% a 3.00%
- **Días de Gracia**: 15 a 30 días
- **Frecuencia**: MENSUAL
- **Aplicación**: MANUAL

---

## 📊 Estructura de Base de Datos

### Tabla: `ctacte` (modificada)
```sql
ALTER TABLE `ctacte`
ADD COLUMN `fecha_vencimiento` date DEFAULT NULL 
COMMENT 'Fecha de vencimiento del movimiento (para cálculo de intereses)'
AFTER `fecha`;

ADD INDEX `idx_fecha_vencimiento` (`fecha_vencimiento`);
```

### Tabla: `configuracion_intereses` (nueva)
```sql
CREATE TABLE `configuracion_intereses` (
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
);
```

### Tabla: `intereses_generados` (nueva)
```sql
CREATE TABLE `intereses_generados` (
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
  KEY `fk_intereses_empresa` (`empresa_id`),
  CONSTRAINT `fk_intereses_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_intereses_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
);
```

---

## 🔧 Funciones Principales

### `calcularInteresMora($saldo, $dias_mora, $tasa_mensual)`
Calcula el interés para un saldo específico.

**Parámetros:**
- `$saldo`: Saldo deudor (float)
- `$dias_mora`: Días transcurridos desde vencimiento (int)
- `$tasa_mensual`: Tasa mensual en porcentaje (float, default: 3.00)

**Retorna:** Monto de interés calculado (float)

### `calcularInteresesCliente($id_cliente, $pdo, $empresa_id)`
Calcula todos los intereses pendientes de un cliente.

**Retorna:** Array con:
- `interes_total`: Suma total de intereses
- `detalle`: Array con detalle por factura
- `config`: Configuración utilizada

### `aplicarInteresesMora($id_cliente, $pdo, $usuario_id)`
Aplica los intereses calculados como un nuevo movimiento en ctacte.

**Retorna:** Array con resultado de la operación

---

## 🎨 Interfaz de Usuario

### En Lista de Clientes (`cuentas_corrientes.php`)
- Botón naranja "Intereses" por cada cliente deudor
- Al hacer clic, muestra confirmación
- Aplica intereses y recarga la página

### En Detalle de Cliente (`cuentas_corrientes_detalle.php`)
- Card especial con sección "Intereses por Mora Pendientes"
- Muestra:
  - Total de intereses calculados
  - Tasa aplicada
  - Días de gracia configurados
  - Botón "Aplicar Intereses"
  - Detalle expandible por factura

### En Configuración (`configuracion_intereses.php`)
- Formulario de parámetros
- Estadísticas del mes actual:
  - Total de intereses generados
  - Monto total
  - Promedio por interés
  - Clientes afectados

---

## ⚠️ Consideraciones Importantes

### 1. Fecha de Vencimiento
- **Para facturas nuevas**: Se debe establecer `fecha_vencimiento` al crear el movimiento en ctacte
- **Para facturas existentes**: La migración asigna `fecha + 30 días` por defecto

### 2. Modificación en Procesos de Venta
Para que el sistema funcione correctamente, modificar el proceso de facturación a ctacte:

```php
// En el proceso de venta a ctacte, después de insertar en ctacte:
$fecha_emision = date('Y-m-d');
$fecha_vencimiento = date('Y-m-d', strtotime('+30 days')); // O según configuración

$sql_update = "UPDATE ctacte 
               SET fecha_vencimiento = :fecha_vencimiento 
               WHERE id = :id";
$stmt_update = $pdo->prepare($sql_update);
$stmt_update->execute([
    ':fecha_vencimiento' => $fecha_vencimiento,
    ':id' => $id_movimiento
]);
```

### 3. Anulaciones
Si se anula una factura, se debe llamar a:
```php
anularInteresesFactura($id_factura, $pdo, $usuario_id);
```

### 4. Aplicación Automática
Si se activa `aplicar_automatico`, crear un cron job que ejecute:
```php
// procesos/calcular_intereses_automatico.php
// Ejecutar según frecuencia configurada
```

---

## 🧪 Pruebas Recomendadas

### Test 1: Cliente sin intereses
- Cliente con saldo a favor o sin mora
- No debe mostrar sección de intereses

### Test 2: Cliente con intereses pendientes
- Cliente con factura vencida de 45 días
- Saldo: $10,000
- Tasa: 3% mensual
- **Esperado**: $450 de interés

### Test 3: Aplicación de intereses
- Aplicar intereses a cliente
- Verificar que se cree movimiento en ctacte
- Verificar registro en `intereses_generados`
- Verificar que no se duplique al aplicar nuevamente

### Test 4: Días de gracia
- Configurar 5 días de gracia
- Factura con 40 días de mora
- **Esperado**: Se calculen 35 días (40 - 5)

### Test 5: Pago con intereses
- Aplicar intereses a cliente
- Registrar pago del total (saldo + intereses)
- Verificar que se cancelen ambos conceptos

---

## 🐛 Solución de Problemas

### No aparecen intereses pendientes
```sql
-- Verificar que fecha_vencimiento no sea NULL
SELECT COUNT(*) FROM ctacte WHERE fecha_vencimiento IS NULL;

-- Verificar configuración activa
SELECT * FROM configuracion_intereses WHERE empresa_id = X AND activo = 1;
```

### Intereses duplicados
- El sistema previene duplicados automáticamente
- Verificar tabla `intereses_generados` para auditoría

### Error al aplicar intereses
- Verificar logs de PHP
- Verificar que el cliente tenga saldo deudor
- Verificar que la configuración esté activa

---

## 📞 Soporte

Para consultas o problemas:
1. Revisar logs de PHP en `/logs/`
2. Verificar tabla `intereses_generados` para auditoría
3. Consultar documentación en `docs/diseño_intereses_mora.md`

---

## ✅ Checklist de Verificación

- [ ] Migración ejecutada exitosamente
- [ ] Campo `fecha_vencimiento` existe en `ctacte`
- [ ] Tablas `configuracion_intereses` e `intereses_generados` creadas
- [ ] Configuración inicial guardada
- [ ] Enlace visible en menú lateral
- [ ] Botones de intereses visibles en lista y detalle
- [ ] Cálculo de intereses funciona correctamente
- [ ] Aplicación de intereses genera movimiento en ctacte
- [ ] Intereses se pueden pagar junto con saldo normal
- [ ] No se duplican intereses al aplicar múltiples veces

---

**Versión:** 1.0  
**Fecha:** 08/03/2026  
**Estado:** Implementado y listo para pruebas