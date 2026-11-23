<?php
declare(strict_types=1);

/**
 * Reklama uchun ochiq sahifa (landing): Instagram/Facebook reklamasi to'g'ridan-to'g'ri t.me havolasiga emas, shu sahifaga olib keladi.
 * Manzil: PUBLIC_BASE_URL/start.php  (ixtiyoriy: ?ref=<telegram ID> — referal, ?agt=<ID> — hamkor havolasi).
 * Sahifada: xizmat tavsifi, haqiqiy slayd namunalari, «Telegram'da ochish» tugmasi, maxfiylik siyosati va aloqa.
 */
require_once __DIR__ . '/config.php';

$bot = (defined('BOT_USERNAME') && BOT_USERNAME !== '' && BOT_USERNAME !== 'BOT_USERNAME_HERE') ? (string)BOT_USERNAME : 'SlayderAiBot';
$payload = '';
if (isset($_GET['ref']) && preg_match('/^\d{3,15}$/', (string)$_GET['ref'])) {
    $payload = 'ref' . $_GET['ref'];
} elseif (isset($_GET['agt']) && preg_match('/^\d{3,15}$/', (string)$_GET['agt'])) {
    $payload = 'agt' . $_GET['agt'];
}
if (isset($_GET['src']) && preg_match('/^[a-z0-9]{2,24}$/i', (string)$_GET['src'])) {
    $payload .= ($payload !== '' ? '_' : '') . 'src_' . strtolower((string)$_GET['src']);    // 📊 reklama manbasi
}
$tg = 'https://t.me/' . rawurlencode($bot) . ($payload !== '' ? '?start=' . $payload : '');
$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SlayderAi — AI taqdimot yaratuvchi</title>
<meta name="description" content="Mavzuni yozing — sun'iy intellekt bir necha daqiqada chiroyli taqdimot (PPTX) tayyorlaydi: 17 xil dizayn, mos rasmlar, himoya uchun konspekt.">
<meta property="og:title" content="SlayderAi — AI taqdimot yaratuvchi">
<meta property="og:description" content="Mavzuni yozing — bir necha daqiqada tayyor taqdimot. Birinchisi bepul.">
<meta property="og:image" content="assets/promo/s1.jpg">
<style>
:root{--bg:#07131d;--card:#0e2233;--ink:#eaf6ff;--mute:#9db4c8;--a:#22d3ee;--b:#3b82f6}
*{box-sizing:border-box;margin:0;padding:0}
body{background:radial-gradient(900px 600px at 10% -10%,#0f4c75 0,transparent 60%),radial-gradient(800px 600px at 110% 40%,#312e81 0,transparent 55%),var(--bg);color:var(--ink);font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif}
.w{max-width:960px;margin:0 auto;padding:0 18px}
header{padding:22px 0;display:flex;align-items:center;gap:12px}
.logo{width:44px;height:44px;border-radius:13px;background:linear-gradient(135deg,#06b6d4,#4f46e5);display:grid;place-items:center;font-size:24px}
.brand{font-weight:800;font-size:20px}.brand b{background:linear-gradient(90deg,#5eead4,#38bdf8);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero{padding:30px 0 10px;text-align:center}
h1{font-size:clamp(32px,6vw,54px);line-height:1.08;letter-spacing:-1px;font-weight:900}
h1 span{background:linear-gradient(90deg,#5eead4,#38bdf8 55%,#a78bfa);-webkit-background-clip:text;background-clip:text;color:transparent}
.lead{color:var(--mute);font-size:18px;max-width:640px;margin:16px auto 26px}
.btn{display:inline-flex;align-items:center;gap:10px;padding:16px 30px;border-radius:999px;background:linear-gradient(90deg,#0891b2,#2563eb);color:#fff;text-decoration:none;font-weight:800;font-size:18px;box-shadow:0 10px 30px rgba(37,99,235,.35)}
.note{color:var(--mute);font-size:14px;margin-top:12px}
.gal{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin:40px 0}
.gal img{width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.12);box-shadow:0 14px 40px rgba(0,0,0,.45);display:block}
.feat{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:10px 0 40px}
.f{background:var(--card);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:20px}
.f .i{font-size:30px}.f h3{font-size:18px;margin:8px 0 4px}.f p{color:var(--mute);font-size:15px}
.steps{counter-reset:s;display:grid;gap:10px;margin:0 0 40px}
.steps div{background:var(--card);border-radius:14px;padding:14px 16px 14px 56px;position:relative;color:var(--mute)}
.steps div::before{counter-increment:s;content:counter(s);position:absolute;left:14px;top:12px;width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--a),var(--b));color:#fff;font-weight:800;display:grid;place-items:center}
.steps b{color:var(--ink)}
h2{font-size:26px;margin:0 0 14px}
.cta{text-align:center;padding:30px 0 50px}
footer{border-top:1px solid rgba(255,255,255,.08);padding:26px 0 40px;color:var(--mute);font-size:14px}
footer h3{color:var(--ink);font-size:16px;margin:0 0 8px}
footer p{margin:0 0 10px}
</style>
</head>
<body>
<div class="w">
  <header><div class="logo">📊</div><div class="brand">Slayder<b>Ai</b></div></header>

  <section class="hero">
    <h1>Taqdimotni <span>bir necha daqiqada</span> tayyorlang</h1>
    <p class="lead">Mavzuni yozing — sun'iy intellekt reja tuzadi, slaydlar matnini yozadi, mos rasmlarni tanlaydi va chiroyli PowerPoint (PPTX) fayl tayyorlaydi. Telegram'da, ro'yxatdan o'tishsiz.</p>
    <a class="btn" href="<?= $e($tg) ?>">✈️ Telegram'da ochish</a>
    <div class="note">🎁 Birinchi taqdimot — bepul</div>
  </section>

  <div class="gal">
    <img src="assets/promo/s1.jpg" alt="PRO dizayndagi muqova slaydi" loading="lazy">
    <img src="assets/promo/s3.jpg" alt="Bosqichlar slaydi" loading="lazy">
    <img src="assets/promo/s4.jpg" alt="Oddiy dizayndagi slayd" loading="lazy">
    <img src="assets/promo/s2.jpg" alt="Ta'rif slaydi" loading="lazy">
    <img src="assets/promo/s5.jpg" alt="Kartalar slaydi" loading="lazy">
    <img src="assets/promo/s6.jpg" alt="Rus tiliga tarjima qilingan slayd" loading="lazy">
  </div>

  <h2>Imkoniyatlar</h2>
  <div class="feat">
    <div class="f"><div class="i">🎨</div><h3>17 xil dizayn</h3><p>Oddiy va PRO (16:9, katta fotosuratlar, animatsiyalar).</p></div>
    <div class="f"><div class="i">🖼</div><h3>Mos rasmlar</h3><p>Har slaydga mavzuga mos rasm avtomatik tanlanadi.</p></div>
    <div class="f"><div class="i">🎤</div><h3>Himoyaga tayyorgarlik</h3><p>Har slayd bo'yicha konspekt va ehtimoliy savol-javoblar.</p></div>
    <div class="f"><div class="i">✏️</div><h3>Tahrirlash</h3><p>Tayyor taqdimotni bot ichida o'zgartirish mumkin.</p></div>
    <div class="f"><div class="i">🌐</div><h3>3 tilda</h3><p>O'zbek, rus va ingliz tillarida; tayyor faylni tarjima qilish.</p></div>
    <div class="f"><div class="i">📚</div><h3>Ishonchli manbalar</h3><p>Ochiq ilmiy bazalardan haqiqiy adabiyotlar ro'yxati.</p></div>
  </div>

  <h2>Qanday ishlaydi</h2>
  <div class="steps">
    <div><b>Botni oching</b> — «Telegram'da ochish» tugmasini bosing va «Start» ni bosing.</div>
    <div><b>Hajm va dizaynni tanlang</b> — slaydlar soni va 17 ta dizayndan birini.</div>
    <div><b>Mavzuni yozing</b> — bir necha daqiqada tayyor PPTX fayl shu chatga keladi.</div>
  </div>

  <div class="cta"><a class="btn" href="<?= $e($tg) ?>">✈️ Telegram'da ochish</a><div class="note">Matn sun'iy intellekt yordamida yaratiladi — topshirishdan oldin mazmunini ko'rib chiqing.</div></div>

  <footer id="privacy">
    <h3>Maxfiylik siyosati</h3>
    <p>Bu sahifa hech qanday shaxsiy ma'lumot yig'maydi va cookie ishlatmaydi. Botda faqat xizmat uchun zarur ma'lumotlar saqlanadi: Telegram ID, siz kiritgan mavzu va titul varag'i uchun ism, guruh, universitet. Bu ma'lumotlar uchinchi shaxslarga sotilmaydi va berilmaydi; faqat buyurtmani bajarish (matn yaratish uchun AI xizmatiga mavzu yuboriladi) va to'lovlarni hisobga olish uchun ishlatiladi.</p>
    <p>Ma'lumotlaringizni o'chirishni so'rash yoki savol berish uchun botdagi «💬 Yordam» bo'limiga yozing.</p>
    <p>© <?= date('Y') ?> SlayderAi</p>
  </footer>
</div>
</body>
</html>
