# Sistema de Gestión de Versiones

## Descripción

El sistema ahora permite modificar la versión de la aplicación dinámicamente desde la base de datos, sin necesidad de modificar código fuente.

## Archivos Modificados

### 1. `funciones/funciones_configuracion.php` (NUEVO)
Archivo con funciones auxiliares para gestionar configuraciones dinámicas:
- `obtener_configuracion($pdo, $clave, $valor_default)` - Obtiene un valor de configuración
- `guardar_configuracion($pdo, $clave, $valor)` - Guarda/actualiza una configuración
- `obtener_version_app($pdo)` - Obtiene la versión actual de la aplicación
- `guardar_version_app($pdo, $version)` - Guarda una nueva versión
- `obtener_todas_configuraciones($pdo)` - Obtiene todas las configuraciones

### 2. `pages/sidebar.php` (MODIFICADO)
- Ahora incluye `funciones_configuracion.php`
- Obtiene la versión desde la base de datos usando `obtener_version_app($pdo)`
- Muestra la versión en el sidebar: `V. X.Y.Z`

### 3. `pages/configuracion.php` (MODIFICADO)
- Agregada nueva pestaña "Sistema" 
- Incluye campo para modificar la versión de la aplicación
- Valida y guarda la versión en la tabla `configuracion`

### 4. `migrations/19_insert_app_version.sql` (NUEVO)
Migración SQL que inserta la versión por defecto '1.0.0' en la tabla configuracion.

## Uso

### Para ver la versión actual
La versión se muestra automáticamente en el sidebar del sistema.

### Para modificar la versión
1. Ir a **Configuración** → Tab **"Sistema"**
2. Modificar el campo "Versión Actual" (formato: X.Y o X.Y.Z)
3. Hacer clic en **"GUARDAR CAMBIOS"**
4. La nueva versión se reflejará automáticamente en el sidebar

### Para programadores

#### Obtener la versión en código:
```php
require_once PATH_BASE . 'funciones/funciones_configuracion.php';
$version = obtener_version_app($pdo);
```

#### Guardar una nueva versión:
```php
require_once PATH_BASE . 'funciones/funciones_configuracion.php';
$guardado = guardar_version_app($pdo, '2.1.0');
if ($guardado) {
    echo "Versión actualizada correctamente";
}
```

## Formato de Versión

El sistema acepta versiones en formato:
- `X.Y` (ej: 1.0, 2.1)
- `X.Y.Z` (ej: 1.0.0, 2.1.0)

Donde X, Y, Z son números enteros positivos.

## Base de Datos

La versión se almacena en la tabla `configuracion` con:
- `clave`: 'app_version'
- `valor`: '1.0.0' (o la versión correspondiente)

## Migración

Para aplicar la migración en una base de datos existente:

```bash
mysql -u root -p pos_dev < migrations/19_insert_app_version.sql
```

O ejecutar el SQL directamente:
```sql
INSERT INTO configuracion (clave, valor) 
VALUES ('app_version', '1.0.0')
ON DUPLICATE KEY UPDATE valor = '1.0.0';
```

## Notas Técnicas

- Si no existe una versión en la base de datos, se usa el valor por defecto '1.0.0'
- El sistema es multi-empresa, pero la versión es global (no por empresa)
- La constante `APP_VERSION` ya no es necesaria, pero se mantiene como fallback
- Todos los cambios se guardan inmediatamente en la base de datos