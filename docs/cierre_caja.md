# Sistema de Cierre de Caja - Documentación Completa

## Descripción General

Sistema de cierre de caja mejorado que permite:
- Cierres con número secuencial único
- Cierres de cualquier período (horas, días, múltiples días)
- Informe completo con desglose por método de pago (efectivo, transferencias, cheques, tarjetas, otros)
- Consulta de cierres históricos con filtros avanzados
- Validación de superposición de períodos
- Sistema de auditoría completo

## Estructura de Base de Datos

### Tabla `cierres_caja`

Campos principales:
- `id` - Identificador único
- `empresa_id` - ID de la empresa
- `sucursal_id` - ID de la sucursal
- `fecha_cierre` - Fecha y hora del cierre
- `fecha_desde` - Fecha/hora de inicio del período cerrado
- `fecha_hasta` - Fecha/hora de fin del período cerrado
- `numero_cierre` - Número secuencial de cierre (1, 2, 3...)
- `saldo_inicial` - Saldo inicial de caja
- `ingresos_efectivo` - Total de ingresos en efectivo
- `ingresos_transf` - Total de ingresos por transferencia
- `ingresos_cheques` - Total de ingresos por cheques
- `ingresos_tarjetas` - Total de ingresos por tarjetas
- `ingresos_otros` - Total de otros métodos de pago
- `egresos` - Total de egresos/gastos
- `saldo_esperado_efectivo` - Saldo que debería haber (sistema)
- `saldo_real_efectivo` - Saldo contado físicamente
- `diferencia` - Diferencia entre esperado y real
- `fondo_reservado_vuelto` - Fondo para el día siguiente
- `observaciones` - Notas del cierre
- `usuario` - Usuario que realizó el cierre

### Tabla `cierres_caja_audit`

Registro de auditoría de todos los cambios en cierres.

### Tabla `estado_caja`

Control del estado actual de caja (ABIERTA/CERRADA).

## Archivos del Sistema

### Funciones Principales

**`funciones/funciones_caja.php`**
- `obtener_estado_caja()` - Obtiene estado actual de caja
- `caja_esta_abierta()` - Verifica si caja está abierta
- `abrir_caja()` - Abre caja con saldo inicial
- `cerrar_caja()` - Cierra caja con cálculo de todos los métodos de pago
- `obtener_resumen_caja()` - Obtiene resumen del día
- `obtener_numero_cierre()` - Obtiene siguiente número de cierre

### Páginas

**`pages/cierre_caja.php`**
- Interfaz de cierre de caja
- Selector de período (fecha/hora desde/hasta)
- Resumen completo por método de pago
- Conteo de billetes
- Cálculo de diferencias en tiempo real

**`pages/procesar_cierre.php`**
- Procesa el cierre de caja
- Valida datos del formulario
- Actualiza el cierre con saldo real
- Genera fondo de vuelto si aplica
- Registra en auditoría

**`pages/reporte_cierres.php`**
- Reporte histórico de cierres
- Filtros por fecha, sucursal y usuario
- Estadísticas agregadas
- Exportación a CSV

**`pages/abrir_caja.php`**
- Interfaz para abrir caja
- Definición de saldo inicial
- Observaciones de la apertura (se guardan en `estado_caja.observaciones`, migración 46)

**`pages/caja_dashboard.php`**
- Dashboard de caja en tiempo real
- Resumen del día actual

**`pages/reparar_cierres_fondo_inicial.php`** (solo rol `developer`)
- Herramienta de mantenimiento: detecta y repara cierres guardados con el
  fondo inicial contado dos veces (ver "Mantenimiento" más abajo)
- GET: solo lectura (detección). POST confirmado + CSRF: aplica la reparación

## Migraciones

### Migración 26: Soporte para cierres de múltiples días
**Archivo:** `migrations/26_agregar_rango_fechas_cierre.sql`

Agrega campos `fecha_desde` y `fecha_hasta` a `cierres_caja` para permitir cierres de cualquier período.

### Migración 27: Métodos de pago adicionales
**Archivo:** `migrations/27_agregar_metodos_pago_cierre.sql`

Agrega campos para desglose de métodos de pago:
- `ingresos_cheques`
- `ingresos_tarjetas`
- `ingresos_otros`

### Migración 45: Limpieza de tablas obsoletas
**Archivo:** `migrations/45_limpiar_tablas_obsoletas.sql`
- Elimina tablas en desuso

### Migración 46: Observaciones en estado_caja
**Archivo:** `migrations/46_estado_caja_observaciones.sql`
- Agrega la columna `observaciones` a `estado_caja`
- Permite registrar notas al abrir caja

### Migración 47: Montos con decimales
**Archivo:** `migrations/47_monto_movimientos_decimal.sql` (ejecutor: `procesos/ejecutar_migracion_47.php`)
- Convierte `movimientos.monto`, `monto_efectivo` y `monto_transferencia` a `DECIMAL(10,2)`
- Permite registrar centavos en ventas, egresos y fondos (evita redondeos que producían diferencias de caja)

## Flujo de Trabajo

### 1. Apertura de Caja

1. Usuario accede a `abrir_caja.php`
2. Ingresa saldo inicial
3. Sistema crea registro en `estado_caja` con estado `ABIERTA`
4. Si hay saldo inicial, se crea movimiento de fondo inicial
   (`detalle = 'FONDO INICIAL (APERTURA)'`, `es_fondo_inicial = 1`).
   **Solo informativo**: ese movimiento se muestra en la lista de movimientos
   pero **no** se suma a los totales, porque el saldo inicial ya se agrega
   desde `estado_caja.saldo_inicial` (de lo contrario el fondo reservado del
   cierre anterior se contaría dos veces).

### 2. Operaciones del Día

- Ventas, gastos y otros movimientos se registran en `movimientos`
- Cada movimiento tiene: tipo, monto, método_pago, fecha, usuario
- Los movimientos se marcan con `cerrado = 0` (pendientes de cierre)

### 3. Cierre de Caja (100% Automático)

1. Usuario accede a `cierre_caja.php`
2. **Sistema calcula automáticamente el período**: desde la apertura de caja hasta el momento actual
3. Sistema muestra:
   - Período automático (fecha/hora de apertura → fecha/hora actual)
   - Duración del período
   - Resumen por método de pago del período
   - Saldo esperado (sistema)
   - Información de apertura
4. Usuario realiza conteo físico de billetes
5. Sistema calcula diferencia en tiempo real
6. Usuario ingresa:
   - Cantidad de billetes por denominación
   - Fondo para mañana (vuelto)
   - Observaciones (opcional)
7. Al confirmar:
   - Se crea registro en `cierres_caja` con todos los totales
   - Se marca número de cierre secuencial
   - Se actualizan movimientos a `cerrado = 1` (toda la sesión)
   - Se cambia estado de caja a `CERRADA`
   - El fondo de vuelto queda registrado como `fondo_reservado_vuelto` y se sugerirá como saldo inicial en la próxima apertura
   - Se registra en auditoría

### 4. Consulta de Cierres Históricos

1. Usuario accede a `reporte_cierres.php`
2. Puede filtrar por:
   - Rango de fechas
   - Sucursal
   - Usuario
3. Visualiza:
   - Estadísticas agregadas
   - Tabla de cierres con todos los detalles
   - Exportación a CSV

## Cálculos

### Saldo Esperado
```
saldo_esperado = saldo_inicial + ingresos_efectivo - egresos
```

**Nota:** Solo se considera el efectivo para el saldo esperado, ya que es lo que debe haber físicamente en caja.
**Importante:** `ingresos_efectivo` **no** incluye el movimiento de fondo inicial
(`es_fondo_inicial = 1`), porque ese monto ya está en `saldo_inicial`. Todas las
consultas de totales deben aplicar el filtro `COALESCE(es_fondo_inicial, 0) = 0`
(constante `SQL_FILTRO_SIN_FONDO_INICIAL` en `funciones/funciones_caja.php`).

### Diferencia
```
diferencia = saldo_real_efectivo - saldo_esperado
```

- `diferencia = 0` → Caja OK
- `diferencia > 0` → Sobrante
- `diferencia < 0` → Faltante

### Total Ingresos
```
total_ingresos = efectivo + transferencias + cheques + tarjetas + otros
```

## Validaciones

### Al Cerrar Caja
- ✅ Caja debe estar abierta
- ✅ Saldo real no puede ser negativo
- ✅ Fondo de vuelto no puede ser negativo
- ✅ Fondo de vuelto no puede ser mayor al efectivo contado
- ✅ Validación de cantidades de billetes (si se ingresan)

### Al Abrir Caja
- ✅ No se puede abrir si ya está abierta (a menos que se cierre primero)

## Características Especiales

### Cierre Automático sin Selección de Fechas
- **No requiere selección manual de fechas**
- El sistema calcula automáticamente el período: desde la apertura de caja hasta el momento actual
- Muestra la duración del período en horas y minutos
- El usuario solo debe confirmar el cierre

### Apertura por Sesión (Modelo Actual)
- Una caja permanece **ABIERTA hasta que el usuario la cierra** (puede abarcar varios días)
- **No se cierra automáticamente** al cambiar el día
- **No** hay apertura automática del día siguiente: la próxima caja se apertura manualmente
- El `fondo_reservado_vuelto` del último cierre se sugiere como saldo inicial en la nueva apertura
- Se permite **cerrar y abrir varias veces dentro del mismo día** (varios cierres por día)

### Cierres de Múltiples Días
- Permite cerrar períodos de cualquier duración
- Ejemplo: Cerrar del 01/08 al 05/08 (5 días completos)
- Los movimientos se cierran solo dentro del rango especificado

### Múltiples Cierres por Día
- Se permite cerrar la caja múltiples veces por día
- Cada cierre tiene un número secuencial único
- Los movimientos se marcan como cerrados para no repetirse

### Fondo de Vuelto
- Al cerrar caja, se puede dejar dinero para el día siguiente
- Ese monto se guarda en `cierres_caja.fondo_reservado_vuelto` y se **sugiere**
  como saldo inicial en la próxima apertura (`pages/abrir_caja.php`)
- Al abrir la caja se registra como `estado_caja.saldo_inicial` y, además, como
  movimiento informativo `FONDO INICIAL (APERTURA)` con `es_fondo_inicial = 1`
- Ese movimiento **no se suma** a los totales (se excluye con
  `SQL_FILTRO_SIN_FONDO_INICIAL`); al cerrar la sesión queda con `cerrado = 1`
  para no arrastrarse al cierre siguiente
- ⚠️ Si se sumara el movimiento de fondo inicial a los ingresos, el fondo
  reservado se contaría **dos veces** (una como saldo inicial y otra como
  ingreso), mostrando un efectivo en caja duplicado

### Sistema de Auditoría
- Todos los cierres se registran en `cierres_caja_audit`
- Se guarda: usuario, fecha/hora, datos completos del cierre
- Permite trazabilidad completa de operaciones

## Métodos de Pago Soportados

- **EFECTIVO** - Dinero en efectivo
- **TRANSFERENCIA** - Transferencias bancarias
- **CHEQUE** - Pagos con cheques
- **TARJETA** - Pagos con tarjetas (crédito/débito)
- **MIXTO** - Combinación de métodos
- **OTROS** - Cualquier otro método no listado

## Reportes

### Reporte de Cierres
- **Filtros:** Fecha desde/hasta, sucursal, usuario
- **Estadísticas:** Total cierres, ingresos por método, egresos, sobrantes, faltantes
- **Tabla:** Detalle completo de cada cierre
- **Exportación:** CSV con todos los datos

### Informe de Cierre Individual
Al cerrar caja se genera un informe con:
- Número de cierre
- Período cerrado (fecha/hora desde/hasta)
- Saldo inicial
- Desglose por método de pago:
  - Efectivo
  - Transferencias
  - Cheques
  - Tarjetas
  - Otros
- Total egresos
- Saldo esperado vs saldo real
- Diferencia (sobrante/faltante)
- Fondo de vuelto
- Usuario que cerró
- Observaciones

## Notas Técnicas

### Transacciones
- Todos los cierres usan transacciones de base de datos
- Si algo falla, se revierte todo el proceso
- Garantiza consistencia de datos

### Índices de Base de Datos
- `idx_fecha_rango` en `cierres_caja` para búsquedas por período
- Índices en `empresa_id` y `sucursal_id` para filtrado

### Compatibilidad
- ✅ Cierres antiguos funcionan sin cambios
- ✅ Reportes históricos mantienen compatibilidad
- ✅ Sistema de numeración es continuo

## Próximas Mejoras (Opcional)

- [ ] Validación de superposición de períodos
- [ ] Bloqueo de cierres que incluyan períodos ya cerrados
- [ ] Confirmación adicional para cierres de más de 7 días
- [ ] Calendario visual para selección de fechas
- [ ] Desglose de billetes en reporte de cierre
- [ ] Impresión de informe de cierre en PDF

## Pruebas automatizadas

Scripts de verificación del ciclo de cierre. Corren contra la BD real pero **no
dejan datos**: trabajan dentro de una transacción que siempre se revierte.

- **`procesos/_test_ciclo_cierre_fondo.php`** — Simula el ciclo completo:
  1. Apertura caja 1 (fondo 0) con ventas en efectivo/transferencia/mixta (con centavos) y egresos
  2. Cierre 1: esperado/real y diferencia = 0; `fondo_reservado_vuelto` guardado
  3. Sugerencia del fondo para la próxima apertura
  4. Apertura caja 2 con ese fondo: el fondo **no** se duplica ni genera diferencia; su movimiento `es_fondo_inicial` se cierra con la sesión
- **`procesos/_test_sql_consultas.php`** — Valida que las consultas de `caja_dashboard.php`, `cierre_caja.php`, la sugerencia de fondo de `abrir_caja.php` y `obtener_resumen_caja()` ejecuten sin errores

```bash
php procesos/_test_ciclo_cierre_fondo.php
php procesos/_test_sql_consultas.php
```

## Soporte

Para reportar problemas o consultas, contactar al equipo de desarrollo.