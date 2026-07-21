#!/bin/bash
set -euo pipefail

# MEMCACHED_HOST tanımlıysa object cache drop-in'ini kaynak ağaca ekle;
# resmi entrypoint /var/www/html boşsa tüm kaynağı oraya kopyalar.
if [ -n "${MEMCACHED_HOST:-}" ]; then
    cp /usr/src/extras/object-cache.php /usr/src/wordpress/wp-content/object-cache.php
    # Konteyner yeniden başladığında webroot zaten doluysa drop-in'i doğrudan yerleştir
    if [ -d /var/www/html/wp-content ] && [ ! -e /var/www/html/wp-content/object-cache.php ]; then
        cp /usr/src/extras/object-cache.php /var/www/html/wp-content/object-cache.php || true
    fi
fi

exec docker-entrypoint.sh "$@"
