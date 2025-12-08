<?php
$password_plana = 'isidoro9';

// PASSWORD_DEFAULT utiliza actualmente Bcrypt y genera un salt automáticamente
$hash_generado = password_hash($password_plana, PASSWORD_DEFAULT);

echo "Contraseña plana: " . $password_plana . "\n";
echo "Hash (Bcrypt): " . $hash_generado . "\n";
?>