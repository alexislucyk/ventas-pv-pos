<?php
// pages/infosesion.php - GUARDIA DE SESIÓN DINÁMICO
session_start();

// 1. Incluimos el config para tener acceso a URL_BASE si no está cargado
// Usamos dirname(__FILE__) para que encuentre el config sin importar desde dónde se llame
require_once dirname(__FILE__) . '/../config/db_config.php';

// 2. GUARDIA DE SEGURIDAD: Si no hay sesión, redirigir al login
if (!isset($_SESSION['usuario_id'])) {
    // Redirigimos a la raíz del entorno actual (pos_dev o pos_prod)
    header('Location: ' . URL_BASE . 'login.php');
    exit();
}

// Datos de la sesión para mostrar
$nombre_usuario = htmlspecialchars($_SESSION['usuario_nombre']); // O $_SESSION['usuario_nombre'] si lo usas así
$rol = htmlspecialchars($_SESSION['usuario_rol']);
?>