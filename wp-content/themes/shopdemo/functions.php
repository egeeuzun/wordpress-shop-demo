<?php
/**
 * Shop Demo — Storefront child teması (Yerel Market).
 *
 * Görünümün tamamı style.css'te. Burada:
 *  - Storefront'un renk ayarları (Customizer'ın ürettiği satır içi CSS de aynı paleti kullansın)
 *  - harici yazı tipi isteğinin kaldırılması
 *  - üst bant, başlık düzeni ve ana sayfa bölümleri
 *  - ürün birimi ve footer'daki demo uyarısı
 */

/** Tek renk kaynağı: style.css'teki --sd-* değişkenleriyle aynı tutun. */
const SHOPDEMO_COLORS = [
    'storefront_heading_color'               => '#16211c',
    'storefront_text_color'                  => '#47534d',
    'storefront_accent_color'                => '#0b7a4b',
    'storefront_header_background_color'     => '#ffffff',
    'storefront_header_text_color'           => '#47534d',
    'storefront_header_link_color'           => '#16211c',
    'storefront_footer_background_color'     => '#0e2a1e',
    'storefront_footer_heading_color'        => '#ffffff',
    'storefront_footer_text_color'           => '#b7c8bf',
    'storefront_footer_link_color'           => '#ffffff',
    'storefront_button_background_color'     => '#0b7a4b',
    'storefront_button_text_color'           => '#ffffff',
    'storefront_button_alt_background_color' => '#0b7a4b',
    'storefront_button_alt_text_color'       => '#ffffff',
];

// Storefront'un renk varsayılanları bu palet olur. Customizer'da elle bir renk
// seçilirse o kazanır; seçilmemişse buradaki değer kullanılır.
add_filter('storefront_setting_default_values', fn(array $defaults) => array_merge($defaults, SHOPDEMO_COLORS));

add_action('wp_enqueue_scripts', function () {
    // Storefront çocuk temanın stilini kendi sürüm numarasıyla yükler; dosya
    // değişince tarayıcı önbelleği yenilenmez. Kendi sürümümüzle yeniden yüklüyoruz.
    wp_dequeue_style('storefront-child-style');
    $deps = ['storefront-style'];
    if (wp_style_is('storefront-woocommerce-style', 'registered')) {
        $deps[] = 'storefront-woocommerce-style';
    }
    wp_enqueue_style('shopdemo', get_stylesheet_uri(), $deps, (string) filemtime(get_stylesheet_directory() . '/style.css'));

    // Google Fonts yok: Storefront'un fonts.googleapis.com isteği kaldırılır,
    // tema sistem yazı tiplerini kullanır. Ziyaretçi verisi üçüncü tarafa gitmez.
    wp_dequeue_style('storefront-fonts');
}, 40);

add_filter('wp_resource_hints', function (array $urls, string $relation) {
    return array_values(array_filter($urls, function ($u) {
        $href = is_array($u) ? ($u['href'] ?? '') : $u;
        return strpos($href, 'fonts.g') === false;
    }));
}, 10, 2);

// Storefront'un kendi kancalarını değiştirmek için: parent tema bunları
// çocuk temadan sonra eklediği için kaldırma işlemi init'te yapılır.
add_action('init', function () {
    // Kenar çubuğu yok: bütün sayfalar tam genişlik
    remove_action('storefront_sidebar', 'storefront_get_sidebar', 10);

    // Ana sayfa: sayfa başlığı yerine kahraman alanı
    remove_action('homepage', 'storefront_homepage_content', 10);

    // Aşağıdaki Storefront fonksiyonları yalnızca WooCommerce açıkken tanımlı
    if (!function_exists('storefront_header_cart')) {
        return;
    }

    // Başlığın ilk satırı: logo | arama | hesap | sepet
    add_action('storefront_header', 'shopdemo_header_account', 40);
    remove_action('storefront_header', 'storefront_header_cart', 60);
    add_action('storefront_header', 'storefront_header_cart', 40);

    // İndirimler kategorilerin hemen altında. "En beğenilenler" ve "Çok
    // satanlar" seed'in değerlendirme ve siparişlerinden dolar.
    remove_action('homepage', 'storefront_on_sale_products', 60);
    add_action('homepage', 'storefront_on_sale_products', 25);
});

add_filter('body_class', fn(array $classes) => array_merge($classes, ['storefront-full-width-content', 'shopdemo']));

// Ana sayfa bölümleri
add_filter('storefront_product_categories_args', fn($a) => array_merge($a, ['limit' => 9, 'columns' => 9, 'title' => 'Kategoriler']));
add_filter('storefront_on_sale_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'İndirimdekiler']));
add_filter('storefront_featured_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'Öne çıkanlar']));
add_filter('storefront_recent_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'Yeni gelenler']));
add_filter('storefront_popular_products_args', fn($a) => array_merge($a, ['limit' => 4, 'columns' => 4, 'title' => 'En beğenilenler']));
add_filter('storefront_best_selling_products_args', fn($a) => array_merge($a, ['limit' => 4, 'columns' => 4, 'title' => 'Çok satanlar']));
add_action('storefront_homepage_before_on_sale_products', fn() => print('<span id="indirimler" class="shopdemo-anchor"></span>'));

// Mağaza sayfasında satırda 4 ürün, sayfada 24 ürün
add_filter('loop_shop_columns', fn() => 4);
add_filter('loop_shop_per_page', fn() => 24);
add_filter('storefront_products_per_page', fn() => 12);

/** Üst bant: teslimat bilgisi ve demo uyarısı (WooCommerce'in alttaki mağaza bildiriminin yerine). */
add_action('storefront_before_header', function () {
    $notice = get_option('woocommerce_demo_store_notice') ?: 'Demo mağazadır, satış yapılmaz.';
    ?>
    <div class="shopdemo-topbar" role="note">
        <div class="col-full">
            <span class="shopdemo-topbar__item shopdemo-topbar__item--truck">750 ₺ üzeri siparişlerde ücretsiz teslimat</span>
            <span class="shopdemo-topbar__item shopdemo-topbar__item--zone" id="shopdemo-zone" hidden
                title="Sunucunun çalıştığı yer ve tarayıcınızdan sunucuya gidiş-dönüş süresi (birkaç ölçümün en iyisi)">
                <span class="shopdemo-zone__name"></span> · <span class="shopdemo-zone__ms"></span>
            </span>
            <span class="shopdemo-topbar__item shopdemo-topbar__item--demo"><?php echo esc_html($notice); ?></span>
        </div>
    </div>
    <?php
}, 5);

/**
 * Üst banttaki konum ve gecikme: zone.php'den sunucunun zone'unu alır ve aynı
 * isteğin gidiş-dönüş süresini ölçer. İlk istek bağlantıyı ısıtır (DNS, TLS);
 * sonraki üç ölçümün en kısası gösterilir. Dış servise istek yok.
 */
add_action('wp_footer', function () {
    ?>
    <script>
    (function () {
        var el = document.getElementById('shopdemo-zone');
        if (!el || !window.fetch || !window.performance) { return; }
        var url = <?php echo wp_json_encode(home_url('/zone.php')); ?>;
        function probe() {
            var t = performance.now();
            return fetch(url, { cache: 'no-store', credentials: 'omit' }).then(function (r) {
                return r.json().then(function (data) { return { data: data, ms: performance.now() - t }; });
            });
        }
        function show(data, ms) {
            el.querySelector('.shopdemo-zone__name').textContent = data.label;
            el.querySelector('.shopdemo-zone__ms').textContent = ms < 1 ? '<1 ms' : Math.round(ms) + ' ms';
            if (data.zone) { el.title = data.zone + ' — ' + el.title; }
            el.hidden = false;
        }
        probe().then(function (first) {
            var best = Infinity, left = 3;
            (function next() {
                if (left-- === 0) { show(first.data, best); return; }
                probe().then(function (r) { best = Math.min(best, r.ms); next(); },
                             function () { show(first.data, isFinite(best) ? best : first.ms); });
            })();
        }).catch(function () {});
    })();
    </script>
    <?php
});

/**
 * Başlıkta hesap linki: girişsiz "Giriş yap", girişli "Hesabım".
 * Storefront bunu yalnızca mobildeki alt çubukta gösteriyor.
 */
function shopdemo_header_account(): void
{
    $url = wc_get_page_permalink('myaccount');
    if (is_user_logged_in()) {
        $user  = wp_get_current_user();
        $label = $user->first_name !== '' ? $user->first_name : 'Hesabım';
    } else {
        $label = 'Giriş yap';
    }
    printf(
        '<a class="shopdemo-account" href="%s"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg><span>%s</span></a>',
        esc_url($url),
        esc_html($label)
    );
}

/** Ana sayfanın başındaki kahraman alanı. */
function shopdemo_hero(): void
{
    $shop  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    $count = wp_count_posts('product')->publish ?? 0;
    $cats  = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => [(int) get_option('default_product_cat')]]);
    $cats  = is_wp_error($cats) ? [] : $cats;

    // Kolaj: görseli olan ilk üç kategori
    $tiles = [];
    foreach ($cats as $term) {
        $img = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
        if ($img) {
            $tiles[] = ['img' => $img, 'name' => $term->name, 'url' => get_term_link($term)];
        }
        if (count($tiles) === 3) {
            break;
        }
    }
    ?>
    <section class="shopdemo-hero" aria-label="Yerel Market">
        <div class="shopdemo-hero__text">
            <span class="shopdemo-eyebrow">Yerel Market · Demo mağaza</span>
            <h1 class="shopdemo-hero__title">Taze market alışverişi, kapınıza kadar.</h1>
            <p class="shopdemo-hero__lead">
                <?php
                printf(
                    'Meyve-sebzeden temizliğe %s kategoride %s ürün. Ürünler, fiyatlar ve siparişler demodur; satış yapılmaz.',
                    esc_html(number_format_i18n(count($cats))),
                    esc_html(number_format_i18n((int) $count))
                );
                ?>
            </p>
            <div class="shopdemo-hero__actions">
                <a class="button shopdemo-hero__cta" href="<?php echo esc_url($shop); ?>">Alışverişe başla</a>
                <a class="shopdemo-hero__link" href="#indirimler">İndirimleri gör &rarr;</a>
            </div>
            <ul class="shopdemo-perks">
                <li>Ücretsiz teslimat <strong>750 ₺</strong> üzeri</li>
                <li>Kapıda ödeme ve havale</li>
                <li>İstanbul içi aynı gün</li>
            </ul>
        </div>
        <?php if ($tiles) : ?>
            <div class="shopdemo-hero__media shopdemo-hero__media--<?php echo count($tiles); ?>">
                <?php foreach ($tiles as $tile) : ?>
                    <a class="shopdemo-hero__tile" href="<?php echo esc_url($tile['url']); ?>">
                        <?php echo wp_get_attachment_image($tile['img'], 'woocommerce_single', false, ['loading' => 'eager', 'alt' => $tile['name']]); ?>
                        <span><?php echo esc_html($tile['name']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}
add_action('homepage', 'shopdemo_hero', 5);

// Ürün birimi (seed'deki `_lz_unit` meta'sı, ör. "1 kg", "500 g", "16 rulo"):
// listede fiyatın üstünde, ürün sayfasında fiyatın altında
function shopdemo_product_unit(): void
{
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }
    $unit = $product->get_meta('_lz_unit');
    if ($unit !== '') {
        printf('<span class="shopdemo-unit">%s</span>', esc_html($unit));
    }
}
add_action('woocommerce_after_shop_loop_item_title', 'shopdemo_product_unit', 6);
add_action('woocommerce_single_product_summary', 'shopdemo_product_unit', 11);

// Footer: "demo mağaza" uyarısı her sayfada (Storefront kredi satırının yerine).
// Storefront telif metnini esc_html ile bastığı için HTML'li kısım kredi
// satırına (wp_kses_post) gidiyor.
add_filter('storefront_credit_link', '__return_false');
add_filter('storefront_copyright_text', function () {
    return '&copy; ' . get_bloginfo('name') . ' ' . gmdate('Y') . ' — Demo mağazadır, satış yapılmaz.';
});
add_filter('storefront_credit_links_output', function ($links) {
    $notice = '<span class="shopdemo-demo-notice">Ürünler, fiyatlar ve müşteri verileri sentetiktir.</span>';
    // Seed fotoğraf atıflarını bu sayfaya yazar (CC BY lisansları atıf ister)
    $credits = get_page_by_path('gorsel-kaynaklari');
    if ($credits && $credits->post_status === 'publish') {
        $notice .= sprintf(' <a class="shopdemo-credits-link" href="%s">Görsel kaynakları</a>', esc_url(get_permalink($credits)));
    }
    return $links === '' ? $notice : $links . ' ' . $notice;
});
