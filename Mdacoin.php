<?php
// 🔐 Config
define('API_KEY','6728105149:AAHBshZKUZ5e41NpemY6pVFVGQ8iu-Ba9ec');
define('ADMIN_ID','1999997369');
define('MIN_BET',100);
define('MAX_LIMIT_BET',10000);
$admin = "1999997369";
function bot($m,$d=[]){$d['method']=$m;$c=curl_init();curl_setopt_array($c,[CURLOPT_URL=>"https://api.telegram.org/bot".API_KEY."/",CURLOPT_RETURNTRANSFER=>1,CURLOPT_POSTFIELDS=>http_build_query($d)]);return json_decode(curl_exec($c),1);}
function save($f,$a){file_put_contents($f,json_encode($a));}

$u = json_decode(file_get_contents('php://input'), true);

$message = $u['message'] ?? null;
$callback = $u['callback_query'] ?? null;
$m = $message ?? $callback['message'] ?? null;

$cid = $m['chat']['id'] ?? null;
$mid = $m['message_id'] ?? null;
$uid = $message['from']['id'] ?? $callback['from']['id'] ?? null;

$tx = $message['text'] ?? null;
$data = $callback['data'] ?? null;

// Javob yuborish (optional)
if ($data) {
    bot('answerCallbackQuery', [
        'callback_query_id' => $u['callback_query']['id'],
        'text' => "⏳",
        'show_alert' => false
    ]);
}

if(!file_exists('users.json'))save('users.json',[]);
if(!file_exists('games.json'))save('games.json',[]);
$users=json_decode(file_get_contents('users.json'),1);
$games=json_decode(file_get_contents('games.json'),1);
$promos = json_decode(file_get_contents("promocodes.json"), true);

// Fayllar va papkalarni avto-yaratish
$required_files = ['users.json', 'duel.json'];
$required_dirs = ['step'];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
    }
}

foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir);
    }
}








$menu = [
    'keyboard' => [
        [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
        [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
        [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
        [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']]
    ],
    'resize_keyboard' => true
];



// Kanal ro'yxati
if(!file_exists('channels.json')) save('channels.json', []);
$channels = json_decode(file_get_contents("channels.json"), true);

// Obunani tekshiruvchi funksiya
function isSubscribed($uid, $channels){
    foreach($channels as $ch){
        $r = bot('getChatMember', ['chat_id'=>"@$ch", 'user_id'=>$uid]);
        if(!in_array($r['result']['status'], ['creator','administrator','member'])){
            return false;
        }
    }
    return true;
}

// Obuna tugmalarini chiqarish
function showSubscribeButtons($cid){
    global $channels;
    $btns = [];
    foreach($channels as $ch){
        $btns[] = [['text'=>"➕ @$ch", 'url'=>"https://t.me/$ch"]];
    }
    $btns[] = [['text'=>"✅ Tekshirish", 'callback_data'=>"check_sub"]];
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"❗ <b>Botdan foydalanish uchun quyidagi kanallarga obuna bo‘ling:</b>",
        'parse_mode'=>'html',
        'reply_markup'=>json_encode(['inline_keyboard'=>$btns])
    ]);
}

// /start komandasi ishlovchisi
if(strpos($tx,"/start") === 0){
    $r = explode(" ", $tx)[1] ?? null;

    // Foydalanuvchini bazaga qo‘shish
    if(!isset($users[$uid])){
        if($r && $r != $uid && isset($users[$r])){
            $users[$uid]['ref'] = $r;
        }
        $users[$uid] = [
            'b' => 0,
            'ref_bonus' => 0,
            'games' => 0,
            'won' => 0,
            'lost' => 0,
            'withdraw' => 0,
            'name' => $m['from']['first_name']
        ];
    }

    // Obuna tekshiruvi
    if(!isSubscribed($uid, $channels)){
        showSubscribeButtons($cid);
        exit();
    }

    // Referal bonusi
    if(isset($users[$uid]['ref']) && empty($users[$uid]['bonus_given'])){
        $rid = $users[$uid]['ref'];
        $users[$rid]['b'] += 100;
        $users[$rid]['ref_bonus'] += 100;
        $users[$uid]['bonus_given'] = true;
        bot('sendMessage',[
            'chat_id'=>$rid,
            'text'=>"👥 Yangi referal qo‘shildi! +100 so‘m"
        ]);
    }

    // Asosiy menyu
    $menu = [
        'keyboard' => [
            [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
            [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
            [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
            [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']]
        ],
        'resize_keyboard' => true
    ];

    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"👋 <b>{$m['from']['first_name']}</b>, xush kelibsiz!\n💰 Balans: {$users[$uid]['b']} so‘m",
        'parse_mode'=>'html',
        'reply_markup'=>json_encode($menu)
    ]);

    // Foydalanuvchini yangilab saqlash
    file_put_contents("users.json", json_encode($users));
}

// Callback tugmasi: Obunani tekshirish
if($data == "check_sub"){
    if(isSubscribed($uid, $channels)){
        bot('editMessageText', [
            'chat_id' => $cid,
            'message_id' => $mid,
            'text' => "✅ Obuna tasdiqlandi! Endi botdan foydalanishingiz mumkin.",
        ]);

        // Asosiy menyu qaytadan yuboriladi
        $menu = [
            'keyboard' => [
                [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
                [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
                [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
                [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']]
            ],
            'resize_keyboard' => true
        ];
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"✅ Obuna muvaffaqiyatli! Bot menyusidan foydalanishingiz mumkin.",
            'reply_markup'=>json_encode($menu)
        ]);
    } else {
        bot('answerCallbackQuery', [
            'callback_query_id' => $qid,
            'text' => "❗ Hali ham barcha kanallarga obuna emassiz.",
            'show_alert' => true
        ]);
    }
}

// Statistika ko‘rish
if($tx == "📊 Statistika" && $uid == $admin){
    // Foydalanuvchilar faylini o‘qish
    $users = json_decode(file_get_contents("users.json"), true);

    $all = count($users);
    $with_refs = count(array_filter($users, fn($u) => isset($u['ref'])));
    $today = date('Y-m-d');

    // Bugun ro‘yxatdan o‘tganlar
    $today_users = count(array_filter($users, fn($u) =>
        isset($u['date']) && $u['date'] == $today
    ));

    // Oxirgi 24 soat ichida aktiv foydalanuvchilar
    $active_24h = count(array_filter($users, fn($u) =>
        isset($u['last_active']) && (time() - $u['last_active']) <= 86400
    ));

    // Bloklagan foydalanuvchilar (agar mavjud bo‘lsa)
    $blocked = count(array_filter($users, fn($u) =>
        isset($u['blocked']) && $u['blocked'] === true
    ));

    // Xabar matni
    $text = "📊 <b>Statistika</b>:\n";
    $text .= "👥 Umumiy foydalanuvchilar: <b>$all</b>\n";
    $text .= "🔗 Referallar orqali kirganlar: <b>$with_refs</b>\n";
    $text .= "🆕 Bugun qo‘shilganlar: <b>$today_users</b>\n";
    $text .= "💡 24 soat ichida faol: <b>$active_24h</b>\n";
    if ($blocked > 0) {
        $text .= "🚫 Bloklagan foydalanuvchilar: <b>$blocked</b>\n";
    }

    // Natijani yuborish
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $text,
        'parse_mode' => 'html'
    ]);
}

// Inline tugma bosilganda javob
if(isset($u['callback_query'])){
    bot('answerCallbackQuery', [
        'callback_query_id'=>$u['callback_query']['id'],
        'text'=>"✅ Amal bajarilmoqda...",
        'show_alert'=>false
    ]);
}

// /admin buyrug‘i
if($tx == "📢 Kanallarni sozlash" && $uid == $admin){
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🔧 <b>Majburiy kanal sozlamalari</b>",
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text'=>"➕ Kanal qo‘shish",'callback_data'=>"add_channel"]],
                [['text'=>"📋 Kanallar ro‘yxati",'callback_data'=>"list_channels"]],
            ]
        ])
    ]);
}


elseif($tx == "/admin"){
 
    
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"Boshqaruv panelidasiz:",
        'reply_markup'=>json_encode([
            'keyboard'=>[
                [['text' => '📢 Kanallarni sozlash'], ['text' => '📊 Statistika']],
            [['text'=>"🎟 Promokod Sozlamalari"], ['text' => '✉  Xabar yuborish']],
            [['text'=>"🔎 Foydalanuvchini boshqarish"]],
            [['text'=>"🔙 Asosiy menyuga qaytish"]],
            ],
            'resize_keyboard'=>true
        ])
    ]);
}


elseif($tx == "📈 Birja"){
 
    
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"Birja hush kelipsiz:",
        'reply_markup'=>json_encode([
            'keyboard'=>[
                [['text' => '🪙 MDACoin'], ['text' => '➕ Cryupto Coin Yaratish']],
            [['text'=>"🔙 Asosiy menyuga qaytish"]],
            ],
            'resize_keyboard'=>true
        ])
    ]);
}


elseif($tx == "🎲 O‘yinxonasi"){
 
    
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"Birja hush kelipsiz:",
        'reply_markup'=>json_encode([
            'keyboard'=>[
                [['text' => '1️⃣ Vs 1️⃣']],
            [['text'=>"🔙 Asosiy menyuga qaytish"]],
            ],
            'resize_keyboard'=>true
        ])
    ]);
}



elseif($tx == "🔙 Ortga qaytish"){
    unset($users[$uid]['step']);
    unset($users[$uid]['withdraw_sum']);
    save('users.json', $users);
    
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"❌ Pul chiqarish bekor qilindi.",
        'reply_markup'=>json_encode([
            'keyboard'=>[
                [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
            [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
            [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
            [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']]
            ],
            'resize_keyboard'=>true
        ])
    ]);
}



// Kanal qo‘shish step boshlanishi
elseif($data == "add_channel" && $uid == $admin){
    file_put_contents("step_$uid.txt", "add_channel");
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"📥 Kanalni <b>@username</b> formatida yuboring:",
        'parse_mode'=>'html'
    ]);
}


elseif($tx == "1️⃣ Vs 1️⃣") {
    $msg = "🎮 <b>O‘yinxona</b>\n";
    $msg .= "Quyidagi bo‘limlardan birini tanlang:";

    $keyboard = [
        'keyboard' => [
            [['text'=>"🎮 O‘yin yaratish"], ['text'=>"📥 O‘yinlar"]],
            [['text'=>"🔙 Asosiy menyuga qaytish"]]
        ],
        'resize_keyboard' => true
    ];

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $msg,
        'parse_mode' => 'html',
        'reply_markup' => json_encode($keyboard)
    ]);
}



// Kanal qo‘shish — matn yuborilganda
elseif(file_exists("step_$uid.txt") && file_get_contents("step_$uid.txt") == "add_channel" && $uid == $admin && strpos($tx,"@")===0){
    $channels = json_decode(file_get_contents("channels.json"),1) ?: [];
    $username = str_replace("@","",$tx);
    if(!in_array($username, $channels)){
        $channels[] = $username;
        file_put_contents("channels.json", json_encode($channels));
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"✅ <b>Kanal qo‘shildi:</b> @$username",
            'parse_mode'=>'html'
        ]);
    } else {
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"⚠️ Bu kanal allaqachon mavjud.",
            'parse_mode'=>'html'
        ]);
    }
    unlink("step_$uid.txt");
}

// Kanallar ro'yxati
elseif($data == "list_channels" && $uid == $admin){
    $channels = json_decode(file_get_contents("channels.json"),1) ?: [];
    if(!$channels){
        bot('sendMessage',['chat_id'=>$cid,'text'=>"❌ Kanal yo‘q"]);
    } else {
        $buttons = [];
        $txt = "📋 <b>Obuna kanallari ro‘yxati:</b>\n\n";
        foreach($channels as $k => $ch){
            $title = bot('getChat',['chat_id'=>"@$ch"])['result']['title'] ?? $ch;
            $txt .= ($k+1).". @$ch ($title)\n";
            $buttons[] = [['text'=>"❌ $title",'callback_data'=>"delch_$k"]];
        }
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>$txt,
            'parse_mode'=>'html',
            'reply_markup'=>json_encode(['inline_keyboard'=>$buttons])
        ]);
    }
}

// Kanal o‘chirish
elseif(strpos($data, "delch_") === 0 && $uid == $admin){
    $channels = json_decode(file_get_contents("channels.json"),1) ?: [];
    $id = (int)str_replace("delch_", "", $data);
    if(isset($channels[$id])){
        $del = $channels[$id];
        unset($channels[$id]);
        $channels = array_values($channels);
        file_put_contents("channels.json", json_encode($channels));
        bot('editMessageText',[
            'chat_id'=>$cid,
            'message_id'=>$mid,
            'text'=>"❌ @$del kanal o‘chirildi."
        ]);
    }
}

elseif($tx == "🔙 Asosiy menyuga qaytish") {
    $menu = [
        'keyboard' => [
            [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
            [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
            [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
            [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']]
        ],
        'resize_keyboard' => true
    ];

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🔙 Asosiy menyuga qaytdingiz!",
        'reply_markup' => json_encode($menu)
    ]);
}


// Fayllarni o‘qish
$users = json_decode(file_get_contents("users.json"), true);
$promos = json_decode(file_get_contents("promocodes.json"), true);
$step = json_decode(file_get_contents("step.json"), true);

// 💼 Balans menyusi
if($tx == '💼 Balans'){
    $user = $users[$uid];
    $bal = $user['b'] ?? 0;
    $mdacoin = $user['mdacoin'] ?? 0;
    $ref_bonus = $user['ref_bonus'] ?? 0;
    $won = $user['won'] ?? 0;
    $lost = $user['lost'] ?? 0;
    $all = $won + $lost;
    $percent = $all > 0 ? round($won / $all * 100) : 0;

    $ranking = [];
    foreach($users as $id => $u){
        $ranking[] = ['id' => $id, 'won' => $u['won'] ?? 0, 'name' => $u['name'] ?? 'No name'];
    }
    usort($ranking, fn($a, $b) => $b['won'] <=> $a['won']);
    $top = $ranking[0] ?? null;
    $your_rank = 0;
    foreach($ranking as $i => $r){
        if($r['id'] == $uid){
            $your_rank = $i + 1;
            break;
        }
    }

    $msg = "💼 <b>Balans holati</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "💰 <b>Asosiy balans:</b> <code>$bal</code> so‘m\n";
    $msg .= "🪙 <b>MDACoin:</b> <code>$mdacoin</code> ta\n";
    $msg .= "🎁 <b>Referal bonusi:</b> <code>$ref_bonus</code> so‘m\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🎮 <b>O‘yin statistikasi</b>\n";
    $msg .= "🔢 Umumiy o‘yinlar: <code>$all</code>\n";
    $msg .= "🏆 G‘alabalar: <code>$won</code>\n";
    $msg .= "😓 Mag‘lubiyatlar: <code>$lost</code>\n";
    $msg .= "📊 G‘alaba foizi: <code>$percent%</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🏅 <b>Top o‘yinchi:</b> ";
    if($top){
        $msg .= "<a href='tg://user?id={$top['id']}'>{$top['name']}</a> ({$top['won']} g‘alaba)\n";
    } else {
        $msg .= "Aniqlanmadi\n";
    }
    if($top && $uid == $top['id']){
        $msg .= "🟢 <b>Siz hozirda Top o‘yinchisiz!</b>\n";
    } else {
        $msg .= "📌 <b>Sizning o‘rningiz:</b> <code>$your_rank</code>\n";
    }

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $msg,
        'parse_mode' => 'html',
        'disable_web_page_preview' => true,
        'reply_markup'=>json_encode([
            'inline_keyboard'=>[
                [['text'=>"🎟 Promokod",'callback_data'=>"enter_promo"]],
            ]
        ])
    ]);
}

// 🎟 Promo kod tugmasi bosilganda
if($data == "enter_promo"){
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"🎟 Promo kodni kiriting:",
        'reply_markup'=>json_encode([
            'inline_keyboard'=>[
                [['text'=>"❌ Bekor qilish",'callback_data'=>"cancel"]],
            ]
        ])
    ]);
    $step[$uid] = "enter_promo";
    file_put_contents("step.json", json_encode($step));
}

// Promo kod yozilganda (matnli xabar)
if(isset($tx) && isset($step[$uid]) && $step[$uid] == "enter_promo"){
    $code = strtoupper(trim($tx));

    if(isset($promos[$code])){
        if($promos[$code]['used'] < $promos[$code]['limit']){
            if(!in_array($uid, $promos[$code]['users'])){
                $bonus = $promos[$code]['amount'];
                $users[$uid]['b'] += $bonus;
                $promos[$code]['used']++;
                $promos[$code]['users'][] = $uid;
                file_put_contents("users.json", json_encode($users));
                file_put_contents("promocodes.json", json_encode($promos));
                bot('sendMessage',[
                    'chat_id'=>$cid,
                    'text'=>"✅ Promo kod qabul qilindi! +$bonus so‘m bonus olindi."
                ]);
            } else {
                bot('sendMessage',[
                    'chat_id'=>$cid,
                    'text'=>"⚠️ Bu promo kod siz tomonidan allaqachon ishlatilgan."
                ]);
            }
        } else {
            bot('sendMessage',[
                'chat_id'=>$cid,
                'text'=>"⛔ Bu promo kod ishlatilish limitiga yetgan."
            ]);
        }
    } else {
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Noto‘g‘ri promo kod!"
        ]);
    }

    unset($step[$uid]);
    file_put_contents("step.json", json_encode($step));
}

// ❌ Bekor qilish tugmasi
if($data == "cancel"){
    bot('editMessageText',[
        'chat_id'=>$cid,
        'message_id'=>$mid,
        'text'=>"❌ Bekor qilindi."
    ]);
    unset($step[$uid]);
    file_put_contents("step.json", json_encode($step));
}

// 👑 Admin promo kod yaratishi (/promo KOD BONUS LIMIT)
$promos = json_decode(file_get_contents("promocodes.json"), true);
$step = json_decode(file_get_contents("step.json"), true);
$temp = json_decode(file_get_contents("temp.json"), true);

// 🔁 Bot username olish
$me = bot('getMe');
$botname = $me['result']['username']; // bot @username

// 1️⃣ Boshlanish: Promokod menyusi
if($tx == "🎟 Promokod Sozlamalari" && $uid == $admin){
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"🎟 Promo kod nomini kiriting (masalan: <code>WELCOME</code>):",
        'parse_mode'=>'html'
    ]);
    $step[$uid] = "promo_name";
    file_put_contents("step.json", json_encode($step));
}

// 2️⃣ Promo nomi qabul qilinadi
elseif($step[$uid] == "promo_name" && $uid == $admin){
    $temp[$uid]['code'] = strtoupper(trim($tx));
    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"💰 Bonus miqdorini kiriting (so‘mda):"
    ]);
    $step[$uid] = "promo_bonus";
    file_put_contents("step.json", json_encode($step));
    file_put_contents("temp.json", json_encode($temp));
}

// 3️⃣ Bonus miqdori
elseif($step[$uid] == "promo_bonus" && $uid == $admin){
    if(is_numeric($tx)){
        $temp[$uid]['amount'] = (int)$tx;
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"♻ Nechta foydalanuvchi ishlata olishini kiriting:"
        ]);
        $step[$uid] = "promo_limit";
        file_put_contents("step.json", json_encode($step));
        file_put_contents("temp.json", json_encode($temp));
    } else {
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Iltimos, raqam kiriting!"
        ]);
    }
}

// 4️⃣ Limit va yaratish
elseif($step[$uid] == "promo_limit" && $uid == $admin){
    if(is_numeric($tx)){
        $code = $temp[$uid]['code'];
        $amount = $temp[$uid]['amount'];
        $limit = (int)$tx;

        $promos[$code] = [
            'amount' => $amount,
            'limit' => $limit,
            'used' => 0,
            'users' => []
        ];
        file_put_contents("promocodes.json", json_encode($promos));
        unset($step[$uid], $temp[$uid]);
        file_put_contents("step.json", json_encode($step));
        file_put_contents("temp.json", json_encode($temp));

        // ✅ Adminga xabar
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"✅ Promo kod yaratildi:\n\n🎟 Kod: <code>$code</code>\n💰 Bonus: $amount so‘m\n♻ Limit: $limit ta",
            'parse_mode'=>'html'
        ]);

        // 📢 Kanalga e'lon
        $channel_id = "@crypto_new_uz"; // ← O‘z kanalingizni yozing

        $promo_text = "🎉 <b>Yangi PROMO KOD!</b>\n\n".
                      "🎟 Kod: <code>$code</code>\n".
                      "💰 Bonus: <b>$amount</b> so‘m\n".
                      "♻ Limit: <b>$limit ta</b>\n\n".
                      "⏳ <i>Shoshiling, cheklangan miqdorda!</i>";

        bot('sendMessage',[
            'chat_id' => $channel_id,
            'text' => $promo_text,
            'parse_mode' => 'html',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text'=>"🎟 Promo kodni faollashtirish", 'url'=>"https://t.me/$botname"]],
                    [['text'=>"🤖 Botga o‘tish", 'url'=>"https://t.me/$botname"]],
                ]
            ])
        ]);
    } else {
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Iltimos, raqam kiriting!"
        ]);
    }
}

// Fayl mavjud emas bo‘lsa, MDACoin faylini yaratish
if (!file_exists("mdacoin.json")) {
    $md = [
        'price' => 100,
        'supply' => 1000000,
        'circulating' => 0
    ];
    file_put_contents("mdacoin.json", json_encode($md));
}

// --- Fayl mavjud emas bo‘lsa, avtomatik yaratish ---
if (!file_exists("mdacoin.json")) {
    $md = [
        'price' => 100,         // 1 ta MDACoin boshlang‘ich narxi
        'supply' => 1000000,    // Jami 1 million coin mavjud
        'circulating' => 0,     // Aylanmadagi miqdor
        'price_history' => []   // Narxlar tarixi (timestamp => price)
    ];
    file_put_contents("mdacoin.json", json_encode($md));
}

elseif ($tx == "🪙 MDACoin") {
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $user = $users[$uid] ?? [];
    $userCoins = $user['mdacoin'] ?? 0;
    $bal = $user['b'] ?? 0;

    $price = number_format($md['price'], 2, '.', '');
    $supply = $md['supply'];
    $circulating = $md['circulating'];
    $left = $supply - $circulating;

    $text = "🪙 <b>MDACoin bo‘limi</b>\n\n";
    $text .= "📈 <b>Joriy narx:</b> <code>$price</code> so‘m\n";
    $text .= "🔢 <b>Sotuvdagi jami miqdor:</b> <code>$left</code> ta\n";
    $text .= "💼 <b>Sizda:</b> <code>$userCoins</code> ta\n";
    $text .= "💰 <b>Balansingiz:</b> <code>$bal</code> so‘m\n\n";
    $text .= "⚠ Sotishda 2.5% komissiya olinadi!\n";

    // 1. Reply keyboardni o‘chirish
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "⏳ Yuklanmoqda...",
        'reply_markup' => json_encode([
            'remove_keyboard' => true
        ])
    ]);

    // 2. Inline tugmalar bilan asosiy xabar
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $text,
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => "💰 MDACoin sotib olish", "callback_data" => "buy_mdacoin"]],
                [['text' => "📤 MDACoin sotish", "callback_data" => "sell_mdacoin"]],
                [['text' => "📊 Narx grafigi", "callback_data" => "price_chart"]],
                [['text' => '❌ Ortga Qaytish', 'callback_data' => 'cancel_buy_mdacoin']]
            ]
        ])
    ]);
}

// --- MDACoin sotib olish menyusi ---
elseif ($data == "buy_mdacoin") {
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $price = number_format($md['price'], 2, '.', '');
    $supply = $md['supply'];
    $circulating = $md['circulating'];
    $left = $supply - $circulating;
    $bal = $users[$uid]['b'] ?? 0;

    $text = "💰 <b>MDACoin sotib olish</b>\n\n";
    $text .= "1 ta MDACoin narxi: <b>$price so‘m</b>\n";
    $text .= "Jami mavjud: <b>$supply</b>\n";
    $text .= "Aylanmada: <b>$circulating</b>\n";
    $text .= "Yangi sotib olish miqdorini kiriting (kamida 100, masalan: 200)\n\n";
    $text .= "💳 Sizda: <b>$bal</b> so‘m\n";
    $text .= "⚠ Minimal: 100 ta, maksimal: $left ta";

    $users[$uid]['step'] = 'buy_mdacoin';
    save("users.json", $users);

    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $text,
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '❌ Bekor qilish', 'callback_data' => 'cancel_buy_mdacoin']]
            ]
        ])
    ]);
}

// --- MDACoin sotish menyusi ---
elseif ($data == "sell_mdacoin") {
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $price = number_format($md['price'], 2, '.', '');
    $userCoins = $users[$uid]['mdacoin'] ?? 0;

    $text = "📤 <b>MDACoin sotish</b>\n\n";
    $text .= "1 ta MDACoin narxi: <b>$price so‘m</b>\n";
    $text .= "Sizda: <b>$userCoins</b> ta MDACoin mavjud\n";
    $text .= "Sotish miqdorini kiriting (kamida 100, maksimal $userCoins ta).\n\n";
    $text .= "⚠ Sotishda 2.5% komissiya olinadi!\n";

    $users[$uid]['step'] = 'sell_mdacoin';
    save("users.json", $users);

    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $text,
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '❌ Bekor qilish', 'callback_data' => 'cancel_sell_mdacoin']]
            ]
        ])
    ]);
}

// --- Bekor qilish (sotib olish yoki sotish uchun) ---
elseif ($data == "cancel_buy_mdacoin" || $data == "cancel_sell_mdacoin") {
    unset($users[$uid]['step']);
    save("users.json", $users);

    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => "❌ Amaliyot bekor qilindi.",
        'parse_mode' => 'html'
    ]);

    // Asosiy menyuni qayta chiqarish
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🏠 Asosiy menyu:",
        'reply_markup' => json_encode([
            'keyboard' => [
                [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
                [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
                [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
                [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']],
            ],
            'resize_keyboard' => true
        ])
    ]);
}

// --- MDACoin sotib olish amalga oshirilganda ---
elseif (isset($users[$uid]['step']) && $users[$uid]['step'] == 'buy_mdacoin' && $tx != "") {
    if (!is_numeric($tx)) return;

    $amount = (int)$tx;
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $price = $md['price'];
    $total_price = $amount * $price;
    $left = $md['supply'] - $md['circulating'];
    $balance = $users[$uid]['b'] ?? 0;

    if ($amount < 100 || $amount > $left) {
        return bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "❌ Noto‘g‘ri miqdor. 100 dan $left tagacha kiriting."
        ]);
    }

    if ($balance < $total_price) {
        return bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "❌ Balansingizda mablag‘ yetarli emas.\n💰 Narx: $total_price so‘m"
        ]);
    }

    // Xarid amalga oshiriladi
    $users[$uid]['b'] -= $total_price;
    $users[$uid]['mdacoin'] = ($users[$uid]['mdacoin'] ?? 0) + $amount;
    $md['circulating'] += $amount;

    // Narx tarixiga yozish (hozirgi vaqt va narx)
    $md['price_history'][] = ['time' => time(), 'price' => $md['price']];

    // Dinamik narxni yangilash
    $md['price'] = 100 + floor($md['circulating'] / 1000) * 5;

    unset($users[$uid]['step']);
    save("users.json", $users);
    file_put_contents("mdacoin.json", json_encode($md));

    bot('sendMessage', [
    'chat_id' => $cid,
    'text' => "✅ <b>$amount</b> ta MDACoin muvaffaqiyatli sotib olindi!\n💸 <b>$total_price</b> so‘m balansingizdan yechildi.",
    'parse_mode' => 'html'
]);

// Asosiy menyuni chiqarish
bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🏠 Asosiy menyu:",
        'reply_markup' => json_encode([
            'keyboard' => [
                [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
                [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
                [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
                [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']],
            ],
            'resize_keyboard' => true
        ])
    ]);

}

// --- MDACoin sotish amalga oshirilganda ---
elseif (isset($users[$uid]['step']) && $users[$uid]['step'] == 'sell_mdacoin' && $tx != "") {
    if (!is_numeric($tx)) return;

    $amount = (int)$tx;
    $userCoins = $users[$uid]['mdacoin'] ?? 0;
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $price = $md['price'];
    $balance = $users[$uid]['b'] ?? 0;

    if ($amount < 100 || $amount > $userCoins) {
        return bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "❌ Noto‘g‘ri miqdor. 100 dan $userCoins tagacha kiriting."
        ]);
    }

    $total_price = $amount * $price;
    $commission = ceil($total_price * 0.025); // 1% komissiya (yuqoriga qarab yaxlitlash)
    $received = $total_price - $commission;

    // Sotish amalga oshiriladi
    $users[$uid]['mdacoin'] -= $amount;
    $users[$uid]['b'] += $received;

    // Aylanmadagi miqdor kamayadi
    $md['circulating'] -= $amount;

    // Narx tarixiga yozish
    $md['price_history'][] = ['time' => time(), 'price' => $md['price']];

    // Dinamik narx yangilanishi (agar xohlasangiz)
    $md['price'] = max(100, 100 + floor($md['circulating'] / 1000) * 5);

    unset($users[$uid]['step']);
    save("users.json", $users);
    file_put_contents("mdacoin.json", json_encode($md));

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "✅ <b>$amount</b> ta MDACoin sotildi!\n💰 Sizga tushgan summa: <b>$received so‘m</b> (2.5% komissiya yechildi).",
        'parse_mode' => 'html'
    ]);
}

// --- Komissiya yig‘imi (admin hisobiga qo‘shish) ---
$admin_id = 1999997369; // Admin Telegram ID
if (!isset($users[$admin_id]['b'])) {
    $users[$admin_id]['b'] = 0;
}
$users[$admin_id]['b'] += $commission;
save("users.json", $users);


// --- MDACoin TOP 10 egalarini chiqarish ---
if ($data == "top_mdacoin") {
    // Foydalanuvchilardan MDACoin balansi borlarini ajratib olamiz
    $top = [];
    foreach ($users as $id => $user) {
        if (isset($user['mdacoin']) && $user['mdacoin'] > 0) {
            $top[$id] = $user['mdacoin'];
        }
    
    
    // Balans boʻyicha saralaymiz (kamayish tartibida)
    arsort($top);
    
    // Faqat TOP 10 talik roʻyxatni olamiz
    $top10 = array_slice($top, 0, 10, true);
    
    // Chiqish matnini tayyorlaymiz
    $result = "🏆 MDACoin TOP 10 egasi:\n\n";
    $position = 1;
    
    foreach ($top10 as $id => $coins) {
        $username = isset($users[$id]['username']) 
                   ? '@' . $users[$id]['username'] 
                   : "Anonim #$id";
        $result .= "$position. $username - $coins MDACoin\n";
        $position++;
    }
    
    return $result;
}


    if (empty($top10)) {
        $text = "🏆 <b>MDACoin Top 10 egalari:</b>\n\nHozircha MDACoin egasi yo‘q.";
    } else {
        $text = "🏆 <b>MDACoin Top 10 egalari:</b>\n\n";
        $rank = 1;
        foreach ($top10 as $id => $coins) {
            $username = htmlspecialchars($users[$id]['username'] ?? 'Foydalanuvchi');
            $text .= "$rank. @$username — <b>$coins</b> ta\n";
            $rank++;
        }
    }


    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $text,
        'parse_mode' => 'html',
        'disable_web_page_preview' => true
    ]);
}




// --- Narx grafikini ko‘rsatish (oddiy matnli) ---
elseif ($data === "price_chart") {
    $md = json_decode(file_get_contents("mdacoin.json"), true);
    $history = $md['price_history'] ?? [];
    $text = "📊 <b>MDACoin narxining oxirgi 10 o‘zgarishi:</b>\n\n";

    $last10 = array_slice($history, -10);
    if (empty($last10)) {
        $text .= "Narx tarixi mavjud emas.";
    } else {
        foreach ($last10 as $entry) {
            $time = date('H:i:s d-m', $entry['time']);
            $price = number_format($entry['price'], 2, '.', '');
            $text .= "$time — $price so‘m\n";
        }
    }

    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $text,
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '🔙 Orqaga', 'callback_data' => '📈 Birja']]
            ]
        ])
    ]);
}



elseif($tx=='🎁 Bonus'){
  $t=time();
  if($t-($users[$uid]['bonus']??0)>=86400){$users[$uid]['bonus']=$t;$users[$uid]['b']+=200;
    bot('sendMessage',['chat_id'=>$cid,'text'=>'🎁 Sizga 200 so‘m bonus berildi!']);
  }else bot('sendMessage',['chat_id'=>$cid,'text'=>'⏳ Keyingi bonus 24 soatdan keyin.']);save('users.json',$users);
}



elseif($tx == "👥 Referallarim"){

    $users = json_decode(file_get_contents("users.json"), true) ?: [];
    $me = bot('getMe');
    $bot_username = $me['result']['username'] ?? 'your_bot';
    $ref_link = "https://t.me/$bot_username?start=$uid";

    $referal_count = 0;
    foreach($users as $id => $user){
        if(isset($user['ref']) && $user['ref'] == $uid){
            $referal_count++;
        }
    }

    $ref_bonus = $users[$uid]['ref_bonus'] ?? 0;

    $msg = "👥 <b>Referallarim</b>\n";
    $msg .= "━━━━━━━━━━━━━━\n";
    $msg .= "🔗 <b>Referal havola:</b>\n<code>$ref_link</code>\n\n";
    $msg .= "👤 <b>Taklif qilganlar soni:</b> <b>$referal_count ta</b>\n";
    $msg .= "💸 <b>Olingan bonus:</b> <b>$ref_bonus so‘m</b>\n\n";
    $msg .= "✅ Do‘stlaringiz botga kirib, majburiy kanallarga obuna bo‘lsa, sizga 100 so‘m bonus beriladi.";

    // Tugma uchun text URL formatda encode qilinmoqda
    $share_text = urlencode("💸 Botga qo‘shiling va bonus oling!");
    $share_url = "https://t.me/share/url?url=$ref_link&text=$share_text";

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $msg,
        'parse_mode' => 'html',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text'=>"📢 Ulashish", 'url'=>$share_url]]
            ]
        ])
    ]);
}


$u = json_decode(file_get_contents('php://input'), 1);
$m = $u['message'] ?? $u['callback_query']['message'];
$tx = $u['message']['text'] ?? $u['callback_query']['data'];
$cid = $m['chat']['id'];
$uid = $u['message']['from']['id'] ?? $u['callback_query']['from']['id'];
$mid = $m['message_id'];
$data = $u['callback_query']['data'] ?? null;

// 📊 Reyting menyusi
if($tx == "📊 Reyting"){
    $keyboard = [
        [['text' => '👥 Referallar', 'callback_data' => 'rank_ref']],
        [['text' => '🎮 O‘yinlar', 'callback_data' => 'rank_games']],
        [['text' => '💸 Pul chiqarish', 'callback_data' => 'rank_withdraw']],
        [['text' => '💰 Balans', 'callback_data' => 'rank_balance']],
    ];
    $txt = "🏆 <b>Reytinglar menyusi:</b>\nQuyidagilardan birini tanlang:";
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $txt,
        'parse_mode' => 'html',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
}

// 👥 Referallar bo‘yicha
elseif($tx == "rank_ref"){
    uasort($users, fn($a, $b) => ($b['ref'] ?? 0) <=> ($a['ref'] ?? 0));
    $t = 0;
    $txt = "👥 <b>Top 10 referal taklif qilganlar:</b>\n━━━━━━━━━━━━━━\n";
    foreach($users as $i => $u){
        if(++$t > 10) break;
        $name = htmlspecialchars($u['name'] ?? "Foydalanuvchi");
        $txt .= "🔹 <b>$t.</b> <a href='tg://user?id=$i'>$name</a> — <code>".($u['ref']??0)."</code> ta\n";
    }
    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $txt,
        'parse_mode' => 'html'
    ]);
}

// 🎮 O‘yinlar bo‘yicha
elseif($tx == "rank_games"){
    uasort($users, fn($a, $b) => ($b['games'] ?? 0) <=> ($a['games'] ?? 0));
    $t = 0;
    $txt = "🎮 <b>Top 10 eng ko‘p o‘yin o‘ynaganlar:</b>\n━━━━━━━━━━━━━━\n";
    foreach($users as $i => $u){
        if(++$t > 10) break;
        $name = htmlspecialchars($u['name'] ?? "Foydalanuvchi");
        $txt .= "🔹 <b>$t.</b> <a href='tg://user?id=$i'>$name</a> — <code>".($u['games']??0)."</code> ta\n";
    }
    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $txt,
        'parse_mode' => 'html'
    ]);
}

// 💸 Pul yechganlar
elseif($tx == "rank_withdraw"){
    uasort($users, fn($a, $b) => ($b['withdraw'] ?? 0) <=> ($a['withdraw'] ?? 0));
    $t = 0;
    $txt = "💸 <b>Top 10 eng ko‘p pul chiqarganlar:</b>\n━━━━━━━━━━━━━━\n";
    foreach($users as $i => $u){
        if(++$t > 10) break;
        $name = htmlspecialchars($u['name'] ?? "Foydalanuvchi");
        $txt .= "🔹 <b>$t.</b> <a href='tg://user?id=$i'>$name</a> — <code>".($u['withdraw']??0)."</code> so‘m\n";
    }
    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $txt,
        'parse_mode' => 'html'
    ]);
}

// 💰 Balans bo‘yicha
elseif($tx == "rank_balance"){
    uasort($users, fn($a, $b) => ($b['b'] ?? 0) <=> ($a['b'] ?? 0));
    $t = 0;
    $txt = "💰 <b>Top 10 eng boy o‘yinchilar:</b>\n━━━━━━━━━━━━━━\n";
    foreach($users as $i => $u){
        if(++$t > 10) break;
        $name = htmlspecialchars($u['name'] ?? "Foydalanuvchi");
        $txt .= "🔹 <b>$t.</b> <a href='tg://user?id=$i'>$name</a> — <code>".($u['b']??0)."</code> so‘m\n";
    }
    bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => $txt,
        'parse_mode' => 'html'
    ]);
}


// 🎮 O‘yin yaratish
elseif($tx == '🎮 O‘yin yaratish'){
  if(count($games) >= 6){
    return bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>6 ta faol o‘yin limiti mavjud. Yangi o‘yin yaratib bo‘lmaydi</b>", 'parse_mode' => 'html']);
  }

  bot('sendMessage', [
    'chat_id' => $cid,
    'text' => "💸 <b>Tikmoqchi bo‘lgan summani kiriting</b> (min. 100 so‘m):",
    'parse_mode' => 'html'
  ]);
  $users[$uid]['step'] = 'create';
  save('users.json', $users);
}

// 💸 Tikish miqdorini qabul qilish
elseif(is_numeric($tx) && $users[$uid]['step'] == 'create'){
  $bet = (int)$tx;
  if($bet < MIN_BET) return bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Minimal tikish 100 so‘m</b>", 'parse_mode' => 'html']);
  if($users[$uid]['b'] < $bet) return bot('sendMessage', ['chat_id' => $cid, 'text' => "❌ <b>Hisobda mablag‘ yetarli emas</b>", 'parse_mode' => 'html']);

  $users[$uid]['b'] -= $bet;
  $id = time().$uid;
  $games[$id] = ['u1' => $uid, 'bet' => $bet];

  bot('sendMessage', [
    'chat_id' => $cid,
    'text' => "✅ <b>O‘yin yaratildi!</b>\n🎮 Tikilgan summa: <b>$bet so‘m</b>\n\n<i>/cancel orqali bekor qilishingiz mumkin</i>",
    'parse_mode' => 'html'
  ]);

  unset($users[$uid]['step']);
  save('users.json', $users);
  save('games.json', $games);
}

// O'yinlar ro'yxati
elseif($tx=='📥 O‘yinlar'){
  $btn = [];
  foreach($games as $id => $g){
    if($g['u1'] != $uid && !isset($g['u2'])){
      $creator = $users[$g['u1']]['name'] ?? "Foydalanuvchi";
      $btn[] = [["text" => "💸 {$g['bet']} so‘m | 👤 $creator", "callback_data" => "join_$id"]];
    }
  }
  
  if(!empty($btn)){
    bot('sendMessage',[
      'chat_id' => $cid,
      'text' => "🎮 <b>Mavjud o‘yinlar</b>:\nQuyidagilardan birini tanlang:",
      'parse_mode' => 'HTML',
      'reply_markup' => json_encode(['inline_keyboard' => $btn])
    ]);
  } else {
    bot('sendMessage',[
      'chat_id' => $cid,
      'text' => "❌ <b>Hozircha hech qanday o‘yin mavjud emas.</b>",
      'parse_mode' => 'HTML'
    ]);
  }
}


// O'yinga qo'shilish
elseif(strpos($tx, 'join_') === 0){
  $id = str_replace('join_', '', $tx);
  if(!isset($games[$id]) || isset($games[$id]['u2']))
    return bot('answerCallbackQuery', [
      'callback_query_id' => $u['callback_query']['id'],
      'text' => '❌ Bu o‘yin mavjud emas yoki allaqachon boshlangan.'
    ]);

  $u1 = $games[$id]['u1'];
  $bet = $games[$id]['bet'];

  if($uid == $u1)
    return bot('answerCallbackQuery', [
      'callback_query_id' => $u['callback_query']['id'],
      'text' => '❌ O‘zingiz yaratgan o‘yinda qatnasha olmaysiz.'
    ]);

  if(($users[$uid]['b'] ?? 0) < $bet)
    return bot('answerCallbackQuery', [
      'callback_query_id' => $u['callback_query']['id'],
      'text' => '❌ Hisobda mablag‘ yetarli emas.'
    ]);

  $users[$uid]['b'] -= $bet;
  $games[$id]['u2'] = $uid;

  // Statistikalar
  $u1_won = $users[$u1]['won'] ?? 0;
  $u1_lost = $users[$u1]['lost'] ?? 0;
  $u1_all = $u1_won + $u1_lost;
  $u1_percent = $u1_all > 0 ? round($u1_won / $u1_all * 100) : 0;

  $u2_won = $users[$uid]['won'] ?? 0;
  $u2_lost = $users[$uid]['lost'] ?? 0;
  $u2_all = $u2_won + $u2_lost;
  $u2_percent = $u2_all > 0 ? round($u2_won / $u2_all * 100) : 0;

  // G‘olibni aniqlash: past foizli o‘yinchi doim yutadi
  if($u1_percent == $u2_percent){
    $winner = rand(0,1) ? $u1 : $uid;
  } else {
    $winner = ($u1_percent < $u2_percent) ? $u1 : $uid;
  }

  $loser = ($winner == $u1) ? $uid : $u1;

  $u1_num = rand(1,6);
  $u2_num = rand(1,6);

  function get_dice_gif($num) {
    return "https://raw.githubusercontent.com/OzzaTeam/assets/main/dice/dice_$num.gif";
  }

  $gif_u1 = get_dice_gif($u1_num);
  $gif_u2 = get_dice_gif($u2_num);

  bot('sendAnimation', [
    'chat_id' => $u1,
    'animation' => $gif_u1,
    'caption' => "🎲 <b>Sizning toshingiz: $u1_num</b>",
    'parse_mode' => 'html'
  ]);
  bot('sendAnimation', [
    'chat_id' => $uid,
    'animation' => $gif_u2,
    'caption' => "🎲 <b>Sizning toshingiz: $u2_num</b>",
    'parse_mode' => 'html'
  ]);

  // Durrang holati
  if($u1_num == $u2_num){
    $users[$u1]['b'] += $bet;
    $users[$uid]['b'] += $bet;

    bot('sendMessage', ['chat_id' => $u1, 'text' => "🤝 <b>Durrang!</b>\n💸 Pul qaytarildi.", 'parse_mode'=>'html']);
    bot('sendMessage', ['chat_id' => $uid, 'text' => "🤝 <b>Durrang!</b>\n💸 Pul qaytarildi.", 'parse_mode'=>'html']);
    unset($games[$id]);
    save('games.json', $games);
    save('users.json', $users);
    return;
  }

  // Yutuq va mag‘lubiyatni yozish
  $total = $bet * 2;
  $win = round($total * 0.9);
  $users[$winner]['b'] += $win;
  $users[$winner]['won'] += 1;
  $users[$loser]['lost'] += 1;

  bot('sendMessage', [
    'chat_id' => $winner,
    'text' => "🏆 <b>Siz g‘alaba qozondingiz!</b>\n🎲 Katta tosh: <b>$win so‘m yutdingiz</b>",
    'parse_mode' => 'html'
  ]);
  bot('sendMessage', [
    'chat_id' => $loser,
    'text' => "😔 <b>Siz yutqazdingiz.</b>\n🎲 Kichik tosh tushdi...\n🔁 Omad keyingi safarga!",
    'parse_mode' => 'html'
  ]);

  unset($games[$id]);
  save('games.json', $games);
  save('users.json', $users);
}

// 💳 Pul kiritish menyusi
elseif($tx == "💳 Pul kiritish"){
    $users[$uid]['step'] = 'enter_deposit_amount';
    save('users.json', $users);

    $btn = [
        [['text'=>"❌ Bekor qilish"]]
    ];

    bot('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"💳 Pul kiritish uchun quyidagi karta raqamiga to‘lov qiling:\n\n💳 *9860 1801 1372 4504*\n\n💰 So‘ngra istalgan miqdorni kiriting (masalan: 15000):",
        'parse_mode'=>'markdown',
        'reply_markup'=>json_encode(['keyboard'=>$btn, 'resize_keyboard'=>true])
    ]);
}

// 💸 Foydalanuvchi miqdor kiritdi
elseif($users[$uid]['step'] == 'enter_deposit_amount'){
    if($tx == "❌ Bekor qilish"){
        unset($users[$uid]['step']);
        save('users.json', $users);
        return bot('sendMessage', [
            'chat_id'=>$cid,
            'text'=>"❌ Pul kiritish bekor qilindi.",
            'reply_markup'=>json_encode(['keyboard'=>$mainmenu,'resize_keyboard'=>true])
        ]);
    }

    $amount = (int)$tx;
    if($amount < 1000){
        return bot('sendMessage', [
            'chat_id'=>$cid,
            'text'=>"❗ Minimal miqdor 1000 so‘m. Qaytadan kiriting yoki bekor qiling."
        ]);
    }

    $users[$uid]['step'] = 'upload_check';
    $users[$uid]['deposit_amount'] = $amount;
    save('users.json', $users);

    bot('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"📸 Iltimos, to‘lov chek (skrinshotini) yuboring.\n\nTo‘lovingiz: *$amount so‘m*",
        'parse_mode'=>'markdown'
    ]);
}

// 📤 Skrinshot qabul qilish
elseif($users[$uid]['step'] == 'upload_check' && isset($m['photo'])){
    $amount = $users[$uid]['deposit_amount'];
    $file_id = end($m['photo'])['file_id'];

    unset($users[$uid]['step'], $users[$uid]['deposit_amount']);
    save('users.json', $users);

    $admin_id = 1999997369; // Admin Telegram ID (o‘zingiznikini yozing)

    $btn = [
        [
            ['text'=>"✅ Hisobni to‘ldirish", 'callback_data'=>"confirm_deposit|$uid|$amount"],
            ['text'=>"❌ Bekor qilish", 'callback_data'=>"cancel_deposit|$uid"]
        ]
    ];

    bot('sendPhoto', [
        'chat_id'=>$admin_id,
        'photo'=>$file_id,
        'caption'=>"💳 *Hisob to‘ldirish so‘rovi*\n\n👤 ID: `$uid`\n💰 Miqdor: *$amount* so‘m\n📎 Chek quyida.",
        'parse_mode'=>'markdown',
        'reply_markup'=>json_encode(['inline_keyboard'=>$btn])
    ]);

    bot('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"✅ So‘rovingiz yuborildi! Admin tomonidan ko‘rib chiqiladi."
    ]);
}

// ❌ Agar foydalanuvchi boshqa narsa yuborsa (foto emas)
elseif($users[$uid]['step'] == 'upload_check'){
    bot('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"❌ Iltimos, faqat *chek skrinshotini* yuboring.",
        'parse_mode'=>'markdown'
    ]);
}

elseif(strpos($data, "confirm_deposit|") === 0){
    list(, $uid, $amount) = explode("|", $data);
    $users[$uid]['b'] += (int)$amount;
    save('users.json', $users);

    bot('sendMessage', [
        'chat_id'=>$uid,
        'text'=>"✅ Hisobingiz *$amount* so‘mga to‘ldirildi.",
        'parse_mode'=>'markdown'
    ]);
    bot('editMessageReplyMarkup', [
        'chat_id'=>$cid,
        'message_id'=>$mid,
        'reply_markup'=>json_encode(['inline_keyboard'=>[]])
    ]);
}

elseif(strpos($data, "cancel_deposit|") === 0){
    list(, $uid) = explode("|", $data);
    bot('sendMessage', [
        'chat_id'=>$uid,
        'text'=>"❌ Hisob to‘ldirish rad etildi. Iltimos, to‘lovni tekshirib qayta urinib ko‘ring."
    ]);
    bot('editMessageReplyMarkup', [
        'chat_id'=>$cid,
        'message_id'=>$mid,
        'reply_markup'=>json_encode(['inline_keyboard'=>[]])
    ]);
}

elseif($tx == "❌ Bekor qilish"){
    unset($users[$uid]['step']);
    save('users.json', $users);

    bot('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"❌ Pul kiritish jarayoni bekor qilindi.",
        'reply_markup'=>json_encode([
            'keyboard'=>[
                [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
        [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
        [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
        [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']],
            ],
            'resize_keyboard'=>true
        ])
    ]);
}

elseif($tx == '💸 Pul chiqarish'){
    if(($users[$uid]['b']??0) < 1000){
        bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Pul chiqarish uchun kamida 1000 so‘m kerak.\n💰 Balansingiz: {$users[$uid]['b']} so‘m"
        ]);
    } else {
        $users[$uid]['step'] = 'withdraw_amount';
        save('users.json', $users);
        bot('sendMessage',[
    'chat_id'=>$cid,
    'text'=>"💸 Qancha miqdorda pul chiqarmoqchisiz?\n(Minimal: 1000 so‘m)",
    'reply_markup' => json_encode([
        'keyboard' => [[['text'=>"🔙 Ortga qaytish"]]],
        'resize_keyboard' => true
    ])
]);
    }
}

elseif($users[$uid]['step'] == 'withdraw_amount'){
    $amount = (int)$tx;
    if($amount < 1000){
        return bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Minimal yechish 1000 so‘m."
        ]);
    }
    if($amount > $users[$uid]['b']){
        return bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ Balansingizda bu miqdor yo‘q.\n💰 Balans: {$users[$uid]['b']} so‘m"
        ]);
    }
    $users[$uid]['step'] = 'withdraw_card';
    $users[$uid]['withdraw_sum'] = $amount;
    save('users.json', $users);
    bot('sendMessage',[
    'chat_id'=>$cid,
    'text'=>"💳 Karta raqamingizni yuboring (masalan: 9860xxxxxxxxxxxx):",
    'reply_markup' => json_encode([
        'keyboard' => [[['text'=>"🔙 Ortga qaytish"]]],
        'resize_keyboard' => true
    ])
]);
}

elseif($users[$uid]['step'] == 'withdraw_card'){
    $card = trim($tx);
    if(!preg_match('/^\d{16}$/', $card)){
        return bot('sendMessage',[
            'chat_id'=>$cid,
            'text'=>"❌ 16 xonali karta raqamini kiriting."
        ]);
    }

    $amount = $users[$uid]['withdraw_sum'];
    $users[$uid]['b'] -= $amount;
    unset($users[$uid]['step'], $users[$uid]['withdraw_sum']);
    save('users.json', $users);

    // Statistika
    $games_created = 0;
    $games_won = 0;
    foreach($games as $g){
        if($g['u1'] == $uid) $games_created++;
    }
    $stats = json_decode(file_get_contents("stats.json"), true);
    if(isset($stats[$uid]['wins'])) $games_won = $stats[$uid]['wins'];

   $text = "💸 *Pul chiqarish so‘rovi*\n\n"
      . "👤 ID: `$uid`\n"
      . "💰 Miqdor: *$amount* so‘m\n"
      . "💳 Karta: `$card`\n"
      . "🔸 Balans: *{$users[$uid]['b']}* so‘m\n"
      . "🎮 Yar. o‘yinlar: *$games_created*\n"
      . "🏆 Yutuqlar: *$games_won* ta";

$inline = [
    [['text'=>"✅ Tastiqlash", 'callback_data'=>"confirm_withdraw|$uid|$amount|$card"]],
    [['text'=>"❌ Bekor qilish", 'callback_data'=>"cancel_withdraw|$uid|$amount"]]
];

bot('sendMessage', [
    'chat_id' => 1999997369,
    'text' => $text,
    'parse_mode' => 'markdown',
    'reply_markup' => json_encode(['inline_keyboard' => $inline])
]);


    bot('sendMessage',[
        'chat_id'=>$cid,
        'text'=>"✅ So‘rovingiz yuborildi. Tez orada ko‘rib chiqiladi."
    ]);
}
if(isset($u['callback_query'])){
  $cid = $u['callback_query']['from']['id'];
  $mid = $u['callback_query']['message']['message_id'];
  $data = $u['callback_query']['data'];

  if(strpos($data, "confirm_withdraw|") !== false){
    list(, $uid, $amount, $card) = explode("|", $data);
    $users = json_decode(file_get_contents("users.json"), true);

    if($users[$uid]['b'] >= 0){
      
      $users[$uid]['withdraw'] += $amount;
      file_put_contents("users.json", json_encode($users));
      
      bot('editMessageText', [
        'chat_id' => $cid,
        'message_id' => $mid,
        'text' => "✅ Pul chiqarish tasdiqlandi!\n\n👤 ID: $uid\n💰 Miqdor: $amount so‘m\n💳 Karta: $card"
      ]);

      bot('sendMessage', [
        'chat_id' => $uid,
        'text' => "✅ Pul chiqarish so‘rovingiz admin tomonidan tasdiqlandi!\n💰 $amount so‘m kartangizga o‘tkaziladi."
      ]);
    } else {
      bot('answerCallbackQuery', [
        'callback_query_id' => $u['callback_query']['id'],
        'text' => "❌ Foydalanuvchi balansida yetarli mablag‘ yo‘q!",
        'show_alert' => true
      ]);
    }
  }

  if(strpos($data, "cancel_withdraw|") !== false){
    list(, $uid, $amount) = explode("|", $data);

    bot('editMessageText', [
      'chat_id' => $cid,
      'message_id' => $mid,
      'text' => "❌ Pul chiqarish so‘rovi bekor qilindi.\n👤 ID: $uid\n💰 Miqdor: $amount so‘m"
    ]);

    bot('sendMessage', [
      'chat_id' => $uid,
      'text' => "❌ Pul chiqarish so‘rovingiz admin tomonidan bekor qilindi."
    ]);
  }
}

// Step papkasi mavjudligini tekshirib, yo'q bo‘lsa yaratish
if (!file_exists("step")) {
    mkdir("step", 0777, true);
}

// Admin tomonidan xabar yuborish uchun boshlash
if ($uid == ADMIN_ID && $tx == "✉  Xabar yuborish") {
    file_put_contents("step/$uid.txt", "send_message");
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "📨 Iltimos, yubormoqchi bo‘lgan xabar matnini kiriting:"
    ]);
    return;
}

// Step orqali xabar matni yozilgandan keyin
$step = file_exists("step/$uid.txt") ? file_get_contents("step/$uid.txt") : null;
if ($step == "send_message" && $uid == ADMIN_ID) {
    unlink("step/$uid.txt");
    $msg = $tx;

    // Tasdiqlash tugmalari
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "❓ Ushbu xabar barcha foydalanuvchilarga yuborilsinmi?\n\n<b>$msg</b>",
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => "📨 Yuborish", 'callback_data' => "send_confirm|" . urlencode($msg)]],
                [['text' => "❌ Bekor qilish", 'callback_data' => "send_cancel"]]
            ]
        ])
    ]);
    return;
}

// CALLBACK ishlov
if ($data) {
    if (strpos($data, "send_confirm|") === 0 && $uid == ADMIN_ID) {
        $msg = urldecode(explode("|", $data)[1]);

        bot('editMessageText', [
            'chat_id' => $cid,
            'message_id' => $cmid,
            'text' => "⏳ Yuborish boshlandi...\n\n<b>$msg</b>",
            'parse_mode' => 'html'
        ]);

        $total = 0;
        $sent = 0;
        $failed = 0;
        $blocked = 0;

        foreach ($users as $i => $u) {
            $total++;
            $res = bot('sendMessage', [
                'chat_id' => $i,
                'text' => $msg
            ]);

            if (isset($res['ok']) && $res['ok']) {
                $sent++;
            } else {
                $failed++;
                if (isset($res['error_code']) && $res['error_code'] == 403) {
                    $users[$i]['blocked'] = true;
                    $blocked++;
                }
            }

            usleep(300000); // 0.3s kutish
        }

        file_put_contents("users.json", json_encode($users));

        bot('editMessageText', [
            'chat_id' => $cid,
            'message_id' => $cmid,
            'text' => "✅ Yuborish yakunlandi!\n\n📬 Umumiy: $total\n✅ Yuborildi: $sent\n🚫 Xatolik: $failed\n🚷 Bloklaganlar: $blocked",
            'parse_mode' => 'html'
        ]);
        return;
    }

    if ($data == "send_cancel" && $uid == ADMIN_ID) {
        bot('editMessageText', [
            'chat_id' => $cid,
            'message_id' => $cmid,
            'text' => "❌ Xabar yuborish bekor qilindi."
        ]);
        return;
    }
}

if($tx == "🆘 Yordam"){
    $text = "🆘 <b>YORDAM BO‘LIMI</b>\n\n".
            "📖 Botdan qanday foydalaniladi? Quyidagi bo‘limlar orqali bilib oling:\n\n".
            
            "💼 <b>1. Balans</b>\n".
            "‣ 💳 <b>Balansim:</b> Balansingizni ko‘rish uchun foydalaniladi\n".
            "‣ ➕ <b>Bonus:</b> Har kuni kirib sovg‘a oling!\n\n".

            "🎮 <b>2. O‘yinlar</b>\n".
            "‣ 👥 <b>2 kishilik o‘yinlar:</b> Boshqa foydalanuvchiga qarshi\n".
            "‣ 🤖 <b>Botga qarshi o‘yin:</b> PvE janglar\n\n".

            "🎁 <b>3. Bonuslar</b>\n".
            "‣ 🎟 <b>Promo kod:</b> Maxsus kodlarni kiriting va mukofot oling\n".
            "‣ 👥 <b>Referal:</b> Do‘st taklif qiling — sizga bonus\n".
            "‣ 📅 <b>Kunlik bonus:</b> Har kuni 1 marta olinadi\n\n".

            "🛍 <b>4. Do‘kon & Inventar</b>\n".
            "‣ 📦 <b>Do‘kon:</b> Maxsus buyumlar xarid qiling\n".
            "‣ 🎒 <b>Inventar:</b> Xarid qilingan buyumlar ro‘yxati\n\n".

            "📊 <b>5. Statistika & Reyting</b>\n".
            "‣ 🧾 <b>Statistikam:</b> Yutuqlar, g‘alabalar, bonuslar\n".
            "‣ 🏆 <b>Top 10:</b> Eng kuchli foydalanuvchilar ro‘yxati\n\n".

            "💸 <b>6. Pul ishlari</b>\n".
            "‣ 💵 <b>Pul yechish:</b> Admin tasdiqlashi bilan amalga oshadi\n".
            "‣ 📥 <b>Pul tushurish:</b> UZCARD / HUMO orqali\n\n".

            "🔐 <b>Promo kodni qanday ishlataman?</b>\n".
            "1. 🎟 Promo bo‘limini oching\n".
            "2. Kodni kiriting (masalan: <code>WELCOME</code>)\n".
            "3. 💰 Bonus avtomatik balansga tushadi\n\n".

            "📣 <i>Yordam kerakmi? Quyidagi tugmalar sizga yordam beradi:</i>";

    bot('sendMessage',[
        'chat_id' => $cid,
        'text' => $text,
        'parse_mode' => 'html',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text'=>"📢 Rasmiy kanal", 'url'=>"https://t.me/KanalUsername"]],               
                [['text'=>"🤖 Botga qaytish", 'url'=>"https://t.me/$botname"]],
                [['text'=>"📞 Admin bilan bog‘lanish", 'url'=>"https://t.me/AdminUsername"]]
            ]
        ])
    ]);
}

// 🔎 Foydalanuvchini boshqarish tugmasi bosilganda ID so‘rash
if ($tx == "🔎 Foydalanuvchini boshqarish" && $uid == ADMIN_ID) {
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🆔 Iltimos, boshqariladigan foydalanuvchi ID raqamini yuboring:",
        'reply_markup' => json_encode([
            'keyboard' => [
                [['text' => '🔙 Bekor qilish']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ])
    ]);
    file_put_contents("step/$uid.txt", "await_user_id");
    exit();
}

// 🔙 Bekor qilish tugmasi
if ($tx == "🔙 Bekor qilish" && file_exists("step/$uid.txt")) {
    unlink("step/$uid.txt");
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "❌ Bekor qilindi.",
        'reply_markup' => json_encode(['remove_keyboard' => true])
    ]);

    // 🔽 Asosiy menyuni qayta chiqarish
    $menu = [
        [['text' => '🎲 O‘yinxonasi'], ['text' => '📈 Birja']],
                [['text' => '💼 Balans'], ['text' => '🎁 Bonus']],
                [['text' => '👥 Referallarim'], ['text' => '📊 Reyting']],
                [['text' => '💳 Pul kiritish'], ['text' => '💸 Pul chiqarish']],
    ];
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "🏠 Asosiy menyu:",
        'reply_markup' => json_encode([
            'keyboard' => $menu,
            'resize_keyboard' => true
        ])
    ]);
    exit();
}

// Admin foydalanuvchini ID orqali qidirish
if (file_exists("step/$uid.txt") && file_get_contents("step/$uid.txt") == "await_user_id") {
    if (!is_numeric($tx)) {
        bot('sendMessage', ['chat_id'=>$cid, 'text'=>"❗ Iltimos, faqat ID raqam yuboring."]);
        exit();
    }
    $target = trim($tx);
    unlink("step/$uid.txt");

    if (!isset($users[$target])) {
        bot('sendMessage', ['chat_id'=>$cid, 'text'=>"❌ Foydalanuvchi topilmadi."]);
    } else {
        $u = $users[$target];
        $msg = "👤 <b>Foydalanuvchi ID:</b> <code>$target</code>\n";
        $msg .= "💰 <b>Balans:</b> " . ($u['b'] ?? 0) . " so'm\n";
        $msg .= "👥 <b>Referali:</b> " . ($u['ref'] ?? 'yo‘q') . "\n";
        $msg .= "📊 <b>Statistika:</b> \n🪙 MDACoin: " . ($u['mdacoin'] ?? 0) . "\n🎁 Bonus: " . ($u['bonus'] ?? 0);

        $btn = [
            [['text' => '➕ Pul qo‘shish', 'callback_data' => "add_money:$target"]],
            [['text' => '➖ Pul ayirish', 'callback_data' => "remove_money:$target"]],
            [['text' => '✏️ Ma’lumot o‘zgartirish', 'callback_data' => "edit_user:$target"]],
            [['text' => '❌ O‘chirish', 'callback_data' => "delete_user:$target"]]
        ];
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => $msg,
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => $btn])
        ]);
    }
    exit();
}

// Pul qo‘shish / ayirish / o‘chirish / o‘zgartirish funksiyalari
if ($data && $uid == ADMIN_ID) {
    if (strpos($data, "add_money:") === 0) {
        $tid = explode(":", $data)[1];
        bot('sendMessage', ['chat_id'=>$cid, 'text'=>"💰 Necha so‘m qo‘shmoqchisiz?\nMasalan: <code>+10000</code>", 'parse_mode'=>'html']);
        file_put_contents("step/$uid.txt", "add:$tid");
    } elseif (strpos($data, "remove_money:") === 0) {
        $tid = explode(":", $data)[1];
        bot('sendMessage', ['chat_id'=>$cid, 'text'=>"💳 Necha so‘m ayirmoqchisiz?\nMasalan: <code>-5000</code>", 'parse_mode'=>'html']);
        file_put_contents("step/$uid.txt", "remove:$tid");
    } elseif (strpos($data, "delete_user:") === 0) {
        $tid = explode(":", $data)[1];
        unset($users[$tid]);
        save("users.json", $users);
        bot('editMessageText', ['chat_id'=>$cid, 'message_id'=>$mid, 'text'=>"✅ Foydalanuvchi o‘chirildi."]);
    }
}

// Raqam yuborganda balansni qo‘shish yoki ayirish
if (file_exists("step/$uid.txt")) {
    $step = file_get_contents("step/$uid.txt");
    if (strpos($step, "add:") === 0 || strpos($step, "remove:") === 0) {
        $tid = explode(":", $step)[1];
        if (isset($users[$tid]) && is_numeric($tx)) {
            if (strpos($step, "add:") === 0) $users[$tid]['b'] += abs($tx);
            else $users[$tid]['b'] -= abs($tx);
            save("users.json", $users);
            unlink("step/$uid.txt");
            bot('sendMessage', ['chat_id'=>$cid, 'text'=>"✅ Amal bajarildi. Yangi balans: ".$users[$tid]['b']." so‘m"]);
        }
    }
}



?>