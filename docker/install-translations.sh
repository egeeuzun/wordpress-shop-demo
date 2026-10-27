#!/bin/bash
# İmaj build'i sırasında dil paketlerini /usr/src/wordpress/wp-content/languages
# altına gömer. Böylece çalışma anında wordpress.org'a gitmeye gerek kalmaz ve
# çeviriler task yenilense de kaybolmaz (ECS'te languages dizini kalıcı değil).
#
# Kullanım:
#   install-translations.sh <locale> <hedef>...
#
# Hedefler:
#   core                  WordPress çekirdeği
#   plugin:<slug>         wp-content/plugins/<slug> altındaki eklenti
#   theme:<slug>          wp-content/themes/<slug> altındaki tema
#
# Sonuna "?" eklenen hedef opsiyoneldir (ör. plugin:amazon-s3-and-cloudfront?):
# çevirisi yoksa uyarı verir, build'i durdurmaz. Diğerleri bulunamazsa build
# hata verir; mağazanın Türkçe açılması bunlara bağlı.
#
# Sürümler imajdaki dosyalardan okunur, paket o sürüme ait çeviriyle eşleşir.
set -euo pipefail

WP_ROOT="${WP_ROOT:-/usr/src/wordpress}"
API="${WP_TRANSLATIONS_API:-https://api.wordpress.org/translations}"
LANG_DIR="$WP_ROOT/wp-content/languages"

locale="$1"
shift

# Eklenti ana dosyasının ya da style.css'in başlığından "Version:" değeri
header_version() {
    php -r '
        $head = file_get_contents($argv[1], false, null, 0, 8192);
        if (preg_match("/^[ \t\/*#@]*Version:\s*(\S+)/mi", $head, $m)) {
            echo $m[1];
        }
    ' "$1"
}

plugin_main_file() {
    local dir="$WP_ROOT/wp-content/plugins/$1"
    if [ -f "$dir/$1.php" ]; then
        echo "$dir/$1.php"
        return
    fi
    # Ana dosya klasörle aynı adı taşımıyorsa "Plugin Name:" başlığını ara
    grep -l -m1 -E '^[ \t/*#@]*Plugin Name:' "$dir"/*.php 2>/dev/null | head -n1 || true
}

# API yanıtından istenen dilin paket adresini çıkarır (yoksa boş)
package_url() {
    curl -fsSL "$1" | php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        foreach ($data["translations"] ?? [] as $t) {
            if (($t["language"] ?? "") === $argv[1]) {
                echo $t["package"];
                break;
            }
        }
    ' "$locale"
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

for target in "$@"; do
    optional=0
    if [ "${target%\?}" != "$target" ]; then
        optional=1
        target="${target%\?}"
    fi

    case "$target" in
        core)
            version="$(php -r 'include $argv[1]; echo $wp_version;' "$WP_ROOT/wp-includes/version.php")"
            url="$API/core/1.0/?version=$version"
            dest="$LANG_DIR"
            ;;
        plugin:*)
            slug="${target#plugin:}"
            main="$(plugin_main_file "$slug")"
            version="$( [ -n "$main" ] && header_version "$main" || true)"
            url="$API/plugins/1.0/?slug=$slug&version=$version"
            dest="$LANG_DIR/plugins"
            ;;
        theme:*)
            slug="${target#theme:}"
            style="$WP_ROOT/wp-content/themes/$slug/style.css"
            version="$( [ -f "$style" ] && header_version "$style" || true)"
            url="$API/themes/1.0/?slug=$slug&version=$version"
            dest="$LANG_DIR/themes"
            ;;
        *)
            echo "Bilinmeyen hedef: $target" >&2
            exit 2
            ;;
    esac

    if [ -z "$version" ]; then
        echo "HATA: $target sürümü okunamadı (kurulu değil mi?)" >&2
        exit 1
    fi

    package="$(package_url "$url" || true)"
    if [ -z "$package" ]; then
        if [ "$optional" -eq 1 ]; then
            echo "UYARI: $target $version için $locale çevirisi yok, atlanıyor." >&2
            continue
        fi
        echo "HATA: $target $version için $locale çevirisi bulunamadı ($url)" >&2
        exit 1
    fi

    mkdir -p "$dest"
    curl -fsSL -o "$tmp/pkg.zip" "$package"
    unzip -o -q "$tmp/pkg.zip" -d "$dest"
    rm -f "$tmp/pkg.zip"
    echo "$target $version -> $locale ($package)"
done
