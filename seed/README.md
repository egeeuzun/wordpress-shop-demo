# Seed kit — Yerel Market demo data

This kit fills the store with fake grocery data: products, customers and
orders. The personal data it creates (names, addresses, phone numbers, national
IDs) is what the Residency Guard checks later look for.

| File | What it is |
| --- | --- |
| `products.csv` | 86 products in 9 categories, SKUs `LZ-0001`…`LZ-0086`. 11 on sale, 9 featured, 1 out of stock. |
| `seed.php` | The script that sets up the store and creates the products, customers and orders. |

The kit is built into the image at `/usr/src/shop-seed/` (outside the web root)
and runs with WP-CLI inside the container. It needs no API keys.

## Ground rules

- **No real people.** Names and addresses are picked at random from word lists.
  All e-mails end in `@example.com`, a domain reserved for examples.
- **National IDs (TCKN) are fake.** They are random 11-digit numbers that pass
  the checksum rule, so the checkout accepts them. They do not belong to anyone.
- **No real brands.** Products have generic names like
  "Tam Yağlı Süt 1 L" (whole milk, 1 L). Product photos are royalty-free stock
  photos (see [Product photos](#product-photos)); none should show a logo.
- **No real payments.** Only Cash on Delivery and Bank Transfer are offered, and
  the bank account (IBAN) shown is invalid on purpose.
- **No e-mails are sent** while the script runs.

## How to run it

Locally:

```bash
docker compose up -d --build
./scripts/seed-demo.sh                          # 150 customers, 300 orders
./scripts/seed-demo.sh orders=500 customers=200 # more data
./scripts/seed-demo.sh dry-run > plan.json      # preview, writes nothing
```

On AWS, in a running ECS task (WordPress installed, WooCommerce active):

```bash
aws ecs execute-command --cluster <CLUSTER> --task <TASK_ID> --container wordpress \
  --interactive --command \
  "su -s /bin/sh www-data -c 'wp eval-file /usr/src/shop-seed/seed.php'"
```

Run it as `www-data`, not root. As root, WP-CLI refuses to run without
`--allow-root`, and the uploaded images would be owned by root.

### Options

Options are written as `name=value` (WP-CLI's `eval-file` does not accept
`--flags`):

| Option | Default | What it does |
| --- | --- | --- |
| `dry-run` | — | Prints the planned data as JSON and writes nothing. |
| `orders=N` | `300` | Number of orders, spread over the last 90 days. |
| `customers=N` | `150` | Number of registered customers. About 15% of orders are guest orders. |
| `seed=N` | `2026` | Random seed. The same seed always gives the same customers and orders. |
| `no-images` | — | Skip product and category images. |
| `reset-stock` | — | Set existing products' stock back to the CSV values. |
| `customer-pass=…` | `demo123` | Password for the demo customer account. |
| `csv=/path` | `products.csv` | Use a different product file. |

## What it sets up

- **Store settings:** site language Turkish (the language packs are in the
  image), Turkish lira, sells to Türkiye only, prices include VAT, Istanbul
  time zone, guest checkout on.
- **Shipping:** 49.90 ₺ standard delivery, free above 750 ₺.
- **Pages:** a Storefront homepage and a category menu. The cart and checkout
  pages are switched to the classic WooCommerce checkout, because the TCKN field
  only works there.
- **Products:** categories, products and category images.
- **Demo customer:** `demo.musteri@example.com` / `demo123`, with a few orders,
  for testing "My account" and order history.
- **Customers and orders:** completed, processing, on-hold (waiting for a bank
  transfer) and cancelled orders. The newest orders are mostly still
  processing. Orders lower the stock, just like real ones.

## Where the personal data ends up

All of it is in the WordPress database:

| Data | Table / field |
| --- | --- |
| Customer account, name, e-mail, addresses | `wp_users`, `wp_usermeta` (`billing_*`, `shipping_*`) |
| Customer TCKN | `wp_usermeta`, key `billing_tckn` |
| Order addresses, phone, e-mail | `wp_wc_orders`, `wp_wc_order_addresses` |
| Order TCKN | `wp_wc_orders_meta`, key `_billing_tckn` |
| Copies used by WooCommerce reports | `wp_wc_customer_lookup`, `wp_wc_order_stats` |

Records made by the script are tagged: orders have `created_via = lz-seed` and
`_lz_seed_ref` (`LZ-S-0001`…), customers have `lz_seed_ref` (`LZ-C-0001`…).
Orders placed through the real checkout store the TCKN under the same
`_billing_tckn` key.

## Running it again

It is safe to run the script more than once. Products are matched by SKU,
customers by e-mail and orders by `_lz_seed_ref`, so nothing is duplicated. If
you change a name, price or description in the CSV, the next run updates that
product. Stock is left alone (orders have already lowered it) unless you pass
`reset-stock`.

## Products only

To load just the products, import `products.csv` in *wp-admin → Products →
Import*. The columns match WooCommerce's importer, and `Meta: _lz_unit` becomes
the product unit. Customers, orders and store settings still need `seed.php`.

## Product photos

Every product has its own photo in `seed/photos/`, named after its SKU
(`LZ-0001.jpg`…). The photos are part of the repo and the Docker image, so the
store gets them with no setup: no API key, no download. The seed never goes
online.

- **Product image:** the product's own photo.
- **Category image:** the photo of the first product in that category.
- **No photo:** a product without a photo uses its category image. A category
  with no photos at all gets a plain coloured square.
- **Your own changes stay:** an image you pick by hand in wp-admin is never
  replaced.
- **Re-running:** a photo is uploaded again only when its file changes.

### Adding or replacing a photo

Put the file in `seed/photos/` with the product's SKU as its name: `.jpg`,
`.jpeg`, `.png` or `.webp`. A square photo of about 1000×1000 works best; the
theme crops to a square anyway. Then run `./scripts/seed-demo.sh` (locally the
`seed` folder is mounted, so no rebuild) and commit the file.

Use only photos you are allowed to use, and none that show a brand or logo.
If the licence asks for credit, add a row to `seed/photos/manifest.csv`
(`author`, `license`, `source_url`). The seed then shows the credit as the
image caption and on the **Görsel kaynakları** page.

### Filling the folder in one go (maintainers only)

`scripts/fetch-photos.py` downloads a photo for every product from
[Wikimedia Commons](https://commons.wikimedia.org/). It needs no account or API
key, only Python 3 and internet access:

```bash
python3 scripts/fetch-photos.py            # products that have no photo yet
python3 scripts/fetch-photos.py LZ-0042    # just these products, again
```

It only picks freely licensed photos that may be used commercially: CC0,
public domain, CC BY and CC BY-SA. Each photo must be a large enough JPEG and
close to square. A hand-placed photo is never overwritten unless you name its
SKU.

For each product, `manifest.csv` holds:

| Column | What it is |
| --- | --- |
| `query` | The search used for this product. |
| `file` | The chosen Commons file, for example `File:Tomatoes.jpg`. |
| `author`, `license`, `source_url` | Credit, filled in by the script. |

If `file` is set, the script downloads that exact file. If not, it searches
with `query` and takes the first suitable result that no other product uses.

To replace a photo, put the name of a file you like on Commons into `file`, or
clear `file` and change `query`. Then run the script with that SKU.

CC BY and CC BY-SA require credit. The seed shows the author and licence as
the image caption, and lists every photo on a **Görsel kaynakları** (image
credits) page linked from the footer.

Check the result in the store, then commit the photos together with
`manifest.csv`.
