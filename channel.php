<?php
error_reporting(0);
$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
$uid = $_GET['uid'] ?? '';

if ($uid != $config['admin_userid'] && $uid != $config['developer_userid']) {
    die('<h1 style="color:red;text-align:center;margin-top:20%;">⛔ ACCESS DENIED</h1>');
}

// Handle POST actions for Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (isset($_POST['demo_link'])) {
        $config['demo_channel_link'] = trim($_POST['demo_link']);
    }
    if (isset($_POST['proof_link'])) {
        $config['proofs_channel_link'] = trim($_POST['proof_link']);
    }
    if (isset($_POST['target_id'])) {
        $config['target_channel_id'] = trim($_POST['target_id']);
    }
    file_put_contents(__DIR__ . '/../config.json', json_encode($config, JSON_PRETTY_PRINT));
    $msg = "✅ Settings Updated!";
}

$channelFile = __DIR__ . '/../channels.json';
if (!file_exists($channelFile)) {
    file_put_contents($channelFile, json_encode([], JSON_PRETTY_PRINT));
}

$channels = json_decode(file_get_contents($channelFile), true);

// Handle POST actions for Channels
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_channel'])) {
        $link = trim($_POST['channel_link']);
        if (!empty($link)) {
            // Check if exists
            $exists = false;
            foreach ($channels as $c) {
                if ($c['link'] === $link) $exists = true;
            }
            if (!$exists) {
                $channels[] = ['link' => $link, 'added_at' => date('Y-m-d H:i:s')];
                file_put_contents($channelFile, json_encode($channels, JSON_PRETTY_PRINT));
                $msg = "✅ Channel Added!";
            } else {
                $err = "⚠️ Channel already exists.";
            }
        }
    }
    
    if (isset($_POST['remove_channel'])) {
        $index = $_POST['remove_index'];
        if (isset($channels[$index])) {
            array_splice($channels, $index, 1);
            file_put_contents($channelFile, json_encode($channels, JSON_PRETTY_PRINT));
            $msg = "🗑️ Channel Removed!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Channels</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f0f13;
            --card-bg: rgba(255, 255, 255, 0.05);
            --text-color: #ffffff;
            --accent-color: #00ff88;
            --danger-color: #ff4444;
        }
        body {
            margin: 0;
            padding: 20px;
            font-family: 'Poppins', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        .header {
            text-align: center; margin-bottom: 20px;
            position: relative;
        }
        .back-btn {
            position: absolute;
            left: 0;
            top: 5px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 20px;
        }
        .input-group {
            display: flex; gap: 10px; margin-bottom: 20px;
        }
        .settings-group {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 30px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #aaa;
        }
        input {
            flex: 1; padding: 12px;
            background: #25252b; border: 1px solid #444;
            color: white; border-radius: 8px; outline: none;
            width: 100%;
            box-sizing: border-box;
        }
        .input-group input {
            width: auto;
        }
        button {
            padding: 12px 20px; border: none;
            background: var(--accent-color); color: #000;
            font-weight: 600; border-radius: 8px; cursor: pointer;
            width: 100%;
        }
        .input-group button {
            width: auto;
        }
        .channel-list {
            display: flex; flex-direction: column; gap: 10px;
        }
        .channel-card {
            background: var(--card-bg);
            padding: 15px; border-radius: 10px;
            display: flex; justify-content: space-between; align-items: center;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .remove-btn {
            background: var(--danger-color); color: white;
            padding: 8px 15px; font-size: 12px;
            width: auto;
        }
        .msg { text-align: center; margin-bottom: 10px; }
        .success { color: var(--accent-color); }
        .error { color: var(--danger-color); }
        h3 { margin-top: 0; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="dashboard.php?uid=<?php echo $uid; ?>" class="back-btn">←</a>
        <h2>Manage Channels</h2>
    </div>

    <?php if (isset($msg)) echo "<div class='msg success'>$msg</div>"; ?>
    <?php if (isset($err)) echo "<div class='msg error'>$err</div>"; ?>

    <div class="settings-group">
        <h3>Main Channels & ID</h3>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label>Demo Channel Link</label>
                <input type="text" name="demo_link" value="<?php echo htmlspecialchars($config['demo_channel_link'] ?? ''); ?>" placeholder="https://t.me/...">
            </div>
            <div style="margin-bottom: 15px;">
                <label>Proof Channel Link</label>
                <input type="text" name="proof_link" value="<?php echo htmlspecialchars($config['proofs_channel_link'] ?? ''); ?>" placeholder="https://t.me/...">
            </div>
            <div style="margin-bottom: 15px;">
                <label>Target Channel ID (Premium)</label>
                <input type="text" name="target_id" value="<?php echo htmlspecialchars($config['target_channel_id'] ?? '-1003212795397'); ?>" placeholder="-100...">
            </div>
            <button type="submit" name="update_settings">SAVE SETTINGS</button>
        </form>
    </div>

    <h3>Extra Channels List</h3>
    <form method="POST" class="input-group">
        <input type="text" name="channel_link" placeholder="https://t.me/..." required>
        <button type="submit" name="add_channel">ADD</button>
    </form>

    <div class="channel-list">
        <?php foreach ($channels as $index => $c): ?>
            <div class="channel-card">
                <span><?php echo htmlspecialchars($c['link']); ?></span>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="remove_index" value="<?php echo $index; ?>">
                    <button type="submit" name="remove_channel" class="remove-btn">REMOVE</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
