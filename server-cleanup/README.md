# Server cleanup — voice / ElevenLabs removal

Companion to the app 2.1 cleanup. Everything here is on the **server**
(`public_html/api/` on nagalandai.com).

**The headline:** once you upload `api/nai_tts.php`, ElevenLabs billing from
this endpoint stops immediately. That file no longer loads `wp-config.php`,
never reads `ELEVENLABS_API_KEY`, and never contacts ElevenLabs.

Nagaland AI chat, Student Mode and Schools are **untouched**. See
"What was deliberately left alone" at the bottom — please read that part.

---

## 1. Upload these 4 files (replace the ones on the server)

All go in `public_html/api/`. **Back up the originals first** — download a
copy, or rename the old one to `chat.php.bak` before pasting the new one.

| File | Was | Now | What changed |
|---|---|---|---|
| `api/nai_tts.php` | 529 lines | 55 lines | **The money fix.** Replaced with a stub that always replies "use the device voice". No ElevenLabs call, ever. |
| `api/chat.php` | 849 lines | 793 lines | Removed `speaking` mode: the mode whitelist entry, the voice daily allowance block, the `nai_speaking.php` branch, and every `$mode === 'speaking'` special case. |
| `api/nai_greeting.php` | 203 lines | 174 lines | Removed the spoken-greeting variants. Text greetings are byte-for-byte identical. |
| `api/nai_knowledge.php` | 244 lines | 244 lines | The AI no longer tells people to find "English Speaking" in the app. Replaced with an accurate "you are text only" statement. |

## 2. Delete these on the server

| Path | Why |
|---|---|
| `api/nai_speaking.php` | 126 lines. The spoken-English teacher prompt. Nothing calls it any more — `chat.php` was its only caller. |
| `api/nai_tts_cache/` | 3.1 MB, 24 cached `.mp3` files. Dead weight once TTS is gone. Delete the whole folder. |

## 3. Revoke the ElevenLabs key (do this too)

The key itself lives in `public_html/wp-config.php`, which is **not** in this
repo, so I could not edit it for you. Open it in your file manager and delete
these two lines:

```php
define('ELEVENLABS_API_KEY', '...');
define('ELEVENLABS_VOICE_ID', '...');
```

Deleting them is safe — after step 1 nothing reads them.

**Then revoke the key at elevenlabs.io → Developer settings → API Keys.**
Removing the line stops *your* server using it; revoking it is what makes the
key useless if it ever leaked. Worth doing while you are in there.

---

## Why `nai_tts.php` is a stub instead of just deleted

You said it yourself: people still on app 2.0.0 have the voice screen
installed, and their phones keep calling this URL.

- **Deleted** → they get a 404, which is an error path I could not test
  against your app build.
- **Stub** → they get `{"fallback": true}`, the endpoint's own documented
  reply for "no premium audio right now". Their phone quietly uses the free
  built-in device voice — exactly what already happened whenever the daily
  budget ran out. Nothing on their screen breaks, and you pay nothing.

Once 2.0.0 installs have faded out you can delete `api/nai_tts.php` and
`api/nai_tts_cache/` outright. There is a note in the file saying so.

## What old 2.0.0 users will see

Their voice screen still opens and still works, in the phone's own voice
rather than the premium one. Because `speaking` mode is gone from `chat.php`,
their request now falls through to normal chat mode (that fallback was
already in the code for unknown modes) — so replies come back a bit longer
and may contain links, which the phone will read aloud. It works; it is just
not the tuned teacher any more. Nobody gets an error, and it costs you
nothing beyond the normal OpenAI chat cost.

---

## What was deliberately left alone

I checked each of the five removed sites against the server code. **The sites
themselves are still live** — you removed them from the *app*, not from the
internet — and Nagaland AI still uses several of them to answer questions.
Cleaning these would have changed how the AI works, so I did not touch them:

- **`nai_ecosystem.php`** — calls `nagalanddictionary.com/api/nai-lookup.php`
  and `nagalandprofiles.com/api/nai-search.php` live. This is how the AI
  answers Naga word and profile questions. Removing it would break a real
  feature.
- **`nai_sources.php`** — reads `nagalandnewstoday.com/feed` and
  `helpnagaland.com/feed` as news sources for the AI.
- **`nai_knowledge.php` ecosystem list** — still correct: those are real
  websites people can visit. Only the *English Speaking* section was removed.
- **`nai_chat.php`** — untouched. Its `$isVoiceQuery` detector (which catches
  "can you talk to me?") still works and now loads the corrected text-only
  knowledge, so it gives the right answer without any code change. Its
  header comment is now slightly out of date; harmless, so I left the file
  alone rather than edit it for a comment.
- **`nai_student.php`** — untouched. Its only "voice" match was
  `active voice|passive voice` in the English-grammar subject regex. Nothing
  to do with speech.
- **Everything Schools** — `nai_school.php`, `nai_student.php`, the
  `nagaland-ai-schools` plugin: not touched at all.
- **The removed notification categories** (Order Updates, Messages, Reviews,
  Nagaland News) — there is no push/FCM sending code anywhere on the server.
  Those were purely app-side toggles, so there was nothing to clean.
- **`nagaland.news`** — appears nowhere in the server code.
- **The `.zip` files in this repo** — left as they are, so the only things in
  this branch are files you actually need to paste.

## How to check it worked

1. Open nagalandai.com and send a normal chat message — should be exactly as
   before.
2. Switch to Student Mode and ask a question — exactly as before.
3. Ask the AI "can you talk to me?" — it should now say it is text only and
   offer written English practice, instead of pointing at the app.
4. Ask it a Naga word ("what is 'thank you' in Ao?") — the dictionary lookup
   should still work, proving the ecosystem connector is intact.
5. Check your ElevenLabs usage page over the next day — it should stop moving.

If anything looks wrong, put your backed-up files back; the changes are
independent of each other, so you can revert one file without the others.

## Verification done before pushing

- All 4 files pass `php -l` (PHP 8.4) with no syntax errors.
- Each edit was applied by exact-text match with a "must match exactly once"
  assertion, so nothing was changed by accident.
- No dangling variables left behind in `chat.php` (`$VOICE_LIMITS`,
  `$voiceFile`, `$voiceCount`, `$voiceLimit`, `$canUpgrade`, `$limitReply`
  are all gone, 0 references).
- No `require` of the deleted `nai_speaking.php` remains — only changelog
  comments mention the name.
- The stub was run against a live PHP server: POST returns
  `{"fallback":true,"reason":"retired"}`, `OPTIONS` returns 204.
- Confirmed the website never called the TTS endpoint: `nai_chatpage.js` and
  `nai_homepage.js` only ever call `chat.php`, `nai_init.php` and
  `nai_memory_api.php`.

**Total removed from the server: 685 lines, plus 3.1 MB of cached audio.**
