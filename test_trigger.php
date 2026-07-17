<?php
$host = '192.168.7.45';
$user = 'root';
$pass = 'isidoro9';
$db_name = 'pos_dev';

$dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";
$options = array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
);

$pdo = new PDO($dsn, $user, $pass, $options);

// 1. Check for triggers
echo "=== TRIGGERS ===\n";
try {
    $result = $pdo->query("SHOW TRIGGERS");
    $triggers = $result->fetchAll(PDO::FETCH_ASSOC);
    if (empty($triggers)) {
        echo "No triggers found.\n";
    } else {
        foreach ($triggers as $t) {
            echo "Trigger: " . $t['Trigger'] . " on " . $t['Event'] . " of " . $t['Table'] . "\n";
            echo "  Statement: " . $t['Statement'] . "\n";
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "Error checking triggers: " . $e->getMessage() . "\n";
}

// 2. Test the exact stock insert from agregar_producto_rapido.php
echo "\n=== TEST STOCK INSERT ===\n";
try {
    $empresa_id = 1;
    $sucursal_id = 1;
    $cod_prod = 'TESTPROD' . time();
    $stock = 10;

    $sql_stock = "INSERT INTO stocks (empresa_id, sucursal_id, cod_prod, stock_actual) VALUES (?, ?, ?, ?) 
                  ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)";
    echo "SQL: $sql_stock\n";
    $stmt_stock = $pdo->prepare($sql_stock);
    echo "Prepared. Executing with: " . json_encode([$empresa_id, $sucursal_id, $cod_prod, $stock]) . "\n";
    $stmt_stock->execute([$empresa_id, $sucursal_id, $cod_prod, $stock]);
    echo "SUCCESS!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

// 3. Check stocks table structure
echo "\n=== STOCKS TABLE STRUCTURE ===\n";
try {
    $result = $pdo->query("DESCRIBE stocks");
    $cols = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo $col['Field'] . " " . $col['Type'] . " " . $col['Key'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 4. Check productos table structure
echo "\n=== PRODUCTOS TABLE STRUCTURE ===\n";
try {
    $result = $pdo->query("DESCRIBE productos");
    $cols = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo $col['Field'] . " " . $col['Type'] . " " . $col['Key'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
