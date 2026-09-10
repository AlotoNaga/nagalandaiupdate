<?php
// public_html/api/chat.php
// Nagaland AI — Pro-Level Backend v4.1 (VOICE REMOVED)
// Modes: "chat" (default) | "student" (AI Teacher for schools)
//
// v4.1 CHANGES (Sep 2026):
// - Voice / spoken English mode removed. The mobile app dropped its
//   voice screen, so nothing sends {"mode":"speaking"} any more.
//   Removed: the speaking entry in the mode whitelist, the voice daily
//   allowance block, the nai_speaking.php router branch, and every
//   $mode === 'speaking' special case in web search, link formatting,
//   history depth, max_tokens and temperature.
// - Chat mode and Student mode are UNCHANGED. With speaking gone every
//   removed branch was unreachable for them, so both behave exactly as
//   they did before.
// - Companion changes: nai_speaking.php deleted, nai_tts.php reduced to
//   a fallback stub (stops ElevenLabs billing), nai_greeting.php spoken
//   greetings removed, nai_knowledge.php no longer advertises English
//   Speaking.
//
// v4.0 CHANGES (Jul 2026):
// - When a FREE user finishes their daily speaking time, the message now
//   warmly invites them to upgrade to keep practising today. Paid users
//   get the same warmth without being sold something they already own.
//   Sends can_upgrade so the app can show a real Upgrade button.
//
// v3.9 CHANGES (Jul 2026):
// - Voice replies now sound natural instead of clipped. Speaking-mode
//   token ceiling raised 160 -> 300 and nai_speaking.php v2 asks for a
//   real teacher's rhythm rather than a fixed sentence count.
// - Voice gets its OWN daily allowance, separate from text chat, so
//   speaking practice never eats a student's text messages.
//   Guests 0 (warm log-in message), registered free 10, pro 40,
//   promax 100, promax+ 250, school 40. All in one array below.
//
// v3.8 CHANGES (Jul 2026):
// - BRAND GREETING: saying or typing "Nagaland Me" is recognised as a
//   greeting (new module nai_greeting.php) and answered instantly by
//   the user's name. Guests get a warm welcome plus an invitation to
//   create a free account so they are remembered. Runs before the
//   daily limit and never calls OpenAI, so greetings are free and
//   unlimited. Reword the greetings any time by editing that file —
//   no app rebuild needed.
//
// v3.7 CHANGES (Jul 2026):
// - NEW "speaking" mode for voice conversation in the mobile app
//   (new module nai_speaking.php). Tuned for listening, not reading:
//   replies capped at 160 tokens so they stay 2-3 sentences, no links
//   or markdown injected, warmer temperature, and a longer 10-entry
//   conversation memory since spoken turns are short. Web search is
//   skipped in this mode to keep the conversation flowing.
//   Send {"mode":"speaking"} from the app to use it.
//
// v3.6 CHANGES (Jul 2026):
// - LIVE WEB SEARCH via Brave Search API (new module nai_websearch.php).
//   Triggers only on explicit requests ("search online...", "google
//   this..."). Results are fed to the AI with source links. 30-minute
//   query cache + 60/day cap protect the free 2,000/month quota.
//   Requires define('BRAVE_SEARCH_KEY', '...') in wp-config.php;
//   without the key everything behaves exactly as before.
//
// v3.5 CHANGES (Jul 2026):
// - Links are now professional named links: [Register](url) style.
//   The AI always uses markdown links with short names, never bare
//   URLs, and never bold-wrapped links (the one combination older app
//   builds could not render). Canned tribal reply and memory replies
//   updated the same way. Limit ERROR strings keep bare URLs because
//   the website error renderer only auto-links plain URLs.
// - Plan numbers preserved as deployed by Aloto: guest 20, explore 25.
//
// v3.4 CHANGES (Jul 2026, public launch):
// - Guest daily limit raised 10 -> 20 so casual first-time users during
//   the launch surge can actually try the assistant before being asked
//   to register.
// - Raw-IP safety ceiling raised 300 -> 500 for busy shared carrier IPs.
// - IMPORTANT: this file also carries the v3.2 CGNAT fix. If it hits the
//   guest limit on a user's FIRST message, the per-IP version is still
//   live and this file has NOT been deployed yet — deploy it.
//
// v3.3 CHANGES (Jul 2026, pre-deploy review):
// - Raw-IP ceiling is now checked BEFORE consuming a device's daily
//   credit, so a ceiling-blocked request no longer wastes the user's
//   own quota.
// - $deviceKey passed as a function parameter (no global).
// - Client fingerprint capped at 64 chars; incoming message capped
//   at 8000 chars before detection regexes run (AI still receives
//   max 3000 as before — no user-visible change).
//
// v3.2 CHANGES (Jul 2026):
// - CGNAT FIX: guest daily limit and per-minute burst limit are now
//   counted per DEVICE (IP + client fingerprint) instead of per IP.
//   Mobile carriers put many users behind one shared IP; the old
//   per-IP counting made those users share one quota and block each
//   other. App installs send a permanent unique fingerprint; the
//   website sends a device-characteristic fingerprint. Falls back to
//   IP alone if no fingerprint is present. A raw-IP safety ceiling
//   (300/day guest) blocks fingerprint-spoofing abuse from one line.
//
// v3.1 CHANGES (Jul 2026):
// - FIX: random "Something went wrong" errors mid-conversation.
//   Root cause: a single slow OpenAI generation (long replies in ongoing
//   chats) crossed the hosting time wall — the server returned an HTML
//   error page instead of JSON, which the app cannot parse.
//   Fixes: set_time_limit(75), per-attempt curl timeout 24s with ONE
//   automatic retry on failure/timeout/429/5xx, chat max_tokens 1500->1200.
// - NEW: error logging to /api/nai_logs/chat_errors.log (self-truncating)
//   so any remaining failure shows its real cause.
// - HARDENED: history entries validated (no PHP warnings), invalid UTF-8
//   scrubbed, every JSON output uses JSON_INVALID_UTF8_SUBSTITUTE so the
//   response body can never be empty or malformed.
// - FIX: links now sent as bare URLs (https://...) instead of markdown
//   [text](url) — the mobile app renders bare URLs as tappable links.
//   Applied to the tribal-language canned reply, limit messages, and a
//   universal formatting rule injected for both modes.
// - History window widened from last 2 to last 4 entries (better
//   continuity for follow-ups like "what?", still speed-safe).

/* =========================================================
   SECURITY & HEADERS
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

date_default_timezone_set('Asia/Kolkata');

// Give slow AI generations room to finish. The hosting gateway
// (LiteSpeed) has its own wall; PHP must not die before it.
@set_time_limit(75);
@ignore_user_abort(true);

/* =========================================================
   OUTPUT + LOGGING HELPERS (v3.1)
   Every response leaves through nai_json_out() so the body
   is ALWAYS valid JSON, even if a string contains bad bytes.
   ========================================================= */
function nai_json_out($arr, $httpCode = 200) {
  if ($httpCode !== 200) http_response_code($httpCode);
  $out = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($out === false) {
    // Absolute last resort — still valid JSON
    $out = '{"error":"Response encoding failed. Please try again."}';
  }
  echo $out;
  exit;
}

function nai_log_error($stage, $detail) {
  $dir = __DIR__ . '/nai_logs';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $file = $dir . '/chat_errors.log';

  // Self-truncate: keep the log under ~2MB
  if (file_exists($file) && filesize($file) > 2097152) {
    @file_put_contents($file, "[log truncated " . date('Y-m-d H:i:s') . "]\n");
  }

  $line = json_encode([
    'time'   => date('Y-m-d H:i:s'),
    'stage'  => $stage,
    'detail' => mb_substr((string)$detail, 0, 500),
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

  @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

// Scrub invalid UTF-8 so user/history text can never poison the AI call
function nai_clean_text($s) {
  $s = (string)$s;
  $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
  if ($clean === false || $clean === null) {
    $clean = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
  }
  return trim((string)$clean);
}

// CORS — allow only your own domains
$allowedOrigins = [
  'https://nagalandai.com',
  'https://www.nagalandai.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Nai-App');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  nai_json_out(['error' => 'Method not allowed'], 405);
}

/* =========================================================
   ANTI-ABUSE: LAYER 1 — Block scripts/bots by User-Agent
   Curl, wget, python-requests, Postman etc. are blocked.
   Real browsers always send a long UA string.

   NATIVE APP BYPASS: The official Nagaland AI mobile app
   sends a secret header. If present and correct, skip the
   browser-only User-Agent check. All other anti-abuse layers
   still apply (burst limit, daily limit, fingerprint).
   Bots cannot guess this secret, so security stays strong.
   ========================================================= */
$naiAppSecret = $_SERVER['HTTP_X_NAI_APP'] ?? '';
$naiIsOfficialApp = ($naiAppSecret === 'NAI_APP_9f3Kd72Lp0qWmZx4Rb8Tn6Vy1Hs5Jc');

if (!$naiIsOfficialApp) {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  if (strlen($ua) < 30 || preg_match('/^(curl|wget|python|httpie|postman|insomnia|go-http|java|perl|ruby|php|node|axios)/i', $ua)) {
    nai_json_out(['error' => 'Access denied.'], 403);
  }
}

/* =========================================================
   READ REQUEST
   ========================================================= */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
  nai_log_error('request_decode', 'Invalid JSON body. First bytes: ' . mb_substr((string)$raw, 0, 120));
  nai_json_out(['error' => 'Invalid JSON'], 400);
}

$message = nai_clean_text($payload['message'] ?? '');
$history = $payload['history'] ?? [];
$mode    = trim((string)($payload['mode'] ?? 'chat')); // "chat" or "student"

// Validate mode
if (!in_array($mode, ['chat', 'student'], true)) {
  $mode = 'chat';
}

if ($message === '') {
  nai_json_out(['error' => 'Empty message'], 400);
}

// Cap extremely long inputs early so detection regexes stay fast.
// The AI itself only ever receives the first 3000 characters anyway.
if (mb_strlen($message) > 8000) {
  $message = mb_substr($message, 0, 8000);
}

// Student mode: optional subject and grade from frontend
$studentSubject = trim((string)($payload['subject'] ?? ''));
$studentGrade   = trim((string)($payload['grade'] ?? ''));

/* =========================================================
   DEVICE IDENTITY (v3.2 — CGNAT FIX)
   Mobile carriers (Jio, Airtel, Vi) put thousands of users
   behind ONE shared public IP (Carrier-Grade NAT). Counting
   limits by IP alone means those users share one quota and
   block each other. We instead identify a device by IP + the
   client fingerprint (_nai_fp). The website sends a fingerprint
   built from device characteristics; the app sends a permanent
   random per-install id. Combining both means:
     - Same carrier IP, different phones  -> different fingerprints -> separated
     - Same phone model, different people -> different IPs -> separated
   If a fingerprint is missing (old cache), we fall back to IP
   alone so nothing breaks. This $deviceKey is used for burst
   and guest daily limits below.
   ========================================================= */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$naiFp = mb_substr(trim((string)($payload['_nai_fp'] ?? '')), 0, 64);
$deviceKey = ($naiFp !== '' && strlen($naiFp) >= 4)
  ? ('ipfp:' . $ip . '|' . $naiFp)   // strong: carrier IP + device fingerprint
  : ('ip:' . $ip);                   // fallback: IP only (fingerprint missing)

/* =========================================================
   ANTI-ABUSE: LAYER 2 — Server-generated session token
   Frontend gets a token from /api/nai_init.php on page load.
   Soft check: uses token if available, still works without.
   Other layers (UA, burst, fingerprint) provide backup.
   ========================================================= */
$sessionToken = trim((string)($payload['_nai_tok'] ?? ''));
$tokenValid = false;

if ($sessionToken !== '' && strlen($sessionToken) >= 16) {
  $tokenFile = sys_get_temp_dir() . '/nai_tok_' . md5('tok:' . $sessionToken) . '.json';
  if (file_exists($tokenFile)) {
    $tokenData = @json_decode(@file_get_contents($tokenFile), true);
    if (is_array($tokenData) && (time() - ($tokenData['created'] ?? 0)) <= 7200) {
      $tokenValid = true;
    } else {
      @unlink($tokenFile);
    }
  }
}
// Token is tracked but not enforced — other layers handle security

/* =========================================================
   ANTI-ABUSE: LAYER 3 — Per-minute burst rate limiting
   Max 5 requests per minute per DEVICE (IP + fingerprint, see
   $deviceKey above). Per-device instead of per-IP so users on
   a shared carrier IP don't block each other. Stops rapid-fire
   abuse from a single device.
   ========================================================= */
$burstFile = sys_get_temp_dir() . '/nai_burst_' . md5('burst:' . $deviceKey) . '.json';
$burstMinute = date('Y-m-d-H-i');
$burstData = null;

if (file_exists($burstFile)) {
  $burstRaw = @file_get_contents($burstFile);
  if ($burstRaw) $burstData = @json_decode($burstRaw, true);
}

if (is_array($burstData) && ($burstData['m'] ?? '') === $burstMinute) {
  if (($burstData['c'] ?? 0) >= 5) {
    nai_json_out(['error' => 'Too many requests. Please wait a moment.'], 429);
  }
  @file_put_contents($burstFile, json_encode(['m' => $burstMinute, 'c' => ($burstData['c'] ?? 0) + 1]));
} else {
  @file_put_contents($burstFile, json_encode(['m' => $burstMinute, 'c' => 1]));
}

/* =========================================================
   TRIBAL LANGUAGE TRANSLATION REDIRECT
   Intercepts BEFORE daily limit & API call — no credit used.
   Detects translation requests into Naga tribal languages
   and redirects politely to nagalanddictionary.com.
   v3.1: link is now a bare URL (renders as tappable link in
   the mobile app AND on the website).
   ========================================================= */
$naiTribalLanguages = [
  'angami', 'ao', 'chakhesang', 'chang', 'khiamniungan',
  'konyak', 'kuki', 'lotha', 'nagamese', 'phom',
  'pochury', 'rengma', 'rongmei', 'sangtam', 'sumi',
  'tikhir', 'yimkhiung', 'zeliang'
];

$tribalPattern = implode('|', $naiTribalLanguages);
$hasTribalLang = (bool)preg_match('/\b(' . $tribalPattern . ')\b/i', $message);

if ($hasTribalLang) {
  // Check for explicit translation intent words
  $hasTranslationIntent = (bool)preg_match('/\b(translate|translation|how\s+to\s+say|how\s+do\s+you\s+say|meaning\s+in|convert|write\s+in|speak\s+in|say\s+in)\b/i', $message);

  // Check for "in/to/into + tribal language" pattern (catches "what is hello in Ao")
  $hasInToTribalLang = (bool)preg_match('/\b(in|into|to)\s+(' . $tribalPattern . ')\b/i', $message);

  if ($hasTranslationIntent || $hasInToTribalLang) {
    // Extract the detected language name for a personalized response
    preg_match('/\b(' . $tribalPattern . ')\b/i', $message, $langMatch);
    $detectedLang = ucfirst(strtolower($langMatch[1] ?? ''));

    $tribalReply  = "I appreciate your interest in the {$detectedLang} language!\n\n";
    $tribalReply .= "Naga tribal languages are unique and deeply rooted in oral tradition — they require verified, human-sourced translations that no AI can reliably provide yet. ";
    $tribalReply .= "Even the most advanced AI models do not have accurate data for Naga tribal languages, so I don't want to give you incorrect translations.\n\n";
    $tribalReply .= "For accurate {$detectedLang} word meanings, translations, and example sentences, please visit **Nagaland Dictionary** — it's built specifically for Naga languages with community-verified data:\n\n";
    $tribalReply .= "[Visit Nagaland Dictionary](https://nagalanddictionary.com)\n\n";
    $tribalReply .= "It supports 18+ Naga languages including Ao, Angami, Sumi, Lotha, Konyak, Nagamese, and more. It's part of the Nagaland Me ecosystem, just like me!\n\n";
    $tribalReply .= "Is there anything else I can help you with?";

    nai_json_out([
      'reply' => $tribalReply,
      'mode'  => $mode
    ]);
  }
}

/* =========================================================
   LOAD WORDPRESS CONFIG (API KEY)
   ========================================================= */
$wpConfigPath = dirname(__DIR__) . '/wp-config.php';
if (file_exists($wpConfigPath)) {
  require_once $wpConfigPath;
}

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
  nai_log_error('config', 'OPENAI_API_KEY missing');
  nai_json_out(['error' => 'Server API key not configured'], 500);
}

$apiKey = OPENAI_API_KEY;

/* =========================================================
   BRAND GREETING — "Nagaland Me" (v3.9)
   Recognised as a greeting and answered instantly by name.

   POSITION MATTERS: this must sit AFTER wp-config.php is loaded
   (ABSPATH gets defined there) and after WordPress user functions
   are available, otherwise the module guard exits and the response
   comes back empty. It is still BEFORE the daily limit and BEFORE
   any OpenAI call, so greetings stay free, instant, and unlimited.
   ========================================================= */
if (!defined('NAI_LOADED')) define('NAI_LOADED', true);
if (file_exists(__DIR__ . '/nai_greeting.php')) {
  require_once __DIR__ . '/nai_greeting.php';
  if (function_exists('nai_detect_wake_greeting') && nai_detect_wake_greeting($message)) {
    nai_json_out([
      'reply'    => nai_build_greeting_reply($mode),
      'mode'     => $mode,
      'greeting' => true,
    ]);
  }
}

/* =========================================================
   DAILY LIMIT (Guest vs Logged-in user plans)
   ========================================================= */

function nai_user_plan_limit($deviceKey) {
  $limits = [
    'guest'       => 20,
    'explore'     => 25,
    'pro'         => 200,
    'promax'      => 1000,
    'promaxplus'  => 10000,
    'school'      => 500,
  ];

  if (function_exists('is_user_logged_in') && is_user_logged_in()) {
    $user_id = get_current_user_id();
    $plan = get_user_meta($user_id, 'nai_plan', true);
    if (!$plan) $plan = 'explore';
    return [$plan, ($limits[$plan] ?? 20), 'user:' . $user_id];
  }

  // Guests: identify by device (IP + fingerprint) so users behind one
  // shared carrier IP (CGNAT) don't share a single quota. $deviceKey is
  // built early in the request and falls back to IP alone if no
  // fingerprint was sent.
  return ['guest', $limits['guest'], 'dev:' . $deviceKey];
}

[$planName, $DAILY_LIMIT, $limitKey] = nai_user_plan_limit($deviceKey);

$today = date('Y-m-d');
$limitFile = sys_get_temp_dir() . '/nai_daily_' . md5($limitKey . $today) . '.txt';
$count = file_exists($limitFile) ? (int)file_get_contents($limitFile) : 0;

if ($count >= $DAILY_LIMIT) {
  $planLabelMap = [
    'guest' => 'Guest', 'explore' => 'Free', 'pro' => 'Pro',
    'promax' => 'Pro Max', 'promaxplus' => 'Pro Max Plus', 'school' => 'School',
  ];
  $planLabel = $planLabelMap[$planName] ?? $planName;

  if ($planName === 'guest') {
    nai_json_out([
      'error' => 'You have reached the free guest limit for today. Please log in at https://nagalandai.com/login or create a free account at https://nagalandai.com/register to continue chatting.'
    ], 429);
  }

  nai_json_out([
    'error' => 'You have reached today\'s limit for your ' . $planLabel . ' plan. Please try again tomorrow or upgrade your account at https://nagalandai.com/upgrade'
  ], 429);
}

/* =========================================================
   ANTI-ABUSE: LAYER 4 — Raw-IP safety ceiling (v3.2)
   Guests are now counted per-device (IP + fingerprint) so that
   users behind one carrier IP don't block each other. The one
   risk of per-device counting: a single abuser could spoof many
   fake fingerprints from one IP to multiply their free quota.
   This ceiling caps the TOTAL guest messages from one raw IP per
   day at a high shared level — high enough that real users on a
   busy carrier IP are never affected, low enough to stop a script
   from rotating thousands of fake fingerprints on one connection.
   Logged-in users are exempt (counted by user id).
   ========================================================= */
$ipCeilFile = null;
$ipCeilCount = 0;
if ($planName === 'guest') {
  $rawIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $ipCeiling = 500; // generous shared daily ceiling per carrier IP (raised for launch surge)
  $ipCeilFile = sys_get_temp_dir() . '/nai_ipcap_' . md5('ipcap:' . $rawIp . ':' . $today) . '.txt';
  $ipCeilCount = file_exists($ipCeilFile) ? (int)@file_get_contents($ipCeilFile) : 0;

  if ($ipCeilCount >= $ipCeiling) {
    nai_json_out([
      'error' => 'This network has reached a high volume of free messages today. Please log in at https://nagalandai.com/login or create a free account at https://nagalandai.com/register to continue chatting.'
    ], 429);
  }
}

// All limit checks passed — consume the credits together.
file_put_contents($limitFile, $count + 1);
if ($ipCeilFile !== null) {
  @file_put_contents($ipCeilFile, $ipCeilCount + 1);
}

/* =========================================================
   MEMORY SYSTEM — Personalization for paid subscribers
   Detects remember/forget/list commands and handles them.
   Loads saved memories for injection into AI system prompt.
   ========================================================= */
$naiMemoryContext = '';
$naiUserId = 0;

if (file_exists(__DIR__ . '/nai_memory.php')) {
  require_once __DIR__ . '/nai_memory.php';

  // Get user ID if logged in
  if (function_exists('is_user_logged_in') && is_user_logged_in()) {
    $naiUserId = get_current_user_id();
  }

  // Detect memory commands in user message
  $memCommand = nai_detect_memory_command($message);

  if ($memCommand !== null) {
    // SAVE memory
    if ($memCommand['type'] === 'save') {
      if ($naiUserId <= 0) {
        nai_json_out(['reply' => 'You need to be logged in to save memories. Please [log in](https://nagalandai.com/login) or [create an account](https://nagalandai.com/register) first.', 'mode' => $mode]);
      }
      $result = nai_add_memory($naiUserId, $planName, $memCommand['content']);
      $reply = $result['success']
        ? "Got it! I will remember: **" . $memCommand['content'] . "**\n\n" . $result['message']
        : $result['message'];
      nai_json_out(['reply' => $reply, 'mode' => $mode]);
    }

    // LIST memories
    if ($memCommand['type'] === 'list') {
      if ($naiUserId <= 0) {
        nai_json_out(['reply' => 'You need to be logged in to view memories. Please [log in](https://nagalandai.com/login) or [create an account](https://nagalandai.com/register) first.', 'mode' => $mode]);
      }
      $memories = nai_get_memories($naiUserId);
      $limit = nai_memory_limit($planName, $naiUserId);
      if (empty($memories)) {
        $reply = "I don't have any saved memories about you yet.\n\n";
        if ($limit > 0) {
          $reply .= "You can tell me things like:\n- \"Remember that I'm preparing for NEET\"\n- \"Remember my name is Anya\"\n- \"Remember I study at XYZ School\"\n\nI'll use these to personalize our conversations! You can save up to **" . $limit . " memories** on your plan.";
        } else {
          $reply .= "Memory is available for **Pro, ProMax, and ProMax+** subscribers. [Upgrade your plan](https://nagalandai.com/upgrade) to unlock this feature.";
        }
      } else {
        $reply = "Here's what I remember about you (" . count($memories) . "/" . $limit . " memories used):\n\n";
        foreach ($memories as $i => $mem) {
          $reply .= ($i + 1) . ". " . $mem['text'] . "\n";
        }
        $reply .= "\nTo remove all memories, say **\"forget everything\"**. To add more, just say **\"remember that...\"**";
      }
      nai_json_out(['reply' => $reply, 'mode' => $mode]);
    }

    // CLEAR all memories
    if ($memCommand['type'] === 'clear') {
      if ($naiUserId <= 0) {
        nai_json_out(['reply' => 'You need to be logged in.', 'mode' => $mode]);
      }
      $result = nai_clear_memories($naiUserId);
      nai_json_out(['reply' => $result['message'], 'mode' => $mode]);
    }
  }

  // Load memories for context injection (for regular messages)
  if ($naiUserId > 0) {
    $memories = nai_get_memories($naiUserId);
    if (!empty($memories)) {
      $naiMemoryContext = nai_build_memory_context($memories);
    }
  }
}

/* =========================================================
   LOAD MODULES
   ========================================================= */
$naiSources = require __DIR__ . '/nai_sources.php';
require_once __DIR__ . '/nai_helpers.php';   // Weather, page cache, scholarship fetch
require_once __DIR__ . '/nai_rss.php';       // RSS fetch/parse/cache

$rssCacheFile = __DIR__ . '/nai_rss_cache.json';

// Load ecosystem connector (Dictionary, Profiles cross-platform)
if (file_exists(__DIR__ . '/nai_ecosystem.php')) {
  require_once __DIR__ . '/nai_ecosystem.php';
}
// Load school attendance connector (Parent AI queries)
if (file_exists(__DIR__ . '/nai_school.php')) {
  if (!defined('NAI_LOADED')) define('NAI_LOADED', true);
  require_once __DIR__ . '/nai_school.php';
}
/* =========================================================
   MODE ROUTER
   ========================================================= */

if ($mode === 'student') {
  // ─── STUDENT MODE ─── (AI Teacher for Nagaland schools)
  require_once __DIR__ . '/nai_student.php';
} else {
  // ─── CHAT MODE ─── (Default: News, Weather, Scholarships, Ecosystem)
  require_once __DIR__ . '/nai_chat.php';
}
/* =========================================================
   SCHOOL ATTENDANCE — Parent AI Query Detection
   Now checks saved memories for parent codes if user
   doesn't provide one in their message.
   ========================================================= */
$naiSchoolContext = '';

if (function_exists('nai_detect_attendance_query')) {
    $attQuery = nai_detect_attendance_query($message);

    if ($attQuery['is_attendance']) {
        $parentCode = $attQuery['parent_code'];

        // If no code in message, check memories for saved parent codes
        if (!$parentCode && $naiUserId > 0 && function_exists('nai_extract_parent_codes')) {
            $memories = nai_get_memories($naiUserId);
            $children = nai_extract_parent_codes($memories);
            if (count($children) === 1) {
                // One child saved - use automatically
                $parentCode = $children[0]['code'];
            } elseif (count($children) > 1) {
                // Multiple children - tell AI to ask which one
                $childList = [];
                foreach ($children as $child) {
                    $childList[] = $child['name'];
                }
                $naiSchoolContext = "ATTENDANCE SYSTEM: The parent is asking about attendance. " .
                    "They have multiple children saved: " . implode(', ', $childList) . ". " .
                    "Ask which child they are asking about. Once they say the name, " .
                    "you can look up attendance. Do NOT ask for the code - you already have it.";
            }
        }

        if ($parentCode) {
            if (function_exists('nai_fetch_school_attendance')) {
                $attData = nai_fetch_school_attendance(
    $parentCode,
    $attQuery['query_type'],
    $attQuery['extras'] ?? []
);
                if (function_exists('nai_build_attendance_context')) {
                    $naiSchoolContext = nai_build_attendance_context($attData, $attQuery['query_type']);
                }
            }
        } elseif ($naiSchoolContext === '') {
            $naiSchoolContext = "ATTENDANCE SYSTEM: The parent is asking about school attendance " .
                "but hasn't provided their 6-digit parent code yet. Ask them to share their " .
                "parent code (the 6-digit number from the slip their school gave them). " .
                "Tip: Tell them they can say 'Remember my child is [name], code [123456]' to save it " .
                "so they never have to type it again. " .
                "If they don't have a code, tell them to contact their school.";
        }
    }
}

if ($naiSchoolContext !== '') {
    $messages[] = ['role' => 'system', 'content' => $naiSchoolContext];
}

// Inject user memories into system prompt
if ($naiMemoryContext !== '') {
    $messages[] = ['role' => 'system', 'content' => $naiMemoryContext];
}

/* =========================================================
   LIVE WEB SEARCH (v3.6 — applies to BOTH modes)
   Runs ONLY when the user explicitly asks to search the
   internet. Needs BRAVE_SEARCH_KEY in wp-config.php; without
   the key this block is completely silent.
   ========================================================= */
if (file_exists(__DIR__ . '/nai_websearch.php')) {
  require_once __DIR__ . '/nai_websearch.php';

  if (defined('BRAVE_SEARCH_KEY') && BRAVE_SEARCH_KEY) {
    // The AI should know it has this ability (so it stops denying it)
    $messages[] = ['role' => 'system', 'content' =>
      "CAPABILITY: You CAN search the live internet when the user asks you to " .
      "search online. If asked whether you can search the web, say yes."
    ];

    if (function_exists('nai_detect_search_intent') && nai_detect_search_intent($message)) {
      $naiSearchCtx = nai_web_search_context($message);
      if ($naiSearchCtx !== '') {
        $messages[] = ['role' => 'system', 'content' => $naiSearchCtx];
      }
    }
  }
}

/* =========================================================
   LINK FORMATTING RULE (v3.1 — applies to BOTH modes)
   The mobile app renders bare URLs as tappable links but
   cannot render markdown [text](url) syntax. Bare URLs
   work on the app AND the website, so they are the standard.
   ========================================================= */
$messages[] = ['role' => 'system', 'content' =>
  "LINK FORMATTING RULE: When sharing any link, write it as a markdown link with a " .
  "short clear name — like [Register](https://nagalandai.com/register) or " .
  "[Nagaland Dictionary](https://nagalanddictionary.com). Never paste long bare URLs. " .
  "NEVER wrap a link inside **bold** markers — write the link plain, with no asterisks " .
  "around it. Place important links on their own line."
];

/* =========================================================
   HISTORY (shared by both modes) — v3.1 hardened
   ========================================================= */
$history = is_array($history) ? array_slice($history, -4) : [];

foreach ($history as $h) {
  if (!is_array($h)) continue;
  if (!isset($h['role'], $h['content'])) continue;
  if (!in_array($h['role'], ['user', 'assistant'], true)) continue;
  if (!is_string($h['content']) && !is_numeric($h['content'])) continue;

  $content = nai_clean_text($h['content']);
  if ($content === '') continue;

  $messages[] = [
    'role' => $h['role'],
    'content' => mb_substr($content, 0, 3000)
  ];
}

$messages[] = [
  'role' => 'user',
  'content' => mb_substr($message, 0, 3000)
];

/* =========================================================
   CALL OPENAI CHAT COMPLETIONS API (v3.1 — WITH RETRY)
   One automatic retry on timeout / network error / 429 / 5xx.
   Per-attempt timeout 24s keeps total request time inside the
   hosting gateway wall even on the retry path.
   ========================================================= */
$body = [
  'model' => 'gpt-4o-mini',
  'messages' => $messages,
  'max_tokens' => ($mode === 'student') ? 2048 : 1200,
];

if ($mode === 'student') {
  $body['temperature'] = 0.7;
}

$bodyJson = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
if ($bodyJson === false) {
  nai_log_error('encode_body', 'json_encode failed: ' . json_last_error_msg());
  nai_json_out(['error' => 'AI service error. Please try again.'], 500);
}

function nai_call_openai($bodyJson, $apiKey) {
  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => $bodyJson,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 24,
  ]);
  $result = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);
  return [$result, $httpCode, $curlErr];
}

[$result, $httpCode, $curlErr] = nai_call_openai($bodyJson, $apiKey);

// Retry ONCE on transient failure (timeout, network error, rate limit, server error)
if ($result === false || $httpCode === 429 || $httpCode >= 500) {
  nai_log_error('openai_attempt1', "http={$httpCode} curl={$curlErr} body=" . mb_substr((string)$result, 0, 200));
  usleep(500000); // 0.5s breather
  [$result, $httpCode, $curlErr] = nai_call_openai($bodyJson, $apiKey);
}

$data = json_decode((string)$result, true);

if ($result === false || $httpCode >= 400 || !is_array($data)) {
  nai_log_error('openai_final', "http={$httpCode} curl={$curlErr} body=" . mb_substr((string)$result, 0, 300));
  nai_json_out(['error' => 'AI service error. Please try again.'], 500);
}

/* =========================================================
   EXTRACT RESPONSE TEXT
   ========================================================= */
$reply = '';

if (!empty($data['choices'][0]['message']['content'])) {
  $reply = $data['choices'][0]['message']['content'];
}

$reply = trim($reply);

if ($reply === '') {
  nai_log_error('empty_reply', 'OpenAI returned empty content. finish_reason=' . ($data['choices'][0]['finish_reason'] ?? '?'));
  nai_json_out(['error' => 'No response generated. Try again.'], 502);
}

nai_json_out([
  'reply' => $reply,
  'mode'  => $mode,
]);