<?php
// config/validar_permisos.php

/**
 * Define la jerarquía numérica de los roles.
 * Cuanto más alto el número, más poder tiene el usuario.
 */
function obtenerNivel($rol) {
    $niveles = [
        'vendedor'   => 1,
        'cajero'     => 2,
        'supervisor' => 3,
        'admin'      => 4,
        'developer'  => 99 // Nivel máximo para desarrolladores con acceso total
    ];

    return isset($niveles[$rol]) ? $niveles[$rol] : 0;
}

/**
 * Comprueba si el usuario tiene el nivel suficiente para ver la página.
 * 
 * Además de la jerarquía de roles, también respeta los permisos individuales
 * por módulo otorgados al usuario (tabla permisos_usuario / permisos_rol).
 * Así, un usuario con permiso de módulo asignado NO es bloqueado por su rol.
 */
function restringirPagina($rolMinimoRequerido) {
    // Aseguramos que URL_BASE esté disponible
    if (!defined('URL_BASE')) {
        require_once dirname(__FILE__) . '/db_config.php';
    }

    // Si no hay sesión iniciada, lo mandamos al login de la raíz actual
    if (!isset($_SESSION['usuario_rol'])) {
        header("Location: " . URL_BASE . "login.php");
        exit();
    }

    // El rol 'developer' siempre tiene acceso total
    if ($_SESSION['usuario_rol'] === 'developer') {
        return;
    }

    $nivelUsuario = obtenerNivel($_SESSION['usuario_rol']);
    $nivelMinimo  = obtenerNivel($rolMinimoRequerido);

    // 1) Si el nivel de rol es suficiente, permitir acceso
    if ($nivelUsuario >= $nivelMinimo) {
        return;
    }

    // 2) NUEVO: Verificar si el usuario tiene el permiso individual del módulo actual
    //    (por ejemplo, pages/abm_empresa.php otorgado en Gestión de Permisos)
    if (isset($_SERVER['SCRIPT_NAME'])) {
        // Obtener la ruta relativa del archivo actual, quitando el prefijo URL_BASE
        $ruta_actual = ltrim($_SERVER['SCRIPT_NAME'], '/');

        if (defined('URL_BASE')) {
            $base_limpia = ltrim(URL_BASE, '/');
            if ($base_limpia !== '' && strpos($ruta_actual, $base_limpia) === 0) {
                $ruta_actual = substr($ruta_actual, strlen($base_limpia));
            }
        }

        // Comprobar en los permisos cargados en sesión (permisos_usuario + permisos_rol)
        if (!empty($ruta_actual) && isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
            if (in_array($ruta_actual, $_SESSION['permisos'])) {
                return; // Tiene permiso individual al módulo: permitir acceso
            }
        }
    }

    // 3) Sin rol suficiente y sin permiso de módulo: denegar acceso
    header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
    exit();
}
