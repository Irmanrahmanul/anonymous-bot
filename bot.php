<?php
// --- 1. SETTING ERROR REPORTING ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 2. CONFIGURATION & DATABASE ---
$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";
$STATE_FILE = "/tmp/state.json"; 

// Database Variables (Tanpa Garis Bawah sesuai Railway kamu)
$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

// Koneksi Database
$conn = new mysqli($host, $user, $pass, $db, $port);

// Cek Koneksi & Buat Tabel Otomatis
if (!$conn->connect_error) {
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// --- 3. LOAD STATE (Untuk Anonymous Chat) ---
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

// Simpan user ke database & hitung total
if (!$conn->connect_error) {
    $conn->query("INSERT IGNORE INTO users (user_id) VALUES ('$user_id')");
    $res = $conn->query("SELECT COUNT(*) as total FROM users");
    $row = $res->fetch_assoc();
    $total_users = number_format($row['total'], 0, ',', '.');
} else {
    $total_users = "0";
}

// --- 5. FUNGSI HELPER ---
function sendMessage($chat_id, $text) {
    global $API;
    $data = ["chat_id" => $chat_id, "text" => $text, "parse_mode" => "Markdown"];
    file_get_contents($API . "sendMessage?" . http_build_query($data));
}

function forwardMedia($method, $chat_id, $file_id, $caption = "") {
    global $API;
    $data = ["chat_id" => $chat_id, array_keys($file_id)[0] => array_values($file_id)[0]];
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
    sendMessage($user_id, "🔒 **Anonymous Chat**\n\n👥 `$total_users` monthly users\n\n/find - Cari teman\n/stop - Berhenti");
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
// --- 7. RELAY PESAN & MEDIA ---
elseif (isset($state["pairs"][$user_id])) {
    $target = $state["pairs"][$user_id];
    $caption = $message["caption"] ?? "";

    if (isset($message["text"])) {
        sendMessage($target, $message["text"]);
    } elseif (isset($message["voice"])) {
        forwardMedia("sendVoice", $target, ["voice" => $message["voice"]["file_id"]]);
    } elseif (isset($message["video"])) {
        forwardMedia("sendVideo", $target, ["video" => $message["video"]["file_id"]], $caption);
    } elseif (isset($message["photo"])) {
        $photo = end($message["photo"]); 
        forwardMedia("sendPhoto", $target, ["photo" => $photo["file_id"]], $caption);
    } elseif (isset($message["sticker"])) {
        forwardMedia("sendSticker", $target, ["sticker" => $message["sticker"]["file_id"]]);
    } elseif (isset($message["audio"])) {
        forwardMedia("sendAudio", $target, ["audio" => $message["audio"]["file_id"]], $caption);
    } elseif (isset($message["video_note"])) {
        forwardMedia("sendVideoNote", $target, ["video_note" => $message["video_note"]["file_id"]]);
    } elseif (isset($message["document"])) {
        forwardMedia("sendDocument", $target, ["document" => $message["document"]["file_id"]], $caption);
    }
}

// Simpan status chat
file_put_contents($STATE_FILE, json_encode($state));
