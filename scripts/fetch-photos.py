#!/usr/bin/env python3
"""Ürün fotoğraflarını Wikimedia Commons'tan bir kez indirir: seed/photos/<SKU>.jpg

Hesap ya da API anahtarı gerekmez; yalnızca Python 3 ve internet. Seed
(seed/seed.php) fotoğrafları bu klasörden yükler ve internete çıkmaz. Bu
betik yalnızca fotoğraf setini doldurmak/yenilemek için; sonucu mağazada
kontrol edip commit'leyin.

Kullanım:
    python3 scripts/fetch-photos.py              # fotoğrafı olmayan ürünler
    python3 scripts/fetch-photos.py LZ-0001 LZ-0042   # yalnızca bu ürünler (yeniden)
    python3 scripts/fetch-photos.py --all        # hepsi (yeniden)

seed/photos/manifest.csv her ürün için arama sorgusunu (`query`) ve seçilen
Commons dosyasını (`file`, ör. "File:Tomatoes on the vine.jpg") tutar. `file`
doluysa o dosya indirilir (herkes aynı sonucu alır); boşsa `query` ile aranır
ve uygun ilk sonuç seçilir.

Beğenmediğiniz bir fotoğrafı değiştirmek için manifest.csv'de o satırda
`file`'a Commons'ta beğendiğiniz dosyanın adını yazın ya da `file`'ı boşaltıp
`query`'yi değiştirin, sonra betiği o SKU ile çalıştırın. Elle konmuş bir
fotoğrafın (png/webp/jpg) üzerine, o SKU açıkça verilmedikçe yazılmaz.

Lisans: yalnızca CC0, kamu malı, CC BY ve CC BY-SA dosyalar seçilir (ticari
kullanım serbest). Yazar ve lisans manifest.csv'ye yazılır; seed bunları
mağazadaki "Görsel kaynakları" sayfasında gösterir (CC BY'nin istediği atıf).
Marka/logo görünen fotoğrafları kullanmayın.
"""

import csv
import html
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTOS = os.path.join(ROOT, "seed", "photos")
MANIFEST = os.path.join(PHOTOS, "manifest.csv")
CATALOG = os.path.join(ROOT, "seed", "products.csv")
FIELDS = ["sku", "query", "file", "author", "license", "source_url"]
API = "https://commons.wikimedia.org/w/api.php"
# Wikimedia her istekte tanımlayıcı bir User-Agent istiyor
UA = "yerel-market-seed/1.0 (WooCommerce demo; https://github.com/egeeuzun/wordpress-shop-demo)"
WIDTH = 960  # Commons'un standart küçük resim boyutlarından biri
EXTS = ("jpg", "jpeg", "png", "webp")
FREE = re.compile(r"^(cc0|public domain|pd\b.*|cc by(-sa)? \d(\.\d)?.*)$", re.I)


def get_json(params):
    url = API + "?" + urllib.parse.urlencode({**params, "format": "json", "formatversion": "2"})
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def plain(value):
    """extmetadata değerleri HTML içerebilir"""
    return html.unescape(re.sub(r"<[^>]+>", "", value or "")).strip()


def info_props():
    return {
        "prop": "imageinfo",
        "iiprop": "url|size|mime|extmetadata",
        "iiurlwidth": WIDTH,
        "iiextmetadatafilter": "LicenseShortName|Artist",
    }


def usable(page):
    """Fotoğraf yeterince büyük, kareye yakın ve serbest lisanslı mı"""
    ii = (page.get("imageinfo") or [{}])[0]
    if ii.get("mime") != "image/jpeg" or not ii.get("thumburl"):
        return None
    w, h = ii.get("width", 0), ii.get("height", 0)
    if w < 800 or h < 600 or not (0.6 <= w / max(h, 1) <= 1.8):
        return None
    meta = ii.get("extmetadata", {})
    lic = plain(meta.get("LicenseShortName", {}).get("value"))
    if not FREE.match(lic):
        return None
    return {
        "file": page["title"],
        "author": plain(meta.get("Artist", {}).get("value"))[:120] or "Bilinmiyor",
        "license": lic,
        "source_url": ii.get("descriptionurl", ""),
        "thumb": ii["thumburl"],
    }


def search(query, used):
    data = get_json({
        "action": "query",
        "generator": "search",
        "gsrsearch": f"{query} filetype:bitmap",
        "gsrnamespace": 6,
        "gsrlimit": 30,
        **info_props(),
    })
    pages = sorted(data.get("query", {}).get("pages", []), key=lambda p: p.get("index", 0))
    for page in pages:
        found = usable(page)
        # Aynı fotoğraf iki ürüne gelmesin (ör. iki pirinç çeşidi)
        if found and found["file"] not in used:
            return found
    return None


def by_title(title):
    data = get_json({"action": "query", "titles": title, **info_props()})
    pages = data.get("query", {}).get("pages", [])
    return usable(pages[0]) if pages else None


def download(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as resp, open(dest + ".tmp", "wb") as out:
        out.write(resp.read())
    os.replace(dest + ".tmp", dest)


def has_photo(sku):
    """Elle konmuş fotoğraf (png/webp...) da sayılır"""
    return any(os.path.exists(os.path.join(PHOTOS, f"{sku}.{e}")) for e in EXTS)


def main():
    args = sys.argv[1:]
    refetch_all = "--all" in args
    only = {a for a in args if not a.startswith("--")}

    with open(MANIFEST, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    for r in rows:
        for k in FIELDS:
            r[k] = r.get(k) or ""
    names = {}
    with open(CATALOG, newline="", encoding="utf-8") as f:
        for r in csv.DictReader(f):
            names[r["SKU"]] = r["Name"]

    used = {r["file"] for r in rows if r["file"]}
    done = failed = 0
    for row in rows:
        sku = row["sku"]
        if not (refetch_all or sku in only or (not only and not has_photo(sku))):
            continue
        try:
            if row["file"]:
                found = by_title(row["file"])
                if found is None:
                    print(f"{sku}: '{row['file']}' bulunamadı ya da lisansı/boyutu uygun değil")
                    failed += 1
                    continue
            else:
                found = search(row["query"], used)
                if found is None:
                    print(f"{sku}: '{row['query']}' için uygun fotoğraf yok, sorguyu değiştirin")
                    failed += 1
                    continue
            download(found["thumb"], os.path.join(PHOTOS, f"{sku}.jpg"))
            row.update({k: found[k] for k in ("file", "author", "license", "source_url")})
            used.add(row["file"])
            done += 1
            print(f"{sku}: {names.get(sku, '')} <- {row['file']} ({row['license']}, {row['author']})")
        except Exception as e:  # ağ hatası vb.: diğer ürünlerle devam et
            print(f"{sku}: HATA {e}")
            failed += 1
        time.sleep(1)  # Wikimedia'ya nazik davran

    with open(MANIFEST, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)

    missing = [r["sku"] for r in rows if not has_photo(r["sku"])]
    print(f"\nİndirilen: {done}, hata: {failed}, fotoğrafı olmayan: {len(missing)} {' '.join(missing)}")
    print("Kontrol: ./scripts/seed-demo.sh ile mağazaya yükleyip bakın; beğenmediklerinizi değiştirip commit'leyin.")


if __name__ == "__main__":
    main()
