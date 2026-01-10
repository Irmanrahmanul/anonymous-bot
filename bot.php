<?php
$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";
$STATE_FILE = "/tmp/state.json"; // Menggunakan folder /tmp agar diizinkan menulis di Railway

// Inisialisasi file state jika belum ada
if (!file_exists($STATE_FILE)) {
    file_put_contents($STATE_FILE, json_encode(["waiting" => null, "pairs" => []]));
}

$update = json_decode(file_get_contents("php://input"), true);

// Debugging: Catat ke log Railway jika ada pesan masuk
if ($update) {
    error_log("Update diterima: " . json_encode($update));
}

if (!isset($update["message"])) exit;

$user_id = $update["message"]["from"]["id"];
$text = trim($update["message"]["text"] ?? "");

$state = json_decode(file_get_contents($STATE_FILE), true);

function sendMessage($chat_id, $text) {
    global $API;
    $url = $API . "sendMessage";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "chat_id" => $chat_id,
        "text" => $text
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

// --- LOGIKA BOT ---
if ($text === "/start") {
    sendMessage($user_id, "🔒 Anonymous Chat Bot\n\n/find - Cari pasangan\n/stop - Berhenti");
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
elseif (isset($state["pairs"][$user_id])) {
    sendMessage($state["pairs"][$user_id], $text);
}

// Simpan State
file_put_contents($STATE_FILE, json_encode($state));
