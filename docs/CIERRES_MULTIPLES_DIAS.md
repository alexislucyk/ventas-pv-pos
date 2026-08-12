# Sistema de Cierre de Caja con Rango de Fechas

## Descripción
Sistema de cierre de caja unificado que permite cerrar cualquier período de tiempo (desde horas hasta múltiples días) mediante la especificación de fecha/hora de inicio y fecha/hora de fin.

## Cambios Realizados

### 1. Base de Datos
**Archivo:** `migrations/26_agregar_rango_fechas_cierre.sql`

Se agregaron dos nuevas columnas a la tabla `cierres_caja`:
- `fecha_desde` (DATETIME): Fecha y hora de inicio del período cerrado
- `fecha_hasta` (DATETIME): Fecha y hora de fin del período cerrado

**IMPORTANTE:** Ejecutar la migración antes de usar la funcionalidad:
```sql
-- Conectarse a MySQL y ejecutar:
mysql -u usuario -p pos_dev < migrations/26_agregar_rango_fechas_cierre.sql
```

O desde phpMyAdmin:
1. Abrir phpMyAdmin
2. Seleccionar base de datos `pos_dev`
3. Ir a la pestaña "SQL"
4. Copiar y pegar el contenido del archivo de migración
5. Ejecutar

### 2. Archivos Modificados

#### `funciones/funciones_caja.php`
- Función `cerrar_caja()` actualizada:
  - Ahora acepta parámetros `$fecha_desde` y `$fecha_hasta` (opcionales)
  - Si no se especifican fechas, cierra el día actual completo (00:00:00 a 23:59:59)
  - Si se especifica un rango, cierra solo los movimientos dentro de ese rango
  - Los movimientos se cierran usando comparación directa de DATETIME
  - Se mantiene el número de cierre secuencial

#### `pages/cierre_caja.php`
- Interfaz actualizada con selector de fecha/hora desde/hasta
- Por defecto muestra cierre del día actual (00:00:00 a 23:59:59)
- Usuario puede seleccionar cualquier rango de fechas y horas personalizado
- Muestra información del período seleccionado con cantidad de días
- Campos ocultos en el formulario para enviar el rango de fechas

#### `pages/procesar_cierre.php`
- Procesa el rango de fechas desde el formulario
- Pasa las fechas a la función `cerrar_caja()`
- Recalcula totales considerando el rango de fechas
- Actualiza el cierre con los datos correctos del período

#### `pages/reporte_cierres.php`
- Muestra columna "Período" con rango de fechas completo (fecha/hora desde/hasta)
- Consulta SQL actualizada para filtrar por `fecha_desde` y `fecha_hasta`
- Exportación CSV incluye la nueva columna de período

## Uso del Sistema

### Cierre de Cualquier Período
1. Ir a "Cierre de Caja"
2. En el selector de período:
   - Seleccionar "Fecha/Hora Desde" (ej: 01/08/2026 00:00:00)
   - Seleccionar "Fecha/Hora Hasta" (ej: 05/08/2026 23:59:59)
   - Hacer clic en "Consultar"
3. El sistema mostrará:
   - Período seleccionado con fecha/hora exactas
   - Cantidad de días del período
   - Resumen de movimientos del período completo
4. Realizar conteo físico de billetes del período completo
5. Confirmar cierre

### Ejemplos de Uso

#### Cierre de un día completo
- Desde: 08/08/2026 00:00:00
- Hasta: 08/08/2026 23:59:59
- Resultado: Cierra todos los movimientos del día

#### Cierre de medio día
- Desde: 08/08/2026 14:00:00
- Hasta: 08/08/2026 23:59:59
- Resultado: Cierra solo movimientos desde las 2 PM hasta fin del día

#### Cierre de múltiples días
- Desde: 01/08/2026 00:00:00
- Hasta: 05/08/2026 23:59:59
- Resultado: Cierra todos los movimientos de los 5 días completos

## Notas Importantes

### Validaciones
- La caja debe estar abierta para realizar cualquier cierre
- Para cierres que incluyan el día actual, se mantiene la advertencia si el día anterior no está cerrado
- El fondo de vuelto solo se genera para el día siguiente al cierre

### Numeración de Cierres
- Cada cierre tiene un número secuencial único
- La numeración es continua: 1, 2, 3, 4... sin importar el período
- Se puede ver el número en el reporte de cierres

### Movimientos
- Los movimientos se marcan como `cerrado = 1` solo dentro del rango especificado
- Movimientos fuera del rango permanecen abiertos para cierres posteriores
- No se puede cerrar el mismo movimiento dos veces
- La comparación se hace por fecha/hora exacta (DATETIME)

### Reportes
- El reporte de cierres muestra el período completo con fecha y hora
- Filtros funcionan correctamente con cualquier tipo de cierre
- Exportación CSV incluye toda la información del período

## Compatibilidad
- ✅ Cierres existentes funcionan sin cambios
- ✅ Reportes históricos mantienen su funcionamiento
- ✅ Numeración de cierres es consistente
- ✅ Fondo de vuelto funciona igual que antes
- ✅ Sistema de auditoría registra el rango de fechas

## Próximos Pasos (Opcional)
- [ ] Agregar validación de superposición de períodos
- [ ] Bloquear cierres que incluyan períodos ya cerrados
- [ ] Agregar confirmación adicional para cierres de más de 7 días
- [ ] Mostrar calendario visual para selección de fechas

## Soporte
Para reportar problemas o consultas, contactar al equipo de desarrollo.