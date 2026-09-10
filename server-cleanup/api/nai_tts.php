<?php
// public_html/api/nai_tts.php
// NAGALAND AI — TEXT TO SPEECH: RETIRED STUB v2.0 (Sep 2026)
//
// WHAT THIS FILE USED TO BE:
//   A premium ElevenLabs text-to-speech endpoint. The mobile app's voice
//   screen posted a line of text here and got back MP3 audio. Every one of
//   those calls cost real money on the ElevenLabs account.
//
// WHY IT IS NOW A STUB AND NOT DELETED:
//   The voice screen is gone from the app, but people still running the
//   OLD app build (2.0.0) have it installed and their phones keep calling
//   this URL. Deleting the file would send them a 404. Answering with the
//   endpoint's own documented "fallback" reply instead means their phone
//   quietly uses the FREE built-in device voice, exactly as it already did
//   whenever the daily budget ran out. Nothing on their screen breaks.
//
//   The important part: this file no longer loads wp-config.php, never
//   reads ELEVENLABS_API_KEY, and never contacts ElevenLabs. Billing from
//   this endpoint stops the moment you upload this file — nothing else is
//   needed to stop the charges.
//
// SAFE TO DELETE LATER:
//   Once old 2.0.0 installs have faded out, this file and the
//   nai_tts_cache/ folder can be removed completely.
//
// NOT USED BY THE WEBSITE: nagalandai.com's chat pages never called this
// endpoint — nai_chatpage.js and nai_homepage.js only use chat.php,
// nai_init.php and nai_memory_api.php. Removing it changes nothing on the
// website.

header('X-Content-Type-Options: nosniff');

/* CORS + preflight, kept identical to the old file so old app builds and
   any website call behave exactly as they did before. */
$naiAllowedOrigins = [
  'https://nagalandai.com',
  'https://www.nagalandai.com',
];
$naiOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($naiOrigin, $naiAllowedOrigins, true)) {
  header('Access-Control-Allow-Origin: ' . $naiOrigin);
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Nai-App');
  header('Access-Control-Allow-Credentials: true');
  header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

/* The original endpoint's contract: HTTP 200 with fallback:true means
   "no premium audio this time — use the device voice." Clients already
   handle it, because it is what they got whenever the daily character
   budget was reached. */
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['fallback' => true, 'reason' => 'retired'], JSON_UNESCAPED_UNICODE);
