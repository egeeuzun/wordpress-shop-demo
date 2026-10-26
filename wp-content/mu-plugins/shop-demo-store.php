<?php
/**
 * Plugin Name: Shop Demo Store Rules
 * Description: Demo market kuralları — yalnızca offline ödeme, checkout'ta T.C. Kimlik No (e-Arşiv) alanı.
 */

/**
 * T.C. Kimlik No sağlama kontrolü (11 hane, ilk hane 0 değil, 10. ve 11. hane
 * algoritmayla). Seed'in ürettiği SENTETİK değerler de bununla doğrulanır.
 */
function shopdemo_tckn_is_valid(string $value): bool
{
    if (!preg_match('/^[1-9][0-9]{10}$/', $value)) {
        return false;
    }
    $d    = array_map('intval', str_split($value));
    $odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $even = $d[1] + $d[3] + $d[5] + $d[7];
    return (((($odd * 7) - $even) % 10 + 10) % 10) === $d[9]
        && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
}

// Demo mağazada gerçek ödeme altyapısı yok: panelden başka bir ödeme yöntemi
// açılsa bile vitrinde yalnızca Kapıda Ödeme ve Havale/EFT görünür.
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    return is_array($gateways) ? array_intersect_key($gateways, array_flip(['cod', 'bacs'])) : $gateways;
});

// ---------------------------------------------------------------------------
// T.C. Kimlik No — klasik checkout alanı
// ---------------------------------------------------------------------------
// "billing_tckn" klasik checkout'ta siparişe otomatik olarak `_billing_tckn`,
// müşteriye `billing_tckn` meta'sı olarak kaydedilir. Blok tabanlı checkout bu
// filtreyi kullanmadığı için seed checkout sayfasını [woocommerce_checkout]
// shortcode'una çevirir.
// Residency Guard için: bu alan kişisel veridir ve WordPress DB'sinde durur.
add_filter('woocommerce_billing_fields', function ($fields) {
    $fields['billing_tckn'] = [
        'label'             => 'T.C. Kimlik No',
        'required'          => true,
        'class'             => ['form-row-wide'],
        'priority'          => 25,
        'maxlength'         => 11,
        'autocomplete'      => 'off',
        'custom_attributes' => ['inputmode' => 'numeric'],
        'description'       => 'e-Arşiv fatura için gereklidir. Demo mağaza: 11111111110 kullanabilirsiniz.',
    ];
    return $fields;
});

add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    $tckn = (string) ($data['billing_tckn'] ?? '');
    if ($tckn !== '' && !shopdemo_tckn_is_valid($tckn)) {
        $errors->add('billing_tckn_validation', '<strong>T.C. Kimlik No</strong> geçerli değil.', ['id' => 'billing_tckn']);
    }
}, 10, 2);

// Hesabım → Adresler formu da aynı alanı kullanıyor
add_action('woocommerce_after_save_address_validation', function ($user_id, $address_type) {
    $tckn = isset($_POST['billing_tckn']) ? wc_clean(wp_unslash($_POST['billing_tckn'])) : '';
    if ($address_type === 'billing' && $tckn !== '' && !shopdemo_tckn_is_valid($tckn)) {
        wc_add_notice('<strong>T.C. Kimlik No</strong> geçerli değil.', 'error');
    }
}, 10, 2);

// Yönetim: sipariş ekranında (`_billing_tckn`) ve kullanıcı profilinde göster
add_filter('woocommerce_admin_billing_fields', function ($fields) {
    $fields['tckn'] = ['label' => 'T.C. Kimlik No', 'show' => true];
    return $fields;
});

add_filter('woocommerce_customer_meta_fields', function ($fields) {
    $fields['billing']['fields']['billing_tckn'] = ['label' => 'T.C. Kimlik No', 'description' => ''];
    return $fields;
});
