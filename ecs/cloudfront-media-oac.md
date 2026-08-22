# Medya bucket'ı + CloudFront (OAC) kurulumu

WP Offload Media yüklenen dosyaları S3'e kopyalar; sunum CloudFront üzerinden
yapılır. Bucket'ı public açmak gerekmez — CloudFront Origin Access Control (OAC)
kullanın.

## 1. Bucket

```bash
BUCKET=ornek-magaza-medya
REGION=eu-west-1

aws s3api create-bucket --bucket "$BUCKET" --region "$REGION" \
  --create-bucket-configuration LocationConstraint="$REGION"

# Public erişim tamamen kapalı kalsın
aws s3api put-public-access-block --bucket "$BUCKET" \
  --public-access-block-configuration \
  BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true

# Eski/çok sürümlü nesneler maliyet üretmesin (opsiyonel)
aws s3api put-bucket-lifecycle-configuration --bucket "$BUCKET" \
  --lifecycle-configuration '{"Rules":[{"ID":"abort-multipart","Status":"Enabled","Filter":{},"AbortIncompleteMultipartUpload":{"DaysAfterInitiation":7}}]}'
```

## 2. CloudFront

İki seçenek var:

**a) Tek dağıtım (önerilen — `WP_CDN_URL` ile aynı alan adı)**

Mevcut dağıtıma ikinci bir origin ekleyin:

- Origin: `ornek-magaza-medya.s3.eu-west-1.amazonaws.com`, Origin access: **OAC**
- Cache behavior: Path pattern `/wp-content/uploads/*` → bu origin
  - Viewer protocol: Redirect HTTP to HTTPS
  - Allowed methods: GET, HEAD
  - Cache policy: `CachingOptimized`
- Varsayılan (`Default (*)`) behavior ALB origin'inde kalır.

Bu durumda `WP_S3_DELIVERY_DOMAIN` verilmese de olur; `WP_CDN_URL` kullanılır.

**b) Ayrı medya dağıtımı**

`cdn-media.ornek-magaza.com` için sadece S3 origin'i olan yeni bir dağıtım açın
ve task definition'da `WP_S3_DELIVERY_DOMAIN=https://cdn-media.ornek-magaza.com`
verin.

## 3. Bucket policy (OAC)

CloudFront dağıtımını oluşturduktan sonra bucket'a şu policy'yi ekleyin:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowCloudFrontRead",
      "Effect": "Allow",
      "Principal": { "Service": "cloudfront.amazonaws.com" },
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::<MEDIA_BUCKET_NAME>/*",
      "Condition": {
        "StringEquals": {
          "AWS:SourceArn": "arn:aws:cloudfront::<ACCOUNT_ID>:distribution/<DISTRIBUTION_ID>"
        }
      }
    }
  ]
}
```

## 4. Task role

`ecs/s3-media-policy.example.json` içindeki policy'yi `wordpressTaskRole`'e
ekleyin. Konteynerde erişim anahtarı tutulmaz; eklenti `use-server-roles` ile
task role kimlik bilgilerini kullanır.

## 5. Mevcut medyayı taşıma

Lite sürüm **yalnızca yeni yüklemeleri** offload eder; halihazırda EFS'te olan
medyayı toplu taşıyan "Offload remaining media" aracı Pro sürüme aittir.
Sıfırdan kurulan bir mağazada bu bir sorun değil. Mevcut bir siteyi taşıyorsanız
iki seçenek var:

1. Dosyaları elle S3'e kopyalayın ve eski URL'leri CloudFront'ta
   `/wp-content/uploads/*` behavior'ı üzerinden S3'e yönlendirin — DB'deki
   URL'ler değişmediği için çalışır:

   ```bash
   # ECS Exec ile konteyner içinden
   aws s3 sync /var/www/html/wp-content/uploads "s3://$BUCKET/wp-content/uploads"
   ```

   (Bu durumda eklenti bu dosyaları "offloaded" olarak işaretlemez; yerel
   kopyaları silmeden bırakın.)

2. Ya da WP Offload Media Pro lisansı alıp toplu offload aracını kullanın.
