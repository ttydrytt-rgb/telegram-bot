<?php
error_reporting(0);
$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
$uid = $_GET['uid'] ?? '';

if ($uid != $config['admin_userid'] && $uid != $config['developer_userid']) {
    die('<h1 style="color:red;text-align:center;margin-top:20%;">⛔ ACCESS DENIED</h1>');
}

$usersData = json_decode(file_get_contents(__DIR__ . '/../users.json'), true);
$totalUsers = $usersData['total_users'] ?? 0;

// Fake growth data for chart
$chartData = [10, 25, 40, 35, 50, 65, $totalUsers]; 

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
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Dashboard */
        #dashboard {
            opacity: 0;
            transition: opacity 1s;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .menu-btn {
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
        }
        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 20px;
        }
        .stat-title {
            font-size: 14px;
            color: #aaa;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 600;
            margin: 10px 0;
        }

        /* Overlay Menu */
        #menu-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .menu-content {
            background: #1a1a1f;
            padding: 30px;
            border-radius: 20px;
            width: 80%;
            max-width: 300px;
            text-align: center;
            border: 1px solid #333;
        }
        .menu-item {
            display: block;
            padding: 15px;
            margin: 10px 0;
            background: #25252b;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            transition: transform 0.2s;
        }
        .menu-item:active {
            transform: scale(0.95);
        }
        .close-btn {
            margin-top: 20px;
            padding: 10px 30px;
            background: #ff4444;
            border: none;
            color: white;
            border-radius: 20px;
            cursor: pointer;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 200px;
            width: 100%;
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

    <!-- Dashboard Content -->
    <div id="dashboard">
        <div class="header">
            <div class="menu-btn" onclick="openMenu()">⋮</div>
            <div style="font-weight:600">Dashboard</div>
            <div style="width:24px"></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">TOTAL USERS</div>
            <div class="stat-value"><?php echo number_format($totalUsers); ?></div>
            <div style="color:var(--accent-color); font-size:12px;">+12% from last week</div>
        </div>

        <div class="stat-card">
            <div class="stat-title">USER GROWTH</div>
            <div class="chart-container">
                <canvas id="userChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Menu Overlay -->
    <div id="menu-overlay">
        <div class="menu-content">
            <h2 style="margin-top:0">Manage</h2>
            <a href="qr.php?uid=<?php echo $uid; ?>" class="menu-item">Manage QR</a>
            <a href="#" onclick="tg.openLink('https://' + window.location.host + '/users.php?uid=<?php echo $uid; ?>'); return false;" class="menu-item">Manage Users</a>
            <a href="channel.php?uid=<?php echo $uid; ?>" class="menu-item">Channels Manage</a>
            <button class="close-btn" onclick="closeMenu()">Close</button>
        </div>
    </div>

    <script>
        // Init Telegram WebApp
        const tg = window.Telegram.WebApp;
        tg.ready();
        tg.expand();
        tg.enableClosingConfirmation();

        // Apply theme colors
        if (tg.themeParams) {
            document.documentElement.style.setProperty('--bg-color', tg.themeParams.bg_color || '#0f0f13');
            document.documentElement.style.setProperty('--text-color', tg.themeParams.text_color || '#ffffff');
            document.documentElement.style.setProperty('--accent-color', tg.themeParams.button_color || '#00ff88');
            document.documentElement.style.setProperty('--card-bg', tg.themeParams.secondary_bg_color || 'rgba(255, 255, 255, 0.05)');
        }

        // Loading Animation
        window.onload = function() {
            const bar = document.getElementById('loadBar');
            bar.style.width = '100%';
            
            setTimeout(() => {
                document.getElementById('loader').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('loader').style.display = 'none';
                    document.getElementById('dashboard').style.opacity = '1';
                }, 500);
            }, 2000);
        };

        // Menu Logic
        const overlay = document.getElementById('menu-overlay');
        function openMenu() {
            overlay.style.display = 'flex';
        }
        function closeMenu() {
            overlay.style.display = 'none';
        }
        
        // Close on outside click
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeMenu();
        });

        // Close on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMenu();
        });

        // Chart
        const ctx = document.getElementById('userChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'New Users',
                    data: <?php echo json_encode($chartData); ?>,
                    borderColor: '#00ff88',
                    backgroundColor: 'rgba(0, 255, 136, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeOutQuart'
                }
            }
        });
    </script>
</body>
</html>
