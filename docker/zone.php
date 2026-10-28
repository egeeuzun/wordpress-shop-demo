<?php
// Sunucunun çalıştığı zone (üst banttaki "İstanbul Local Zone · 3 ms").
// WordPress yüklenmez: tarayıcı bu isteğin gidiş-dönüş süresini ölçer, ağ
// gecikmesine PHP'nin payı çok küçük kalır. Zone, ECS task metadata
// endpoint'inden bir kez okunup konteynerin /tmp'sinde tutulur.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cache = sys_get_temp_dir() . '/shop-zone.json';
$zone  = is_readable($cache) ? json_decode((string) file_get_contents($cache), true) : null;

if (!is_array($zone)) {
    $zone     = ['zone' => '', 'label' => 'Yerel ortam'];
    $metadata = getenv('ECS_CONTAINER_METADATA_URI_V4');
    if ($metadata) {
        $ctx  = stream_context_create(['http' => ['timeout' => 1]]);
        $task = json_decode((string) @file_get_contents($metadata . '/task', false, $ctx), true);
        $az   = is_array($task) ? (string) ($task['AvailabilityZone'] ?? '') : '';
        // Metadata okunamadıysa önbelleğe yazılmaz, sonraki istek tekrar dener
        if ($az !== '') {
            if (str_contains($az, '-ist-')) {
                $label = 'İstanbul Local Zone';
            } elseif (str_starts_with($az, 'eu-central-1')) {
                $label = 'Frankfurt';
            } else {
                $label = $az;
            }
            $zone = ['zone' => $az, 'label' => $label];
            @file_put_contents($cache, json_encode($zone, JSON_UNESCAPED_UNICODE));
        }
    }
}

echo json_encode($zone, JSON_UNESCAPED_UNICODE);
