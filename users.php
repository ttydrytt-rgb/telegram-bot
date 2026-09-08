<?php
error_reporting(0);
$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
$uid = $_GET['uid'] ?? '';

if ($uid != $config['admin_userid'] && $uid != $config['developer_userid']) {
    die('<h1 style="color:red;text-align:center;margin-top:20%;">⛔ ACCESS DENIED</h1>');
}

$usersData = json_decode(file_get_contents(__DIR__ . '/../users.json'), true);
$users = $usersData['users'] ?? [];
$totalUsers = count($users);

$botToken = $config['bot_token'] ?? '';
$botName = 'ADMIN';
if ($botToken) {
    $response = @file_get_contents("https://api.telegram.org/bot$botToken/getMe");
    if ($response) {
        $botData = json_decode($response, true);
        if (isset($botData['result']['first_name'])) {
            $botName = $botData['result']['first_name'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users List</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            --bg-color: #0f0f13;
            --card-bg: rgba(255, 255, 255, 0.05);
            --text-color: #ffffff;
            --accent-color: #00ff88;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            overflow-x: hidden;
        }
        /* Loading Screen */
        #loader {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: #000;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transition: opacity 0.5s;
        }
        .bar-container {
            width: 80%;
            height: 4px;
            background: #333;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 20px;
        }
        .bar {
            height: 100%;
            width: 0%;
            background: var(--accent-color);
            transition: width 2s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .logo-text {
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 2px;
        }

        /* Content */
        #content {
            opacity: 0;
            transition: opacity 1s;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
        }
        .back-btn {
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
            text-decoration: none;
            color: white;
        }
        .user-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .user-info {
            display: flex;
            flex-direction: column;
        }
        .username {
            font-weight: 600;
            font-size: 16px;
        }
        .userid {
            font-size: 12px;
            color: #aaa;
        }
    </style>
</head>
<body>

    <!-- Loading Screen -->
    <div id="loader">
        <div class="logo-text"><?php echo htmlspecialchars($botName); ?></div>
        <div class="bar-container">
            <div class="bar" id="loadBar"></div>
        </div>
    </div>

    <!-- Users Content -->
    <div id="content">
        <div class="header">
            <div style="font-weight:600">Users (<?php echo $totalUsers; ?>)</div>
        </div>

        <div id="user-list">
            <?php if (empty($users)): ?>
                <div style="text-align:center; margin-top: 50px; color: #aaa;">No users found</div>
            <?php else: ?>
                <?php foreach ($users as $id => $user): ?>
                <div class="user-card">
                    <div class="user-info">
                        <span class="username"><?php echo htmlspecialchars($user['username'] ?? 'User'); ?></span>
                        <span class="userid">ID: <?php echo htmlspecialchars($id); ?></span>
                    </div>
                    <div style="color: var(--accent-color); font-size: 12px;">Active</div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const tg = window.Telegram.WebApp;
        tg.ready();
        tg.expand();

        // Use the default back button of Telegram
        tg.BackButton.show();
        tg.BackButton.onClick(() => {
            window.location.href = 'dashboard.php?uid=<?php echo $uid; ?>';
        });

        // Apply theme colors
        if (tg.themeParams) {
            document.documentElement.style.setProperty('--bg-color', tg.themeParams.bg_color || '#0f0f13');
            document.documentElement.style.setProperty('--text-color', tg.themeParams.text_color || '#ffffff');
            document.documentElement.style.setProperty('--accent-color', tg.themeParams.button_color || '#00ff88');
            document.documentElement.style.setProperty('--card-bg', tg.themeParams.secondary_bg_color || 'rgba(255, 255, 255, 0.05)');
        }

        window.onload = function() {
            const bar = document.getElementById('loadBar');
            bar.style.width = '100%';
            
            setTimeout(() => {
                document.getElementById('loader').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('loader').style.display = 'none';
                    document.getElementById('content').style.opacity = '1';
                }, 500);
            }, 2000);
        };
    </script>
</body>
</html>
