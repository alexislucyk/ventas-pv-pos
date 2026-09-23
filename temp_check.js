const fs = require('fs');
const path = 'c:/laragon/www/pos_dev/js/presupuestos.js';
const content = fs.readFileSync(path, 'utf8');
const lines = content.split('\n');

console.log('=== Keydown handler (líneas 245-270) ===');
for (let i = 244; i < 270; i++) {
    if (lines[i]) console.log((i + 1) + ': ' + lines[i]);
}

console.log('\n=== editarItem (líneas 259-275) ===');
for (let i = 258; i < 275; i++) {
    if (lines[i]) console.log((i + 1) + ': ' + lines[i]);
}

console.log('\n=== actualizarPrecioProducto (search) ===');
const updateIdx = lines.findIndex(l => l.includes('window.actualizarPrecioProducto'));
if (updateIdx >= 0) {
    for (let i = updateIdx; i < Math.min(updateIdx + 15, lines.length); i++) {
        if (lines[i].trim() === '};') break;
        console.log((i + 1) + ': ' + lines[i]);
    }
}

console.log('\n=== eliminarItem (search) ===');
const elimIdx = lines.findIndex(l => l.includes('window.eliminarItem'));
if (elimIdx >= 0) {
    for (let i = elimIdx; i < Math.min(elimIdx + 10, lines.length); i++) {
        if (lines[i].trim() === '};') break;
        console.log((i + 1) + ': ' + lines[i]);
    }
}
