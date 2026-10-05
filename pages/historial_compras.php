<?php
// pages/historial_compras.php
include 'infosesion.php';
require_once '../config/validar_permisos.php';
require '../config/db_config.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$empresa_id = $_SESSION['empresa_id'] ?? null;
$sucursal_id = $_SESSION['sucursal_id'] ?? 1;
if (!$empresa_id) {
    die('❌ ERROR CRÍTICO: Falta empresa_id en sesión.');
}

// Filtros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$proveedor_id = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
$tipo_doc = $_GET['tipo_doc'] ?? '';
// Filtro de estado: 'todas' por defecto; solo se aceptan los valores del ENUM.
$estado = $_GET['estado'] ?? 'todas';
if (!in_array($estado, ['todas', 'Activa', 'Anulada'], true)) {
    $estado = 'todas';
}

$where = ['c.empresa_id = :empresa_id'];
$params = [':empresa_id' => $empresa_id];

if ($fecha_desde) {
    $where[] = "c.fecha_compra >= :fecha_desde";
    $params[':fecha_desde'] = $fecha_desde;
}

if ($fecha_hasta) {
    $where[] = "c.fecha_compra <= :fecha_hasta";
    $params[':fecha_hasta'] = $fecha_hasta;
}

if ($proveedor_id > 0) {
    $where[] = "c.cod_proveedor = :proveedor_id";
    $params[':proveedor_id'] = $proveedor_id;
}

if ($tipo_doc) {
    $where[] = "c.documento = :tipo_doc";
    $params[':tipo_doc'] = $tipo_doc;
}

if ($estado !== 'todas') {
    $where[] = "c.estado = :estado";
    $params[':estado'] = $estado;
}

$where_sql = implode(' AND ', $where);

// Paginación
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$sql_count = "SELECT COUNT(*) FROM compras c WHERE $where_sql";
$total_rows = $pdo->prepare($sql_count);
$total_rows->execute($params);
$total = $total_rows->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$sql = "SELECT c.id, c.documento, c.n_documento, c.cond_pago, c.total_compra,
               c.fecha_compra, c.fecha_operacion, c.observaciones,
               c.es_sin_detalle, c.estado,
               p.razon AS proveedor, p.cuit,
               u.usuario AS usuario
        FROM compras c
        LEFT JOIN proveedores p ON c.cod_proveedor = p.cod_prov AND p.empresa_id = c.empresa_id
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        WHERE $where_sql
        ORDER BY c.fecha_operacion DESC, c.id DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales del período (excluyen las compras anuladas)
$sql_totales = "SELECT 
    SUM(c.total_compra) AS total_general,
    COUNT(c.id) AS cant_compras
    FROM compras c
    WHERE $where_sql
      AND c.estado != 'Anulada'";
$stmt_totales = $pdo->prepare($sql_totales);
$stmt_totales->execute($params);
$totales = $stmt_totales->fetch();

// Proveedores para el filtro
$stmt_provs = $pdo->prepare("SELECT cod_prov, razon FROM proveedores WHERE empresa_id = :empresa_id ORDER BY razon");
$stmt_provs->execute([':empresa_id' => $empresa_id]);
$proveedores = $stmt_provs->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Compras | <?php echo $nombre_empresa_sistema; ?></title>
    <link rel="stylesheet" href="<?php echo url('css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo url('css/pages/compras.css'); ?>">
</head>
<body>
    <button id="menuToggle" aria-label="Abrir Menú">☰ Menú</button>
    <?php include 'sidebar.php'; ?>
    
    <div class="content content-topbar">
        <?php include 'topbar.php'; ?>
        <div class="compra-header">
            <h1>📋 Historial de Compras</h1>
            <div class="header-actions">
                <a href="<?php echo route('compras'); ?>" class="btn btn-secondary">Nueva Compra</a>
                <a href="<?php echo url('ajax/exportar_compras_csv.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '')); ?>" class="btn btn-secondary">CSV</a>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card">
            <form method="GET" class="form-row">
                <div class="form-row">
                    <div>
                        <label>Fecha Desde</label>
                        <input type="date" name="fecha_desde" class="input-field" value="<?php echo $fecha_desde; ?>">
                    </div>
                    <div>
                        <label>Fecha Hasta</label>
                        <input type="date" name="fecha_hasta" class="input-field" value="<?php echo $fecha_hasta; ?>">
                    </div>
                    <div>
                        <label>Tipo Doc</label>
                        <select name="tipo_doc" class="input-field">
                            <option value="">Todos</option>
                            <option value="FACTURA A" <?php echo $tipo_doc === 'FACTURA A' ? 'selected' : ''; ?>>FACTURA A</option>
                            <option value="FACTURA B" <?php echo $tipo_doc === 'FACTURA B' ? 'selected' : ''; ?>>FACTURA B</option>
                            <option value="FACTURA C" <?php echo $tipo_doc === 'FACTURA C' ? 'selected' : ''; ?>>FACTURA C</option>
                        </select>
                    </div>
                    <div>
                        <label>Proveedor</label>
                        <select name="proveedor_id" class="input-field">
                            <option value="0">Todos</option>
                            <?php foreach ($proveedores as $p): ?>
                                <option value="<?php echo $p['cod_prov']; ?>" <?php echo $proveedor_id == $p['cod_prov'] ? 'selected' : ''; ?>><?php echo $p['razon']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Estado</label>
                        <select name="estado" class="input-field">
                            <option value="todas" <?php echo $estado === 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="Activa" <?php echo $estado === 'Activa' ? 'selected' : ''; ?>>Activas</option>
                            <option value="Anulada" <?php echo $estado === 'Anulada' ? 'selected' : ''; ?>>Anuladas</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
                </div>
            </form>
        </div>

        <!-- Totales -->
        <div class="resumen-container">
            <div class="widget widget-contado">
                <div style="text-align: center;">
                    <h3><?php echo $totales['cant_compras'] ?? 0; ?></h3>
                    <small>Compras</small>
                </div>
            </div>
            <div class="widget" style="background: #2c3e50;">
                <div style="text-align: center;">
                    <h3>$<?php echo number_format($totales['total_general'] ?? 0, 2); ?></h3>
                    <small>Total Período</small>
                </div>
            </div>
        </div>

        <!-- Lista de Compras -->
        <div class="card">
            <table class="tabla-carrito">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Documento</th>
                        <th>Proveedor</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Cond. Pago</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($compras) > 0): ?>
                        <?php foreach ($compras as $c): ?>
                            <?php
                                $es_rapida = ((int)($c['es_sin_detalle'] ?? 0) === 1);
                                $esta_anulada = (($c['estado'] ?? 'Activa') === 'Anulada');
                            ?>
                            <tr class="<?php echo $esta_anulada ? 'fila-anulada' : ''; ?>">
                                <td><?php echo $c['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($c['fecha_compra'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($c['documento'] . ' ' . $c['n_documento']); ?>
                                    <?php if ($esta_anulada): ?>
                                        <span class="badge-compra badge-anulada" title="Compra anulada: no participa en los totales">Anulada</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($c['proveedor'] ?? 'S/D'); ?></td>
                                <td class="text-right">$<?php echo number_format($c['total_compra'], 2); ?></td>
                                <td class="text-right">
                                    <?php echo htmlspecialchars($c['cond_pago']); ?>
                                    <?php if ($es_rapida): ?>
                                        <span class="badge-compra badge-rapida" title="Compra rápida (sin detalle de productos)">S/Detalle</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo route('compras'); ?>?compra_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary" title="Ver detalle">🔍</a>
                                    <?php if ($es_rapida && !$esta_anulada): ?>
                                        <button type="button" class="btn btn-sm btn-danger" title="Anular compra rápida"
                                                onclick="anularCompraRapida(<?php echo (int)$c['id']; ?>, '<?php echo htmlspecialchars($c['documento'] . ' ' . $c['n_documento'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 30px; color: #888;">
                                No se encontraron compras en el período seleccionado.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 5px; margin-top: 15px;">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" 
                           class="btn btn-secondary btn-sm" style="padding: 5px 10px;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Anular una compra rapida (sin detalle) desde el historial
        function anularCompraRapida(idCompra, documento) {
            confirmarAccion(
                'Anular Compra Rápida',
                '¿Anular la compra ' + documento + '?\n\n'
                + 'Se revertirán los asientos de la cuenta corriente del proveedor '
                + 'y el egreso en caja (si fue CONTADO). La compra quedará marcada como Anulada.\n\n'
                + 'Esta acción no se puede deshacer.',
                'ANULAR',
                'btn-danger',
                function() { ejecutarAnulacion(idCompra); }
            );
        }

        async function ejecutarAnulacion(idCompra) {
            try {
                const response = await fetch('<?php echo URL_BASE; ?>ajax/anular_compra_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_compra: idCompra })
                });

                const data = await response.json();

                if (data.success) {
                    mostrarMensaje('Compra Anulada', data.mensaje, 'success', function() {
                        location.reload();
                    });
                } else {
                    mostrarMensaje('No se pudo anular', data.mensaje || 'Error desconocido.', 'error');
                }
            } catch (err) {
                console.error('Error en fetch de anulación:', err);
                mostrarMensaje('Error', 'Error de conexión con el servidor.', 'error');
            }
        }
    </script>
</body>
</html>
