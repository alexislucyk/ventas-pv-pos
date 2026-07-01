<!-- selector_empresa.php - Selector de empresa para multi-tenancy -->
<?php
// Ubicacion: pages/components/selector_empresa.php
// Incluir este archivo en sidebar.php despues del .empresa-title

if (!isset($_SESSION['permisos'])) return;

try {
    $sql_empresas = "SELECT id, nombre_fantasia FROM empresas WHERE activa = 1 ORDER BY nombre_fantasia";
    $stmt_empresas = $pdo->query($sql_empresas);
    $empresas = $stmt_empresas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $empresas = [];
}
?>

<div class="empresa-selector-container">
    <select id="selectorEmpresa" class="empresa-selector" onchange="cambiarEmpresa(this.value)">
        <?php foreach ($empresas as $emp): ?>
            <option value="<?php echo $emp['id']; ?>" 
                <?php echo (isset($_SESSION['empresa_id']) && $_SESSION['empresa_id'] == $emp['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($emp['nombre_fantasia']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<style>
.empresa-selector-container {
    padding: 0 10px 10px 10px;
    flex-shrink: 0;
}
.empresa-selector {
    width: 100%;
    padding: 8px 12px;
    background: #252525;
    border: 1px solid #333;
    color: #fff;
    border-radius: 6px;
    font-size: 0.85em;
    cursor: pointer;
}
.empresa-selector:focus {
    border-color: #00bcd4;
    outline: none;
}
</style>

<script>
function cambiarEmpresa(empresa_id) {
    fetch('ajax/cambiar_empresa.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'empresa_id=' + empresa_id
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            mostrarMensaje('Error', data.error || 'No se pudo cambiar de empresa', 'error');
        }
    });
}
</script>