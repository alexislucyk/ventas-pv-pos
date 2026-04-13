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

    $nivelUsuario = obtenerNivel($_SESSION['usuario_rol']);
    $nivelMinimo  = obtenerNivel($rolMinimoRequerido);

    // Si el nivel es insuficiente, lo mandamos al index de la raíz actual
    if ($nivelUsuario < $nivelMinimo) {
        header("Location: " . URL_BASE . "index.php?error=acceso_denegado");
        exit();
    }
}