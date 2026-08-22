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

# ---------------------------------------------------------------------------
# ElastiCache TLS tüneli (in-transit encryption / Serverless Memcached)
# ---------------------------------------------------------------------------
# PHP'nin memcached eklentisinin TLS desteği YOK — upstream php-memcached'de
# OPT_USE_TLS diye bir seçenek bulunmuyor. ElastiCache tarafında şifreli
# bağlantı gerekiyorsa (Serverless bunu zorunlu tutar) araya stunnel koyuyoruz:
#
#   PHP -> 127.0.0.1:11211 -> stunnel -> TLS -> node-1:11211
#   PHP -> 127.0.0.1:11212 -> stunnel -> TLS -> node-2:11211
#
# Yerel port eşlemesi MEMCACHED_HOST sırasına göre deterministik (i. node ->
# 11211+i). wp-config.php aynı hesabı yaptığı için ortam değişkeni yeniden
# yazmıyoruz; böylece WP-CLI gibi ayrı süreçler de aynı tüneli kullanır.
if [ -n "${MEMCACHED_HOST:-}" ] && [ "${MEMCACHED_TLS:-0}" = "1" ]; then
    if ! command -v stunnel >/dev/null 2>&1; then
        echo "HATA: MEMCACHED_TLS=1 fakat stunnel kurulu değil; object cache devre dışı kalacak." >&2
    else
        stunnel_conf=/etc/stunnel/shop-memcached.conf
        mkdir -p /etc/stunnel
        {
            echo "; shop-entrypoint.sh tarafından üretildi — elle düzenlemeyin"
            echo "foreground = yes"
            echo "pid ="
            echo "syslog = no"
            echo "debug = ${MEMCACHED_TLS_DEBUG:-notice}"
            echo "sslVersionMin = TLSv1.2"
            echo "setuid = www-data"
            echo "setgid = www-data"
            # ElastiCache node IP'leri değişebilir: DNS'i başlangıçta değil
            # her bağlantıda çöz, yoksa failover'da bayat IP'ye gidilir.
            echo "delay = yes"
        } > "$stunnel_conf"

        stunnel_idx=0
        IFS=',' read -r -a shop_nodes <<< "$MEMCACHED_HOST" || true
        for shop_node in "${shop_nodes[@]}"; do
            shop_node="$(echo "$shop_node" | tr -d '[:space:]')"
            if [ -z "$shop_node" ]; then
                continue
            fi
            shop_node_host="${shop_node%%:*}"
            if [ "$shop_node" = "$shop_node_host" ]; then
                shop_node_port="${MEMCACHED_PORT:-11211}"
            else
                shop_node_port="${shop_node##*:}"
            fi
            {
                echo
                echo "[memcached-${stunnel_idx}]"
                echo "client = yes"
                echo "accept = 127.0.0.1:$((11211 + stunnel_idx))"
                echo "connect = ${shop_node_host}:${shop_node_port}"
                echo "sni = ${shop_node_host}"
                if [ "${MEMCACHED_TLS_VERIFY:-1}" = "1" ]; then
                    echo "verifyChain = yes"
                    echo "CApath = /etc/ssl/certs"
                    echo "checkHost = ${shop_node_host}"
                fi
            } >> "$stunnel_conf"
            stunnel_idx=$((stunnel_idx + 1))
        done

        if [ "$stunnel_idx" -eq 0 ]; then
            echo "HATA: MEMCACHED_TLS=1 fakat MEMCACHED_HOST'tan geçerli node çıkmadı." >&2
        else
            echo "Memcached TLS tüneli: ${stunnel_idx} node, 127.0.0.1:11211-$((11210 + stunnel_idx))" >&2

            # stunnel düşerse cache kalıcı olarak kaybolmasın diye basit watchdog.
            # (Cache down = site yavaşlar, ayakta kalır; yine de kendini toparlasın.)
            (
                while true; do
                    stunnel_started=$SECONDS
                    stunnel "$stunnel_conf" || echo "stunnel çıktı (kod $?), yeniden başlatılıyor" >&2
                    # Saniyesinde düştüyse yapılandırma/sertifika hatası olabilir;
                    # log'u (ve CloudWatch faturasını) boğmamak için uzun bekle.
                    if [ $((SECONDS - stunnel_started)) -lt 5 ]; then
                        sleep 30
                    else
                        sleep 2
                    fi
                done
            ) &

            # İlk isteklerin cache'i ıskalamaması için tünelin dinlemeye
            # başlamasını kısa süre bekle (en fazla ~2 sn).
            for _ in 1 2 3 4 5 6 7 8 9 10; do
                if (exec 3<>/dev/tcp/127.0.0.1/11211) 2>/dev/null; then
                    break
                fi
                sleep 0.2
            done
        fi
    fi
fi

# Eklenti/tema kurulumu açıkken (varsayılan) OPcache dosya damgalarını yeniden
# doğrulamalı; aksi halde panelden kurulan/güncellenen eklentilerin PHP dosyaları
# konteyner yeniden başlayana dek eski haliyle çalışır.
# php-overrides.ini'deki validate_timestamps=0'ı ezmek için alfabetik olarak
# sonra gelen bir conf.d dosyası yazıyoruz.
if [ "${WORDPRESS_DISALLOW_FILE_MODS:-0}" != "1" ] && [ -w /usr/local/etc/php/conf.d ]; then
    cat > /usr/local/etc/php/conf.d/zzz-file-mods.ini <<'INI'
; Panelden eklenti/tema kurulabildiği için OPcache değişiklikleri görmeli
opcache.validate_timestamps = 1
opcache.revalidate_freq = 15
INI
fi

# WordPress'in wp-admin üzerinden eklenti/tema kurabilmesi için yazılabilir
# dizinler. EFS mount'ları root'a ait geldiğinde de sahipliği düzeltiyoruz;
# read-only mount'ta hata verip başlangıcı bloklamasın diye "|| true".
for dir in plugins themes uploads upgrade languages; do
    target="/var/www/html/wp-content/$dir"
    mkdir -p "$target" 2>/dev/null || true
    if [ -d "$target" ] && [ "$(stat -c '%U' "$target" 2>/dev/null)" != "www-data" ]; then
        chown www-data:www-data "$target" || true
    fi
done

exec docker-entrypoint.sh "$@"
