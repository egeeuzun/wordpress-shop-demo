# Running guide

**English** · [Türkçe](calistirma-kilavuzu.md) · [README](../README.md)

This guide shows how to run the *Yerel Market* demo store, step by step. There
are two ways:

| Where | When to use it | Time | Cost |
| --- | --- | --- | --- |
| **[A. On your computer](#a-on-your-computer-docker-compose)** (Docker Compose) | Trying the store, working on the theme or the seed kit | ~10–15 min | Free |
| **[B. On AWS](#b-on-aws)** (ECS) | The real setup: Frankfurt or the Istanbul Local Zone | ~30–45 min | Billed by the hour |

If this is your first time, start with **A**.

---

## A. On your computer (Docker Compose)

### Overview

```
1. Install Docker  ─►  2. Get the code  ─►  3. Create .env
4. docker compose up -d --build  ─►  5. ./scripts/seed-demo.sh
6. Open http://localhost:8080
```

### A1. Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| [Docker Desktop](https://docs.docker.com/get-docker/) or Docker Engine | current | Must include **Compose v2** (the `docker compose` command, with a space). |
| git | — | To clone the repo. |
| bash | — | `scripts/seed-demo.sh` is a bash script. On Windows, use **WSL 2** (recommended) or Git Bash. |

You also need:

- about **3 GB** of free disk space (images + database);
- port **8080** free on your computer (see [Troubleshooting](#a8-troubleshooting)
  to use another port);
- an internet connection for the first build (it downloads WordPress,
  WooCommerce, Storefront and the Turkish language packs).

Check:

```bash
docker --version
```

```bash
docker compose version
```

Apple Silicon (M1/M2/…) Macs work as they are locally; the image is built for
your own processor. Only images pushed to AWS need `linux/amd64` (see
[B](#b-on-aws)).

### A2. Get the code

```bash
git clone https://github.com/egeeuzun/wordpress-shop-demo.git
```

```bash
cd wordpress-shop-demo
```

### A3. Create the settings file (.env)

```bash
cp .env.example .env
```

The defaults work as they are; you do not need to change anything. The file
holds the local database name, user and password, and commented-out examples
for optional features (S3 media offload, Memcached TLS).

> `.env` is in `.gitignore`. Never commit real passwords or AWS keys.

### A4. Start the containers

```bash
docker compose up -d --build
```

The first build takes **5–10 minutes**; later starts take seconds. Compose
starts three services, a small copy of the AWS setup:

| Service | Plays the role of | Notes |
| --- | --- | --- |
| `wordpress` | the ECS task | Built from the `Dockerfile`; published on port 8080. |
| `db` | RDS / MariaDB | MySQL 8.0; data in the `db_data` volume. |
| `memcached` | ElastiCache | Object cache. |

Wait until the database shows as `healthy`:

```bash
docker compose ps
```

At this point http://localhost:8080 shows the WordPress install screen.
**Do not fill it in**; the next step installs everything for you.

### A5. Fill the store

```bash
./scripts/seed-demo.sh
```

This script:

1. installs WordPress in Turkish (`admin` / `admin123`);
2. sets the site language and the permalinks;
3. switches on WooCommerce and the `shopdemo` theme;
4. runs the seed kit (`seed/seed.php`): 86 products with photos, 150 customers,
   about 330 orders and about 200 reviews.

It takes a few minutes and ends with `Tamamlandı! 🎉` (done). It needs no API
keys and does not send e-mails.

You can run it again at any time: it skips what already exists, so nothing is
duplicated. Options:

```bash
./scripts/seed-demo.sh orders=500 customers=200   # more data
```

```bash
./scripts/seed-demo.sh dry-run > plan.json        # preview only, writes nothing
```

All options (`seed=`, `no-images`, `reset-stock`, `customer-pass=`, …) are in
[`seed/README.md`](../seed/README.md#options).

### A6. Open it

| What | Address | Login |
| --- | --- | --- |
| Store | http://localhost:8080 | — |
| Admin panel | http://localhost:8080/wp-admin | `admin` / `admin123` |
| Demo customer | **Hesabım** (My account) link at the top of the store | `demo.musteri@example.com` / `demo123` |
| Health check | http://localhost:8080/health.php | Should print `OK` |

Things to try:

- Add products to the cart and check out. The checkout asks for a national ID
  (TCKN); only Cash on Delivery and Bank Transfer are offered.
- Log in as the demo customer and look at past orders.
- In wp-admin, look at the orders (**WooCommerce** menu) and the product reviews
  (**Products** menu).

Locally the top bar shows **"Yerel ortam"** (local environment): there is no
ECS metadata or CloudFront in front of it. The zone and edge appear only on
AWS.

### A7. Everyday work

**Logs:**

```bash
docker compose logs -f wordpress
```

**WP-CLI** (always as `www-data`, not root):

```bash
docker compose exec -u www-data wordpress wp plugin list
```

**Database shell:**

```bash
docker compose exec db mysql -u wordpress -pwordpress wordpress
```

**Is the object cache working?**

```bash
docker compose exec -u www-data wordpress wp cache type
```

It should print `Memcached`.

**What needs a rebuild after a change:**

| You changed | What to do |
| --- | --- |
| `wp-content/themes/shopdemo/` | Nothing; it is mounted live. Refresh the page. |
| `seed/` (`products.csv`, `seed.php`, photos) | Nothing; it is mounted live. Run `./scripts/seed-demo.sh` again. |
| `wp-content/mu-plugins/`, `wp-config/`, `docker/`, `Dockerfile` | `docker compose up -d --build` |
| `.env` | `docker compose up -d` |

**Stop and start** (data is kept):

```bash
docker compose stop
```

```bash
docker compose start
```

**Start from scratch** (deletes the database and uploads):

```bash
docker compose down -v
```

Then repeat [A4](#a4-start-the-containers) and [A5](#a5-fill-the-store).

### A8. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `port is already allocated` / `address already in use` on 8080 | Another program uses port 8080. In `docker-compose.yml` change `"8080:80"` to e.g. `"8081:80"`, and `WP_HOME` to `http://localhost:8081`. Then run the seed with `SITE_URL=http://localhost:8081 ./scripts/seed-demo.sh`. |
| `Error establishing a database connection` | The database is not ready yet. Wait until `docker compose ps` shows `db` as `healthy`. |
| `service "wordpress" is not running` from `seed-demo.sh` | Run `docker compose up -d --build` first, from the repo directory. |
| `toomanyrequests` / `429` while pulling images | Docker Hub's anonymous download limit. Run `docker login` and try again, or wait a while. |
| The build fails at the language packs step | The build could not reach `downloads.wordpress.org`, or a translation is missing. Check your internet connection and build again. The build stops on purpose rather than producing an English store. |
| Product or category pages give 404 | `docker compose exec -u www-data wordpress wp rewrite flush` |
| `permission denied` when running `./scripts/seed-demo.sh` | `chmod +x scripts/seed-demo.sh`, or run it with `bash scripts/seed-demo.sh`. |
| On Windows: `bad interpreter` or `\r` errors | Git converted line endings. Clone inside WSL, or run `git config core.autocrlf input` and clone again. |
| The admin password is not `admin123` | The database was installed with another password. Reset it: `docker compose exec -u www-data wordpress wp user update admin --user_pass=admin123`, or start from scratch with `docker compose down -v`. |
| A plugin installed from wp-admin is gone | Locally, plugins installed from the panel live only inside the container. See the commented-out `plugins` volume in `docker-compose.yml`. |

---

## B. On AWS

### B1. Recommended: with the Terraform repo

The infrastructure (VPC, ECS, database, CloudFront, WAF, secrets) is in the
[woocommerce-istanbul-local-zone](https://github.com/egeeuzun/woocommerce-istanbul-local-zone)
repo. Follow its **[running guide](https://github.com/egeeuzun/woocommerce-istanbul-local-zone/blob/main/docs/running-guide.md)**;
it covers everything from an empty AWS account to tearing everything down.

This repo's part in that flow is the **image**. In short:

1. Create the ECR repository with Terraform (step 6 of that guide).
2. From this repo, build and push the image. It **must be `linux/amd64`**:

   ```bash
   REPO_URL=<ECR repository URL, from: terraform output -raw ecr_repository_url>
   ```

   ```bash
   aws ecr get-login-password | docker login --username AWS --password-stdin "${REPO_URL%%/*}"
   ```

   ```bash
   TAG=$(git rev-parse --short HEAD)
   docker buildx build --platform linux/amd64 -t "$REPO_URL:$TAG" --push .
   ```

3. Deploy with `terraform apply -var image_tag=$TAG`.
4. The store fills itself: Terraform sets `SHOP_AUTO_SEED=1`, so the first task
   installs WordPress and runs the seed (see
   [Filling the store on AWS](../README.md#filling-the-store-on-aws)). Give it a
   few minutes after `apply`.
5. Log in as `admin`. The password is **not** `admin123`; read it from SSM:

   ```bash
   aws ssm get-parameter --with-decryption --query Parameter.Value --output text \
     --name "$(terraform output -raw admin_password_ssm_name)"
   ```

> ⚠️ AWS resources are billed by the hour. Tear the environment down with
> `terraform destroy` when you are done (see the Terraform guide).

### B2. By hand, on your own infrastructure

If you run the image on infrastructure you built yourself, this is the minimum:

1. **ECR:** create a repository (e.g. `wordpress-shop-demo`) and push the image
   as shown in [B1](#b1-recommended-with-the-terraform-repo), step 2.
2. **Database:** MySQL 8 or MariaDB. TLS is on by default
   (`WORDPRESS_DB_SSL=1`); RDS supports it.
3. **Task definition:** start from
   [`ecs/taskdef.example.json`](../ecs/taskdef.example.json) and fill in the
   placeholders. Keep the container name `wordpress`.
4. **Environment variables and secrets** (the most important ones):

   | Variable | What |
   | --- | --- |
   | `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER` | Database connection. |
   | `WORDPRESS_DB_PASSWORD` | As a secret (Secrets Manager or SSM). |
   | `WP_HOME` | The public address, e.g. `https://shop.example.com` (use `https://`). |
   | `SHOP_AUTO_SEED` | `1` = install and fill the store at start-up. |
   | `WORDPRESS_ADMIN_PASSWORD` | As a secret; required when `SHOP_AUTO_SEED=1`. |
   | `MEMCACHED_HOST` | Optional; ElastiCache node addresses. |
   | `WP_S3_BUCKET`, `WP_CDN_URL` | Optional; S3 media offload and CDN. |

   The full lists are in the [README](../README.md): auto-seed, media offload,
   object cache.
5. **Load balancer:** set the target group health check path to `/health.php`.
6. **Shared storage:** mount `wp-content/uploads`, `plugins` and `themes` on EFS
   if you run more than one task.
7. **Media on S3 (optional):** follow
   [`ecs/cloudfront-media-oac.md`](../ecs/cloudfront-media-oac.md) and attach
   [`ecs/s3-media-policy.example.json`](../ecs/s3-media-policy.example.json) to
   the task role.
8. **ECS Exec:** to run WP-CLI in a running task:

   ```bash
   aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
     --interactive --command "su -s /bin/sh www-data"
   ```

   If `SHOP_AUTO_SEED` is off, install and fill the store by hand as described
   in [Filling the store on AWS → By hand](../README.md#by-hand).

### B3. Troubleshooting on AWS

| Symptom | Cause and fix |
| --- | --- |
| Task stops with `exec format error` | The image was built for ARM. Build again with `--platform linux/amd64`. |
| 502/503 right after deploy | The first task is still installing WordPress and running the seed. Wait a few minutes; follow the `[auto-seed]` lines in the container log. |
| Store is empty or in English | Check the container log for `[auto-seed]` errors. A failed run is retried on the next task start. |
| `admin123` does not work | Expected on AWS. The password comes from `WORDPRESS_ADMIN_PASSWORD` (SSM); see [B1](#b1-recommended-with-the-terraform-repo), step 5. |
| Redirect loop or mixed-content warnings | Set `WP_HOME` to the `https://` address. |
| Installing plugins from wp-admin fails | Tasks need outbound HTTPS (NAT) to reach `wordpress.org`, and the plugin directories must be writable (EFS). |
| `wp cache type` does not print `Memcached` | `MEMCACHED_HOST` is empty, or the security group blocks port 11211, or the cluster needs TLS and `MEMCACHED_TLS=1` is missing. |

---

## Command summary

```bash
# Local: first run
cp .env.example .env
docker compose up -d --build
./scripts/seed-demo.sh

# Local: everyday
docker compose logs -f wordpress
docker compose exec -u www-data wordpress wp <command>
docker compose stop            # stop, keep data
docker compose start
docker compose down -v         # delete everything

# AWS: image (amd64)
docker buildx build --platform linux/amd64 -t "$REPO_URL:$TAG" --push .
```
