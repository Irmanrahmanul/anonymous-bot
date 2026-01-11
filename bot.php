<?php
// --- 1. KONEKSI DATABASE & AUTO-CREATE TABLE ---
$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

$conn = new mysqli($host, $user, $pass, $db, $port);

// Buat tabel users otomatis jika belum ada (Solusi Error 1067)
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// --- 2. KONFIGURASI BOT ---
$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";
$STATE_FILE = "/tmp/state.json"; 

if (!file_exists($STATE_FILE)) {
    file_put_contents($STATE_FILE, json_encode(["waiting" => null, "pairs" => []]));
}

$update = json_decode(file_get_contents("php://input"), true);
if (!isset($update["message"])) exit;

$message = $update["message"];
$user_id = $message["from"]["id"];
$text = trim($message["text"] ?? "");
$state = json_decode(file_get_contents($STATE_FILE), true);

// --- 3. LOGIKA STATISTIK PENGGUNA ---
// Masukkan user ke database (UNIQUE akan mencegah duplikat)
$conn->query("INSERT IGNORE INTO users (user_id) VALUES ('$user_id')");

// Ambil total user untuk ditampilkan di /start
$res = $conn->query("SELECT COUNT(*) as total FROM users");
$row = $res->fetch_assoc();
$total_users = number_format($row['total'], 0, ',', '.');

// --- 4. FUNGSI PENGIRIMAN ---
function sendMessage($chat_id, $text) {
    global $API;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $API . "sendMessage");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "chat_id" => $chat_id, 
        "text" => $text,
        "parse_mode" => "Markdown"
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

// --- 5. LOGIKA UTAMA BOT ---
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
// --- 6. BAGIAN RELAY (Penerusan Pesan & Media) ---
elseif (isset($state["pairs"][$user_id])) {
    $target = $state["pairs"][$user_id];
    $caption = $message["caption"] ?? "";

    if (isset($message["text"])) {
        sendMessage($target, $message["text"]);
    } elseif (isset($message["voice"])) {
        forwardMedia("sendVoice", $target, ["voice" => $message["voice"]["file_id"]]);
    } elseif (isset($message["video"])) {
        forwardMedia("sendVideo", $target, ["video" => $message["video"]["file_id"]], $caption);
    } elseif (isset($message["audio"])) {
        forwardMedia("sendAudio", $target, ["audio" => $message["audio"]["file_id"]], $caption);
    } elseif (isset($message["photo"])) {
        $photo = end($message["photo"]); 
        forwardMedia("sendPhoto", $target, ["photo" => $photo["file_id"]], $caption);
    } elseif (isset($message["sticker"])) {
        forwardMedia("sendSticker", $target, ["sticker" => $message["sticker"]["file_id"]]);
    } elseif (isset($message["video_note"])) {
        forwardMedia("sendVideoNote", $target, ["video_note" => $message["video_note"]["file_id"]]);
    } elseif (isset($message["document"])) {
        forwardMedia("sendDocument", $target, ["document" => $message["document"]["file_id"]], $caption);
    } elseif (isset($message["animation"])) {
        forwardMedia("sendAnimation", $target, ["animation" => $message["animation"]["file_id"]], $caption);
    }
}

// Simpan status chat sementara
file_put_contents($STATE_FILE, json_encode($state));
