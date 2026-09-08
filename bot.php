<?php

// ================================================
// CONFIGURATION & SETUP
// ================================================

// Production-safe error handling (webhook must never break)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Base directory
$baseDir = __DIR__;

// Include Trigger Helper
require_once __DIR__ . '/trigger_helper.php';

// Helper function to safely read JSON with locking
function readJson($filename) {
    global $baseDir;
    $path = $baseDir . '/' . $filename;
    
    if (!file_exists($path)) {
        // Create default if missing (safety net)
        $default = [];
        if ($filename == 'config.json') $default = ['bot_token' => '', 'admin_userid' => 0, 'demo_channel_link' => '', 'proofs_channel_link' => ''];
        if ($filename == 'users.json') $default = ['total_users' => 0, 'users' => []];
        if ($filename == 'paid.json') $default = [];
        if ($filename == 'img.json' || $filename == 'qr.json') $default = ['url' => ''];
        if ($filename == 'caption.json' || $filename == 'caption2.json') $default = ['text' => ''];
        if ($filename == 'settings.json') $default = ['auto_payment' => false];
        if ($filename == 'transactions.json') $default = [];
        
        // Ensure directory exists if needed (though seller/ is base here)
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }

    $fp = @fopen($path, 'r');
    if (!$fp) {
        return [];
    }
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

// Helper function to safely write JSON with locking
function writeJson($filename, $data) {
    global $baseDir;
    $path = $baseDir . '/' . $filename;
    
    $fp = @fopen($path, 'c');
    if (!$fp) {
        return false;
    }
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

// Load Config
$config = readJson('config.json');
$BOT_TOKEN = $config['bot_token'] ?? '';
$ADMIN_ID = $config['admin_userid'] ?? 0;
$DEVELOPER_ID = $config['developer_userid'] ?? 0;

// ================================================
// AUTOMATIC WEBHOOK SETUP & VERIFICATION
// ================================================

function autoSetWebhook() {
    global $BOT_TOKEN;
    if (empty($BOT_TOKEN)) return;

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $path = $_SERVER['SCRIPT_NAME'];
    $webhookUrl = "$protocol://$host$path";

    // Only set if not already set correctly or forced via param
    $info = apiRequest('getWebhookInfo');
    if ($info && isset($info['result']['url']) && $info['result']['url'] !== $webhookUrl) {
        apiRequest('setWebhook', ['url' => $webhookUrl]);
    }
}

// Call auto webhook setup
if (!empty($BOT_TOKEN)) {
    autoSetWebhook();
}

// Verification Page Logic
if (php_sapi_name() !== 'cli' && empty(file_get_contents("php://input"))) {
    $status = "✅ Bot is running.";
    $missing = [];
    $required_files = ['config.json', 'img.json', 'qr.json', 'caption.json', 'caption2.json'];
    foreach ($required_files as $file) {
        if (!file_exists($file)) $missing[] = "Missing: $file";
    }
    
    $img = readJson('img.json');
    if (empty($img['url'])) $missing[] = "Warning: Image URL in img.json is empty.";
    
    $admin_username = "Unknown";
    if ($BOT_TOKEN && $ADMIN_ID) {
        $chatInfo = apiRequest('getChat', ['chat_id' => $ADMIN_ID]);
        if ($chatInfo && isset($chatInfo['result']['username'])) {
            $admin_username = "@" . $chatInfo['result']['username'];
        } elseif ($chatInfo && isset($chatInfo['result']['first_name'])) {
            $admin_username = $chatInfo['result']['first_name'];
        }
    }

    echo "<html><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
    echo "<h1>Bot Status Page</h1>";
    echo "<p><strong>Status:</strong> $status</p>";
    echo "<p><strong>Webhook URL:</strong> " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}</p>";
    echo "<p><strong>Admin:</strong> $admin_username (ID: $ADMIN_ID)</p>";
    
    if (!empty($missing)) {
        echo "<h3 style='color: red;'>Issues Detected:</h3><ul>";
        foreach ($missing as $m) echo "<li>$m</li>";
        echo "</ul>";
    } else {
        echo "<h3 style='color: green;'>System OK!</h3>";
    }
    echo "</body></html>";
    exit;
}

// ================================================
// TELEGRAM API HELPERS
// ================================================

function apiRequest($method, $params = []) {
    global $BOT_TOKEN;
    if (empty($BOT_TOKEN)) return false;

    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);

    if (curl_error($ch)) {
        error_log("Curl error: " . curl_error($ch));
    }

    curl_close($ch);
    
    // Log response for debugging
    error_log("TG API Response ($method): " . $response);

    return json_decode($response, true);
}

function sendMessage($chatId, $text, $keyboard = null, $replyToMessageId = null) {
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
    return apiRequest('sendMessage', $params);
}

function sendPhoto($chatId, $photoUrl, $caption, $keyboard = null, $replyToMessageId = null) {
    $params = ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
    return apiRequest('sendPhoto', $params);
}

function editMessageMedia($chatId, $messageId, $media, $keyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'media' => $media
    ];
    if ($keyboard) $params['reply_markup'] = $keyboard;
    return apiRequest('editMessageMedia', $params);
}

// ================================================
// INCOMING REQUEST HANDLING
// ================================================

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    // Basic health check or empty request
    echo "Bot is running.";
    exit;
}

// Process Update
// Run auto cleanup
require_once __DIR__ . '/auto_cleanup.php';

$tempFiles = ['temp_setimg.json', 'temp_setqr.json'];
foreach ($tempFiles as $tFile) {
    $tempPath = $baseDir . '/' . $tFile;
    if (file_exists($tempPath)) {
        $tData = json_decode(file_get_contents($tempPath), true);
        if (isset($tData['time']) && (time() - $tData['time'] > 300)) {
            @unlink($tempPath);
        }
    }
}

if (isset($update['message'])) {
    handleMessage($update['message']);
} elseif (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
} elseif (isset($update['chat_member'])) {
    handleChatMember($update['chat_member']);
}

function handleChatMember($chatMemberUpdate) {
    global $BOT_TOKEN;
    
    // Check if the user was added or joined
    $newChatMember = $chatMemberUpdate['new_chat_member'] ?? null;
    $status = $newChatMember['status'] ?? '';
    $userId = $newChatMember['user']['id'] ?? null;
    
    if (($status === 'member' || $status === 'creator' || $status === 'administrator') && $userId) {
        $autoPayments = readJson('auto_payment.json');
        
        // Find if this user just paid via auto payment
        if (isset($autoPayments[$userId]) && $autoPayments[$userId]['status'] === 'paid' && !empty($autoPayments[$userId]['claimed'])) {
            sendMessage($userId, "Welcome 💖");
        }
    }
}

// ================================================
// HANDLERS
// ================================================

function handleMessage($message) {
    global $ADMIN_ID, $DEVELOPER_ID, $config, $baseDir;

    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $fullName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
    $msgId = $message['message_id'] ?? null;

    // ================================================
    // COMMAND FLOW ORDER (STRICT)
    // a) /start
    // b) Admin commands & replies
    // c) User commands & screenshots
    // ================================================

    // a) /start must work for BOTH admin and normal users
    if (is_string($text) && strpos($text, '/start') === 0) {
        updateUser($userId, $username, $fullName);
        sendStartMessage($chatId, $msgId);
        return;
    }

    // b) Admin Logic
    if ($userId == $ADMIN_ID || $userId == $DEVELOPER_ID) {
        
        // [SYSTEM 4] AUTO PAYMENT COMMANDS
        if ($text === '/auto on') {
            $settings = readJson('settings.json');
            $settings['auto_payment'] = true;
            writeJson('settings.json', $settings);
            sendMessage($chatId, "✅ <b>Auto Payment ENABLED.</b>\nUsers will be asked for Transaction ID.");
            return;
        }
        if ($text === '/auto off') {
            $settings = readJson('settings.json');
            $settings['auto_payment'] = false;
            writeJson('settings.json', $settings);
            sendMessage($chatId, "❌ <b>Auto Payment DISABLED.</b>\nUsers will upload screenshots manually.");
            return;
        }

        if (strpos($text, '/setmid ') === 0) {
            $settings = readJson('settings.json');
            if (empty($settings['auto_payment'])) {
                sendMessage($chatId, "⚠️ <b>Warning:</b> Enable Auto Payment first using /auto on");
                return;
            }
            $newMid = trim(substr($text, 8));
            if (empty($newMid)) {
                sendMessage($chatId, "❌ Please provide a Merchant ID. Usage: /setmid MID");
                return;
            }
            $settings['mid'] = $newMid;
            writeJson('settings.json', $settings);
            sendMessage($chatId, "✅ <b>Merchant ID Updated:</b> $newMid");
            return;
        }

        if (strpos($text, '/setupi ') === 0) {
            $settings = readJson('settings.json');
            if (empty($settings['auto_payment'])) {
                sendMessage($chatId, "⚠️ <b>Warning:</b> Enable Auto Payment first using /auto on");
                return;
            }
            $newUpi = trim(substr($text, 8));
            if (empty($newUpi)) {
                sendMessage($chatId, "❌ Please provide a UPI ID. Usage: /setupi UPI");
                return;
            }
            $settings['upi'] = $newUpi;
            writeJson('settings.json', $settings);
            sendMessage($chatId, "✅ <b>UPI ID Updated:</b> $newUpi");
            return;
        }

        if (strpos($text, '/setprice ') === 0) {
            $parts = explode(' ', $text, 2);
            if (count($parts) < 2) {
                sendMessage($chatId, "❌ Usage: /setprice [amount]");
                return;
            }
            $newPrice = trim($parts[1]);
            if (!is_numeric($newPrice)) {
                sendMessage($chatId, "❌ Please provide a valid numeric amount. Usage: /setprice 199");
                return;
            }
            $priceData = readJson('price.json');
            $priceData['amount'] = $newPrice;
            writeJson('price.json', $priceData);
            
            // Clear existing auto_payment sessions to force new QR generation with new price
            writeJson('auto_payment.json', []);
            
            sendMessage($chatId, "✅ <b>Price Updated:</b> ₹$newPrice\n(All active payment sessions have been reset)");
            return;
        }

        if (strpos($text, '/setmsg ') === 0) {
            $parts = explode(' ', $text, 2);
            if (count($parts) < 2) {
                sendMessage($chatId, "❌ Usage: /setmsg [message]");
                return;
            }
            $newMsg = trim($parts[1]);
            $priceData = readJson('price.json');
            $priceData['message'] = $newMsg;
            writeJson('price.json', $priceData);
            
            // Clear existing sessions
            writeJson('auto_payment.json', []);
            
            sendMessage($chatId, "✅ <b>Payment Message Updated:</b> $newMsg\n(All active payment sessions have been reset)");
            return;
        }

        // [SYSTEM 3] ADMIN <-> USER DIRECT CHAT (REPLY BASED)
        if (isset($message['reply_to_message'])) {
            $reply = $message['reply_to_message'];
            $targetUserId = 0;

            // Try to find User ID in caption or text of the message admin replied to
            $srcText = $reply['caption'] ?? $reply['text'] ?? '';
            if (preg_match('/User ID:\s*(\d+)/i', $srcText, $matches)) {
                $targetUserId = $matches[1];
            }

            if ($targetUserId) {
                // Forward Admin's reply to User
                $adminText = $text;
                $formattedReply = "👑 <b>Admin Reply:</b>\n\n" . htmlspecialchars($adminText);
                
                // If admin replied with text
                if (!empty($adminText)) {
                    $res = sendMessage($targetUserId, $formattedReply);
                } 
                // If admin replied with photo
                elseif (isset($message['photo'])) {
                    $photoId = end($message['photo'])['file_id'];
                    $caption = "👑 <b>Admin Reply:</b>\n" . htmlspecialchars($message['caption'] ?? '');
                    $res = sendPhoto($targetUserId, $photoId, $caption);
                }
                
                if (isset($res) && ($res['ok'] ?? false)) {
                    sendMessage($chatId, "✅ Reply sent to User ID: $targetUserId", null, $msgId);
                } else {
                    sendMessage($chatId, "❌ Failed to send reply. User might have blocked the bot.", null, $msgId);
                }
                return; // Stop further processing
            }
        }

        // Admin Commands
        if (is_string($text) && strpos($text, '/') === 0) {
            $parts = explode(' ', $text, 2);
            $command = $parts[0] ?? '';
            $arg = isset($parts[1]) ? trim($parts[1]) : '';

            // Prevent changing developer ID via setadmin command if needed, or allow both to use commands.
            // But specifically, the request is to allow this ID to use commands.

            switch ($command) {
                case '/start':
                    updateUser($userId, $username, $fullName);
                    sendStartMessage($chatId, $msgId);
                    return;

                case '/settoken':
                    if (empty($arg)) { sendMessage($chatId, "⚠️ <b>Usage:</b> <code>/settoken NEW_TOKEN</code>\n\nGet your token from @BotFather.", null, $msgId); return; }
                    $newToken = trim($arg);
                    if (strpos($newToken, ':') === false) { sendMessage($chatId, "❌ <b>Invalid Token!</b> Tokens usually look like <code>123456:ABC-DEF...</code>", null, $msgId); return; }
                    
                    $config['bot_token'] = $newToken;
                    if (writeJson('config.json', $config)) {
                        global $BOT_TOKEN; $BOT_TOKEN = $newToken;
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $path = $_SERVER['SCRIPT_NAME'];
                        $webhookUrl = "$protocol://$host$path";
                        
                        $res = apiRequest('setWebhook', ['url' => $webhookUrl]);
                        $botInfo = apiRequest('getMe');
                        $botName = $botInfo['result']['first_name'] ?? 'Bot';
                        $botUser = $botInfo['result']['username'] ?? 'Unknown';

                        if ($res && ($res['ok'] ?? false)) {
                            $msg = "✅ <b>Bot Successfully Connected!</b>\n\n" .
                                   "🤖 <b>Bot Name:</b> $botName\n" .
                                   "🔗 <b>Username:</b> @$botUser\n" .
                                   "🌐 <b>Webhook:</b> Set Successfully\n" .
                                   "📅 <b>Date:</b> " . date("Y-m-d H:i:s") . "\n\n" .
                                   "<i>Your bot is now live and ready to use!</i>";
                            sendMessage($chatId, $msg, null, $msgId);
                        } else {
                            sendMessage($chatId, "⚠️ <b>Token Saved, but Webhook Failed!</b>\n\nError: " . ($res['description'] ?? 'Unknown error') . "\n\nPlease check if the token is correct.", null, $msgId);
                        }
                    } else {
                        sendMessage($chatId, "❌ <b>Error!</b> Failed to save config.json. Check file permissions.", null, $msgId);
                    }
                    return;

                case '/setadmin':
                    if (!preg_match('/^[0-9]+$/', $arg)) { 
                        sendMessage($chatId, "❌ Invalid User ID.", null, $msgId); 
                        return; 
                    }
                    $newAdminId = (int)$arg;
                    $currentAdminId = (int)($config['admin_userid'] ?? 0);

                    if ($newAdminId === $currentAdminId) {
                        sendMessage($chatId, "⚠️ This user is already the admin.", null, $msgId);
                        return;
                    }

                    // Check if new user is in users.json
                    $usersData = readJson('users.json');
                    if (!isset($usersData['users'][$newAdminId])) {
                        sendMessage($chatId, "⚠️ This user is not registered in the bot database.\n      Please ensure the user has started the bot first.", null, $msgId);
                        return;
                    }

                    // Fetch names via getChat API
                    $newAdminChat = apiRequest('getChat', ['chat_id' => $newAdminId]);
                    $oldAdminChat = apiRequest('getChat', ['chat_id' => $currentAdminId]);

                    $newAdminName = "New Admin";
                    if ($newAdminChat && isset($newAdminChat['result'])) {
                        $newAdminName = trim(($newAdminChat['result']['first_name'] ?? '') . ' ' . ($newAdminChat['result']['last_name'] ?? ''));
                        if (empty($newAdminName)) $newAdminName = "New Admin";
                    }

                    $oldAdminName = "Old Admin";
                    if ($oldAdminChat && isset($oldAdminChat['result'])) {
                        $oldAdminName = trim(($oldAdminChat['result']['first_name'] ?? '') . ' ' . ($oldAdminChat['result']['last_name'] ?? ''));
                        if (empty($oldAdminName)) $oldAdminName = "Old Admin";
                    }

                    // Protect JSON update
                    $config['admin_userid'] = $newAdminId;
                    if (writeJson('config.json', $config)) {
                        global $ADMIN_ID;
                        $ADMIN_ID = $newAdminId;
                        
                        // Success msg to current user
                        $successMsg = "━━━━━━━━━━━━━━━━━━\n👑 NEW ADMIN SUCCESSFULLY SET\n\n👤 Name:\n<a href=\"tg://user?id={$newAdminId}\">" . htmlspecialchars($newAdminName) . "</a>\n\n🆔 User ID:\n<code>{$newAdminId}</code>\n\n✅ Admin rights transferred successfully.\n━━━━━━━━━━━━━━━━━━";
                        sendMessage($chatId, $successMsg, null, $msgId);
                        
                        // Notification to new admin
                        $notifyMsg = "━━━━━━━━━━━━━━━━━━\n🎉 YOU ARE MY NEW ADMIN\n\n👤 Previous Admin:\n<a href=\"tg://user?id={$currentAdminId}\">" . htmlspecialchars($oldAdminName) . "</a>\n\n🆔 <code>{$currentAdminId}</code>\n\n🔐 You now have full admin access.\n━━━━━━━━━━━━━━━━━━";
                        sendMessage($newAdminId, $notifyMsg);
                    } else {
                        sendMessage($chatId, "❌ <b>Error!</b> Failed to save admin ID.", null, $msgId);
                    }
                    return;


                case '/setdemo':
                    if (empty($arg)) { sendMessage($chatId, "⚠️ <b>Usage:</b> <code>/setdemo LINK</code>", null, $msgId); return; }
                    $config['demo_channel_link'] = trim($arg);
                    writeJson('config.json', $config);
                    sendMessage($chatId, "✅ <b>Demo Channel Updated!</b>\nLink: $arg", null, $msgId);
                    return;

                case '/setproof':
                    if (empty($arg)) { sendMessage($chatId, "⚠️ <b>Usage:</b> <code>/setproof LINK</code>", null, $msgId); return; }
                    $config['proofs_channel_link'] = trim($arg);
                    writeJson('config.json', $config);
                    sendMessage($chatId, "✅ <b>Proofs Channel Updated!</b>\nLink: $arg", null, $msgId);
                    return;

                case '/totalusers':
                    $usersData = readJson('users.json');
                    sendMessage($chatId, "📊 <b>Total Users:</b> " . ($usersData['total_users'] ?? 0), null, $msgId);
                    return;

                case '/check':
                    $premiumChannelId = $config['target_channel_id'] ?? null;
                    if (!$premiumChannelId) {
                        sendMessage($chatId, "❌ <b>Channel ID is not set!</b>\nUse <code>/setid CHANNEL_ID</code> to set it.", null, $msgId);
                        return;
                    }
                    $res = apiRequest('getChatMember', [
                        'chat_id' => $premiumChannelId,
                        'user_id' => $BOT_TOKEN ? explode(':', $BOT_TOKEN)[0] : 0
                    ]);
                    
                    if ($res && ($res['ok'] ?? false)) {
                        $status = $res['result']['status'] ?? '';
                        if ($status === 'administrator' || $status === 'creator') {
                            $canInvite = ($res['result']['can_invite_users'] ?? false) ? "✅ Yes" : "❌ No";
                            $msg = "📢 <b>Channel Status Check</b>\n\n" .
                                   "🆔 <b>Channel ID:</b> <code>$premiumChannelId</code>\n" .
                                   "🤖 <b>Bot Status:</b> Admin\n" .
                                   "🔗 <b>Can Create Links:</b> $canInvite";
                        } else {
                            $msg = "⚠️ <b>Bot is NOT an Admin in the channel!</b>\n\nPlease make the bot an admin in <code>$premiumChannelId</code> with link creation permissions.";
                        }
                    } else {
                        $error = $res['description'] ?? 'Unknown Error';
                        $msg = "❌ <b>Check Failed!</b>\n\nError: $error\n\nMake sure the bot is added to the channel <code>$premiumChannelId</code>.";
                    }
                    sendMessage($chatId, $msg, null, $msgId);
                    return;

                case '/setimg':
                    if (empty($arg)) { sendMessage($chatId, "❌ Usage: /setimg URL", null, $msgId); return; }
                    $url = trim(preg_replace('/\s+/', '', $arg));
                    
                    // Validate URL
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url)) {
                        sendMessage($chatId, "⚠️ Invalid Image URL. Please provide direct image link.", null, $msgId);
                        return;
                    }

                    // Save to temp
                    writeJson('temp_setimg.json', ['url' => $url, 'time' => time()]);

                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '✅ Confirm', 'callback_data' => 'confirm_setimg'],
                                ['text' => '❌ Remove', 'callback_data' => 'remove_setimg']
                            ]
                        ]
                    ];

                    $res = sendPhoto($chatId, $url, "🖼 <b>Preview Start Image</b>\n\nKya aap is image ko confirm karna chahte hain?", json_encode($keyboard), $msgId);
                    if (!$res || (isset($res['ok']) && !$res['ok'])) {
                         sendMessage($chatId, "⚠️ Invalid Image URL. Please provide direct image link.", null, $msgId);
                         @unlink($baseDir . '/temp_setimg.json');
                    }
                    return;

                case '/remimg':
                    writeJson('img.json', ['url' => '']);
                    sendMessage($chatId, "🗑️ Start Image URL removed.", null, $msgId);
                    return;

                case '/setqr':
                    if (empty($arg)) { sendMessage($chatId, "❌ Usage: /setqr URL", null, $msgId); return; }
                    $url = trim(preg_replace('/\s+/', '', $arg));
                    
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', explode('?', $url)[0])) {
                        sendMessage($chatId, "⚠️ Invalid QR Image URL. Please provide direct image link.", null, $msgId);
                        return;
                    }

                    writeJson('temp_setqr.json', ['url' => $url, 'time' => time()]);

                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '✅ Confirm QR', 'callback_data' => 'confirm_setqr'],
                                ['text' => '❌ Remove', 'callback_data' => 'remove_setqr']
                            ]
                        ]
                    ];

                    $res = sendPhoto($chatId, $url, "🖼 <b>Preview QR Image</b>\n\nKya aap is QR image ko confirm karna chahte hain?", json_encode($keyboard), $msgId);
                    if (!$res || (isset($res['ok']) && !$res['ok'])) {
                        sendMessage($chatId, "⚠️ Invalid Image URL. Please provide direct image link.", null, $msgId);
                        @unlink($baseDir . '/temp_setqr.json');
                    }
                    return;

                case '/setid':
                    if (empty($arg)) { 
                        sendMessage($chatId, "⚠️ <b>Usage:</b> <code>/setid CHANNEL_ID</code>\n\nExample: <code>/setid -100123456789</code>", null, $msgId); 
                        return; 
                    }

                    $newChannelId = trim($arg);

                    // Validate Channel ID Format
                    if (!preg_match('/^-100\d+$/', $newChannelId)) {
                        sendMessage($chatId, "❌ Invalid Channel ID format.\nMust start with -100 followed by numbers.", null, $msgId);
                        return;
                    }

                    // Verify Channel Using getChat
                    $chatInfo = apiRequest('getChat', ['chat_id' => $newChannelId]);
                    if (!$chatInfo || !isset($chatInfo['result'])) {
                        sendMessage($chatId, "❌ Unable to verify channel. Please check the Channel ID.", null, $msgId);
                        return;
                    }

                    $channelTitle = $chatInfo['result']['title'] ?? 'Unknown Title';
                    $channelUsername = $chatInfo['result']['username'] ?? null;
                    $channelLink = $channelUsername ? "https://t.me/" . $channelUsername : "Private Channel";

                    // Check Bot Admin Status
                    $botId = explode(':', $BOT_TOKEN)[0];
                    $adminCheck = apiRequest('getChatMember', [
                        'chat_id' => $newChannelId,
                        'user_id' => $botId
                    ]);

                    $isAdmin = false;
                    if ($adminCheck && isset($adminCheck['result'])) {
                        $status = $adminCheck['result']['status'] ?? '';
                        if ($status === 'administrator' || $status === 'creator') {
                            $isAdmin = true;
                        }
                    }

                    $adminStatusStr = $isAdmin ? "✅ Yes" : "❌ No";

                    // Update Config
                    $config['target_channel_id'] = $newChannelId;
                    if (writeJson('config.json', $config)) {
                        // Success Response
                        $successMsg = "━━━━━━━━━━━━━━━━━━\n" .
                                      "📢 NEW CHANNEL SUCCESSFULLY SET\n\n" .
                                      "📛 Channel Name:\n<b>" . htmlspecialchars($channelTitle) . "</b>\n\n" .
                                      "🆔 Channel ID:\n<code>" . htmlspecialchars($newChannelId) . "</code>\n\n" .
                                      "🔗 Channel Link:\n" . htmlspecialchars($channelLink) . "\n\n" .
                                      "🤖 Bot Admin in Channel:\n" . $adminStatusStr . "\n" .
                                      "━━━━━━━━━━━━━━━━━━\n" .
                                      "𝑷𝒐𝒘𝒆𝒓𝒆𝒅 𝒃𝒚 @BALAKxBOTS\n" .
                                      "━━━━━━━━━━━━━━━━━━";

                        sendMessage($chatId, $successMsg, null, $msgId);

                        if (!$isAdmin) {
                            sendMessage($chatId, "⚠️ <b>Warning:</b> Bot must be admin in the channel to post messages.", null, $msgId);
                        }
                    } else {
                        sendMessage($chatId, "❌ <b>Error!</b> Failed to save channel ID.", null, $msgId);
                    }
                    return;

                case '/remqr':
                    writeJson('qr.json', ['url' => '']);
                    sendMessage($chatId, "🗑️ QR Image URL removed.", null, $msgId);
                    return;

                case '/setcap':
                    if (empty($arg)) { sendMessage($chatId, "❌ Usage: /setcap TEXT", null, $msgId); return; }
                    writeJson('caption.json', ['text' => $arg]);
                    sendMessage($chatId, "✅ Start Caption updated.", null, $msgId);
                    return;

                case '/remcap':
                    writeJson('caption.json', ['text' => '']);
                    sendMessage($chatId, "🗑️ Start Caption removed.", null, $msgId);
                    return;

                case '/setcap2':
                    if (empty($arg)) { sendMessage($chatId, "❌ Usage: /setcap2 TEXT", null, $msgId); return; }
                    writeJson('caption2.json', ['text' => $arg]);
                    sendMessage($chatId, "✅ Premium Caption updated.", null, $msgId);
                    return;

                case '/OPEN':
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $basePath = dirname($_SERVER['SCRIPT_NAME']);
                    // Ensure basePath has trailing slash if root, or construct cleanly
                    $basePath = rtrim($basePath, '/');

                    if ($userId == $ADMIN_ID || $userId == $DEVELOPER_ID) {
                        $webappUrl = "$protocol://$host$basePath/webapp/dashboard.php?uid=$userId";
                        
                        $keyboard = [
                            'inline_keyboard' => [
                                [['text' => 'OPEN PANEL', 'web_app' => ['url' => $webappUrl]]]
                            ]
                        ];
                        
                        sendMessage($chatId, "✅ <b>Admin Panel Ready</b>\nTap button below to open", json_encode($keyboard), $msgId);
                    } else {
                        sendMessage($chatId, "⛔ <b>Access Denied</b>", null, $msgId);
                    }
                    return;

                case '/broad':
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $basePath = dirname($_SERVER['SCRIPT_NAME']);
                    $basePath = rtrim($basePath, '/');
                    $webappUrl = "$protocol://$host$basePath/webapp/broadcast.html";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => 'OPEN BROADCAST', 'web_app' => ['url' => $webappUrl]]]
                        ]
                    ];
                    
                    sendMessage($chatId, "👇 <b>Click below to open Broadcast Panel</b>", json_encode($keyboard), $msgId);
                    return;

                case '/remcap2':
                    writeJson('caption2.json', ['text' => '']);
                    sendMessage($chatId, "🗑️ Premium Caption removed.", null, $msgId);
                    return;

                case '/cmds':
                case '/help':
                    if ($userId == $DEVELOPER_ID) {
                        $help = "🚀 <b>Developer Console</b>\n\n" .
                                "🛠️ <b>System:</b>\n" .
                                "• <code>/settoken TOKEN</code>\n" .
                                "• <code>/setadmin ID</code>\n" .
                                "• <code>/setid CHANNEL_ID</code>\n\n" .
                                "💳 <b>Payments:</b>\n" .
                                "• <code>/auto on/off</code>\n" .
                                "• <code>/setmid MID</code>\n" .
                                "• <code>/setupi UPI</code>\n" .
                                "• <code>/setprice AMT</code>\n" .
                                "• <code>/setmsg MSG</code>\n\n" .
                                "👤 <b>User:</b>\n" .
                                "• <code>/totalusers</code>\n" .
                                "• <code>/broad</code>\n" .
                                "• <code>/OPEN</code> - Admin Panel";
                    } elseif ($userId == $ADMIN_ID) {
                        $help = "👑 <b>Admin Control Panel</b>\n\n" .
                                "💳 <b>Auto Payment:</b>\n" .
                                "• <code>/auto on/off</code> - Toggle system\n" .
                                "• <code>/setmid MID</code> - Set Merchant ID\n" .
                                "• <code>/setupi UPI</code> - Set UPI ID\n" .
                                "• <code>/setprice AMT</code> - Set Amount\n" .
                                "• <code>/setmsg MSG</code> - Set Note\n\n" .
                                "👤 <b>User Management:</b>\n" .
                                "• <code>/totalusers</code> - Total users\n" .
                                "• <code>/broad</code> - Broadcast message\n" .
                                "• <code>/OPEN</code> - Open Web Dashboard";
                    } else {
                        $help = "👋 <b>Welcome to Premium Bot</b>\n\n" .
                                "✨ <b>Available Commands:</b>\n" .
                                "• <code>/start</code> - Check premium status\n" .
                                "• <code>/help</code> - Show this menu\n\n" .
                                "💎 Join our premium channel for exclusive content!";
                    }
                    sendMessage($chatId, $help, null, $msgId);
                    return;

                case '/broadcast':
                    // Check if reply exists
                    $reply = $message['reply_to_message'] ?? null;
                    
                    if (!$reply && empty($arg)) { 
                        sendMessage($chatId, "⚠️ <b>Usage:</b>\n1. Reply to a message (text/photo/video) with <code>/broadcast</code>\n2. Or type <code>/broadcast MESSAGE</code>", null, $msgId); 
                        return; 
                    }

                    // Prepare Data
                    $type = 'text';
                    $content = '';
                    $caption = '';

                    if ($reply) {
                        // Handle Reply
                        if (isset($reply['text'])) {
                            $type = 'text';
                            $content = $reply['text'];
                        } elseif (isset($reply['photo'])) {
                            $type = 'photo';
                            $content = end($reply['photo'])['file_id']; // Best quality
                            $caption = $reply['caption'] ?? '';
                        } elseif (isset($reply['video'])) {
                            $type = 'video';
                            $content = $reply['video']['file_id'];
                            $caption = $reply['caption'] ?? '';
                        } else {
                            sendMessage($chatId, "❌ Unsupported media type in reply. Only Text, Photo, and Video are supported.", null, $msgId);
                            return;
                        }
                    } else {
                        // Handle Argument (Text only)
                        $type = 'text';
                        $content = $arg;
                    }
                    
                    $lockFile = $baseDir . '/broadcast_running.lock';
                    if (file_exists($lockFile)) {
                        $lockContent = file_get_contents($lockFile);
                        $lockData = json_decode($lockContent, true);
                        
                        if (!is_array($lockData)) {
                             // Invalid lock file, treat as not running or stale
                             $lockTime = 0; 
                        } else {
                             $lockTime = $lockData['started_at'] ?? 0;
                        }
                        
                        $elapsed = time() - $lockTime;
                        if ($elapsed < 3600) {
                            $mins = round($elapsed / 60);
                            sendMessage($chatId, "⚠️ <b>Broadcast Already Running!</b>\n\nEk broadcast pehle se chal raha hai ($mins min se).\nNaya broadcast start nahi ho sakta jab tak pehla khatam na ho.\n\nForce stop karne ke liye: <code>/stopbroadcast</code>", null, $msgId);
                            return;
                        } else {
                            @unlink($lockFile);
                        }
                    }

                    $lastBroadcast = readJson('last_broadcast.json');
                    $msgHash = md5($type . $content . $caption);
                    
                    if (isset($lastBroadcast['hash']) && $lastBroadcast['hash'] === $msgHash) {
                        $lastTime = $lastBroadcast['sent_at'] ?? 0;
                        $timeDiff = time() - $lastTime;
                        if ($timeDiff < 300) {
                            $wait = 300 - $timeDiff;
                            sendMessage($chatId, "⚠️ <b>Duplicate Broadcast Blocked!</b>\n\nYehi same message $timeDiff seconds pehle bheja gaya tha.\nDobara bhejne ke liye $wait seconds wait karo.\n\nAlag message bhejne ke liye koi different text likho.", null, $msgId);
                            return;
                        }
                    }

                    $data = [
                        'type' => $type,
                        'content' => $content,
                        'caption' => $caption,
                        'media_group' => []
                    ];
                    file_put_contents($baseDir . '/broadcast_data.json', json_encode($data));
                    
                    writeJson('last_broadcast.json', [
                        'hash' => $msgHash,
                        'sent_at' => time(),
                        'content_preview' => ($type === 'text' ? mb_substr($content, 0, 100) : "Media: $type")
                    ]);
                    
                    if (triggerBackgroundProcess('broadcast_runner.php')) {
                        $msg = "🚀 <b>Broadcast Started Successfully!</b>\n\n" .
                               "📦 <b>Type:</b> " . strtoupper($type) . "\n" .
                               "👥 <b>Target Users:</b> " . ($usersData['total_users'] ?? 'Calculating...') . "\n" .
                               "⏳ <b>Status:</b> Running in background\n\n" .
                               "<i>You will receive progress updates shortly.</i>";
                        sendMessage($chatId, $msg, null, $msgId);
                    } else {
                        sendMessage($chatId, "❌ <b>Trigger Failed!</b>\nCould not start the background process. Please check server logs.", null, $msgId);
                    }
                    return;

                case '/stopbroadcast':
                    $lockFile = $baseDir . '/broadcast_running.lock';
                    $stopFlag = $baseDir . '/stop_broadcast.flag';
                    file_put_contents($stopFlag, time());
                    if (file_exists($lockFile)) {
                        @unlink($lockFile);
                        sendMessage($chatId, "🛑 <b>Broadcast Stop Signal Sent!</b>\nBroadcast jaldi band ho jayega.", null, $msgId);
                    } else {
                        sendMessage($chatId, "ℹ️ Koi broadcast abhi chal nahi raha, lekin stop flag set kar diya hai.", null, $msgId);
                    }
                    return;

                case '/ban':
                    if (empty($arg)) { sendMessage($chatId, "Usage: /ban USER_ID", null, $msgId); return; }
                    $usersData = readJson('users.json');
                    if (isset($usersData['users'][$arg])) {
                        $usersData['users'][$arg]['status'] = 'blocked';
                        writeJson('users.json', $usersData);
                        sendMessage($chatId, "User $arg has been banned.", null, $msgId);
                    } else { sendMessage($chatId, "User $arg not found.", null, $msgId); }
                    return;

                case '/unban':
                    if (empty($arg)) { sendMessage($chatId, "Usage: /unban USER_ID", null, $msgId); return; }
                    $usersData = readJson('users.json');
                    if (isset($usersData['users'][$arg])) {
                        $usersData['users'][$arg]['status'] = 'active';
                        writeJson('users.json', $usersData);
                        sendMessage($chatId, "User $arg has been unbanned.", null, $msgId);
                    } else { sendMessage($chatId, "User $arg not found.", null, $msgId); }
                    return;
            }

            // Unknown admin command: avoid fall-through into user flow
            return;
        }
    }

    // --- User Logic ---
    $usersData = readJson('users.json');
    if (isset($usersData['users'][$userId]) && ($usersData['users'][$userId]['status'] ?? '') === 'blocked') {
        return; // Do nothing for banned users
    }

    // [SYSTEM 1] PAYMENT SCREENSHOT HANDLING & DIRECT CHAT & AUTO PAYMENT
    $userState = $usersData['users'][$userId]['state'] ?? '';

    if ($userState === 'awaiting_txn' && !empty($text) && strpos($text, '/') !== 0) {
        $txnId = trim($text);

        // 1. Validate Format
        if (strlen($txnId) < 8 || !ctype_alnum($txnId)) {
            sendMessage($chatId, "❌ <b>Invalid Transaction ID!</b>\n\nMust be at least 8 characters and alphanumeric.\nPlease try again.");
            return;
        }

        // 2. Check Duplicates
        $txns = readJson('transactions.json');
        if (in_array($txnId, $txns)) {
            sendMessage($chatId, "❌ <b>Transaction ID already used!</b>\nPlease contact admin if this is a mistake.");
            return;
        }

        // 3. Verify API
        sendMessage($chatId, "⏳ Verifying transaction...");
        $apiUrl = "https://verify.pikaapis.workers.dev/?id=KUXkzh00504432927191&trn=" . urlencode($txnId);
        
        // Use simple curl or file_get_contents
        $apiRes = file_get_contents($apiUrl);
        $apiData = json_decode($apiRes, true);
        
        // Check STATUS
        if (($apiData['STATUS'] ?? '') === 'TXN_SUCCESS') {
            // Success!
            
            // Mark TXN as used
            $txns[] = $txnId;
            writeJson('transactions.json', $txns);
            
            // Clear state
            $usersData['users'][$userId]['state'] = 'active';
            writeJson('users.json', $usersData);
            
            // Call Approval Logic (Reused)
            approveUserLogic($userId, $ADMIN_ID, null, null, $txnId);
            
        } else {
            sendMessage($chatId, "❌ <b>Payment Verification Failed!</b>\n\nStatus: " . ($apiData['STATUS'] ?? 'Unknown'));
        }
        return;
    }

    if (isset($message['photo']) || (!empty($text) && $userId != $ADMIN_ID && !isset($message['reply_to_message']) && strpos($text, '/') !== 0)) {
        $photoId = isset($message['photo']) ? end($message['photo'])['file_id'] : null;
        $caption = $message['caption'] ?? $text ?? '(No text)';
        
        // Prepare Admin Notification
        $type = $photoId ? "Screenshot" : "Message";
        $adminMsg = "📩 <b>New $type Received</b>\n\n" .
                    "👤 <b>User ID:</b> $userId\n" .
                    "👤 <b>Name:</b> " . htmlspecialchars($fullName) . "\n" .
                    "🔗 <b>Username:</b> @" . ($username ?: 'None') . "\n" .
                    "💬 <b>Content:</b> " . htmlspecialchars($caption);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => "approve_$userId"],
                    ['text' => '❌ Reject', 'callback_data' => "reject_$userId"]
                ]
            ]
        ];

        // Send to Admin
        if ($ADMIN_ID) {
            if ($photoId) {
                sendPhoto($ADMIN_ID, $photoId, $adminMsg, json_encode($keyboard));
            } else {
                sendMessage($ADMIN_ID, $adminMsg, json_encode($keyboard));
            }
            // Notify User
            sendMessage($chatId, "✅ <b>Message Sent!</b>\n\nAdmin will review it soon.", null, $msgId);
        } else {
            sendMessage($chatId, "⚠️ System Error: Admin ID not set. Please contact support.", null, $msgId);
        }
        return;
    }

    // c) User commands (add more user commands here if needed)
    return;
}

function handleCallbackQuery($callbackQuery) {
    global $ADMIN_ID, $DEVELOPER_ID, $config, $baseDir;

    $chatId = $callbackQuery['message']['chat']['id'];
    $userId = $callbackQuery['from']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];
    $callbackId = $callbackQuery['id'];

    if ($data === 'confirm_setimg') {
        if ($userId != $ADMIN_ID && $userId != $DEVELOPER_ID) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Access Denied', 'show_alert' => true]);
            return;
        }
        $tempImgFile = $baseDir . '/temp_setimg.json';
        if (file_exists($tempImgFile)) {
            $tempImgData = json_decode(file_get_contents($tempImgFile), true);
            if (!empty($tempImgData['url'])) {
                writeJson('img.json', ['url' => $tempImgData['url']]);
                @unlink($tempImgFile);
                apiRequest('editMessageCaption', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'caption' => "✅ <b>Start Image Successfully Updated.</b>",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => []])
                ]);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Success!']);
            } else {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Session expired.', 'show_alert' => true]);
            }
        } else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Session expired.', 'show_alert' => true]);
        }
        return;
    }

    if ($data === 'confirm_setqr') {
        if ($userId != $ADMIN_ID && $userId != $DEVELOPER_ID) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Access Denied', 'show_alert' => true]);
            return;
        }
        $tempQrFile = $baseDir . '/temp_setqr.json';
        if (file_exists($tempQrFile)) {
            $tempQrData = json_decode(file_get_contents($tempQrFile), true);
            if (!empty($tempQrData['url'])) {
                writeJson('qr.json', ['url' => $tempQrData['url']]);
                @unlink($tempQrFile);
                apiRequest('editMessageCaption', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'caption' => "✅ <b>QR Image Successfully Updated.</b>",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => []])
                ]);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Success!']);
            } else {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Session expired.', 'show_alert' => true]);
            }
        } else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Session expired.', 'show_alert' => true]);
        }
        return;
    }

    if ($data === 'remove_setimg' || $data === 'remove_setqr') {
        if ($userId != $ADMIN_ID && $userId != $DEVELOPER_ID) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Access Denied', 'show_alert' => true]);
            return;
        }
        @unlink($baseDir . '/temp_setimg.json');
        @unlink($baseDir . '/temp_setqr.json');
        apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        sendMessage($chatId, "❌ Image update cancelled.");
        return;
    }

    // Check if banned
    $usersData = readJson('users.json');
    if (isset($usersData['users'][$userId]) && ($usersData['users'][$userId]['status'] ?? '') === 'blocked') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'You are banned.', 'show_alert' => true]);
        return;
    }

    // [SYSTEM 2] APPROVE / REJECT CALLBACK
    if (strpos($data, 'approve_') === 0) {
        $targetUserId = str_replace('approve_', '', $data);
        approveUserLogic($targetUserId, $chatId, $callbackId, $messageId);
        return;
    }

    if (strpos($data, 'reject_') === 0) {
        $targetUserId = str_replace('reject_', '', $data);
        
        // Notify User
        sendMessage($targetUserId, "❌ <b>Payment Rejected.</b>\nPlease send a clear payment screenshot again.");
        
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Rejected!']);
        sendMessage($chatId, "❌ Rejected payment for User ID: $targetUserId");
        
        // Remove buttons
        apiRequest('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ]);
        return;
    }

    // Answer callback to stop loading animation
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

    if($data==="qr_generate"){

        $cid=$callbackQuery["message"]["chat"]["id"];
        $mid=$callbackQuery["message"]["message_id"];

        # DELETE OLD MESSAGE
        apiRequest("deleteMessage",[
            "chat_id"=>$cid,
            "message_id"=>$mid
        ]);

        # WAIT MESSAGE
        apiRequest("sendMessage",[
            "chat_id"=>$cid,
            "text"=>"QR CODE GENERATING..."
        ]);

        $qrfile = __DIR__."/qr.json";

        $qr_url="";

        if(file_exists($qrfile)){
            $json=json_decode(file_get_contents($qrfile),true);
            $qr_url=$json["url"] ?? "";
        }

        # SEND QR IMAGE
        if(!empty($qr_url)){
            apiRequest("sendPhoto",[
                "chat_id"=>$cid,
                "photo"=>$qr_url,
                "caption"=>"Send screenshot after payment",
                "reply_markup"=>json_encode([
                    "inline_keyboard"=>[
                        [
                            [
                                "text"=>"I HAVE PAID (Submit Screenshot)",
                                "callback_data"=>"submit_payment"
                            ]
                        ]
                    ]
                ])
            ]);
        }else{
            apiRequest("sendMessage",[
                "chat_id"=>$cid,
                "text"=>"QR FILE NOT FOUND"
            ]);
        }

        return;
    }

    if ($data === 'submit_payment') {
        $settings = readJson('settings.json');
        if (!empty($settings['auto_payment'])) {
            $usersData = readJson('users.json');
            $usersData['users'][$userId]['state'] = 'awaiting_txn';
            writeJson('users.json', $usersData);
            sendMessage($chatId, "💳 <b>Auto Payment Mode</b>\n\nPlease enter your <b>Transaction ID</b> below:");
        } else {
            sendMessage($chatId, "📸 <b>Please send your payment screenshot now.</b>\n\nYou can also include your UTR or any message in the caption.");
        }
        return;
    }

    if ($data === 'get_premium') {
        $settings = readJson('settings.json');
        
        if (!empty($settings['auto_payment'])) {
            // STEP 1: READ PRICE
            $priceData = readJson('price.json');
            if (empty($priceData['amount'])) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ System Error: Price not configured', 'show_alert' => true]);
                return;
            }
            $price_amount = $priceData['amount'];
            $payment_note = $priceData['message'] ?? 'Bot Payment';
            
            // Check existing auto_payment status
            $autoPayments = readJson('auto_payment.json');
            $qrImageUrl = null;
            
            if (isset($autoPayments[$userId])) {
                $userSession = $autoPayments[$userId];
                
                // If paid and claimed
                if ($userSession['status'] === 'paid' && !empty($userSession['claimed'])) {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ PAYMENT ALREADY CLAIM PLEASE GENERATE NEW QR', 'show_alert' => true]);
                    return;
                }
                
                // If pending, just show existing
                if ($userSession['status'] === 'pending') {
                    $track_id = $userSession['track_id'] ?? '';
                    $amount = $userSession['amount'] ?? $price_amount;
                    $qrImageUrl = $userSession['qr_image_url'] ?? null;
                }
            }
            
            if (empty($qrImageUrl)) {
                // Call QR Generate API
                $mid = $settings['mid'] ?? 'KUXkzh00504432927191';
                $upi = $settings['upi'] ?? 'paytmqr1d7sduqnyq@paytm';
                $apiUrl = "https://payment.pikaapis.workers.dev/?id={$mid}&upi={$upi}&amount={$price_amount}&note=" . urlencode($payment_note);
                
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $response = curl_exec($ch);
                curl_close($ch);
                
                $qrData = json_decode($response, true);
                
                if (!$qrData || ($qrData['status'] ?? '') !== 'success') {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Failed to generate QR Code. Try again.', 'show_alert' => true]);
                    return;
                }
                
                $track_id = $qrData['trackId'] ?? '';
                $amount = $price_amount; // amount is not returned in API
                $qrImageUrl = $qrData['qrImageUrl'];
                
                // Save to auto_payment.json
                $autoPayments[$userId] = [
                    'track_id' => $track_id,
                    'amount' => $amount,
                    'qr_image_url' => $qrImageUrl,
                    'status' => 'pending',
                    'claimed' => false,
                    'created_at' => time()
                ];
                writeJson('auto_payment.json', $autoPayments);
            }
            
            // Send QR image with caption
            $cap = readJson('caption2.json');
            $captionText = $cap['text'] ?? '';
            
            $media = [
                'type' => 'photo',
                'media' => $qrImageUrl,
                'caption' => $captionText
            ];
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'GET PRIVATE CHANNEL LINK ✅', 'callback_data' => 'check_payment']
                    ],
                    [
                        ['text' => '🔙 Back', 'callback_data' => 'back_menu']
                    ]
                ]
            ];
            
            $res = editMessageMedia($chatId, $messageId, json_encode($media), json_encode($keyboard));
            
            if (!$res || empty($res['ok'])) {
                // Fallback: Delete old message and send a new one
                apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
                sendPhoto($chatId, $qrImageUrl, $captionText, json_encode($keyboard));
            }
            
            return;
        }

        $qr = readJson('qr.json');
        $cap = readJson('caption2.json');
        
        $media = [
            'type' => 'photo',
            'media' => $qr['url'] ?? '',
            'caption' => $cap['text'] ?? ''
        ];
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ I HAVE PAID (Submit Screenshot)', 'callback_data' => 'send_screenshot']
                ],
                [
                    ['text' => '📜 VIEW PROOFS', 'url' => $config['proofs_channel_link']]
                ],
                [
                    ['text' => '✖️ Cancel', 'callback_data' => 'back']
                ]
            ]
        ];

        $res = editMessageMedia($chatId, $messageId, json_encode($media), json_encode($keyboard));
        
        if (!$res || empty($res['ok'])) {
            // Fallback
            apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
            sendPhoto($chatId, $qr['url'] ?? '', $cap['text'] ?? '', json_encode($keyboard));
        }
        
    } elseif ($data === 'check_payment') {
        $autoPayments = readJson('auto_payment.json');
        
        // Acknowledge the callback immediately
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

        if (!isset($autoPayments[$userId])) {
            sendMessage($chatId, "❌ Payment session expired. Please generate a new QR.");
            return;
        }

        $userSession = $autoPayments[$userId];
        
        if ($userSession['status'] === 'paid' && !empty($userSession['claimed'])) {
            sendMessage($chatId, "❌ PAYMENT ALREADY CLAIM PLEASE GENERATE NEW QR");
            return;
        }

        $track_id = $userSession['track_id'];
        $saved_amount = $userSession['amount'];
        
        $settings = readJson('settings.json');
        // Verify API
        $mid = $settings['mid'] ?? 'KUXkzh00504432927191';
        $apiUrl = "https://verify.pikaapis.workers.dev/?id={$mid}&trn=" . urlencode($track_id);
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response === false) {
            sendMessage($chatId, "❌ Connection Error. Please try again later.");
            return;
        }

        $apiData = json_decode($response, true);
        $status = $apiData['STATUS'] ?? '';
        
        if ($status === 'TXN_FAILED' || $status === 'FAILURE') {
            sendMessage($chatId, "❌ PAYMENT FAILED\n\nPayment karo, fir try karo. (Make the payment, then try again)");
            return;
        } elseif ($status === 'PENDING') {
            sendMessage($chatId, "⏳ PAYMENT PENDING! Please check after some time.");
            return;
        } elseif ($status !== 'TXN_SUCCESS') {
            sendMessage($chatId, "❌ PAYMENT NOT RECEIVED YET! Please pay first.");
            return;
        }
        
        if (($apiData['TXNAMOUNT'] ?? '') != $saved_amount) {
            $received = $apiData['TXNAMOUNT'] ?? '0';
            sendMessage($chatId, "❌ INVALID AMOUNT! Expected ₹{$saved_amount}, Received ₹{$received}");
            return;
        }
        
        // Duplicate Payment Protection
        foreach ($autoPayments as $uid => $session) {
            if ($uid != $userId && $session['track_id'] === $track_id && $session['status'] === 'paid') {
                sendMessage($chatId, "❌ THIS PAYMENT IS ALREADY USED BY ANOTHER USER");
                return;
            }
        }
        
        // Success Flow
        $premiumChannelId = $config['target_channel_id'] ?? null;
        if (!$premiumChannelId) {
            sendMessage($chatId, "❌ Channel ID not configured");
            return;
        }
        
        $resLink = apiRequest('createChatInviteLink', [
            'chat_id' => $premiumChannelId,
            'expire_date' => time() + 600,
            'member_limit' => 1
        ]);
        
        if ($resLink && ($resLink['ok'] ?? false)) {
            $inviteLink = $resLink['result']['invite_link'];
            
            // Send success message
            $msg = "PAYMENT SUCCESSFUL {$saved_amount} HERE IS YOUR PRIVATE CHANNEL LINK and LINK only for one user join:\n\n{$inviteLink}";
            sendMessage($chatId, $msg);
            
            // Update session
            $autoPayments[$userId]['status'] = 'paid';
            $autoPayments[$userId]['claimed'] = true;
            writeJson('auto_payment.json', $autoPayments);
            
            // Add to paid users
            $paidData = readJson('paid.json');
            $usersData = readJson('users.json');
            $targetInfo = $usersData['users'][$userId] ?? [];
            $paidData[$userId] = [
                'user_id' => (int)$userId,
                'username' => $targetInfo['username'] ?? 'Unknown',
                'approved_at' => date("Y-m-d H:i:s"),
                'channel_id' => $premiumChannelId,
                'txn_id' => $track_id
            ];
            writeJson('paid.json', $paidData);
            
            // Edit message to remove buttons
            apiRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []])
            ]);
        } else {
            sendMessage($chatId, "❌ Failed to generate link. Contact Admin.");
        }
        return;
    }

    if ($data === 'send_screenshot') {
        $settings = readJson('settings.json');
        if (!empty($settings['auto_payment'])) {
            // Auto Mode
            $usersData = readJson('users.json');
            $usersData['users'][$userId]['state'] = 'awaiting_txn';
            writeJson('users.json', $usersData);
            
            sendMessage($chatId, "💳 <b>Auto Payment Mode</b>\n\nPlease enter your <b>Transaction ID</b> below:");
        } else {
            // Manual Mode
            sendMessage($chatId, "📸 <b>Please send your payment screenshot now.</b>\n\nYou can also include your UTR or any message in the caption.");
        }
    } elseif ($data === 'back' || $data === 'back_menu') {
        // DEBUG: Log the callback
        error_log("Handling back_menu for user: " . $userId);
        $img = readJson('img.json');
        $cap = readJson('caption.json');
        
        $media = [
            'type' => 'photo',
            'media' => $img['url'] ?? '',
            'caption' => $cap['text'] ?? ''
        ];
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => 'Get Premium', 'callback_data' => 'get_premium']],
                [['text' => 'Demo Channel', 'url' => $config['demo_channel_link']]],
                [['text' => 'Proofs', 'url' => $config['proofs_channel_link']]]
            ]
        ];
        
        editMessageMedia($chatId, $messageId, json_encode($media), json_encode($keyboard));
    }
}

function updateUser($userId, $username, $fullName) {
    global $ADMIN_ID;
    $usersData = readJson('users.json');
    
    // Check if user exists
    if (!isset($usersData['users'][$userId])) {
        // New User
        $now = date("Y-m-d H:i:s");
        $timestamp = time();
        
        $usersData['users'][$userId] = [
            'full_name' => $fullName,
            'username' => $username,
            'user_id' => $userId,
            'join_date' => $now,
            'first_seen' => $timestamp,
            'last_active' => $timestamp,
            'status' => 'active'
        ];
        $usersData['total_users']++;
        writeJson('users.json', $usersData);
        
        // Notify Admin
        if ($ADMIN_ID) {
            $profileLink = $username ? "https://t.me/$username" : "tg://user?id=$userId";
            $msg = "🆕 <b>New User Joined</b> 🚀\n\n" .
                   "👤 <b>Name:</b> $fullName\n" .
                   "🔗 <b>Username:</b> @$username\n" .
                   "🆔 <b>User ID:</b> <code>$userId</code>\n" .
                   "🌍 <b>Profile:</b> <a href='$profileLink'>Open Profile</a>\n\n" .
                   "📊 <b>Total Users:</b> " . $usersData['total_users'];
            sendMessage($ADMIN_ID, $msg);
        }
    } else {
        // Existing User - Update last_active
        $usersData['users'][$userId]['last_active'] = time();
        $usersData['users'][$userId]['status'] = 'active'; // Reset if was blocked
        // We optimize by not writing to disk on every single interaction if not needed, 
        // but requirements say "Update last_active only". Strict adherence implies writing.
        writeJson('users.json', $usersData);
    }
}

function sendStartMessage($chatId, $replyToMessageId = null) {
    global $config, $ADMIN_ID;
    
    $img = readJson('img.json');
    $cap = readJson('caption.json');
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'Get Premium', 'callback_data' => 'get_premium']],
            [['text' => 'Demo Channel', 'url' => $config['demo_channel_link'] ?? '']],
            [['text' => 'Proofs', 'url' => $config['proofs_channel_link'] ?? '']]
        ]
    ];
    
    $photoUrl = $img['url'] ?? '';
    $captionText = $cap['text'] ?? '';
    
    if (empty($photoUrl)) {
        // Fallback if URL is empty
        sendMessage($chatId, empty($captionText) ? "Welcome to Premium Bot!" : $captionText, json_encode($keyboard), $replyToMessageId);
    } else {
        $res = sendPhoto($chatId, $photoUrl, $captionText, json_encode($keyboard), $replyToMessageId);
        if (!$res || (isset($res['ok']) && !$res['ok'])) {
            // Fallback if photo fails (e.g. invalid URL)
            sendMessage($chatId, empty($captionText) ? "Welcome to Premium Bot!" : $captionText, json_encode($keyboard), $replyToMessageId);
            if ($ADMIN_ID) {
                $errorDesc = $res['description'] ?? 'Unknown Error';
                sendMessage($ADMIN_ID, "⚠️ Alert: Failed to send start image.\nURL: $photoUrl\nError: $errorDesc");
            }
        }
    }
}

function approveUserLogic($targetUserId, $adminChatId = null, $callbackId = null, $msgIdToEdit = null, $txnId = null) {
    global $usersData, $config; // Expects to be available or we read it
    if (empty($usersData)) $usersData = readJson('users.json');

    $premiumChannelId = $config['target_channel_id'] ?? null;
    
    if (!$premiumChannelId) {
        if ($callbackId) apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Channel ID not set!', 'show_alert' => true]);
        if ($adminChatId) sendMessage($adminChatId, "❌ <b>Cannot approve user:</b> Channel ID is not configured.");
        return;
    }

    // Check if already premium
    $paidData = readJson('paid.json');
    if (isset($paidData[$targetUserId])) {
        if ($callbackId) apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'User is already Premium!', 'show_alert' => true]);
        if ($adminChatId) sendMessage($adminChatId, "⚠️ User $targetUserId is already Premium.");
        return;
    }

    // STEP 1: Create Invite Link
    $resLink = apiRequest('createChatInviteLink', [
        'chat_id' => $premiumChannelId,
        'member_limit' => 1
    ]);

    if ($resLink && ($resLink['ok'] ?? false)) {
        $inviteLink = $resLink['result']['invite_link'];

        // STEP 2: Save to paid.json
        $targetInfo = $usersData['users'][$targetUserId] ?? [];
        $paidData[$targetUserId] = [
            'user_id' => (int)$targetUserId,
            'username' => $targetInfo['username'] ?? 'Unknown',
            'approved_at' => date("Y-m-d H:i:s"),
            'channel_id' => $premiumChannelId,
            'txn_id' => $txnId // Save TXN ID if auto
        ];
        writeJson('paid.json', $paidData);

        // STEP 3: Notify User
        $userMsg = "✅ <b>Payment Approved!</b>\n\n" .
                   "🎉 Welcome to the Premium Channel\n\n" .
                   "🔗 <b>Your private invite link:</b>\n$inviteLink\n\n" .
                   "⚠️ This link is valid for ONE USER only.\nDo not share it.";
        sendMessage($targetUserId, $userMsg);

        if ($callbackId) apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Approved!']);
        
        $adminMsg = "✅ Approved payment for User ID: $targetUserId\nLink created: $inviteLink";
        if ($txnId) $adminMsg .= "\nAuto-verified TXN: $txnId";
        
        if ($adminChatId) sendMessage($adminChatId, $adminMsg);
    } else {
        $err = $resLink['description'] ?? 'Unknown API Error';
        if ($adminChatId) sendMessage($adminChatId, "❌ <b>Failed to create invite link!</b>\nError: $err");
        if ($callbackId) apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Link creation failed!', 'show_alert' => true]);
    }

    // Remove buttons if manual approval
    if ($msgIdToEdit && $adminChatId) {
        apiRequest('editMessageReplyMarkup', [
            'chat_id' => $adminChatId,
            'message_id' => $msgIdToEdit,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ]);
    }
}
?>
