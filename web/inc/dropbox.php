<?php
// Automatic backups to Dropbox. Requires inc/common.php and inc/backup.php.
//
// Connection: a Dropbox "App folder" app owned by the site owner. The admin page runs the OAuth
// code flow with PKCE (no app secret needed; Dropbox shows a code the owner pastes back) and stores
// a long-lived refresh token in DROPBOX_FILE. That token can only reach the app's own folder.
// The schedule runs from solar-backup.timer (inc/auto-backup.php); status goes to BACKUP_STATUS_FILE.
// Both files live in /etc/solar-logger (writable by www-data and the solarcap service via group www-data).

const DROPBOX_FILE = '/etc/solar-logger/dropbox.json';
const BACKUP_STATUS_FILE = '/etc/solar-logger/backup-status.json';
const BACKUP_DEFAULTS = ['dropbox_enabled' => false, 'frequency' => 'weekly', 'keep' => 8, 'include_sources' => false];

function json_file_read($path) {
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

// Atomic write, group-writable so both the web server and the backup service can update it.
function json_file_write($path, $data) {
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false)
        throw new RuntimeException("could not write $path");
    chmod($tmp, 0660);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException("could not replace $path"); }
}

function backup_settings($cfg) { return array_merge(BACKUP_DEFAULTS, $cfg['backup'] ?? []); }
function backup_status() { return json_file_read(BACKUP_STATUS_FILE) ?? []; }
function dropbox_creds() { return json_file_read(DROPBOX_FILE); }

// HTTPS request without the curl extension. Returns [status, body].
function http_request($url, $headers, $body, $timeout = 60) {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers) . "\r\n", 'content' => $body,
        'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    if (isset($hdrs[0]) && preg_match('#\s(\d{3})(\s|$)#', $hdrs[0], $m)) $status = (int)$m[1];
    if ($resp === false && !$status) throw new RuntimeException('could not reach ' . parse_url($url, PHP_URL_HOST) . ' (no internet?)');
    return [$status, (string)$resp];
}

function dropbox_error($what, $status, $body) {
    $j = json_decode($body, true);
    $msg = $j['error_summary'] ?? $j['error_description'] ?? $j['error'] ?? trim(substr($body, 0, 200));
    return new RuntimeException("Dropbox $what failed (HTTP $status): " . (is_string($msg) ? $msg : json_encode($msg)));
}

// ---- OAuth (PKCE, no redirect: Dropbox displays the code)
function pkce_pair() {
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return [$verifier, $challenge];
}

function dropbox_auth_url($appKey, $challenge) {
    return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
        'client_id' => $appKey, 'response_type' => 'code', 'token_access_type' => 'offline',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]);
}

function dropbox_finish_auth($appKey, $code, $verifier) {
    [$st, $body] = http_request('https://api.dropboxapi.com/oauth2/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'authorization_code', 'code' => trim($code),
                          'client_id' => $appKey, 'code_verifier' => $verifier]), 20);
    $j = json_decode($body, true);
    if ($st !== 200 || empty($j['refresh_token'])) throw dropbox_error('connection', $st, $body);
    json_file_write(DROPBOX_FILE, ['app_key' => $appKey, 'refresh_token' => $j['refresh_token'],
                                   'account_id' => $j['account_id'] ?? null, 'connected_at' => time()]);
}

function dropbox_access_token($creds) {
    [$st, $body] = http_request('https://api.dropboxapi.com/oauth2/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $creds['refresh_token'],
                          'client_id' => $creds['app_key']]), 20);
    $j = json_decode($body, true);
    if ($st !== 200 || empty($j['access_token'])) throw dropbox_error('sign-in', $st, $body);
    return $j['access_token'];
}

function dropbox_rpc($token, $endpoint, $args) {
    [$st, $body] = http_request("https://api.dropboxapi.com/2/$endpoint",
        ["Authorization: Bearer $token", 'Content-Type: application/json'], json_encode($args), 30);
    if ($st !== 200) throw dropbox_error($endpoint, $st, $body);
    return json_decode($body, true);
}

function dropbox_upload($token, $file, $name) {
    // Files are small (a few MB), so a single upload call (limit 150 MB) is enough.
    $arg = json_encode(['path' => "/$name", 'mode' => 'add', 'autorename' => true, 'mute' => true]);
    [$st, $body] = http_request('https://content.dropboxapi.com/2/files/upload',
        ["Authorization: Bearer $token", 'Content-Type: application/octet-stream',
         'Dropbox-API-Arg: ' . $arg], file_get_contents($file), 300);
    if ($st !== 200) throw dropbox_error('upload', $st, $body);
}

// Delete the oldest solar-backup-* files beyond $keep. Returns the number deleted.
function dropbox_prune($token, $keep) {
    $r = dropbox_rpc($token, 'files/list_folder', ['path' => '']);
    $names = [];
    while (true) {
        foreach ($r['entries'] as $e) {
            if ($e['.tag'] === 'file' && str_starts_with($e['name'], 'solar-backup-')) $names[] = $e['name'];
        }
        if (empty($r['has_more'])) break;
        $r = dropbox_rpc($token, 'files/list_folder/continue', ['cursor' => $r['cursor']]);
    }
    sort($names);  // names start with a sortable timestamp
    $old = array_slice($names, 0, max(0, count($names) - $keep));
    foreach ($old as $n) dropbox_rpc($token, 'files/delete_v2', ['path' => "/$n"]);
    return count($old);
}

function dropbox_disconnect() {
    $creds = dropbox_creds();
    if ($creds) {
        try { dropbox_rpc(dropbox_access_token($creds), 'auth/token/revoke', null); } catch (Exception $e) { /* offline: still forget it */ }
    }
    @unlink(DROPBOX_FILE);
}

// Make a full backup and upload it if due (or $force). Returns a short result message.
function run_auto_backup($force = false) {
    $cfg = load_config();
    $s = backup_settings($cfg);
    $status = backup_status();
    if (!$force) {
        if (empty($s['dropbox_enabled'])) return 'automatic backups are off';
        $interval = $s['frequency'] === 'daily' ? 20 * 3600 : 6.5 * 86400;
        if (!empty($status['last_success']) && time() - $status['last_success'] < $interval) return 'not due yet';
    }
    $status['last_attempt'] = time();
    try {
        $creds = dropbox_creds();
        if (!$creds) throw new RuntimeException('Dropbox is not connected');
        $b = backup_build(backup_tmpdir(), !empty($s['include_sources']));
        $token = dropbox_access_token($creds);
        dropbox_upload($token, $b['path'], $b['name']);
        $deleted = dropbox_prune($token, max(1, (int)$s['keep']));
        $status = array_merge($status, ['last_success' => time(), 'last_file' => $b['name'], 'last_size' => $b['size'],
                                        'last_readings' => $b['readings'], 'last_error' => null]);
        $msg = sprintf('uploaded %s (%.1f MB)%s', $b['name'], $b['size'] / 1048576, $deleted ? ", removed $deleted old" : '');
    } catch (Exception $e) {
        $status['last_error'] = $e->getMessage();
        $msg = 'failed: ' . $e->getMessage();
    }
    json_file_write(BACKUP_STATUS_FILE, $status);
    return $msg;
}
