<?php
/**
 * Ortam değişkeni tabanlı WordPress yapılandırması.
 * Tüm değerler ECS task definition'daki environment / secrets bloklarından gelir.
 * Sırlar (DB şifresi, salt'lar) Secrets Manager üzerinden "secrets" olarak verilmelidir.
 */

// ---------------------------------------------------------------------------
// ALB / CloudFront arkasında HTTPS ve host algılama
// ---------------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}

/** Ortam değişkenini oku, yoksa varsayılanı döndür. */
function shop_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

// ---------------------------------------------------------------------------
// Veritabanı (RDS)
// ---------------------------------------------------------------------------
define('DB_NAME',     shop_env('WORDPRESS_DB_NAME', 'wordpress'));
define('DB_USER',     shop_env('WORDPRESS_DB_USER', 'wordpress'));
define('DB_PASSWORD', shop_env('WORDPRESS_DB_PASSWORD'));
define('DB_HOST',     shop_env('WORDPRESS_DB_HOST', 'db:3306'));
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');

$table_prefix = shop_env('WORDPRESS_TABLE_PREFIX', 'wp_');

// ---------------------------------------------------------------------------
// Güvenlik anahtarları — üretimde Secrets Manager'dan geçirin!
// Yenilerini üretmek için: https://api.wordpress.org/secret-key/1.1/salt/
// ---------------------------------------------------------------------------
define('AUTH_KEY',         shop_env('WORDPRESS_AUTH_KEY',         'degistir-beni-auth-key'));
define('SECURE_AUTH_KEY',  shop_env('WORDPRESS_SECURE_AUTH_KEY',  'degistir-beni-secure-auth-key'));
define('LOGGED_IN_KEY',    shop_env('WORDPRESS_LOGGED_IN_KEY',    'degistir-beni-logged-in-key'));
define('NONCE_KEY',        shop_env('WORDPRESS_NONCE_KEY',        'degistir-beni-nonce-key'));
define('AUTH_SALT',        shop_env('WORDPRESS_AUTH_SALT',        'degistir-beni-auth-salt'));
define('SECURE_AUTH_SALT', shop_env('WORDPRESS_SECURE_AUTH_SALT', 'degistir-beni-secure-auth-salt'));
define('LOGGED_IN_SALT',   shop_env('WORDPRESS_LOGGED_IN_SALT',   'degistir-beni-logged-in-salt'));
define('NONCE_SALT',       shop_env('WORDPRESS_NONCE_SALT',       'degistir-beni-nonce-salt'));

// ---------------------------------------------------------------------------
// ElastiCache Memcached (object cache)
// MEMCACHED_HOST tanımlıysa entrypoint object-cache.php drop-in'ini yerleştirir.
// ---------------------------------------------------------------------------
$memcached_host = shop_env('MEMCACHED_HOST');
if ($memcached_host !== '') {
    define('WP_CACHE', true);
    $GLOBALS['memcached_servers'] = [
        $memcached_host . ':' . shop_env('MEMCACHED_PORT', '11211'),
    ];
}

// ---------------------------------------------------------------------------
// Site URL'leri — CloudFront alan adınızı WP_HOME olarak verin
// ---------------------------------------------------------------------------
$wp_home = shop_env('WP_HOME');
if ($wp_home !== '') {
    define('WP_HOME',    $wp_home);
    define('WP_SITEURL', shop_env('WP_SITEURL', $wp_home));
}

// ---------------------------------------------------------------------------
// Konteyner ortamı için sertleştirme
// ---------------------------------------------------------------------------
define('FS_METHOD', 'direct');
define('DISALLOW_FILE_EDIT', true);
// Üretimde eklenti/tema kurulumunu da kapatmak için WORDPRESS_DISALLOW_FILE_MODS=1
define('DISALLOW_FILE_MODS', shop_env('WORDPRESS_DISALLOW_FILE_MODS', '0') === '1');
define('AUTOMATIC_UPDATER_DISABLED', true); // Güncellemeler CI/CD ile imaj üzerinden yapılır

define('WP_DEBUG', shop_env('WORDPRESS_DEBUG', '0') === '1');
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false); // Loglar stdout/stderr üzerinden CloudWatch'a akar

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
