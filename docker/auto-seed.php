<?php
/**
 * Konteyner açılışında mağazayı hazırlar (SHOP_AUTO_SEED=1 iken entrypoint
 * arka planda başlatır; Apache beklemez):
 *
 *  1. WordPress kurulu değilse Türkçe kurar, WooCommerce'i ve shopdemo
 *     temasını etkinleştirir.
 *  2. Seed kiti bu veritabanında hiç çalışmadıysa ya da imajdaki kit
 *     değiştiyse (/usr/src/shop-seed/.version) seed'i çalıştırır.
 *
 * Birden fazla task aynı anda açılır; yalnızca veritabanında GET_LOCK alan
 * çalışır, diğerleri bir şey yapmadan çıkar. Seed idempotent olduğu için
 * yarıda kalan bir çalışma sonraki açılışta tamamlanır.
 *
 * Ortam: WP_HOME, WORDPRESS_DB_*, WORDPRESS_ADMIN_PASSWORD (zorunlu),
 * WORDPRESS_ADMIN_USER (admin), WORDPRESS_ADMIN_EMAIL, SHOP_SEED_ARGS
 * (seed.php'ye ek argümanlar, örn. "orders=500").
 */

const SHOP_WP_PATH   = '/var/www/html';
const SHOP_SEED_DIR  = '/usr/src/shop-seed';
const SHOP_LOCK_NAME = 'shop_auto_seed';

function shop_log(string $msg): void
{
    fwrite(STDOUT, '[auto-seed] ' . $msg . "\n");
}

function shop_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/** wp-cli'ı çalıştırır; çıktı konteyner loguna akar. $stdin verilirse komuta yazılır. */
function shop_wp(array $args, ?string $stdin = null): int
{
    $cmd  = 'wp --path=' . SHOP_WP_PATH . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($proc)) {
        return 1;
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    return proc_close($proc);
}

/** wp-cli'ın çıktısını döndürür (hata durumunda null). */
function shop_wp_output(array $args): ?string
{
    $cmd = 'wp --path=' . SHOP_WP_PATH . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
    exec($cmd, $out, $rc);
    return $rc === 0 ? trim(implode("\n", $out)) : null;
}

/** wp-config.php ile aynı ayarlarla (TLS dahil) veritabanına bağlanır. */
function shop_db_connect(): ?mysqli
{
    [$host, $port] = array_pad(explode(':', shop_env('WORDPRESS_DB_HOST', 'db:3306'), 2), 2, '3306');
    $flags = shop_env('WORDPRESS_DB_SSL', '1') === '1'
        ? MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT
        : 0;

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    $ok = @$db->real_connect($host, shop_env('WORDPRESS_DB_USER', 'wordpress'), shop_env('WORDPRESS_DB_PASSWORD'),
        shop_env('WORDPRESS_DB_NAME', 'wordpress'), (int) $port, null, $flags);
    return $ok ? $db : null;
}

// wp-cli, www-data'nın ev dizinine (/var/www, root'a ait) önbellek yazamıyor
putenv('WP_CLI_CACHE_DIR=/tmp/wp-cli-cache');

$admin_password = shop_env('WORDPRESS_ADMIN_PASSWORD');
if ($admin_password === '' || shop_env('WP_HOME') === '') {
    shop_log('WORDPRESS_ADMIN_PASSWORD ve WP_HOME gerekli; otomatik kurulum atlandı.');
    exit(0);
}

// Resmi entrypoint WordPress dosyalarını /var/www/html'e kopyalayana dek bekle
for ($i = 0; $i < 60 && !is_file(SHOP_WP_PATH . '/wp-includes/version.php'); $i++) {
    sleep(2);
}

// İlk deploy'da veritabanı sunucusu konteynerden sonra hazır olabilir
$db = null;
for ($i = 0; $i < 60 && !($db = shop_db_connect()); $i++) {
    if ($i === 0) {
        shop_log('Veritabanı bekleniyor...');
    }
    sleep(10);
}
if (!$db) {
    shop_log('Veritabanına 10 dakikada bağlanılamadı; sonraki açılışta tekrar denenecek.');
    exit(1);
}

$res  = $db->query("SELECT GET_LOCK('" . SHOP_LOCK_NAME . "', 0)");
$lock = $res ? ($res->fetch_row()[0] ?? null) : null;
if ((string) $lock !== '1') {
    shop_log('Başka bir task kurulumu yürütüyor; bu task atlıyor.');
    exit(0);
}

if (shop_wp(['core', 'is-installed']) !== 0) {
    shop_log('WordPress kuruluyor (tr_TR)');
    $rc = shop_wp([
        'core', 'install',
        '--url=' . shop_env('WP_HOME'),
        '--title=Yerel Market',
        '--locale=tr_TR',
        '--admin_user=' . shop_env('WORDPRESS_ADMIN_USER', 'admin'),
        '--admin_email=' . shop_env('WORDPRESS_ADMIN_EMAIL', 'demo@example.com'),
        '--skip-email',
        '--prompt=admin_password', // parola komut satırında (ps) görünmesin
    ], $admin_password . "\n");
    if ($rc !== 0) {
        shop_log('wp core install başarısız.');
        exit(1);
    }
    // Mağaza sayfaları WooCommerce etkinleşirken sitenin dilinde oluşturulur
    shop_wp(['site', 'switch-language', 'tr_TR']);
}
if (shop_wp_output(['plugin', 'is-active', 'woocommerce']) === null) {
    shop_wp(['plugin', 'activate', 'woocommerce']);
}
if (shop_wp_output(['option', 'get', 'stylesheet']) !== 'shopdemo') {
    shop_wp(['theme', 'activate', 'shopdemo']);
}

$want = trim((string) @file_get_contents(SHOP_SEED_DIR . '/.version'));
$have = shop_wp_output(['option', 'get', 'shop_seed_version']);
if ($want !== '' && $have === $want) {
    shop_log("Seed kiti zaten uygulanmış ($want).");
    exit(0);
}

shop_log('Seed çalışıyor (' . ($have ?: 'ilk kez') . ' -> ' . ($want ?: 'sürüm yok') . ')');
$seed_args = preg_split('/\s+/', shop_env('SHOP_SEED_ARGS'), -1, PREG_SPLIT_NO_EMPTY);
if (shop_wp(array_merge(['eval-file', SHOP_SEED_DIR . '/seed.php'], $seed_args)) !== 0) {
    shop_log('Seed başarısız; sonraki açılışta tekrar denenecek.');
    exit(1);
}
if ($want !== '') {
    shop_wp(['option', 'update', 'shop_seed_version', $want]);
}
shop_log('Mağaza hazır.');
