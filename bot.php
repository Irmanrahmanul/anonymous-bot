<?php
$BOT_TOKEN = "8002083390:AAHaXaKqYILkNSDMpUcQiJb1p3Aa-Ugfw14";
$STATE_FILE = "state.json";

$update = json_decode(file_get_contents("php://input"), true);
if (!$update || !isset($update["message"])) exit;

$user_id = $update["message"]["from"]["id"];
$text = trim($update["message"]["text"] ?? "");

$state = json_decode(file_get_contents($STATE_FILE), true);

function sendMessage($chat_id, $text) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot$BOT_TOKEN/sendMessage";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        "chat_id" => $chat_id,
        "text" => $text
    ]));
    curl_exec($ch);
    curl_close($ch);
}

// ... (Gunakan logika /start, /find, /next, /stop milikmu sebelumnya di sini) ...

// Simpan State
file_put_contents($STATE_FILE, json_encode($state));
