# Çalıştırma kılavuzu

[English](running-guide.md) · **Türkçe** · [README](../README.tr.md)

Bu kılavuz *Yerel Market* demo mağazasını adım adım nasıl çalıştıracağınızı
anlatır. İki yol var:

| Nerede | Ne zaman | Süre | Maliyet |
| --- | --- | --- | --- |
| **[A. Kendi bilgisayarınızda](#a-kendi-bilgisayarınızda-docker-compose)** (Docker Compose) | Mağazayı denemek, tema veya seed kiti üzerinde çalışmak | ~10–15 dk | Ücretsiz |
| **[B. AWS'de](#b-awsde)** (ECS) | Asıl kurulum: Frankfurt ya da İstanbul Local Zone | ~30–45 dk | Saatlik ücretlendirilir |

İlk kez deniyorsanız **A** ile başlayın.

---

## A. Kendi bilgisayarınızda (Docker Compose)

### Genel bakış

```
1. Docker'ı kurun  ─►  2. Kodu indirin  ─►  3. .env oluşturun
4. docker compose up -d --build  ─►  5. ./scripts/seed-demo.sh
6. http://localhost:8080 adresini açın
```

### A1. Gereksinimler

| Araç | Sürüm | Not |
| --- | --- | --- |
| [Docker Desktop](https://docs.docker.com/get-docker/) veya Docker Engine | güncel | **Compose v2** olmalı (boşluklu `docker compose` komutu). |
| git | — | Repoyu klonlamak için. |
| bash | — | `scripts/seed-demo.sh` bir bash betiği. Windows'ta **WSL 2** (önerilen) ya da Git Bash kullanın. |

Ayrıca gerekenler:

- yaklaşık **3 GB** boş disk (imajlar + veritabanı);
- bilgisayarınızda boş bir **8080** portu (başka port kullanmak için
  [Sorun giderme](#a8-sorun-giderme) bölümüne bakın);
- ilk build için internet bağlantısı (WordPress, WooCommerce, Storefront ve
  Türkçe dil paketleri indirilir).

Kontrol edin:

```bash
docker --version
```

```bash
docker compose version
```

Apple Silicon (M1/M2/…) Mac'lerde lokal çalıştırma olduğu gibi çalışır; imaj
kendi işlemcinize göre build edilir. Yalnızca AWS'ye gönderilen imajın
`linux/amd64` olması gerekir (bkz. [B](#b-awsde)).

### A2. Kodu indirin

```bash
git clone https://github.com/egeeuzun/wordpress-shop-demo.git
```

```bash
cd wordpress-shop-demo
```

### A3. Ayar dosyasını (.env) oluşturun

```bash
cp .env.example .env
```

Varsayılan değerler olduğu gibi çalışır; hiçbir şeyi değiştirmeniz gerekmez.
Dosyada lokal veritabanının adı, kullanıcısı ve parolası ile isteğe bağlı
özellikler (S3 medya offload, Memcached TLS) için yorum satırı hâlinde örnekler
bulunur.

> `.env` dosyası `.gitignore`'da. Gerçek parolaları veya AWS anahtarlarını asla
> commit etmeyin.

### A4. Konteynerleri başlatın

```bash
docker compose up -d --build
```

İlk build **5–10 dakika** sürer; sonraki açılışlar saniyeler içinde olur.
Compose, AWS kurulumunun küçük bir kopyası olan üç servis başlatır:

| Servis | AWS'deki karşılığı | Not |
| --- | --- | --- |
| `wordpress` | ECS task'ı | `Dockerfile`'dan build edilir; 8080 portunda yayınlanır. |
| `db` | RDS / MariaDB | MySQL 8.0; veriler `db_data` volume'unda. |
| `memcached` | ElastiCache | Object cache. |

Veritabanı `healthy` görünene kadar bekleyin:

```bash
docker compose ps
```

Bu noktada http://localhost:8080 WordPress kurulum ekranını gösterir. **Formu
doldurmayın**; bir sonraki adım her şeyi sizin yerinize kurar.

### A5. Mağazayı doldurun

```bash
./scripts/seed-demo.sh
```

Bu betik:

1. WordPress'i Türkçe kurar (`admin` / `admin123`);
2. site dilini ve kalıcı bağlantıları ayarlar;
3. WooCommerce'i ve `shopdemo` temasını etkinleştirir;
4. seed kitini (`seed/seed.php`) çalıştırır: fotoğraflı 86 ürün, 150 müşteri,
   yaklaşık 330 sipariş ve yaklaşık 200 değerlendirme.

Birkaç dakika sürer ve `Tamamlandı! 🎉` ile biter. API anahtarı gerekmez,
e-posta gönderilmez.

İstediğiniz zaman tekrar çalıştırabilirsiniz: var olanları atlar, hiçbir şey
iki kez oluşmaz. Seçenekler:

```bash
./scripts/seed-demo.sh orders=500 customers=200   # daha fazla veri
```

```bash
./scripts/seed-demo.sh dry-run > plan.json        # yalnızca önizleme, hiçbir şey yazmaz
```

Tüm seçenekler (`seed=`, `no-images`, `reset-stock`, `customer-pass=`, …)
[`seed/README.tr.md`](../seed/README.tr.md#seçenekler) dosyasında.

### A6. Açın

| Ne | Adres | Giriş |
| --- | --- | --- |
| Mağaza | http://localhost:8080 | — |
| Yönetim paneli | http://localhost:8080/wp-admin | `admin` / `admin123` |
| Demo müşteri | Mağazanın üstündeki **Hesabım** bağlantısı | `demo.musteri@example.com` / `demo123` |
| Sağlık kontrolü | http://localhost:8080/health.php | `OK` yazmalı |

Deneyebilecekleriniz:

- Sepete ürün ekleyip ödemeye geçin. Ödeme sayfası T.C. kimlik numarası ister;
  yalnızca Kapıda Ödeme ve Banka Havalesi sunulur.
- Demo müşteri olarak giriş yapıp geçmiş siparişlere bakın.
- wp-admin'de siparişlere (**WooCommerce** menüsü) ve ürün değerlendirmelerine
  (**Ürünler** menüsü) göz atın.

Lokalde üst bant **"Yerel ortam"** gösterir: önünde ECS metadata'sı ya da
CloudFront yok. Zone ve edge bilgisi yalnızca AWS'de görünür.

### A7. Günlük kullanım

**Loglar:**

```bash
docker compose logs -f wordpress
```

**WP-CLI** (her zaman `www-data` olarak, root değil):

```bash
docker compose exec -u www-data wordpress wp plugin list
```

**Veritabanı kabuğu:**

```bash
docker compose exec db mysql -u wordpress -pwordpress wordpress
```

**Object cache çalışıyor mu?**

```bash
docker compose exec -u www-data wordpress wp cache type
```

`Memcached` yazmalı.

**Bir değişiklikten sonra ne yapmalı:**

| Değiştirdiğiniz | Yapılacak |
| --- | --- |
| `wp-content/themes/shopdemo/` | Hiçbir şey; canlı mount edili. Sayfayı yenileyin. |
| `seed/` (`products.csv`, `seed.php`, fotoğraflar) | Hiçbir şey; canlı mount edili. `./scripts/seed-demo.sh`'yi tekrar çalıştırın. |
| `wp-content/mu-plugins/`, `wp-config/`, `docker/`, `Dockerfile` | `docker compose up -d --build` |
| `.env` | `docker compose up -d` |

**Durdurma ve başlatma** (veriler korunur):

```bash
docker compose stop
```

```bash
docker compose start
```

**Sıfırdan başlama** (veritabanını ve yüklenen dosyaları siler):

```bash
docker compose down -v
```

Sonra [A4](#a4-konteynerleri-başlatın) ve [A5](#a5-mağazayı-doldurun)
adımlarını tekrarlayın.

### A8. Sorun giderme

| Belirti | Nedeni ve çözümü |
| --- | --- |
| 8080'de `port is already allocated` / `address already in use` | 8080 portunu başka bir program kullanıyor. `docker-compose.yml`'de `"8080:80"`'i örneğin `"8081:80"`, `WP_HOME`'u da `http://localhost:8081` yapın. Seed'i `SITE_URL=http://localhost:8081 ./scripts/seed-demo.sh` ile çalıştırın. |
| `Error establishing a database connection` | Veritabanı henüz hazır değil. `docker compose ps` çıktısında `db` `healthy` olana kadar bekleyin. |
| `seed-demo.sh` çalışırken `service "wordpress" is not running` | Önce repo dizininde `docker compose up -d --build` çalıştırın. |
| İmaj çekerken `toomanyrequests` / `429` | Docker Hub'ın anonim indirme sınırı. `docker login` yapıp tekrar deneyin ya da bir süre bekleyin. |
| Build dil paketleri adımında duruyor | Build `downloads.wordpress.org`'a ulaşamadı ya da bir çeviri eksik. İnternet bağlantınızı kontrol edip tekrar build edin. Build, İngilizce bir mağaza üretmek yerine bilerek durur. |
| Ürün veya kategori sayfaları 404 veriyor | `docker compose exec -u www-data wordpress wp rewrite flush` |
| `./scripts/seed-demo.sh` çalıştırırken `permission denied` | `chmod +x scripts/seed-demo.sh` ya da `bash scripts/seed-demo.sh` ile çalıştırın. |
| Windows'ta `bad interpreter` veya `\r` hataları | Git satır sonlarını değiştirmiş. WSL içinde klonlayın ya da `git config core.autocrlf input` yapıp yeniden klonlayın. |
| Yönetici parolası `admin123` değil | Veritabanı başka bir parolayla kurulmuş. Sıfırlayın: `docker compose exec -u www-data wordpress wp user update admin --user_pass=admin123` ya da `docker compose down -v` ile sıfırdan başlayın. |
| wp-admin'den kurulan eklenti kayboldu | Lokalde panelden kurulan eklentiler yalnızca konteynerin içinde yaşar. `docker-compose.yml`'deki yorum satırındaki `plugins` volume'una bakın. |

---

## B. AWS'de

### B1. Önerilen: Terraform reposuyla

Altyapı (VPC, ECS, veritabanı, CloudFront, WAF, gizli değerler)
[woocommerce-istanbul-local-zone](https://github.com/egeeuzun/woocommerce-istanbul-local-zone)
reposunda. Onun **[çalıştırma kılavuzunu](https://github.com/egeeuzun/woocommerce-istanbul-local-zone/blob/main/docs/calistirma-kilavuzu.md)**
izleyin; boş bir AWS hesabından her şeyi kaldırmaya kadar her adımı anlatır.

Bu reponun o akıştaki payı **imaj**dır. Kısaca:

1. ECR reposunu Terraform ile oluşturun (o kılavuzun 6. adımı).
2. Bu repodan imajı build edip gönderin. İmaj **`linux/amd64` olmalı**:

   ```bash
   REPO_URL=<ECR repo adresi, şuradan: terraform output -raw ecr_repository_url>
   ```

   ```bash
   aws ecr get-login-password | docker login --username AWS --password-stdin "${REPO_URL%%/*}"
   ```

   ```bash
   TAG=$(git rev-parse --short HEAD)
   docker buildx build --platform linux/amd64 -t "$REPO_URL:$TAG" --push .
   ```

3. `terraform apply -var image_tag=$TAG` ile kurun.
4. Mağaza kendini doldurur: Terraform `SHOP_AUTO_SEED=1` verir, böylece ilk task
   WordPress'i kurar ve seed'i çalıştırır (bkz.
   [Mağazayı AWS'de doldurma](../README.tr.md#mağazayı-awsde-doldurma)).
   `apply`'dan sonra birkaç dakika bekleyin.
5. `admin` olarak giriş yapın. Parola `admin123` **değildir**; SSM'den okuyun:

   ```bash
   aws ssm get-parameter --with-decryption --query Parameter.Value --output text \
     --name "$(terraform output -raw admin_password_ssm_name)"
   ```

> ⚠️ AWS kaynakları saatlik ücretlendirilir. İşiniz bitince ortamı
> `terraform destroy` ile kaldırın (Terraform kılavuzuna bakın).

### B2. Elle, kendi altyapınızda

İmajı kendi kurduğunuz bir altyapıda çalıştıracaksanız en azından şunlar
gerekir:

1. **ECR:** bir repo oluşturun (ör. `wordpress-shop-demo`) ve imajı
   [B1](#b1-önerilen-terraform-reposuyla) 2. adımdaki gibi gönderin.
2. **Veritabanı:** MySQL 8 veya MariaDB. TLS varsayılan olarak açık
   (`WORDPRESS_DB_SSL=1`); RDS bunu destekler.
3. **Task definition:**
   [`ecs/taskdef.example.json`](../ecs/taskdef.example.json) dosyasından başlayıp
   yer tutucuları doldurun. Konteyner adını `wordpress` olarak bırakın.
4. **Ortam değişkenleri ve gizli değerler** (en önemlileri):

   | Değişken | Ne |
   | --- | --- |
   | `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER` | Veritabanı bağlantısı. |
   | `WORDPRESS_DB_PASSWORD` | Gizli değer olarak (Secrets Manager veya SSM). |
   | `WP_HOME` | Herkese açık adres, ör. `https://magaza.example.com` (`https://` kullanın). |
   | `SHOP_AUTO_SEED` | `1` = açılışta mağazayı kur ve doldur. |
   | `WORDPRESS_ADMIN_PASSWORD` | Gizli değer olarak; `SHOP_AUTO_SEED=1` ise zorunlu. |
   | `MEMCACHED_HOST` | İsteğe bağlı; ElastiCache node adresleri. |
   | `WP_S3_BUCKET`, `WP_CDN_URL` | İsteğe bağlı; S3 medya offload ve CDN. |

   Tam listeler [README](../README.tr.md)'de: otomatik seed, medya offload,
   object cache.
5. **Yük dengeleyici:** target group sağlık kontrolü yolunu `/health.php`
   yapın.
6. **Paylaşılan depolama:** birden fazla task çalıştıracaksanız
   `wp-content/uploads`, `plugins` ve `themes` dizinlerini EFS'e mount edin.
7. **S3'te medya (isteğe bağlı):**
   [`ecs/cloudfront-media-oac.md`](../ecs/cloudfront-media-oac.md) adımlarını
   izleyin ve
   [`ecs/s3-media-policy.example.json`](../ecs/s3-media-policy.example.json)
   politikasını task role'e bağlayın.
8. **ECS Exec:** çalışan bir task'ta WP-CLI kullanmak için:

   ```bash
   aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
     --interactive --command "su -s /bin/sh www-data"
   ```

   `SHOP_AUTO_SEED` kapalıysa mağazayı
   [Mağazayı AWS'de doldurma → Elle](../README.tr.md#elle) bölümündeki gibi
   elle kurup doldurun.

### B3. AWS'de sorun giderme

| Belirti | Nedeni ve çözümü |
| --- | --- |
| Task `exec format error` ile duruyor | İmaj ARM için build edilmiş. `--platform linux/amd64` ile tekrar build edin. |
| Kurulumdan hemen sonra 502/503 | İlk task hâlâ WordPress'i kuruyor ve seed'i çalıştırıyor. Birkaç dakika bekleyin; konteyner logundaki `[auto-seed]` satırlarını izleyin. |
| Mağaza boş ya da İngilizce | Konteyner logunda `[auto-seed]` hatalarına bakın. Başarısız bir çalıştırma bir sonraki task açılışında tekrar denenir. |
| `admin123` çalışmıyor | AWS'de beklenen durum. Parola `WORDPRESS_ADMIN_PASSWORD`'dan (SSM) gelir; bkz. [B1](#b1-önerilen-terraform-reposuyla) 5. adım. |
| Yönlendirme döngüsü ya da karışık içerik (mixed content) uyarıları | `WP_HOME`'u `https://` adres olarak verin. |
| wp-admin'den eklenti kurulamıyor | Task'ların `wordpress.org`'a dışarı HTTPS (NAT) erişimi olmalı ve eklenti dizinleri yazılabilir (EFS) olmalı. |
| `wp cache type` `Memcached` yazmıyor | `MEMCACHED_HOST` boş, ya da security group 11211 portunu engelliyor, ya da cluster TLS istiyor ve `MEMCACHED_TLS=1` eksik. |

---

## Komut özeti

```bash
# Lokal: ilk çalıştırma
cp .env.example .env
docker compose up -d --build
./scripts/seed-demo.sh

# Lokal: günlük
docker compose logs -f wordpress
docker compose exec -u www-data wordpress wp <komut>
docker compose stop            # durdur, veriler kalsın
docker compose start
docker compose down -v         # her şeyi sil

# AWS: imaj (amd64)
docker buildx build --platform linux/amd64 -t "$REPO_URL:$TAG" --push .
```
