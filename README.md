# WordPress Shop Demo — Yerel Market

A WooCommerce grocery store, **Yerel Market**, packaged as a Docker image for
AWS ECS. It is the demo app for a project that moves a high-availability
WordPress setup from Frankfurt to the AWS Istanbul Local Zone, and then checks
that customer data really stays in Türkiye.

Everything in the store is fake. There are no real customers, no real brands
and no real payments. Nothing is sold.

## What's in the store

- **86 products** in 9 grocery categories (fruit & vegetables, dairy, meat,
  pantry, snacks, drinks, cleaning, personal care, baby). Each has a SKU
  (`LZ-0001`…), price, stock, weight and unit. A few are on sale. Each product
  has its own freely licensed photo (Wikimedia Commons), stored in the repo
  (`seed/photos/`); see [`seed/README.md`](seed/README.md#product-photos).
- **150 customers and 300 orders** from the last 90 days. Names, Istanbul
  addresses, `+90 5xx` phone numbers and `@example.com` e-mails are all
  randomly generated.
- **A national ID (TCKN) on every order.** Turkish shops often ask for it to
  issue e-invoices, so it gives the project a realistic piece of sensitive
  data to track. The numbers are random; they just pass the checksum rule.
- **Offline payments only:** Cash on Delivery and Bank Transfer. Other
  payment methods are never shown, even if enabled in wp-admin.
- **A demo notice** ("Demo mağazadır, satış yapılmaz" — demo store, nothing is
  sold) in the site tagline, the top banner and the footer.
- **A demo customer account** with a few orders:
  `demo.musteri@example.com` / `demo123`.

## Quick start (local)

```bash
cp .env.example .env
docker compose up -d --build
./scripts/seed-demo.sh
```

- **Store:** http://localhost:8080
- **Admin:** http://localhost:8080/wp-admin (`admin` / `admin123`)

`seed-demo.sh` installs WordPress in Turkish, then fills the store. You can run it again: it skips products, customers and orders that
already exist, so nothing is duplicated.

```bash
./scripts/seed-demo.sh orders=500 customers=200   # more data
./scripts/seed-demo.sh dry-run > plan.json        # preview, writes nothing
```

## Filling the store on AWS

The seed kit is built into the image at `/usr/src/shop-seed/`, so the same
steps work in a running ECS task. Open a shell in it:

```bash
aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
  --interactive --command "su -s /bin/sh www-data"
```

Then install WordPress (skip if already installed), switch on the store and
fill it:

```bash
wp core install --url="$WP_HOME" --title="Yerel Market" --locale=tr_TR \
  --admin_user=admin --admin_password='<strong password>' \
  --admin_email=demo@example.com --skip-email
wp plugin activate woocommerce
wp theme activate shopdemo
wp eval-file /usr/src/shop-seed/seed.php
```

None of this needs API keys. All options, and which database tables hold the
personal data, are in [`seed/README.md`](seed/README.md).

## Language

The store is in Turkish. The Turkish (`tr_TR`) language packs for WordPress,
WooCommerce and Storefront (and WP Offload Media Lite, if a translation exists)
are built into the image by `docker/install-translations.sh`. It reads the
installed versions and downloads the matching packages, so the translations
always fit the code. If the core, WooCommerce or Storefront translation is
missing, the build fails instead of quietly shipping an English store.

Because the packs are in the image:

- the site needs no connection to wordpress.org to be in Turkish;
- they survive task replacement on ECS (`wp-content/languages` is not on EFS
  or any other shared disk);
- the seed kit sets the site language to `tr_TR`.

Automatic translation updates are off, like other background updates. New
translations arrive with the next image build.

## Architecture

```text
User → Route53 → WAF → CloudFront ─┬─ /wp-content/uploads/* → S3 (media, OAC)
                                   └─ /*  → ALB → ECS Fargate (2 AZ)
                                                  ├── RDS MySQL (Primary + Read Replica)
                                                  ├── ElastiCache Memcached
                                                  └── EFS (uploads, plugins, themes)

CI/CD: GitHub → CodeBuild → CodePipeline → ECR → ECS
```

## Key Principles

- **Baked Baseline, Extensible at Runtime:** Core, WooCommerce, WP Offload Media Lite, Storefront and the custom theme (`shopdemo`) are baked into the Docker image. Installing extra plugins/themes from the WordPress.org store in `wp-admin` is enabled (`DISALLOW_FILE_MODS=0`) — those land on EFS so every task sees them.
- **Media on S3:** Uploads are offloaded to S3 by WP Offload Media Lite and served through CloudFront, so static objects never touch PHP or EFS.
- **Shared Storage:** `wp-content/uploads`, `plugins` and `themes` are mounted on EFS across Availability Zones.
- **Environment Config:** Managed via environment variables and AWS Secrets Manager.
- **Caching:** Automatically loads the Memcached object cache drop-in if `MEMCACHED_HOST` is set — multi-node, consistent hashing, optional TLS.

## Project Structure

```text
├── Dockerfile               # Fargate container image
├── buildspec.yml            # CodeBuild specification
├── docker-compose.yml       # Local development setup
├── docker/                  # Entrypoint, PHP settings, health check, drop-in patch, language packs
├── wp-config/wp-config.php  # Env-based WordPress config
├── wp-content/
│   ├── themes/shopdemo/     # Storefront child theme: modern grocery layout, system fonts only
│   └── mu-plugins/          # S3 auto-activation, CDN redirect, XML-RPC off, store rules (offline payments, TCKN)
├── seed/                    # Demo data kit baked into the image (/usr/src/shop-seed)
│   ├── products.csv         # 86 grocery products (WooCommerce CSV importer format)
│   └── seed.php             # WP-CLI seeder: store setup, catalog, synthetic customers & orders
├── scripts/seed-demo.sh     # Local setup: core install (Turkish) + seed.php
└── ecs/
    ├── taskdef.example.json      # ECS Task Definition template
    ├── s3-media-policy.example.json  # Task role policy for the media bucket
    └── cloudfront-media-oac.md      # S3 bucket + CloudFront (OAC) setup guide
```

## AWS CI/CD Setup

1. **ECR:** Create repository `wordpress-shop-demo`.
2. **CodeBuild:** Connect repository, enable *Privileged mode*, and grant ECR push permissions.
3. **Secrets Manager:** Create secrets for DB password and WordPress salts.
4. **Task Definition:** Fill in placeholders in `ecs/taskdef.example.json`. Keep container name as `wordpress`.
5. **CodePipeline:** Pipeline flow: GitHub → CodeBuild → ECS (using `imagedefinitions.json`).
6. **ALB Health Check:** Set target group path to `/health.php`.
7. **Environment Variables:** Set `WP_HOME` (CloudFront URL), `WP_CDN_URL` and `WP_S3_BUCKET`.
8. **Media Bucket:** Follow `ecs/cloudfront-media-oac.md` and attach the task role policy.

*Every `git push` will automatically trigger a deployment to ECS Fargate.*

## Media Offload to S3

Uploads are pushed to S3 and served from CloudFront instead of EFS/PHP — cheaper
storage, no per-request EFS throughput, and identical URLs across all tasks.

1. Create the bucket and CloudFront behavior: see `ecs/cloudfront-media-oac.md`.
2. Attach `ecs/s3-media-policy.example.json` to the ECS **task role** — no access
   keys are stored anywhere; the plugin authenticates with `use-server-roles`.
3. Set these in the task definition:

   | Variable | Meaning |
   | --- | --- |
   | `WP_S3_BUCKET` | Media bucket name. **Empty = offload disabled** (plugin stays inactive). |
   | `WP_S3_REGION` | Bucket region (default `eu-west-1`). |
   | `WP_S3_DELIVERY_DOMAIN` | Domain that serves the media. Falls back to `WP_CDN_URL`. |
   | `WP_S3_OBJECT_PREFIX` | Key prefix (default `wp-content/uploads/`). |
   | `WP_S3_REMOVE_LOCAL` | `1` (default) deletes the local copy after a successful offload. |

The plugin is configured entirely through `AS3CF_SETTINGS` in `wp-config.php`, so
the settings screen is read-only and no DB state has to be seeded. The mu-plugin
force-activates it via the `option_active_plugins` filter when `WP_S3_BUCKET` is
set, which keeps every task identical without an activation step.

**Lite limits:** only *new* uploads are offloaded; bulk-migrating an existing
library is a Pro feature (workaround in `ecs/cloudfront-media-oac.md`).

When offload is on, the mu-plugin's `upload_dir` CDN rewrite is skipped — the
plugin already rewrites URLs, and both together would double-rewrite the path.

## Installing Plugins & Themes from wp-admin

Enabled by default: `FS_METHOD=direct` plus `www-data`-owned content directories,
so *Plugins → Add New* works without FTP credentials.

- `wp-content/plugins` and `wp-content/themes` are EFS mounts in
  `ecs/taskdef.example.json`. Without them an install would only exist in the one
  container that performed it and would vanish on the next deployment.
- The entrypoint enables `opcache.validate_timestamps` whenever file mods are
  allowed, so newly installed/updated PHP files take effect without a restart.
- Tasks need outbound HTTPS (NAT gateway or equivalent) to reach
  `api.wordpress.org` and `downloads.wordpress.org`.
- The file editor stays disabled (`DISALLOW_FILE_EDIT`) — installs, yes; editing
  PHP in the browser, no.
- On an image update, a plugin already present on EFS is **not** overwritten by
  the image copy. Update baked plugins by bumping them in the `Dockerfile` and
  removing the EFS copy, or update them from wp-admin.
- To lock everything down again: `WORDPRESS_DISALLOW_FILE_MODS=1`.

## Object Cache (ElastiCache Memcached)

The image ships the PECL `memcached` extension plus the Memcached Redux drop-in.
The drop-in is only installed (as `wp-content/object-cache.php`) when
`MEMCACHED_HOST` is set, so local runs without it are unaffected.

| Variable | Meaning |
| --- | --- |
| `MEMCACHED_HOST` | One node, or several separated by commas. Empty = object cache off. |
| `MEMCACHED_PORT` | Port for nodes listed without one (default `11211`). |
| `MEMCACHED_TLS` | `1` routes every node through a local stunnel TLS tunnel. |
| `MEMCACHED_TLS_VERIFY` | `0` disables certificate chain/hostname verification (debug only). |
| `WP_CACHE_SALT` | Cache key prefix. Defaults to the `WP_HOME` host. |

### List the nodes, not the configuration endpoint

The PECL `memcached` extension does **not** implement ElastiCache
auto-discovery — it treats the configuration endpoint as one ordinary node. On a
single-node cluster that is harmless; on a multi-node cluster all traffic would
pile onto one node. So list the node endpoints:

```
MEMCACHED_HOST=n1.abc.0001.euw1.cache.amazonaws.com:11211,n2.abc.0001.euw1.cache.amazonaws.com:11211
```

`docker/patch-object-cache.sh` injects the client options the drop-in never sets
(it stopped being maintained in 2020), at image build time:

- **Ketama / consistent hashing** — without it, `modula` distribution remaps
  nearly every key when a node joins or leaves, emptying the cache and dumping
  the load onto RDS.
- **`REMOVE_FAILED_SERVERS` + connect/send/receive timeouts (500 ms)** — an
  unreachable node degrades into a cache miss instead of stalling requests.

The patch anchors on a specific line of upstream code and **fails the build** if
it no longer matches, rather than silently producing an unpatched image.

### TLS (in-transit encryption / Serverless)

`php-memcached` has no TLS support at all — there is no `OPT_USE_TLS` in the
extension. Since ElastiCache Serverless *requires* TLS, the image tunnels
through stunnel instead:

```
PHP → 127.0.0.1:11211 → stunnel → TLS → node-1:11211
PHP → 127.0.0.1:11212 → stunnel → TLS → node-2:11211
```

Set `MEMCACHED_TLS=1` and the entrypoint generates the stunnel config from
`MEMCACHED_HOST`, verifying the chain against the system trust store and pinning
the hostname with `checkHost`/`sni`. The local port mapping (node *i* →
`11211+i`) is computed identically in `wp-config.php`, so WP-CLI and any other
process in the container use the same tunnels. A watchdog restarts stunnel if it
dies; `delay = yes` re-resolves DNS per connection so node failover is picked up.

### Cache key salt

`WP_CACHE_SALT` prefixes every key. Set it per environment when prod and staging
share a cluster — otherwise they read each other's cache. Setting it to the image
tag also invalidates everything on deploy, at the cost of a cold cache each time.
Keep it short: memcached keys are capped at 250 bytes.

### Verifying

```bash
php -i | grep -A2 '^memcached'                        # extension loaded?
wp cache type                                         # should print Memcached
wp eval 'wp_cache_set("t",42); var_dump(wp_cache_get("t"));'
echo -e 'stats\r' | nc $NODE 11211 | grep -E 'get_hits|evictions'
```

If `get_hits` stays at zero the client cannot reach the cluster (security group,
or TLS required but `MEMCACHED_TLS` unset) — the site keeps working, silently
uncached.

### Known gaps

- The drop-in implements `wp_cache_get_multi()`, not core's
  `wp_cache_get_multiple()`; core's compat shim loops instead of issuing a real
  multi-get.
- No persistent connections — each PHP request opens a new TCP connection.
- `wp_cache_flush()` flushes the entire cluster, for every task and every site
  sharing it.

## Production Notes

- Plugin/theme installs are enabled by default; set `WORDPRESS_DISALLOW_FILE_MODS=1`
  to freeze the file system if you want a strictly immutable deployment.
- Background auto-updates are off; set `WORDPRESS_AUTOMATIC_UPDATES=1` to enable them.
- Upgrade WordPress/WooCommerce by updating base image tags in `Dockerfile`.
- Execute initial WP setup via ECS Exec using the pre-installed WP-CLI (`wp core install --locale=tr_TR`).
- Using RDS Read Replicas requires HyperDB or LudicrousDB plugins (default config uses Writer endpoint only).
- ECS tasks need outbound HTTPS for the plugin store, and reachability to S3/ElastiCache.
