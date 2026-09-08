<?php
ignore_user_abort(true);
set_time_limit(0);
ini_set('display_errors', 0);
error_reporting(0);

$baseDir = __DIR__;

$runnerTokenFile = $baseDir . '/broadcast_runner_token.json';
if (php_sapi_name() !== 'cli') {
    $tokenData = file_exists($runnerTokenFile) ? json_decode(file_get_contents($runnerTokenFile), true) : null;
    $validToken = $tokenData['token'] ?? '';
    $tokenTime = $tokenData['created_at'] ?? 0;

    if (empty($validToken) || (time() - $tokenTime) > 3600) {
        http_response_code(403);
        exit('Forbidden');
    }

    @unlink($runnerTokenFile);

    header('Connection: close');
    header('Content-Length: 2');
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }
}

$configFile = $baseDir . '/config.json';
$usersFile = $baseDir . '/users.json';
$broadcastDataFile = $baseDir . '/broadcast_data.json';
$broadcastStateFile = $baseDir . '/broadcast_state.json';
$lockFile = $baseDir . '/broadcast_running.lock';
$stopFlag = $baseDir . '/stop_broadcast.flag';
$tempDir = $baseDir . '/temvd';

if (!file_exists($configFile)) exit('No config');

$config = json_decode(file_get_contents($configFile), true);
$BOT_TOKEN = $config['bot_token'] ?? '';
$ADMIN_ID = $config['admin_userid'] ?? 0;

if (empty($BOT_TOKEN)) exit('No token');

if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    $lockTime = $lockData['started_at'] ?? 0;
    if ((time() - $lockTime) < 3600) {
        exit('Already running');
    }
}

file_put_contents($lockFile, json_encode(['started_at' => time(), 'pid' => getmypid()], JSON_PRETTY_PRINT));

if (!file_exists($broadcastDataFile)) {
    @unlink($lockFile);
    exit('No broadcast data');
}

$broadcastData = json_decode(file_get_contents($broadcastDataFile), true);
if (!$broadcastData) {
    @unlink($lockFile);
    exit('Invalid broadcast data');
}

$type = $broadcastData['type'] ?? 'text';
$text = $broadcastData['text'] ?? '';
$caption = $broadcastData['caption'] ?? '';
$mediaFiles = $broadcastData['media_files'] ?? [];
$userIds = $broadcastData['user_ids'] ?? [];
$totalUsers = count($userIds);

if ($totalUsers === 0) {
    @unlink($lockFile);
    exit('No users');
}

$state = [
    'status' => 'running',
    'total' => $totalUsers,
    'sent' => 0,
    'failed' => 0,
    'blocked_removed' => 0,
    'current_index' => 0,
    'started_at' => time(),
    'updated_at' => time()
];

if (file_exists($broadcastStateFile)) {
    $existingState = json_decode(file_get_contents($broadcastStateFile), true);
    if ($existingState && ($existingState['status'] ?? '') === 'running' && ($existingState['current_index'] ?? 0) > 0) {
        $state = $existingState;
    }
}

$usersData = json_decode(file_get_contents($usersFile), true);
if (!is_array($usersData)) $usersData = ['users' => []];
$hasUsersKey = isset($usersData['users']);
$usersObj = $hasUsersKey ? $usersData['users'] : $usersData;

$startIndex = $state['current_index'];
$sent = $state['sent'];
$failed = $state['failed'];
$blockedRemoved = $state['blocked_removed'];

$cachedFileIds = [];

function telegramApiUrl($method) {
    global $BOT_TOKEN;
    return "https://api.telegram.org/bot$BOT_TOKEN/$method";
}

function uploadMediaToTelegram($filePath, $mediaType, $chatId, $captionText = '') {
    global $BOT_TOKEN;
    $url = '';
    $fieldName = '';

    if ($mediaType === 'photo') {
        $url = telegramApiUrl('sendPhoto');
        $fieldName = 'photo';
    } elseif ($mediaType === 'video') {
        $url = telegramApiUrl('sendVideo');
        $fieldName = 'video';
    } else {
        return false;
    }

    $postData = [
        'chat_id' => $chatId,
        $fieldName => new CURLFile($filePath),
        'parse_mode' => 'HTML'
    ];
    if (!empty($captionText)) {
        $postData['caption'] = $captionText;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($data && ($data['ok'] ?? false)) {
        $result = $data['result'] ?? [];
        if (isset($result['photo'])) {
            return end($result['photo'])['file_id'];
        } elseif (isset($result['video'])) {
            return $result['video']['file_id'];
        }
    }
    return false;
}

function uploadAlbumToTelegram($mediaFiles, $chatId, $captionText, $baseDir) {
    global $BOT_TOKEN;
    $url = telegramApiUrl('sendMediaGroup');

    $postData = ['chat_id' => $chatId];
    $media = [];
    $attachIdx = 0;

    foreach ($mediaFiles as $idx => $file) {
        $filePath = $baseDir . '/temvd/' . $file['filename'];
        if (!file_exists($filePath)) continue;

        $attachName = 'file' . $attachIdx;
        $postData[$attachName] = new CURLFile($filePath);

        $item = [
            'type' => $file['type'],
            'media' => 'attach://' . $attachName
        ];
        if ($idx === 0 && !empty($captionText)) {
            $item['caption'] = $captionText;
            $item['parse_mode'] = 'HTML';
        }
        $media[] = $item;
        $attachIdx++;
    }

    $postData['media'] = json_encode($media);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($data && ($data['ok'] ?? false)) {
        $results = $data['result'] ?? [];
        $fileIds = [];
        foreach ($results as $r) {
            if (isset($r['photo'])) {
                $fileIds[] = ['type' => 'photo', 'file_id' => end($r['photo'])['file_id']];
            } elseif (isset($r['video'])) {
                $fileIds[] = ['type' => 'video', 'file_id' => $r['video']['file_id']];
            }
        }
        return $fileIds;
    }
    return false;
}

if ($type !== 'text' && !empty($mediaFiles) && $totalUsers > 0) {
    $firstUser = $userIds[0];

    if ($type === 'album') {
        $albumIds = uploadAlbumToTelegram($mediaFiles, $firstUser, $caption, $baseDir);
        if ($albumIds) {
            $cachedFileIds = $albumIds;
        } else {
            $state['status'] = 'failed';
            $state['updated_at'] = time();
            file_put_contents($broadcastStateFile, json_encode($state, JSON_PRETTY_PRINT));
            @unlink($lockFile);
            exit('Media upload failed');
        }
    } else {
        $file = $mediaFiles[0];
        $filePath = $baseDir . '/temvd/' . $file['filename'];
        if (file_exists($filePath)) {
            $fileId = uploadMediaToTelegram($filePath, $type, $firstUser, $caption);
            if ($fileId) {
                $cachedFileIds = [['type' => $type, 'file_id' => $fileId]];
            } else {
                $state['status'] = 'failed';
                $state['updated_at'] = time();
                file_put_contents($broadcastStateFile, json_encode($state, JSON_PRETTY_PRINT));
                @unlink($lockFile);
                exit('Media upload failed');
            }
        }
    }

    $sent++;
    $startIndex = max($startIndex, 1);
    $state['current_index'] = $startIndex;
    $state['sent'] = $sent;
    saveState($broadcastStateFile, $state);
}

function isBlockedError($response) {
    if (!$response) return false;
    $data = json_decode($response, true);
    if (!$data) return false;
    $errCode = $data['error_code'] ?? 0;
    $desc = strtolower($data['description'] ?? '');

    if ($errCode == 403) return true;
    if ($errCode == 400 && strpos($desc, 'chat not found') !== false) return true;
    if (strpos($desc, 'blocked') !== false) return true;
    if (strpos($desc, 'deactivated') !== false) return true;
    if (strpos($desc, 'user is deactivated') !== false) return true;

    return false;
}

function saveState($file, $state) {
    $state['updated_at'] = time();
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

$batchSize = 25;

for ($i = $startIndex; $i < $totalUsers; $i += $batchSize) {
    if (file_exists($stopFlag)) {
        $state['status'] = 'stopped';
        $state['current_index'] = $i;
        $state['sent'] = $sent;
        $state['failed'] = $failed;
        $state['blocked_removed'] = $blockedRemoved;
        saveState($broadcastStateFile, $state);
        @unlink($lockFile);
        @unlink($stopFlag);
        cleanTempFiles($tempDir);
        exit;
    }

    $mh = curl_multi_init();
    $handles = [];
    $batchEnd = min($i + $batchSize, $totalUsers);

    for ($j = $i; $j < $batchEnd; $j++) {
        $userId = $userIds[$j];

        $url = '';
        $postData = '';

        if ($type === 'text') {
            $url = telegramApiUrl('sendMessage');
            $postData = json_encode([
                'chat_id' => $userId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ]);
        } elseif (($type === 'photo' || $type === 'video') && !empty($cachedFileIds)) {
            $fid = $cachedFileIds[0]['file_id'];
            if ($type === 'photo') {
                $url = telegramApiUrl('sendPhoto');
                $postData = json_encode([
                    'chat_id' => $userId,
                    'photo' => $fid,
                    'caption' => $caption,
                    'parse_mode' => 'HTML'
                ]);
            } else {
                $url = telegramApiUrl('sendVideo');
                $postData = json_encode([
                    'chat_id' => $userId,
                    'video' => $fid,
                    'caption' => $caption,
                    'parse_mode' => 'HTML'
                ]);
            }
        } elseif ($type === 'album' && !empty($cachedFileIds)) {
            $url = telegramApiUrl('sendMediaGroup');
            $media = [];
            foreach ($cachedFileIds as $idx => $cf) {
                $item = [
                    'type' => $cf['type'],
                    'media' => $cf['file_id']
                ];
                if ($idx === 0 && !empty($caption)) {
                    $item['caption'] = $caption;
                    $item['parse_mode'] = 'HTML';
                }
                $media[] = $item;
            }
            $postData = json_encode([
                'chat_id' => $userId,
                'media' => $media
            ]);
        }

        if (!$url) continue;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['ch' => $ch, 'user_id' => $userId];
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    foreach ($handles as $h) {
        $response = curl_multi_getcontent($h['ch']);
        $userId = $h['user_id'];

        $data = json_decode($response, true);
        $ok = ($data['ok'] ?? false);

        if ($ok) {
            $sent++;
        } else {
            if (false) { // Disabled: isBlockedError($response)
                $blockedRemoved++;
                if (isset($usersObj[$userId])) {
                    unset($usersObj[$userId]);
                }
                $removeKeys = [];
                foreach ($usersObj as $key => $u) {
                    if (is_array($u) && (string)($u['user_id'] ?? '') === (string)$userId) {
                        $removeKeys[] = $key;
                    }
                }
                foreach ($removeKeys as $rk) {
                    unset($usersObj[$rk]);
                }
            } else {
                $failed++;
            }
        }

        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }

    curl_multi_close($mh);

    $state['current_index'] = $batchEnd;
    $state['sent'] = $sent;
    $state['failed'] = $failed;
    $state['blocked_removed'] = $blockedRemoved;
    $state['status'] = 'running';
    saveState($broadcastStateFile, $state);

    if ($hasUsersKey) {
        $usersData['users'] = $usersObj;
    } else {
        $usersData = $usersObj;
    }

    if ($i % 75 == 0) {
        file_put_contents($usersFile, json_encode($usersData, JSON_PRETTY_PRINT));
    }

    usleep(50000);
}

if ($hasUsersKey) {
    $usersData['users'] = $usersObj;
    $usersData['total_users'] = count($usersObj);
} else {
    $usersData = $usersObj;
}
file_put_contents($usersFile, json_encode($usersData, JSON_PRETTY_PRINT));

$state['status'] = 'completed';
$state['sent'] = $sent;
$state['failed'] = $failed;
$state['blocked_removed'] = $blockedRemoved;
$state['current_index'] = $totalUsers;
saveState($broadcastStateFile, $state);

cleanTempFiles($tempDir);
@unlink($lockFile);
@unlink($broadcastDataFile);

function cleanTempFiles($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '/*');
    foreach ($files as $f) {
        if (is_file($f) && basename($f) !== '.gitkeep') @unlink($f);
    }
}
