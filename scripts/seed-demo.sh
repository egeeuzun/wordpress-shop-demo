#!/usr/bin/env bash
# Lokal ortamda demo mağazayı kurar: WordPress kurulumu, WooCommerce,
# tema aktivasyonu ve örnek ürünler.
# Önce: docker compose up -d --build
set -euo pipefail

SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin123}"
ADMIN_EMAIL="${ADMIN_EMAIL:-demo@example.com}"

wp() {
    docker compose exec -T -u www-data wordpress wp "$@"
}

echo "==> WordPress çekirdek kurulumu"
if ! wp core is-installed 2>/dev/null; then
    wp core install \
        --url="$SITE_URL" \
        --title="Masa Mağaza" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASS" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email
else
    echo "    Zaten kurulu, atlanıyor."
fi

echo "==> Türkçe dil paketi"
wp language core install tr_TR --activate || true

echo "==> Tema ve eklentiler"
wp theme activate shopdemo
wp plugin activate woocommerce

echo "==> WooCommerce sayfaları ve mağaza ayarları"
wp wc tool run install_pages --user="$ADMIN_USER" || true
wp option update woocommerce_currency "TRY"
wp option update woocommerce_default_country "TR:TR34"
wp option update woocommerce_store_address "Örnek Mah. Demo Cad. No:1"
wp option update woocommerce_store_city "İstanbul"
wp option update woocommerce_onboarding_opt_in "no"
wp option update woocommerce_task_list_hidden "yes"

echo "==> Kalıcı bağlantılar"
wp rewrite structure '/%postname%/' --hard

echo "==> Örnek ürünler"
create_product() {
    local name="$1" price="$2" sale="$3" desc="$4"
    if wp post list --post_type=product --title="$name" --field=ID | grep -q .; then
        echo "    '$name' zaten var, atlanıyor."
        return
    fi
    local args=(--user="$ADMIN_USER" --name="$name" --type=simple
                --regular_price="$price" --description="$desc" --status=publish)
    [ -n "$sale" ] && args+=(--sale_price="$sale")
    wp wc product create "${args[@]}" >/dev/null
    echo "    + $name (${price} TL)"
}

create_product "Ahşap Çalışma Masası"      "4500" "3990" "Masif meşe, 140x70 cm çalışma masası."
create_product "Ergonomik Ofis Sandalyesi" "2750" ""     "Bel destekli, ayarlanabilir ergonomik sandalye."
create_product "Masa Lambası"              "450"  "349"  "Dokunmatik, 3 kademeli LED masa lambası."
create_product "Monitör Yükseltici"        "320"  ""     "Bambu monitör yükseltici stand."
create_product "Kablosuz Klavye & Mouse"   "890"  ""     "Sessiz tuşlu kablosuz klavye-mouse seti."
create_product "Kitap Desteği (2'li)"      "180"  "149"  "Metal, kaymaz tabanlı kitap desteği seti."

echo
echo "Tamamlandı! 🎉"
echo "  Mağaza:      $SITE_URL"
echo "  Yönetim:     $SITE_URL/wp-admin ($ADMIN_USER / $ADMIN_PASS)"
