<?php
/**
 * Plugin Name: Shop Demo Tweaks
 * Description: AWS mimarisine özgü ayarlar — S3 offload, CDN medya URL'leri, proxy düzeltmeleri.
 */

$shopdemo_s3_bucket = getenv('WP_S3_BUCKET');
$shopdemo_s3_active = ($shopdemo_s3_bucket !== false && $shopdemo_s3_bucket !== '');

// WP_S3_BUCKET tanımlıysa WP Offload Media'yı veritabanına dokunmadan aktif et.
// İmaj immutable olduğu için her task aynı eklenti setiyle açılsın; ayrıca yeni
// bir ortamda "eklentiyi aktif etmeyi unutma" adımı kalmasın.
// (Eklenti kendi tablosunu ilk kullanımda oluşturur, activation hook gerekmez.)
if ($shopdemo_s3_active) {
    add_filter('option_active_plugins', function ($plugins) {
        $offload = 'amazon-s3-and-cloudfront/wordpress-s3.php';
        if (!is_array($plugins)) {
            return $plugins;
        }
        if (!in_array($offload, $plugins, true) && file_exists(WP_PLUGIN_DIR . '/' . $offload)) {
            $plugins[] = $offload;
        }
        return $plugins;
    });
}

// WP_CDN_URL tanımlıysa (CloudFront), medya URL'lerini CDN'e yönlendir.
// S3 offload açıkken bu devre dışı: URL'leri eklenti zaten yeniden yazıyor,
// ikisi birden çalışırsa yol iki kez değişip 404 üretir.
$shopdemo_cdn = getenv('WP_CDN_URL');
if (!$shopdemo_s3_active && $shopdemo_cdn !== false && $shopdemo_cdn !== '') {
    add_filter('upload_dir', function ($dirs) use ($shopdemo_cdn) {
        $dirs['baseurl'] = rtrim($shopdemo_cdn, '/') . '/wp-content/uploads';
        return $dirs;
    });
}

// XML-RPC saldırı yüzeyini kapat (WAF'a ek koruma)
add_filter('xmlrpc_enabled', '__return_false');
