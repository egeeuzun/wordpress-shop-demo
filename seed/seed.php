<?php
/**
 * Yerel Market — demo mağaza seed'i (Faz 2: WooCommerce'i demo verilerle doldur):
 * katalog, müşteriler, siparişler ve ürün değerlendirmeleri.
 *
 * İmaja /usr/src/shop-seed/ altında gömülüdür; WP-CLI ile konteyner içinden
 * çalışır (lokalde docker compose exec, AWS'de ECS Exec). REST API anahtarı
 * gerekmez, dış servise istek atmaz (ürün fotoğrafları repoda: seed/photos):
 *
 *   wp eval-file /usr/src/shop-seed/seed.php [dry-run] [orders=300] [customers=150]
 *                [seed=2026] [no-images] [reset-stock] [customer-pass=demo123]
 *
 * WP-CLI eval-file `--bayrak` kabul etmediği için argümanlar `anahtar=değer`.
 *
 * TÜM VERİLER SENTETİKTİR:
 *  - Müşteriler rastgele isim/adres; e-postalar @example.com (RFC 2606, rezerve).
 *  - TCKN'ler sağlama algoritmasına uyan ama RASTGELE üretilmiş değerlerdir;
 *    gerçek bir kişiyle ilişkilendirilmemiştir. Siparişte `_billing_tckn`,
 *    kullanıcıda `billing_tckn` meta'sında durur.
 *  - Ödeme yalnızca Kapıda Ödeme / Havale; kart verisi yok.
 *  - Değerlendirmeleri ürünü satın alan müşteriler yazar; IP'ler RFC 5737
 *    belgeleme aralıklarından (wp_comments.comment_author_IP).
 *
 * İdempotent: ürünler SKU'ya, müşteriler e-postaya, siparişler ve
 * değerlendirmeler `_lz_seed_ref` meta'sına göre eşlenir; tekrar çalıştırınca çoğaltmaz. Aynı `seed` değeri
 * aynı müşteri/sipariş planını üretir (tarihler çalıştırma anına göredir).
 * Var olan ürünlerin katalog alanları CSV'den güncellenir, stok korunur
 * (siparişler stok düşürdüğü için); CSV stoğuna dönmek için `reset-stock`.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Bu dosya WP-CLI ile çalıştırılır: wp eval-file seed.php\n");
}
if (!class_exists('WooCommerce')) {
    WP_CLI::error('WooCommerce aktif değil. Önce: wp plugin activate woocommerce');
}

// ---------------------------------------------------------------------------
// Sentetik veri sözlükleri
// ---------------------------------------------------------------------------
const LZSEED_FEMALE = ['Ayşe', 'Fatma', 'Elif', 'Zeynep', 'Emine', 'Hatice', 'Merve', 'Büşra', 'Esra',
    'Selin', 'Derya', 'Gizem', 'Melike', 'Ceren', 'Ece', 'Sena', 'Defne', 'Nazlı', 'İrem', 'Özge',
    'Burcu', 'Dilek', 'Gamze', 'Tuğba', 'Yasemin', 'Pınar', 'Seda', 'Hande', 'Aslı', 'Ebru'];
const LZSEED_MALE = ['Mehmet', 'Mustafa', 'Ahmet', 'Ali', 'Hüseyin', 'Hasan', 'İbrahim', 'Murat', 'Emre',
    'Burak', 'Can', 'Kerem', 'Mert', 'Oğuz', 'Serkan', 'Tolga', 'Uğur', 'Yusuf', 'Ömer', 'Eren',
    'Barış', 'Cem', 'Deniz', 'Kaan', 'Onur', 'Gökhan', 'Volkan', 'Selim', 'Tarık', 'Berk'];
const LZSEED_SURNAMES = ['Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Yıldırım', 'Öztürk',
    'Aydın', 'Özdemir', 'Arslan', 'Doğan', 'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan',
    'Şimşek', 'Polat', 'Özcan', 'Korkmaz', 'Çakır', 'Karaca', 'Yavuz', 'Can', 'Acar', 'Şen', 'Aktaş',
    'Güler', 'Yalçın', 'Güneş', 'Bozkurt', 'Bulut', 'Keskin', 'Ünal', 'Turan', 'Gül', 'Özer', 'Işık',
    'Kaplan', 'Avcı', 'Sarı', 'Tekin', 'Taş', 'Köse', 'Yüksel', 'Ateş', 'Aksoy'];
// İstanbul ilçeleri ve ilçe merkezinin posta kodu (sokak düzeyinde doğruluk iddiası yok)
const LZSEED_DISTRICTS = ['Adalar' => '34970', 'Arnavutköy' => '34275', 'Ataşehir' => '34750',
    'Avcılar' => '34310', 'Bağcılar' => '34200', 'Bahçelievler' => '34180', 'Bakırköy' => '34140',
    'Başakşehir' => '34480', 'Bayrampaşa' => '34030', 'Beşiktaş' => '34330', 'Beykoz' => '34820',
    'Beylikdüzü' => '34520', 'Beyoğlu' => '34420', 'Büyükçekmece' => '34500', 'Çatalca' => '34540',
    'Çekmeköy' => '34782', 'Esenler' => '34230', 'Esenyurt' => '34510', 'Eyüpsultan' => '34050',
    'Fatih' => '34080', 'Gaziosmanpaşa' => '34245', 'Güngören' => '34160', 'Kadıköy' => '34710',
    'Kağıthane' => '34410', 'Kartal' => '34860', 'Küçükçekmece' => '34290', 'Maltepe' => '34840',
    'Pendik' => '34890', 'Sancaktepe' => '34785', 'Sarıyer' => '34450', 'Silivri' => '34570',
    'Sultanbeyli' => '34920', 'Sultangazi' => '34265', 'Şile' => '34980', 'Şişli' => '34360',
    'Tuzla' => '34940', 'Ümraniye' => '34760', 'Üsküdar' => '34660', 'Zeytinburnu' => '34020'];
const LZSEED_NEIGHBOURHOODS = ['Cumhuriyet', 'Atatürk', 'Fatih', 'Yeni', 'Merkez', 'İstiklal', 'Gazi',
    'Barbaros', 'Yıldız', 'Çamlık', 'Hürriyet', 'Mimar Sinan', 'Yavuz Selim', 'Zafer', 'Esentepe'];
const LZSEED_STREETS = ['Lale', 'Gül', 'Menekşe', 'Papatya', 'Çınar', 'Akasya', 'Ihlamur', 'Nergis',
    'Söğüt', 'Defne', 'Karanfil', 'Manolya', 'Leylak', 'Zambak', 'Erguvan'];
const LZSEED_NOTES = ['Zili çalmayın, lütfen telefonla arayın.', 'Kapıcıya bırakabilirsiniz.',
    'Poşetsiz teslim edilsin lütfen.', "Akşam 19:00'dan sonra teslim edin.",
    'Domatesler çok olgun olmasın.', 'Kapıda kartla ödeyeceğim.', 'Bozuk para yok, üstünü getirin lütfen.',
    'Apartmanın arka kapısını kullanın.'];
// Ürün değerlendirmeleri, puana göre. Kişisel bilgi (isim, adres, telefon) içermez.
const LZSEED_REVIEWS = [
    5 => ['Çok memnun kaldım, tekrar alacağım.', 'Fiyatına göre gayet kaliteli.', 'Paketleme özenliydi, teşekkürler.',
        'Her hafta alıyorum, hiç sorun yaşamadım.', 'Beklediğimden iyi çıktı.', 'Teslimat hızlıydı, ürün sorunsuz.',
        'Tam aradığım gibi.', 'Ailece çok beğendik.', 'Kalitesi her seferinde aynı, güvenle alıyorum.',
        'Market rafındakinden daha taze geldi.', 'Kesinlikle tavsiye ederim.', 'Fiyat/performans ürünü.'],
    4 => ['Güzel ama biraz pahalı.', 'Kaliteli, sadece paketi biraz ezik geldi.', 'Genel olarak memnunum.',
        'İyi ürün, bir dahakine daha büyük boyunu alacağım.', 'Teslimat biraz gecikti ama ürün iyi.',
        'Beğendim, indirimdeyken almak daha mantıklı.', 'Gayet iyi, küçük bir eksiği var ama idare eder.',
        'Önceki aldığım markadan daha iyi.'],
    3 => ['İdare eder, beklentimi tam karşılamadı.', 'Ortalama bir ürün.', 'Fena değil ama daha iyisini denedim.',
        'Bu sefer öncekiler kadar iyi değildi.', 'Fiyatına göre normal.', 'Kötü değil, tekrar alır mıyım bilmiyorum.'],
    2 => ['Beklediğim gibi değildi.', 'Son kullanma tarihine çok az kalmıştı.', 'Paket hasarlı geldi.',
        'Fotoğraftakinden farklı geldi.', 'Bu fiyata daha iyisini bulmak mümkün.'],
    1 => ['Bozuk geldi, kullanamadım.', 'Ezilmiş geldi, hiç memnun kalmadım.', 'Ürün eksik geldi.',
        'Bir daha almayacağım.'],
];
// 4–5 yıldızlı değerlendirmelere eklenen kategoriye özgü cümleler
const LZSEED_REVIEW_DETAILS = [
    'meyve-sebze'     => ['Tazeydi, iki gün sonra bile ilk günkü gibiydi.', 'Tadı tam yerinde, kokusu bile güzel.', 'Boyları birbirine yakın ve temiz geldi.'],
    'sut-kahvaltilik' => ['Kahvaltı sofrasının vazgeçilmezi oldu.', 'Son kullanma tarihi uzun geldi.', 'Kıvamı ve tadı çok iyi.'],
    'et-tavuk'        => ['Soğuk zincir bozulmadan geldi.', 'Yağ oranı az, temiz kesilmiş.', 'Pişince suyunu salmadı.'],
    'temel-gida'      => ['Mutfakta hep bulunduruyorum.', 'Kalitesi tutarlı.', 'Ambalajı ağzı kapanabilir, pratik.'],
    'atistirmalik'    => ['Çocuklar çok sevdi.', 'Film gecesi için ideal.', 'Çok tatlı değil, tam kıvamında.'],
    'icecek'          => ['Soğuk servis edince harika.', 'Şişesi sağlam geldi.', 'Tadı yapay değil.'],
    'temizlik'        => ['Kokusu hoş, iyi temizliyor.', 'Az miktarı yetiyor, idareli.', 'Lekeleri ilk seferde çıkardı.'],
    'kisisel-bakim'   => ['Cildimi kurutmadı.', 'Kokusu hafif ve güzel.', 'Uzun süre idare ediyor.'],
    'bebek'           => ['Bebeğimde pişik yapmadı.', 'Hassas cilde uygun.', 'Kalıbı tam oturuyor.'],
];
// Mağazanın 1–2 yıldızlı değerlendirmelere yanıtı; %s = müşterinin adı
const LZSEED_REVIEW_REPLIES = [
    'Merhaba %s, yaşadığınız sorun için özür dileriz. Bildiriminizi tedarik ekibimize ilettik.',
    'Merhaba %s, geri bildiriminiz için teşekkürler. Bir sonraki siparişinizde bu ürünü özellikle kontrol edeceğiz.',
    'Merhaba %s, bu deneyim bizim standartlarımıza uymuyor. Müşteri hizmetlerimiz sizinle iletişime geçecek.',
];
// Değerlendirmenin gönderildiği tarayıcı (wp_comments.comment_agent)
const LZSEED_AGENTS = [
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 15; SM-A556E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15',
];
// Sepette kategori ağırlığı: taze ürün ve kahvaltılık daha sık alınır
const LZSEED_CATEGORY_WEIGHT = ['meyve-sebze' => 5, 'sut-kahvaltilik' => 5, 'temel-gida' => 4,
    'et-tavuk' => 3, 'icecek' => 3, 'atistirmalik' => 3, 'temizlik' => 2, 'kisisel-bakim' => 2, 'bebek' => 1];
// Fotoğrafı olmayan kategoriler için yedek görsel renkleri (düz renk, metin/marka yok)
const LZSEED_COLORS = ['meyve-sebze' => '#7cb342', 'sut-kahvaltilik' => '#f5f0e1', 'et-tavuk' => '#c0504d',
    'temel-gida' => '#d9a441', 'atistirmalik' => '#e8833a', 'icecek' => '#4a90c2', 'temizlik' => '#5bc0be',
    'kisisel-bakim' => '#b48ec9', 'bebek' => '#f2b5c4'];
const LZSEED_SHIPPING_FLAT = '49.90';
const LZSEED_FREE_SHIPPING_MIN = '750';
const LZSEED_DEMO_NOTICE = 'Demo mağazadır, satış yapılmaz. Ürünler, fiyatlar ve müşteri verileri sentetiktir.';

// ---------------------------------------------------------------------------
// Yardımcılar
// ---------------------------------------------------------------------------
function lzseed_parse_args(array $args): array
{
    $o = [
        'dry-run'       => false,
        'orders'        => 300,
        'customers'     => 150,
        'seed'          => 2026,
        'images'        => true,
        'reset-stock'   => false,
        'customer-pass' => 'demo123',
        'csv'           => '',
    ];
    foreach ($args as $arg) {
        $arg = ltrim($arg, '-');
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, null);
        switch ($key) {
            case 'dry-run':     $o['dry-run'] = true; break;
            case 'no-images':   $o['images'] = false; break;
            case 'reset-stock': $o['reset-stock'] = true; break;
            case 'orders':
            case 'customers':
            case 'seed':
                if ($value === null || !ctype_digit($value)) {
                    WP_CLI::error("$key için sayı bekleniyor: $arg");
                }
                $o[$key] = (int) $value;
                break;
            case 'customer-pass':
            case 'csv':
                $o[$key] = (string) $value;
                break;
            default:
                WP_CLI::error("Bilinmeyen argüman: $arg");
        }
    }
    if ($o['customers'] < 1 || $o['customers'] > 5000 || $o['orders'] > 5000) {
        WP_CLI::error('customers 1–5000, orders 0–5000 arasında olmalı.');
    }
    if ($o['csv'] === '') {
        // eval-file __DIR__'ı dosyanın dizinine çevirir; yine de imaj yolunu yedekle
        $o['csv'] = is_readable(__DIR__ . '/products.csv') ? __DIR__ . '/products.csv' : '/usr/src/shop-seed/products.csv';
    }
    return $o;
}

function lzseed_pick(array $list)
{
    return $list[mt_rand(0, count($list) - 1)];
}

function lzseed_chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

/** 0–1 arası; üs > 1 küçük değerlere yığar (az sayıda sadık müşteri, çok sayıda seyrek müşteri). */
function lzseed_skewed(float $exp): float
{
    return (mt_rand() / mt_getrandmax()) ** $exp;
}

function lzseed_ascii(string $s): string
{
    $s = strtr($s, ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'ö' => 'o',
        'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u']);
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
}

/** Sağlama algoritmasına uyan RASTGELE 11 haneli TCKN (SENTETİK). */
function lzseed_tckn(): string
{
    $d = [mt_rand(1, 9)];
    for ($i = 1; $i < 9; $i++) {
        $d[] = mt_rand(0, 9);
    }
    $odd   = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $even  = $d[1] + $d[3] + $d[5] + $d[7];
    $d[9]  = ((($odd * 7) - $even) % 10 + 10) % 10;
    $d[10] = array_sum($d) % 10;
    return implode('', $d);
}

function lzseed_phone(): string
{
    return sprintf('+90 5%d%d %03d %02d %02d', mt_rand(3, 5), mt_rand(0, 9), mt_rand(0, 999), mt_rand(0, 99), mt_rand(0, 99));
}

/** Rastgele kişi + İstanbul adresi. */
function lzseed_person(): array
{
    $female   = lzseed_chance(0.5);
    $district = lzseed_pick(array_keys(LZSEED_DISTRICTS));
    $address2 = lzseed_chance(0.6) ? sprintf('Kat:%d D:%d', mt_rand(1, 9), mt_rand(1, 24)) : sprintf('D:%d', mt_rand(1, 12));
    return [
        'first_name' => lzseed_pick($female ? LZSEED_FEMALE : LZSEED_MALE),
        'last_name'  => lzseed_pick(LZSEED_SURNAMES),
        'phone'      => lzseed_phone(),
        'tckn'       => lzseed_tckn(),
        'address'    => [
            'address_1' => sprintf('%s Mah. %s Sok. No:%d', lzseed_pick(LZSEED_NEIGHBOURHOODS), lzseed_pick(LZSEED_STREETS), mt_rand(1, 120)),
            'address_2' => $address2,
            'city'      => $district,
            'state'     => 'TR34',
            'postcode'  => LZSEED_DISTRICTS[$district],
            'country'   => 'TR',
        ],
    ];
}

function lzseed_iso(int $ts): string
{
    return wp_date('c', $ts, new DateTimeZone('Europe/Istanbul'));
}

// ---------------------------------------------------------------------------
// Katalog (products.csv — WooCommerce CSV içe aktarıcısının kolon adları)
// ---------------------------------------------------------------------------
function lzseed_read_catalog(string $path): array
{
    $fh = @fopen($path, 'r');
    if (!$fh) {
        WP_CLI::error("Katalog okunamadı: $path");
    }
    $header   = fgetcsv($fh, 0, ',', '"', '');
    $required = ['SKU', 'Name', 'Regular price', 'Categories'];
    if (!$header || array_diff($required, $header)) {
        WP_CLI::error('products.csv başlığı eksik; gerekli kolonlar: ' . implode(', ', $required));
    }
    $rows = [];
    $line = 1;
    while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if ($cells === [null]) {
            continue; // boş satır
        }
        if (count($cells) !== count($header)) {
            WP_CLI::error("products.csv satır $line: kolon sayısı başlıkla uyuşmuyor.");
        }
        $row = array_combine($header, $cells);
        $sku = trim($row['SKU']);
        if ($sku === '' || isset($rows[$sku])) {
            WP_CLI::error("products.csv satır $line: SKU boş ya da tekrarlı ($sku).");
        }
        $row['_categories'] = array_values(array_filter(array_map('trim', explode(',', $row['Categories']))));
        $rows[$sku] = $row;
    }
    fclose($fh);
    if (!$rows) {
        WP_CLI::error('products.csv boş.');
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Plan: DB'ye dokunmadan, yalnızca seed'e bağlı deterministik müşteri/sipariş
// ---------------------------------------------------------------------------
function lzseed_plan(array $catalog, array $o, int $now): array
{
    mt_srand($o['seed']);
    $tz = new DateTimeZone('Europe/Istanbul');

    // Müşteriler. #0 bilinen şifreli demo hesabı (k6 "Hesabım" senaryosu için).
    $customers = [];
    for ($i = 0; $i < $o['customers']; $i++) {
        $p = lzseed_person();
        if ($i === 0) {
            $p['first_name'] = 'Demo';
            $p['last_name']  = 'Müşteri';
            $p['username']   = 'demo';
            $p['email']      = 'demo.musteri@example.com';
        } else {
            $p['username'] = sprintf('%s.%s.%03d', lzseed_ascii($p['first_name']), lzseed_ascii($p['last_name']), $i);
            $p['email']    = $p['username'] . '@example.com';
        }
        $p['ref'] = sprintf('LZ-C-%04d', $i + 1);
        $customers[] = $p;
    }

    // Sepete girebilecek ürünler (stokta olanlar), kategori ağırlığıyla
    $pool = [];
    foreach ($catalog as $sku => $row) {
        if (isset($row['Stock']) && $row['Stock'] !== '' && (int) $row['Stock'] <= 0) {
            continue;
        }
        $slug = sanitize_title($row['_categories'][0] ?? '');
        $pool = array_merge($pool, array_fill(0, LZSEED_CATEGORY_WEIGHT[$slug] ?? 2, $sku));
    }

    $orders = [];
    for ($i = 0; $i < $o['orders']; $i++) {
        // Son 90 gün; hafifçe yakın tarihlere yığılı. Saat 08–23 (İstanbul).
        $days = min(89, (int) floor(90 * lzseed_skewed(1.15)));
        $ts   = (new DateTimeImmutable('today', $tz))
            ->modify("-$days days")
            ->setTime(mt_rand(8, 22), mt_rand(0, 59), mt_rand(0, 59))
            ->getTimestamp();
        if ($ts > $now) {
            $ts = $now - mt_rand(300, 6 * 3600);
        }

        $guest    = lzseed_chance(0.15);
        $customer = $guest ? null : min($o['customers'] - 1, (int) floor($o['customers'] * lzseed_skewed(1.8)));
        $person   = $guest ? lzseed_person() : null;
        if ($guest) {
            $person['email'] = sprintf('%s.%s.m%04d@example.com', lzseed_ascii($person['first_name']), lzseed_ascii($person['last_name']), $i + 1);
        }

        $orders[] = ['ts' => $ts, 'customer' => $customer, 'guest' => $person] + lzseed_plan_basket($pool, $ts, $now);
    }
    usort($orders, fn($a, $b) => $a['ts'] <=> $b['ts']);
    foreach ($orders as $i => &$order) {
        $order['ref'] = sprintf('LZ-S-%04d', $i + 1);
    }
    unset($order);

    // Kayıt tarihi: ilk siparişten 1–120 gün önce (siparişi yoksa son 180 gün)
    $first = [];
    foreach ($orders as $order) {
        if ($order['customer'] !== null && !isset($first[$order['customer']])) {
            $first[$order['customer']] = $order['ts'];
        }
    }
    foreach ($customers as $i => &$c) {
        $c['registered_ts'] = ($first[$i] ?? $now) - mt_rand(1, isset($first[$i]) ? 120 : 180) * DAY_IN_SECONDS;
    }
    unset($c);

    // Yukarıdaki dağılımda sadık müşteriler çok sipariş verir, kuyruktakilerin
    // bir kısmına hiç sipariş düşmez. Onlara kayıttan sonra birer ilk sipariş.
    // Ana plandan sonra üretildiği için önceki siparişler ve referansları
    // değişmez; yeniler LZ-S-<orders+1>'den devam eder.
    if ($o['orders'] > 0) {
        $next = count($orders);
        foreach ($customers as $i => $c) {
            if (isset($first[$i])) {
                continue;
            }
            $ts  = mt_rand($c['registered_ts'] + HOUR_IN_SECONDS, $now - 300);
            $day = (new DateTimeImmutable("@$ts"))->setTimezone($tz)->setTime(mt_rand(8, 22), mt_rand(0, 59), mt_rand(0, 59))->getTimestamp();
            if ($day > $c['registered_ts'] && $day < $now) {
                $ts = $day;
            }
            $orders[] = ['ts' => $ts, 'customer' => $i, 'guest' => null]
                + lzseed_plan_basket($pool, $ts, $now)
                + ['ref' => sprintf('LZ-S-%04d', ++$next)];
        }
        usort($orders, fn($a, $b) => $a['ts'] <=> $b['ts']);
    }

    return ['customers' => $customers, 'orders' => $orders, 'reviews' => lzseed_plan_reviews($catalog, $customers, $orders, $now)];
}

/** Sepet, ödeme yöntemi, sipariş yaşına göre durum ve teslimat notu. */
function lzseed_plan_basket(array $pool, int $ts, int $now): array
{
    $want  = lzseed_pick([1, 2, 2, 3, 3, 3, 4, 4, 4, 5, 5, 6, 7, 8]);
    $items = [];
    for ($try = 0; count($items) < $want && $try < 50; $try++) {
        $sku = lzseed_pick($pool);
        if (!isset($items[$sku])) {
            $items[$sku] = lzseed_pick([1, 1, 1, 1, 2, 2, 3]);
        }
    }

    $payment = lzseed_chance(0.7) ? 'cod' : 'bacs';
    $age     = ($now - $ts) / DAY_IN_SECONDS;
    $roll    = mt_rand(1, 100);
    if ($age < 1) {
        $status = $roll <= 5 ? 'cancelled' : (($payment === 'bacs' && $roll <= 55) ? 'on-hold' : 'processing');
    } elseif ($age < 3) {
        $status = $roll <= 10 ? 'cancelled' : ($roll <= 50 ? 'processing' : 'completed');
    } else {
        $status = $roll <= 12 ? 'cancelled' : 'completed';
    }

    return [
        'items'        => $items,
        'payment'      => $payment,
        'status'       => $status,
        'completed_ts' => $status === 'completed' ? min($now, $ts + mt_rand(3 * 3600, 36 * 3600)) : null,
        'note'         => lzseed_chance(0.12) ? lzseed_pick(LZSEED_NOTES) : '',
    ];
}

/**
 * Ürün değerlendirmeleri: yalnızca tamamlanmış siparişlerden, ürünü gerçekten
 * alan kişi yazar (doğrulanmış alıcı). Kişi başına ürün başına en fazla bir
 * değerlendirme. Puanlar gerçek mağazalardaki gibi olumluya yığılı; 1–2
 * yıldızların çoğuna mağaza yanıt verir. Son bir haftadakilerin bir kısmı
 * onay bekler (wp-admin'de moderasyon kuyruğu boş kalmasın).
 */
function lzseed_plan_reviews(array $catalog, array $customers, array $orders, int $now): array
{
    $reviews = [];
    $seen    = [];
    foreach ($orders as $order) {
        if ($order['status'] !== 'completed') {
            continue;
        }
        $person = $order['customer'] !== null ? $customers[$order['customer']] : $order['guest'];
        foreach ($order['items'] as $sku => $qty) {
            $key = $person['email'] . '|' . $sku;
            if (isset($seen[$key]) || !lzseed_chance($order['customer'] !== null ? 0.25 : 0.08)) {
                continue;
            }
            $ts = $order['completed_ts'] + mt_rand(2 * HOUR_IN_SECONDS, 10 * DAY_IN_SECONDS);
            if ($ts > $now) {
                continue;
            }
            $seen[$key] = true;

            $rating = lzseed_pick([5, 5, 5, 5, 5, 5, 5, 5, 5, 4, 4, 4, 4, 4, 4, 3, 3, 3, 2, 2, 1]);
            $text   = lzseed_pick(LZSEED_REVIEWS[$rating]);
            $slug   = sanitize_title($catalog[$sku]['_categories'][0] ?? '');
            if ($rating >= 4 && isset(LZSEED_REVIEW_DETAILS[$slug]) && lzseed_chance(0.6)) {
                $text .= ' ' . lzseed_pick(LZSEED_REVIEW_DETAILS[$slug]);
            }
            $approved = ($now - $ts < 7 * DAY_IN_SECONDS && lzseed_chance(0.3)) ? 0 : 1;

            $reviews[] = [
                'ref'      => $order['ref'] . '/' . $sku,
                'sku'      => $sku,
                'order'    => $order['ref'],
                'customer' => $order['customer'],
                'author'   => $person['first_name'] . ' ' . mb_substr($person['last_name'], 0, 1) . '.',
                'email'    => $person['email'],
                'rating'   => $rating,
                'text'     => $text,
                'ts'       => $ts,
                'approved' => $approved,
                // RFC 5737 belgeleme aralıkları: gerçek bir istemciye ait olamaz
                'ip'       => lzseed_pick(['192.0.2', '198.51.100', '203.0.113']) . '.' . mt_rand(1, 254),
                'agent'    => lzseed_pick(LZSEED_AGENTS),
                'reply'    => ($approved && $rating <= 2 && lzseed_chance(0.7)) ? [
                    'ts'   => min($now, $ts + mt_rand(2 * HOUR_IN_SECONDS, 2 * DAY_IN_SECONDS)),
                    'text' => sprintf(lzseed_pick(LZSEED_REVIEW_REPLIES), $person['first_name']),
                ] : null,
            ];
        }
    }
    return $reviews;
}

// ---------------------------------------------------------------------------
// Uygulama adımları
// ---------------------------------------------------------------------------
function lzseed_apply_settings(): void
{
    // Dil paketleri imajda (docker/install-translations.sh). Yoksa siteyi
    // çevirisi olmayan bir dile çevirip İngilizce bırakmak yerine uyar.
    if (in_array('tr_TR', get_available_languages(), true)) {
        update_option('WPLANG', 'tr_TR');
    } else {
        WP_CLI::warning('tr_TR dil paketi kurulu değil; site dili değiştirilmedi.');
    }

    $options = [
        'blogname'                                     => 'Yerel Market',
        'blogdescription'                              => LZSEED_DEMO_NOTICE,
        'timezone_string'                              => 'Europe/Istanbul',
        'woocommerce_currency'                         => 'TRY',
        'woocommerce_currency_pos'                     => 'right_space',
        'woocommerce_price_thousand_sep'               => '.',
        'woocommerce_price_decimal_sep'                => ',',
        'woocommerce_price_num_decimals'               => '2',
        'woocommerce_default_country'                  => 'TR:TR34',
        'woocommerce_store_address'                    => 'Örnek Mah. Demo Cad. No:1',
        'woocommerce_store_city'                       => 'Kadıköy',
        'woocommerce_store_postcode'                   => '34710',
        'woocommerce_allowed_countries'                => 'specific',
        'woocommerce_specific_allowed_countries'       => ['TR'],
        'woocommerce_ship_to_countries'                => '',
        'woocommerce_weight_unit'                      => 'kg',
        'woocommerce_manage_stock'                     => 'yes',
        // Fiyatlar KDV dahil gösterilir; vergi hesabı demo kapsamı dışında
        'woocommerce_calc_taxes'                       => 'no',
        'woocommerce_prices_include_tax'               => 'yes',
        'woocommerce_enable_guest_checkout'            => 'yes',
        'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
        // Hesabım sayfasında giriş + üye ol. Demo e-posta göndermediği için
        // parolayı ziyaretçi kendisi belirler (otomatik parola e-postayla gider).
        'woocommerce_enable_myaccount_registration'    => 'yes',
        'woocommerce_registration_generate_username'   => 'yes',
        'woocommerce_registration_generate_password'   => 'no',
        'woocommerce_enable_checkout_login_reminder'   => 'yes',
        // Değerlendirmeler: yıldız zorunlu, yalnızca ürünü satın alan yazabilir
        // (seed'in değerlendirmelerinin hepsi doğrulanmış alıcıdan)
        'woocommerce_enable_reviews'                   => 'yes',
        'woocommerce_enable_review_rating'             => 'yes',
        'woocommerce_review_rating_required'           => 'yes',
        'woocommerce_review_rating_verification_label' => 'yes',
        'woocommerce_review_rating_verification_required' => 'yes',
        // Üst bant: WooCommerce'in yerleşik "demo mağaza" bildirimi
        'woocommerce_demo_store'                       => 'yes',
        'woocommerce_demo_store_notice'                => LZSEED_DEMO_NOTICE,
        // WooCommerce 9.1+ yeni kurulumları "yakında" modunda açar; vitrin görünsün
        'woocommerce_coming_soon'                      => 'no',
        'woocommerce_onboarding_opt_in'                => 'no',
        'woocommerce_task_list_hidden'                 => 'yes',
        'woocommerce_allow_tracking'                   => 'no',
        'woocommerce_show_marketplace_suggestions'     => 'no',
    ];
    foreach ($options as $name => $value) {
        update_option($name, $value);
    }

    // Ödeme: yalnızca offline yöntemler (mu-plugin ayrıca diğerlerini vitrinde gizler)
    $gateways = [
        'cod' => [
            'enabled'            => 'yes',
            'title'              => 'Kapıda Ödeme',
            'description'        => 'Siparişinizi teslim alırken ödeyin. Demo mağaza: tahsilat yapılmaz.',
            'instructions'       => 'Demo sipariştir; teslimat ve tahsilat yapılmayacaktır.',
            'enable_for_methods' => [],
            'enable_for_virtual' => 'yes',
        ],
        'bacs' => [
            'enabled'      => 'yes',
            'title'        => 'Havale / EFT',
            'description'  => 'Demo mağaza: aşağıdaki hesap bilgileri geçersizdir, ödeme göndermeyin.',
            'instructions' => 'Demo sipariştir; ödeme göndermeyin.',
        ],
        'cheque' => ['enabled' => 'no'],
    ];
    foreach ($gateways as $id => $settings) {
        $key = "woocommerce_{$id}_settings";
        update_option($key, array_merge((array) get_option($key, []), $settings));
    }
    // Bilerek geçersiz IBAN (TR00 sağlama basamağı tutmaz) — gerçek havale yapılamaz
    update_option('woocommerce_bacs_accounts', [[
        'account_name'   => 'Yerel Market (DEMO)',
        'account_number' => '0000000',
        'bank_name'      => 'Demo Bankası',
        'sort_code'      => '',
        'iban'           => 'TR00 0000 0000 0000 0000 0000 00',
        'bic'            => '',
    ]]);

    if (get_stylesheet() !== 'shopdemo' && wp_get_theme('shopdemo')->exists()) {
        switch_theme('shopdemo');
    }

    // Kalıcı bağlantılar: option'ı yazmak yetmez, bu istekteki $wp_rewrite de
    // güncellenmeli. Kurallar en sonda lzseed_flush_rewrite() ile yazılır.
    global $wp_rewrite;
    $wp_rewrite->set_permalink_structure('/%postname%/');
}

/**
 * URL kurallarını yeniden yazar. set_permalink_structure() WP_Rewrite::init()
 * ile kayıtlı endpoint'leri siler; WooCommerce'inkiler (orders, view-order,
 * order-received...) yeniden eklenmezse kurallarda yer almaz ve "Hesabım →
 * Siparişler" ile sipariş sonrası teşekkür sayfası 404 verir. Ana sayfa
 * ayarı endpoint maskesini etkilediği için sayfalardan sonra çağrılır.
 */
function lzseed_flush_rewrite(): void
{
    if (isset(WC()->query)) {
        WC()->query->add_endpoints();
    }
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    flush_rewrite_rules(true);

    $orders = get_option('woocommerce_myaccount_orders_endpoint', 'orders');
    $found  = false;
    foreach (array_keys((array) get_option('rewrite_rules')) as $regex) {
        if (str_contains($regex, '/' . $orders . '(/')) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        WP_CLI::warning("URL kurallarında WooCommerce endpoint'leri yok ($orders); hesap sayfaları 404 verebilir. Elle: wp rewrite flush");
    }
}

/** Mağaza sayfaları; checkout/cart klasik shortcode'a çevrilir (TCKN alanı klasik checkout API'siyle eklenir). */
function lzseed_apply_pages(): void
{
    WC_Install::create_pages();
    foreach (['checkout' => '[woocommerce_checkout]', 'cart' => '[woocommerce_cart]'] as $page => $shortcode) {
        $id = wc_get_page_id($page);
        if ($id > 0 && trim((string) get_post_field('post_content', $id)) !== $shortcode) {
            wp_update_post(['ID' => $id, 'post_content' => $shortcode]);
        }
    }

    $home = get_page_by_path('ana-sayfa');
    if (!$home) {
        $home_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'ana-sayfa',
            'post_title'   => 'Ana Sayfa',
            'post_content' => '<p>Yerel Market\'e hoş geldiniz. ' . LZSEED_DEMO_NOTICE . '</p>',
        ]);
    } else {
        $home_id = $home->ID;
    }
    update_post_meta($home_id, '_wp_page_template', 'template-homepage.php'); // Storefront ana sayfa şablonu
    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);
}

function lzseed_apply_shipping(): void
{
    foreach (WC_Shipping_Zones::get_zones() as $zone) {
        if ($zone['zone_name'] === 'Türkiye') {
            return;
        }
    }
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name('Türkiye');
    $zone->add_location('TR', 'country');
    $zone->save();

    $flat = $zone->add_shipping_method('flat_rate');
    update_option("woocommerce_flat_rate_{$flat}_settings", [
        'title' => 'Standart Teslimat', 'tax_status' => 'none', 'cost' => LZSEED_SHIPPING_FLAT,
    ]);
    $free = $zone->add_shipping_method('free_shipping');
    update_option("woocommerce_free_shipping_{$free}_settings", [
        'title' => 'Ücretsiz Teslimat', 'requires' => 'min_amount', 'min_amount' => LZSEED_FREE_SHIPPING_MIN,
    ]);
}

/** @return array<string,int> kategori adı => term_id */
function lzseed_apply_categories(array $catalog): array
{
    $map = [];
    foreach ($catalog as $row) {
        foreach ($row['_categories'] as $name) {
            if (isset($map[$name])) {
                continue;
            }
            $slug = sanitize_title($name);
            $term = get_term_by('slug', $slug, 'product_cat');
            if (!$term) {
                $res = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
                if (is_wp_error($res)) {
                    WP_CLI::error("Kategori oluşturulamadı ($name): " . $res->get_error_message());
                }
                $map[$name] = (int) $res['term_id'];
            } else {
                $map[$name] = (int) $term->term_id;
            }
        }
    }
    return $map;
}

/** seed/photos/manifest.csv: SKU => [query, file, author, license, source_url] */
function lzseed_read_photo_credits(string $dir): array
{
    $file = "$dir/manifest.csv";
    if (!is_readable($file)) {
        return [];
    }
    $fh     = fopen($file, 'r');
    $header = fgetcsv($fh, null, ',', '"', '');
    $rows   = [];
    while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (count($row) === count($header)) {
            $r               = array_combine($header, $row);
            $rows[$r['sku']] = $r;
        }
    }
    fclose($fh);
    return $rows;
}

/**
 * Seed'in yüklediği (değiştirilebilir) bir görsel mi. Elle seçilen ürün
 * görsellerine dokunulmaz; silinmiş bir görsel de değiştirilebilir sayılır.
 */
function lzseed_is_seed_image(int $id): bool
{
    if (!$id || !get_post($id)) {
        return true;
    }
    foreach (['_lz_photo', '_lz_category_image', '_lz_placeholder'] as $key) {
        if (get_post_meta($id, $key, true) !== '') {
            return true;
        }
    }
    return false;
}

/** seed/photos/<SKU>.(jpg|jpeg|png|webp): elle konan fotoğraflar da olur. */
function lzseed_find_photo(string $dir, string $sku): ?string
{
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (is_file("$dir/$sku.$ext")) {
            return "$dir/$sku.$ext";
        }
    }
    return null;
}

/**
 * seed/photos/<SKU>.* dosyasını medya kütüphanesine yükler ya da daha önce
 * yüklenmiş aynı dosyayı döndürür. Dosya değiştiyse yeniden yüklenir, eskisi
 * silinir. İnternete çıkmaz: fotoğraflar repoda durur (scripts/fetch-photos.py).
 */
function lzseed_product_photo(string $sku, string $path, string $title, array $credit): int
{
    $hash     = md5_file($path);
    $existing = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids',
        'meta_key' => '_lz_photo', 'meta_value' => $sku,
    ]);
    $old = $existing ? (int) $existing[0] : 0;
    if ($old && get_post_meta($old, '_lz_photo_hash', true) === $hash) {
        return $old;
    }

    // media_handle_sideload dosyayı taşıdığı için kopyası yüklenir
    $tmp = wp_tempnam(basename($path));
    copy($path, $tmp);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $id  = media_handle_sideload(['name' => strtolower($sku) . ".$ext", 'tmp_name' => $tmp], 0, $title);
    if (is_wp_error($id)) {
        @unlink($tmp);
        WP_CLI::warning("Fotoğraf yüklenemedi ($sku): " . $id->get_error_message());
        return $old;
    }
    update_post_meta($id, '_lz_photo', $sku);
    update_post_meta($id, '_lz_photo_hash', $hash);
    update_post_meta($id, '_wp_attachment_image_alt', $title);
    if (!empty($credit['author'])) {
        wp_update_post(['ID' => $id, 'post_excerpt' => lzseed_photo_caption($credit)]);
        update_post_meta($id, '_lz_photo_source', $credit['source_url'] ?? '');
    }
    if ($old) {
        wp_delete_attachment($old, true);
    }
    return (int) $id;
}

/** "Fotoğraf: Yazar (CC BY-SA 4.0, Wikimedia Commons)" */
function lzseed_photo_caption(array $credit): string
{
    $origin = str_contains($credit['source_url'] ?? '', 'wikimedia.org') ? ', Wikimedia Commons' : '';
    $lic    = trim(($credit['license'] ?? '') . $origin, ', ');
    return 'Fotoğraf: ' . $credit['author'] . ($lic !== '' ? " ($lic)" : '');
}

/**
 * "Görsel kaynakları" sayfası: her ürün fotoğrafının yazarı, lisansı ve
 * kaynağı. CC BY / CC BY-SA lisansları atıf ister; footer bu sayfaya bağlanır.
 */
function lzseed_apply_credits_page(array $catalog, array $credits): void
{
    $items = '';
    foreach ($catalog as $sku => $row) {
        $c = $credits[$sku] ?? [];
        if (empty($c['author'])) {
            continue;
        }
        $items .= sprintf(
            "<li><strong>%s</strong> — %s, %s. <a href=\"%s\" rel=\"nofollow noopener\">Kaynak</a></li>\n",
            esc_html($row['Name']),
            esc_html($c['author']),
            esc_html($c['license'] ?: 'lisans belirtilmemiş'),
            esc_url($c['source_url'] ?? '')
        );
    }
    $page = get_page_by_path('gorsel-kaynaklari');
    if ($items === '') {
        return;
    }
    $content = "<!-- wp:paragraph --><p>Bu demo mağazadaki ürün fotoğrafları serbest lisanslıdır. "
        . "Ürünler ve fotoğraflar arasında gerçek bir marka ilişkisi yoktur.</p><!-- /wp:paragraph -->\n"
        . "<!-- wp:html --><ul class=\"shopdemo-credits\">\n$items</ul><!-- /wp:html -->";
    $data = ['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Görsel kaynakları',
        'post_name' => 'gorsel-kaynaklari', 'post_content' => $content];
    if ($page) {
        $data['ID'] = $page->ID;
        wp_update_post($data);
    } else {
        wp_insert_post($data);
    }
}

/** Hiç fotoğrafı olmayan kategori için düz renkli yedek görsel (bir kez üretilir). */
function lzseed_category_placeholder(string $name): int
{
    $slug     = sanitize_title($name);
    $existing = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids',
        'meta_key' => '_lz_placeholder', 'meta_value' => $slug,
    ]);
    foreach ($existing as $id) {
        if (get_post_meta((int) $id, '_lz_image_type', true) !== 'photo') {
            return (int) $id;
        }
    }
    [$r, $g, $b] = sscanf(LZSEED_COLORS[$slug] ?? '#9e9e9e', '#%02x%02x%02x');
    $img = imagecreatetruecolor(800, 800);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    // Ortada açık tonlu daire: ızgarada düz kareden daha okunur
    imagefilledellipse($img, 400, 400, 420, 420, imagecolorallocatealpha($img, 255, 255, 255, 80));
    $tmp = wp_tempnam("lz-$slug.png");
    imagepng($img, $tmp);
    imagedestroy($img);

    $id = media_handle_sideload(['name' => "lz-placeholder-$slug.png", 'tmp_name' => $tmp], 0, "$name (placeholder)");
    if (is_wp_error($id)) {
        @unlink($tmp);
        WP_CLI::warning("Placeholder yüklenemedi ($name): " . $id->get_error_message());
        return 0;
    }
    update_post_meta($id, '_lz_placeholder', $slug);
    update_post_meta($id, '_lz_image_type', 'placeholder');
    return (int) $id;
}

/**
 * Ürün ve kategori görselleri:
 *  - seed/photos/<SKU>.jpg (ya da .jpeg/.png/.webp) varsa ürünün kendi fotoğrafı olur.
 *  - Kategori kapağı, kategorideki ilk fotoğraflı ürünün fotoğrafıdır; hiç yoksa düz renk.
 *  - Fotoğrafı olmayan ürün kategori kapağını kullanır.
 *  - Elle değiştirilmiş ürün görselleri korunur.
 *  - Eski sürümün Picsum'dan indirdiği rastgele kategori fotoğrafları silinir.
 */
function lzseed_apply_images(array $catalog, array $products, array $categories, array &$stats): void
{
    if (!function_exists('imagecreatetruecolor')) {
        WP_CLI::warning('GD yok; görseller atlanıyor.');
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $dir     = __DIR__ . '/photos';
    $credits = lzseed_read_photo_credits($dir);

    $photos = [];
    foreach ($catalog as $sku => $row) {
        $path = lzseed_find_photo($dir, $sku);
        if ($path !== null) {
            $photos[$sku] = lzseed_product_photo($sku, $path, $row['Name'], $credits[$sku] ?? []);
            if (count($photos) % 10 === 0) {
                WP_CLI::log('    fotoğraflar: ' . count($photos));
            }
        }
    }
    if (!$photos) {
        WP_CLI::warning('seed/photos altında ürün fotoğrafı yok; düz renkli görseller kullanılıyor. Bkz. scripts/fetch-photos.py');
    }

    $covers = [];
    foreach ($categories as $name => $term_id) {
        foreach ($catalog as $sku => $row) {
            if (($row['_categories'][0] ?? '') === $name && !empty($photos[$sku])) {
                $covers[$name] = $photos[$sku];
                break;
            }
        }
        $covers[$name] ??= lzseed_category_placeholder($name);
        if ($covers[$name]) {
            update_term_meta($term_id, 'thumbnail_id', $covers[$name]); // Storefront "Kategoriler" bölümü
        }
    }

    foreach ($products as $sku => $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }
        $want    = $photos[$sku] ?? ($covers[$catalog[$sku]['_categories'][0] ?? ''] ?? 0);
        $current = (int) $product->get_image_id();
        if ($want && $want !== $current && lzseed_is_seed_image($current)) {
            $product->set_image_id($want);
            $product->save();
            $stats['images_set']++;
        }
    }

    lzseed_apply_credits_page($catalog, $credits);

    // Picsum'dan gelen eski rastgele fotoğraflar artık hiçbir yerde kullanılmıyor
    $stale = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids',
        'meta_key' => '_lz_image_type', 'meta_value' => 'photo',
    ]);
    foreach ($stale as $id) {
        wp_delete_attachment((int) $id, true);
    }
}

/** @return array<string,int> SKU => ürün id */
function lzseed_apply_products(array $catalog, array $categories, bool $reset_stock, array &$stats): array
{
    $ids = [];
    foreach ($catalog as $sku => $row) {
        $id      = wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : new WC_Product_Simple();
        if (!$product) {
            WP_CLI::warning("SKU $sku okunamadı, atlanıyor.");
            continue;
        }
        try {
            if (!$id) {
                $product->set_sku($sku);
            }
            $product->set_name($row['Name']);
            $product->set_status(($row['Published'] ?? '1') === '1' ? 'publish' : 'draft');
            $product->set_featured(($row['Is featured?'] ?? '0') === '1');
            $product->set_catalog_visibility($row['Visibility in catalog'] ?? 'visible');
            $product->set_short_description($row['Short description'] ?? '');
            $product->set_description($row['Description'] ?? '');
            $product->set_regular_price($row['Regular price']);
            $product->set_sale_price($row['Sale price'] ?? '');
            $product->set_weight($row['Weight (kg)'] ?? '');
            $product->set_category_ids(array_map(fn($c) => $categories[$c], $row['_categories']));
            $product->set_manage_stock(true);
            if (!$id || $reset_stock) {
                $product->set_stock_quantity((int) ($row['Stock'] ?? 100));
            }
            foreach ($row as $col => $value) {
                if (strncmp($col, 'Meta: ', 6) === 0) {
                    $product->update_meta_data(substr($col, 6), $value);
                }
            }
            $ids[$sku] = $product->save();
            $stats[$id ? 'products_updated' : 'products_created']++;
        } catch (WC_Data_Exception $e) {
            WP_CLI::warning("SKU $sku kaydedilemedi: " . $e->getMessage());
        }
    }
    return $ids;
}

function lzseed_apply_menu(array $categories): void
{
    $locations = get_theme_mod('nav_menu_locations', []);
    if (!empty($locations['primary']) && wp_get_nav_menu_object($locations['primary'])) {
        return;
    }
    $menu = wp_get_nav_menu_object('Kategoriler');
    $menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu('Kategoriler');
    if (is_wp_error($menu_id)) {
        WP_CLI::warning('Menü oluşturulamadı: ' . $menu_id->get_error_message());
        return;
    }
    if (!$menu) {
        foreach ($categories as $name => $term_id) {
            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title' => $name, 'menu-item-object' => 'product_cat',
                'menu-item-object-id' => $term_id, 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish',
            ]);
        }
    }
    $locations['primary'] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

/** @return array<int,int> plan müşteri indeksi => user id */
function lzseed_apply_customers(array $customers, string $demo_pass, array &$stats): array
{
    $ids      = [];
    $progress = WP_CLI\Utils\make_progress_bar('Müşteriler', count($customers));
    foreach ($customers as $i => $c) {
        $user = get_user_by('email', $c['email']);
        if ($user) {
            $ids[$i] = (int) $user->ID;
            $stats['customers_existing']++;
            $progress->tick();
            continue;
        }
        try {
            $customer = new WC_Customer();
            $customer->set_email($c['email']);
            $customer->set_username($c['username']);
            $customer->set_password($i === 0 ? $demo_pass : wp_generate_password(24));
            $customer->set_first_name($c['first_name']);
            $customer->set_last_name($c['last_name']);
            $customer->set_display_name($c['first_name'] . ' ' . $c['last_name']);
            foreach (['billing', 'shipping'] as $type) {
                $customer->{"set_{$type}_first_name"}($c['first_name']);
                $customer->{"set_{$type}_last_name"}($c['last_name']);
                foreach ($c['address'] as $field => $value) {
                    $customer->{"set_{$type}_{$field}"}($value);
                }
            }
            $customer->set_billing_email($c['email']);
            $customer->set_billing_phone($c['phone']);
            $customer->set_shipping_phone($c['phone']);
            $customer->update_meta_data('billing_tckn', $c['tckn']); // SENTETİK
            $customer->update_meta_data('lz_seed_ref', $c['ref']);
            $id = $customer->save();
            wp_update_user(['ID' => $id, 'user_registered' => gmdate('Y-m-d H:i:s', $c['registered_ts'])]);
            $ids[$i] = (int) $id;
            $stats['customers_created']++;
        } catch (Exception $e) {
            WP_CLI::warning("Müşteri {$c['email']} oluşturulamadı: " . $e->getMessage());
        }
        $progress->tick();
    }
    $progress->finish();
    return $ids;
}

function lzseed_apply_orders(array $plan, array $products, array $users, array &$stats): void
{
    $existing = [];
    $found    = wc_get_orders([
        'type' => 'shop_order', 'limit' => -1, 'status' => array_keys(wc_get_order_statuses()),
        'meta_query' => [['key' => '_lz_seed_ref', 'compare' => 'EXISTS']],
    ]);
    foreach ($found as $order) {
        $existing[$order->get_meta('_lz_seed_ref')] = true;
    }

    $titles   = ['cod' => 'Kapıda Ödeme', 'bacs' => 'Havale / EFT'];
    $progress = WP_CLI\Utils\make_progress_bar('Siparişler', count($plan['orders']));
    foreach ($plan['orders'] as $o) {
        $progress->tick();
        if (isset($existing[$o['ref']])) {
            $stats['orders_existing']++;
            continue;
        }
        if ($o['guest'] === null && !isset($users[$o['customer']])) {
            continue; // müşteri oluşturulamamıştı
        }
        $person = $o['guest'] ?? $plan['customers'][$o['customer']];

        try {
            $order = wc_create_order([
                'status'      => 'pending',
                'customer_id' => $o['guest'] === null ? $users[$o['customer']] : 0,
                'created_via' => 'lz-seed',
            ]);
            if (is_wp_error($order)) {
                throw new Exception($order->get_error_message());
            }
            foreach ($o['items'] as $sku => $qty) {
                if (isset($products[$sku]) && ($product = wc_get_product($products[$sku]))) {
                    $order->add_product($product, $qty);
                }
            }
            $address = array_merge($person['address'], [
                'first_name' => $person['first_name'],
                'last_name'  => $person['last_name'],
                'phone'      => $person['phone'],
            ]);
            $order->set_address($address + ['email' => $person['email']], 'billing');
            $order->set_address($address, 'shipping');

            $subtotal = (float) $order->get_subtotal();
            $shipping = new WC_Order_Item_Shipping();
            if ($subtotal >= (float) LZSEED_FREE_SHIPPING_MIN) {
                $shipping->set_props(['method_id' => 'free_shipping', 'method_title' => 'Ücretsiz Teslimat', 'total' => 0]);
            } else {
                $shipping->set_props(['method_id' => 'flat_rate', 'method_title' => 'Standart Teslimat', 'total' => LZSEED_SHIPPING_FLAT]);
            }
            $order->add_item($shipping);

            $order->set_payment_method($o['payment']);
            $order->set_payment_method_title($titles[$o['payment']]);
            $order->set_customer_note($o['note']);
            $order->update_meta_data('_billing_tckn', $person['tckn']); // SENTETİK
            $order->update_meta_data('_lz_seed_ref', $o['ref']);
            $order->set_date_created($o['ts']);
            $order->calculate_totals(false);
            $order->save();

            if (in_array($o['status'], ['processing', 'completed'], true)) {
                $order->set_date_paid($o['ts']);
            }
            // Durum geçişi WooCommerce kancalarını tetikler (stok düşümü, notlar)
            $order->update_status($o['status'], 'Demo seed.', false);
            if ($o['completed_ts']) {
                $order->set_date_completed($o['completed_ts']);
                $order->save();
            }
            $stats['orders_created']++;
            $stats['status_' . $o['status']] = ($stats['status_' . $o['status']] ?? 0) + 1;
        } catch (Exception $e) {
            WP_CLI::warning("Sipariş {$o['ref']} oluşturulamadı: " . $e->getMessage());
        }
    }
    $progress->finish();
}

/**
 * Plandaki değerlendirmeleri ve mağaza yanıtlarını yazar; `_lz_seed_ref`
 * (sipariş ref'i/SKU) ile eşlenir, tekrar çalıştırınca çoğaltmaz.
 */
function lzseed_apply_reviews(array $plan, array $products, array $users, array &$stats): void
{
    $existing = [];
    foreach (get_comments(['meta_key' => '_lz_seed_ref', 'status' => 'all', 'post_type' => 'product', 'number' => 0]) as $c) {
        $existing[get_comment_meta($c->comment_ID, '_lz_seed_ref', true)] = (int) $c->comment_ID;
    }
    $admin   = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID']);
    $touched = [];

    $insert = function (array $data, array $meta, int $ts) {
        return wp_insert_comment($data + [
            'comment_type'     => 'review',
            'comment_date'     => get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts)),
            'comment_date_gmt' => gmdate('Y-m-d H:i:s', $ts),
            'comment_meta'     => $meta,
        ]);
    };

    wp_defer_comment_counting(true);
    $progress = WP_CLI\Utils\make_progress_bar('Değerlendirmeler', count($plan['reviews']));
    foreach ($plan['reviews'] as $r) {
        $progress->tick();
        $product_id = $products[$r['sku']] ?? 0;
        if (!$product_id || ($r['customer'] !== null && !isset($users[$r['customer']]))) {
            continue;
        }
        $id = $existing[$r['ref']] ?? 0;
        if ($id) {
            $stats['reviews_existing']++;
        } else {
            $id = (int) $insert([
                'comment_post_ID'      => $product_id,
                'comment_author'       => $r['author'],
                'comment_author_email' => $r['email'],
                'comment_author_IP'    => $r['ip'],
                'comment_agent'        => $r['agent'],
                'comment_content'      => $r['text'],
                'comment_approved'     => $r['approved'],
                'user_id'              => $r['customer'] !== null ? $users[$r['customer']] : 0,
            ], ['rating' => $r['rating'], 'verified' => 1, '_lz_seed_ref' => $r['ref']], $r['ts']);
            if (!$id) {
                WP_CLI::warning("Değerlendirme {$r['ref']} yazılamadı.");
                continue;
            }
            $stats['reviews_created']++;
            $touched[$product_id] = true;
        }

        $reply_ref = $r['ref'] . '/yanit';
        if ($r['reply'] && !isset($existing[$reply_ref])) {
            $insert([
                'comment_post_ID'      => $product_id,
                'comment_parent'       => $id,
                'comment_author'       => 'Yerel Market',
                'comment_author_email' => get_option('admin_email'),
                'comment_content'      => $r['reply']['text'],
                'comment_approved'     => 1,
                'user_id'              => (int) ($admin[0] ?? 0),
            ], ['_lz_seed_ref' => $reply_ref], $r['reply']['ts']);
            $stats['review_replies']++;
        }
    }
    $progress->finish();
    wp_defer_comment_counting(false);

    // Ürünün ortalama puanı ve değerlendirme sayısı (_wc_average_rating, ...)
    foreach (array_keys($touched) as $product_id) {
        WC_Comments::clear_transients($product_id);
    }
}

// ---------------------------------------------------------------------------
// Çalıştır
// ---------------------------------------------------------------------------
$lz_opts    = lzseed_parse_args($args ?? []);
$lz_catalog = lzseed_read_catalog($lz_opts['csv']);
$lz_plan    = lzseed_plan($lz_catalog, $lz_opts, time());

if (function_exists('shopdemo_tckn_is_valid')) {
    foreach (array_merge($lz_plan['customers'], array_filter(array_column($lz_plan['orders'], 'guest'))) as $lz_p) {
        if (!shopdemo_tckn_is_valid($lz_p['tckn'])) {
            WP_CLI::error("Üretilen TCKN doğrulamadan geçmedi: {$lz_p['tckn']}");
        }
    }
}

if ($lz_opts['dry-run']) {
    // Yalnızca plan; DB'ye yazılmaz. Örn: wp eval-file seed.php dry-run > plan.json
    foreach ($lz_plan['orders'] as &$lz_o) {
        $lz_o['ts']           = lzseed_iso($lz_o['ts']);
        $lz_o['completed_ts'] = $lz_o['completed_ts'] ? lzseed_iso($lz_o['completed_ts']) : null;
    }
    unset($lz_o);
    foreach ($lz_plan['customers'] as &$lz_c) {
        $lz_c['registered_ts'] = lzseed_iso($lz_c['registered_ts']);
    }
    unset($lz_c);
    foreach ($lz_plan['reviews'] as &$lz_r) {
        $lz_r['ts'] = lzseed_iso($lz_r['ts']);
        if ($lz_r['reply']) {
            $lz_r['reply']['ts'] = lzseed_iso($lz_r['reply']['ts']);
        }
    }
    unset($lz_r);
    WP_CLI::line(wp_json_encode([
        'synthetic' => 'SENTETİK — tüm kişiler, TCKN\'ler ve adresler rastgele üretilmiştir',
        'options'   => array_diff_key($lz_opts, ['customer-pass' => 1]),
        'products'  => array_values(array_map(fn($r) => array_diff_key($r, ['_categories' => 1]), $lz_catalog)),
        'customers' => $lz_plan['customers'],
        'orders'    => $lz_plan['orders'],
        'reviews'   => $lz_plan['reviews'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return;
}

// Seed sırasında hiçbir e-posta gönderilmesin (hesap/sipariş bildirimleri, SES vs.)
add_filter('pre_wp_mail', '__return_false');
wp_defer_term_counting(true);

$lz_stats = array_fill_keys(['products_created', 'products_updated', 'images_set', 'customers_created',
    'customers_existing', 'orders_created', 'orders_existing', 'reviews_created', 'reviews_existing', 'review_replies'], 0);

WP_CLI::log('==> Mağaza ayarları, ödeme ve teslimat');
lzseed_apply_settings();
lzseed_apply_pages();
lzseed_apply_shipping();

WP_CLI::log('==> Kategoriler ve ürünler (' . count($lz_catalog) . ')');
$lz_categories = lzseed_apply_categories($lz_catalog);
$lz_products   = lzseed_apply_products($lz_catalog, $lz_categories, $lz_opts['reset-stock'], $lz_stats);
lzseed_apply_menu($lz_categories);
if ($lz_opts['images']) {
    WP_CLI::log('==> Ürün ve kategori görselleri (seed/photos)');
    lzseed_apply_images($lz_catalog, $lz_products, $lz_categories, $lz_stats);
}
wp_defer_term_counting(false);

WP_CLI::log('==> Sentetik müşteriler ve siparişler');
$lz_users = lzseed_apply_customers($lz_plan['customers'], $lz_opts['customer-pass'], $lz_stats);
lzseed_apply_orders($lz_plan, $lz_products, $lz_users, $lz_stats);

WP_CLI::log('==> Ürün değerlendirmeleri');
lzseed_apply_reviews($lz_plan, $lz_products, $lz_users, $lz_stats);
wc_delete_product_transients();
lzseed_flush_rewrite();

WP_CLI\Utils\format_items('table', array_map(
    fn($k, $v) => ['metrik' => $k, 'adet' => $v],
    array_keys($lz_stats),
    $lz_stats
), ['metrik', 'adet']);
WP_CLI::success('Demo veri hazır. Demo müşteri: demo.musteri@example.com');
