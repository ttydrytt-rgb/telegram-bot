<?php
ignore_user_abort(true);
set_time_limit(0);

// Disable output buffering to stream responses immediately
while (ob_get_level()) {
    ob_end_clean();
}

$baseDir = __DIR__ . '/..';
$configFile = $baseDir . '/config.json';
$usersFile = "../../users.json";
if (!file_exists($usersFile)) {
    $usersFile = "../users.json"; 
}
$qrFile = "../../qr.json";
if (!file_exists($qrFile)) {
    $qrFile = "../qr.json";
}

$config = @json_decode(@file_get_contents($configFile), true);
$botToken = $config['bot_token'] ?? '';
$apiUrl = "https://api.telegram.org/bot" . $botToken . "/";

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$text = trim($_POST['text'] ?? '');
$hash = md5($text . ($_POST['caption'] ?? '') . ($_POST['btn_text'] ?? '') . ($_POST['btn_url'] ?? '') . ($_POST['qr_enabled'] ?? ''));
if (isset($_SESSION['broadcast_sent']) && $_SESSION['broadcast_sent'] === $hash) {
    echo "Broadcast Successfully";
    exit;
}
$_SESSION['broadcast_sent'] = $hash;

$tempDir = __DIR__ . '/temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$text = $_POST['text'] ?? '';
$caption = isset($_POST['caption']) && $_POST['caption'] !== '' ? $_POST['caption'] : null;
$btn_text = $_POST['btn_text'] ?? '';
$btn_url = $_POST['btn_url'] ?? '';

$qr_enabled = isset($_POST['qr_enabled']) && $_POST['qr_enabled'] === '1';
$type = $_POST['type'] ?? 'text';

$photo = $_POST['photo'] ?? '';
$video = $_POST['video'] ?? '';
$document = $_POST['document'] ?? '';

// Check for uploaded files and move them to temp directory
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $type = 'photo';
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'upload_' . uniqid() . '.' . $ext;
    $dest = $tempDir . '/' . $filename;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        $photo = $filename;
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "Photo uploaded: " . $filename . "\n", FILE_APPEND);
    }
} elseif (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $type = 'video';
    $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION) ?: 'mp4';
    $filename = 'upload_' . uniqid() . '.' . $ext;
    $dest = $tempDir . '/' . $filename;
    if (move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
        $video = $filename;
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "Video uploaded: " . $filename . "\n", FILE_APPEND);
    }
} elseif (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
    $type = 'document';
    $ext = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION) ?: 'pdf';
    $filename = 'upload_' . uniqid() . '.' . $ext;
    $dest = $tempDir . '/' . $filename;
    if (move_uploaded_file($_FILES['document']['tmp_name'], $dest)) {
        $document = $filename;
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "Document uploaded: " . $filename . "\n", FILE_APPEND);
    }
}

$broadcast_id = uniqid();

$replyMarkup = null;
if (!empty($btn_text)) {
    if ($qr_enabled) {
        $replyMarkup = [
            'inline_keyboard' => [[['text' => $btn_text, 'callback_data' => 'qr_generate']]]
        ];
    } else {
        $targetUrl = ($btn_url === 'QR' || empty($btn_url)) ? 'https://telegram.org' : $btn_url;
        $replyMarkup = [
            'inline_keyboard' => [[['text' => $btn_text, 'url' => $targetUrl]]]
        ];
    }
}

$queueData = [
    "id" => $broadcast_id,
    "qr_enabled" => $qr_enabled,
    "type" => $type,
    "message" => $text,
    "caption" => $caption,
    "reply_markup" => $replyMarkup,
    "photo" => $photo,
    "video" => $video,
    "document" => $document,
    "btn_text" => $btn_text,
    "btn_url" => $btn_url,
    "offset" => 0,
    "total_users" => 0,
    "success_count" => 0,
    "failed_count" => 0,
    "status" => "running",
    "created" => time()
];

file_put_contents('broadcast_queue.json', json_encode($queueData));
file_put_contents(__DIR__ . "/broadcast_debug.txt", "Queue created: " . json_encode($queueData) . "\n", FILE_APPEND);

// Trigger the worker via HTTP (Serv00 compatible)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname($_SERVER['SCRIPT_NAME']);
$workerUrl = "$protocol://$host$basePath/broadcast_worker.php";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $workerUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 1);
curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_exec($ch);
curl_close($ch);

echo "Broadcast Successfully";
exit;

?>