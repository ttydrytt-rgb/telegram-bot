<?php
$logFiles = [
    __DIR__."/broadcast_debug.txt",
    __DIR__."/broadcast_failed.txt"
];

foreach($logFiles as $file){
    if(file_exists($file)){
        $age = time() - filemtime($file);
        # 3600 seconds = 1 hour
        if($age > 3600){
            unlink($file);
        }
    }
}

$tempDir = __DIR__."/temp";

if(!is_dir($tempDir)){
mkdir($tempDir,0777,true);
}
// broadcast_worker.php - Process the broadcast queue in safe batches
ignore_user_abort(true);
set_time_limit(0);

$baseDir = __DIR__ . '/..';
$configFile = $baseDir . '/config.json';
$queueFile = __DIR__ . '/broadcast_queue.json';
$usersFile = $baseDir . '/users.json';

$lockFile = __DIR__ . '/broadcast_worker.lock';
if (file_exists($lockFile)) {
    if (time() - filemtime($lockFile) < 300) {
        exit;
    }
}
file_put_contents($lockFile, time()); file_put_contents(__DIR__ . "/broadcast_debug.txt", "Worker started at " . date("Y-m-d H:i:s") . "\n", FILE_APPEND);

// 4. Load users.json
$usersData = json_decode(file_get_contents($usersFile), true);
$ids = [];

if (isset($usersData['users']) && is_array($usersData['users'])) {
    foreach ($usersData['users'] as $uid => $u) {
        $id = is_array($u) ? ($u['user_id'] ?? $uid) : $uid;
        if (preg_match('/^\d+$/', $id)) $ids[] = (string)$id;
    }
} else if (is_array($usersData)) {
    foreach ($usersData as $uid => $u) {
        $id = is_array($u) ? ($u['user_id'] ?? $uid) : $uid;
        if (preg_match('/^\d+$/', $id)) $ids[] = (string)$id;
    }
}

$totalUsers = count($ids);

$config = json_decode(file_get_contents($configFile), true);
$botToken = trim($config['bot_token'] ?? '');
if (empty($botToken)) {
    file_put_contents(__DIR__ . "/broadcast_debug.txt", "Error: Bot token is empty! Check config.json\n", FILE_APPEND);
}
$apiUrl = "https://api.telegram.org/bot" . $botToken . "/";

function tgRequest($method, $data, $apiUrl) {
    $ch = curl_init($apiUrl . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $hasFile = false;
    foreach($data as $key => $val) {
        if ($val instanceof CURLFile) {
            $hasFile = true;
            break;
        }
    }
    
    if ($hasFile) {
        if (isset($data['reply_markup'])) {
            $data['reply_markup'] = json_encode($data['reply_markup']);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch,CURLOPT_SAFE_UPLOAD,true);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "cURL Error: " . $err . "\n", FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "TG API Response: " . $res . "\n", FILE_APPEND);
    }
    
    return json_decode($res, true);
}

while (true) {
    if (!file_exists($queueFile)) break;
    $queue = json_decode(file_get_contents($queueFile), true);
    if (!$queue || ($queue['status'] ?? '') !== 'running') break;

    $offset = $queue['offset'] ?? 0;
    $batch = array_slice($ids, $offset, 20);

    if (empty($batch)) { file_put_contents(__DIR__ . "/broadcast_debug.txt", "Batch empty, ending.\n", FILE_APPEND);
        unlink($queueFile);
        break;
    }

    $file = '';
    $mediaKey = '';
    $type = $queue['type'] ?? 'text';
    
    if ($type === 'photo') {
        $file = $queue['photo'] ?? $queue['file_id'] ?? '';
        $mediaKey = 'photo';
    } elseif ($type === 'video') {
        $file = $queue['video'] ?? $queue['file_id'] ?? '';
        $mediaKey = 'video';
    } elseif ($type === 'document') {
        $file = $queue['document'] ?? $queue['file_id'] ?? '';
        $mediaKey = 'document';
    }
    
    // Handle both full paths and filenames
    if(!empty($file)){
        if(file_exists($file)){
            $path = $file;
        } else {
            $path = $tempDir."/".$file;
        }
    } else {
        $path = '';
    }

    foreach ($batch as $userId) {
        $message = !empty($queue['caption']) ? $queue['caption'] : ($queue['message'] ?? '');
        
        $payload = [
            'chat_id' => $userId,
            'parse_mode' => 'HTML'
        ];
        
        if ($type === 'photo' || $type === 'video' || $type === 'document') {
            if ($type === 'photo') {
                $method = 'sendPhoto';
            } elseif ($type === 'video') {
                $method = 'sendVideo';
            } else {
                $method = 'sendDocument';
            }
            
            if(!empty($path) && file_exists($path)){
                $realPath = realpath($path);
                $payload[$mediaKey] = new CURLFile($realPath);
                file_put_contents(__DIR__ . "/broadcast_debug.txt", "Using file: " . $realPath . " for " . $mediaKey . "\n", FILE_APPEND);
            }else{
                $errorMsg = "Missing file: " . $path . " (original: " . $file . ")\n";
                echo $errorMsg;
                file_put_contents(__DIR__ . "/broadcast_debug.txt", $errorMsg, FILE_APPEND);
                continue;
            }
            
            if ($message !== '') $payload['caption'] = $message;
        } else {
            $method = 'sendMessage';
            if ($message !== '') $payload['text'] = $message;
        }
        
        $btn_text = '';
        $btn_url = '';
        $callback_data = '';
        
        if (!empty($queue['reply_markup']) && is_array($queue['reply_markup'])) {
            $btn_text = $queue['reply_markup']['inline_keyboard'][0][0]['text'] ?? '';
            $btn_url = $queue['reply_markup']['inline_keyboard'][0][0]['url'] ?? '';
            $callback_data = $queue['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '';
        }
        
        if (!empty($queue['btn_text'])) $btn_text = $queue['btn_text'];
        if (!empty($queue['btn_url'])) $btn_url = $queue['btn_url'];
        
        $is_qr = !empty($queue['qr_enabled']) || ($queue['type'] ?? '') === 'qrcode';
        $button_obj = ["text" => $btn_text];
        if ($is_qr) {
            $button_obj["callback_data"] = "qr_generate";
        } elseif (!empty($btn_url)) {
            $button_obj["url"] = $btn_url;
        } elseif (!empty($callback_data)) {
            $button_obj["callback_data"] = $callback_data;
        }

        $buttons = ["inline_keyboard" => [[$button_obj]]];
        
        if (!empty($btn_text)) {
            $payload['reply_markup'] = $buttons;
        }

        // Add debug logging
        file_put_contents(__DIR__ . "/broadcast_debug.txt", "Sending to $userId: " . json_encode($payload) . "\n", FILE_APPEND);

        $res = tgRequest($method, $payload, $apiUrl);
        
        // Smart Retry System for Rate Limits
        if (!$res || empty($res['ok'])) {
            if (isset($res['error_code']) && $res['error_code'] === 429) {
                $retryAfter = $res['parameters']['retry_after'] ?? 5;
                file_put_contents(__DIR__ . "/broadcast_debug.txt", "Rate limited. Sleeping for $retryAfter seconds...\n", FILE_APPEND);
                sleep($retryAfter);
                $res = tgRequest($method, $payload, $apiUrl); // Retry once
            }
        }

        if ($res && isset($res['ok']) && $res['ok']) {
            $queue['success_count'] = ($queue['success_count'] ?? 0) + 1;
        } else {
            $queue['failed_count'] = ($queue['failed_count'] ?? 0) + 1;
            // Debug Version: Print which user failed
            $errorMsg = isset($res['description']) ? $res['description'] : 'Unknown error';
            file_put_contents(__DIR__ . "/broadcast_failed.txt", "Failed ID: $userId | Reason: $errorMsg\n", FILE_APPEND);
        }
    }

    $queue['offset'] += count($batch);
    $queue['total_users'] = $totalUsers;
    file_put_contents($queueFile, json_encode($queue));

    $done = $queue['offset'];
    $success = $queue['success_count'] ?? 0;
    $failed = $queue['failed_count'] ?? 0;
    file_put_contents(__DIR__ . "/broadcast_debug.txt", "Progress: $done/$totalUsers (Success: $success, Failed: $failed)\n", FILE_APPEND);
    
    // Progress update to admin
    if (!empty($queue['admin_chat_id'])) {
        $progressMsg = "📢 <b>Broadcast Progress</b>\n\n" .
                      "📊 Sent: $done / $totalUsers\n" .
                      "✅ Success: $success\n" .
                      "❌ Failed: $failed";
        tgRequest('sendMessage', ['chat_id' => $queue['admin_chat_id'], 'text' => $progressMsg, 'parse_mode' => 'HTML'], $apiUrl);
    }
    
    sleep(1);
}

if (isset($queue) && !empty($queue['admin_chat_id'])) {
    $finalMsg = "✅ <b>Broadcast Completed!</b>\n\n" .
                "📊 <b>Stats:</b>\n" .
                "👥 Total: " . ($queue['total_users'] ?? $totalUsers) . "\n" .
                "✅ Success: " . ($queue['success_count'] ?? 0) . "\n" .
                "❌ Failed: " . ($queue['failed_count'] ?? 0);
    tgRequest('sendMessage', ['chat_id' => $queue['admin_chat_id'], 'text' => $finalMsg, 'parse_mode' => 'HTML'], $apiUrl);
}

unlink($lockFile);
exit;
