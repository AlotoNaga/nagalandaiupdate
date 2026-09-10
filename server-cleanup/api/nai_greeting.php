<?php
// public_html/api/nai_greeting.php
// NAGALAND AI — BRAND GREETING v1.2
//
// v1.2: spoken greetings removed along with voice mode. Nothing sends
// mode "speaking" any more, so those branches could never run. The
// text greetings below are untouched and are what everyone now gets.
//
// v1.1: spoken greetings were an English TEACHER greeting a student
// ("are you ready to practise today?") rather than an assistant asking
// "how can I help". Text-mode greetings were unchanged.
//
// Turns "Nagaland Me" into a greeting the AI recognises and answers by name.
// The goal is cultural: the more warmly and personally this replies, the more
// people repeat it, and the more the brand name spreads through normal speech.
//
// WHY THIS LIVES ON THE SERVER, NOT IN THE APP:
//   1. It works for the website AND the app from one place.
//   2. The user's name and memory already live here.
//   3. You can reword the greetings any time by editing this file. No app
//      rebuild, no app store review, no waiting. Users see it immediately.
//
// COST: zero. A greeting never reaches OpenAI and never spends a daily
// message credit, so people can greet Nagaland AI as often as they like.

if (!defined('ABSPATH') && !defined('NAI_LOADED')) {
  exit;
}

/* =========================================================
   1. DETECT — did the user greet us with the brand name?

   Must be forgiving, because speech recognition mangles it:
   "Nagaland Me" often arrives as "nagaland may", "naga land me",
   "nagaland mi", "nagalandme".

   Must ALSO be strict about intent: a real question that happens
   to contain the brand name ("what is Nagaland Me?") is a question,
   not a greeting, and should go to the AI as normal.
   ========================================================= */
function nai_detect_wake_greeting($message) {
  $m = trim(mb_strtolower((string)$message));
  if ($m === '') return false;

  // Strip punctuation and emoji, collapse spaces
  $m = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $m);
  $m = trim(preg_replace('/\s+/u', ' ', $m));
  if ($m === '') return false;

  // The brand phrase, tolerant of how speech-to-text hears it
  $brand = '/\bnaga\s?land\s?(me|mee|may|mi|my|mae)\b/u';
  if (!preg_match($brand, $m)) return false;

  // A real question that mentions the brand is a QUESTION, not a greeting.
  // "What is Nagaland Me?" must go to the AI and get a proper answer.
  if (preg_match('/\b(what|whats|who|whose|how|why|when|where|which|can|could|would|should|' .
                 'do|does|did|tell|explain|show|give|help|make|write|find|search|open|' .
                 'download|price|cost|free|plan|plans|about|use|using|work|works)\b/u', $m)) {
    return false;
  }

  // Remove the brand phrase, then remove ordinary greeting filler.
  // Whatever is left tells us if this was a greeting or a real question.
  $rest = preg_replace($brand, ' ', $m);
  $rest = preg_replace(
    '/\b(hi|hii|hiii|hey|heyy|hello|helo|hallo|yo|dear|good|morning|afternoon|evening|night|' .
    'are|you|u|there|is|it|its|am|back|again|ok|okay|okey|please|pls|namaste|kuknalim|' .
    'greetings|sup|whats|what\'?s|up|my|friend|bro|sir|madam|ma\'?am)\b/u',
    ' ', $rest
  );
  $rest = trim(preg_replace('/\s+/u', ' ', $rest));

  // Nothing meaningful left over -> they were greeting us.
  if ($rest === '') return true;
  $words = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY);
  return (count($words) <= 1);
}

/* =========================================================
   2. NAME — who are we talking to?
   WordPress account name first (most reliable), then memory.
   Returns '' for guests.
   ========================================================= */
function nai_greeting_user_name() {
  if (!function_exists('is_user_logged_in') || !is_user_logged_in()) return '';
  if (!function_exists('wp_get_current_user')) return '';

  $u = wp_get_current_user();
  if (!$u || empty($u->ID)) return '';

  // a) first name on the account
  if (function_exists('get_user_meta')) {
    $first = trim((string)get_user_meta($u->ID, 'first_name', true));
    if ($first !== '' && mb_strlen($first) <= 30) return nai_greeting_clean_name($first);
  }

  // b) display name, unless it is just the login or an email
  $display = trim((string)($u->display_name ?? ''));
  if ($display !== ''
      && mb_strpos($display, '@') === false
      && mb_strtolower($display) !== mb_strtolower((string)($u->user_login ?? ''))) {
    $parts = preg_split('/\s+/u', $display, -1, PREG_SPLIT_NO_EMPTY);
    if (!empty($parts[0]) && mb_strlen($parts[0]) <= 30) return nai_greeting_clean_name($parts[0]);
  }

  // c) something the user asked us to remember, e.g. "my name is Anya"
  if (function_exists('nai_get_memories')) {
    $memories = nai_get_memories($u->ID);
    if (is_array($memories)) {
      foreach ($memories as $mem) {
        $text = is_array($mem) ? (string)($mem['text'] ?? '') : (string)$mem;
        if (preg_match('/\bname\s+is\s+([\p{L}\'\-]{2,30})/ui', $text, $hit)) {
          return nai_greeting_clean_name($hit[1]);
        }
      }
    }
  }

  return '';
}

function nai_greeting_clean_name($name) {
  $name = trim(preg_replace('/[^\p{L}\p{N}\'\- ]/u', '', (string)$name));
  if ($name === '') return '';
  return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

/* =========================================================
   3. REPLY — warm, varied, and different for each situation.

   Variations matter. A greeting people say every day must not
   feel like a recording, so one is picked at random each time.
   Edit the wording here freely; it goes live instantly.
   ========================================================= */
function nai_build_greeting_reply($mode = 'chat') {
  $name   = nai_greeting_user_name();
  $isUser = ($name !== '');

  // ---- A. We know them by name ----
  if ($isUser) {
    $options = [
      "Hello {$name}! Good to hear from you. What can I help you with today?",
      "Hi {$name}! I'm right here. What would you like to do?",
      "{$name}! Welcome back. What's on your mind today?",
      "Hey {$name}, good to see you again. How can I help?",
    ];
    return $options[array_rand($options)];
  }

  // ---- B. Logged in, but we still don't know their name ----
  if (function_exists('is_user_logged_in') && is_user_logged_in()) {
    return "Hello, welcome back! I don't know your name yet though. " .
           "Just tell me \"remember my name is ...\" and I'll keep it for next time.\n\n" .
           "What can I help you with today?";
  }

  // ---- C. Guest: greet warmly first, invite second ----
  $options = [
    "Hello there! It's good to hear from you.\n\n" .
    "I don't know you yet, but I'd like to. [Create a free account](https://nagalandai.com/register) " .
    "and tell me your name, and I'll remember you every time you come back.\n\n" .
    "In the meantime, what can I help you with?",

    "Hi, welcome! I'm sorry I don't know your name yet.\n\n" .
    "If you [create a free account](https://nagalandai.com/register), I can remember your name " .
    "and the things that matter to you, so we can pick up right where we left off.\n\n" .
    "What would you like to know today?",

    "Hello! Nice to meet you.\n\n" .
    "I don't know your name yet. [Create a free account](https://nagalandai.com/register) " .
    "and I'll remember you next time, so you never have to introduce yourself twice.\n\n" .
    "How can I help you right now?",
  ];
  return $options[array_rand($options)];
}