# Cambios hechos dentro del contenedor

Configuración que **no vive en el repositorio** y que un redespliegue revierte.
Si algo dejó de funcionar después de un deploy, empezá por acá.

## 0. Dónde vive de verdad la configuración (leer primero)

El 18/09/2026 el contenedor se recreó y se perdieron el scheduler, el límite
de nginx y los permisos. La causa: los había aplicado **dentro** del
contenedor, y el contenedor es desechable.

La configuración real sale de archivos que Easypanel genera en el host y
monta adentro:

| host                                                              | contenedor                                   |
|-------------------------------------------------------------------|----------------------------------------------|
| `/etc/easypanel/projects/heaven/c21_laravel/generated/supervisord.conf` | `/etc/supervisor/conf.d/supervisord.conf` |
| `/etc/easypanel/projects/heaven/c21_laravel/generated/site.conf`        | `/etc/nginx/sites-available/default`      |
| `/etc/easypanel/projects/heaven/c21_laravel/generated/php.ini`          | `/etc/php/8.2/fpm/conf.d/99-easypanel.ini` |
| `/etc/easypanel/projects/heaven/c21_laravel/generated/start.sh`         | `/start.sh`                                |

Editar esos archivos en el host **sobrevive a la recreación del contenedor**,
pero no a que alguien guarde la configuración del servicio en el panel de
Easypanel, que los regenera. Por eso el lugar definitivo es el propio panel:

- **Scheduler** → Easypanel → servicio `c21_laravel` → *Processes* /
  *Supervisord* → agregar un programa igual al `[program:whatsapp]` que ya
  está, con `command=/bin/bash -c "php artisan schedule:work --no-interaction"`
  y `user=www-data`.
- **Límite de subida** → mismo servicio → configuración de nginx → agregar
  `client_max_body_size 256M;` dentro del `server`.

Mientras eso no esté en el panel, los puntos 1 y 2 de abajo se aplican sobre
los archivos de `generated/`, no dentro del contenedor. Después:

```bash
docker exec $C sh -c "nginx -t && nginx -s reload"
docker exec $C sh -c "supervisorctl reread && supervisorctl update"
```

## 1. nginx — límite de subida

Sin esto nginx corta en 1 MB y rechaza varias fotos o un video antes de que PHP los vea.

```nginx
# generated/site.conf en el host (ver punto 0), dentro del server
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
