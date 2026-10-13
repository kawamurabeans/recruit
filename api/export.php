<?php
/**
 * 応募の控え（entries.csv）を Google スプレッドシート側へ渡す受け取り口（PHP 7.3 で動くように書く）
 *
 * - 呼ぶのはスプレッドシートの Apps Script（時間トリガー）だけ。応募者の送信処理とは無関係
 * - 合言葉（recruit_private/export_token.txt）が一致しないと何も返さない
 * - since（YYYYMMDD）を渡すと、その日以降の受付分だけを返す。重複はシート側で受付IDを見て除く
 * - 応答：{"ok":true,"rows":[{"受付ID":"...", ...}]}
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

$private = dirname(dirname(dirname(__DIR__))) . '/recruit_private';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(array('ok' => false), 404);

$tokenFile = $private . '/export_token.txt';
$expected = is_readable($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$given = isset($_POST['token']) ? (string) $_POST['token'] : '';
// 合言葉が未設定・不一致のときは、存在しないページと同じ応答にする
if (strlen($expected) < 32 || !hash_equals($expected, $given)) respond(array('ok' => false), 404);

$since = isset($_POST['since']) ? preg_replace('/\D/', '', (string) $_POST['since']) : '';
$since = substr($since, 0, 8);

$file = $private . '/entries.csv';
if (!is_file($file)) respond(array('ok' => true, 'rows' => array()));

$fp = fopen($file, 'r');
if (!$fp) respond(array('ok' => false, 'error' => 'read'), 500);
flock($fp, LOCK_SH);
$header = fgetcsv($fp);
if ($header && isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$rows = array();
while ($header && ($line = fgetcsv($fp)) !== false) {
  if (count($line) !== count($header)) continue;
  $row = array_combine($header, $line);
  if ($since !== '' && strcmp(substr($row['受付ID'], 0, 8), $since) < 0) continue;
  $rows[] = $row;
}
flock($fp, LOCK_UN);
fclose($fp);

respond(array('ok' => true, 'rows' => $rows));
