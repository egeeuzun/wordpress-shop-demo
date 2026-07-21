<?php
// ALB hedef grubu sağlık kontrolü için hafif endpoint (WordPress yüklenmez).
// Target group health check path: /health.php
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
