# WordPress mağaza demo imajı — ECS Fargate üzerinde çalışacak şekilde tasarlandı.
# Kod imaja gömülür (immutable), kalıcı veriler (uploads) EFS'e mount edilir.
FROM wordpress:6.8-php8.3-apache

# ElastiCache (Memcached) için PHP memcached eklentisi + yardımcı araçlar
RUN apt-get update && apt-get install -y --no-install-recommends \
        libmemcached-dev \
        zlib1g-dev \
        libssl-dev \
        unzip \
        less \
        default-mysql-client \
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

# Mağaza için eklenti ve temalar: WooCommerce, Storefront,
# Memcached object cache drop-in (Memcached Redux)
RUN set -eux; \
    curl -fsSL -o /tmp/woocommerce.zip \
        https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip; \
    unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins/; \
    curl -fsSL -o /tmp/storefront.zip \
        https://downloads.wordpress.org/theme/storefront.latest-stable.zip; \
    unzip -q /tmp/storefront.zip -d /usr/src/wordpress/wp-content/themes/; \
    curl -fsSL -o /tmp/memcached-redux.zip \
        https://downloads.wordpress.org/plugin/memcached-redux.latest-stable.zip; \
    unzip -q /tmp/memcached-redux.zip -d /tmp/; \
    mkdir -p /usr/src/extras; \
    cp /tmp/memcached-redux/object-cache.php /usr/src/extras/object-cache.php; \
    rm -rf /tmp/*.zip /tmp/memcached-redux

# Özel tema, mu-plugin'ler ve ortam değişkeni tabanlı wp-config
COPY wp-content/ /usr/src/wordpress/wp-content/
COPY wp-config/wp-config.php /usr/src/wordpress/wp-config.php
COPY docker/health.php /usr/src/wordpress/health.php
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-overrides.ini

COPY docker/entrypoint.sh /usr/local/bin/shop-entrypoint.sh
RUN chmod +x /usr/local/bin/shop-entrypoint.sh \
    && chown -R www-data:www-data /usr/src/wordpress

ENTRYPOINT ["shop-entrypoint.sh"]
CMD ["apache2-foreground"]
