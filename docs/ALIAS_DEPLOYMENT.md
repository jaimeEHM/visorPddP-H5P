# Despliegue con alias de subdirectorio

Este documento describe como desplegar el proyecto cuando no cuelga de la raiz del dominio, sino de un alias/subdirectorio.

Ejemplo:

- URL base directa: `https://midominio.cl/`
- URL con alias: `https://midominio.cl/visor-h5p`

## Objetivo

Permitir que la aplicacion Laravel + Inertia + Vue funcione correctamente bajo un prefijo de ruta (alias), manteniendo:

- rutas web funcionales,
- assets cargando sin 404,
- acciones LTI/LRS sin hardcode absoluto,
- compatibilidad en entorno Docker.

## Estado del proyecto (relevante)

El frontend de integraciones (`resources/js/pages/LtiPlatforms.vue`) ya fue ajustado para usar rutas Wayfinder en vez de strings hardcodeadas (`/lti/...`), lo que reduce problemas al usar alias.

## 1) Variables de entorno

Configura el alias en `.env`:

```env
APP_URL=https://midominio.cl/visor-h5p
ASSET_URL=https://midominio.cl/visor-h5p
```

Notas:

- Si usas CDN para assets, `ASSET_URL` puede apuntar a otro origen.
- Si cambias `.env`, limpia cache de config:

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

## 2) Servidor web (alias)

Debes enrutar el alias al `public/` de Laravel.

### Apache (referencial)

```apache
Alias /visor-h5p /var/www/html/public

<Directory /var/www/html/public>
    AllowOverride All
    Require all granted
</Directory>
```

Y confirmar que el rewrite de Laravel funcione bajo ese prefijo.

### Nginx (referencial)

```nginx
location /visor-h5p/ {
    try_files $uri $uri/ /visor-h5p/index.php?$query_string;
}

location ~ ^/visor-h5p/index\.php(/|$) {
    fastcgi_pass php-fpm:9000;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
    include fastcgi_params;
}
```

## 3) Build frontend

En el contenedor/proyecto:

```bash
npm install --include=optional
npm run build
```

Si aparece error de `rolldown` por bindings opcionales (Linux ARM64):

```bash
npm install --save-optional @rolldown/binding-linux-arm64-gnu@latest
npm run build
```

## 4) Regenerar rutas Wayfinder

Recomendado después de cambios de rutas/controladores:

```bash
php artisan wayfinder:generate
```

## 5) Validaciones funcionales mínimas

Validar en navegador usando la URL con alias:

1. `https://midominio.cl/visor-h5p/`
2. Botón **Integraciones** en la portada.
3. `https://midominio.cl/visor-h5p/lti/plataformas`
4. Login de acceso a integraciones.
5. Segmentos por tabs:
   - `LTI`
   - `LRS` (deshabilitado si no hay plataforma LTI registrada).
6. Crear plataforma LTI.
7. Habilitación de segmento LRS.
8. Crear/probar/eliminar conexión LRS.
9. Carga de favicon y assets sin errores 404.

## 6) Checklist de producción

- [ ] `APP_URL` y `ASSET_URL` apuntan al alias correcto.
- [ ] Alias del servidor web apunta a `public/`.
- [ ] `php artisan config:clear` ejecutado.
- [ ] Build frontend generado sin errores.
- [ ] Wayfinder generado.
- [ ] Navegación LTI/LRS validada bajo alias.
- [ ] Logs sin errores de rutas/asset.

## Nota sobre LTI/OIDC

Si la herramienta LTI usa URLs públicas absolutas para integración LMS, asegúrate de registrar en LMS las URLs con el alias (issuer, launch y login initiation), consistentes con `APP_URL`.
