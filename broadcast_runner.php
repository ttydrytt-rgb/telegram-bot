<?php
// Karn/broadcast_runner.php - Background Broadcast Processor

// Crucial for running in background via HTTP trigger
ignore_user_abort(true);
set_time_limit(0);

ini_set('display_errors', 0);
error_reporting(0);

$baseDir = __DIR__;

// ================================================
// HELPERS
// ================================================

function readJson($filename) {
    global $baseDir;
    $path = $baseDir . '/' . $filename;
    if (!file_exists($path)) return [];
    $fp = @fopen($path, 'r');
    if (!$fp) return [];
    if (flock($fp, LOCK_SH)) {
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    } else {
        fclose($fp);
        return [];
    }
}

function writeJson($filename, $data) {
    global $baseDir;
    $path = $baseDir . '/' . $filename;
    $fp = @fopen($path, 'c');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    } else {
        fclose($fp);
        return false;
    }
}

function apiRequest($method, $parameters = []) {
    global $BOT_TOKEN;
    if (empty($BOT_TOKEN)) return false;
    $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    if (!empty($parameters)) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    }
    $response = curl_exec($handle);
    if ($response === false) { curl_close($handle); return false; }
    curl_close($handle);
    return json_decode($response, true);
}

function sendMessage($chatId, $text) {
    return apiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']);
}

function sendPhoto($chatId, $photo, $caption) {
    return apiRequest('sendPhoto', ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML']);
}

function sendVideo($chatId, $video, $caption) {
    return apiRequest('sendVideo', ['chat_id' => $chatId, 'video' => $video, 'caption' => $caption, 'parse_mode' => 'HTML']);
}

function sendMediaGroup($chatId, $media) {
    return apiRequest('sendMediaGroup', ['chat_id' => $chatId, 'media' => $media]);
}

// ================================================
// LOGIC
// ================================================

// Load Config
$config = readJson('config.json');
$BOT_TOKEN = $config['bot_token'] ?? '';
$ADMIN_ID = $config['admin_userid'] ?? 0;

if (empty($BOT_TOKEN)) exit("No token");

$lockFile = $baseDir . '/broadcast_running.lock';
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    $lockTime = $lockData['started_at'] ?? 0;
    if ((time() - $lockTime) < 3600) {
        exit("Broadcast already running");
    }
}

file_put_contents($lockFile, json_encode([
    'started_at' => time(),
    'pid' => getmypid()
], JSON_PRETTY_PRINT));

// Read Broadcast Data
$dataFile = $baseDir . '/broadcast_data.json';
if (!file_exists($dataFile)) {
    @unlink($lockFile);
    if ($ADMIN_ID) sendMessage($ADMIN_ID, "❌ Broadcast failed: Data file missing.");
    exit("No data file");
}
$broadcastData = json_decode(file_get_contents($dataFile), true);
if (!$broadcastData) {
    @unlink($lockFile);
    if ($ADMIN_ID) sendMessage($ADMIN_ID, "❌ Broadcast failed: Invalid JSON data.");
    exit("Invalid JSON");
}

$type = $broadcastData['type'] ?? 'text';
$content = $broadcastData['content'] ?? '';
$caption = $broadcastData['caption'] ?? '';
$mediaGroup = $broadcastData['media_group'] ?? [];

$broadcastHash = md5($type . $content . $caption . json_encode($mediaGroup));

$sentLog = readJson('broadcast_sent_log.json');
$alreadySent = [];
if (isset($sentLog['hash']) && $sentLog['hash'] === $broadcastHash) {
    $alreadySent = $sentLog['sent_users'] ?? [];
}

// Load Users
$usersData = readJson('users.json');
$users = $usersData['users'] ?? [];
$totalCount = count($users);
$skippedDuplicate = 0;

if ($ADMIN_ID) sendMessage($ADMIN_ID, "🚀 <b>Broadcast Started!</b>\nType: " . strtoupper($type) . "\nTarget: $totalCount users" . (count($alreadySent) > 0 ? "\nAlready sent: " . count($alreadySent) . " (skip honge)" : ""));

$successCount = 0;
$blockedCount = 0;
$failedCount = 0;
$processed = 0;
$stopFlag = $baseDir . '/stop_broadcast.flag';
$sentUsers = $alreadySent;

foreach ($users as $uid => $u) {
    if (file_exists($stopFlag)) {
        if ($ADMIN_ID) sendMessage($ADMIN_ID, "🛑 <b>Broadcast Stopped by Admin!</b>");
        break;
    }

    $processed++;
    if (($u['status'] ?? '') === 'blocked') { $blockedCount++; continue; }

    if (in_array((string)$uid, $sentUsers, true)) {
        $skippedDuplicate++;
        continue;
    }

    $res = false;
    switch ($type) {
        case 'text':
            $res = sendMessage($uid, $content);
            break;
        case 'photo':
            $res = sendPhoto($uid, $content, $caption);
            break;
        case 'video':
            $res = sendVideo($uid, $content, $caption);
            break;
        case 'album':
            $validMedia = [];
            foreach ($mediaGroup as $m) {
                if (isset($m['type'], $m['media'])) {
                    $validMedia[] = $m;
                }
            }
            if (!empty($validMedia)) {
                 $res = sendMediaGroup($uid, $validMedia);
            }
            break;
    }

    if ($res && ($res['ok'] ?? false)) {
        $successCount++;
        $sentUsers[] = (string)$uid;
    } else {
        $errCode = $res['error_code'] ?? 0;
        if ($errCode == 403) {
            $usersData['users'][$uid]['status'] = 'blocked';
            $blockedCount++;
            $sentUsers[] = (string)$uid;
        } elseif ($errCode == 429) {
             // Rate Limit Hit - 429 Too Many Requests
             $retryAfter = $res['parameters']['retry_after'] ?? 5;
             sleep((int)$retryAfter + 1);
             // We count as failed here to avoid infinite loops, but at least we respected the wait
             $failedCount++; 
        } else {
            $failedCount++;
        }
    }

    // Safer Rate Limiting: Sleep 1s every 20 messages (approx 20 msg/sec)
    if ($processed % 20 == 0) sleep(1);
    
    if ($processed % 50 == 0) {
        writeJson('users.json', $usersData);
        writeJson('broadcast_sent_log.json', [
            'hash' => $broadcastHash,
            'sent_users' => $sentUsers,
            'updated_at' => time()
        ]);
        // Free memory
        unset($res);
    }
    if ($ADMIN_ID && $processed % 100 == 0) {
        sendMessage($ADMIN_ID, "📊 <b>Progress:</b> $processed / $totalCount\n✅ $successCount | 🚫 $blockedCount | ❌ $failedCount | ⏭️ Skipped: $skippedDuplicate");
    }
}

writeJson('users.json', $usersData);
writeJson('broadcast_sent_log.json', [
    'hash' => $broadcastHash,
    'sent_users' => $sentUsers,
    'updated_at' => time()
]);

$finalMsg = "🏁 <b>Broadcast Finished!</b>\n\n✅ Delivered: $successCount\n🚫 Blocked: $blockedCount\n❌ Failed: $failedCount\n⏭️ Duplicate Skipped: $skippedDuplicate\n📉 Total Processed: $processed";
if ($ADMIN_ID) sendMessage($ADMIN_ID, $finalMsg);

// Cleanup
@unlink($dataFile);
@unlink($stopFlag);
@unlink($lockFile);
?>
