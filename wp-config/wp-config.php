<?php
/**
 * Ortam değişkeni tabanlı WordPress yapılandırması.
 * Tüm değerler ECS task definition'daki environment / secrets bloklarından gelir.
 * Sırlar (DB şifresi, salt'lar) Secrets Manager üzerinden "secrets" olarak verilmelidir.
 */

// ---------------------------------------------------------------------------
// ALB / CloudFront arkasında HTTPS ve host algılama
// ---------------------------------------------------------------------------
// CloudFront origin'e HTTP ile bağlandığında (ALB'de sertifika yoksa) ALB
// "X-Forwarded-Proto: http" yazar — viewer HTTPS kullansa bile. Bu yüzden
// CloudFront'un kendi başlığına da bakıyoruz.
// Not: En sağlam çözüm WP_HOME'u https:// olarak vermektir (aşağıya bakın).
$shop_forwarded_proto = strtolower(
    $_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']
        ?? $_SERVER['HTTP_X_FORWARDED_PROTO']
        ?? ''
);
if ($shop_forwarded_proto === 'https') {
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

// RDS MariaDB 11.8'in varsayılan parametre grubu require_secure_transport=1 ile
// gelir; sunucu TLS kullanmayan bağlantıları reddeder ve WordPress "Error
// establishing a database connection" verir. mysqli'yi TLS'e zorluyoruz.
//
// Sunucu sertifikası doğrulanmıyor: imajda RDS CA bundle'ı yok. Trafik yine de
// şifreli. Tam doğrulama için CA bundle'ını imaja ekleyip MYSQLI_CLIENT_SSL'i
// tek başına kullanın.
//
// Yerel geliştirmede kapatmak için: WORDPRESS_DB_SSL=0
if (shop_env('WORDPRESS_DB_SSL', '1') === '1') {
    define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
}

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
//
// MEMCACHED_HOST virgülle ayrılmış birden fazla node alabilir:
//   node-1.xxx.cache.amazonaws.com:11211,node-2.xxx.cache.amazonaws.com:11211
// Port yazılmayan node'lar için MEMCACHED_PORT (varsayılan 11211) kullanılır.
//
// Neden config endpoint yerine node listesi: PHP'nin memcached eklentisi
// ElastiCache auto-discovery bilmez, config endpoint'i sıradan tek bir node
// sanar. Tek node'luk cluster'da fark etmez, çok node'da tüm trafik tek node'a
// gider. Node listesi bunu çözer; imajdaki drop-in de consistent hashing ile
// yamalı olduğu için node değişiminde anahtarların tamamı taşınmaz.
// ---------------------------------------------------------------------------
$memcached_host = shop_env('MEMCACHED_HOST');
if ($memcached_host !== '') {
    define('WP_CACHE', true);

    $memcached_port = shop_env('MEMCACHED_PORT', '11211');

    // MEMCACHED_TLS=1 iken entrypoint her node için bir stunnel tüneli açar —
    // PHP'nin memcached eklentisinin TLS desteği yok, ElastiCache'te in-transit
    // encryption (ve Serverless) ancak böyle kullanılabiliyor.
    // Eşleme deterministik: i. node -> 127.0.0.1:(11211+i). Aynı hesap
    // docker/entrypoint.sh içinde de yapılıyor, ikisi birlikte değişmeli.
    $memcached_tls = shop_env('MEMCACHED_TLS', '0') === '1';

    $memcached_nodes = [];
    foreach (explode(',', $memcached_host) as $node) {
        $node = trim($node);
        if ($node === '') {
            continue;
        }
        $memcached_nodes[] = $memcached_tls
            ? '127.0.0.1:' . (11211 + count($memcached_nodes))
            : (strpos($node, ':') === false ? $node . ':' . $memcached_port : $node);
    }
    $GLOBALS['memcached_servers'] = $memcached_nodes;

    // Cache anahtarı öneki. İki işe yarıyor:
    //  1) Aynı cluster'ı paylaşan ortamlar (prod/staging) birbirinin
    //     anahtarlarını okumaz.
    //  2) Şema değiştiren bir sürümde WP_CACHE_SALT'ı değiştirerek (örn. imaj
    //     etiketi) tüm cache'i tek hamlede geçersiz kılabilirsiniz — bunun
    //     bedeli deploy sonrası soğuk cache'tir, bilinçli kullanın.
    // Memcached anahtar sınırı 250 byte olduğu için salt'ı kısa tutun.
    $cache_salt = shop_env('WP_CACHE_SALT');
    if ($cache_salt === '') {
        $salt_home  = shop_env('WP_HOME');
        $cache_salt = $salt_home !== ''
            ? (parse_url($salt_home, PHP_URL_HOST) ?: $salt_home)
            : 'shop';
    }
    define('WP_CACHE_KEY_SALT', rtrim(preg_replace('/\s+/', '', $cache_salt), ':') . ':');
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
// S3 medya offload (WP Offload Media Lite)
// ---------------------------------------------------------------------------
// Medya dosyaları yüklendikleri anda S3'e kopyalanır, URL'ler S3/CloudFront'a
// yönlendirilir. Böylece statik içerik PHP konteynerine ve EFS'e hiç uğramaz:
// EFS depolama/throughput maliyeti düşer, dosyalar tüm task'larda tutarlı olur.
//
// Kimlik doğrulama ECS task role üzerinden yapılır (use-server-roles) — imajda
// veya ortam değişkenlerinde erişim anahtarı tutulmaz. Lokalde anahtarla test
// etmek için AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY yeterlidir; AWS SDK'nın
// varsayılan zinciri ikisini de çözer.
//
// WP_S3_BUCKET tanımlı değilse eklenti hiç yapılandırılmaz (mu-plugin de onu
// aktif etmez) — yani lokal geliştirme etkilenmez.
$s3_bucket = shop_env('WP_S3_BUCKET');
if ($s3_bucket !== '') {
    // Teslimat alanı: ayrı bir medya dağıtımı yoksa sitenin CloudFront alanı.
    // Not: aynı dağıtımı kullanıyorsanız CloudFront'ta /wp-content/uploads/*
    // için S3 origin'ine giden bir cache behavior tanımlı olmalı.
    $s3_domain = shop_env('WP_S3_DELIVERY_DOMAIN', shop_env('WP_CDN_URL'));
    if ($s3_domain !== '') {
        $s3_domain = parse_url($s3_domain, PHP_URL_HOST) ?: trim($s3_domain, '/');
    }

    define('AS3CF_SETTINGS', serialize([
        'provider'               => 'aws',
        'use-server-roles'       => true,           // ECS task role / instance profile
        'bucket'                 => $s3_bucket,
        'region'                 => shop_env('WP_S3_REGION', shop_env('AWS_REGION', 'eu-west-1')),
        'copy-to-s3'             => true,           // Yeni yüklemeleri S3'e gönder
        'serve-from-s3'          => true,           // URL'leri S3/CDN'e yeniden yaz
        'enable-object-prefix'   => true,
        'object-prefix'          => shop_env('WP_S3_OBJECT_PREFIX', 'wp-content/uploads/'),
        'use-yearmonth-folders'  => true,
        'object-versioning'      => false,          // CloudFront invalidation'a gerek kalmasın isterseniz true
        // Offload sonrası yerel kopyayı sil — EFS'in şişmesini engeller.
        // Yerel kopyayı korumak için WP_S3_REMOVE_LOCAL=0
        'remove-local-file'      => shop_env('WP_S3_REMOVE_LOCAL', '1') === '1',
        'force-https'            => true,
        'delivery-provider'      => $s3_domain !== '' ? 'aws' : 'storage',
        'enable-delivery-domain' => $s3_domain !== '',
        'delivery-domain'        => $s3_domain,
    ]));
}

// ---------------------------------------------------------------------------
// Konteyner ortamı için sertleştirme
// ---------------------------------------------------------------------------
// Eklenti/tema kurulumu wp-admin üzerinden yapılabilsin diye doğrudan dosya
// sistemi kullanılır (FTP sorulmaz). Kod dizinleri www-data'ya aittir.
define('FS_METHOD', 'direct');
// Dosya editörü kapalı: panelden PHP düzenlemek konteynerde kalıcı değildir
// ve saldırı yüzeyini büyütür. Eklenti mağazasından kurulum bundan etkilenmez.
define('DISALLOW_FILE_EDIT', true);
// Eklenti/tema kurulumu ve güncellemesi varsayılan olarak AÇIK.
// Tamamen kilitlemek için WORDPRESS_DISALLOW_FILE_MODS=1
define('DISALLOW_FILE_MODS', shop_env('WORDPRESS_DISALLOW_FILE_MODS', '0') === '1');
// Arka plan otomatik güncellemeleri kapalı (güncellemeler CI/CD ile imaj
// üzerinden yapılır); panelden manuel "Güncelle" yine çalışır.
// Otomatik güncellemeleri açmak için WORDPRESS_AUTOMATIC_UPDATES=1
define('AUTOMATIC_UPDATER_DISABLED', shop_env('WORDPRESS_AUTOMATIC_UPDATES', '0') !== '1');

define('WP_DEBUG', shop_env('WORDPRESS_DEBUG', '0') === '1');
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false); // Loglar stdout/stderr üzerinden CloudWatch'a akar

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
