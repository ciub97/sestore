<?php
/**
 * SE STORE — сохранение сайта из админ-панели.
 * Положите этот файл рядом с index.html (в ту же папку на сервере).
 * Админка отправляет сюда готовый HTML, а скрипт перезаписывает index.html.
 *
 * Защита: пароль проверяется на сервере (по хэшу из текущего index.html),
 * перед записью делается резервная копия в папку backups/.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$TARGET      = __DIR__ . '/index.html';   // какой файл перезаписывать
$BACKUP_DIR  = __DIR__ . '/backups';      // папка резервных копий
$KEEP        = 15;                        // сколько копий хранить
$MAX_BYTES   = 25 * 1024 * 1024;          // максимальный размер файла сайта
$MAX_FAILS   = 5;                         // неудачных попыток подряд
$BLOCK_SECS  = 600;                       // блокировка на 10 минут

function out($code, $arr) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false, 'error' => 'Нужен POST-запрос']);
}

// Запрос должен прийти с этого же сайта
$host = $_SERVER['HTTP_HOST'] ?? '';
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $hdr) {
    if (!empty($_SERVER[$hdr])) {
        $h = parse_url($_SERVER[$hdr], PHP_URL_HOST);
        $p = parse_url($_SERVER[$hdr], PHP_URL_PORT);
        $hp = $h . ($p ? ':' . $p : '');
        if (strcasecmp($hp, $host) !== 0 && strcasecmp($h, preg_replace('~:\d+$~', '', $host)) !== 0) {
            out(403, ['ok' => false, 'error' => 'Запрос с чужого сайта отклонён']);
        }
        break;
    }
}

// Ограничение перебора пароля
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'x';
$failFile = sys_get_temp_dir() . '/sestore_save_' . md5($ip) . '.json';
$fail    = ['n' => 0, 't' => 0];
if (is_file($failFile)) {
    $d = json_decode(@file_get_contents($failFile), true);
    if (is_array($d)) $fail = $d + $fail;
}
if ($fail['n'] >= $MAX_FAILS && time() - $fail['t'] < $BLOCK_SECS) {
    out(429, ['ok' => false, 'error' => 'Слишком много попыток. Подождите 10 минут.']);
}
if (time() - $fail['t'] >= $BLOCK_SECS) $fail['n'] = 0;

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $MAX_BYTES) {
    out(413, ['ok' => false, 'error' => 'Файл слишком большой или пустой']);
}
$in   = json_decode($raw, true);
$html = is_array($in) ? ($in['html'] ?? '') : '';
$hash = is_array($in) ? strtolower((string)($in['h'] ?? '')) : '';

if (!is_string($html) || $html === '' || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
    out(400, ['ok' => false, 'error' => 'Неверные данные запроса']);
}

// Достаём действующий хэш пароля из текущего index.html на сервере
$cur       = is_file($TARGET) ? (string)file_get_contents($TARGET) : '';
$serverHash = null;
$legacy    = false;
if ($cur !== '' && preg_match('~<script id="seed" type="application/json">(.*?)</script>~s', $cur, $m)) {
    $seed = json_decode($m[1], true);
    if (is_array($seed) && isset($seed['AUTH'])) {
        if (!empty($seed['AUTH']['h'])) $serverHash = strtolower($seed['AUTH']['h']);
        else $legacy = true;
    }
}
if ($serverHash === null && !$legacy && preg_match("~AH='([0-9a-f]{64})'~", $cur, $m)) {
    $serverHash = strtolower($m[1]);
}
if ($legacy) {
    out(403, ['ok' => false, 'error' => 'Задайте пароль заново в админке (раздел «Безопасность»), затем сохраните снова.']);
}
if ($serverHash === null) {
    out(500, ['ok' => false, 'error' => 'На сервере не найден текущий index.html с настройками доступа']);
}

if (!hash_equals($serverHash, $hash)) {
    $fail['n']++; $fail['t'] = time();
    @file_put_contents($failFile, json_encode($fail));
    out(401, ['ok' => false, 'error' => 'Неверный логин или пароль']);
}
@unlink($failFile);

// Проверка, что присланное — действительно страница SE STORE
if (stripos(ltrim($html), '<!DOCTYPE html') !== 0
    || strpos($html, 'id="seed"') === false
    || strpos($html, 'id="app-js"') === false
    || stripos($html, '</html>') === false
    || !preg_match('~<script id="seed" type="application/json">(.*?)</script>~s', $html, $m2)
    || !is_array(json_decode($m2[1], true))) {
    out(422, ['ok' => false, 'error' => 'Присланный файл не похож на сайт SE STORE — запись отменена']);
}

// Резервная копия
if ($cur !== '') {
    if (!is_dir($BACKUP_DIR)) {
        @mkdir($BACKUP_DIR, 0755, true);
        @file_put_contents($BACKUP_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (is_dir($BACKUP_DIR)) {
        @file_put_contents($BACKUP_DIR . '/index-' . date('Ymd-His') . '.html', $cur);
        $files = glob($BACKUP_DIR . '/index-*.html') ?: [];
        sort($files);
        while (count($files) > $KEEP) @unlink(array_shift($files));
    }
}

// Атомарная запись: сначала во временный файл, потом переименование
$tmp = $TARGET . '.tmp' . getmypid();
$lock = fopen(__FILE__, 'r');
if ($lock) flock($lock, LOCK_EX);
$ok = @file_put_contents($tmp, $html) !== false;
if ($ok) {
    if (is_file($TARGET)) @chmod($tmp, fileperms($TARGET) & 0777);
    $ok = @rename($tmp, $TARGET);
}
if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
if (!$ok) {
    @unlink($tmp);
    out(500, ['ok' => false, 'error' => 'Не удалось записать index.html: проверьте права на папку сайта']);
}
if (function_exists('opcache_invalidate')) @opcache_invalidate($TARGET, true);

out(200, ['ok' => true, 'bytes' => strlen($html), 'time' => date('c')]);
