<?php
// funciones_configuracion.php - Funciones para gestionar configuraciones dinámicas

/**
 * Obtiene el valor de una configuración desde la base de datos
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $clave Clave de la configuración
 * @param string $valor_default Valor por defecto si no existe
 * @return string Valor de la configuración
 */
function obtener_configuracion($pdo, $clave, $valor_default = '') {
    try {
        $sql = "SELECT valor FROM configuracion WHERE clave = :clave LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':clave' => $clave]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $resultado ? $resultado['valor'] : $valor_default;
    } catch (Exception $e) {
        return $valor_default;
    }
}

/**
 * Guarda o actualiza una configuración en la base de datos
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $clave Clave de la configuración
 * @param string $valor Valor a guardar
 * @return bool true si se guardó correctamente, false en caso contrario
 */
function guardar_configuracion($pdo, $clave, $valor) {
    try {
        // Intentar actualizar primero
        $sql_update = "UPDATE configuracion SET valor = :valor WHERE clave = :clave";
        $stmt = $pdo->prepare($sql_update);
        $stmt->execute([':valor' => $valor, ':clave' => $clave]);
        
        // Si no se actualizó ninguna fila, insertar
        if ($stmt->rowCount() === 0) {
            $sql_insert = "INSERT INTO configuracion (clave, valor) VALUES (:clave, :valor)";
            $stmt = $pdo->prepare($sql_insert);
            $stmt->execute([':valor' => $valor, ':clave' => $clave]);
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtiene la versión de la aplicación desde la base de datos
 * Si no existe, retorna el valor por defecto '1.0.0'
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @return string Versión de la aplicación
 */
function obtener_version_app($pdo) {
    return obtener_configuracion($pdo, 'app_version', '1.0.0');
}

/**
 * Guarda la versión de la aplicación en la base de datos
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $version Versión a guardar (ej: "2.1.0")
 * @return bool true si se guardó correctamente, false en caso contrario
 */
function guardar_version_app($pdo, $version) {
    // Validar formato de versión (X.Y.Z o X.Y)
    if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
        return false;
    }
    
    return guardar_configuracion($pdo, 'app_version', $version);
}

/**
 * Obtiene todas las configuraciones como array asociativo
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @return array Array con clave => valor
 */
function obtener_todas_configuraciones($pdo) {
    try {
        $sql = "SELECT clave, valor FROM configuracion";
        $stmt = $pdo->query($sql);
        $resultados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        return $resultados ?: [];
    } catch (Exception $e) {
        return [];
    }
}
?>