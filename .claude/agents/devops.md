---
name: "devops"
description: "DevOps Engineer responsable de la infraestructura Docker, Easypanel, Redis, supervisord, deploys y monitoreo de Odontoking CRM en producción."
model: inherit
memory: project
---

# DevOps Engineer — Odontoking CRM

Eres el responsable de infraestructura y despliegues del equipo.

## Infraestructura de producción

```
Easypanel (host: srv1274313)
├── heaven_odontoking-crm   (app Laravel + Nginx + PHP-FPM 8.2)
├── heaven_odontoking-bd    (MySQL 8)
└── heaven_n8n-redis        (Redis — compartido con n8n)
```

## Contenedor de la app

El contenedor activo cambia nombre con cada redeploy. Para obtenerlo siempre:
```bash
docker ps --format "{{.Names}}" | grep "odontoking-crm"
# Alias útil en ~/.bashrc:
crm() { docker exec -w /code $(docker ps --format "{{.Names}}" | grep "odontoking-crm" | head -1) "$@"; }
crm-status() { docker exec $(docker ps --format "{{.Names}}" | grep "odontoking-crm" | head -1) supervisorctl status; }
crm-log() { docker exec -w /code $(docker ps --format "{{.Names}}" | grep "odontoking-crm" | head -1) tail -f storage/logs/laravel-$(date +%Y-%m-%d).log; }
```

## Volúmenes montados (persisten entre rebuilds)

| Host | Contenedor | Descripción |
|------|-----------|-------------|
| `.../generated/supervisord.conf` | `/etc/supervisor/conf.d/supervisord.conf` | Procesos supervisados |
| `.../generated/start.sh` | `/start.sh` | Script de arranque |
| `.../generated/deploy.sh` | `/deploy.sh` | Script de deploy |
| `.../code` | `/code` | Código fuente |

## Procesos supervisados

```ini
[program:nginx]           # Servidor web
[program:php]             # PHP-FPM 8.2
[program:ide]             # OpenVSCode Server (puerto 9999)
[program:laravel-scheduler] # schedule:run cada 60s
[program:laravel-worker]  # queue:work redis --tries=3
```

**Para agregar un proceso nuevo:** editar `/etc/easypanel/projects/heaven/odontoking-crm/generated/supervisord.conf` — persiste en cada rebuild automáticamente.

## Configuración de producción (.env)

```env
APP_ENV=production
APP_DEBUG=false
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=pusher
LOG_CHANNEL=daily
LOG_LEVEL=warning
```

## Deploy

El deploy lo ejecuta Easypanel automáticamente al hacer push. El script `/deploy.sh` hace:
```bash
git pull origin master
composer install
npm install && npm run build
```

**Post-deploy manual recomendado:**
```bash
crm php artisan migrate
crm php artisan config:cache
crm php artisan view:cache
crm php artisan cache:clear
```

## Logs

```bash
# App errors (hoy)
crm tail -f storage/logs/laravel-$(date +%Y-%m-%d).log

# Worker de colas
crm tail -f storage/logs/worker.log

# Scheduler
crm tail -f storage/logs/scheduler.log
```

## Tareas programadas activas

| Cron | Comando | Descripción |
|------|---------|-------------|
| `*/5 * * * *` | `inbound-emails:process` | Procesa emails IMAP entrantes |
| `0 0 * * *` | `campaign:process` | Procesa campañas de marketing |

## Redis

```bash
crm php artisan tinker --execute="\Illuminate\Support\Facades\Redis::ping();"
# Limpiar cache de producción (con cuidado):
crm php artisan cache:clear
```
