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
