# Análisis del Flujo de Caja y Sistema de Cierres

> **Sistema:** POS Electricidad Lucyk (pos_dev)  
> **Versión:** 2.0.0  
> **Fecha de análisis:** 08/03/2026  
> **Analista:** Sistema de Análisis  

---

## 1. RESUMEN EJECUTIVO

El sistema implementa un **flujo de caja completo** con las siguientes características:

- ✅ **Registro de movimientos** (ingresos/egresos) en tiempo real
- ✅ **Cierre diario de caja** con conteo físico de billetes
- ✅ **Cálculo automático** de saldo esperado vs. saldo real
- ✅ **Detección de diferencias** (sobrante/faltante)
- ✅ **Fondo de vuelto** para el día siguiente
- ✅ **Historial de cierres** en tabla dedicada
- ✅ **Marcado de movimientos** como cerrados
- ⚠️ **Multi-sucursal parcial** (sin selector UI visible)
- ⚠️ **Sin validación** de cierres previos antes de abrir nuevo día

---

## 2. ARQUITECTURA DEL FLUJO DE CAJA

### 2.1 Componentes Principales

```
┌─────────────────────────────────────────────────────────┐
│                    FLUJO DE CAJA                         │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ENTRADAS DE DINERO:                                     │
│  ├── Ventas (pages/ventas.php)                           │
│  │   ├── Efectivo                                        │
│  │   ├── Transferencia                                   │
│  │   └── Mixto                                           │
│  ├── Pagos Cuenta Corriente (pages/cobro_cuotas.php)     │
│  ├── Pagos Proveedores (ajax/registrar_pago_proveedor)   │
│  └── Movimientos Manuales (pages/movimiento_manual.php)  │
│                                                          │
│  SALIDAS DE DINERO:                                      │
│  ├── Compras (pages/compras.php)                         │
│  ├── Gastos Manuales (pages/movimiento_manual.php)       │
│  └── Anulaciones (pages/anulaciones.php)                 │
│                                                          │
│  PROCESAMIENTO:                                          │
│  ├── Dashboard (pages/caja_dashboard.php)                │
│  ├── Cierre (pages/cierre_caja.php)                      │
│  └── Procesar Cierre (pages/procesar_cierre.php)         │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### 2.2 Flujo de Datos

```
Venta/Ingreso → INSERT en movimientos (cerrado=0)
              ↓
         [Acumulación en el día]
              ↓
         Usuario revisa caja_dashboard.php
              ↓
         Usuario ejecuta cierre_caja.php
              ↓
         Conteo físico de billetes
              ↓
         Cálculo: Saldo Esperado vs. Saldo Real
              ↓
         INSERT en cierres_caja
              ↓
         UPDATE movimientos SET cerrado=1
              ↓
         (Opcional) Fondo inicial para mañana
              ↓
         Día siguiente: Nuevos movimientos con cerrado=0
```

---

## 3. ESTRUCTURA DE BASE DE DATOS

### 3.1 Tabla `movimientos` (Registro de transacciones)

```sql
CREATE TABLE `movimientos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
  `tipo` varchar(255) NOT NULL,           -- 'INGRESO' | 'EGRESO'
  `monto` decimal(10,0) NOT NULL,
  `detalle` text NOT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `fecha` date NOT NULL,
  `metodo_pago` text NOT NULL,            -- 'EFECTIVO' | 'TRANSFERENCIA' | 'MIXTO'
  `cerrado` tinyint(1) DEFAULT '0'        -- 0=abierto, 1=cerrado
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

**Campos clave:**
- `cerrado`: Flag que indica si el movimiento está incluido en un cierre
- `tipo`: Distingue entre ingresos y egresos
- `metodo_pago`: Clasifica el medio de pago
- `empresa_id` + `sucursal_id`: Filtrado multi-empresa/multi-sucursal

### 3.2 Tabla `cierres_caja` (Historial de cierres)

```sql
CREATE TABLE `cierres_caja` (
  `id` int NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `sucursal_id` int NOT NULL,
  `fecha_cierre` datetime DEFAULT CURRENT_TIMESTAMP,
  `saldo_inicial` decimal(10,2) DEFAULT NULL,         -- No usado actualmente
  `ingresos_efectivo` decimal(10,2) DEFAULT NULL,
  `ingresos_transf` decimal(10,2) DEFAULT NULL,
  `egresos` decimal(10,2) DEFAULT NULL,
  `saldo_esperado_efectivo` decimal(10,2) DEFAULT NULL,
  `saldo_real_efectivo` decimal(10,2) DEFAULT NULL,
  `diferencia` decimal(10,2) DEFAULT NULL,
  `observaciones` text,
  `usuario` varchar(50) DEFAULT NULL,
  `fondo_reservado_vuelto` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

**Campos clave:**
- `saldo_esperado_efectivo`: Calculado por el sistema (ingresos_efectivo - egresos)
- `saldo_real_efectivo`: Ingresado por el usuario (conteo físico)
- `diferencia`: Saldo real - Saldo esperado (positivo=sobrante, negativo=faltante)
- `fondo_reservado_vuelto`: Dinero que queda en caja para el día siguiente

**Relaciones:**
- Foreign Key a `empresas` (ON DELETE CASCADE)
- Foreign Key a `sucursales` (ON DELETE CASCADE)

---

## 4. ANÁLISIS DE PROCESOS

### 4.1 Registro de Movimientos

#### 4.1.1 Desde Ventas (`pages/ventas.php`)

```php
// Línea aproximada en ventas.php
$sql_mov = "INSERT INTO movimientos 
            (empresa_id, sucursal_id, tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado) 
            VALUES (?, ?, 'INGRESO', ?, ?, ?, NOW(), ?, 0)";
```

**Características:**
- Se registra automáticamente al finalizar una venta
- `cerrado = 0` (queda pendiente de cierre)
- `metodo_pago` puede ser: EFECTIVO, TRANSFERENCIA, o MIXTO
- Si es MIXTO, se genera un solo movimiento con el monto total

**⚠️ Problema identificado:**
- En ventas MIXTAS, no se separa el monto en efectivo y transferencia
- Esto puede causar inconsistencias en el cierre de caja

#### 4.1.2 Desde Movimientos Manuales (`pages/movimiento_manual.php`)

```php
$sql = "INSERT INTO movimientos 
        (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id)
        VALUES (?, ?, ?, ?, NOW(), ?, 0, ?, ?)";
```

**Características:**
- Permite ingresos o egresos manuales
- Usado para ajustes, gastos, etc.
- También queda pendiente de cierre

#### 4.1.3 Desde Anulaciones (`pages/anulaciones.php`)

```php
// Genera un EGRESO por el monto anulado
INSERT INTO movimientos (tipo, monto, metodo_pago, detalle, fecha, usuario, cerrado, empresa_id, sucursal_id)
VALUES ('EGRESO', ?, ?, ?, NOW(), ?, 0, ?, ?)
```

**Características:**
- Crea un movimiento de egreso para revertir la venta
- Asegura que la anulación se refleje en el cierre de caja

### 4.2 Dashboard de Caja (`pages/caja_dashboard.php`)

**Funcionalidad:**
- Muestra resumen del día actual
- Calcula totales por método de pago
- Muestra últimos 10 movimientos
- Calcula "Caja Física" = (Efectivo + Mixto) - Egresos

**Consultas SQL:**

```sql
-- Ingresos por método de pago (solo del día actual)
SELECT 
    SUM(CASE WHEN metodo_pago = 'EFECTIVO' THEN monto ELSE 0 END) as efectivo,
    SUM(CASE WHEN metodo_pago = 'TRANSFERENCIA' THEN monto ELSE 0 END) as transferencia,
    SUM(CASE WHEN metodo_pago = 'MIXTO' THEN monto ELSE 0 END) as mixto
FROM movimientos 
WHERE tipo = 'INGRESO' 
  AND DATE(fecha) = ? 
  AND empresa_id = ? 
  AND sucursal_id = ?

-- Egresos del día
SELECT SUM(monto) as total_egresos 
FROM movimientos 
WHERE tipo = 'EGRESO' 
  AND DATE(fecha) = ? 
  AND empresa_id = ? 
  AND sucursal_id = ?
```

**⚠️ Problema identificado:**
- Filtra por `DATE(fecha)` en lugar de `cerrado = 0`
- Esto incluye movimientos de días anteriores si tienen `fecha` de hoy
- No considera el flag `cerrado`

### 4.3 Cierre de Caja (`pages/cierre_caja.php` + `pages/procesar_cierre.php`)

#### 4.3.1 Pantalla de Cierre

**Características:**
- Muestra "Saldo Esperado" calculado por el sistema
- Permite ingresar conteo físico de billetes (denominaciones argentinas)
- Calcula diferencia en tiempo real (JavaScript)
- Muestra advertencia si hay diferencia
- Permite ingresar observaciones
- Permite definir "Fondo de Vuelto" para el día siguiente

**Denominaciones de billetes argentinos:**
```php
$denominaciones = [20000, 10000, 2000, 1000, 500, 200, 100, 50];
```

**Cálculo en JavaScript:**
```javascript
totalReal = Σ (valor_billete × cantidad)
diferencia = totalReal - saldoEsperado
```

#### 4.3.2 Procesamiento del Cierre (`pages/procesar_cierre.php`)

**Pasos:**

1. **Validación de permisos:**
   ```php
   require_permiso('pages/cierre_caja.php');
   ```

2. **Inicio de transacción:**
   ```php
   $pdo->beginTransaction();
   ```

3. **Cálculo de totales del sistema:**
   ```sql
   SELECT 
       SUM(CASE WHEN tipo = 'INGRESO' AND (metodo_pago = 'EFECTIVO' OR metodo_pago = 'MIXTO') 
                THEN monto ELSE 0 END) as ingresos_efectivo,
       SUM(CASE WHEN tipo = 'INGRESO' AND metodo_pago = 'TRANSFERENCIA' 
                THEN monto ELSE 0 END) as ingresos_transf,
       SUM(CASE WHEN tipo = 'EGRESO' THEN monto ELSE 0 END) as egresos
   FROM movimientos 
   WHERE cerrado = 0 
     AND empresa_id = :empresa_id 
     AND sucursal_id = :sucursal_id
   ```

4. **Validación de fondo de vuelto:**
   ```php
   if ($fondo_vuelto_manana > $saldo_real_efectivo) {
       throw new Exception("El fondo reservado no puede ser mayor al efectivo contado");
   }
   ```

5. **Registro en `cierres_caja`:**
   ```php
   INSERT INTO cierres_caja 
   (fecha_cierre, ingresos_efectivo, ingresos_transf, egresos, 
    saldo_esperado_efectivo, saldo_real_efectivo, diferencia, 
    fondo_reservado_vuelto, observaciones, usuario, empresa_id, sucursal_id)
   ```

6. **Marcado de movimientos como cerrados:**
   ```php
   UPDATE movimientos SET cerrado = 1 
   WHERE cerrado = 0 
     AND empresa_id = :empresa_id 
     AND sucursal_id = :sucursal_id
   ```

7. **Generación de fondo inicial para mañana (si aplica):**
   ```php
   if ($fondo_vuelto_manana > 0) {
       $mañana = date('Y-m-d 07:00:00', strtotime('+1 day'));
       INSERT INTO movimientos 
       (tipo, monto, metodo_pago, detalle, fecha, cerrado, empresa_id, sucursal_id)
       VALUES ('INGRESO', ?, 'EFECTIVO', 'FONDO INICIAL (VUELTO)', ?, 0, ?, ?)
   }
   ```

8. **Commit y redirección:**
   ```php
   $pdo->commit();
   $_SESSION['status_msj'] = $msj_tipo;
   header("Location: caja_dashboard.php");
   ```

**✅ Aspectos positivos:**
- Uso de transacciones para garantizar integridad
- Validación de fondo de vuelto
- Mensaje diferenciado según diferencia (0, positiva, negativa)
- Rollback en caso de error

---

## 5. FLUJO DE CAJA DIARIO (Paso a Paso)

### 5.1 Inicio del Día

1. **Estado inicial:**
   - Movimientos con `cerrado = 0` del día anterior (si hay)
   - Fondo de vuelto generado en el cierre anterior (si se definió)

2. **Primera venta:**
   - Se crea movimiento en `movimientos` con `cerrado = 0`
   - Se descuenta stock
   - Se genera ticket

### 5.2 Durante el Día

**Operaciones que generan movimientos:**

| Operación | Tipo | Método Pago | Cuándo se crea |
|-----------|------|-------------|-----------------|
| Venta contado | INGRESO | EFECTIVO/TRANSFERENCIA/MIXTO | Al finalizar venta |
| Venta cuenta corriente | INGRESO | - | No genera movimiento inmediato |
| Cobro de cuota | INGRESO | Variable | Al procesar pago |
| Compra | EGRESO | EFECTIVO | Al registrar compra |
| Gasto manual | EGRESO | Variable | Al crear movimiento |
| Anulación | EGRESO | Igual a venta original | Al anular |

**⚠️ Inconsistencia detectada:**
- Ventas en CUENTA CORRIENTE no generan movimiento de caja inmediato
- Solo generan movimiento cuando se cobra la cuota

### 5.3 Cierre del Día

**Pre-condiciones:**
- No hay validación de que el día anterior esté cerrado
- No hay validación de que no haya cierres duplicados en el mismo día

**Proceso:**
1. Usuario accede a `cierre_caja.php`
2. Sistema calcula totales de movimientos con `cerrado = 0`
3. Usuario ingresa conteo físico de billetes
4. Sistema calcula diferencia
5. Usuario confirma cierre
6. Se registra en `cierres_caja`
7. Se marcan todos los movimientos como `cerrado = 1`
8. Se genera fondo inicial para mañana (opcional)

**Post-cierre:**
- Movimientos del día quedan marcados como cerrados
- Nuevos movimientos del día siguiente se crean con `cerrado = 0`
- Fondo de vuelto aparece como primer ingreso del día siguiente

---

## 6. PROBLEMAS IDENTIFICADOS

### 6.1 🔴 Críticos

#### 6.1.1 Sin validación de cierre previo
**Ubicación:** `pages/cierre_caja.php`  
**Problema:** No se verifica si ya existe un cierre para el día actual  
**Impacto:** Se pueden generar múltiples cierres en el mismo día  
**Solución propuesta:**
```php
// Antes de mostrar el formulario
$sql_check = "SELECT id FROM cierres_caja 
              WHERE empresa_id = ? AND sucursal_id = ? 
                AND DATE(fecha_cierre) = CURDATE()";
// Si existe, mostrar mensaje de error
```

#### 6.1.2 Inconsistencia en filtrado de movimientos
**Ubicación:** `pages/caja_dashboard.php` vs `pages/procesar_cierre.php`  
**Problema:** 
- `caja_dashboard.php` filtra por `DATE(fecha) = ?`
- `procesar_cierre.php` filtra por `cerrado = 0`  
**Impacto:** Dashboard muestra movimientos cerrados, cierre no los incluye  
**Solución propuesta:** Usar `cerrado = 0` en ambos archivos

#### 6.1.3 Ventas MIXTAS sin desglose
**Ubicación:** `pages/ventas.php`  
**Problema:** Una venta mixta (efectivo + transferencia) se registra como un solo movimiento  
**Impacto:** No se puede saber cuánto corresponde a cada método de pago  
**Solución propuesta:** 
- Generar dos movimientos separados para ventas mixtas
- O agregar campos `monto_efectivo` y `monto_transferencia` a la tabla `movimientos`

### 6.2 🟡 Medios

#### 6.2.1 Campo `saldo_inicial` sin uso
**Ubicación:** Tabla `cierres_caja`  
**Problema:** El campo `saldo_inicial` existe pero nunca se completa  
**Impacto:** No se registra el fondo inicial real del día  
**Solución propuesta:** 
- Eliminar el campo si no se usa
- O implementar su carga en el formulario de cierre

#### 6.2.2 Sin historial de fondos de vuelto
**Ubicación:** `pages/procesar_cierre.php`  
**Problema:** El fondo de vuelto se registra como movimiento, pero no hay trazabilidad  
**Impacto:** No se puede auditar cuánto fondo se generó cada día  
**Solución propuesta:** Agregar un campo `es_fondo_inicial` a la tabla `movimientos`

#### 6.2.3 Sin reporte de cierres
**Ubicación:** Falta archivo  
**Problema:** No existe una página para ver historial de cierres  
**Impacto:** No se puede consultar cierres anteriores sin acceder a la BD  
**Solución propuesta:** Crear `pages/reporte_cierres.php`

#### 6.2.4 Multi-sucursal sin selector UI
**Ubicación:** Varias páginas  
**Problema:** El sistema soporta multi-sucursal en BD, pero no hay selector visible  
**Impacto:** Usuario no puede cambiar de sucursal fácilmente  
**Solución propuesta:** Agregar selector de sucursal en topbar o sidebar

### 6.3 🟢 Bajos

#### 6.3.1 Denominaciones de billetes hardcodeadas
**Ubicación:** `pages/cierre_caja.php` línea 70  
**Problema:** Las denominaciones están en el código  
**Impacto:** Si cambian billetes, hay que modificar código  
**Solución propuesta:** Mover a tabla de configuración

#### 6.3.2 Sin validación de billetes negativos
**Ubicación:** `pages/cierre_caja.php`  
**Problema:** Input `min="0"` pero no hay validación backend  
**Impacto:** Posible envío de valores negativos por manipulación  
**Solución propuesta:** Validar en `procesar_cierre.php`

---

## 7. MEJORAS RECOMENDADAS

### 7.1 Corto Plazo (1-2 semanas)

1. **Agregar validación de cierre previo**
   - Verificar si ya existe cierre para el día antes de mostrar formulario
   - Mostrar mensaje de error si ya se cerró

2. **Unificar filtrado de movimientos**
   - Usar `cerrado = 0` en `caja_dashboard.php`
   - Agregar índice compuesto en `movimientos(empresa_id, sucursal_id, cerrado)`

3. **Crear reporte de cierres**
   - Página `pages/reporte_cierres.php`
   - Filtros por fecha, sucursal, usuario
   - Exportación a PDF/Excel

4. **Agregar selector de sucursal en UI**
   - Agregar en topbar o sidebar
   - Usar el endpoint existente `ajax/cambiar_sucursal.php`

### 7.2 Mediano Plazo (1 mes)

1. **Implementar desglose de ventas mixtas**
   - Opción A: Dos movimientos separados
   - Opción B: Campos adicionales en tabla `movimientos`

2. **Agregar validación de billetes en backend**
   - Validar que todas las cantidades sean >= 0
   - Validar que el total coincida con el envío

3. **Implementar cierre de caja parcial**
   - Permitir cierres en horarios específicos (mañana/tarde)
   - Agregar campo `tipo_cierre` a tabla `cierres_caja`

4. **Agregar campo `es_fondo_inicial` a `movimientos`**
   - Mejorar trazabilidad de fondos de vuelto
   - Facilitar reportes

### 7.3 Largo Plazo (3 meses)

1. **Implementar arqueo de caja programado**
   - Alertas si no se cierra caja en horario
   - Bloqueo de nuevas operativas si hay cierre pendiente

2. **Agregar módulo de auditoría**
   - Log de cambios en cierres
   - Registro de quién modificó qué y cuándo

3. **Implementar cierre automático**
   - Cierre automático a hora configurable
   - Notificación por email al cierre

4. **Integración con sistema contable**
   - Exportación de asientos contables
   - Integración con sistemas externos

---

## 8. MÉTRICAS Y ESTADÍSTICAS

### 8.1 Código

| Métrica | Valor |
|---------|-------|
| Archivos de caja/cierres | 4 |
| Líneas de código (PHP) | ~350 |
| Líneas de código (JS) | ~40 |
| Tablas involucradas | 2 |
| Endpoints AJAX relacionados | 0 |

### 8.2 Funcionalidad

| Característica | Estado | Notas |
|----------------|--------|-------|
| Registro de ingresos | ✅ | Automático desde ventas, manual desde interfaz |
| Registro de egresos | ✅ | Manual y automático (anulaciones) |
| Cálculo de saldo esperado | ✅ | Suma ingresos - resta egresos |
| Conteo físico de billetes | ✅ | Con denominaciones argentinas |
| Cálculo de diferencia | ✅ | En tiempo real (JS) |
| Fondo de vuelto | ✅ | Se genera como movimiento del día siguiente |
| Historial de cierres | ✅ | Tabla `cierres_caja` |
| Marcado de movimientos | ✅ | Flag `cerrado` |
| Reportes de caja | ❌ | No existe página de reporte |
| Validación de cierre previo | ❌ | No implementado |
| Multi-sucursal UI | ⚠️ | Backend soporta, falta UI |

---

## 9. SEGURIDAD

### 9.1 Aspectos de Seguridad Implementados

✅ **Control de permisos:**
```php
require_permiso('pages/cierre_caja.php');
```

✅ **Transacciones:**
```php
$pdo->beginTransaction();
// ... operaciones ...
$pdo->commit();
// o
$pdo->rollBack();
```

✅ **Validación de datos:**
```php
$saldo_real_efectivo = (float)str_replace(',', '.', $_POST['saldo_real_efectivo'] ?? '0');
```

✅ **Prepared statements:**
```php
$pdo->prepare($sql)->execute([':empresa_id' => $empresa_id, ':sucursal_id' => $sucursal_id]);
```

### 9.2 Aspectos de Seguridad Faltantes

❌ **Sin validación de cierre duplicado**  
❌ **Sin log de auditoría** (quién cerró, cuándo, con qué valores)  
❌ **Sin validación de integridad** (hash o checksum de movimientos)  
❌ **Sin backup automático** antes del cierre  
❌ **Sin notificación** de cierres con diferencias significativas  

---

## 10. CONCLUSIONES

### 10.1 Estado General

El sistema de flujo de caja y cierres está **funcionalmente completo** para operaciones básicas, pero presenta **vulnerabilidades críticas** que deben ser addressed antes de su uso en producción.

**Calificación: 6.5/10**

### 10.2 Fortalezas

1. ✅ Lógica de cierre bien estructurada
2. ✅ Uso de transacciones para garantizar integridad
3. ✅ Cálculo automático de saldo esperado
4. ✅ Detección de diferencias (sobrante/faltante)
5. ✅ Fondo de vuelto automatizado
6. ✅ Control de permisos implementado
7. ✅ Multi-empresa/multi-sucursal en BD

### 10.3 Debilidades Críticas

1. ❌ Sin validación de cierre duplicado
2. ❌ Inconsistencia en filtrado de movimientos (dashboard vs. cierre)
3. ❌ Ventas mixtas sin desglose
4. ❌ Sin reporte de cierres históricos
5. ❌ Sin log de auditoría

### 10.4 Recomendaciones Prioritarias

**Antes de producción:**
1. Implementar validación de cierre previo
2. Unificar filtrado de movimientos
3. Agregar log de auditoría básico

**En las próximas semanas:**
4. Crear reporte de cierres
5. Implementar desglose de ventas mixtas
6. Agregar selector de sucursal en UI

**Futuro:**
7. Cierre automático programado
8. Integración contable
9. Alertas de diferencias significativas

---

## 11. ANEXOS

### 11.1 Archivos Relacionados

| Archivo | Propósito |
|---------|-----------|
| `pages/caja_dashboard.php` | Dashboard de caja del día |
| `pages/cierre_caja.php` | Formulario de cierre |
| `pages/procesar_cierre.php` | Lógica de procesamiento |
| `pages/movimiento_manual.php` | Registro manual de movimientos |
| `pages/reparar_caja_total.php` | Utilidad de corrección |
| `pages/ventas.php` | Módulo de ventas (genera movimientos) |
| `pages/anulaciones.php` | Anulaciones (genera egresos) |
| `pages/compras_rapidas.php` | Compras rápidas (genera egresos) |

### 11.2 Tablas Relacionadas

| Tabla | Relación |
|-------|----------|
| `movimientos` | Registro de transacciones |
| `cierres_caja` | Historial de cierres |
| `ventas` | Genera movimientos de ingreso |
| `compras` | Genera movimientos de egreso |
| `cuotas_pagos` | Genera movimientos de ingreso |
| `devoluciones` | Genera movimientos de egreso |

### 11.3 Endpoints AJAX Relacionados

| Endpoint | Propósito |
|----------|-----------|
| `ajax/registrar_pago_ctacte_ajax.php` | Registra pagos de cuenta corriente |
| `ajax/registrar_pago_proveedor_ajax.php` | Registra pagos a proveedores |
| `ajax/procesar_pago_cuota.php` | Procesa pago de cuotas |

---

*Documento generado el 08/03/2026 basado en análisis del código fuente del repositorio `pos_dev`.*