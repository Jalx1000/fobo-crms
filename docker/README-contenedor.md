# Cambios hechos dentro del contenedor

Configuración que **no vive en el repositorio** y que un redespliegue revierte.
Si algo dejó de funcionar después de un deploy, empezá por acá.

## 1. nginx — límite de subida

Sin esto nginx corta en 1 MB y rechaza varias fotos o un video antes de que PHP los vea.

```nginx
# /etc/nginx/sites-enabled/default, dentro del server
client_max_body_size 256M;
```

```bash
docker exec $C nginx -t && docker exec $C nginx -s reload
```

## 2. supervisor — el scheduler

**No existía.** Sin él, todo comando programado en `app/Console/Kernel.php` es código que nadie
ejecuta: el aviso de derivaciones sin tomar nunca salía. El comentario del entrypoint dice que
supervisor levanta un scheduler; no lo levantaba.

```ini
; /etc/supervisor/conf.d/supervisord.conf
[program:scheduler]
command=/bin/bash -c "php artisan schedule:work"
directory=/code
user=www-data
environment=HOME="/tmp"
autostart=true
autorestart=true
stdout_logfile=/var/log/scheduler.log
stderr_logfile=/var/log/scheduler.err.log
stopasgroup=true
killasgroup=true
```

```bash
docker exec $C supervisorctl reread && docker exec $C supervisorctl update
docker exec $C supervisorctl status      # deben aparecer: ide, nginx, php, scheduler, whatsapp
```

## 3. Permisos de storage

La CLI corre como `root` y la web como `www-data`. Un archivo creado por uno deja al otro afuera, y
eso ya rompió la carga de galerías y el bloqueo de tareas programadas.

```bash
docker exec -w /code $C sh -c '
  chown -R www-data:www-data storage bootstrap/cache
  find storage bootstrap/cache -type d -exec chmod 775 {} \;
  find storage bootstrap/cache -type f -exec chmod 664 {} \;'
```

**Correr artisan siempre como www-data** para no volver a ensuciarlo:

```bash
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan ...
```

## 4. Workers duplicados — revisar tras cada cambio de código

`supervisorctl restart` levanta uno nuevo pero **no mata al que se desprendió**. Dos workers sobre la
misma cola hacen que las cosas fallen la mitad de las veces.

```bash
docker exec $C sh -c 'ps -o pid=,ppid=,lstart=,cmd= -C php | grep queue'
```

Más de una línea, o alguna con `ppid=0`, es un huérfano: `docker exec $C kill -TERM <pid>`.

## 5. Recompilar el CSS del admin

El build de Vite no está en el repositorio y **cada servidor lo compila**. Si se agregan clases de
Tailwind en vistas nuevas —o se toca `packages/Webkul/Admin/tailwind.config.js`— hay que rehacerlo o
esas clases no existen en el CSS y el HTML sale sin estilo.

```bash
cd packages/Webkul/Admin && npm run build     # node vive en el host, no en el contenedor

# lo genera root: devolver los archivos a www-data
docker exec -w /code $C sh -c '
  chown -R www-data:www-data public/admin/build
  find public/admin/build -type f -exec chmod 644 {} \;
  find public/admin/build -type d -exec chmod 755 {} \;'
```

`tailwind.config.js` tiene que listar el módulo cuyas vistas quieras estilar. Hoy incluye Admin,
Whatsapp e Inmobiliaria; un módulo nuevo hay que agregarlo ahí.

---

## Pendiente, no resuelto

- **El correo no sale.** SMTP devuelve `535 Incorrect authentication data` para
  `jmogro@sofopolis.com`. Afecta el aviso al asesor cuando le derivan y el escalamiento al
  supervisor. Los dos fallan en silencio: quedan en el log y nadie lo mira.
- **IMAP falla igual**, con 742 errores de autenticación desde el 27/08.
