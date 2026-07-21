# WordPress Shop Demo — AWS HA CI/CD Template

WooCommerce-based WordPress shop template designed for automated deployment to an AWS High Availability (HA) architecture.

## Architecture

```text
User → Route53 → WAF → CloudFront → ALB → ECS Fargate (2 AZ)
                                          ├── RDS MySQL (Primary + Read Replica)
                                          ├── ElastiCache Memcached
                                          └── EFS (uploads)

CI/CD: GitHub → CodeBuild → CodePipeline → ECR → ECS
```

## Key Principles

- **Immutable Code:** Core, plugins, and custom theme (`shopdemo`) are baked into the Docker image. Updates are applied strictly via CI/CD.
- **Shared Storage:** Only `wp-content/uploads` is mounted on EFS across Availability Zones.
- **Environment Config:** Managed via environment variables and AWS Secrets Manager.
- **Caching:** Automatically loads Memcached (`object-cache.php`) if `MEMCACHED_HOST` is set.

## Project Structure

```text
├── Dockerfile               # Fargate container image
├── buildspec.yml            # CodeBuild specification
├── docker-compose.yml       # Local development setup
├── docker/                  # Entrypoint, PHP settings, health check
├── wp-config/wp-config.php  # Env-based WordPress config
├── wp-content/
│   ├── themes/shopdemo/     # Custom Storefront child theme
│   └── mu-plugins/          # CDN redirect, XML-RPC disable
├── scripts/seed-demo.sh     # Demo content seeder
└── ecs/taskdef.example.json # ECS Task Definition template
```

## Local Development

```bash
cp .env.example .env
docker compose up -d --build
./scripts/seed-demo.sh
```

- **Storefront:** http://localhost:8080
- **Admin Panel:** http://localhost:8080/wp-admin (`admin` / `admin123`)

## AWS CI/CD Setup

1. **ECR:** Create repository `wordpress-shop-demo`.
2. **CodeBuild:** Connect repository, enable *Privileged mode*, and grant ECR push permissions.
3. **Secrets Manager:** Create secrets for DB password and WordPress salts.
4. **Task Definition:** Fill in placeholders in `ecs/taskdef.example.json`. Keep container name as `wordpress`.
5. **CodePipeline:** Pipeline flow: GitHub → CodeBuild → ECS (using `imagedefinitions.json`).
6. **ALB Health Check:** Set target group path to `/health.php`.
7. **Environment Variables:** Set `WP_HOME` (CloudFront URL) and `WP_CDN_URL`.

*Every `git push` will automatically trigger a deployment to ECS Fargate.*

## Production Notes

- Set `WORDPRESS_DISALLOW_FILE_MODS=1` in production to prevent in-app file edits.
- Upgrade WordPress/WooCommerce by updating base image tags in `Dockerfile`.
- Execute initial WP setup via ECS Exec using the pre-installed WP-CLI (`wp core install`).
- Using RDS Read Replicas requires HyperDB or LudicrousDB plugins (default config uses Writer endpoint only).
