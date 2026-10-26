<?php
/**
 * Shop Demo — Storefront child teması.
 */

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('storefront-parent', get_template_directory_uri() . '/style.css');
    wp_enqueue_style(
        'shopdemo',
        get_stylesheet_directory_uri() . '/style.css',
        ['storefront-parent'],
        wp_get_theme()->get('Version')
    );
});

// Demo mağaza için ana sayfada ürün sayısını artır
add_filter('storefront_products_per_page', fn() => 12);

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
        printf('<span class="shopdemo-unit">Birim: %s</span>', esc_html($unit));
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
