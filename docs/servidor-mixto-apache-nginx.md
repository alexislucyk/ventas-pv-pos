# Servidor Mixto Apache / Nginx — POS (pos_dev)

> La app es **agnóstica del servidor web**. El enrutamiento de URLs limpias lo
> resuelve `core/Router.php` leyendo `$_SERVER['REQUEST_URI']`, por lo que
> funciona igual en Apache (mod_rewrite) y en Nginx (`try_files`).

## 1. Apache (actual)

Usa `.htaccess` en la raíz: reescribe a `index.php` solo cuando la URL no
corresponde a un archivo/directorio real y bloquea carpetas internas y
archivos sensibles. No requiere cambios.

## 2. Nginx

Requiere este server block. Replica exactamente las reglas del `.htaccess`:

```nginx
server {
    listen 80;
    server_name _;

    root /var/www/pos_dev;      # ajustar a la ruta real del proyecto
    index index.php;
    charset utf-8;
    client_max_body_size 32M;

    # --- Bloquear archivos/directorios sensibles (equivalente <FilesMatch>) ---
    location ~ /\.(?!well-known) { return 404; }          # .env, .git, .licencia_*, etc.
    location ~* \.(bak|backup|swp|swo|tmp|log|sql)$ { return 404; }

    # --- Bloquear acceso directo a código interno (equivalente RewriteRule 404) ---
    location ~ ^/(app|config|core|migrations|vendor|backups|docs)(/|$) {
        return 404;
    }

    # --- Front controller: archivos reales directo, resto al router ---
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # o 127.0.0.1:9000
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }
}
```

## 3. Protección a nivel de aplicación (ambos servidores)

Además de las reglas del servidor web, los archivos internos de
`app/`, `config/` y `core/` llevan desde su primera línea un **guard PHP**
que devuelve `404` si el archivo es el script solicitado directamente por HTTP:

```php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit('Not Found');
}
```

Esto bloquea la ejecución/descusión de lógica interna incluso si falta una
regla del servidor web (p. ej. un Nginx mal configurado), porque la protección
viaja con el código. Los archivos **no PHP** (`.env`, dumps SQL en `backups/`,
`cache/`) no pueden autoprotegerse: para esos casos son obligatorias las
reglas `location ~ /\.` y `location ~* \.(...|sql)$` mostradas arriba.

## 4. Nota sobre licencias

El módulo de licencias (`config/licencia_manager.php`) ya no está en uso;
no condiciona el despliegue.
