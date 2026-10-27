#!/usr/bin/env python3
"""Ürün fotoğraflarını Wikimedia Commons'tan indirir: seed/photos/<SKU>.jpg

Hesap ya da API anahtarı gerekmez; yalnızca Python 3 ve internet. Seed
(seed/seed.php) fotoğrafları bu klasörden yükler ve internete çıkmaz. Bu betik
yalnızca fotoğraf setini hazırlamak için; sonucu kontrol edip commit'leyin.

Kullanım:
    python3 scripts/fetch-photos.py                  # fotoğrafı olmayan ürünler
    python3 scripts/fetch-photos.py LZ-0001 LZ-0042  # yalnızca bu ürünler (yeniden)
    python3 scripts/fetch-photos.py --all            # hepsi (yeniden)
    python3 scripts/fetch-photos.py --check          # indirmeden, repodaki fotoğrafları
                                                     # uygunsuz içerik filtresinden geçirir

Her çalıştırmadan sonra seed/photos/review.html oluşur: bütün ürünler ve
fotoğrafları tek sayfada (git'e girmez).

seed/photos/manifest.csv, ürün başına:
    search    Commons'ta aranacak metin
    keywords  Dosya başlığında geçmesi ZORUNLU kelimelerden en az biri (| ile ayrılır).
              Alakasız sonuçları (ör. "cream" -> dondurma) bu eler.
    exclude   Başlıkta geçerse fotoğrafı eleyen kelimeler (| ile ayrılır)
    file      Seçilen Commons dosyası. Doluysa doğrudan o indirilir.
    author, license, source_url   Atıf; betik doldurur.

Beğenmediğiniz bir fotoğraf için: Commons'ta beğendiğiniz dosyanın adını
`file`'a yazın (ör. "File:Tomatoes.jpg"), ya da `file`'ı boşaltıp `keywords` /
`exclude`'u sıkılaştırın; sonra betiği o SKU ile çalıştırın. Elle konmuş bir
fotoğrafın (jpg/png/webp) üzerine, SKU açıkça verilmedikçe yazılmaz.

Yalnızca ticari kullanımı serbest lisanslar seçilir: CC0, kamu malı, CC BY,
CC BY-SA. Seed yazar/lisansı görselin alt yazısına ve "Görsel kaynakları"
sayfasına koyar (CC BY'nin istediği atıf).
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
REVIEW = os.path.join(PHOTOS, "review.html")
CATALOG = os.path.join(ROOT, "seed", "products.csv")
FIELDS = ["sku", "search", "keywords", "exclude", "file", "author", "license", "source_url"]

API = "https://commons.wikimedia.org/w/api.php"
# Wikimedia her istekte tanımlayıcı bir User-Agent istiyor
UA = "yerel-market-seed/2.0 (WooCommerce demo; https://github.com/egeeuzun/wordpress-shop-demo)"
WIDTH = 960  # Commons'un standart küçük resim boyutlarından biri
EXTS = ("jpg", "jpeg", "png", "webp")
PAUSE = 1.0  # istekler arası saniye; Wikimedia'ya nazik davran

FREE_LICENSE = re.compile(r"^(cc0|public domain|pd\b.*|cc by(-sa)? \d(\.\d)?.*)$", re.I)
# Her ürün için geçerli: ürün fotoğrafı olmayan sonuçlar
NOT_A_PHOTO = (
    "painting", "drawing", "illustration", "engraving", "lithograph", "etching", "poster",
    "advert", "wellcome", "museum", "manga", "screenshot", "logo", "diagram", "cartoon",
    "comic", "scan", "postcard", "stamp", "map of", "apollo", "nasa", "microscop", "sem image",
)
# Uygunsuz içerik: dosya başlığında ya da Commons kategorilerinde geçerse asla seçilmez.
# Kategori kontrolü asıl güvencedir; başlık masum olsa bile Commons dosyayı
# "Nude ...", "Erotic ..." gibi kategorilere koyar.
UNSAFE = (
    "nude", "nudity", "naked", "erotic", "porn", "nsfw", "sexual", "sex ", "sex toy", "topless",
    "bare breast", "breasts", "lingerie", "bikini", "underwear", "fetish", "bdsm", "genital",
    "penis", "vagina", "vulva", "condom", "strip club", "stripper", "playboy", "hentai",
    "gore", "corpse", "dead body", "injur", "wound", "blood",
)


# --- Commons -------------------------------------------------------------------

def api(params):
    url = API + "?" + urllib.parse.urlencode({**params, "format": "json", "formatversion": "2"})
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = json.load(resp)
    time.sleep(PAUSE)
    return data


IMAGEINFO = {
    "prop": "imageinfo|categories",
    "clshow": "!hidden",
    "cllimit": "max",
    "iiprop": "url|size|mime|extmetadata",
    "iiurlwidth": WIDTH,
    "iiextmetadatafilter": "LicenseShortName|Artist",
}


def plain(value):
    """extmetadata değerleri HTML içerebilir"""
    return html.unescape(re.sub(r"<[^>]+>", "", value or "")).strip()


def title_text(title):
    """'File:Raw_chicken_legs (2).jpg' -> 'raw chicken legs (2)'"""
    t = re.sub(r"^file:", "", title, flags=re.I)
    t = re.sub(r"\.[a-z0-9]+$", "", t, flags=re.I)
    return t.replace("_", " ").lower()


def words(cell):
    return [w.strip().lower() for w in (cell or "").split("|") if w.strip()]


def has(text, word):
    """Kelime başından eşleşir: 'diaper' -> 'diapers' olur, 'air conditioner' ->
    'hair conditioner' olmaz."""
    return re.search(r"(?<![a-z0-9])" + re.escape(word), text) is not None


def candidate(page, keywords, exclude):
    """Sayfa uygunsa indirme bilgisini, değilse None döndürür."""
    t = title_text(page.get("title", ""))
    if keywords and not any(has(t, k) for k in keywords):
        return None
    if any(has(t, x) for x in exclude) or any(has(t, x) for x in NOT_A_PHOTO):
        return None
    if not safe(page):
        return None
    ii = (page.get("imageinfo") or [{}])[0]
    if ii.get("mime") != "image/jpeg" or not ii.get("thumburl"):
        return None
    w, h = ii.get("width", 0), ii.get("height", 0)
    if w < 800 or h < 600 or not (0.6 <= w / max(h, 1) <= 1.8):
        return None
    meta = ii.get("extmetadata", {})
    lic = plain(meta.get("LicenseShortName", {}).get("value"))
    if not FREE_LICENSE.match(lic):
        return None
    return {
        "file": page["title"],
        "author": plain(meta.get("Artist", {}).get("value"))[:120] or "Bilinmiyor",
        "license": lic,
        "source_url": ii.get("descriptionurl", ""),
        "thumb": ii["thumburl"],
    }


def safe(page):
    """Başlıkta ya da kategorilerde uygunsuz içerik işareti yoksa True."""
    texts = [title_text(page.get("title", ""))]
    texts += [c.get("title", "").lower().replace("_", " ") for c in page.get("categories", [])]
    return not any(has(t, w) for t in texts for w in UNSAFE)


def search_pages(text):
    data = api({
        "action": "query",
        "generator": "search",
        "gsrsearch": f"{text} filetype:bitmap",
        "gsrnamespace": 6,
        "gsrlimit": 50,
        **IMAGEINFO,
    })
    return sorted(data.get("query", {}).get("pages", []), key=lambda p: p.get("index", 0))


def find(row, used):
    """Önce `search` ile, sonuç çıkmazsa her anahtar kelime için başlıkta ara."""
    keywords, exclude = words(row["keywords"]), words(row["exclude"])
    attempts = [row["search"]] + [f'intitle:"{k}"' for k in keywords]
    for text in attempts:
        if not text:
            continue
        for page in search_pages(text):
            found = candidate(page, keywords, exclude)
            # Aynı fotoğraf iki ürüne gitmesin (ör. iki bebek bezi)
            if found and found["file"] not in used:
                return found
    return None


def by_title(title):
    data = api({"action": "query", "titles": title, **IMAGEINFO})
    pages = data.get("query", {}).get("pages", [])
    # Elle seçilmiş dosyada kelime kontrolü yapılmaz, lisans/boyut yapılır
    return candidate(pages[0], [], []) if pages else None


def download(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as resp, open(dest + ".tmp", "wb") as out:
        out.write(resp.read())
    os.replace(dest + ".tmp", dest)
    time.sleep(PAUSE)


# --- Dosyalar --------------------------------------------------------------------

def photo_path(sku):
    for e in EXTS:
        p = os.path.join(PHOTOS, f"{sku}.{e}")
        if os.path.exists(p):
            return p
    return None


def read_manifest():
    with open(MANIFEST, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    for r in rows:
        for k in FIELDS:
            r[k] = r.get(k) or ""
    return rows


def write_manifest(rows):
    with open(MANIFEST, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)


def write_review(rows, names):
    cells = []
    for r in rows:
        p = photo_path(r["sku"])
        img = (f'<img src="{html.escape(os.path.basename(p))}" alt="">' if p
               else '<div class="none">fotoğraf yok</div>')
        src = (f'<a href="{html.escape(r["source_url"])}">{html.escape(r["file"] or "elle konmuş")}</a>'
               if r["source_url"] else html.escape(r["file"] or ("elle konmuş" if p else "")))
        cells.append(
            f'<figure>{img}<figcaption><b>{r["sku"]}</b> {html.escape(names.get(r["sku"], ""))}'
            f'<br><small>{src} · {html.escape(r["license"])}</small></figcaption></figure>'
        )
    page = (
        '<!doctype html><meta charset="utf-8"><title>Ürün fotoğrafları</title>'
        "<style>body{font:14px system-ui;margin:20px;background:#f5f7f6}"
        "main{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}"
        "figure{margin:0;background:#fff;border-radius:12px;padding:8px;border:1px solid #e4eae7}"
        "img,.none{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;background:#eee}"
        ".none{display:grid;place-items:center;color:#999}small{color:#77847e;word-break:break-all}</style>"
        f"<h1>Ürün fotoğrafları ({sum(1 for r in rows if photo_path(r['sku']))}/{len(rows)})</h1>"
        "<main>" + "".join(cells) + "</main>"
    )
    with open(REVIEW, "w", encoding="utf-8") as f:
        f.write(page)


# --- Ana akış --------------------------------------------------------------------

def check(rows, names):
    """Repodaki Commons fotoğraflarını uygunsuz içerik filtresinden geçirir."""
    bad = []
    for row in rows:
        if not row["file"]:
            continue
        data = api({"action": "query", "titles": row["file"], "prop": "categories", "clshow": "!hidden", "cllimit": "max"})
        pages = data.get("query", {}).get("pages", [])
        if pages and not safe(pages[0]):
            bad.append(row["sku"])
            print(f"{row['sku']}: {names.get(row['sku'], '')} <- {row['file']} UYGUNSUZ olabilir")
    print(f"\nKontrol edilen: {sum(1 for r in rows if r['file'])}, sorunlu: {len(bad)} {' '.join(bad)}")
    if bad:
        print("Sorunlu fotoğrafları silip `file`'ı boşaltın, sonra betiği o SKU'larla çalıştırın.")


def main():
    args = sys.argv[1:]
    refetch_all = "--all" in args
    only = {a for a in args if not a.startswith("--")}

    rows = read_manifest()
    with open(CATALOG, newline="", encoding="utf-8") as f:
        names = {r["SKU"]: r["Name"] for r in csv.DictReader(f)}

    if "--check" in args:
        check(rows, names)
        return

    used = {r["file"] for r in rows if r["file"]}
    done, failed = 0, []
    for row in rows:
        sku = row["sku"]
        if not (refetch_all or sku in only or (not only and photo_path(sku) is None)):
            continue
        try:
            found = by_title(row["file"]) if row["file"] else find(row, used)
            if found is None:
                why = f"'{row['file']}' indirilemedi (lisans/boyut)" if row["file"] else "uygun fotoğraf bulunamadı"
                print(f"{sku}: {names.get(sku, '')}: {why}")
                failed.append(sku)
                continue
            download(found["thumb"], os.path.join(PHOTOS, f"{sku}.jpg"))
            row.update({k: found[k] for k in ("file", "author", "license", "source_url")})
            used.add(row["file"])
            done += 1
            print(f"{sku}: {names.get(sku, '')} <- {row['file']} ({row['license']})")
        except Exception as e:  # ağ hatası vb.: diğer ürünlerle devam et
            print(f"{sku}: HATA {e}")
            failed.append(sku)
        write_manifest(rows)  # yarıda kesilirse emek kaybolmasın

    write_manifest(rows)
    write_review(rows, names)
    missing = [r["sku"] for r in rows if photo_path(r["sku"]) is None]
    print(f"\nİndirilen: {done}, bulunamayan/hata: {len(failed)}, fotoğrafı olmayan: {len(missing)} {' '.join(missing)}")
    print(f"Hepsini tek sayfada görmek için: {os.path.relpath(REVIEW, ROOT)}")
    print("Fotoğrafı olmayan ürün mağazada kategorisinin görselini gösterir.")


if __name__ == "__main__":
    main()
