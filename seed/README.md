# Seed kiti — "Yerel Market" demo verisi (Faz 2)

Migros benzeri, **tamamen sentetik** verili bir market mağazası. Residency Guard'ın
izleyeceği kişisel veri (müşteri, adres, telefon, TCKN, sipariş) burada oluşur.

| Dosya | İçerik |
| --- | --- |
| `products.csv` | 86 ürün, 9 kategori, SKU `LZ-0001`…`LZ-0086`, stok, ağırlık, birim; 11 indirimli, 9 öne çıkan, 1 stokta olmayan ürün. WooCommerce CSV içe aktarıcısının kolon adlarıyla. |
| `seed.php` | WP-CLI seed betiği: mağaza ayarları → ödeme/teslimat → kategoriler → ürünler → müşteriler → siparişler. |

Kit imaja `/usr/src/shop-seed/` olarak gömülür (webroot dışında). Seçilen yöntem
**WP-CLI** (akıştaki 3. yöntem): konteynerin içinden çalıştığı için REST API
anahtarı gerekmez, repoda ya da ortamda sır tutulmaz, dış servise istek atılmaz.

## Kurallar

- **Gerçek kişisel veri yok.** İsimler/adresler sözlükten rastgele; e-postalar
  `@example.com` (RFC 2606 rezerve alan adı).
- **TCKN'ler SENTETİKTİR:** sağlama algoritmasına uyan ama rastgele üretilmiş
  11 hanelik değerler; gerçek bir kişiyle ilişkilendirilmemiştir.
- **Marka/görsel yok:** ürünler generic ("Tam Yağlı Süt 1 L"). Görsel olarak
  kategori başına düz renkli PNG placeholder üretilir.
- **Ödeme yalnızca offline:** Kapıda Ödeme ve Havale/EFT (IBAN bilerek geçersiz).
  `wp-content/mu-plugins/shop-demo-store.php` vitrinde başka yöntem göstermez.
- **"Demo mağazadır, satış yapılmaz"** notu site açıklamasında, üst bantta
  (WooCommerce demo bildirimi) ve footer'da.
- Seed sırasında **hiç e-posta gönderilmez** (`pre_wp_mail`).

## Çalıştırma

Lokal (kurulum + dil paketi + seed):

```bash
docker compose up -d --build
./scripts/seed-demo.sh                       # 150 müşteri, 300 sipariş
./scripts/seed-demo.sh orders=500 customers=200
./scripts/seed-demo.sh dry-run > plan.json   # DB'ye yazmadan planı gör
```

AWS (ECS Exec, WordPress kurulu ve WooCommerce aktifken):

```bash
aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
  --interactive --command \
  "su -s /bin/sh www-data -c 'wp eval-file /usr/src/shop-seed/seed.php orders=300'"
```

`www-data` ile çalıştırın: root ile WP-CLI `--allow-root` ister ve yüklenen
placeholder dosyaları root'a ait kalır.

### Argümanlar

WP-CLI `eval-file` `--bayrak` kabul etmediği için `anahtar=değer` biçiminde:

| Argüman | Varsayılan | Anlamı |
| --- | --- | --- |
| `dry-run` | — | Planı JSON olarak basar, hiçbir şey yazmaz. |
| `orders=N` | `300` | Sipariş sayısı (son 90 güne yayılır). |
| `customers=N` | `150` | Kayıtlı müşteri sayısı (siparişlerin ~%15'i misafir). |
| `seed=N` | `2026` | Rastgele üretecin tohumu; aynı tohum aynı planı üretir. |
| `no-images` | — | Placeholder görselleri üretme. |
| `reset-stock` | — | Var olan ürünlerin stoğunu CSV'deki değere döndür. |
| `customer-pass=…` | `demo123` | Demo müşteri hesabının şifresi. |
| `csv=/yol` | `products.csv` | Başka bir katalog dosyası. |

## Ne oluşturur?

- **Ayarlar:** TRY, `1.234,56 ₺` biçimi, yalnızca Türkiye'ye satış, KDV dahil
  fiyat (vergi hesabı kapalı), `Europe/Istanbul`, misafir checkout açık,
  "yakında" modu ve pazar yeri önerileri kapalı, `/%postname%/`.
- **Teslimat:** "Türkiye" bölgesi — Standart 49,90 ₺, 750 ₺ üzeri ücretsiz.
- **Sayfalar:** Storefront ana sayfa şablonu, kategori menüsü. Sepet ve ödeme
  sayfaları **klasik shortcode**'a çevrilir; TCKN alanı klasik checkout API'si ile
  ekleniyor, blok checkout bu alanı göstermez.
- **Katalog:** kategoriler, ürünler, kategori görselleri.
- **Demo müşteri:** `demo.musteri@example.com` / `demo123` — "Hesabım / sipariş
  geçmişi" senaryosu (Faz 8, k6) için birkaç siparişi olan sabit hesap.
- **Müşteriler/siparişler:** İstanbul ilçelerinden adresler, `+90 5xx` telefon.
  Siparişler tamamlandı / hazırlanıyor / beklemede (havale) / iptal; son günlerin
  siparişleri ağırlıkla "hazırlanıyor". Durum geçişleri WooCommerce kancalarıyla
  yapıldığı için stoklar düşer ve sipariş notları oluşur.

## Kişisel veri nerede? (Residency Guard için)

Hepsi WordPress veritabanında:

| Veri | Tablo / anahtar |
| --- | --- |
| Müşteri hesabı, ad, e-posta | `wp_users`, `wp_usermeta` (`billing_*`, `shipping_*`) |
| Müşteri TCKN'si | `wp_usermeta.billing_tckn` |
| Sipariş adresleri, telefon, e-posta | `wp_wc_orders`, `wp_wc_order_addresses` (HPOS) |
| Sipariş TCKN'si | `wp_wc_orders_meta._billing_tckn` |
| Analitik kopyaları | `wp_wc_customer_lookup`, `wp_wc_order_stats` |

Seed'in yazdığı kayıtlar işaretlidir: siparişlerde `created_via = lz-seed` ve
`_lz_seed_ref` (`LZ-S-0001`…), müşterilerde `lz_seed_ref` (`LZ-C-0001`…).
Checkout'tan gelen yeni siparişler de aynı `_billing_tckn` anahtarına yazılır.

## İdempotentlik

Ürünler SKU'ya, müşteriler e-postaya, siparişler `_lz_seed_ref`'e göre eşlenir;
betiği tekrar çalıştırmak çoğaltmaz. CSV'de değiştirdiğiniz ad/fiyat/açıklama
var olan ürünlere yansır, stok ise (siparişler düşürdüğü için) korunur. Sipariş
tarihleri çalıştırma anına göre hesaplanır; tekrar çalıştırmada zaten var olan
siparişler atlandığı için tarihleri değişmez.

## Alternatif: CSV içe aktarıcı

Sadece katalog gerekiyorsa `products.csv` doğrudan *wp-admin → Ürünler → İçe
Aktar* ile yüklenebilir; kolonlar otomatik eşlenir, `Meta: _lz_unit` ürün birimi
olarak gelir. Müşteri/sipariş ve mağaza ayarları için `seed.php` gerekir.
