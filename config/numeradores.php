<?php
// config/numeradores.php - NUMERADORES DE VENTAS
//
// Las ventas usan dos series de n_documento separadas:
//
//   - Numerador NORMAL (ventas finalizadas): 1, 2, 3, ... < VENTA_PENDIENTE_BASE
//   - Numerador de PENDIENTES:               >= VENTA_PENDIENTE_BASE
//     (primer pendiente: VENTA_PENDIENTE_BASE + 1 = 1000001)
//
// Una venta guardada como PENDIENTE consume únicamente el numerador de
// pendientes; recién al FINALIZARSE se le asigna el siguiente número de la
// serie normal y se re-enlaza su detalle (ventas y ventas_detalle).
//
// El rango reservado es obligatorio: las búsquedas por n_documento
// (reanudar, anular, ticket) necesitan que el número identifique a una sola
// venta, por lo que la serie de pendientes no puede repetir números de la
// serie normal.

// Bloqueo de acceso directo (compatibilidad Apache/Nginx): si este archivo es
// el script solicitado por HTTP, responder 404.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit('Not Found'); }

if (!defined('VENTA_PENDIENTE_BASE')) {
    define('VENTA_PENDIENTE_BASE', 1000000);
}

if (!function_exists('venta_siguiente_n')) {
    /**
     * Devuelve el próximo n_documento de venta.
     *
     * @param PDO    $pdo         Conexión (debe estar dentro de la transacción
     *                            de la venta para que el FOR UPDATE tenga efecto).
     * @param bool   $es_pendiente true → numerador de pendientes (rango >= BASE);
     *                             false → numerador normal (rango < BASE).
     * @return int
     */
    function venta_siguiente_n(PDO $pdo, $es_pendiente)
    {
        if ($es_pendiente) {
            $stmt = $pdo->prepare("SELECT MAX(n_documento) AS ultimo FROM ventas WHERE n_documento >= ? FOR UPDATE");
            $stmt->execute([VENTA_PENDIENTE_BASE]);
        } else {
            // El numerador normal ignora por completo el rango de pendientes,
            // para que las pendientes no "gasten" números de venta normales.
            $stmt = $pdo->prepare("SELECT MAX(n_documento) AS ultimo FROM ventas WHERE n_documento < ? FOR UPDATE");
            $stmt->execute([VENTA_PENDIENTE_BASE]);
        }
        $ultimo = $stmt->fetchColumn();
        if ($ultimo === null || $ultimo === false) {
            return $es_pendiente ? (VENTA_PENDIENTE_BASE + 1) : 1;
        }
        return (int)$ultimo + 1;
    }
}
