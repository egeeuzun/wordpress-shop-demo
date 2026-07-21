<?php
/**
 * Plugin Name: Shop Demo Tweaks
 * Description: AWS mimarisine özgü ayarlar — CDN medya URL'leri, proxy düzeltmeleri.
 */

// WP_CDN_URL tanımlıysa (CloudFront/S3 static assets), medya URL'lerini CDN'e yönlendir.
$shopdemo_cdn = getenv('WP_CDN_URL');
if ($shopdemo_cdn !== false && $shopdemo_cdn !== '') {
    add_filter('upload_dir', function ($dirs) use ($shopdemo_cdn) {
        $dirs['baseurl'] = rtrim($shopdemo_cdn, '/') . '/wp-content/uploads';
        return $dirs;
    });
}

// XML-RPC saldırı yüzeyini kapat (WAF'a ek koruma)
add_filter('xmlrpc_enabled', '__return_false');
