<?php
// ============================================================
//  REPUBLICKA CHAT v4
//
//  SERVER  = relay only. Messages held 5 min then purged.
//            If you weren't online you won't see old messages.
//  CLIENT  = localStorage is the real permanent store.
//            No message cap — user clears when they want.
//
//  Admin panel at /chat.php?admin  (password below)
// ============================================================

define('LOG_FILE',    __DIR__ . '/chat-relay.json');
define('ONLINE_FILE', __DIR__ . '/chat-online.json');
define('BAN_FILE',    __DIR__ . '/chat-bans.json');
define('MAX_NAME',    24);
define('MAX_MSG',     400);
define('RATE_LIMIT',  1.5);
define('ONLINE_TTL',  20);
define('RELAY_TTL',   300);     // 5 min relay window
define('ADMIN_PASS',  'password');  // ← your admin password

// ── API ───────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $action = $_GET['api'] ?? '';
    $uname  = trim(strip_tags($_GET['u'] ?? ''));

    if ($uname && $uname !== '__system__') update_online($uname);

    // Poll
    if ($action === 'poll') {
        $since = isset($_GET['since']) ? (int)$_GET['since'] : time_ms();
        $relay = purge_relay(read_json(LOG_FILE));
        write_json(LOG_FILE, $relay);
        $fresh = array_values(array_filter($relay, fn($m) => $m['t'] > $since));
        echo json_encode(['ok'=>true,'msgs'=>$fresh,'online'=>read_online(),'t'=>time_ms()]);
        exit;
    }

    // Post
    if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $name = trim(strip_tags($body['name'] ?? ''));
        $text = trim(strip_tags($body['text'] ?? ''));

        if (!$name || !$text)            die(json_encode(['ok'=>false,'err'=>'Empty']));
        if (mb_strlen($name) > MAX_NAME) die(json_encode(['ok'=>false,'err'=>'Name too long']));
        if (mb_strlen($text) > MAX_MSG)  die(json_encode(['ok'=>false,'err'=>'Message too long']));

        if ($name !== '__system__' && is_banned($name))
            die(json_encode(['ok'=>false,'err'=>'You have been banned from this room']));

        $ip   = md5($_SERVER['REMOTE_ADDR'] ?? 'x');
        $mark = sys_get_temp_dir() . "/rkchat_{$ip}";
        if ($name !== '__system__' && file_exists($mark) && (microtime(true) - filemtime($mark)) < RATE_LIMIT)
            die(json_encode(['ok'=>false,'err'=>'Slow down']));
        touch($mark);

        $relay   = purge_relay(read_json(LOG_FILE));
        $relay[] = ['t' => time_ms(), 'n' => $name, 'm' => $text];
        write_json(LOG_FILE, $relay);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Leave
    if ($action === 'leave') {
        remove_online($uname);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Admin gate
    $pass = $_SERVER['HTTP_X_ADMIN_PASS'] ?? '';
    if ($pass !== ADMIN_PASS) die(json_encode(['ok'=>false,'err'=>'Unauthorized']));

    if ($action === 'admin_stats') {
        echo json_encode([
            'ok'     => true,
            'relay'  => purge_relay(read_json(LOG_FILE)),
            'online' => read_online(),
            'bans'   => read_json(BAN_FILE),
        ]);
        exit;
    }
    if ($action === 'admin_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        write_json(LOG_FILE, []);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $t    = (int)($body['t'] ?? 0);
        $msgs = array_values(array_filter(read_json(LOG_FILE), fn($m) => $m['t'] !== $t));
        write_json(LOG_FILE, $msgs);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'admin_ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true);
        $bname = trim(strip_tags($body['name'] ?? ''));
        if (!$bname) die(json_encode(['ok'=>false,'err'=>'No name']));
        $bans  = read_json(BAN_FILE);
        if (!in_array(strtolower($bname), array_map('strtolower', array_column($bans, 'n'))))
            $bans[] = ['n'=>$bname,'t'=>time()];
        write_json(BAN_FILE, $bans);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'admin_unban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body  = json_decode(file_get_contents('php://input'), true);
        $bname = strtolower(trim(strip_tags($body['name'] ?? '')));
        $bans  = array_values(array_filter(read_json(BAN_FILE), fn($b) => strtolower($b['n']) !== $bname));
        write_json(BAN_FILE, $bans);
        echo json_encode(['ok'=>true]);
        exit;
    }
    if ($action === 'admin_kick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        remove_online(trim(strip_tags($body['name'] ?? '')));
        echo json_encode(['ok'=>true]);
        exit;
    }

    die(json_encode(['ok'=>false,'err'=>'Unknown action']));
}

// ── Helpers ───────────────────────────────────────────────────
function time_ms(): int { return (int)(microtime(true) * 1000); }
function read_json(string $f): array { return file_exists($f) ? (json_decode(@file_get_contents($f), true) ?? []) : []; }
function write_json(string $f, array $d): void { file_put_contents($f, json_encode($d), LOCK_EX); }
function purge_relay(array $msgs): array {
    $cutoff = (time() - RELAY_TTL) * 1000;
    return array_values(array_filter($msgs, fn($m) => $m['t'] > $cutoff));
}
function is_banned(string $name): bool {
    return in_array(strtolower($name), array_map('strtolower', array_column(read_json(BAN_FILE), 'n')));
}
function read_online(): array {
    $cutoff = time() - ONLINE_TTL;
    return array_values(array_filter(read_json(ONLINE_FILE), fn($u) => $u['t'] > $cutoff));
}
function update_online(string $name): void {
    $cutoff = time() - ONLINE_TTL;
    $users  = array_values(array_filter(read_json(ONLINE_FILE), fn($u) => $u['t'] > $cutoff && $u['n'] !== $name));
    $users[]= ['n'=>$name,'t'=>time()];
    write_json(ONLINE_FILE, $users);
}
function remove_online(string $name): void {
    write_json(ONLINE_FILE, array_values(array_filter(read_json(ONLINE_FILE), fn($u) => $u['n'] !== $name)));
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>REPUBLICKA — Live Chamber</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;900&family=Crimson+Text:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<style>
/* ── Themes ─────────────────────────────────────────────── */
[data-theme="dark"]{
  --bg:#0a0805;--bg2:#110e08;--bg3:#181208;--bg4:#1f1809;
  --border:#2c2010;
  --gold:#c9a84c;--gold-dim:#7a6128;--gold-glow:#e8c86a;--gold-bg:rgba(201,168,76,.06);
  --text:#d4b96a;--text-dim:#6a5a32;--text-body:#c0a858;
  --online:#5dbd7a;--danger:#c0392b;--warn:#c08a39;
  --sys-bg:rgba(201,168,76,.10);--sys-text:#7a6838;
  --mine-bg:rgba(201,168,76,.05);--input-bg:#0f0c06;
  --overlay:rgba(0,0,0,.65);--shadow:0 4px 24px rgba(0,0,0,.6);
  --panel-bg:#0d0a05;--row-bg:rgba(201,168,76,.03);
}
[data-theme="light"]{
  --bg:#faf6ed;--bg2:#f2ebda;--bg3:#ebe3cf;--bg4:#e2d8c0;
  --border:#cfc099;
  --gold:#6b4f08;--gold-dim:#9a7620;--gold-glow:#4a3605;--gold-bg:rgba(107,79,8,.05);
  --text:#2e2208;--text-dim:#8a7040;--text-body:#241a06;
  --online:#2e7a46;--danger:#a82820;--warn:#8a5a10;
  --sys-bg:rgba(107,79,8,.08);--sys-text:#8a7030;
  --mine-bg:rgba(107,79,8,.04);--input-bg:#f8f3e8;
  --overlay:rgba(0,0,0,.45);--shadow:0 4px 24px rgba(80,50,0,.15);
  --panel-bg:#f5f0e4;--row-bg:rgba(107,79,8,.03);
}

/* ── Reset ──────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;height:100dvh;overflow:hidden}
body{background:var(--bg);color:var(--text);font-family:'Crimson Text',Georgia,serif;font-size:17px;line-height:1.5;transition:background .3s,color .3s}
*{scrollbar-width:thin;scrollbar-color:var(--border) transparent}
*::-webkit-scrollbar{width:4px}
*::-webkit-scrollbar-track{background:transparent}
*::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px}
button{font-family:inherit}

/* ── App shell ──────────────────────────────────────────── */
#app{display:flex;flex-direction:column;height:100%;height:100dvh;max-width:1040px;margin:0 auto;border-left:1px solid var(--border);border-right:1px solid var(--border)}

/* ── Header ─────────────────────────────────────────────── */
header{display:flex;align-items:center;gap:10px;padding:11px 18px;border-bottom:1px solid var(--border);background:var(--bg2);flex-shrink:0;position:relative;z-index:20;min-height:54px}
header::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--gold-dim),transparent);pointer-events:none}
.h-sigil{font-size:22px;color:var(--gold);flex-shrink:0;text-shadow:0 0 14px currentColor;user-select:none}
.h-titles{flex:1;min-width:0}
.h-title{font-family:'Cinzel',serif;font-size:13px;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.h-sub{font-size:11px;color:var(--text-dim);letter-spacing:.06em}
@media(max-width:479px){.h-sub{display:none}}
.h-btn{background:none;border:1px solid var(--border);color:var(--text-dim);width:36px;height:36px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;transition:border-color .2s,color .2s,background .2s}
.h-btn:hover,.h-btn.active{border-color:var(--gold-dim);color:var(--gold);background:var(--gold-bg)}

/* ── Main ───────────────────────────────────────────────── */
#main{display:flex;flex:1;overflow:hidden;position:relative}
#col-messages{display:flex;flex-direction:column;flex:1;overflow:hidden;min-width:0}

/* ── Message list ───────────────────────────────────────── */
#messages{flex:1;overflow-y:auto;padding:14px 14px 8px;display:flex;flex-direction:column;gap:2px;scroll-behavior:smooth;overscroll-behavior:contain}

.msg-date{text-align:center;font-family:'Cinzel',serif;font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:var(--text-dim);margin:10px 0 4px;position:relative}
.msg-date::before,.msg-date::after{content:'';position:absolute;top:50%;width:26%;height:1px;background:var(--border)}
.msg-date::before{left:0}.msg-date::after{right:0}

.msg-system{text-align:center;max-width:fit-content;margin:5px auto;font-family:'Cinzel',serif;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--sys-text);background:var(--sys-bg);border-radius:20px;padding:4px 14px}

.msg{display:grid;grid-template-columns:36px 1fr;grid-template-rows:auto auto;column-gap:10px;padding:6px 10px;border-radius:6px;transition:background .15s}
.msg:hover{background:var(--gold-bg)}
.msg.mine{background:var(--mine-bg)}
.msg.mine:hover{background:var(--gold-bg)}
.msg-avatar{grid-row:1/3;width:34px;height:34px;border-radius:50%;border:1px solid var(--border);background:var(--bg3);display:flex;align-items:center;justify-content:center;font-family:'Cinzel',serif;font-size:13px;font-weight:600;color:var(--text-dim);flex-shrink:0;user-select:none;margin-top:3px}
.msg.mine .msg-avatar{border-color:var(--gold-dim);color:var(--gold)}
.msg-meta{display:flex;align-items:baseline;gap:7px;min-width:0}
.msg-name{font-family:'Cinzel',serif;font-size:12px;font-weight:600;letter-spacing:.08em;color:var(--gold-dim);white-space:nowrap}
.msg.mine .msg-name{color:var(--gold)}
.msg-time{font-size:11px;color:var(--text-dim);white-space:nowrap}
.msg-badge{font-size:10px;color:var(--text-dim);opacity:.5;font-family:'Cinzel',serif;text-transform:uppercase;letter-spacing:.08em}
.msg-text{font-size:16px;line-height:1.55;color:var(--text-body);word-break:break-word}
@keyframes msgIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.msg-new{animation:msgIn .22s ease-out}

/* ── Composer ───────────────────────────────────────────── */
#composer-wrap{border-top:1px solid var(--border);background:var(--bg2);flex-shrink:0;position:relative}
#composer-wrap::before{content:'';position:absolute;top:-1px;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--gold-dim),transparent);pointer-events:none}
.composer-meta{display:flex;align-items:center;padding:8px 16px 0;gap:8px}
.c-user{font-family:'Cinzel',serif;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold-dim);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.c-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}
.c-char{font-size:12px;color:var(--text-dim);min-width:28px;text-align:right}
.c-char.warn{color:var(--warn)}.c-char.over{color:var(--danger)}
.c-link-btn{background:none;border:none;font-family:'Cinzel',serif;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--text-dim);cursor:pointer;padding:0;transition:color .2s}
.c-link-btn:hover{color:var(--gold)}.c-link-btn.danger:hover{color:var(--danger)}
#composer{display:flex;gap:8px;padding:8px 16px;padding-bottom:max(12px,env(safe-area-inset-bottom))}
#msg-input{flex:1;background:var(--input-bg);border:1px solid var(--border);color:var(--text-body);font-family:'Crimson Text',serif;font-size:17px;padding:10px 14px;border-radius:6px;outline:none;resize:none;max-height:110px;min-height:44px;line-height:1.4;transition:border-color .2s}
#msg-input::placeholder{color:var(--text-dim)}
#msg-input:focus{border-color:var(--gold-dim)}
#send-btn{padding:10px 18px;background:transparent;border:1px solid var(--gold-dim);color:var(--gold);font-family:'Cinzel',serif;font-size:12px;letter-spacing:.15em;text-transform:uppercase;cursor:pointer;border-radius:6px;transition:all .2s;flex-shrink:0;align-self:flex-end;min-height:44px}
#send-btn:hover:not(:disabled){background:var(--gold-bg);border-color:var(--gold);box-shadow:0 0 14px rgba(201,168,76,.15)}
#send-btn:disabled{opacity:.35;cursor:not-allowed}

/* ── Sidebar ────────────────────────────────────────────── */
#sidebar{width:188px;flex-shrink:0;border-left:1px solid var(--border);background:var(--bg2);display:flex;flex-direction:column;overflow:hidden}
.sidebar-head{padding:12px 14px 8px;border-bottom:1px solid var(--border);font-family:'Cinzel',serif;font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--gold-dim);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.sidebar-count{font-size:14px;font-weight:600;color:var(--gold)}
#online-list{flex:1;overflow-y:auto;padding:8px 10px;display:flex;flex-direction:column;gap:3px}
.online-user{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;transition:background .15s;overflow:hidden}
.online-user:hover{background:var(--gold-bg)}
.online-dot{width:7px;height:7px;border-radius:50%;background:var(--online);flex-shrink:0;box-shadow:0 0 5px var(--online)}
.online-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px;color:var(--text-body)}
.online-user.me .online-name{color:var(--gold)}
@media(max-width:660px){
  #sidebar{position:absolute;top:0;right:0;bottom:0;width:220px;transform:translateX(100%);z-index:30;box-shadow:var(--shadow);transition:transform .25s ease}
  #sidebar.open{transform:translateX(0)}
  #sb-overlay{display:none;position:absolute;inset:0;background:var(--overlay);z-index:25}
  #sidebar.open+#sb-overlay{display:block}
}
@media(min-width:661px){#sidebar-toggle{display:none!important}}

/* ── Join screen ────────────────────────────────────────── */
#join-screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 20px;gap:28px}
.join-sigil{display:block;text-align:center;font-size:68px;color:var(--gold);text-shadow:0 0 40px currentColor;animation:breathe 4s ease-in-out infinite;user-select:none}
@keyframes breathe{0%,100%{text-shadow:0 0 28px currentColor;opacity:.85}50%{text-shadow:0 0 58px currentColor;opacity:1}}
.join-tagline{font-family:'Cinzel',serif;font-size:12px;letter-spacing:.28em;text-transform:uppercase;color:var(--text-dim);text-align:center;margin-top:8px}
.join-form{width:100%;max-width:340px;display:flex;flex-direction:column;gap:10px}
.join-label{font-family:'Cinzel',serif;font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--gold-dim)}
.join-input{width:100%;background:var(--input-bg);border:1px solid var(--border);color:var(--text-body);font-family:'Crimson Text',serif;font-size:20px;padding:12px 16px;border-radius:6px;outline:none;transition:border-color .2s,box-shadow .2s}
.join-input::placeholder{color:var(--text-dim)}
.join-input:focus{border-color:var(--gold-dim);box-shadow:0 0 0 2px rgba(201,168,76,.08)}
.join-btn{margin-top:4px;padding:13px;background:transparent;border:1px solid var(--gold-dim);color:var(--gold);font-family:'Cinzel',serif;font-size:12px;letter-spacing:.2em;text-transform:uppercase;cursor:pointer;border-radius:6px;transition:all .2s;min-height:48px}
.join-btn:hover{background:var(--gold-bg);border-color:var(--gold);box-shadow:0 0 18px rgba(201,168,76,.12)}

/* ── Shared overlay/panel base ──────────────────────────── */
.overlay{display:none;position:fixed;inset:0;background:var(--overlay);z-index:100;align-items:center;justify-content:center;padding:16px}
.overlay.open{display:flex}
.panel{background:var(--panel-bg);border:1px solid var(--border);border-radius:10px;width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:var(--shadow)}
.panel-header{display:flex;align-items:center;gap:10px;padding:15px 20px;border-bottom:1px solid var(--border);flex-shrink:0}
.panel-title{font-family:'Cinzel',serif;font-size:13px;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);flex:1}
.panel-close{background:none;border:1px solid var(--border);color:var(--text-dim);width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all .2s}
.panel-close:hover{border-color:var(--danger);color:var(--danger)}
.panel-body{flex:1;overflow-y:auto;padding:20px}

/* ── Admin tabs ─────────────────────────────────────────── */
.ap-tabs{display:flex;border-bottom:1px solid var(--border);flex-shrink:0}
.ap-tab{padding:10px 18px;background:none;border:none;border-bottom:2px solid transparent;font-family:'Cinzel',serif;font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:var(--text-dim);cursor:pointer;transition:all .2s;margin-bottom:-1px}
.ap-tab:hover{color:var(--gold)}.ap-tab.active{color:var(--gold);border-bottom-color:var(--gold)}
.ap-pane{display:none}.ap-pane.active{display:block}

/* stats cards */
.ap-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.ap-card{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:14px;text-align:center}
.ap-card-num{font-family:'Cinzel',serif;font-size:28px;font-weight:600;color:var(--gold);display:block}
.ap-card-label{font-family:'Cinzel',serif;font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--text-dim);margin-top:2px;display:block}

/* section header */
.ap-sh{font-family:'Cinzel',serif;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--gold-dim);margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}

/* danger button */
.btn-danger{padding:8px 16px;background:transparent;border:1px solid var(--danger);color:var(--danger);font-family:'Cinzel',serif;font-size:10px;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;border-radius:5px;transition:all .2s}
.btn-danger:hover{background:rgba(192,57,43,.1)}
.btn-gold{padding:8px 16px;background:transparent;border:1px solid var(--gold-dim);color:var(--gold);font-family:'Cinzel',serif;font-size:10px;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;border-radius:5px;transition:all .2s}
.btn-gold:hover{background:var(--gold-bg)}
.btn-sm{padding:4px 10px;font-size:9px}

/* message rows in admin */
.ap-rows{display:flex;flex-direction:column;gap:4px;margin-bottom:16px}
.ap-row{display:flex;align-items:flex-start;gap:10px;background:var(--row-bg);border:1px solid var(--border);border-radius:5px;padding:8px 10px}
.ap-row-info{flex:1;min-width:0}
.ap-row-meta{font-family:'Cinzel',serif;font-size:10px;color:var(--gold-dim);letter-spacing:.08em;margin-bottom:2px}
.ap-row-text{font-size:14px;color:var(--text-dim);word-break:break-word}
.ap-row-actions{display:flex;gap:6px;align-self:center;flex-shrink:0}

/* ban input row */
.ap-input-row{display:flex;gap:8px;margin-bottom:12px}
.ap-input{flex:1;background:var(--input-bg);border:1px solid var(--border);color:var(--text-body);font-family:'Crimson Text',serif;font-size:16px;padding:8px 12px;border-radius:5px;outline:none}
.ap-input:focus{border-color:var(--gold-dim)}

/* ── Password overlay ───────────────────────────────────── */
#pass-overlay .panel{max-width:360px}
.pass-form{display:flex;flex-direction:column;gap:12px;padding:4px 0}

/* ── Confirm dialog ─────────────────────────────────────── */
#confirm-overlay .panel{max-width:340px}
.confirm-body-text{font-size:15px;color:var(--text-dim);margin-bottom:20px;text-align:center}
.confirm-btns{display:flex;gap:10px;justify-content:center}

/* ── Toast ──────────────────────────────────────────────── */
#toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:9px 20px;border-radius:6px;font-family:'Cinzel',serif;font-size:11px;letter-spacing:.1em;pointer-events:none;opacity:0;transition:opacity .3s;z-index:999;white-space:nowrap}
#toast.show{opacity:1}
#toast.err{border-color:var(--danger);color:var(--danger)}
#toast.ok{border-color:var(--online);color:var(--online)}

/* ── Shake ──────────────────────────────────────────────── */
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
.shake{animation:shake .28s ease}
</style>
</head>
<body>
<div id="app">

  <!-- HEADER -->
  <header>
    <span class="h-sigil">⚜</span>
    <div class="h-titles">
      <div class="h-title">REPUBLICKA — Live Chamber</div>
      <div class="h-sub">CC0 · All love reserved</div>
    </div>
    <button class="h-btn" id="theme-btn" title="Toggle light/dark">🌙</button>
    <button class="h-btn" id="admin-btn" title="Admin panel">⚙️</button>
    <button class="h-btn" id="sidebar-toggle" title="Online users">👥</button>
  </header>

  <!-- JOIN SCREEN -->
  <div id="join-screen">
    <div>
      <span class="join-sigil">⚜</span>
      <p class="join-tagline">Enter your name to join</p>
    </div>
    <div class="join-form">
      <label class="join-label" for="name-input">Your Name</label>
      <input class="join-input" type="text" id="name-input"
             placeholder="e.g. Wendell" maxlength="24"
             autocomplete="off" spellcheck="false">
      <button class="join-btn" id="join-btn">Enter the Chamber</button>
    </div>
  </div>

  <!-- CHAT SCREEN -->
  <div id="chat-screen" style="display:none;flex:1;flex-direction:column;overflow:hidden">
    <div id="main">
      <div id="col-messages">
        <div id="messages"></div>
        <div id="composer-wrap">
          <div class="composer-meta">
            <span class="c-user" id="c-user-name">⚜ —</span>
            <div class="c-actions">
              <span class="c-char" id="char-count">400</span>
              <button class="c-link-btn danger" id="clear-btn">Clear</button>
              <button class="c-link-btn danger" id="logout-btn">Log out</button>
            </div>
          </div>
          <div id="composer">
            <textarea id="msg-input" rows="1"
              placeholder="Speak your truth…" maxlength="400"></textarea>
            <button id="send-btn">Send</button>
          </div>
        </div>
      </div>

      <!-- SIDEBAR -->
      <div id="sidebar">
        <div class="sidebar-head">
          Online <span class="sidebar-count" id="sidebar-count">0</span>
        </div>
        <div id="online-list"></div>
      </div>
      <div id="sb-overlay"></div>
    </div>
  </div>

</div><!-- /app -->

<!-- PASSWORD OVERLAY -->
<div class="overlay" id="pass-overlay">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">⚜ Admin Access</span>
      <button class="panel-close" id="pass-close">✕</button>
    </div>
    <div class="panel-body">
      <div class="pass-form">
        <label class="join-label" for="pass-input">Password</label>
        <input class="join-input" type="password" id="pass-input"
               placeholder="Enter admin password" autocomplete="off">
        <button class="join-btn" id="pass-submit">Enter</button>
      </div>
    </div>
  </div>
</div>

<!-- ADMIN PANEL OVERLAY -->
<div class="overlay" id="admin-overlay">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title">⚙️ Admin Panel</span>
      <button class="panel-close" id="admin-close">✕</button>
    </div>
    <div class="ap-tabs">
      <button class="ap-tab active" data-tab="stats">Stats</button>
      <button class="ap-tab" data-tab="messages">Messages</button>
      <button class="ap-tab" data-tab="users">Users</button>
      <button class="ap-tab" data-tab="bans">Bans</button>
    </div>
    <div class="panel-body" id="admin-body">
      <!-- filled by JS -->
    </div>
  </div>
</div>

<!-- CONFIRM OVERLAY -->
<div class="overlay" id="confirm-overlay">
  <div class="panel">
    <div class="panel-header">
      <span class="panel-title" id="confirm-title">Confirm</span>
    </div>
    <div class="panel-body">
      <p class="confirm-body-text" id="confirm-body">Are you sure?</p>
      <div class="confirm-btns">
        <button class="btn-gold" id="confirm-cancel">Cancel</button>
        <button class="btn-danger" id="confirm-ok">Confirm</button>
      </div>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ════════════════════════════════════════════════════
//  CONFIG
// ════════════════════════════════════════════════════
const LS_HISTORY = 'rk_chat_history';
const LS_NAME    = 'rk_chat_name';
const LS_THEME   = 'rk_theme';
const MAX_LEN    = 400;
const POLL_MS    = 2500;
const BEAT_MS    = 12000;

// ════════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════════
let myName     = '';
let cursor     = 0;
let pollTimer  = null;
let beatTimer  = null;
let toastTmr   = null;
let confirmCb  = null;
let adminPass  = '';     // stored in memory only for session
let activeTab  = 'stats';
const rendered = new Set(); // deduplicates rendered messages by timestamp

// ════════════════════════════════════════════════════
//  DOM
// ════════════════════════════════════════════════════
const $ = id => document.getElementById(id);
const joinScreen   = $('join-screen'),  chatScreen  = $('chat-screen');
const nameInput    = $('name-input'),   joinBtn     = $('join-btn');
const messages     = $('messages'),     msgInput    = $('msg-input');
const sendBtn      = $('send-btn'),     clearBtn    = $('clear-btn');
const logoutBtn    = $('logout-btn'),   charCount   = $('char-count');
const cUserName    = $('c-user-name'),  onlineList  = $('online-list');
const sbCount      = $('sidebar-count'),sidebar     = $('sidebar');
const sbToggle     = $('sidebar-toggle'),sbOverlay  = $('sb-overlay');
const themeBtn     = $('theme-btn');
const adminBtn     = $('admin-btn');
const passOverlay  = $('pass-overlay'), passInput   = $('pass-input');
const passClose    = $('pass-close'),   passSubmit  = $('pass-submit');
const adminOverlay = $('admin-overlay'),adminClose  = $('admin-close');
const adminBody    = $('admin-body');
const confirmOvl   = $('confirm-overlay');
const confirmTitle = $('confirm-title'), confirmBody = $('confirm-body');
const confirmOk    = $('confirm-ok'),    confirmCncl = $('confirm-cancel');
const toast        = $('toast');

// ════════════════════════════════════════════════════
//  THEME
// ════════════════════════════════════════════════════
setTheme(localStorage.getItem(LS_THEME) || 'dark');
function setTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  themeBtn.textContent = t === 'dark' ? '☀️' : '🌙';
  localStorage.setItem(LS_THEME, t);
}
themeBtn.addEventListener('click', () =>
  setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark')
);

// ════════════════════════════════════════════════════
//  SIDEBAR
// ════════════════════════════════════════════════════
sbToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
sbOverlay.addEventListener('click', () => sidebar.classList.remove('open'));

// ════════════════════════════════════════════════════
//  JOIN
// ════════════════════════════════════════════════════
const savedName = localStorage.getItem(LS_NAME);
if (savedName) nameInput.value = savedName;
nameInput.focus();

function tryJoin() {
  const name = nameInput.value.trim();
  if (!name) { triggerShake(nameInput); return; }
  myName = name;
  localStorage.setItem(LS_NAME, name);
  enterChat();
}
joinBtn.addEventListener('click', tryJoin);
nameInput.addEventListener('keydown', e => { if (e.key === 'Enter') tryJoin(); });

async function enterChat() {
  joinScreen.style.display = 'none';
  chatScreen.style.display = 'flex';
  cUserName.textContent    = '⚜ ' + myName;
  loadLocalHistory();
  // Sync cursor to server clock to avoid client/server clock-skew dropping messages
  try {
    const syncRes  = await fetch('?api=poll&since=99999999999999&u=');
    const syncData = await syncRes.json();
    cursor = syncData.t ?? Date.now();
  } catch (_) {
    cursor = Date.now();
  }
  apiPost({ name: '__system__', text: myName + ' has entered the chamber' });
  pollServer();
  pollTimer = setInterval(pollServer, POLL_MS);
  beatTimer = setInterval(heartbeat, BEAT_MS);
  heartbeat();
  msgInput.focus();
}

// ════════════════════════════════════════════════════
//  LOGOUT
// ════════════════════════════════════════════════════
logoutBtn.addEventListener('click', () =>
  doConfirm('Log Out', 'Leave the chamber? Your local history stays.', doLogout)
);
function doLogout() {
  apiPost({ name: '__system__', text: myName + ' has left the chamber' })
    .finally(() => {
      fetch(`?api=leave&u=${enc(myName)}`).catch(() => {});
      clearInterval(pollTimer); clearInterval(beatTimer);
      chatScreen.style.display = 'none';
      joinScreen.style.display = 'flex';
      messages.innerHTML = ''; cursor = 0; myName = '';
      sidebar.classList.remove('open');
      nameInput.focus();
    });
}

// ════════════════════════════════════════════════════
//  CLEAR LOCAL HISTORY
// ════════════════════════════════════════════════════
clearBtn.addEventListener('click', () =>
  doConfirm('Clear History', 'Wipes your saved messages from this device. Cannot be undone.', () => {
    localStorage.removeItem(LS_HISTORY);
    messages.innerHTML = '';
    cursor = Date.now();
    showToast('History cleared', 'ok');
  })
);

// ════════════════════════════════════════════════════
//  LOCAL STORAGE
// ════════════════════════════════════════════════════
function readLocal() {
  try { return JSON.parse(localStorage.getItem(LS_HISTORY) || '[]'); }
  catch { return []; }
}
function saveToLocal(m) {
  const log = readLocal();
  if (log.some(x => x.t === m.t && x.n === m.n)) return;
  log.push(m);
  localStorage.setItem(LS_HISTORY, JSON.stringify(log));
}
function loadLocalHistory() {
  const log = readLocal();
  if (!log.length) return;
  addDivider('Stored History');
  log.forEach(m => renderMsg(m, false, true));
  scrollBottom(true);
}

// ════════════════════════════════════════════════════
//  POLLING
// ════════════════════════════════════════════════════
async function pollServer() {
  try {
    const res  = await fetch(`?api=poll&since=${cursor}&u=${enc(myName)}`);
    const data = await res.json();
    if (!data.ok) return;
    if (data.msgs.length) {
      data.msgs.forEach(m => {
        renderMsg(m, true, false);
        if (m.n !== '__system__') saveToLocal(m);
      });
      cursor = data.msgs[data.msgs.length - 1].t;
    }
    if (data.online) updateSidebar(data.online);
  } catch (_) {}
}
function heartbeat() {
  fetch(`?api=poll&since=${cursor}&u=${enc(myName)}`).catch(() => {});
}

// ════════════════════════════════════════════════════
//  RENDER
// ════════════════════════════════════════════════════
function addDivider(label) {
  const d = document.createElement('div');
  d.className = 'msg-date'; d.textContent = label;
  messages.appendChild(d);
}
// ════════════════════════════════════════════════════
//  LINKIFY — turns URLs in text into clickable links
// ════════════════════════════════════════════════════
function linkify(text) {
  // Split on raw URLs first, THEN escape each part individually
  return text.split(/(https?:\/\/[^\s]+)/g).map((part, i) => {
    if (i % 2 === 1) {
      // URL part — escape for safety but wrap in anchor
      const safe = esc(part);
      return '<a href="' + safe + '" target="_blank" rel="noopener noreferrer" style="color:var(--u-main,var(--gold));text-decoration:underline;text-underline-offset:2px;opacity:.9">' + safe + '</a>';
    }
    return esc(part);
  }).join('');
}

// ════════════════════════════════════════════════════
//  USER COLORS — 12 distinct palette entries
// ════════════════════════════════════════════════════
const USER_COLORS = [
  { main: '#5b9bd5', dim: 'rgba(91,155,213,.18)',  border: 'rgba(91,155,213,.5)'  }, // blue
  { main: '#56b87a', dim: 'rgba(86,184,122,.15)',  border: 'rgba(86,184,122,.5)'  }, // green
  { main: '#d4706a', dim: 'rgba(212,112,106,.15)', border: 'rgba(212,112,106,.5)' }, // red
  { main: '#c97fd4', dim: 'rgba(201,127,212,.15)', border: 'rgba(201,127,212,.5)' }, // purple
  { main: '#d4a44c', dim: 'rgba(212,164,76,.15)',  border: 'rgba(212,164,76,.5)'  }, // amber
  { main: '#4cc9c9', dim: 'rgba(76,201,201,.15)',  border: 'rgba(76,201,201,.5)'  }, // teal
  { main: '#d47a4c', dim: 'rgba(212,122,76,.15)',  border: 'rgba(212,122,76,.5)'  }, // orange
  { main: '#7a9bd4', dim: 'rgba(122,155,212,.15)', border: 'rgba(122,155,212,.5)' }, // periwinkle
  { main: '#b8d45b', dim: 'rgba(184,212,91,.15)',  border: 'rgba(184,212,91,.5)'  }, // lime
  { main: '#d45b9b', dim: 'rgba(212,91,155,.15)',  border: 'rgba(212,91,155,.5)'  }, // pink
  { main: '#5bd4a4', dim: 'rgba(91,212,164,.15)',  border: 'rgba(91,212,164,.5)'  }, // mint
  { main: '#d4c44c', dim: 'rgba(212,196,76,.15)',  border: 'rgba(212,196,76,.5)'  }, // yellow
];

// Gold is reserved for "me"
const ME_COLOR = { main: '#c9a84c', dim: 'rgba(201,168,76,.08)', border: 'rgba(201,168,76,.55)' };

function userColor(name) {
  if (name === myName) return ME_COLOR;
  let hash = 0;
  for (let i = 0; i < name.length; i++) hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
  return USER_COLORS[hash % USER_COLORS.length];
}

function renderMsg(m, animate, fromLocal) {
  // Deduplicate — skip if this exact message was already rendered
  const dedupKey = m.t + '|' + m.n;
  if (rendered.has(dedupKey)) return;
  rendered.add(dedupKey);
  if (m.n === '__system__') {
    const el = document.createElement('div');
    el.className = 'msg-system' + (animate ? ' msg-new' : '');
    el.textContent = m.m; messages.appendChild(el); scrollBottom(); return;
  }
  const isMine = m.n === myName;
  const col    = userColor(m.n);
  const el     = document.createElement('div');
  el.className = 'msg' + (isMine ? ' mine' : '') + (animate ? ' msg-new' : '');
  el.style.setProperty('--u-main',   col.main);
  el.style.setProperty('--u-dim',    col.dim);
  el.style.setProperty('--u-border', col.border);
  const time = new Date(m.t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  el.innerHTML = `
    <div class="msg-avatar" style="border-color:var(--u-border);color:var(--u-main);background:var(--u-dim)">${esc(m.n.charAt(0).toUpperCase())}</div>
    <div class="msg-meta">
      <span class="msg-name" style="color:var(--u-main)">${esc(m.n)}</span>
      <span class="msg-time">${time}</span>
      ${fromLocal ? '<span class="msg-badge">· stored</span>' : ''}
    </div>
    <div class="msg-text">${linkify(m.m)}</div>`;
  messages.appendChild(el); scrollBottom();
}

// ════════════════════════════════════════════════════
//  SIDEBAR
// ════════════════════════════════════════════════════
function updateSidebar(users) {
  sbCount.textContent = users.length;
  onlineList.innerHTML = '';
  users.sort((a, b) => a.n === myName ? -1 : b.n === myName ? 1 : a.n.localeCompare(b.n));
  users.forEach(u => {
    const col = userColor(u.n);
    const el = document.createElement('div');
    el.className = 'online-user' + (u.n === myName ? ' me' : '');
    el.innerHTML = `<span class="online-dot" style="background:${col.main};box-shadow:0 0 5px ${col.main}"></span><span class="online-name" style="color:${col.main}">${esc(u.n)}${u.n === myName ? ' (you)' : ''}</span>`;
    onlineList.appendChild(el);
  });
  sbToggle.classList.toggle('active', users.length > 0);
}

// ════════════════════════════════════════════════════
//  SEND
// ════════════════════════════════════════════════════
async function sendMessage() {
  const text = msgInput.value.trim();
  if (!text || text.length > MAX_LEN) return;
  sendBtn.disabled = true;
  msgInput.value = ''; updateCharCount();
  try {
    const data = await apiPost({ name: myName, text });
    if (!data.ok) showToast(data.err || 'Could not send', 'err');
    else pollServer();
  } catch (_) { showToast('Network error', 'err'); }
  finally { sendBtn.disabled = false; msgInput.focus(); }
}
sendBtn.addEventListener('click', sendMessage);
msgInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
msgInput.addEventListener('input', updateCharCount);
function updateCharCount() {
  const rem = MAX_LEN - msgInput.value.length;
  charCount.textContent = rem;
  charCount.className = rem < 0 ? 'c-char over' : rem < 60 ? 'c-char warn' : 'c-char';
}

// ════════════════════════════════════════════════════
//  ADMIN — PASSWORD
// ════════════════════════════════════════════════════
adminBtn.addEventListener('click', () => {
  if (adminPass) { openAdminPanel(); return; }
  passInput.value = '';
  passOverlay.classList.add('open');
  setTimeout(() => passInput.focus(), 50);
});
passClose.addEventListener('click', () => passOverlay.classList.remove('open'));
passOverlay.addEventListener('click', e => { if (e.target === passOverlay) passOverlay.classList.remove('open'); });

async function tryAdminPass() {
  const p = passInput.value.trim();
  if (!p) return;
  // Test the password against the server
  const res  = await fetch('?api=admin_stats', { headers: { 'X-Admin-Pass': p } });
  const data = await res.json();
  if (!data.ok) { triggerShake(passInput); showToast('Wrong password', 'err'); return; }
  adminPass = p;
  passOverlay.classList.remove('open');
  openAdminPanel(data);
}
passSubmit.addEventListener('click', tryAdminPass);
passInput.addEventListener('keydown', e => { if (e.key === 'Enter') tryAdminPass(); });

// ════════════════════════════════════════════════════
//  ADMIN — PANEL
// ════════════════════════════════════════════════════
async function openAdminPanel(preloaded) {
  adminOverlay.classList.add('open');
  const data = preloaded || await adminFetch('admin_stats');
  if (!data.ok) { showToast('Auth error', 'err'); adminOverlay.classList.remove('open'); adminPass = ''; return; }
  renderAdminTab(activeTab, data);
}

adminClose.addEventListener('click', () => adminOverlay.classList.remove('open'));
adminOverlay.addEventListener('click', e => { if (e.target === adminOverlay) adminOverlay.classList.remove('open'); });

document.querySelectorAll('.ap-tab').forEach(btn => {
  btn.addEventListener('click', async () => {
    document.querySelectorAll('.ap-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeTab = btn.dataset.tab;
    const data = await adminFetch('admin_stats');
    if (data.ok) renderAdminTab(activeTab, data);
  });
});

function renderAdminTab(tab, data) {
  const relay  = data.relay  || [];
  const online = data.online || [];
  const bans   = data.bans   || [];

  if (tab === 'stats') {
    adminBody.innerHTML = `
      <div class="ap-stats">
        <div class="ap-card"><span class="ap-card-num">${relay.length}</span><span class="ap-card-label">In Relay</span></div>
        <div class="ap-card"><span class="ap-card-num">${online.length}</span><span class="ap-card-label">Online Now</span></div>
        <div class="ap-card"><span class="ap-card-num">${bans.length}</span><span class="ap-card-label">Banned</span></div>
      </div>
      <p class="ap-sh">Relay Info</p>
      <p style="font-size:14px;color:var(--text-dim);margin-bottom:16px">
        The relay holds the last 5 minutes of messages so active users can receive them.
        Messages are stored permanently in each user's own browser localStorage.
      </p>
      <button class="btn-danger" id="ap-clear-all">Clear Relay Now</button>`;
    $('ap-clear-all').addEventListener('click', () =>
      doConfirm('Clear Relay', 'Remove all messages from the server relay?', async () => {
        const r = await adminPost('admin_clear');
        if (r.ok) { showToast('Relay cleared', 'ok'); openAdminPanel(); }
      })
    );
  }

  if (tab === 'messages') {
    const msgs = relay.filter(m => m.n !== '__system__').slice().reverse();
    adminBody.innerHTML = `<p class="ap-sh">Live Relay Messages (${msgs.length})</p>`;
    if (!msgs.length) {
      adminBody.innerHTML += `<p style="font-size:14px;color:var(--text-dim)">No messages in relay right now.</p>`;
    } else {
      const list = document.createElement('div');
      list.className = 'ap-rows';
      msgs.forEach(m => {
        const time = new Date(m.t).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
        const row  = document.createElement('div');
        row.className = 'ap-row';
        row.innerHTML = `
          <div class="ap-row-info">
            <div class="ap-row-meta">${esc(m.n)} · ${time}</div>
            <div class="ap-row-text">${esc(m.m)}</div>
          </div>
          <div class="ap-row-actions">
            <button class="btn-danger btn-sm ap-del" data-t="${m.t}">Delete</button>
          </div>`;
        list.appendChild(row);
      });
      adminBody.appendChild(list);
      adminBody.querySelectorAll('.ap-del').forEach(btn => {
        btn.addEventListener('click', () => {
          const t = parseInt(btn.dataset.t);
          doConfirm('Delete Message', 'Remove this message from the relay?', async () => {
            const r = await adminPost('admin_delete', { t });
            if (r.ok) { showToast('Deleted', 'ok'); openAdminPanel(); }
          });
        });
      });
    }
  }

  if (tab === 'users') {
    adminBody.innerHTML = `<p class="ap-sh">Currently Online (${online.length})</p>`;
    if (!online.length) {
      adminBody.innerHTML += `<p style="font-size:14px;color:var(--text-dim)">No users online.</p>`;
    } else {
      const list = document.createElement('div');
      list.className = 'ap-rows';
      online.forEach(u => {
        const row = document.createElement('div');
        row.className = 'ap-row';
        row.innerHTML = `
          <div class="ap-row-info"><div class="ap-row-meta" style="font-size:14px">${esc(u.n)}</div></div>
          <div class="ap-row-actions">
            <button class="btn-danger btn-sm ap-kick" data-n="${esc(u.n)}">Kick</button>
            <button class="btn-danger btn-sm ap-ban-user" data-n="${esc(u.n)}">Ban</button>
          </div>`;
        list.appendChild(row);
      });
      adminBody.appendChild(list);
      adminBody.querySelectorAll('.ap-kick').forEach(btn => {
        btn.addEventListener('click', () => {
          const n = btn.dataset.n;
          doConfirm('Kick User', `Remove ${n} from the online list?`, async () => {
            const r = await adminPost('admin_kick', { name: n });
            if (r.ok) { showToast(`${n} kicked`, 'ok'); openAdminPanel(); }
          });
        });
      });
      adminBody.querySelectorAll('.ap-ban-user').forEach(btn => {
        btn.addEventListener('click', () => {
          const n = btn.dataset.n;
          doConfirm('Ban User', `Ban ${n}? They won't be able to post.`, async () => {
            const r = await adminPost('admin_ban', { name: n });
            if (r.ok) { showToast(`${n} banned`, 'ok'); openAdminPanel(); }
          });
        });
      });
    }
  }

  if (tab === 'bans') {
    adminBody.innerHTML = `
      <p class="ap-sh">Ban a Username</p>
      <div class="ap-input-row">
        <input class="ap-input" id="ban-name-input" placeholder="Username to ban" maxlength="24">
        <button class="btn-danger" id="do-ban-btn">Ban</button>
      </div>
      <p class="ap-sh">Current Bans (${bans.length})</p>`;

    $('do-ban-btn').addEventListener('click', async () => {
      const n = $('ban-name-input').value.trim();
      if (!n) return;
      doConfirm('Ban User', `Ban "${n}" from posting?`, async () => {
        const r = await adminPost('admin_ban', { name: n });
        if (r.ok) { showToast(`${n} banned`, 'ok'); openAdminPanel(); }
      });
    });
    $('ban-name-input').addEventListener('keydown', e => {
      if (e.key === 'Enter') $('do-ban-btn').click();
    });

    if (!bans.length) {
      adminBody.innerHTML += `<p style="font-size:14px;color:var(--text-dim)">No bans in place.</p>`;
    } else {
      const list = document.createElement('div');
      list.className = 'ap-rows';
      bans.forEach(b => {
        const row = document.createElement('div');
        row.className = 'ap-row';
        row.innerHTML = `
          <div class="ap-row-info"><div class="ap-row-meta" style="font-size:14px">${esc(b.n)}</div></div>
          <div class="ap-row-actions">
            <button class="btn-gold btn-sm ap-unban" data-n="${esc(b.n)}">Unban</button>
          </div>`;
        list.appendChild(row);
      });
      adminBody.appendChild(list);
      adminBody.querySelectorAll('.ap-unban').forEach(btn => {
        btn.addEventListener('click', async () => {
          const n = btn.dataset.n;
          const r = await adminPost('admin_unban', { name: n });
          if (r.ok) { showToast(`${n} unbanned`, 'ok'); openAdminPanel(); }
        });
      });
    }
  }
}

// ════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════
async function apiPost(body) {
  const r = await fetch('?api=post', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  return r.json();
}
async function adminFetch(action) {
  const r = await fetch(`?api=${action}`, { headers: { 'X-Admin-Pass': adminPass } });
  return r.json();
}
async function adminPost(action, body = {}) {
  const r = await fetch(`?api=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Admin-Pass': adminPass },
    body: JSON.stringify(body)
  });
  return r.json();
}
function scrollBottom(force) {
  const diff = messages.scrollHeight - messages.scrollTop - messages.clientHeight;
  if (force || diff < 140) messages.scrollTop = messages.scrollHeight;
}
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function enc(s) { return encodeURIComponent(s); }
function showToast(msg, type = '') {
  toast.textContent = msg;
  toast.className = 'show' + (type ? ' ' + type : '');
  clearTimeout(toastTmr);
  toastTmr = setTimeout(() => { toast.className = ''; }, 3000);
}
function triggerShake(el) {
  el.classList.remove('shake'); void el.offsetWidth;
  el.classList.add('shake');
  el.addEventListener('animationend', () => el.classList.remove('shake'), { once: true });
}
function doConfirm(title, body, cb) {
  confirmTitle.textContent = title; confirmBody.textContent = body; confirmCb = cb;
  confirmOvl.classList.add('open');
}
confirmOk.addEventListener('click', () => {
  confirmOvl.classList.remove('open');
  if (confirmCb) { confirmCb(); confirmCb = null; }
});
confirmCncl.addEventListener('click', () => { confirmOvl.classList.remove('open'); confirmCb = null; });
confirmOvl.addEventListener('click', e => {
  if (e.target === confirmOvl) { confirmOvl.classList.remove('open'); confirmCb = null; }
});
window.addEventListener('beforeunload', () => {
  if (myName) navigator.sendBeacon(`?api=leave&u=${enc(myName)}`);
});
</script>
</body>
</html>
