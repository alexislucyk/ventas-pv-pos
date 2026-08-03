<!-- selector_empresa.php - Selector de empresa para multi-tenancy -->
<?php
// Ubicacion: pages/components/selector_empresa.php
// Incluir este archivo en sidebar.php despues del .empresa-title

if (!isset($_SESSION['permisos'])) return;

try {
    $sql_empresas = "SELECT id, nombre_fantasia FROM empresas WHERE activa = 1 ORDER BY nombre_fantasia";
    $stmt_empresas = $pdo->query($sql_empresas);
    $selector_empresas = $stmt_empresas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $selector_empresas = [];
}
?>

<div class="empresa-selector-container">
    <select id="selectorEmpresa" class="empresa-selector" onchange="cambiarEmpresa(this.value)">
        <?php foreach ($selector_empresas as $emp): ?>
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
    const selector = document.getElementById('selectorEmpresa');
    if (selector) selector.disabled = true;
    
    // Construir URL completa para depuración
    const url = '<?php echo URL_BASE; ?>ajax/cambiar_empresa.php';
    console.log('Intentando cambiar a empresa:', empresa_id);
    console.log('URL:', url);
    
    // Usar URL_BASE para la ruta correcta
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'empresa_id=' + encodeURIComponent(empresa_id)
    })
    .then(r => {
        console.log('Response status:', r.status);
        console.log('Response headers:', r.headers);
        return r.text().then(text => {
            console.log('Response text:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Error parsing JSON:', e);
                throw new Error('Respuesta inválida del servidor');
            }
        });
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            // Redirección forzada con parámetro anti-caché
            window.location.href = window.location.pathname + '?empresa_cambiada=' + Date.now();
        } else {
            mostrarMensaje('Error', data.error || 'No se pudo cambiar de empresa', 'error');
            if (selector) selector.disabled = false;
        }
    })
    .catch(err => {
        console.error('Error completo:', err);
        mostrarMensaje('Error', 'Error: ' + err.message, 'error');
        if (selector) selector.disabled = false;
    });
}

// Asegurar que el selector muestre la empresa correcta al cargar
document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('selectorEmpresa');
    if (selector && <?php echo isset($_SESSION['empresa_id']) ? $_SESSION['empresa_id'] : 'null'; ?>) {
        selector.value = <?php echo isset($_SESSION['empresa_id']) ? $_SESSION['empresa_id'] : 'null'; ?>;
    }
});
</script>
