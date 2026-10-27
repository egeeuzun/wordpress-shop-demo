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

    // Sepet ikonu arama kutusunun yanına, başlığın ilk satırına
    remove_action('storefront_header', 'storefront_header_cart', 60);
    add_action('storefront_header', 'storefront_header_cart', 40);

    // Puan/satış verisi olmadığı için "popüler" ve "çok satan" bölümleri yok;
    // indirimler kategorilerin hemen altında
    remove_action('homepage', 'storefront_popular_products', 50);
    remove_action('homepage', 'storefront_best_selling_products', 70);
    remove_action('homepage', 'storefront_on_sale_products', 60);
    add_action('homepage', 'storefront_on_sale_products', 25);
});

add_filter('body_class', fn(array $classes) => array_merge($classes, ['storefront-full-width-content', 'shopdemo']));

// Ana sayfa bölümleri
add_filter('storefront_product_categories_args', fn($a) => array_merge($a, ['limit' => 9, 'columns' => 9, 'title' => 'Kategoriler']));
add_filter('storefront_on_sale_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'İndirimdekiler']));
add_filter('storefront_featured_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'Öne çıkanlar']));
add_filter('storefront_recent_products_args', fn($a) => array_merge($a, ['limit' => 8, 'columns' => 4, 'title' => 'Yeni gelenler']));
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
            <span class="shopdemo-topbar__item shopdemo-topbar__item--demo"><?php echo esc_html($notice); ?></span>
        </div>
    </div>
    <?php
}, 5);

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
    return $links === '' ? $notice : $links . ' ' . $notice;
});
