# Communication Module — Livestream Plan

> Status: PLANNING. Nothing implemented yet. This file is the single source of truth for the
> Livestream feature in the `ugocosmo` Communication module. Update it as we build.

## 0. Context — what already exists (reuse, don't rebuild)

The module **already integrates LiveKit** via the `Agence104\LiveKit` PHP SDK:
- `CallService::generateLiveKitToken(User, roomName)` mints a `VideoGrant` with
  `setRoomJoin(true)`, `setCanPublish(true)`, `setCanSubscribe(true)`, `setCanPublishData(true)`.
- `CallService` manages `CallLog` (one row per call) + `CallParticipant` (per-user row).
- Broadcasting is wired: `user.{id}` and `call.{conversationId}` channels in `routes/channels.php`.
- Webhook receiver already exists: `CallWebhookController::handleLiveKitWebhook` verifies
  LiveKit signatures (`config('communication.livekit.api_key/api_secret/webhook_token')`).

**Livestream is NOT a new media engine** — it is a *broadcast-mode call*: one publisher, many
subscribers. We extend the existing Call machinery with a `stream` subtype instead of a second
stack. This keeps token minting, webhooks, and broadcast channels identical.

## 1. Core model decision

Treat a livestream as `CallLog` where `call_type = 'stream'` (Option A, see §3).
Room = LiveKit room. Host = publisher. Viewers = subscribers (`canPublish=false`).

We reuse `generateLiveKitToken()` but pass a **grant mode**:
- host  -> `canPublish=true,  canSubscribe=true`
- viewer-> `canPublish=false, canSubscribe=true`

This requires a tiny change to `generateLiveKitToken()` to accept a `$mode` param
('host' | 'viewer'). No new SDK, no new vendor.

## 2. Answers to the open questions

### Q1: New controller? New service?
- **No new service.** Livestream logic lives in `CallService` (add `startStream`,
  `joinStream`, `endStream` methods that wrap the existing call plumbing).
- **One new controller:** `LivestreamController` (groups stream endpoints, same shape as `CallController`).
- Reuse `CallWebhookController` for LiveKit room events — already built.

### Q2: How many APIs?  (~6-7)
| Method | URI | Purpose | Reuses |
|--------|-----|---------|---------|
| POST | `/livestreams/start`        | Host starts stream -> CallLog(call_type=stream), mints host token (publish) | `initiateCall` logic |
| POST | `/livestreams/{id}/join`  | Viewer joins -> mints viewer token (subscribe-only) | `joinCall` logic |
| POST | `/livestreams/{id}/leave` | Viewer/host leaves | `leaveCall` logic |
| POST | `/livestreams/{id}/end`   | Host ends stream (closes room) | `endCall` logic |
| GET  | `/livestreams/active`      | List currently-live streams (discovery feed) | new query |
| GET  | `/livestreams/{id}`        | Stream details + viewer count | `getCall` logic |
| POST | `/livestreams/webhooks/livekit` | LiveKit room lifecycle (already exists as `/webhooks/livekit`) | `CallWebhookController` |

### Q3: Events — DEDICATED, no reuse (per your decision)
We do NOT reuse `CallInitiated` / `CallEnded`. Three new stream-specific events:

- **`LivestreamStarted`** — fired on host start. Payload: stream id, host, title,
  room, thumbnail, started_at. Broadcast on `user.{id}` (notify followers "X went live")
  + public `livestreams` channel (discovery feed insert).
- **`LivestreamEnded`** — fired on host end. Payload: stream id, duration, ended_by,
  final viewer count. Broadcast on `user.{id}` + public `livestreams` channel (feed remove).
- **`LivestreamUpdated`** — NEEDED (you confirmed the "X viewers watching" ticker).
  Fired on every viewer join/leave while live. Payload: stream id, viewer_count,
  joined/left user id + name, action.
  WHY NEEDED: the ticker shows "X watching" to people who are NOT in the LiveKit
  room (profile page, discovery card, push). LiveKit's native room callbacks only
  reach in-room participants — they cannot update a public viewer count for outsiders.
  So server-authoritative `viewer_count` (cached on call_logs) must be broadcast via
  this event to the public `livestreams` channel.
  -> Updates `call_logs.viewer_count` (cached) + fires on `livestreams` channel.

Broadcast targets:
- `user.{id}` — per-user pushes (follower "went live" notifications), as you specified.
- public `livestreams` channel — discovery feed live updates.
- Keep `call.{conversationId}` for in-call controls (unchanged).

### Q4: Recording / HLS (egress)?
- **NOT NEEDED** (per your decision). No `LivestreamEgressService`, no egress config.
- Side benefit: your 8GB/2vCPU EC2 is freed from egress CPU load — pure SFU only.

## 3. Data model — Option A (chosen)

Add `call_type = 'stream'` to existing `CallLog`. Add nullable columns to `call_logs`:
  `title VARCHAR(191) NULL`, `thumbnail_url VARCHAR(512) NULL`, `is_live TINYINT(1) DEFAULT 0`.

Pros: reuses `CallParticipant`, webhooks, tokens, all `CallService` methods.
Cons: `CallLog` semantics broaden — acceptable.

## 4. Scaling note (100 go-live x 10 viewers = 1000 conns)

- LiveKit SFU load is CPU-bound. One 2vCPU/8GB node ~ 400-1000 subs safely.
- 1000 concurrent needs **multi-node + Redis** (LiveKit clustering) OR **LiveKit Cloud** (autoscale).
- Single EC2 cannot host 100 simultaneous streams. Plan: self-host single node for dev/low-scale;
  move heavy scale to LiveKit Cloud or clustered nodes. (No egress on this box anyway.)

## 5. Build order (phased)

1. **Token grant mode** — extend `generateLiveKitToken(User, roomName, mode)` with host/viewer.
2. **Migration** — add `title`, `thumbnail_url`, `is_live` to `call_logs` (Option A).
3. **Events** — create `LivestreamStarted`, `LivestreamEnded` (+ listener if needed).
4. **LivestreamController** + routes (§2 table).
5. **Service methods** — `startStream`, `joinStream`, `endStream`, `activeStreams` in `CallService`.
6. **Broadcast** — fire `LivestreamStarted`/`LivestreamEnded` on `user.{id}` +
   public `livestreams` channel; fire `LivestreamUpdated` (viewer_count) on join/leave.
7. **Discovery feed** — `GET /livestreams/active` (where `is_live=1`).

## 6. Decisions locked
- [x] Dedicated events `LivestreamStarted` + `LivestreamEnded` (NO reuse of Call events)
- [x] `LivestreamUpdated` NEEDED (X viewers watching ticker for non-participants)
- [x] No recording / egress
- [x] Option A (extend `call_logs`, add `call_type='stream'`) — DONE
- [ ] Self-host single node now, or LiveKit Cloud key from day 1?  (NOT blocking; build is engine-agnostic)
- [ ] Discovery feed channel name confirmed: public `livestreams` channel + `user.{id}`

## 7. Files created / changed (implementation done)

### New files
- `database/migrations/2026_07_15_000001_add_livestream_columns_to_call_logs.php`
  - `call_type` enum -> `('audio','video','stream')`
  - cols: `title`, `thumbnail_url`, `is_live`, `viewer_count`
  - index `(is_live, started_at)` for discovery feed
- `app/Events/LivestreamStarted.php`  (ShouldBroadcastNow; user.{id} + public `livestreams`)
- `app/Events/LivestreamEnded.php`    (same channels)
- `app/Events/LivestreamUpdated.php`  (public `livestreams`; viewer_count ticker)
- `app/Http/Controllers/LivestreamController.php`

### Changed files
- `app/Services/CallService.php`
  - `generateLiveKitToken(User, roomName, mode='host')` -> host publish / viewer subscribe-only
  - new: `startStream`, `joinStream`, `leaveStream`, `endStream`, `activeStreams`
  - helpers: `buildStreamRoomName`, `buildStreamPayload`, `broadcastStreamUpdated`, `followerIds`
- `app/Models/CallLog.php` -> fillable + casts for new cols
- `routes/api.php` -> `LivestreamController` routes
- `routes/channels.php` -> public `livestreams` channel

## 8. MIGRATE command (run on server — needs PHP + DB creds)

```
cd /var/www/ugocosmo   # or your project root
php artisan migrate --path=Modules/Communication/database/migrations/2026_07_15_000001_add_livestream_columns_to_call_logs.php
php artisan route:clear && php artisan config:clear
```

> NOTE: this WSL box has NO php binary, so the migrate must run on the AWS/server.
> All code is written + structurally validated (brace/paren balance OK).

## 9. VERIFICATION PASS — flows + webhook gap (done)

### Flow matrix (verified against code)
| Flow | DB | Event | Channel | Status |
|------|----|-------|---------|--------|
| startStream | CallLog(is_live=true) | LivestreamStarted | user.{id} + livestreams | OK |
| joinStream | viewer_count++, participant joined | LivestreamUpdated | livestreams | OK |
| leaveStream | viewer_count-- | LivestreamUpdated | livestreams | OK |
| endStream (host API) | is_live=false, viewer_count=0 | LivestreamEnded | user.{id} + livestreams | OK |
| Webhook: host drop (dead phone/offline) | is_live=false, viewer_count=0 | LivestreamEnded | user.{id} + livestreams | FIXED |
| Webhook: viewer drop (app crash) | viewer_count-- kept live | LivestreamUpdated | livestreams | FIXED |
| Webhook: room_finished | is_live=false | LivestreamEnded | user.{id} | FIXED |

### Bugs found + fixed in handleLiveKitWebhook
1. **Critical**: webhook closed a stream but fired `CallEnded` on `call.{conversationId}`
   instead of `LivestreamEnded` on `user.{id}` -> followers never got "stream ended".
   FIX: branch on `call_type==='stream'` -> fire `LivestreamEnded` + reset is_live/viewer_count.
2. **Medium**: `shouldClose` required `num_participants===0`. On a livestream the HOST
   (publisher) dropping while viewers remain would NOT close the stream.
   FIX: detect host drop via `participant.identity == initiator_id` -> force close.
3. **Hygiene**: webhook path never reset `is_live`/`viewer_count`. FIX: reset in both
   close branches; viewer-disconnect branch decrements count + ticker.

### is_online / offline note
- `is_online` is NOT a DB column. It is a **Cache** key (`communication:user:online:{id}`,
  TTL 5m) set by `TrackUserOnline` middleware. "User offline" is cache-expiry driven.
- The authoritative stream-lifecycle signal is the **LiveKit webhook**
  (`participant_disconnected` / `room_finished`), which we now handle for streams.
  No DB `is_online` flag is needed.

### Open (not blocking)
- `endCallRoom()` is called for streams too; verify it tolerates a host-only room cleanly
  (LiveKit removes the single publisher). Low risk.
- Webhook signature: ensure `communication.livekit.webhook_token` (or api_key/secret) is set
  on the server so LiveKit-signed webhooks are accepted; unsigned -> ignored.

## 10. SECOND CROSS-CHECK (after first fixes) — 2 more bugs found + fixed

### Bug 4 (critical): wrong follower relation
- `followerIds()` used `App\Models\Follow` (does NOT exist) with columns
  `following_id`/`follower_id`. The app's real follow graph is
  `UserConnection` (table `user_connections`): follower = row where
  `recipient_id = host` AND `connection_type = 'follow'`, `requester_id` = follower.
- Impact: `class_exists()` guard returned `[]` -> **every stream start/end notified ZERO
  followers**. Silent failure.
- FIX: query `UserConnection` where `recipient_id = userId` and `connection_type='follow'`,
  pluck `requester_id`.

### Bug 5 (critical): GET /livestreams/{id} 403'd followers
- Controller `show()` called `getCall()` -> `activeConversationParticipant()` which throws
  `AuthorizationException('Conversation not found.')` for anyone not in the conversation.
  A follower watching from the discovery feed is NOT a conversation member -> 403.
- FIX: added `getStream()` (loads CallLog by id, no conversation-membership check) and pointed
  `show()` to it. Uses `sendError` (was `sendResponse($exception,...)` which leaked the
  exception object to the client).

### Remaining notes (non-blocking)
- Fan-out: `LivestreamStarted`/`Ended` open one `user.{id}` channel per follower. Fine for
  moderate follower counts; for 10k+ followers consider a follower-batch/pagination later.
- `endCallRoom()` is invoked for streams on webhook close — verify it tolerates a host-only
  room (low risk; LiveKit removes the single publisher).
- Webhook auth: server must set `COMMUNICATION_LIVEKIT_WEBHOOK_TOKEN` (or api_key+secret) or
  LiveKit-signed webhooks are ignored (`ignored => true`).

### Final verification matrix (all green)
| Flow | DB | Event | Channel | Status |
|------|----|-------|---------|--------|
| startStream | is_live=true, viewer_count=0 | LivestreamStarted | user.{id}+livestreams | OK |
| joinStream | viewer_count++ , participant joined | LivestreamUpdated | livestreams | OK |
| leaveStream | viewer_count-- | LivestreamUpdated | livestreams | OK |
| endStream (API) | is_live=false, viewer_count=0 | LivestreamEnded | user.{id}+livestreams | OK |
| webhook: host drop | is_live=false, viewer_count=0 | LivestreamEnded | user.{id}+livestreams | OK (fix 1-3) |
| webhook: viewer drop | viewer_count-- (kept live) | LivestreamUpdated | livestreams | OK (fix 3) |
| webhook: room_finished | is_live=false | LivestreamEnded | user.{id} | OK (fix 1) |
| GET /livestreams/active | reads is_live=1 | - | - | OK |
| GET /livestreams/{id} | - | - | - | OK (fix 5, no 403) |
| follower notify | UserConnection graph | - | - | OK (fix 4) |

## 11. POSTMAN / API TEST REFERENCE (for tomorrow's live test)

> Base URL: `https://your-domain/api/communication`
> All stream endpoints need header: `Authorization: Bearer <user_token>`
> (auth middleware = `COMMUNICATION_API_AUTH_MIDDLEWARE`, default `auth:api`)
> Content-Type: `application/json`

### A. Host starts a livestream
`POST /livestreams/start`

Request body:
{
  "conversation_id": 12,          // int, REQUIRED — the conversation hosting the stream
  "title": "My first live!",      // string, REQUIRED
  "thumbnail_url": "https://.../cover.jpg"  // string|null, OPTIONAL
}

Success (201):
{
  "success": true,
  "message": "Livestream started",
  "data": {
    "id": 55,
    "call_type": "stream",
    "title": "My first live!",
    "thumbnail_url": "https://.../cover.jpg",
    "room_name": "conv_12_stream_55",
    "is_live": true,
    "viewer_count": 0,
    "status": "ongoing",
    "started_at": "2026-07-20T10:00:00+00:00",
    "token": "<LiveKit JWT — host publish grant>",
    "user_id": 3
  }
}

### B. Viewer joins
`POST /livestreams/{id}/join`    e.g. `/livestreams/55/join`
Body: empty `{}`
Success (200): same shape as above but `token` is a **subscribe-only** grant,
`viewer_count` incremented by 1.

### C. Viewer leaves
`POST /livestreams/{id}/leave`
Body: empty `{}`
Success (200): stream payload, `viewer_count` decremented.

### D. Host ends
`POST /livestreams/{id}/end`
Body: empty `{}`
Success (200): `is_live=false`, `viewer_count=0`, `status=completed`.
Fires `LivestreamEnded` on `user.{id}` (followers) + `livestreams`.

### E. Discovery feed (active streams)
`GET /livestreams/active`
Success (200): array of
{
  "id": 55,
  "title": "My first live!",
  "thumbnail_url": "...",
  "viewer_count": 4,
  "started_at": "2026-07-20T10:00:00+00:00",
  "host": { "id": 3, "first_name": "Sifat", "last_name": "", "avatar": null }
}

### F. Stream detail (followers can view too)
`GET /livestreams/{id}`
Success (200): same payload as start but `token: null` (no join token).

### G. Expected realtime events (websocket)
| Event | Channel | Fires when |
|-------|---------|-----------|
| `LivestreamStarted` | `user.{followerId}` + `livestreams` | host starts |
| `LivestreamUpdated` | `livestreams` | viewer joins/leaves (viewer_count changes) |
| `LivestreamEnded` | `user.{followerId}` + `livestreams` | host ends / host drops (webhook) |

### H. LiveKit webhook (server-to-server, NOT Postman-auth)
`POST /api/communication/webhooks/livekit`
Headers: `Authorization: Bearer <livekit_api_secret>` OR `x-livekit-webhook-token: <token>`
Body (host dropped) example:
{
  "event": "participant_disconnected",
  "room": { "name": "conv_12_stream_55", "num_participants": 1 },
  "participant": { "identity": "3" }
}
Expected: stream closes, `is_live=false`, `LivestreamEnded` fired to followers.

### I. Test checklist (tomorrow)
- [ ] migrate ran (new cols exist in call_logs)
- [ ] Host `start` → got `token` + `room_name`
- [ ] 2nd user `join` → got viewer `token`, `viewer_count=1`
- [ ] `LivestreamUpdated` received on `livestreams` channel
- [ ] Both clients connect to LiveKit with their tokens (A publishes, B sees video)
- [ ] Host `end` → `LivestreamEnded` on `user.{id}` of a follower
- [ ] Simulate host crash → POST webhook `participant_disconnected` identity=host → stream ends
- [ ] `GET /livestreams/active` empty after end

## 12. EXACT EVENT-FIRING CONDITIONS (code truth)

Each event fires ONLY when its condition below is met. Conditions are taken verbatim
from CallService / webhook logic.

### LivestreamStarted
Fires in `startStream()` immediately after the CallLog row is created + host token minted.
CONDITION (always, on success):
  - CallLog created with call_type='stream', is_live=true, status='ongoing'
  - (no extra guard — runs every successful start)
Broadcasts on: `user.{followerId}` for EACH follower (from UserConnection) + `livestreams`
Payload field `token` = HOST publish JWT.

### LivestreamUpdated
Fires in TWO places, both guarded:

(1) `joinStream()` — ONLY when it is a NEW join:
    CONDITION: CallParticipant for (call_log_id, user_id) does NOT already have status='joined'
               (re-joins / already-joined viewers do NOT re-fire)
    Action: viewer_count++, then broadcastStreamUpdated(action='joined')

(2) `leaveStream()` — ONLY when participant was 'joined':
    CONDITION: CallParticipant exists AND status === 'joined'
    Action: status='left', viewer_count--, broadcastStreamUpdated(action='left')

(3) Webhook viewer drop — ONLY for a stream + a non-host leave event:
    CONDITION: call_type='stream'
              AND eventType IN (participant_left, participant_disconnected, participant_left_room)
              AND NOT hostLeft
              AND participant.identity != ''
    Action: viewer_count--, broadcastStreamUpdated(action='left')  [stream stays live]
Broadcasts on: `livestreams` (public) ONLY — no per-user channel.
Payload: { id, viewer_count, action, user_id }.

### LivestreamEnded
Fires in TWO places:

(1) `endStream()` (host API) — guarded:
    CONDITION: call_type='stream' AND (int)callLog.initiator_id === (int)host.id
              (non-host calling endStream -> AuthorizationException, no event)
    Action: is_live=false, viewer_count=0, finalizeCall('completed'), THEN fire.
    Broadcast on: `user.{followerId}` + `livestreams`.

(2) Webhook close — guarded:
    CONDITION: call_type='stream' AND the webhook decided to CLOSE the room, where CLOSE happens if:
       - eventType IN (room_finished, room_deleted, room_ended, room_closed, roomfinished)
         OR (leaveEvent AND num_participants === 0)
         OR hostLeft  [hostLeft = call_type='stream' AND participant.identity === (string)initiator_id]
    Action: finalizeCall -> is_live=false, viewer_count=0, save -> THEN fire LivestreamEnded
            (NOT CallEnded — that branch is only for non-stream calls)
    Broadcast on: `user.{followerId}` + `livestreams`.

### What does NOT fire an event
- Re-join by an already-'joined' viewer -> no LivestreamUpdated (count unchanged).
- Webhook leaveEvent with num_participants > 0 and NOT host -> no close, no LivestreamEnded
  (stream stays live; only LivestreamUpdated if it was a stream viewer drop).
- Webhook event type not in roomClosed/leave lists and not hostLeft -> ignored, no event.
- Non-stream calls still fire the OLD CallInitiated / CallLifecycleUpdated / CallEnded
  (untouched by livestream changes).

### Summary table
| Event | Trigger | Channels | Notes |
|-------|---------|----------|-------|
| LivestreamStarted | successful startStream | user.{follower}+livestreams | every start |
| LivestreamUpdated | NEW join / joined-leave / stream viewer webhook drop | livestreams only | count ticker |
| LivestreamEnded | endStream by host / webhook close (host drop or room finished) | user.{follower}+livestreams | is_live=false |

## 13. HOST-DROP GRACE PERIOD (added after review)

Problem: original code ended the stream the instant the host's LiveKit
`participant_disconnected` arrived. A 3-5s network blip would kill the stream.

Fix: 20s grace window (config `communication.livestream_host_grace_seconds`, default 20).

Flow:
1. Webhook gets host drop (LiveKit or Agora) on a stream -> sets cache key
   `livestream:host_grace:{streamId}` + dispatches `LivestreamHostGraceJob`
   delayed by graceSeconds. Returns `grace_started=true` (does NOT end yet).
2. If host reconnects within window (joinStream with initiator_id):
   - cache key forgotten -> pending job becomes a no-op (job checks key on run)
   - host gets a HOST (publish) token -> stream stays live
3. If host does NOT return: job runs `endStreamSafely()` -> is_live=false,
   viewer_count=0, finalizeCall, fires `LivestreamEnded` to user.{follower}+livestreams.

Files:
- NEW `app/Jobs/LivestreamHostGraceJob.php` (ShouldQueue, pattern like HandleMiscallJob)
- `CallService::endStreamSafely()` (webhook/job-safe, no auth guard)
- webhook (LiveKit + Agora) host-drop branch -> grace dispatch
- `joinStream()` -> host rejoin cancels grace + gets publish token

### Updated LivestreamEnded firing condition
- endStream (host API): host calls end -> immediate LivestreamEnded
- webhook host drop: NOT immediate -> after grace window if host does not return ->
  LivestreamEnded (via LivestreamHostGraceJob -> endStreamSafely)
- webhook room_finished (room deleted server-side): immediate LivestreamEnded
  (grace only covers participant_disconnected of the host, not full room teardown)

### Queue requirement
LivestreamHostGraceJob is `ShouldQueue` -> needs a running queue worker:
  php artisan queue:work
(or it won't run and the stream will never auto-end after a host drop!).
If you do NOT run a queue worker, use `->afterResponse()` dispatch instead of queued.

## 14. FINAL RE-CHECK (after grace-period addition) — ALL GREEN

Re-verified 2026-07-19: every livestream file read end-to-end, string-aware brace/paren/
bracket balance = 0 on all 7 files, User imported, no stale refs.

| File | Status |
|------|--------|
| database/migrations/2026_07_15_000001_..._call_logs.php | OK (enum stream + 4 cols + index) |
| app/Models/CallLog.php | OK (fillable + casts) |
| app/Services/CallService.php | OK (balance 0/0/0; start/join/leave/end/active/getStream/endStreamSafely/followerIds) |
| app/Http/Controllers/LivestreamController.php | OK (balance 0/0/0; 6 endpoints -> getStream) |
| app/Events/LivestreamStarted.php | OK |
| app/Events/LivestreamEnded.php | OK |
| app/Events/LivestreamUpdated.php | OK |
| app/Jobs/LivestreamHostGraceJob.php | OK (ShouldQueue, grace logic) |
| routes/api.php | OK (6 routes) |
| routes/channels.php | OK (livestreams public + user.{id}) |

### Flow matrix (final)
| Flow | DB | Event | Grace |
|------|----|-------|-------|
| startStream | is_live=true | LivestreamStarted (user.{id}+livestreams) | - |
| joinStream (new viewer) | viewer_count++ | LivestreamUpdated (livestreams) | - |
| joinStream (host rejoin in grace) | grace cancelled, host gets publish token | (no event, stream stays live) | cancels |
| leaveStream | viewer_count-- | LivestreamUpdated | - |
| endStream (host API) | is_live=false | LivestreamEnded | - |
| webhook host drop | cache+grace job | (none yet) | 20s window |
| grace expires (no rejoin) | is_live=false | LivestreamEnded | fires end |
| webhook room_finished | is_live=false | LivestreamEnded | immediate |
| webhook viewer drop | viewer_count-- | LivestreamUpdated | stream live |
| GET /active | reads is_live=1 | - | - |
| GET /{id} | - | - | no 403 |

### Remaining server-side prerequisites (cannot test from WSL)
1. `php artisan migrate` (new cols) — run on server.
2. `php artisan queue:work` — REQUIRED for LivestreamHostGraceJob (else host-drop won't auto-end).
3. LiveKit server reachable + `COMMUNICATION_LIVEKIT_API_KEY/API_SECRET` set.
4. LiveKit webhook URL = `/api/communication/webhooks/livekit` registered + `COMMUNICATION_LIVEKIT_WEBHOOK_TOKEN` set (else webhook ignored).
5. Config `communication.livestream_host_grace_seconds` (default 20) — optional override.

### Verdict
Code-complete, structurally valid, logically consistent. Ready for live test on server.
