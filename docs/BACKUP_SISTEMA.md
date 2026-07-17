# Sistema de Backup - Documentación

## 📋 Descripción General

Sistema de backup automático de base de datos para el POS. Permite realizar backups programados (diarios, semanales o mensuales) y backups manuales bajo demanda.

## 🎯 Características

- ✅ Backup automático programado (diario/semanal/mensual)
- ✅ Backup manual bajo demanda
- ✅ Configuración de ruta de almacenamiento personalizada
- ✅ Rotación automática de backups (mantiene solo los N más recientes)
- ✅ Interfaz web para gestión completa
- ✅ Descarga de backups
- ✅ Integrado con sistema de permisos
- ✅ Registro de actividad en logs

## 🔧 Requisitos

- PHP 7.4 o superior
- MySQL/MariaDB
- Permisos de escritura en el directorio de backups
- (Opcional) Acceso a cron/tareas programadas para backup automático

## 📦 Archivos Creados

```
migrations/20_backup_system.sql      # Migración de base de datos
procesos/backup_database.php         # Script de backup automático
pages/backup.php                     # Interfaz de gestión de backups
pages/sidebar.php                    # Actualizado con link a Backup
docs/BACKUP_SISTEMA.md               # Esta documentación
```

## 🚀 Instalación

### 1. Ejecutar Migración

Ejecutar el archivo de migración en la base de datos:

```bash
mysql -u root -p pos_dev < migrations/20_backup_system.sql
```

O desde phpMyAdmin, importar el archivo `migrations/20_backup_system.sql`.

### 2. Configurar Permisos

El módulo de Backup debe ser asignado a los usuarios que tendrán acceso. 

**Opción A - Por Rol (recomendado):**
```sql
-- Asignar permiso de backup a rol admin
INSERT INTO permisos_rol (empresa_id, rol, modulo_id)
SELECT 1, 'admin', id FROM modulos WHERE archivo = 'pages/backup.php';
```

**Opción B - Por Usuario:**
```sql
-- Asignar permiso a usuario específico
INSERT INTO permisos_usuario (empresa_id, usuario_id, modulo_id)
SELECT 1, 5, id FROM modulos WHERE archivo = 'pages/backup.php';
-- Reemplazar el 5 por el ID del usuario
```

### 3. Configurar Backup Automático (Opcional)

Para que el backup automático funcione, configurar una tarea programada (cron):

**En Linux/Unix:**
```bash
# Editar crontab
crontab -e

# Agregar línea para ejecutar backup diario a las 2:00 AM
0 2 * * * php /var/www/html/pos_dev/procesos/backup_database.php

# O para ejecutar backup semanal (domingos a las 3:00 AM)
0 3 * * 0 php /var/www/html/pos_dev/procesos/backup_database.php
```

**En Windows (Task Scheduler):**
1. Crear tarea programada
2. Acción: Iniciar programa
3. Programa: `php.exe`
4. Argumentos: `C:\laragon\www\pos_dev\procesos\backup_database.php`

## 📖 Uso del Sistema

### Acceder al Módulo

1. Iniciar sesión en el sistema
2. En el menú lateral, buscar "Backup" (sección Administración)
3. Hacer clic en el icono de base de datos

### Configuración de Backup

En la página de Backup, sección "Configuración":

1. **Habilitar backup automático**: Marcar checkbox para activar/desactivar
2. **Frecuencia**: Seleccionar diario/semanal/mensual
3. **Ruta de almacenamiento**: 
   - Dejar vacío para usar carpeta predeterminada: `backups/`
   - O especificar ruta completa: `C:\backups\` o `/var/backups/pos/`
4. **Cantidad de backups a mantener**: Número de archivos a conservar (1-50)
5. Hacer clic en "Guardar Configuración"

### Ejecutar Backup Manual

1. En la sección "Backup Manual"
2. Hacer clic en "Ejecutar Backup Ahora"
3. Confirmar la acción
4. El backup se generará y guardará automáticamente

### Descargar Backups

1. En la tabla "Backups Disponibles"
2. Hacer clic en el botón "Descargar" del backup deseado
3. El archivo .sql se descargará automáticamente

### Eliminar Backups

1. En la tabla "Backups Disponibles"
2. Hacer clic en el botón "Eliminar" del backup a borrar
3. Confirmar la eliminación

## 🔍 Información Mostrada

### Estadísticas

- **Último Backup**: Fecha del backup más reciente
- **Backups Totales**: Cantidad de archivos de backup existentes
- **A Mantener**: Número configurado de backups a conservar
- **Frecuencia**: Periodicidad del backup automático

### Lista de Backups

Muestra todos los backups disponibles con:
- Nombre del archivo
- Fecha y hora de creación
- Tamaño del archivo
- Acciones (descargar/eliminar)

## ⚙️ Configuración Avanzada

### Variables de Configuración

Las configuraciones se guardan en la tabla `configuracion`:

| Clave | Descripción | Valores Posibles | Default |
|-------|-------------|------------------|---------|
| `backup_habilitado` | Habilita backup automático | 0/1 | 0 |
| `backup_frecuencia` | Frecuencia de backup | diario/semanal/mensual | diario |
| `backup_ruta` | Ruta de almacenamiento | Ruta completa o vacía | backups/ |
| `backup_cantidad` | Cantidad a mantener | 1-50 | 7 |

### Formato de Archivos de Backup

Los archivos siguen el formato estándar de MySQL:
- Extensión: `.sql`
- Nombre: `backup_{nombre_bd}_{fecha}.sql`
- Ejemplo: `backup_pos_dev_2026-07-14_18-30-00.sql`

### Contenido del Backup

Cada backup incluye:
- Estructura de todas las tablas
- Datos de todas las tablas
- Configuraciones de caracteres y collations
- Sentencias SQL compatibles con MySQL

## 🔒 Seguridad

### Permisos

- Solo usuarios con permiso `pages/backup.php` pueden acceder
- El rol 'developer' tiene acceso automático
- Se recomienda asignar solo a administradores de confianza

### Protección del Script

El script `backup_database.php` tiene protección:
- Solo ejecutable desde CLI o con clave de seguridad
- No accesible directamente desde navegador sin parámetro `key`

## 🛠️ Solución de Problemas

### Error: "No se pudo crear el directorio de backups"

**Solución:**
```bash
# Crear directorio manualmente
mkdir /ruta/de/backups
chmod 755 /ruta/de/backups
chown www-data:www-data /ruta/de/backups  # En Linux
```

### Error: "El directorio de backups no tiene permisos de escritura"

**Solución:**
```bash
# Linux/Unix
chmod 755 /ruta/de/backups
chown www-data:www-data /ruta/de/backups

# Windows
# Verificar que el usuario IIS/USUARIO_SISTEMA tenga permisos de escritura
```

### Backup automático no se ejecuta

**Verificar:**
1. Que el backup esté habilitado en configuración
2. Que la tarea cron esté activa: `crontab -l`
3. Revisar logs de PHP y del sistema
4. Verificar ruta de PHP: `which php`

### No aparecen backups en la lista

**Verificar:**
1. Que la ruta configurada sea correcta
2. Que existan archivos con el formato correcto
3. Permisos de lectura en el directorio

## 📊 Restauración de Backup

Para restaurar un backup:

```bash
# Opción 1: Desde línea de comandos
mysql -u root -p pos_dev < backup_pos_dev_2026-07-14_18-30-00.sql

# Opción 2: Desde phpMyAdmin
# 1. Ir a phpMyAdmin
# 2. Seleccionar base de datos
# 3. Ir a pestaña "Importar"
# 4. Seleccionar archivo .sql
# 5. Ejecutar importación
```

## 📝 Notas Importantes

1. **Espacio en disco**: Monitorear el espacio disponible, especialmente con backups frecuentes
2. **Ruta de backups**: Se recomienda usar ruta fuera del directorio web por seguridad
3. **Pruebas**: Probar restauración de backups periódicamente
4. **Cantidad de backups**: Ajustar según espacio disponible y necesidades
5. **Seguridad**: Los backups contienen datos sensibles, proteger el directorio

## 🔄 Actualizaciones Futuras

Posibles mejoras a implementar:
- [ ] Compresión de backups (gzip)
- [ ] Backup a servicios cloud (Dropbox, Google Drive, etc.)
- [ ] Notificaciones por email al completar backup
- [ ] Backup incremental
- [ ] Encriptación de backups
- [ ] Historial de backups con más detalles
- [ ] Programación de backups por horario específico

## 📞 Soporte

Para reportar problemas o solicitar mejoras, contactar al administrador del sistema.

---

**Versión del Sistema**: 1.0.0  
**Última Actualización**: Julio 2026