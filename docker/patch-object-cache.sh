#!/bin/bash
# Memcached Redux drop-in'ine ElastiCache için istemci ayarlarını enjekte eder.
# İmaj build'i sırasında bir kez çalışır: patch-object-cache.sh <object-cache.php>
#
# Neden gerekli:
# Redux (0.1.7, 2020'den beri güncellenmiyor) "new Memcached()" deyip hiçbir
# OPT_* ayarı yapmıyor; libmemcached varsayılanlarıyla kalıyoruz:
#
#   * Dağıtım "modula": çok node'lu bir cluster'da bir node eklenip çıktığında
#     anahtarların neredeyse tamamı başka node'a düşer -> topyekûn cache miss ve
#     RDS'e yığılma. Ketama/consistent hashing'de sadece o node'un payı taşınır.
#   * Bloklayan bağlantı: erişilemeyen bir node her isteği bekletir; timeout
#     verilmezse cache arızası site yavaşlamasına dönüşür.
#
# Ayarlar addServers() ÇAĞRILMADAN ÖNCE verilmek zorunda: ketama continuum
# sunucular eklenirken kuruluyor.
#
# Upstream dosya değişir de çapa satırı bulunamazsa build'i sessizce
# yamasız geçmek yerine kasten düşürüyoruz (exit 3).
set -euo pipefail

TARGET="${1:?kullanım: patch-object-cache.sh <object-cache.php>}"
ANCHOR='$this->mc[$bucket] = new Memcached();'
MARKER='shop-demo:memcached-options'

[ -f "$TARGET" ] || { echo "patch-object-cache: '$TARGET' yok" >&2; exit 2; }

if grep -qF "$MARKER" "$TARGET"; then
    echo "patch-object-cache: zaten yamalı, atlanıyor"
    exit 0
fi

grep -qF "$ANCHOR" "$TARGET" || {
    echo "patch-object-cache: beklenen satır bulunamadı: $ANCHOR" >&2
    echo "Memcached Redux upstream'de değişmiş olabilir; yamayı güncelleyin." >&2
    exit 3
}

SNIPPET="$(mktemp)"
trap 'rm -f "$SNIPPET" "$TARGET.new"' EXIT

cat > "$SNIPPET" <<'PHP'
			// --- shop-demo:memcached-options (imaj build'inde eklendi) ---
			// addServers() öncesinde verilmeli; ketama continuum orada kuruluyor.
			$this->mc[$bucket]->setOptions( array(
				// Node eklenip çıkarıldığında anahtarların tamamı yer değiştirmesin
				Memcached::OPT_LIBKETAMA_COMPATIBLE  => true,
				Memcached::OPT_DISTRIBUTION          => Memcached::DISTRIBUTION_CONSISTENT,
				// Erişilemeyen bir node her isteği bekletmesin
				Memcached::OPT_REMOVE_FAILED_SERVERS => true,
				Memcached::OPT_RETRY_TIMEOUT         => 2,      // saniye
				Memcached::OPT_CONNECT_TIMEOUT       => 500,    // milisaniye
				Memcached::OPT_POLL_TIMEOUT          => 500,    // milisaniye
				Memcached::OPT_SEND_TIMEOUT          => 500000, // mikrosaniye
				Memcached::OPT_RECV_TIMEOUT          => 500000, // mikrosaniye
			) );
			// --- /shop-demo:memcached-options ---
PHP

awk -v anchor="$ANCHOR" -v snippet="$SNIPPET" '
    { print }
    !patched && index($0, anchor) {
        while ( ( getline line < snippet ) > 0 ) print line
        close(snippet)
        patched = 1
    }
    END { if (!patched) exit 3 }
' "$TARGET" > "$TARGET.new"

mv "$TARGET.new" "$TARGET"
grep -qF "$MARKER" "$TARGET" || { echo "patch-object-cache: yama doğrulanamadı" >&2; exit 3; }
echo "patch-object-cache: ElastiCache istemci ayarları eklendi -> $TARGET"
