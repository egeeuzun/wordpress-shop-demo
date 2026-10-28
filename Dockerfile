# WordPress mağaza demo imajı — ECS Fargate üzerinde çalışacak şekilde tasarlandı.
# Kod imaja gömülür (immutable), kalıcı veriler (uploads) EFS'e mount edilir.
FROM wordpress:7.0-php8.3-apache

# ElastiCache (Memcached) için PHP memcached eklentisi + yardımcı araçlar.
# stunnel: PHP'nin memcached eklentisinin TLS desteği yok; ElastiCache'te
# in-transit encryption / Serverless kullanılacaksa (MEMCACHED_TLS=1) entrypoint
# bağlantıyı stunnel üzerinden tünelliyor.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libmemcached-dev \
        zlib1g-dev \
        libssl-dev \
        unzip \
        less \
        default-mysql-client \
        stunnel4 \
        ca-certificates \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && rm -rf /var/lib/apt/lists/*

# ALB / CloudFront arkasında gerçek istemci IP'sini görebilmek için
RUN a2enmod remoteip
COPY docker/remoteip.conf /etc/apache2/conf-available/remoteip.conf
RUN a2enconf remoteip

# WP-CLI (seed ve bakım komutları için)
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Object cache drop-in'ine ElastiCache ayarlarını enjekte eden yama (aşağıda kullanılıyor)
COPY docker/patch-object-cache.sh /usr/local/bin/patch-object-cache.sh
RUN chmod +x /usr/local/bin/patch-object-cache.sh

# Mağaza için eklenti ve temalar: WooCommerce, Storefront,
# WP Offload Media Lite (S3 + CloudFront medya offload),
# Memcached object cache drop-in (Memcached Redux)
RUN set -eux; \
    curl -fsSL -o /tmp/woocommerce.zip \
        https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip; \
    unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins/; \
    curl -fsSL -o /tmp/offload-media.zip \
        https://downloads.wordpress.org/plugin/amazon-s3-and-cloudfront.latest-stable.zip; \
    unzip -q /tmp/offload-media.zip -d /usr/src/wordpress/wp-content/plugins/; \
    curl -fsSL -o /tmp/storefront.zip \
        https://downloads.wordpress.org/theme/storefront.latest-stable.zip; \
    unzip -q /tmp/storefront.zip -d /usr/src/wordpress/wp-content/themes/; \
    curl -fsSL -o /tmp/memcached-redux.zip \
        https://downloads.wordpress.org/plugin/memcached-redux.latest-stable.zip; \
    unzip -q /tmp/memcached-redux.zip -d /tmp/; \
    mkdir -p /usr/src/extras; \
    cp /tmp/memcached-redux/object-cache.php /usr/src/extras/object-cache.php; \
    patch-object-cache.sh /usr/src/extras/object-cache.php; \
    rm -rf /tmp/*.zip /tmp/memcached-redux

# Türkçe dil paketleri imaja gömülür: çalışma anında wordpress.org'a gidilmez
# ve ECS'te task yenilense de çeviriler kaybolmaz. Paketler imajdaki çekirdek,
# eklenti ve tema sürümleriyle eşleşir; çekirdek/WooCommerce/Storefront
# çevirisi bulunamazsa build durur.
COPY docker/install-translations.sh /usr/local/bin/install-translations.sh
RUN chmod +x /usr/local/bin/install-translations.sh \
    && install-translations.sh tr_TR \
        core \
        plugin:woocommerce \
        theme:storefront \
        'plugin:amazon-s3-and-cloudfront?'

# Özel tema, mu-plugin'ler ve ortam değişkeni tabanlı wp-config
COPY wp-content/ /usr/src/wordpress/wp-content/
COPY wp-config/wp-config.php /usr/src/wordpress/wp-config.php
COPY docker/health.php /usr/src/wordpress/health.php
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-overrides.ini

# Demo mağaza seed kiti (Faz 2): katalog + WP-CLI seed betiği. Webroot dışında;
# lokalde scripts/seed-demo.sh, AWS'de ECS Exec ile çalıştırılır:
#   wp eval-file /usr/src/shop-seed/seed.php
COPY seed/ /usr/src/shop-seed/
# Seed kitinin içerik özeti: otomatik kurulum (SHOP_AUTO_SEED=1) bunu
# veritabanındaki shop_seed_version ile karşılaştırır, kit değişmediyse
# seed'i tekrar çalıştırmaz.
RUN cd /usr/src/shop-seed \
    && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16 > .version
COPY docker/auto-seed.php /usr/local/bin/shop-auto-seed.php

COPY docker/entrypoint.sh /usr/local/bin/shop-entrypoint.sh
# wp-admin üzerinden eklenti/tema kurulumu için WordPress'in yazabilmesi gereken
# dizinler (upgrade = indirilen paketlerin açıldığı geçici dizin).
RUN chmod +x /usr/local/bin/shop-entrypoint.sh \
    && mkdir -p /usr/src/wordpress/wp-content/upgrade \
                /usr/src/wordpress/wp-content/uploads \
                /usr/src/wordpress/wp-content/languages \
    && chown -R www-data:www-data /usr/src/wordpress

ENTRYPOINT ["shop-entrypoint.sh"]
CMD ["apache2-foreground"]
