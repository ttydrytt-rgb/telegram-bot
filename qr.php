<?php
error_reporting(0);
$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
$uid = $_GET['uid'] ?? '';

if ($uid != $config['admin_userid'] && $uid != $config['developer_userid']) {
    die('<h1 style="color:red;text-align:center;margin-top:20%;">⛔ ACCESS DENIED</h1>');
}

$qrFile = __DIR__ . '/../qr.json';
$qrData = json_decode(file_get_contents($qrFile), true);
$currentQr = $qrData['url'] ?? '';

$captionFile = __DIR__ . '/../caption2.json';
$captionData = json_decode(file_get_contents($captionFile), true);
$currentCaption = $captionData['text'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newUrl = $_POST['qr_url'] ?? '';
    $newCaption = $_POST['caption_text'] ?? '';
    
    if (!empty($newUrl)) {
        $qrData['url'] = $newUrl;
        file_put_contents($qrFile, json_encode($qrData, JSON_PRETTY_PRINT));
        $currentQr = $newUrl;
    }

    if (isset($_POST['caption_text'])) {
        $captionData['text'] = $newCaption;
        file_put_contents($captionFile, json_encode($captionData, JSON_PRETTY_PRINT));
        $currentCaption = $newCaption;
    }
    
    $msg = "✅ Updated Successfully!";
}

// Handle AJAX Upload to Catbox
if (isset($_GET['action']) && $_GET['action'] === 'upload') {
    // Helper function for recursive delete
    if (!function_exists('deleteDir')) {
        function deleteDir($dirPath) {
            if (!is_dir($dirPath)) return;
            $files = glob($dirPath . '*', GLOB_MARK);
            foreach ($files as $file) {
                if (is_dir($file)) deleteDir($file);
                else unlink($file);
            }
            rmdir($dirPath);
        }
    }

    if (empty($_FILES['file'])) {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded. Check post_max_size.']);
        exit;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        $message = $errors[$file['error']] ?? 'Unknown upload error.';
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }

    // Auto create temp folder
    $tmpDir = __DIR__ . '/../temp/';
    if(!is_dir($tmpDir)){ mkdir($tmpDir,0777,true); }

    // Move uploaded file into temp folder first
    $tempPath = $tmpDir . basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file to temp directory.']);
        exit;
    }

    header('Content-Type: application/json');

    $ch = curl_init('https://catbox.moe/user/api.php');

    $postData = [
    'reqtype'=>'fileupload',
    'fileToUpload'=>new CURLFile($tempPath,$file['type'],$file['name'])
    ];

    curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$postData,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
    CURLOPT_CONNECTTIMEOUT=>20,
    CURLOPT_TIMEOUT=>60,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_SSL_VERIFYPEER=>false,
    CURLOPT_SSL_VERIFYHOST=>false,
    CURLOPT_USERAGENT=>'Mozilla/5.0'
    ]);

    $response=curl_exec($ch);

    if (curl_errno($ch)) {
        echo json_encode(['status' => 'error', 'message' => 'Curl error: ' . curl_error($ch)]);
        curl_close($ch);
        // Cleanup temp folder
        deleteDir($tmpDir);
        exit;
    }
    curl_close($ch);

    if (!function_exists('deleteDir')) {
        function deleteDir($dirPath) {
            if (!is_dir($dirPath)) return;
            $files = glob($dirPath . '*', GLOB_MARK);
            foreach ($files as $file) {
                if (is_dir($file)) deleteDir($file);
                else unlink($file);
            }
            rmdir($dirPath);
        }
    }
    deleteDir($tmpDir);

    if ($response && strpos($response, 'http') === 0) {
        echo json_encode(['status' => 'success', 'url' => trim($response)]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Catbox error: ' . $response]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage QR & Caption</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            --bg-color: var(--tg-theme-bg-color, #0f0f13);
            --card-bg: var(--tg-theme-secondary-bg-color, rgba(255, 255, 255, 0.05));
            --text-color: var(--tg-theme-text-color, #ffffff);
            --accent-color: var(--tg-theme-link-color, #00ff88);
            --hint-color: var(--tg-theme-hint-color, #aaa);
            --button-text-color: var(--tg-theme-button-text-color, #000);
            --button-color: var(--tg-theme-button-color, #00ff88);
        }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 90%;
            max-width: 500px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-top: 20px;
        }
        input[type="text"], textarea {
            width: 90%;
            padding: 12px;
            margin: 10px 0;
            background: var(--bg-color);
            border: 1px solid var(--hint-color);
            color: var(--text-color);
            border-radius: 8px;
            outline: none;
        }
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        button {
            background: var(--button-color);
            color: var(--button-text-color);
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            margin-top: 10px;
            width: 100%;
        }
        button:active {
            transform: scale(0.95);
        }
        .qr-preview-container {
            position: relative;
            margin-bottom: 20px;
        }
        img {
            max-width: 100%;
            border-radius: 10px;
            border: 2px solid var(--accent-color);
        }
        .drop-zone {
            width: 100%;
            height: 150px;
            border: 2px dashed var(--hint-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            margin-bottom: 20px;
            cursor: pointer;
            transition: border-color 0.3s;
            position: relative;
        }
        .drop-zone.dragover {
            border-color: var(--accent-color);
            background: rgba(0, 255, 136, 0.05);
        }
        .drop-zone p {
            margin: 5px 0;
            color: var(--hint-color);
            font-size: 14px;
        }
        .success {
            color: var(--accent-color);
            margin-bottom: 10px;
        }
        label {
            display: block;
            text-align: left;
            margin-left: 5%;
            font-weight: 600;
            margin-top: 10px;
            color: var(--accent-color);
        }
        #upload-status {
            font-size: 12px;
            margin-top: 5px;
        }
        /* Spinner */
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.1);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border-left-color: var(--accent-color);
            animation: spin 1s linear infinite;
            display: none;
            margin: 10px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Progress Bar */
        .progress-container {
            width: 90%;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 10px auto;
            display: none;
            overflow: hidden;
        }
        .progress-bar {
            width: 0%;
            height: 10px;
            background-color: var(--accent-color);
            transition: width 0.3s ease;
        }
        .progress-text {
            font-size: 12px;
            color: var(--hint-color);
            margin-top: 5px;
            display: none;
        }

        @keyframes pop{
        from{transform:scale(.85);opacity:0;}
        to{transform:scale(1);opacity:1;}
        }
    </style>
</head>
<body>

<?php $uid = $_GET['uid'] ?? ''; ?>

<div style="
display:flex;
align-items:center;
gap:10px;
padding:14px;
font-size:18px;
font-weight:bold;
border-bottom:1px solid #eee;
background:white;
position:sticky;
top:0;
z-index:999;
width:100%;
color: black;
">
<span onclick="window.location.href='dashboard.php?uid=<?=$uid?>'"
style="cursor:pointer;font-size:22px;">⬅</span>
<span>QR Settings</span>
</div>

<div class="container">
    <h2>Manage QR & Caption</h2>
    <?php if (isset($msg)) echo "<p class='success'>$msg</p>"; ?>
    
    <label>Current QR Code:</label>
    <div style="display:flex;align-items:center;gap:20px;margin:20px 0;">

    <img src="<?=htmlspecialchars($currentQr)?>"
    style="
    width:180px;
    border-radius:16px;
    box-shadow:0 14px 40px rgba(0,0,0,0.25);
    ">

    <button onclick="openQrEditor()" style="
    padding:12px 22px;
    border:none;
    border-radius:14px;
    background:#2AABEE;
    color:white;
    font-weight:700;
    cursor:pointer;
    box-shadow:0 8px 25px rgba(0,0,0,0.25);
    ">
    ✏ Edit </button>

    </div>

    <form method="POST" id="main-form">
        <input type="hidden" name="qr_url" id="qr-url-input" value="<?php echo htmlspecialchars($currentQr); ?>">
        
        <label>Update Caption:</label>
        <textarea name="caption_text" placeholder="Enter Caption Text"><?php echo htmlspecialchars($currentCaption); ?></textarea>
        
        <button type="submit">SAVE CHANGES</button>
    </form>
</div>

<div id="qrOverlay" style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,0.6);
z-index:9999;
justify-content:center;
align-items:center;
">

<div style="
background:white;
padding:30px;
border-radius:22px;
width:95%;
max-width:440px;
box-shadow:0 30px 80px rgba(0,0,0,0.4);
animation:pop .25s ease;
color: black;
">

<h3 style="margin-top:0;">Upload New QR</h3>

<!-- PASTE ORIGINAL DRAG DROP HTML HERE -->
    <div class="drop-zone" id="drop-zone" style="background: #f9f9f9; border-color: #ccc;">
        <p>📁 Drag & Drop New QR Here</p>
        <p>or Click to Select File</p>
        <input type="file" id="file-input" style="display: none;" accept="image/*">
        <div class="spinner" id="spinner" style="border-left-color: #2AABEE; border-color: rgba(0,0,0,0.1);"></div>
        <div class="progress-container" id="progress-container" style="background-color: rgba(0,0,0,0.1);">
            <div class="progress-bar" id="progress-bar" style="background-color: #2AABEE;"></div>
        </div>
        <div class="progress-text" id="progress-text">0%</div>
        <div id="upload-status"></div>
    </div>
    
    <!-- Hidden img for preview inside upload logic if needed, though we show current one in main view -->
    <img id="qr-img" style="display: none;" alt="New QR Preview">
    <p id="no-qr" style="display:none;"></p>


<div style="display:flex;gap:12px;margin-top:22px;">

<button onclick="submitQrUpdate()" style="
flex:1;
padding:13px;
background:#00c853;
color:white;
border:none;
border-radius:14px;
font-weight:bold;
cursor:pointer;">
Done </button>

<button onclick="closeQrEditor()" style="
flex:1;
padding:13px;
background:#f1f1f1;
border:none;
border-radius:14px;
cursor:pointer;
color: black;
">
Cancel </button>

</div>

</div>
</div>

<script>
    const tg = window.Telegram.WebApp;
    tg.ready();
    tg.expand();

    const backButton = tg.BackButton;
    backButton.hide();

    function openQrEditor(){
    document.getElementById("qrOverlay").style.display="flex";
    }

    function closeQrEditor(){
    document.getElementById("qrOverlay").style.display="none";
    }

    function submitQrUpdate(){

    // Submit the main form to save URL and Caption
    document.getElementById("main-form").submit();

    setTimeout(()=>{
    showSuccessToast();
    closeQrEditor();
    },700);

    }

    function showSuccessToast(){

    let toast=document.createElement("div");

    toast.innerHTML="✅ QR Code Successfully Updated";

    toast.style.position="fixed";
    toast.style.bottom="30px";
    toast.style.left="50%";
    toast.style.transform="translateX(-50%)";
    toast.style.background="linear-gradient(45deg,#00c853,#69f0ae)";
    toast.style.color="white";
    toast.style.padding="18px 28px";
    toast.style.borderRadius="16px";
    toast.style.fontWeight="bold";
    toast.style.fontSize="17px";
    toast.style.boxShadow="0 18px 60px rgba(0,0,0,0.35)";
    toast.style.zIndex="99999";

    document.body.appendChild(toast);

    setTimeout(()=>toast.remove(),3500);

    }

    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const qrImg = document.getElementById('qr-img');
    const noQrText = document.getElementById('no-qr');
    const qrUrlInput = document.getElementById('qr-url-input');
    const status = document.getElementById('upload-status');
    const spinner = document.getElementById('spinner');
    const progressContainer = document.getElementById('progress-container');
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');

    dropZone.addEventListener('click', (e) => {
        if(e.target !== fileInput) fileInput.click();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) handleUpload(file);
    });

    fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) handleUpload(file);
    });

    function handleUpload(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file.');
            return;
        }

        // Instant Image Preview
        const reader = new FileReader();
        reader.onload = e => {
            qrImg.src = e.target.result;
            // qrImg.style.display = 'block'; // Hidden in overlay
            if (noQrText) noQrText.style.display = 'none';
        };
        reader.readAsDataURL(file);

        status.textContent = '⏳ Uploading to Catbox...';
        status.style.color = 'var(--hint-color)';
        
        // Show spinner and progress
        spinner.style.display = 'block';
        progressContainer.style.display = 'block';
        progressText.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.innerText = '0%';

        const formData = new FormData();
        formData.append('file', file);

        // Get current URL without query params to avoid appending multiple actions
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'upload');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', url.toString(), true);

        // Real smooth progress bar
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                let percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                progressText.innerText = percent + '%';
            }
        };

        xhr.onload = function() {
            spinner.style.display = 'none';
            progressContainer.style.display = 'none';
            progressText.style.display = 'none';

            if (xhr.status === 200) {
                let result;
                try {
                    result = JSON.parse(xhr.responseText);
                } catch (e) {
                    console.error('Server response:', xhr.responseText);
                    status.textContent = '❌ Invalid server response';
                    status.style.color = 'red';
                    return;
                }

                if (result.status === 'success') {
                    const newUrl = result.url + '?t=' + Date.now();
                    qrUrlInput.value = result.url;
                    // Preview is already set by FileReader, but update src to remote URL if needed later
                    // qrImg.src = newUrl; 
                    
                    status.textContent = '✅ QR Uploaded! Click Save below.';
                    status.style.color = 'var(--accent-color)';
                    
                    tg.showPopup({
                        title: '✅ Success',
                        message: 'QR Uploaded Successfully. Click Done to save.',
                        buttons: [{type: 'ok'}]
                    });

                    tg.HapticFeedback.notificationOccurred('success');
                } else {
                    status.textContent = '❌ Upload failed: ' + result.message;
                    status.style.color = 'red';
                    tg.HapticFeedback.notificationOccurred('error');
                }
            } else {
                status.textContent = '❌ Upload failed (HTTP ' + xhr.status + ')';
                status.style.color = 'red';
            }
        };

        xhr.onerror = function() {
            spinner.style.display = 'none';
            progressContainer.style.display = 'none';
            progressText.style.display = 'none';
            status.textContent = '❌ Error uploading file.';
            status.style.color = 'red';
        };

        xhr.send(formData);
    }
</script>

</body>
</html>
