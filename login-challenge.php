<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}
$now = time();
$challenges = $_SESSION['login_captcha_challenges'] ?? [];
foreach ($challenges as $key => $issuedAt) {
    if (!is_numeric($issuedAt) || $now - (int)$issuedAt > 15) unset($challenges[$key]);
}
$challenge = bin2hex(random_bytes(16));
$challenges[$challenge] = $now;
$_SESSION['login_captcha_challenges'] = array_slice($challenges, -3, null, true);
echo json_encode(['challenge' => $challenge], JSON_UNESCAPED_SLASHES);
