# Seed kiti — Yerel Market demo verisi

[English](README.md) · **Türkçe**

Bu kit mağazayı sahte market verisiyle doldurur: ürünler, müşteriler,
siparişler ve ürün değerlendirmeleri. Oluşturduğu kişisel veriler (isimler,
adresler, telefon numaraları, T.C. kimlik numaraları, değerlendirme
yazanların e-postaları ve IP adresleri), Residency Guard kontrollerinin daha
sonra aradığı verilerdir.

| Dosya | Ne |
| --- | --- |
| `products.csv` | 9 kategoride 86 ürün, SKU'lar `LZ-0001`…`LZ-0086`. 11'i indirimde, 9'u öne çıkan, 1'i stokta yok. |
| `seed.php` | Mağazayı kuran; ürünleri, müşterileri, siparişleri ve değerlendirmeleri oluşturan betik. |

Kit imajda `/usr/src/shop-seed/` altında bulunur (web kökünün dışında) ve
konteynerin içinde WP-CLI ile çalışır. API anahtarı gerektirmez.

## Temel kurallar

- **Gerçek kişi yok.** İsimler ve adresler kelime listelerinden rastgele
  seçilir. Tüm e-postalar, örnekler için ayrılmış bir alan adı olan
  `@example.com` ile biter.
- **T.C. kimlik numaraları (TCKN) sahte.** Doğrulama kuralını geçen rastgele
  11 haneli sayılardır, bu yüzden ödeme sayfası onları kabul eder. Hiç kimseye
  ait değildirler.
- **Gerçek marka yok.** Ürünlerin "Tam Yağlı Süt 1 L" gibi genel adları var.
  Ürün fotoğrafları telifsiz stok fotoğraflardır (bkz.
  [Ürün fotoğrafları](#ürün-fotoğrafları)); hiçbirinde logo görünmemeli.
- **Gerçek ödeme yok.** Yalnızca Kapıda Ödeme ve Banka Havalesi sunulur;
  gösterilen banka hesabı (IBAN) bilerek geçersizdir.
- **Değerlendirme yazanların IP adresleri sahte.** Dokümantasyon için ayrılmış
  aralıklardan gelirler (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`,
  RFC 5737).
- Betik çalışırken **hiç e-posta gönderilmez**.

## Nasıl çalıştırılır

Lokalde:

```bash
docker compose up -d --build
./scripts/seed-demo.sh                          # 150 müşteri, ~330 sipariş, ~200 değerlendirme
./scripts/seed-demo.sh orders=500 customers=200 # daha fazla veri
./scripts/seed-demo.sh dry-run > plan.json      # önizleme, hiçbir şey yazmaz
```

AWS'de konteyner, `SHOP_AUTO_SEED=1` olduğunda açılışta kiti kendisi
çalıştırır (bkz.
[Mağazayı AWS'de doldurma](../README.tr.md#mağazayı-awsde-doldurma)). Elle,
çalışan bir ECS task'ında (WordPress kurulu, WooCommerce etkin):

```bash
aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
  --interactive --command \
  "su -s /bin/sh www-data -c 'wp eval-file /usr/src/shop-seed/seed.php'"
```

Betiği root olarak değil, `www-data` olarak çalıştırın. Root olarak WP-CLI
`--allow-root` olmadan çalışmayı reddeder ve yüklenen görseller root'a ait
olur.

### Seçenekler

Seçenekler `ad=değer` biçiminde yazılır (WP-CLI'nin `eval-file` komutu
`--bayrak` kabul etmez):

| Seçenek | Varsayılan | Ne yapar |
| --- | --- | --- |
| `dry-run` | — | Planlanan veriyi JSON olarak basar, hiçbir şey yazmaz. |
| `orders=N` | `300` | Son 90 güne yayılan sipariş sayısı. Siparişi olmayan müşterilere ayrıca bir ilk sipariş eklenir (varsayılanda yaklaşık 30 tane daha). `orders=0` hiç sipariş oluşturmaz. |
| `customers=N` | `150` | Kayıtlı müşteri sayısı. Siparişlerin yaklaşık %15'i misafir siparişidir. |
| `seed=N` | `2026` | Rastgelelik tohumu. Aynı tohum her zaman aynı müşterileri ve siparişleri verir. |
| `no-images` | — | Ürün ve kategori görsellerini atlar. |
| `reset-stock` | — | Mevcut ürünlerin stoğunu CSV'deki değerlere geri döndürür. |
| `customer-pass=…` | `demo123` | Demo müşteri hesabının parolası. |
| `csv=/yol` | `products.csv` | Başka bir ürün dosyası kullanır. |

## Ne kurar

- **Mağaza ayarları:** site dili Türkçe (dil paketleri imajda), Türk lirası,
  yalnızca Türkiye'ye satış, fiyatlara KDV dahil, İstanbul saat dilimi,
  misafir olarak ödeme açık.
- **Kargo:** 49,90 ₺ standart teslimat, 750 ₺ üzeri ücretsiz.
- **Sayfalar:** bir Storefront ana sayfası ve bir kategori menüsü. Sepet ve
  ödeme sayfaları klasik WooCommerce ödemesine çevrilir, çünkü TCKN alanı
  yalnızca orada çalışır.
- **Ürünler:** kategoriler, ürünler ve kategori görselleri.
- **Demo müşteri:** "Hesabım" ve sipariş geçmişini denemek için birkaç
  siparişi olan `demo.musteri@example.com` / `demo123`.
- **Müşteriler ve siparişler:** tamamlanmış, hazırlanıyor, beklemede (banka
  havalesi bekleniyor) ve iptal edilmiş siparişler. En yeni siparişlerin çoğu
  hâlâ hazırlanıyor durumundadır. Siparişler, gerçekleri gibi stoğu düşürür.
  Birkaç sadık müşteri sık sipariş verir; kayıtlı her müşterinin en az bir
  siparişi vardır.
- **Değerlendirmeler:** tamamlanmış siparişlere, siparişi veren müşterinin
  (kayıtlı müşteriler ve birkaç misafir) teslimattan birkaç gün sonra yazdığı,
  kısa Türkçe yorumlu yıldız puanları. Kişi ve ürün başına en fazla bir
  değerlendirme. Puanlar gerçek mağazalardaki gibi olumluya yatkındır (yaklaşık
  yarısı 5 yıldız). Mağaza ("Yerel Market", yönetici kullanıcı) 1–2 yıldızlı
  değerlendirmelerin çoğuna yanıt verir. Son bir haftanın bazı
  değerlendirmeleri onay bekler durumda bırakılır, böylece wp-admin'deki
  moderasyon kuyruğu boş kalmaz. Değerlendirmeler yıldız zorunlu olarak
  açılır ve bir ürünü yalnızca onu satın almış müşteriler değerlendirebilir.

## Kişisel veri nereye gider

Hepsi WordPress veritabanındadır:

| Veri | Tablo / alan |
| --- | --- |
| Müşteri hesabı, adı, e-postası, adresleri | `wp_users`, `wp_usermeta` (`billing_*`, `shipping_*`) |
| Müşteri TCKN'si | `wp_usermeta`, anahtar `billing_tckn` |
| Sipariş adresleri, telefon, e-posta | `wp_wc_orders`, `wp_wc_order_addresses` |
| Sipariş TCKN'si | `wp_wc_orders_meta`, anahtar `_billing_tckn` |
| WooCommerce raporlarının kullandığı kopyalar | `wp_wc_customer_lookup`, `wp_wc_order_stats` |
| Değerlendirme yazanın adı, e-postası, IP adresi, tarayıcısı | `wp_comments` (`comment_author*`, `comment_agent`), `comment_type = review` |

Betiğin oluşturduğu kayıtlar etiketlidir: siparişlerde `created_via = lz-seed`
ve `_lz_seed_ref` (`LZ-S-0001`…), müşterilerde `lz_seed_ref` (`LZ-C-0001`…),
değerlendirmelerde `_lz_seed_ref` yorum meta'sı (`<sipariş ref>/<SKU>`;
yanıtlar `/yanit` ile biter) bulunur.
Gerçek ödeme sayfasından verilen siparişler de TCKN'yi aynı `_billing_tckn`
anahtarıyla saklar.

## Tekrar çalıştırma

Betiği birden fazla kez çalıştırmak güvenlidir. Ürünler SKU'ya, müşteriler
e-postaya, siparişler ve değerlendirmeler `_lz_seed_ref`'e göre eşleştirilir;
hiçbir şey iki kez oluşmaz. Kitin eski bir sürümüyle doldurulmuş bir mağaza
siparişlerini korur; yeni ilk siparişleri ve değerlendirmeleri bir sonraki
çalıştırmada alır. CSV'de bir ürünün adını, fiyatını ya da açıklamasını
değiştirirseniz bir sonraki çalıştırma o ürünü günceller. Stoğa dokunulmaz
(siparişler onu zaten düşürmüştür); `reset-stock` verirseniz sıfırlanır.

## Yalnızca ürünler

Yalnızca ürünleri yüklemek için `products.csv` dosyasını *wp-admin → Ürünler →
İçe aktar* (Products → Import) ekranından içe aktarın. Sütunlar WooCommerce'in
içe aktarıcısıyla eşleşir; `Meta: _lz_unit` ürün birimi olur. Müşteriler,
siparişler ve mağaza ayarları için yine `seed.php` gerekir.

## Ürün fotoğrafları

Her ürünün `seed/photos/` içinde, SKU'suyla adlandırılmış (`LZ-0001.jpg`…)
kendi fotoğrafı var. Fotoğraflar reponun ve Docker imajının parçasıdır; mağaza
onları hiçbir kurulum olmadan alır: API anahtarı yok, indirme yok. Seed
internete hiç çıkmaz.

- **Ürün görseli:** ürünün kendi fotoğrafı.
- **Kategori görseli:** o kategorideki ilk ürünün fotoğrafı.
- **Fotoğraf yoksa:** fotoğrafı olmayan bir ürün kategorisinin görselini
  kullanır. Hiç fotoğrafı olmayan bir kategoriye düz renkli bir kare verilir.
- **Kendi değişiklikleriniz kalır:** wp-admin'de elle seçtiğiniz bir görselin
  yerine asla başkası konmaz.
- **Tekrar çalıştırma:** bir fotoğraf yalnızca dosyası değiştiğinde yeniden
  yüklenir.

### Fotoğraf ekleme ya da değiştirme

Dosyayı ürünün SKU'su adıyla `seed/photos/` içine koyun: `.jpg`, `.jpeg`,
`.png` ya da `.webp`. Yaklaşık 1000×1000 boyutunda kare bir fotoğraf en iyi
sonucu verir; tema zaten kare kırpar. Sonra `./scripts/seed-demo.sh`'yi
çalıştırın (lokalde `seed` klasörü mount edili, rebuild gerekmez) ve dosyayı
commit edin.

Yalnızca kullanma izniniz olan ve marka ya da logo göstermeyen fotoğraflar
kullanın. Lisans kaynak gösterilmesini istiyorsa `seed/photos/manifest.csv`
dosyasına bir satır ekleyin (`author`, `license`, `source_url`). Seed bu
bilgiyi görsel altyazısı olarak ve **Görsel kaynakları** sayfasında gösterir.

### Klasörü tek seferde doldurma (yalnızca bakımcılar)

`scripts/fetch-photos.py` her ürün için
[Wikimedia Commons](https://commons.wikimedia.org/)'tan bir fotoğraf indirir.
Hesap ya da API anahtarı gerekmez; yalnızca Python 3 ve internet erişimi
yeterli:

```bash
python3 scripts/fetch-photos.py            # henüz fotoğrafı olmayan ürünler
python3 scripts/fetch-photos.py LZ-0042    # yalnızca bu ürünler, yeniden
```

Repodaki fotoğrafları hiçbir şey indirmeden aynı uygunluk filtresine göre
kontrol etmek için `python3 scripts/fetch-photos.py --check` çalıştırın.

Bitince her ürünü fotoğrafıyla gösteren tek bir sayfa,
`seed/photos/review.html` yazar. Git bu dosyayı yok sayar.

Betik bir fotoğrafı yalnızca şu koşulların hepsi sağlandığında seçer:

- **Başlık ürüne uyuyor.** Dosya adı ürünün `keywords` kelimelerinden en az
  birini içeriyor. "cream" araması dondurmalı sandviç getirmesin diye bu
  kural var.
- **Hariç tutulan kelime yok.** Başlık ürünün `exclude` kelimelerinden
  hiçbirini içermiyor; örneğin saç kremi için "air", çöp poşeti için "forest".
- **Bir fotoğraf.** Tablolar, posterler, reklamlar, ekran görüntüleri,
  logolar ve benzeri dosyalar her ürün için atlanır.
- **Uygun.** Çıplaklık, erotik veya cinsel içerik, vahşet ya da yaralanma yok.
  Betik dosya başlığına ve dosyanın Commons kategorilerine de bakar; böylece
  zararsız görünen bir başlık "Nude …" gibi bir kategorideyse yine atlanır.
- **Serbestçe kullanılabilir.** Lisans CC0, kamu malı, CC BY ya da CC BY-SA.
- **Biçimi doğru.** JPEG, yeterince büyük ve kareye yakın.
- **Başka ürün kullanmıyor.** Aynı fotoğrafı başka bir ürün kullanmıyor.

`manifest.csv` dosyasında her ürün için bir satır var:

| Sütun | Ne |
| --- | --- |
| `search` | Commons araması. Hiçbir sonuç uymazsa betik her anahtar kelime için yalnızca başlıklarda yeniden arar. |
| `keywords` | Başlık bunlardan en az birini içermeli, `\|` ile ayrılır. Kelimeler başından eşleşir; `diaper` "diapers" ile de eşleşir. |
| `exclude` | Başlık bunlardan hiçbirini içermemeli, `\|` ile ayrılır. |
| `file` | Seçilen Commons dosyası, örneğin `File:Tomatoes.jpg`. Doluysa tam olarak bu dosya indirilir. |
| `author`, `license`, `source_url` | Kaynak bilgisi; betik doldurur. |

Bir fotoğrafı değiştirmek için Commons'ta beğendiğiniz bir dosyanın adını
`file` sütununa yazın, ya da `file`'ı boşaltıp `keywords` veya `exclude`'u
daraltın. Sonra betiği o SKU ile çalıştırın. Elle konmuş bir fotoğrafın
üzerine, SKU'su açıkça verilmedikçe asla yazılmaz.

CC BY ve CC BY-SA kaynak gösterilmesini ister. Seed yazarı ve lisansı görsel
altyazısı olarak gösterir ve her fotoğrafı, alt bilgiden bağlantı verilen
**Görsel kaynakları** sayfasında listeler.

Fotoğrafı olmayan bir ürün mağazada kategorisinin görselini gösterir; yani bir
eksik hiçbir şeyi bozmaz.
