<?php
/**
 * 採用サイト 応募フォームの送信処理（Xserver 用・PHP 7.3 で動くように書く）
 *
 * - 採用担当（work@）へ応募内容を通知
 * - 応募者へ受付の自動返信
 * - 応答は JSON（{"ok":true} / {"ok":false,"error":"..."}）。フォーム側の fetch が受け取る
 */

// メールは UTF-8 で送る。'Japanese'（ISO-2022-JP）だと 髙・𠮷・①・㈱・絵文字・半角カナ・— が「?」に化ける
mb_language('uni');
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

const TO_ADDRESS   = 'work@mame-kawamura.com';
const FROM_ADDRESS = 'work@mame-kawamura.com';
const FROM_NAME    = '甘納豆かわむら 採用担当';
const SHOP_TEL     = '076-282-7007';
const ALLOWED_ORIGINS = array('https://recruit.mame-kawamura.com');
const RATE_LIMIT_SEC  = 60; // 同じIPからの連続送信の間隔

function respond($ok, $error = '', $status = 200) {
  http_response_code($status);
  echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => $error), JSON_UNESCAPED_UNICODE);
  exit;
}

// 1行項目：改行を除いて長さを制限（メールヘッダーへの差し込み対策も兼ねる）
function field($name, $max = 100) {
  $v = isset($_POST[$name]) ? (string) $_POST[$name] : '';
  $v = trim(preg_replace('/[\r\n\t\x00]+/u', ' ', $v));
  return mb_substr($v, 0, $max);
}

// 複数行項目
function textarea($name, $max = 1000) {
  $v = isset($_POST[$name]) ? (string) $_POST[$name] : '';
  $v = str_replace(array("\r\n", "\r", "\x00"), array("\n", "\n", ''), $v);
  return mb_substr(trim($v), 0, $max);
}

// 署名は担当者名を含むので公開リポジトリには置かず、サーバーの公開フォルダの外
// （~/mame-kawamura.com/recruit_private/mail_signature.txt）から読む。無ければ簡易署名
function signature() {
  $file = dirname(dirname(dirname(__DIR__))) . '/recruit_private/mail_signature.txt';
  if (is_readable($file)) {
    $sig = trim(str_replace(array("\r\n", "\r"), "\n", (string) file_get_contents($file)));
    if ($sig !== '') return $sig . "\n";
  }
  return "甘納豆かわむら 採用担当\nTEL：" . SHOP_TEL . "\nMAIL：" . FROM_ADDRESS . "\n";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'method', 405);

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '' && !in_array($origin, ALLOWED_ORIGINS, true)) respond(false, 'origin', 403);

// ボット対策：見えない項目に入力があれば、送ったふりをして何もしない
if (field('website') !== '') respond(true);

// 連続送信の制限
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$lock = sys_get_temp_dir() . '/kw_recruit_entry_' . md5($ip);
if (is_file($lock) && time() - filemtime($lock) < RATE_LIMIT_SEC) respond(false, 'rate', 429);

$d = array(
  'page'         => field('page', 50),
  'page_label'   => field('page_label', 100),
  'job'          => field('job', 100),
  'name'         => field('name', 60),
  'kana'         => field('kana', 60),
  'tel'          => field('tel', 30),
  'email'        => field('email', 200),
  'status'       => field('status', 50),
  'status_other' => field('status_other', 100),
  'category'     => field('category', 50),
  'start'        => field('start', 50),
  'shift'        => field('shift', 200),
  'source'       => field('source', 50),
  'message'      => textarea('message', 1000),
  'agree'        => field('agree', 20),
  'utm_source'   => field('utm_source', 100),
  'utm_medium'   => field('utm_medium', 100),
  'utm_campaign' => field('utm_campaign', 100),
);

// 全角の電話番号・メールアドレス、カタカナのふりがなを受け付けられるようにそろえる
$d['tel'] = str_replace(array('－', '−', '‐', '―', 'ー'), '-', mb_convert_kana($d['tel'], 'as'));
$d['email'] = preg_replace('/\s+/u', '', mb_convert_kana($d['email'], 'as'));
$d['kana'] = mb_convert_kana($d['kana'], 'HVcs');

// フォーム側と同じ必須チェック
$telDigits = preg_replace('/\D/', '', $d['tel']);
if ($d['name'] === ''
  || !preg_match('/^[ぁ-んー\s　]+$/u', $d['kana'])
  || !preg_match('/^0\d{9,10}$/', $telDigits)
  || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)
  || $d['category'] === ''
  || $d['agree'] === '') {
  respond(false, 'invalid', 400);
}

$job = $d['job'] !== '' ? $d['job'] : '採用';
$status = $d['status'] . ($d['status_other'] !== '' ? '（' . $d['status_other'] . '）' : '');
$dash = function ($v) { return $v !== '' ? $v : '（未入力）'; };

$summary =
    "■ 応募職種：" . $job . "\n"
  . "■ お名前：" . $d['name'] . "（" . $d['kana'] . "）\n"
  . "■ 電話番号：" . $d['tel'] . "\n"
  . "■ メールアドレス：" . $d['email'] . "\n"
  . "■ 現在の状況：" . $dash($status) . "\n"
  . "■ 応募区分：" . $d['category'] . "\n"
  . "■ 希望入社時期：" . $dash($d['start']) . "\n"
  . "■ 学校名・製菓経験：" . $dash($d['shift']) . "\n"
  . "■ 知ったきっかけ：" . $dash($d['source']) . "\n"
  . "■ 志望動機・ご質問：\n" . $dash($d['message']) . "\n";

$now = date('Y年n月j日 H:i');
$fromHeader = 'From: ' . mb_encode_mimeheader(FROM_NAME) . ' <' . FROM_ADDRESS . '>';
$envelope = '-f' . FROM_ADDRESS;

// 1) 採用担当への通知（返信ボタンで応募者に返せるよう Reply-To を応募者に）
$adminSubject = '【採用応募】' . $job . '｜' . $d['name'] . '様';
$adminBody =
    "採用サイトから応募がありました。\n"
  . "受付日時：" . $now . "\n\n"
  . $summary . "\n"
  . "---------------------------------\n"
  . "ページ：" . $dash($d['page_label']) . "（" . $dash($d['page']) . "）\n"
  . "流入元：utm_source=" . $dash($d['utm_source'])
  . " / utm_medium=" . $dash($d['utm_medium'])
  . " / utm_campaign=" . $dash($d['utm_campaign']) . "\n"
  . "送信元IP：" . $ip . "\n";
$adminHeaders = $fromHeader . "\r\n" . 'Reply-To: ' . $d['email'];

if (!mb_send_mail(TO_ADDRESS, $adminSubject, $adminBody, $adminHeaders, $envelope)) {
  respond(false, 'mail', 500);
}
touch($lock);

// 2) 応募者への自動返信（失敗しても応募自体は受け付け済みなので ok を返す）
$replySubject = '甘納豆かわむら：ご応募を受け付けました';
$replyBody =
    $d['name'] . " 様\n\n"
  . "このたびは甘納豆かわむらの求人にご応募いただき、\n"
  . "誠にありがとうございます。\n\n"
  . "以下の内容で受け付けました。\n"
  . "内容を確認のうえ、担当者より順次ご連絡差し上げます。\n"
  . "今しばらくお待ちください。\n\n"
  . "---------------------------------\n"
  . $summary
  . "---------------------------------\n\n"
  . "※このメールは自動でお送りしています。\n"
  . "　ご不明な点は、このメールへのご返信またはお電話にてお問い合わせください。\n"
  . "※お心当たりのない場合は、お手数ですがこのメールを破棄してください。\n\n"
  . signature();
@mb_send_mail($d['email'], $replySubject, $replyBody, $fromHeader . "\r\n" . 'Reply-To: ' . FROM_ADDRESS, $envelope);

respond(true);
