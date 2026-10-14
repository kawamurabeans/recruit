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
require __DIR__ . '/csv.inc.php';

function respond($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

$private = dirname(dirname(dirname(__DIR__))) . '/recruit_private';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(array('ok' => false), 404);

$tokenFile = $private . '/export_token.txt';
$expected = is_readable($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$raw = isset($_POST['token']) ? (string) $_POST['token'] : '';
// 貼り付け時に紛れ込みやすい前後の空白・改行は取り除いてから照合する
$given = trim($raw);
// 合言葉が未設定・不一致のときは、存在しないページと同じ応答にする
if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
  // 原因を追えるよう、失敗の様子だけを非公開フォルダに残す（合言葉そのものは記録しない）
  @file_put_contents($private . '/export_fail.log', date('Y-m-d H:i:s') . ' len=' . strlen($raw)
    . ' trimmed_len=' . strlen($given) . ' ua=' . substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 80)
    . "\n", FILE_APPEND | LOCK_EX);
  respond(array('ok' => false), 404);
}

$since = isset($_POST['since']) ? preg_replace('/\D/', '', (string) $_POST['since']) : '';
$since = substr($since, 0, 8);

$file = $private . '/entries.csv';
if (!is_file($file)) respond(array('ok' => true, 'rows' => array()));

$fp = fopen($file, 'r');
if (!$fp) respond(array('ok' => false, 'error' => 'read'), 500);
flock($fp, LOCK_SH);
$all = kw_csv_parse(stream_get_contents($fp));
flock($fp, LOCK_UN);
fclose($fp);

$header = array_shift($all);
$rows = array();
$broken = 0; // 列数が合わない行。黙って捨てずに件数を返し、シート側でエラーとして知らせる
foreach ($all as $line) {
  if (count($line) === 1 && $line[0] === '') continue; // 空行
  if (!$header || count($line) !== count($header)) { $broken++; continue; }
  $row = array_combine($header, $line);
  if ($since !== '' && strcmp(substr($row['受付ID'], 0, 8), $since) < 0) continue;
  $rows[] = $row;
}

respond(array('ok' => true, 'rows' => $rows, 'broken' => $broken));
