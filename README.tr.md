# WordPress Shop Demo — Yerel Market

[English](README.md) · **Türkçe**

> **İlk kez mi çalıştırıyorsunuz?** Adım adım [çalıştırma kılavuzu](docs/calistirma-kilavuzu.md)
> mağazayı kendi bilgisayarınızda Docker ile ya da AWS'de nasıl
> çalıştıracağınızı, ikisi için de sorun gidermeyle birlikte anlatır.

AWS ECS için Docker imajı olarak paketlenmiş bir WooCommerce market mağazası:
**Yerel Market**. Yüksek erişilebilir bir WordPress kurulumunu Frankfurt'tan
AWS İstanbul Local Zone'a taşıyan ve ardından müşteri verisinin gerçekten
Türkiye'de kaldığını doğrulayan bir projenin demo uygulamasıdır.

Mağazadaki her şey sahte. Gerçek müşteri, gerçek marka ya da gerçek ödeme
yok. Hiçbir şey satılmaz.

## Mağazada neler var

- **9 market kategorisinde 86 ürün** (meyve-sebze, süt ürünleri, et,
  temel gıda, atıştırmalık, içecek, temizlik, kişisel bakım, bebek). Her birinin
  SKU'su (`LZ-0001`…), fiyatı, stoğu, ağırlığı ve birimi var. Bazıları
  indirimde. Her ürünün repoda (`seed/photos/`) saklanan, serbest lisanslı
  kendi fotoğrafı var (Wikimedia Commons); bkz.
  [`seed/README.tr.md`](seed/README.tr.md#ürün-fotoğrafları).
- **Son 90 güne yayılmış 150 müşteri ve yaklaşık 330 sipariş.** Kayıtlı her
  müşterinin en az bir siparişi var. İsimler, İstanbul adresleri, `+90 5xx`
  telefon numaraları ve `@example.com` e-postaları rastgele üretilir.
- **Yıldızlı yaklaşık 200 ürün değerlendirmesi.** Her birini o ürünü satın alıp
  teslim almış bir müşteri yazmıştır ("doğrulanmış sahip"). Mağaza 1–2 yıldızlı
  değerlendirmelerin çoğuna yanıt verir; birkaç yeni değerlendirme wp-admin'de
  onay bekler. Ana sayfa bu verilerden "En beğenilenler" ve "Çok satanlar"
  bölümlerini gösterir.
- **Her siparişte T.C. kimlik numarası (TCKN).** Türk mağazaları e-fatura
  kesmek için bunu sık sık ister; bu da projeye takip edilecek gerçekçi bir
  hassas veri sağlar. Numaralar rastgeledir; yalnızca doğrulama kuralını
  geçerler.
- **Yalnızca çevrimdışı ödemeler:** Kapıda Ödeme ve Banka Havalesi. Diğer ödeme
  yöntemleri wp-admin'de etkinleştirilse bile asla gösterilmez.
- **Demo uyarısı** ("Demo mağazadır, satış yapılmaz") site sloganında, üst
  bantta ve alt bilgide.
- **Nerede çalıştığı, canlı ölçülür.** Üst bant, ulaştığınız CloudFront
  edge'ini, sunucunun çalıştığı zone'u ve gidiş-dönüş süresini gösterir; örneğin
  "CloudFront IST → İstanbul Local Zone · edge 12 ms · sunucu 3 ms" (toplam süre
  ipucunda). Zone, ECS task metadata'sından gelir (`/zone.php`; CloudFront bunu
  önbelleğe almaz ve WordPress'i yüklemez), edge ise `x-amz-cf-pop`
  başlığından. Toplam süre `/zone.php`'ye gidiş-dönüştür; edge payı,
  CloudFront'un önbelleğinden sunduğu küçük bir dosyaya (`edge-ping.txt`)
  gidiş-dönüştür, sunucu payı da ikisinin farkıdır. Hiçbir değer sabit
  yazılmamıştır.
- **Birkaç siparişi olan bir demo müşteri hesabı:**
  `demo.musteri@example.com` / `demo123`.

## Hızlı başlangıç (lokal)

```bash
cp .env.example .env
docker compose up -d --build
./scripts/seed-demo.sh
```

- **Mağaza:** http://localhost:8080
- **Yönetim:** http://localhost:8080/wp-admin (`admin` / `admin123`)

Gereksinimler, günlük komutlar ve sorun giderme
[çalıştırma kılavuzunda](docs/calistirma-kilavuzu.md).

`admin123` yalnızca lokal varsayılandır. AWS'de yönetici parolası
`WORDPRESS_ADMIN_PASSWORD`'dan gelir (bir SSM parametresi, aşağıya bakın).

`seed-demo.sh` WordPress'i Türkçe kurar, ardından mağazayı doldurur. Tekrar
çalıştırabilirsiniz: zaten var olan ürün, müşteri, sipariş ve
değerlendirmeleri atlar, hiçbir şey iki kez oluşmaz.

```bash
./scripts/seed-demo.sh orders=500 customers=200   # daha fazla veri
./scripts/seed-demo.sh dry-run > plan.json        # önizleme, hiçbir şey yazmaz
```

## Mağazayı AWS'de doldurma

### Otomatik (Terraform reposuyla varsayılan)

`SHOP_AUTO_SEED=1` verin; konteyner açılırken mağazayı kendisi hazırlar. Bu iş
arka planda çalışır, böylece Apache ve sağlık kontrolü beklemez:

1. WordPress kurulu değilse Türkçe kurar; WooCommerce'i ve `shopdemo`
   temasını etkinleştirir.
2. Yönetici kullanıcının parolasını `WORDPRESS_ADMIN_PASSWORD` yapar; böylece
   veritabanı daha önce başka bir parolayla kurulmuş olsa bile SSM'deki parola
   her zaman çalışır. wp-admin'de değiştirilen parola bir sonraki task
   açılışında sıfırlanır; korumak için `SHOP_SYNC_ADMIN_PASSWORD=0` verin.
3. Seed kiti bu veritabanında henüz çalışmadıysa ya da imajdaki kit geçen
   seferkinden farklıysa seed'i çalıştırır.

| Değişken | Anlamı |
| --- | --- |
| `SHOP_AUTO_SEED` | `1` = açık. Varsayılan kapalı; böylece lokal `docker compose` değişmez. |
| `WORDPRESS_ADMIN_PASSWORD` | Zorunlu. Yönetici kullanıcının parolası; kurulumda ve her açılışta ayarlanır. ECS secret olarak verin. |
| `WORDPRESS_ADMIN_USER` | Varsayılan `admin`. |
| `WORDPRESS_ADMIN_EMAIL` | Varsayılan `demo@example.com`. |
| `SHOP_SEED_ARGS` | Ek seed seçenekleri, örneğin `orders=500 customers=200`. |
| `SHOP_SYNC_ADMIN_PASSWORD` | Varsayılan `1`. `0` = yönetici parolasını yalnızca kurulumda ayarla. |

Terraform reposunda `wp_admin_password` vermezseniz parola rastgeledir. Şöyle
okuyun:

```bash
aws ssm get-parameter --with-decryption --query Parameter.Value --output text \
  --name "$(terraform output -raw admin_password_ssm_name)"
```

Her task açılışta kontrolü yapar, ama işi yalnızca biri yapar: veritabanı
kilidini (`GET_LOCK('shop_auto_seed')`) ilk alan; diğerleri atlar. İmaj, seed
kitinin parmak izini `/usr/src/shop-seed/.version` içinde tutar; başarılı bir
çalıştırmadan sonra aynı değer `shop_seed_version` seçeneğine yazılır ve bir
sonraki açılış yapacak bir şey görmez. Başarısız bir çalıştırma seçeneğe
dokunmaz, böylece bir sonraki task açılışı tekrar dener. Çıktısı konteyner
logundadır; satırlar `[auto-seed]` ile başlar.

### Elle

Seed kiti imajda `/usr/src/shop-seed/` altında olduğundan aynı adımlar çalışan
bir ECS task'ında da çalışır. İçinde bir kabuk açın:

```bash
aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
  --interactive --command "su -s /bin/sh www-data"
```

Sonra WordPress'i kurun (zaten kuruluysa atlayın), mağazayı etkinleştirin ve
doldurun:

```bash
wp core install --url="$WP_HOME" --title="Yerel Market" --locale=tr_TR \
  --admin_user=admin --admin_password='<güçlü parola>' \
  --admin_email=demo@example.com --skip-email
wp plugin activate woocommerce
wp theme activate shopdemo
wp eval-file /usr/src/shop-seed/seed.php
```

WordPress ürün ve kategori URL kurallarını yalnızca açılırken kalıcı bağlantı
yapısı zaten ayarlıysa ekler; taze kurulumda bu yapıyı ayarlayan da seed'in
kendisidir. Bu yüzden seed, URL kurallarını en sonda yeni bir WP-CLI sürecinde
yazar. Yine de eksik bulursa bir uyarı basar (`URL kurallarında eksik: …`);
`wp rewrite flush` elle düzeltir.

Bunların hiçbiri API anahtarı gerektirmez. Tüm seçenekler ve kişisel verinin
hangi veritabanı tablolarında tutulduğu [`seed/README.tr.md`](seed/README.tr.md)
dosyasında.

## Dil

Mağaza Türkçedir. WordPress, WooCommerce ve Storefront için (ve çevirisi varsa
WP Offload Media Lite için) Türkçe (`tr_TR`) dil paketleri
`docker/install-translations.sh` ile imaja gömülür. Betik kurulu sürümleri
okuyup eşleşen paketleri indirir, böylece çeviriler her zaman koda uyar.
Çekirdek, WooCommerce ya da Storefront çevirisi eksikse build, sessizce İngilizce
bir mağaza üretmek yerine başarısız olur.

Paketler imajda olduğu için:

- sitenin Türkçe olması için wordpress.org'a bağlantı gerekmez;
- ECS'te task yenilense de kaybolmazlar (`wp-content/languages` EFS'te ya da
  başka bir paylaşılan diskte değildir);
- seed kiti site dilini `tr_TR` yapar.

Otomatik çeviri güncellemeleri, diğer arka plan güncellemeleri gibi kapalıdır.
Yeni çeviriler bir sonraki imaj build'iyle gelir.

## Mimari

```text
Kullanıcı → Route53 → WAF → CloudFront ─┬─ /wp-content/uploads/* → S3 (medya, OAC)
                                        └─ /*  → ALB → ECS Fargate (2 AZ)
                                                       ├── RDS MySQL (Primary + Read Replica)
                                                       ├── ElastiCache Memcached
                                                       └── EFS (uploads, plugins, themes)

CI/CD: GitHub → CodeBuild → CodePipeline → ECR → ECS
```

## Temel ilkeler

- **İmaja gömülü temel, çalışma anında genişletilebilir:** Çekirdek, WooCommerce, WP Offload Media Lite, Storefront ve özel tema (`shopdemo`) Docker imajına gömülüdür. `wp-admin`'de WordPress.org mağazasından ek eklenti/tema kurmak açıktır (`DISALLOW_FILE_MODS=0`); bunlar EFS'e yazılır, böylece her task görür.
- **Medya S3'te:** Yüklemeler WP Offload Media Lite ile S3'e aktarılır ve CloudFront üzerinden sunulur; statik nesneler PHP'ye ya da EFS'e hiç uğramaz.
- **Paylaşılan depolama:** `wp-content/uploads`, `plugins` ve `themes` Availability Zone'lar arasında EFS'e mount edilir.
- **Ortam yapılandırması:** Ortam değişkenleri ve AWS Secrets Manager ile yönetilir.
- **Önbellek:** `MEMCACHED_HOST` tanımlıysa Memcached object cache drop-in'i otomatik yüklenir; çok node'lu, consistent hashing, isteğe bağlı TLS.

## Proje yapısı

```text
├── Dockerfile               # Fargate konteyner imajı
├── docker-compose.yml       # Lokal geliştirme ortamı
├── docker/                  # Entrypoint, otomatik kurulum + seed, PHP ayarları, sağlık kontrolü, drop-in yaması, dil paketleri
├── wp-config/wp-config.php  # Ortam değişkeni tabanlı WordPress yapılandırması
├── wp-content/
│   ├── themes/shopdemo/     # Storefront alt teması: modern market düzeni, yalnızca sistem fontları
│   └── mu-plugins/          # S3 otomatik etkinleştirme, CDN yönlendirme, XML-RPC kapalı, mağaza kuralları (çevrimdışı ödeme, TCKN)
├── seed/                    # İmaja gömülü demo veri kiti (/usr/src/shop-seed)
│   ├── products.csv         # 86 market ürünü (WooCommerce CSV içe aktarıcı biçimi)
│   └── seed.php             # WP-CLI seed betiği: mağaza ayarları, katalog, sentetik müşteri, sipariş ve değerlendirmeler
├── scripts/seed-demo.sh     # Lokal kurulum: çekirdek kurulumu (Türkçe) + seed.php
├── docs/                    # Çalıştırma kılavuzu (Türkçe + İngilizce)
└── ecs/
    ├── taskdef.example.json          # ECS Task Definition şablonu
    ├── s3-media-policy.example.json  # Medya bucket'ı için task role politikası
    └── cloudfront-media-oac.md       # S3 bucket + CloudFront (OAC) kurulum kılavuzu
```

## AWS CI/CD kurulumu

1. **ECR:** `wordpress-shop-demo` adlı repoyu oluşturun.
2. **CodeBuild:** Repoyu bağlayın, *Privileged mode*'u açın ve ECR'ye push izni verin. Build spec bu repoda değil; Terraform reposu onu satır içinde tanımlar (`modules/cicd`).
3. **Secrets Manager:** Veritabanı parolası ve WordPress salt'ları için gizli değerler oluşturun.
4. **Task Definition:** `ecs/taskdef.example.json` içindeki yer tutucuları doldurun. Konteyner adını `wordpress` olarak bırakın.
5. **CodePipeline:** Akış: GitHub → CodeBuild → ECS (`imagedefinitions.json` ile).
6. **ALB sağlık kontrolü:** Target group yolunu `/health.php` yapın.
7. **Ortam değişkenleri:** `WP_HOME` (CloudFront URL'si), `WP_CDN_URL` ve `WP_S3_BUCKET` değerlerini verin.
8. **Medya bucket'ı:** `ecs/cloudfront-media-oac.md` adımlarını izleyin ve task role politikasını bağlayın.

*Her `git push` ECS Fargate'e otomatik bir dağıtım başlatır.*

## S3'e medya aktarımı

Yüklemeler EFS/PHP yerine S3'e gönderilir ve CloudFront'tan sunulur: daha ucuz
depolama, istek başına EFS throughput'u yok ve tüm task'larda aynı URL'ler.

1. Bucket'ı ve CloudFront davranışını oluşturun: bkz. `ecs/cloudfront-media-oac.md`.
2. `ecs/s3-media-policy.example.json` politikasını ECS **task role**'üne
   bağlayın. Hiçbir yerde erişim anahtarı saklanmaz; eklenti `use-server-roles`
   ile kimlik doğrular.
3. Task definition'da şunları verin:

   | Değişken | Anlamı |
   | --- | --- |
   | `WP_S3_BUCKET` | Medya bucket'ının adı. **Boş = aktarım kapalı** (eklenti pasif kalır). |
   | `WP_S3_REGION` | Bucket bölgesi (varsayılan `eu-west-1`). |
   | `WP_S3_DELIVERY_DOMAIN` | Medyayı sunan alan adı. Verilmezse `WP_CDN_URL` kullanılır. |
   | `WP_S3_OBJECT_PREFIX` | Anahtar öneki (varsayılan `wp-content/uploads/`). |
   | `WP_S3_REMOVE_LOCAL` | `1` (varsayılan) başarılı aktarımdan sonra lokal kopyayı siler. |

Eklenti tamamen `wp-config.php` içindeki `AS3CF_SETTINGS` ile yapılandırılır;
bu yüzden ayarlar ekranı salt okunurdur ve veritabanına bir durum yazmak
gerekmez. `WP_S3_BUCKET` tanımlıysa mu-plugin eklentiyi
`option_active_plugins` filtresiyle zorla etkinleştirir; böylece bir
etkinleştirme adımı olmadan her task aynı kalır.

**Lite sınırları:** yalnızca *yeni* yüklemeler aktarılır; mevcut kütüphaneyi
toplu taşımak bir Pro özelliğidir (geçici çözüm `ecs/cloudfront-media-oac.md`
içinde).

Aktarım açıkken mu-plugin'in `upload_dir` CDN yeniden yazımı atlanır: eklenti
URL'leri zaten yeniden yazar, ikisi birlikte yolu iki kez değiştirir.

## wp-admin'den eklenti ve tema kurma

Varsayılan olarak açık: `FS_METHOD=direct` ve `www-data`'ya ait içerik
dizinleri sayesinde *Eklentiler → Yeni ekle* FTP bilgisi olmadan çalışır.

- `ecs/taskdef.example.json` içinde `wp-content/plugins` ve
  `wp-content/themes` EFS mount'larıdır. Bunlar olmadan bir kurulum yalnızca
  onu yapan konteynerde bulunur ve bir sonraki dağıtımda kaybolur.
- Dosya değişikliklerine izin verildiğinde entrypoint
  `opcache.validate_timestamps`'i açar; böylece yeni kurulan/güncellenen PHP
  dosyaları yeniden başlatma olmadan devreye girer.
- Task'ların `api.wordpress.org` ve `downloads.wordpress.org`'a ulaşmak için
  dışarı HTTPS erişimi (NAT gateway ya da eşdeğeri) olmalı.
- Dosya düzenleyici kapalı kalır (`DISALLOW_FILE_EDIT`): kurulum evet,
  tarayıcıda PHP düzenleme hayır.
- İmaj güncellemesinde EFS'te zaten bulunan bir eklentinin üzerine imajdaki
  kopya **yazılmaz**. İmaja gömülü eklentileri `Dockerfile`'da sürümünü
  yükseltip EFS kopyasını silerek ya da wp-admin'den güncelleyin.
- Her şeyi yeniden kilitlemek için: `WORDPRESS_DISALLOW_FILE_MODS=1`.

## Object cache (ElastiCache Memcached)

İmaj, PECL `memcached` eklentisini ve Memcached Redux drop-in'ini içerir.
Drop-in yalnızca `MEMCACHED_HOST` tanımlıysa (`wp-content/object-cache.php`
olarak) kurulur; bu yüzden onsuz lokal çalıştırmalar etkilenmez.

| Değişken | Anlamı |
| --- | --- |
| `MEMCACHED_HOST` | Bir node ya da virgülle ayrılmış birkaç node. Boş = object cache kapalı. |
| `MEMCACHED_PORT` | Portsuz yazılan node'lar için port (varsayılan `11211`). |
| `MEMCACHED_TLS` | `1` her node'u lokal bir stunnel TLS tünelinden geçirir. |
| `MEMCACHED_TLS_VERIFY` | `0` sertifika zinciri/ana makine adı doğrulamasını kapatır (yalnızca hata ayıklama için). |
| `WP_CACHE_SALT` | Cache anahtarı öneki. Varsayılan `WP_HOME`'un ana makine adı. |

### Configuration endpoint'i değil, node'ları listeleyin

PECL `memcached` eklentisi ElastiCache auto-discovery'yi **desteklemez**;
configuration endpoint'ini sıradan tek bir node gibi görür. Tek node'lu bir
cluster'da bu zararsızdır; çok node'lu bir cluster'da ise tüm trafik tek bir
node'a yığılır. Bu yüzden node adreslerini listeleyin:

```
MEMCACHED_HOST=n1.abc.0001.euw1.cache.amazonaws.com:11211,n2.abc.0001.euw1.cache.amazonaws.com:11211
```

`docker/patch-object-cache.sh`, drop-in'in hiç ayarlamadığı istemci
seçeneklerini (2020'den beri bakımı yapılmıyor) imaj build'i sırasında ekler:

- **Ketama / consistent hashing:** bu olmadan `modula` dağıtımı, bir node
  eklendiğinde ya da çıktığında neredeyse her anahtarı yeniden eşler; cache
  boşalır ve yük RDS'e biner.
- **`REMOVE_FAILED_SERVERS` + bağlanma/gönderme/alma zaman aşımları (500 ms):**
  ulaşılamayan bir node istekleri bekletmek yerine cache ıskalamasına dönüşür.

Yama, upstream kodun belirli bir satırına tutunur ve o satır artık
eşleşmiyorsa sessizce yamasız bir imaj üretmek yerine **build'i durdurur**.

### TLS (in-transit encryption / Serverless)

`php-memcached`'in hiç TLS desteği yok; eklentide `OPT_USE_TLS` diye bir
seçenek bulunmuyor. ElastiCache Serverless TLS'i *zorunlu* tuttuğu için imaj
bunun yerine stunnel üzerinden tünel açar:

```
PHP → 127.0.0.1:11211 → stunnel → TLS → node-1:11211
PHP → 127.0.0.1:11212 → stunnel → TLS → node-2:11211
```

`MEMCACHED_TLS=1` verin; entrypoint stunnel yapılandırmasını `MEMCACHED_HOST`'tan
üretir, zinciri sistem güven deposuna karşı doğrular ve ana makine adını
`checkHost`/`sni` ile sabitler. Lokal port eşlemesi (*i*. node → `11211+i`)
`wp-config.php`'de de aynı şekilde hesaplanır; böylece WP-CLI ve konteynerdeki
diğer süreçler aynı tünelleri kullanır. stunnel düşerse bir watchdog onu
yeniden başlatır; `delay = yes` DNS'i her bağlantıda yeniden çözer, böylece
node failover'ı fark edilir.

### Cache anahtarı salt'ı

`WP_CACHE_SALT` her anahtarın başına eklenir. Prod ve staging aynı cluster'ı
paylaşıyorsa her ortam için ayrı verin; yoksa birbirlerinin cache'ini okurlar.
İmaj etiketine eşitlemek her dağıtımda her şeyi geçersiz kılar, bedeli de her
seferinde soğuk bir cache'tir. Kısa tutun: memcached anahtarları 250 baytla
sınırlıdır.

### Doğrulama

```bash
php -i | grep -A2 '^memcached'                        # eklenti yüklü mü?
wp cache type                                         # Memcached yazmalı
wp eval 'wp_cache_set("t",42); var_dump(wp_cache_get("t"));'
echo -e 'stats\r' | nc $NODE 11211 | grep -E 'get_hits|evictions'
```

`get_hits` sıfırda kalıyorsa istemci cluster'a ulaşamıyordur (security group
ya da TLS gerekli ama `MEMCACHED_TLS` verilmemiş). Site çalışmaya devam eder,
ama sessizce cache'siz.

### Bilinen eksikler

- Drop-in, çekirdeğin `wp_cache_get_multiple()` fonksiyonunu değil
  `wp_cache_get_multi()`'yi uygular; çekirdeğin uyumluluk katmanı gerçek bir
  çoklu okuma yapmak yerine döngü kurar.
- Kalıcı bağlantı yok: her PHP isteği yeni bir TCP bağlantısı açar.
- `wp_cache_flush()` tüm cluster'ı, onu paylaşan her task ve her site için
  temizler.

## Üretim notları

- Eklenti/tema kurulumu varsayılan olarak açıktır; tamamen değişmez bir dağıtım
  istiyorsanız dosya sistemini dondurmak için `WORDPRESS_DISALLOW_FILE_MODS=1`
  verin.
- Arka plan otomatik güncellemeleri kapalıdır; açmak için
  `WORDPRESS_AUTOMATIC_UPDATES=1` verin.
- WordPress/WooCommerce'i `Dockerfile`'daki temel imaj etiketlerini
  güncelleyerek yükseltin.
- İlk WordPress kurulumunu ECS Exec üzerinden imajdaki WP-CLI ile yapın
  (`wp core install --locale=tr_TR`).
- RDS Read Replica kullanmak HyperDB ya da LudicrousDB eklentisi gerektirir
  (varsayılan yapılandırma yalnızca Writer endpoint'ini kullanır).
- ECS task'larının eklenti mağazası için dışarı HTTPS erişimine ve
  S3/ElastiCache'e ulaşabilmesine ihtiyacı vardır.
