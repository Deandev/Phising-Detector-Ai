<?php

// PENTING:
// 1. Dapatkan API Key Anda dari Google AI Studio: https://aistudio.google.com/
// 2. Ganti 'YOUR_GEMINI_API_KEY' di bawah dengan kunci API Anda yang sebenarnya!
// 3. JANGAN PERNAH MENGEKSPOS API KEY DI KODE JAVASCRIPT FRONTEND ANDA.

define('GEMINI_API_KEY', ''); // <-- GANTI DENGAN KUNCI API ANDA YANG ASLI!

header('Content-Type: application/json');

// Izinkan akses dari origin yang berbeda (untuk pengembangan lokal)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tangani preflight request untuk CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metode HTTP tidak diizinkan. Hanya POST.']);
    exit();
}

// Ambil data JSON dari body permintaan
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input.']);
    exit();
}

$urlToAnalyze = $data['url'] ?? null;

if (empty($urlToAnalyze)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tautan (URL) tidak ditemukan dalam permintaan.']);
    exit();
}

// Validasi URL dasar di sisi server
if (!filter_var($urlToAnalyze, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tautan (URL) tidak valid. Harap masukkan URL lengkap.']);
    exit();
}

// --- PROMPT UNTUK GEMINI ---
// Kita akan meminta Gemini untuk menganalisis URL dan memberikan status serta persentase keyakinan.
// Kita instruksikan untuk memisahkan hasilnya dengan format yang spesifik.
$prompt = "Tinjau tautan (URL) berikut ini dan tentukan apakah itu berpotensi menjadi tautan phishing atau tidak: '$urlToAnalyze'.
Berikan analisis singkat mengapa demikian.
Jika tautan tersebut berpotensi phishing, statusnya HARUS 'Potensi Phishing'.
Jika tautan tersebut bukan potensi phishing, statusnya HARUS 'Aman'.
Jika Anda tidak yakin atau tidak dapat menganalisis, statusnya HARUS 'Tidak Yakin'.
JANGAN gunakan kata 'phishing' di status jika tautannya Aman atau Tidak Yakin.

Hasilnya harus dalam format yang sangat spesifik, tanpa tanda bintang atau tanda format Markdown lainnya:
Status: [Potensi Phishing/Aman/Tidak Yakin]
Keyakinan: [Persentase keyakinan, contoh: 95% atau Tidak tersedia]
Penjelasan: [Analisis singkat AI tentang mengapa tautan tersebut dianggap phishing atau tidak, atau mengapa tidak yakin]";


$requestBody = json_encode([
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
            ]
        ]
    ]
]);

// Gunakan model gemini-1.5-flash
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . GEMINI_API_KEY;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Mengembalikan transfer sebagai string
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL Error: ' . curl_error($ch)]);
    curl_close($ch);
    exit();
}

$responseData = json_decode($response, true);
curl_close($ch);

// Periksa error dari API Gemini
if (isset($responseData['error'])) {
    http_response_code(isset($responseData['error']['code']) ? $responseData['error']['code'] : 500);
    echo json_encode([
        'error' => 'API Error: ' . ($responseData['error']['message'] ?? 'Unknown API error'),
        'details' => $responseData['error']['details'] ?? null
    ]);
    exit();
}

$status = 'Tidak dapat menganalisis.';
$confidence = 'Tidak tersedia.';
$explanation = 'Tidak ada penjelasan tambahan.';

if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $fullText = $responseData['candidates'][0]['content']['parts'][0]['text'];

    // HILANGKAN SEMUA TANDA BOLD (**) DAN ASTERISK (*) DARI TEKS RESPON
    $fullText = str_replace('**', '', $fullText);
    $fullText = str_replace('*', '', $fullText);

    // Coba pisahkan teks berdasarkan pola yang diminta di prompt
    $statusPrefix = 'Status:';
    $confidencePrefix = 'Keyakinan:';
    $explanationPrefix = 'Penjelasan:';

    $statusPos = mb_strpos($fullText, $statusPrefix);
    $confidencePos = mb_strpos($fullText, $confidencePrefix);
    $explanationPos = mb_strpos($fullText, $explanationPrefix);

    if ($statusPos !== false && $confidencePos !== false && $explanationPos !== false) {
        $status = mb_substr($fullText, $statusPos + mb_strlen($statusPrefix), $confidencePos - ($statusPos + mb_strlen($statusPrefix)));
        $confidence = mb_substr($fullText, $confidencePos + mb_strlen($confidencePrefix), $explanationPos - ($confidencePos + mb_strlen($confidencePrefix)));
        $explanation = mb_substr($fullText, $explanationPos + mb_strlen($explanationPrefix));
    } else {
        // Fallback jika format tidak persis, coba ekstrak semaksimal mungkin
        if ($statusPos !== false) {
            $remainingText = mb_substr($fullText, $statusPos + mb_strlen($statusPrefix));
            $firstNewline = mb_strpos($remainingText, "\n");
            $status = trim($firstNewline !== false ? mb_substr($remainingText, 0, $firstNewline) : $remainingText);
        }
        if ($confidencePos !== false) {
            $remainingText = mb_substr($fullText, $confidencePos + mb_strlen($confidencePrefix));
            $firstNewline = mb_strpos($remainingText, "\n");
            $confidence = trim($firstNewline !== false ? mb_substr($remainingText, 0, $firstNewline) : $remainingText);
        }
        if ($explanationPos !== false) {
            $explanation = trim(mb_substr($fullText, $explanationPos + mb_strlen($explanationPrefix)));
        } else {
            // Jika tidak ada pola yang cocok, seluruh teks dianggap sebagai penjelasan
            $explanation = $fullText;
            $status = 'Tidak yakin (format respons AI tidak standar).';
            $confidence = 'Tidak tersedia.';
        }
    }
}

echo json_encode([
    'status' => trim($status),
    'confidence' => trim($confidence),
    'explanation' => trim($explanation)
]);

?>
