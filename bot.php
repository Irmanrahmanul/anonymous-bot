<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. CONFIGURATION ---
$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";
$STATE_FILE = "/tmp/state.json"; 

// AMBIL PRIVATE URL DARI RAILWAY
$db_url = getenv('MYSQL_URL') ?: getenv('MYSQL_PUBLIC_URL');

try {
    if (!$db_url) {
        throw new Exception("URL Database Kosong");
    }

    // Membersihkan URL agar bisa dibaca PDO
    $url = parse_url($db_id);
    $host = $url['host'];
    $port = $url['port'];
    $user = $url['user'];
    $pass = $url['pass'];
    $db   = ltrim($url['path'], '/');

    $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Ambil User ID
    $update = json_decode(file_get_contents("php://input"), true);
    $user_id = $update["message"]["from"]["id"] ?? null;

    if ($user_id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (user_id) VALUES (?)");
        $stmt->execute([$user_id]);
    }

    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    // Tampilkan pesan error spesifik agar kita tahu rusaknya di mana
    $total_users = "Error: " . $e->getMessage(); 
}// --- 3. LOAD STATE ---
if (!file_exists($STATE_FILE)) {
    file_put_contents($STATE_FILE, json_encode(["waiting" => null, "pairs" => []]));
}
$state = json_decode(file_get_contents($STATE_FILE), true);

// --- 4. INPUT DARI TELEGRAM ---
$update = json_decode(file_get_contents("php://input"), true);
if (!isset($update["message"])) exit;

$message = $update["message"];
$user_id = $message["from"]["id"];
$text = trim($message["text"] ?? "");

// Simpan user & hitung total
if ($pdo) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (user_id) VALUES (?)");
        $stmt->execute([$user_id]);
        $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (Exception $e) {
        $total_users = "Error DB";
    }
} else {
    $total_users = "Offline";
}

// --- 5. FUNGSI HELPER ---
function sendMessage($chat_id, $text) {
    global $API;
    $data = ["chat_id" => $chat_id, "text" => $text, "parse_mode" => "Markdown"];
    file_get_contents($API . "sendMessage?" . http_build_query($data));
}

function forwardMedia($method, $chat_id, $file_id, $caption = "") {
    global $API;
    $key = str_replace("send", "", strtolower($method));
    if ($key == "videonote") $key = "video_note";
    
    $data = ["chat_id" => $chat_id, $key => $file_id];
    if ($caption) $data["caption"] = $caption;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $API . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// --- 6. LOGIKA BOT ---
if ($text === "/start") {
    sendMessage($user_id, "🔒 **Anonymous Chat**\n\n /find - Cari teman\n/stop - Berhenti");
} 
elseif ($text === "/find") {
    if (isset($state["pairs"][$user_id])) {
        sendMessage($user_id, "⚠️ Kamu sudah terhubung.");
    } elseif ($state["waiting"] === $user_id) {
        sendMessage($user_id, "⏳ Masih mencari pasangan...");
    } elseif ($state["waiting"] === null) {
        $state["waiting"] = $user_id;
        sendMessage($user_id, "⏳ Mencari pasangan...");
    } else {
        $partner = $state["waiting"];
        $state["waiting"] = null;
        $state["pairs"][$user_id] = $partner;
        $state["pairs"][$partner] = $user_id;
        sendMessage($user_id, "✅ Terhubung! Silakan chat.");
        sendMessage($partner, "✅ Terhubung! Silakan chat.");
    }
}
elseif ($text === "/stop") {
    if (isset($state["pairs"][$user_id])) {
        $partner = $state["pairs"][$user_id];
        unset($state["pairs"][$user_id], $state["pairs"][$partner]);
        sendMessage($partner, "❌ Pasangan telah berhenti.");
    }
    if ($state["waiting"] === $user_id) $state["waiting"] = null;
    sendMessage($user_id, "🛑 Kamu telah keluar.");
}
// --- 7. RELAY PESAN & MEDIA LENGKAP ---
elseif (isset($state["pairs"][$user_id])) {
    $target = $state["pairs"][$user_id];
    $caption = $message["caption"] ?? "";

    if (isset($message["text"])) {
        sendMessage($target, $message["text"]);
    } elseif (isset($message["photo"])) {
        forwardMedia("sendPhoto", $target, end($message["photo"])["file_id"], $caption);
    } elseif (isset($message["sticker"])) {
        forwardMedia("sendSticker", $target, $message["sticker"]["file_id"]);
    } elseif (isset($message["voice"])) {
        forwardMedia("sendVoice", $target, $message["voice"]["file_id"]);
    } elseif (isset($message["video"])) {
        forwardMedia("sendVideo", $target, $message["video"]["file_id"], $caption);
    } elseif (isset($message["video_note"])) {
        forwardMedia("sendVideoNote", $target, $message["video_note"]["file_id"]);
    } elseif (isset($message["audio"])) {
        forwardMedia("sendAudio", $target, $message["audio"]["file_id"], $caption);
    } elseif (isset($message["document"])) {
        forwardMedia("sendDocument", $target, $message["document"]["file_id"], $caption);
    }
}

file_put_contents($STATE_FILE, json_encode($state));
