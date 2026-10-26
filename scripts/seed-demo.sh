#!/usr/bin/env bash
# Lokal ortamda demo market'i kurar: WordPress kurulumu, Türkçe dil paketi,
# WooCommerce + tema, ardından seed kiti (seed/seed.php): ~90 ürün, sentetik
# müşteri ve siparişler.
# Önce: docker compose up -d --build
#
# Ek argümanlar seed.php'ye geçer, örn:
#   ./scripts/seed-demo.sh orders=500 customers=200
#   ./scripts/seed-demo.sh dry-run > plan.json   (ilk kurulumdan sonra; DB'ye yazmaz)
set -euo pipefail

SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin123}"
ADMIN_EMAIL="${ADMIN_EMAIL:-demo@example.com}"

wp() {
    docker compose exec -T -u www-data wordpress wp "$@"
}

# dry-run: sadece planı (JSON) bas, hiçbir şey kurma/yazma
for arg in "$@"; do
    if [ "$arg" = "dry-run" ]; then
        wp eval-file /usr/src/shop-seed/seed.php "$@"
        exit 0
    fi
done

echo "==> WordPress çekirdek kurulumu"
if ! wp core is-installed 2>/dev/null; then
    wp core install \
        --url="$SITE_URL" \
        --title="Yerel Market" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASS" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email
else
    echo "    Zaten kurulu, atlanıyor."
fi

echo "==> Türkçe dil paketi"
wp language core install tr_TR --activate || true
wp language plugin install woocommerce tr_TR || true
wp language theme install storefront tr_TR || true

echo "==> Tema ve eklentiler"
wp plugin activate woocommerce
wp theme activate shopdemo

echo "==> Demo veri (seed/seed.php)"
wp eval-file /usr/src/shop-seed/seed.php "$@"

echo
echo "Tamamlandı! 🎉"
echo "  Mağaza:       $SITE_URL"
echo "  Yönetim:      $SITE_URL/wp-admin ($ADMIN_USER / $ADMIN_PASS)"
echo "  Demo müşteri: demo.musteri@example.com / demo123 (customer-pass= ile değişir)"
