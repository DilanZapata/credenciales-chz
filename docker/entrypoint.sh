#!/bin/bash
# =====================================================================
#  Arranque del contenedor
#   1. Espera a que la base de datos responda
#   2. Aplica las migraciones pendientes
#   3. Verifica la instalacion
#   4. Cede el control a Apache
# =====================================================================
set -euo pipefail

cd /var/www/html

echo "==> Verificando el material criptografico"
if ! php docker/check-crypto.php; then
    exit 1
fi

echo "==> Verificando extensiones de PHP"
if ! php docker/check-extensions.php; then
    echo "!!! La imagen se construyo de forma incompleta."
    echo "    Vuelva a desplegar con la cache limpia (Clean Cache)."
    exit 1
fi

echo "==> Esperando a la base de datos (${DB_HOST:-db}:${DB_PORT:-3306})"
intentos=0
until php -r '
    $h = getenv("DB_HOST") ?: "db";
    $p = (int) (getenv("DB_PORT") ?: 3306);
    $d = getenv("DB_DATABASE") ?: "credenciales_corp";
    $u = getenv("DB_USERNAME") ?: "root";
    $w = getenv("DB_PASSWORD") ?: "";
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli($h, $u, $w, $d, $p);
    exit($c->connect_errno ? 1 : 0);
' 2>/dev/null; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 60 ]; then
        echo "!!! La base de datos no respondio tras 60 intentos."
        exit 1
    fi
    sleep 2
done
echo "    Base de datos disponible."

echo "==> Preparando directorios de trabajo"
mkdir -p storage/logs storage/tmp storage/exports
chown -R www-data:www-data storage
chmod -R 750 storage

echo "==> Aplicando migraciones"
php bin/console.php migrate

echo "==> Diagnostico"
php bin/console.php doctor || true

# Las ordenes anteriores corren como root y pueden haber creado archivos de
# log de su propiedad. Se devuelve storage/ a www-data para que Apache pueda
# seguir escribiendo.
chown -R www-data:www-data storage
chmod -R 750 storage

# ---------------------------------------------------------------------
#  Tareas programadas dentro de este mismo contenedor
# ---------------------------------------------------------------------
#  Solo si APP_SCHEDULER=true. En el despliegue con Compose hay un
#  contenedor "cron" aparte y esto queda apagado; en un despliegue de un
#  solo contenedor (tipo "Application" de Dokploy) no lo hay, y sin estas
#  tareas los Excel exportados con contrasenas reales se quedan en el
#  servidor para siempre: el barrido que los borra es justo "maintenance".
#
#  Un proceso de fondo en el contenedor de la aplicacion no es lo ideal,
#  pero la alternativa aqui no es un contenedor dedicado: es no tener
#  mantenimiento.
if [ "${APP_SCHEDULER:-false}" = "true" ]; then
    echo "==> Tareas programadas activadas dentro de este contenedor"
    su -s /bin/bash www-data -c '
        ultima_alerta=""
        while true; do
            php /var/www/html/bin/console.php maintenance >/dev/null 2>&1 || true
            hoy=$(date +%F)
            if [ "$(date +%H)" = "07" ] && [ "$ultima_alerta" != "$hoy" ]; then
                php /var/www/html/bin/console.php alerts:run || true
                ultima_alerta="$hoy"
            fi
            sleep 600
        done
    ' &
fi

echo "==> Listo. Iniciando Apache."
exec "$@"
