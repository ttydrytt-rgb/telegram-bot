<?php
// Karn/webapp/index.php - Admin Dashboard
// Simple, single-file dashboard for managing the bot.

session_start();

function triggerBackgroundProcess($script){
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $url .= "://".$_SERVER['HTTP_HOST'];
    $url .= rtrim(dirname($_SERVER['REQUEST_URI']), '/').'/'.$script;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_exec($ch);
    curl_close($ch);

    return true;
}

// ================================================
// AUTHENTICATION & SETUP
// ================================================

// Load Config relative to webapp/
$baseDir = __DIR__ . '/..';
$configFile = $baseDir . '/config.json';

if (!file_exists($configFile)) {
    die("Config file missing. Please set up the bot first.");
}

$config = json_decode(file_get_contents($configFile), true);
$ADMIN_ID = $config['admin_userid'] ?? 0;

// Simple "Password" check using Admin ID (User enters Admin ID to login)
// In a real Telegram WebApp, we would validate initData, but for this standalone request:
// We will rely on a simple session login or if accessed via Telegram WebApp with data.

$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (isset($_POST['login'])) {
    $input_id = $_POST['admin_id'] ?? '';
    if ($input_id == $ADMIN_ID) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid Admin ID";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Actions Handling
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // File Upload Handling
    if (isset($_FILES['bc_media_file']) && $_FILES['bc_media_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['bc_media_file']['tmp_name'];
        $fileName = $_FILES['bc_media_file']['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'mp4', 'mov', 'avi'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $mediaDir = $baseDir . '/media/';
            if (!is_dir($mediaDir)) mkdir($mediaDir, 0777, true);
            
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $destPath = $mediaDir . $newFileName;
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $basePath = dirname($_SERVER['SCRIPT_NAME']);
                $basePath = rtrim(dirname($basePath), '/');
                $_POST['bc_media_url'] = "$protocol://$host$basePath/media/$newFileName";
            }
        }
    }

    // Update Settings
    if (isset($_POST['update_settings'])) {
        // QR Code
        $qrData = ['url' => $_POST['qr_url'] ?? ''];
        file_put_contents($baseDir . '/qr.json', json_encode($qrData, JSON_PRETTY_PRINT));
        
        // Link (Demo Channel? - Or maybe a specific link setting)
        // User asked for "qr code image + link" - likely the demo/proof channel or just generic link
        $config['demo_channel_link'] = $_POST['demo_link'] ?? $config['demo_channel_link'];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        
        $message = "✅ Settings updated!";
    }

    // Stop Broadcast
    if (isset($_POST['stop_broadcast'])) {
        file_put_contents($baseDir . '/stop_broadcast.flag', '1');
        $message = "🛑 Stop signal sent to broadcast runner.";
    }

    // Trigger Broadcast
    if (isset($_POST['start_broadcast'])) {
        $type = $_POST['bc_type'] ?? 'text';
        $text = $_POST['bc_text'] ?? '';
        $mediaUrl = $_POST['bc_media_url'] ?? ''; // Only support URL for now as per bot architecture
        
        $data = [
            'type' => $type,
            'content' => ($type === 'text') ? $text : $mediaUrl,
            'caption' => ($type !== 'text') ? $text : '',
            'media_group' => [] // Todo: Parse multiple URLs for album if needed
        ];
        
        // Save broadcast data
        file_put_contents($baseDir . '/broadcast_data.json', json_encode($data));
        
        // Launch runner via Trigger Helper (NO EXEC!)
        if (triggerBackgroundProcess('broadcast_runner.php')) {
            $message = "🚀 Broadcast triggered in background!";
        } else {
            $message = "❌ Trigger Failed! Could not contact runner script.";
        }
    }
}

// Read Current Data
$qr = json_decode(file_get_contents($baseDir . '/qr.json') ?: '[]', true);
$usersData = json_decode(file_get_contents($baseDir . '/users.json') ?: '{"users":[]}', true);
$users = $usersData['users'] ?? [];
$totalUsers = count($users);

// Success Users (Not Blocked)
$activeUsers = 0;
foreach($users as $u) {
    if (($u['status'] ?? '') !== 'blocked') $activeUsers++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Telegram WebApp Script -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script>
        // Auto-login if WebApp provides correct User ID
        window.Telegram.WebApp.ready();
        const initData = window.Telegram.WebApp.initDataUnsafe;
        if (initData && initData.user && initData.user.id == <?php echo $ADMIN_ID ?: 0; ?>) {
            // Check if already logged in via session
            <?php if (!$isLoggedIn): ?>
                // If not logged in, auto-submit the admin ID
                const form = document.createElement('form');
                form.method = 'POST';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'admin_id';
                input.value = initData.user.id;
                const loginBtn = document.createElement('input');
                loginBtn.type = 'hidden';
                loginBtn.name = 'login';
                loginBtn.value = '1';
                form.appendChild(input);
                form.appendChild(loginBtn);
                document.body.appendChild(form);
                form.submit();
            <?php endif; ?>
        } else if (initData && initData.user) {
            // If user is not admin, show error and close webapp
            alert("Access Denied: You are not an administrator.");
            window.Telegram.WebApp.close();
        }
    </script>
</head>
<body class="bg-gray-100 text-gray-800 font-sans">

<?php if (!$isLoggedIn): ?>
    <div class="flex items-center justify-center h-screen px-4">
        <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
            <h1 class="text-2xl font-bold mb-6 text-center text-blue-600">Admin Login</h1>
            <?php if(isset($error)) echo "<p class='text-red-500 mb-4 text-center'>$error</p>"; ?>
            <form method="POST">
                <input type="password" name="admin_id" placeholder="Enter Admin ID" class="w-full p-3 border rounded mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" name="login" class="w-full bg-blue-600 text-white p-3 rounded font-bold hover:bg-blue-700 transition">Login</button>
            </form>
        </div>
    </div>
<?php else: ?>

    <div class="max-w-xl mx-auto min-h-screen bg-white shadow-xl relative pb-20">
        
        <!-- Header -->
        <div class="bg-blue-600 text-white p-4 sticky top-0 z-10 flex justify-between items-center shadow-md">
            <h1 class="text-xl font-bold">Admin Panel</h1>
            <a href="?logout=1" class="text-sm bg-blue-800 px-3 py-1 rounded">Logout</a>
        </div>

        <!-- Notification -->
        <?php if($message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 m-4" role="alert">
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 gap-4 p-4">
            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 text-center">
                <h3 class="text-gray-500 text-sm font-semibold">Total Users</h3>
                <p class="text-3xl font-bold text-blue-700"><?php echo number_format($totalUsers); ?></p>
            </div>
            <div class="bg-green-50 p-4 rounded-lg border border-green-200 text-center">
                <h3 class="text-gray-500 text-sm font-semibold">Active Users</h3>
                <p class="text-3xl font-bold text-green-700"><?php echo number_format($activeUsers); ?></p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="px-4">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button onclick="switchTab('settings')" id="tab-settings" class="border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Settings</button>
                    <button onclick="switchTab('qr')" id="tab-qr" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">QR Admin</button>
                    <button onclick="switchTab('broadcast')" id="tab-broadcast" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Broadcast</button>
                    <button onclick="switchTab('users')" id="tab-users" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">User List</button>
                </nav>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="content-settings" class="p-4 space-y-6">
            <form method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">QR Code Image URL</label>
                        <input type="text" name="qr_url" value="<?php echo htmlspecialchars($qr['url'] ?? ''); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Link (Demo/Main)</label>
                        <input type="text" name="demo_link" value="<?php echo htmlspecialchars($config['demo_channel_link'] ?? ''); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <button type="submit" name="update_settings" class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Save Settings</button>
                </div>
            </form>
        </div>

        <!-- QR Admin Tab -->
        <div id="content-qr" class="hidden">
            <iframe src="qr.php" class="w-full border-0" style="height: 80vh;"></iframe>
        </div>

        <!-- Broadcast Tab -->
        <div id="content-broadcast" class="hidden p-4 space-y-6">
            <div class="bg-yellow-50 p-4 rounded border border-yellow-200 text-sm text-yellow-800">
                ⚠️ Sending messages to many users takes time. Use "Stop Broadcast" if needed.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Broadcast Type</label>
                        <select name="bc_type" id="bc_type" onchange="toggleMediaFields()" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm">
                            <option value="text">Text Only</option>
                            <option value="photo">Image + Caption</option>
                            <option value="video">Video + Caption</option>
                        </select>
                    </div>

                    <div id="media-field" class="hidden space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Select File from Device</label>
                            <input type="file" name="bc_media_file" id="bc_media_file" accept="image/*,video/*" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm bg-gray-50">
                            <p class="text-xs text-gray-500 mt-1">OR enter direct URL below</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Media URL (Optional if file selected)</label>
                            <input type="text" name="bc_media_url" placeholder="https://..." class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Message / Caption</label>
                        <textarea name="bc_text" rows="4" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm" placeholder="Enter your message..."></textarea>
                    </div>

                    <div class="flex space-x-2">
                        <button type="submit" name="start_broadcast" class="flex-1 bg-green-600 text-white p-2 rounded hover:bg-green-700">🚀 Start Broadcast</button>
                        <button type="submit" name="stop_broadcast" class="flex-1 bg-red-600 text-white p-2 rounded hover:bg-red-700">🛑 Stop</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Users Tab -->
        <div id="content-users" class="hidden p-4">
            <h3 class="font-bold mb-4">Total Users List (Last 50)</h3>
            <div class="bg-gray-50 rounded border h-96 overflow-y-auto p-2 text-sm font-mono">
                <?php 
                $count = 0;
                foreach(array_reverse($users, true) as $uid => $u) {
                    $status = ($u['status'] ?? '') == 'blocked' ? '🔴' : '🟢';
                    $name = htmlspecialchars($u['first_name'] ?? 'User');
                    echo "<div class='border-b py-1'>$status <b>$uid</b> - $name</div>";
                    $count++;
                    if($count >= 50) break;
                }
                ?>
            </div>
            <p class="text-xs text-gray-500 mt-2 text-center">Showing last 50 users to prevent lag.</p>
        </div>

    </div>

    <script>
        function switchTab(tabName) {
            // Hide all content
            ['settings', 'qr', 'broadcast', 'users'].forEach(t => {
                document.getElementById('content-' + t).classList.add('hidden');
                document.getElementById('tab-' + t).classList.remove('border-blue-500', 'text-blue-600');
                document.getElementById('tab-' + t).classList.add('border-transparent', 'text-gray-500');
            });

            // Show selected
            document.getElementById('content-' + tabName).classList.remove('hidden');
            document.getElementById('tab-' + tabName).classList.add('border-blue-500', 'text-blue-600');
            document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
        }

        function toggleMediaFields() {
            const type = document.getElementById('bc_type').value;
            const field = document.getElementById('media-field');
            if (type === 'text') {
                field.classList.add('hidden');
            } else {
                field.classList.remove('hidden');
            }
        }
    </script>
<?php endif; ?>

</body>
</html>
