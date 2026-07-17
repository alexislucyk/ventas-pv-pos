<?php
// Explorador de archivos para seleccionar ruta de backup
// Suprimir warnings para no interferir con el JSON
error_reporting(E_ERROR);
ini_set('display_errors', 0);

require_once dirname(__FILE__) . '/../config/db_config.php';

// Verificar que el usuario esté autenticado
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'No autorizado']));
}

header('Content-Type: application/json');

$directorio_raiz = isset($_GET['dir']) ? $_GET['dir'] : 'C:\\';
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';

// Decodificar URL y sanitizar ruta
$directorio_raiz = urldecode($directorio_raiz);
$directorio_raiz = str_replace(['..', '/'], '', $directorio_raiz);
$directorio_raiz = rtrim($directorio_raiz, '\\');

// Si está vacío o es muy corto, usar C:\
if (strlen($directorio_raiz) < 2) {
    $directorio_raiz = 'C:\\';
}

$directorio_raiz = $directorio_raiz . '\\';

try {
    switch ($accion) {
        case 'listar':
            listar_directorio($directorio_raiz);
            break;
            
        case 'subir':
            $nuevo_directorio = dirname($directorio_raiz);
            listar_directorio($nuevo_directorio);
            break;
            
        default:
            listar_directorio($directorio_raiz);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function listar_directorio($ruta) {
    global $directorio_raiz;
    
    // Verificar que el directorio existe
    if (!is_dir($ruta)) {
        echo json_encode([
            'error' => 'Directorio no encontrado o no accesible',
            'directorio_actual' => $directorio_raiz,
            'elementos' => []
        ]);
        return;
    }
    
    // Verificar si se solicita crear un nuevo directorio
    if (isset($_GET['crear_directorio']) && isset($_GET['nombre'])) {
        $nombre_directorio = trim($_GET['nombre']);
        $ruta_nuevo = $ruta . $nombre_directorio;
        
        if (empty($nombre_directorio)) {
            echo json_encode(['error' => 'El nombre del directorio no puede estar vacío']);
            return;
        }
        
        if (file_exists($ruta_nuevo)) {
            echo json_encode(['error' => 'Ya existe un archivo o directorio con ese nombre']);
            return;
        }
        
        if (mkdir($ruta_nuevo, 0755)) {
            echo json_encode([
                'success' => true,
                'mensaje' => 'Directorio creado exitosamente',
                'directorio_actual' => $ruta
            ]);
        } else {
            echo json_encode(['error' => 'No se pudo crear el directorio. Verifique los permisos.']);
        }
        return;
    }
    
    // Verificar que se puede leer el directorio
    if (!is_readable($ruta)) {
        echo json_encode([
            'error' => 'Sin permisos para leer este directorio',
            'directorio_actual' => $directorio_raiz,
            'elementos' => []
        ]);
        return;
    }
    
    $elementos = [];
    
    // Obtener información del directorio actual
    $directorio_actual = $ruta;
    
    // Leer contenido del directorio
    $archivos = @scandir($ruta);
    
    if ($archivos === false) {
        echo json_encode([
            'error' => 'No se pudo leer el contenido del directorio. Verifique los permisos.',
            'directorio_actual' => $directorio_actual,
            'elementos' => []
        ]);
        return;
    }
    
    // Separar directorios y archivos
    $directorios = [];
    $archivos_list = [];
    
    foreach ($archivos as $archivo) {
        if ($archivo === '.' || $archivo === '..') {
            continue;
        }
        
        $ruta_completa = $ruta . $archivo;
        $es_directorio = is_dir($ruta_completa);
        
        // Obtener información del elemento (con manejo de errores para archivos protegidos)
        try {
            $permisos = substr(sprintf('%o', @fileperms($ruta_completa)), -4);
            $tamano = $es_directorio ? null : @filesize($ruta_completa);
            $fecha = @filemtime($ruta_completa) ? date('Y-m-d H:i:s', @filemtime($ruta_completa)) : 'N/A';
        } catch (Exception $e) {
            $permisos = '0000';
            $tamano = false;
            $fecha = 'N/A';
        }
        
        $elemento = [
            'nombre' => $archivo,
            'ruta' => $ruta_completa,
            'es_directorio' => $es_directorio,
            'permisos' => $permisos,
            'tamano' => $tamano,
            'fecha_modificacion' => $fecha
        ];
        
        if ($es_directorio) {
            $directorios[] = $elemento;
        } else {
            $archivos_list[] = $elemento;
        }
    }
    
    // Ordenar alfabéticamente
    usort($directorios, function($a, $b) {
        return strcasecmp($a['nombre'], $b['nombre']);
    });
    
    usort($archivos_list, function($a, $b) {
        return strcasecmp($a['nombre'], $b['nombre']);
    });
    
    // Combinar (directorios primero)
    $elementos = array_merge($directorios, $archivos_list);
    
    // Determinar si se puede subir
    $puede_subir = strlen($directorio_actual) > 3; // Más de "C:\"
    
    echo json_encode([
        'error' => null,
        'directorio_actual' => $directorio_actual,
        'puede_subir' => $puede_subir,
        'directorio_padre' => $puede_subir ? dirname($directorio_actual) . '\\' : null,
        'cantidad_directorios' => count($directorios),
        'cantidad_archivos' => count($archivos_list),
        'elementos' => $elementos
    ]);
}

function formatear_tamano($bytes) {
    if ($bytes === null) return '-';
    if ($bytes == 0) return '0 B';
    
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>