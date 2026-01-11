<?php
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

// Fungsi Kirim Pesan (Teks & Media)
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

function sendMessage($chat_id, $text) {
    global $API;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $API . "sendMessage");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(["chat_id" => $chat_id, "text" => $text]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

// --- LOGIKA UTAMA ---
if ($text === "/start") {
    sendMessage($user_id, "🔒 Anonymous Chat\n/find - Cari teman\n/stop - Berhenti");
} 
elseif ($text === "/find") {
    if (isset($state["pairs"][$user_id])) {
        sendMessage($user_id, "⚠️ Kamu sudah terhubung.");
    } elseif ($state["waiting"] === $user_id) {
        sendMessage($user_id, "⏳ Masih mencari...");
    } elseif ($state["waiting"] === null) {
        $state["waiting"] = $user_id;
        sendMessage($user_id, "⏳ Mencari pasangan...");
    } else {
        $partner = $state["waiting"];
        $state["waiting"] = null;
        $state["pairs"][$user_id] = $partner;
        $state["pairs"][$partner] = $user_id;
        sendMessage($user_id, "✅ Terhubung!");
        sendMessage($partner, "✅ Terhubung!");
    }
}
elseif ($text === "/stop") {
    if (isset($state["pairs"][$user_id])) {
        $partner = $state["pairs"][$user_id];
        unset($state["pairs"][$user_id], $state["pairs"][$partner]);
        sendMessage($partner, "❌ Pasangan berhenti.");
    }
    if ($state["waiting"] === $user_id) $state["waiting"] = null;
    sendMessage($user_id, "🛑 Selesai.");
}
// --- BAGIAN RELAY (Penerusan Media) ---
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
        $photo = end($message["photo"]); // Ambil ukuran foto terbesar
        forwardMedia("sendPhoto", $target, ["photo" => $photo["file_id"]], $caption);
    } elseif (isset($message["sticker"])) {
        forwardMedia("sendSticker", $target, ["sticker" => $message["sticker"]["file_id"]]);
    } elseif (isset($message["video_note"])) {
        forwardMedia("sendVideoNote", $target, ["video_note" => $message["video_note"]["file_id"]]);
    }
}

file_put_contents($STATE_FILE, json_encode($state));
