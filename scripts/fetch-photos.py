#!/usr/bin/env python3
"""Ürün fotoğraflarını Pexels'tan bir kez indirir: seed/photos/<SKU>.jpg

Seed (seed/seed.php) fotoğrafları bu klasörden yükler ve internete çıkmaz.
Bu betik yalnızca fotoğrafları yenilemek istediğinizde çalıştırılır; sonucu
mağazada kontrol edip commit'leyin.

Kullanım:
    export PEXELS_API_KEY=...          # https://www.pexels.com/api/ (ücretsiz)
    python3 scripts/fetch-photos.py              # fotoğrafı olmayan ürünler
    python3 scripts/fetch-photos.py LZ-0001 LZ-0042   # yalnızca bu ürünler (yeniden)
    python3 scripts/fetch-photos.py --all        # hepsini yeniden

seed/photos/manifest.csv her ürün için arama sorgusunu ve seçilen fotoğrafın
Pexels numarasını tutar. `pexels_id` doluysa o fotoğraf indirilir (aynı
sonucu herkes alır); boşsa `query` ile aranır ve ilk uygun sonuç seçilir.

Beğenmediğiniz bir fotoğrafı değiştirmek için manifest.csv'de o satırda:
  - Pexels'ta beğendiğiniz fotoğrafın numarasını (adresin sonundaki sayı)
    `pexels_id`'ye yazın, ya da
  - `pexels_id`'yi boşaltıp `query`'yi değiştirin,
sonra betiği o SKU ile çalıştırın.

Lisans: Pexels fotoğrafları ticari kullanım dahil ücretsizdir, atıf zorunlu
değildir (https://www.pexels.com/license/). Yine de fotoğrafçı ve kaynak
manifest.csv'ye yazılır; seed bunu görselin alt yazısına koyar. Marka/logo
görünen fotoğrafları kullanmayın.
"""

import csv
import json
import os
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTOS = os.path.join(ROOT, "seed", "photos")
MANIFEST = os.path.join(PHOTOS, "manifest.csv")
CATALOG = os.path.join(ROOT, "seed", "products.csv")
FIELDS = ["sku", "query", "pexels_id", "photographer", "photographer_url", "source_url"]
API = "https://api.pexels.com/v1"
SIZE = 1000  # kare, piksel


def api(path, key):
    req = urllib.request.Request(f"{API}{path}", headers={
        "Authorization": key,
        "User-Agent": "yerel-market-seed/1.0",
    })
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def download(photo, dest):
    # Pexels görsel adresleri boyut parametrelerini kabul eder: kare kırpılmış,
    # sıkıştırılmış bir JPEG iste.
    url = photo["src"]["original"] + f"?auto=compress&cs=tinysrgb&fit=crop&w={SIZE}&h={SIZE}"
    req = urllib.request.Request(url, headers={"User-Agent": "yerel-market-seed/1.0"})
    with urllib.request.urlopen(req, timeout=60) as resp, open(dest + ".tmp", "wb") as out:
        out.write(resp.read())
    os.replace(dest + ".tmp", dest)


def main():
    key = os.environ.get("PEXELS_API_KEY", "").strip()
    if not key:
        sys.exit("PEXELS_API_KEY tanımlı değil. Ücretsiz anahtar: https://www.pexels.com/api/")

    args = sys.argv[1:]
    refetch_all = "--all" in args
    only = {a for a in args if not a.startswith("--")}

    with open(MANIFEST, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    names = {}
    with open(CATALOG, newline="", encoding="utf-8") as f:
        for r in csv.DictReader(f):
            names[r["SKU"]] = r["Name"]

    used = {r["pexels_id"] for r in rows if r["pexels_id"]}
    done = failed = 0
    for row in rows:
        sku = row["sku"]
        dest = os.path.join(PHOTOS, f"{sku}.jpg")
        wanted = refetch_all or sku in only or (not only and not os.path.exists(dest))
        if not wanted:
            continue
        try:
            if row["pexels_id"]:
                # Seçilmiş fotoğraf
                photo = api(f"/photos/{row['pexels_id']}", key)
            else:
                q = urllib.parse.urlencode({"query": row["query"], "per_page": 15, "orientation": "square"})
                result = api(f"/search?{q}", key)
                # Aynı fotoğraf iki ürüne gelmesin (ör. iki pirinç çeşidi)
                photo = next((p for p in result.get("photos", []) if str(p["id"]) not in used), None)
                if photo is None:
                    print(f"{sku}: '{row['query']}' için sonuç yok, sorguyu değiştirin")
                    failed += 1
                    continue
            download(photo, dest)
            row.update({
                "pexels_id": str(photo["id"]),
                "photographer": photo.get("photographer", ""),
                "photographer_url": photo.get("photographer_url", ""),
                "source_url": photo.get("url", ""),
            })
            used.add(row["pexels_id"])
            done += 1
            print(f"{sku}: {names.get(sku, '')} <- {row['source_url']} ({row['photographer']})")
        except Exception as e:  # ağ hatası, kota vb.: diğer ürünlerle devam et
            print(f"{sku}: HATA {e}")
            failed += 1
        time.sleep(0.4)  # Pexels saatte 200 istek sınırı

    with open(MANIFEST, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        w.writeheader()
        w.writerows(rows)

    missing = [r["sku"] for r in rows if not os.path.exists(os.path.join(PHOTOS, f"{r['sku']}.jpg"))]
    print(f"\nİndirilen: {done}, hata: {failed}, fotoğrafı olmayan: {len(missing)} {' '.join(missing)}")
    print("Kontrol: ./scripts/seed-demo.sh ile mağazaya yükleyip bakın; beğenmediklerinizi değiştirip commit'leyin.")


if __name__ == "__main__":
    main()
