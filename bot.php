<?php
// Tampilkan error jika ada masalah (untuk debugging)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- KONEKSI DATABASE (SESUAI VARIABEL RAILWAY KAMU) ---
$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

$conn = new mysqli($host, $user, $pass, $db, $port);

// Cek jika koneksi gagal
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// --- BUAT TABEL OTOMATIS (WAJIB ADA) ---
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";

$update = json_decode(file_get_contents("php://input"), true);
if (!isset($update["message"])) exit;

$message = $update["message"];
$user_id = $message["from"]["id"];
$text = trim($message["text"] ?? "");

// Simpan user baru ke database
$conn->query("INSERT IGNORE INTO users (user_id) VALUES ('$user_id')");

// Ambil total user
$res = $conn->query("SELECT COUNT(*) as total FROM users");
$row = $res->fetch_assoc();
$total_users = $row['total'] ?? 0;

// --- LOGIKA START ---
if ($text === "/start") {
    $pesan = "🔒 **Anonymous Chat**\n\n";
    $pesan .= "👥 `$total_users` monthly users\n\n";
    $pesan .= "/find - Cari teman\n/stop - Berhenti";
    
    file_get_contents($API . "sendMessage?" . http_build_query([
        "chat_id" => $user_id,
        "text" => $pesan,
        "parse_mode" => "Markdown"
    ]));
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
