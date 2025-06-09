<?php
define('BOT_TOKEN', '6583579506:AAHBEOVW9cnJhF3IQ_0OZCIMovA_gtChWUU');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
$users_file = __DIR__ . '/users.json';
if (!file_exists($users_file)) file_put_contents($users_file, '{}');
$users = json_decode(file_get_contents($users_file), true);

$roles = [
    "🗡 Jangchi" => ["hp" => 120, "mp" => 30, "atk" => 12, "def" => 8, "desc" => "Kuchli va chidamli jangchi"],
    "✨ Sehrgar" => ["hp" => 80, "mp" => 80, "atk" => 7, "def" => 4, "desc" => "Sehr kuchiga ega"],
    "🏹 O‘qchi"  => ["hp" => 100, "mp" => 40, "atk" => 10, "def" => 5, "desc" => "Tez va aniq hujumchi"],
];

$admin_ids = ['1999997369']; // O'zingizni admin qiling

function apiRequest($method, $params = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_URL . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
function sendMessage($chat_id, $text, $buttons = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($buttons) $data['reply_markup'] = json_encode(['keyboard' => $buttons, 'resize_keyboard' => true, 'one_time_keyboard' => false]);
    return apiRequest('sendMessage', $data);
}
function sendInlineAdminMenu($chat_id) {
    $inline_keyboard = [
        [
            ['text' => '👤 Foydalanuvchilar', 'callback_data' => 'admin_users'],
            ['text' => '🔎 Qidiruv', 'callback_data' => 'admin_search'],
        ],
        [
            ['text' => '✉️ Yangi xabar', 'callback_data' => 'admin_broadcast'],
            ['text' => '📢 E‘lon', 'callback_data' => 'admin_announce'],
        ],
        [
            ['text' => '🚫 Ban', 'callback_data' => 'admin_ban'],
            ['text' => '✅ Unban', 'callback_data' => 'admin_unban'],
        ],
        [
            ['text' => '📊 Statistika', 'callback_data' => 'admin_stat'],
            ['text' => '🏆 Reyting', 'callback_data' => 'admin_top'],
        ],
        [
            ['text' => '⬅️ Orqaga', 'callback_data' => 'admin_back'],
            ['text' => '🔚 Chiqish', 'callback_data' => 'admin_exit'],
        ],
    ];
    apiRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👑 <b>Admin Panel</b>\n\nKerakli bo‘limni tanlang:",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard])
    ]);
}
function isAdmin($user_id) {
    global $admin_ids;
    return in_array($user_id, $admin_ids ?? []);
}
function mainMenu($is_registered = false, $is_admin = false) {
    $menu = [];
    if ($is_registered) {
        $menu = [
            ['🗡 Oʻynash', '📦 Inventar'],
            ['📊 Statistika','⚔️ Noyob Asboblar','🥇 Reyting'],
            ['ℹ️ Qoʻllanma']
        ];
    } else {
        $menu = [
            ['📝 Ro‘yxatdan o‘tish']
        ];
    }
    if ($is_admin) $menu[] = ['👑 Admin Panel'];
    return $menu;
}
function resetUserState(&$users, $user_id) {
    $users[$user_id]['state'] = '';
    $users[$user_id]['tmp'] = [];
}
function saveUsers($users) {
    global $users_file;
    file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
}
function adventureEvent(&$user, &$log) {
    $stat = &$user['stats'];
    $level = &$user['level'];
    $xp = &$user['xp'];
    if (!isset($user['coins'])) $user['coins'] = 0;

    $user['total_adventures'] = $user['total_adventures'] ?? 0;
    $user['total_wins'] = $user['total_wins'] ?? 0;
    $user['total_losses'] = $user['total_losses'] ?? 0;

    $adventure_types = [
        'battle', 'trap', 'find', 'magic', 'npc'
    ];
    $type = $adventure_types[array_rand($adventure_types)];
    $msg = "";
    $xp_gain = 0;
    $levelup = false;
    $user['total_adventures']++;

    // --- Tanga yig‘ish (har adventure faqat 1 tanga)
    $user['coins'] += 1;

    if ($type == 'battle') {
        $enemies = [
            ['name'=>'Goblin', 'hp'=>rand(10,20)+$level*2, 'atk'=>rand(3,5)+$level, 'def'=>rand(1,3)+floor($level/2), 'emoji'=>'👺'],
            ['name'=>'Bo‘ri', 'hp'=>rand(8,16)+$level*2, 'atk'=>rand(4,7)+$level, 'def'=>rand(1,3)+floor($level/2), 'emoji'=>'🐺'],
            ['name'=>'Skelet', 'hp'=>rand(15,22)+$level*2, 'atk'=>rand(5,8)+$level, 'def'=>rand(2,4)+floor($level/2), 'emoji'=>'💀'],
            ['name'=>'Sehrli arvoh', 'hp'=>rand(12,19)+$level*2, 'atk'=>rand(5,9)+$level, 'def'=>rand(3,5)+floor($level/2), 'emoji'=>'👻'],
            ['name'=>'Bandit', 'hp'=>rand(13,21)+$level*2, 'atk'=>rand(6,10)+$level, 'def'=>rand(2,5)+floor($level/2), 'emoji'=>'🥷'],
        ];
        // Binokl effektini adventure uchun (agar bor bo‘lsa)
        if (isset($user['binocular']) && $user['binocular']) {
            foreach($enemies as &$e) {
                $e['atk'] = max(1, floor($e['atk']*0.5));
                $e['def'] = max(1, floor($e['def']*0.5));
            }
            $user['binocular'] = 0; // Faqat 1 adventure uchun
            $msg .= "🔭 Binokl tufayli dushmanlar kuchsizlandi!\n";
        }
        $enemy = $enemies[array_rand($enemies)];
        $your_power = $stat['atk'] + rand(0,$level+2);
        $your_def = $stat['def'] + rand(0,$level+1);
        $your_hp = $stat['hp'];
        $enemy_power = $enemy['atk'] + rand(0,2);
        $enemy_def = $enemy['def'] + rand(0,2);
        $enemy_hp = $enemy['hp'];
        $rounds = [];
        while ($your_hp > 0 && $enemy_hp > 0) {
            $damage_to_enemy = max(1, $your_power - $enemy_def + rand(-1,2));
            $damage_to_you = max(1, $enemy_power - $your_def + rand(-1,2));
            $enemy_hp -= $damage_to_enemy;
            $rounds[] = "Siz hujum qildingiz! ({$damage_to_enemy} zarar)";
            if ($enemy_hp <= 0) break;
            $your_hp -= $damage_to_you;
            $rounds[] = "{$enemy['emoji']} Dushman sizga zarba berdi! ({$damage_to_you} zarar)";
        }
        if ($your_hp > 0) {
            $msg .= "{$enemy['emoji']} <b>{$enemy['name']}</b> bilan jangda g‘alaba qozondingiz!\n";
            $xp_gain = rand(7,15)+$level*2;
            $stat['hp'] = $your_hp;
            $msg .= "🟢 Jang tafsilotlari:\n".implode("\n",$rounds)."\n🟩 Siz yutdingiz! +$xp_gain XP";
            $user['total_wins']++;
        } else {
            $msg .= "{$enemy['emoji']} <b>{$enemy['name']}</b> sizni mag‘lub etdi! 😵\n";
            $stat['hp'] = 10 + $level*2;
            $xp_gain = rand(1,4);
            $msg .= "🔴 Jang tafsilotlari:\n".implode("\n",$rounds)."\n🟥 Siz mag‘lub bo‘ldingiz. +$xp_gain XP, HP tiklandi";
            $user['total_losses']++;
        }
        $xp += $xp_gain;
    }
    elseif ($type == 'trap') {
        $lose = rand(2,6);
        $stat['hp'] -= $lose;
        if ($stat['hp'] < 1) $stat['hp'] = 1;
        $msg = "🔥 Tuzoqga tushib qoldingiz!\n-{$lose} HP\n";
        $xp_gain = rand(2,6);
        $xp += $xp_gain;
        $msg .= "+$xp_gain XP";
    }
    elseif ($type == 'find') {
        $bonuses = [
            ['msg'=>"💎 Qimmatbaho tosh topdingiz! +5 HP, +2 Atk", 'hp'=>5, 'atk'=>2, 'def'=>0, 'mp'=>0],
            ['msg'=>"🧪 Sehrli eliksir topdingiz! +5 MP, +1 Def", 'hp'=>0, 'atk'=>0, 'def'=>1, 'mp'=>5],
            ['msg'=>"🪙 Tangalar topdingiz! +1 Atk, +1 Def", 'hp'=>0, 'atk'=>1, 'def'=>1, 'mp'=>0],
            ['msg'=>"🍗 Ovqat topdingiz! +7 HP", 'hp'=>7, 'atk'=>0, 'def'=>0, 'mp'=>0],
        ];
        $b = $bonuses[array_rand($bonuses)];
        foreach(['hp','atk','def','mp'] as $k) $stat[$k]+=$b[$k];
        $xp_gain = rand(4,10);
        $xp += $xp_gain;
        $msg = $b['msg']."\n+$xp_gain XP";
    }
    elseif ($type == 'magic') {
        $effects = [
            ['msg'=>"✨ Sehrli buloqdan suv ichdingiz. Energiya to‘ldi!", 'energy'=>5, 'mp'=>3],
            ['msg'=>"🔮 Sehrgar sizga duo qildi! +1 Atk, +1 Def", 'atk'=>1, 'def'=>1],
            ['msg'=>"🌚 Qorong‘u kuchlar sizdan energiya oldi! -2 Energiya", 'energy'=>-2],
            ['msg'=>"🌟 Sehrli yulduz sizga kuch berdi! +2 HP, +2 MP", 'hp'=>2, 'mp'=>2],
        ];
        $e = $effects[array_rand($effects)];
        foreach(['hp','atk','def','mp'] as $k) if(isset($e[$k])) $stat[$k]+=$e[$k];
        if (isset($e['energy'])) {
            $user['energy'] += $e['energy'];
            if ($user['energy'] < 0) $user['energy'] = 0;
            if ($user['energy'] > 5) $user['energy'] = 5;
        }
        $xp_gain = rand(2,7);
        $xp += $xp_gain;
        $msg = $e['msg']."\n+$xp_gain XP";
    }
    elseif ($type == 'npc') {
        $npc = rand(0,1) ? 'do‘st' : 'yovuz';
        if ($npc == 'do‘st') {
            $msg = "🧙‍♂️ Sayohatchi uchratdingiz. U sizga yordam berdi: +2 HP, +1 Def\n";
            $stat['hp'] += 2; $stat['def'] += 1;
            $xp_gain = rand(3,8);
            $xp += $xp_gain;
            $msg .= "+$xp_gain XP";
        } else {
            $msg = "🦹‍♂️ Qaroqchi sizni aldadi. -3 HP, -1 Def\n";
            $stat['hp'] -= 3; if ($stat['hp']<1) $stat['hp']=1;
            $stat['def'] -= 1; if ($stat['def']<0) $stat['def']=0;
            $xp_gain = rand(1,4);
            $xp += $xp_gain;
            $msg .= "+$xp_gain XP";
        }
    }
    $xp_need = $level*25 + 15;
    if ($xp >= $xp_need) {
        $level++;
        $xp = 0;
        $stat['hp'] += 7;
        $stat['atk'] += 2;
        $stat['def'] += 2;
        $user['coins'] += 10; // --- LEVEL UP uchun 10 tanga!
        $msg .= "\n\n🎉 <b>Yangi LEVEL: $level!</b>\nStatlaringiz oshdi!\n💰 10 tanga!";
        $levelup = true;
    }
    foreach (['hp', 'atk', 'def', 'mp'] as $k) if ($stat[$k] < 0) $stat[$k] = 0;
    $log[] = $msg;
    if (count($log) > 3) array_shift($log);
    return [$msg, $levelup];
}
// ==== CALLBACK HANDLING (INLINE ADMIN PANEL) ====
$update = json_decode(file_get_contents('php://input'), true);
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cb_data = $callback['data'];
    $cb_uid = $callback['from']['id'];
    $cb_cid = $callback['message']['chat']['id'];
    if (strpos($cb_data, 'admin_') === 0) {
        if ($cb_data == 'admin_back' || $cb_data == 'admin_exit') {
            resetUserState($users, $cb_uid);
            saveUsers($users);
            apiRequest('editMessageText', [
                'chat_id' => $cb_cid,
                'message_id' => $callback['message']['message_id'],
                'text' => "Admin paneldan chiqdingiz.",
                'reply_markup' => json_encode([
                    'keyboard' => mainMenu($users[$cb_uid]['registered'], isAdmin($cb_uid)),
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }
        if ($cb_data == 'admin_users') {
            $msg = "Barcha foydalanuvchilar:\n";
            foreach ($users as $uid => $ud)
                if ($ud['registered']) $msg .= "- <b>{$ud['first_name']}</b> ({$ud['role']})\n";
            apiRequest('editMessageText', [
                'chat_id' => $cb_cid,
                'message_id' => $callback['message']['message_id'],
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text'=>'⬅️ Orqaga','callback_data'=>'admin_back']]]])
            ]);
            exit;
        }
        if ($cb_data == 'admin_search') {
            $users[$cb_uid]['state'] = 'admin_search_user';
            saveUsers($users);
            apiRequest('sendMessage', [
                'chat_id' => $cb_cid,
                'text' => "Qidiruv uchun foydalanuvchi ismi yoki ID kiriting:",
                'reply_markup' => json_encode(['keyboard' => [['⬅️ Orqaga']], 'resize_keyboard' => true])
            ]);
            exit;
        }
        if ($cb_data == 'admin_broadcast') {
            $users[$cb_uid]['state'] = 'admin_broadcast';
            saveUsers($users);
            apiRequest('sendMessage', [
                'chat_id' => $cb_cid,
                'text' => "Yangi xabar matnini yuboring. Bekor qilish uchun ⬅️ Orqaga.",
                'reply_markup' => json_encode(['keyboard' => [['⬅️ Orqaga']], 'resize_keyboard' => true])
            ]);
            exit;
        }
        if ($cb_data == 'admin_announce') {
            $users[$cb_uid]['state'] = 'admin_announce';
            saveUsers($users);
            apiRequest('sendMessage', [
                'chat_id' => $cb_cid,
                'text' => "E‘lon matnini yuboring:",
                'reply_markup' => json_encode(['keyboard' => [['⬅️ Orqaga']], 'resize_keyboard' => true])
            ]);
            exit;
        }
        if ($cb_data == 'admin_ban') {
            $users[$cb_uid]['state'] = 'admin_ban';
            saveUsers($users);
            apiRequest('sendMessage', [
                'chat_id' => $cb_cid,
                'text' => "Ban qilinadigan user ID ni kiriting:",
                'reply_markup' => json_encode(['keyboard' => [['⬅️ Orqaga']], 'resize_keyboard' => true])
            ]);
            exit;
        }
        if ($cb_data == 'admin_unban') {
            $users[$cb_uid]['state'] = 'admin_unban';
            saveUsers($users);
            apiRequest('sendMessage', [
                'chat_id' => $cb_cid,
                'text' => "Unban qilinadigan user ID ni kiriting:",
                'reply_markup' => json_encode(['keyboard' => [['⬅️ Orqaga']], 'resize_keyboard' => true])
            ]);
            exit;
        }
        if ($cb_data == 'admin_stat') {
            $registered = count(array_filter($users, fn($u)=>$u['registered']));
            $msg = "Foydalanuvchilar: ".count($users)."\nRo‘yxatdan o‘tganlar: $registered";
            apiRequest('editMessageText', [
                'chat_id' => $cb_cid,
                'message_id' => $callback['message']['message_id'],
                'text' => $msg,
                'reply_markup' => json_encode(['inline_keyboard' => [[['text'=>'⬅️ Orqaga','callback_data'=>'admin_back']]]])
            ]);
            exit;
        }
        if ($cb_data == 'admin_top') {
            $top = $users;
            usort($top, fn($a, $b) => ($b['level']*100+$b['xp'])-($a['level']*100+$a['xp']));
            $msg = "🥇 <b>Eng kuchli 5 o‘yinchi:</b>\n";
            $i = 1;
            foreach ($top as $u) {
                if (!$u['registered']) continue;
                $msg .= "{$i}. <b>{$u['first_name']}</b> ({$u['role']}) - Level {$u['level']}, XP {$u['xp']}\n";
                $i++; if ($i > 5) break;
            }
            apiRequest('editMessageText', [
                'chat_id' => $cb_cid,
                'message_id' => $callback['message']['message_id'],
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text'=>'⬅️ Orqaga','callback_data'=>'admin_back']]]])
            ]);
            exit;
        }
        sendInlineAdminMenu($cb_cid);
        exit;
    }
}

// ==== MAIN LOGIC ====
if (!$update) exit;
$message = $update['message'] ?? null;
if (!$message) exit;

$chat_id = $message['chat']['id'];
$user_id = $message['from']['id'];
$text = trim($message['text'] ?? '');

// --- ADMIN STATE-LARDA ORQAGA BOSILGANINI HAR DOIM TEKSHIRING!
if (
    isset($users[$user_id]['state']) &&
    strpos($users[$user_id]['state'], 'admin') === 0 &&
    ($text == '⬅️ Orqaga' || $text == '🔚 Chiqish')
) {
    resetUserState($users, $user_id);
    saveUsers($users);
    sendMessage($chat_id, "Admin paneldan chiqdingiz.", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}

if (!isset($users[$user_id])) {
    $users[$user_id] = [
        'username' => $message['from']['username'] ?? '',
        'first_name' => $message['from']['first_name'] ?? '',
        'state' => '',
        'tmp' => [],
        'registered' => false,
        'role' => '',
        'stats' => [],
        'energy' => 5,
        'adventure_log' => [],
        'level' => 1,
        'xp' => 0,
        'total_adventures' => 0,
        'total_wins' => 0,
        'total_losses' => 0,
        'coins' => 0
    ];
    saveUsers($users);
}
foreach (['energy'=>5, 'adventure_log'=>[], 'level'=>1, 'xp'=>0, 'total_adventures'=>0, 'total_wins'=>0, 'total_losses'=>0, 'coins'=>0] as $k=>$v)
    if (!isset($users[$user_id][$k])) $users[$user_id][$k] = $v;

if ($text == '⬅️ Orqaga' || $text == '/start') {
    resetUserState($users, $user_id);
    saveUsers($users);
    sendMessage($chat_id, "Asosiy menyuga qaytdingiz!", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($text == '👑 Admin Panel' && isAdmin($user_id)) {
    $users[$user_id]['state'] = 'admin';
    saveUsers($users);
    sendInlineAdminMenu($chat_id);
    exit;
}
if ($users[$user_id]['state'] == 'admin_search_user' && $text !== '⬅️ Orqaga') {
    $result = [];
    foreach ($users as $uid => $ud) {
        if (stripos($ud['first_name'], $text) !== false || (string)$uid === $text)
            $result[] = "{$ud['first_name']} (ID: $uid)";
    }
    $msg = $result ? "Natija:\n".implode("\n", $result) : "Foydalanuvchi topilmadi!";
    resetUserState($users, $user_id);
    sendMessage($chat_id, $msg, mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($users[$user_id]['state'] == 'admin_broadcast' && $text !== '⬅️ Orqaga') {
    foreach ($users as $uid => $ud) sendMessage($uid, "Admin xabari:\n\n$text");
    resetUserState($users, $user_id);
    sendMessage($chat_id, "Xabar yuborildi.", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($users[$user_id]['state'] == 'admin_announce' && $text !== '⬅️ Orqaga') {
    foreach ($users as $uid => $ud) if($ud['registered']) sendMessage($uid, "📢 <b>E‘lon:</b>\n$text");
    resetUserState($users, $user_id);
    sendMessage($chat_id, "E‘lon yuborildi.", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($users[$user_id]['state'] == 'admin_ban' && is_numeric($text)) {
    $users[$text]['banned'] = true;
    resetUserState($users, $user_id);
    sendMessage($chat_id, "User $text ban qilindi.", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($users[$user_id]['state'] == 'admin_unban' && is_numeric($text)) {
    $users[$text]['banned'] = false;
    resetUserState($users, $user_id);
    sendMessage($chat_id, "User $text unban qilindi.", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
    exit;
}
if ($text == '📝 Ro‘yxatdan o‘tish' && $users[$user_id]['registered']) {
    sendMessage($chat_id, "Siz allaqachon ro‘yxatdan o‘tgansiz!", mainMenu(true, isAdmin($user_id)));
    exit;
}
if ($text == '📝 Ro‘yxatdan o‘tish' && !$users[$user_id]['registered']) {
    $users[$user_id]['state'] = 'register_role';
    saveUsers($users);
    $role_btns = array_map(fn($k)=>[$k], array_keys($roles));
    $role_btns[] = ['⬅️ Orqaga'];
    sendMessage($chat_id, "Rolni tanlang (faqat 1 marta tanlanadi):", $role_btns);
    exit;
}
if ($users[$user_id]['state'] == 'register_role' && !$users[$user_id]['registered']) {
    global $roles;
    if (isset($roles[$text])) {
        $users[$user_id]['role'] = $text;
        $users[$user_id]['stats'] = $roles[$text];
        $users[$user_id]['registered'] = true;
        $users[$user_id]['energy'] = 5;
        $users[$user_id]['adventure_log'] = [];
        $users[$user_id]['level'] = 1;
        $users[$user_id]['xp'] = 0;
        $users[$user_id]['total_adventures'] = 0;
        $users[$user_id]['total_wins'] = 0;
        $users[$user_id]['total_losses'] = 0;
        $users[$user_id]['coins'] = 0;
        resetUserState($users, $user_id);
        saveUsers($users);
        sendMessage($chat_id, "<b>Tabriklaymiz!</b>\nSiz <b>$text</b> rolini tanladingiz va ro‘yxatdan o‘tdingiz.\n\n<b>Statistikangiz:</b>\n❤️ HP: {$roles[$text]['hp']}\n💧 MP: {$roles[$text]['mp']}\n⚔️ Atk: {$roles[$text]['atk']}\n🛡 Def: {$roles[$text]['def']}\n\nEndi o‘yin menyusidan foydalanishingiz mumkin!", mainMenu(true, isAdmin($user_id)));
    } else {
        $role_btns = array_map(fn($k)=>[$k], array_keys($roles));
        $role_btns[] = ['⬅️ Orqaga'];
        sendMessage($chat_id, "Rolni to‘g‘ri tanlang:", $role_btns);
    }
    exit;
}
if (!$users[$user_id]['registered']) {
    sendMessage($chat_id, "Botdan foydalanish uchun dastlab ro‘yxatdan o‘ting.", mainMenu(false, isAdmin($user_id)));
    exit;
}

// --- SHOP ITEMLARI ---
$shop = [
    ["id"=>"mini_potion", "name"=>"🧃 Mini eliksir", "desc"=>"+5 HP", "cost"=>4],
    ["id"=>"snack", "name"=>"🍪 Pechenye", "desc"=>"+1 Energiya", "cost"=>2],
    ["id"=>"stone", "name"=>"🪨 Tosh", "desc"=>"+1 Def", "cost"=>7],
    ["id"=>"stick", "name"=>"🌿 Tayoq", "desc"=>"+1 Atk", "cost"=>7],
    ["id"=>"book", "name"=>"📕 Kitob", "desc"=>"+3 XP", "cost"=>5],
];
$items = [
    "mini_potion" => "🧃 Mini eliksir (+5 HP)",
    "snack"       => "🍪 Pechenye (+1 Energiya)",
    "stone"       => "🪨 Tosh (+1 Def)",
    "stick"       => "🌿 Tayoq (+1 Atk)",
    "book"        => "📕 Kitob (+3 XP)",
];
$item_names = array_column($shop, "name", "id");

// --- Botga kelgan xabarlarni qabul qilish ---
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;
$message = $update['message'] ?? null;
if (!$message) exit;
$chat_id = $message['chat']['id'];
$user_id = $message['from']['id'];
$text = trim($message['text'] ?? '');

if (!isset($users[$user_id])) {
    $users[$user_id] = [
        'first_name' => $message['from']['first_name'] ?? '',
        'state' => '',
        'coins' => 10, // boshlang‘ichiga 10 tanga
        'inventory' => [],
        'stats' => ['hp'=>20,'atk'=>2,'def'=>2],
        'energy' => 3,
        'xp' => 0
    ];
    saveUsers($users);
}

// --- Noyob asboblar ro‘yxati: har biri ajoyib nom, roli, darajasi va limiti bilan ---
$rare_def = [
    // nom, roli, daraja, ehtimol(%), limit, effekt
    ['🗡️ Zulfikor',        'Jangchi',   'Oddiy 1',    35,   1000, 'ATK +2'],
    ['🪓 Yirtqich Bolta',   'Jangchi',   'Oddiy 2',    18,   400,  'ATK +4'],
    ['🛡️ Temir Qalqon',    'Himoyachi', 'Oddiy 3',    8,    200,  'DEF +3'],
    ['🏹 Tezkor Kamon',     'O‘qchi',    'Yaxshi',     4,    90,   'ATK +3, crit +2%'],
    ['🔵 Kumush Qilich',    'Jangchi',   'Yaxshi',     2,    50,   'ATK +6'],
    ['🟣 Sehrli Qalqon',    'Himoyachi', 'Olliy',      0.5,  10,   'DEF +7, mag. damage -2'],
    ['🟡 Oltin Qilich',     'Jangchi',   'Olliy',      0.2,  5,    'ATK +9, HP +15'],
    ['🟢 Qahramon Kamon',   'O‘qchi',    'Qudratli',   0.07, 2,    'ATK +10, crit +7%'],
    ['🟥 Zamin So‘qqich',   'Jangchi',   'Qudratli',   0.03, 1,    'ATK +18, dushman DEF -5'],
    ['⚡ Qasos Nayzasi',    'Jangchi',   'Qudratli',   0.03, 1,    'ATK +15, Revenge: dushman zarbasi qaytariladi'],
    ['🔥 Anqov Yonmasi',    'Mag',       'Olliy',      0.1,  3,    'MAG +10, HP +10'],
    ['❄️ Muz Oyog‘i',      'Mag',       'Qudratli',   0.02, 1,    'MAG +17, speed +5'],
    ['🌪️ Bo‘ron Qo‘li',    'Mag',       'Yaxshi',     0.5,  7,    'MAG +6, crit +3%'],
    ['💀 Qorong‘u Qilich',  'Jangchi',   'Qudratli',   0.01, 1,    'ATK +20, HP -10, LifeLeech +10%'],
    ['🦅 Burgut Qanoti',    'O‘qchi',    'Olliy',      0.1,  2,    'ATK +8, speed +6'],
    ['🧲 Qutb Uchi',        'Himoyachi', 'Qudratli',   0.01, 1,    'DEF +17, ATK +2'],
    ['🌈 Ilohiy Sabr',      'Himoyachi', 'Qudratli',   0.01, 1,    'DEF +10, HP +30'],
    ['🦉 Donishmand Asosi', 'Mag',       'Olliy',      0.1,  3,    'MAG +8, XP +10%'],
];

if (!isset($users['rare_counts'])) $users['rare_counts'] = [];
foreach ($rare_def as $i=>$r) if (!isset($users['rare_counts'][$i])) $users['rare_counts'][$i]=0;

if ($text == '⚔️ Noyob Asboblar') {
    sendMessage($chat_id, "⚔️ <b>Noyob Asboblar bo‘limi:</b>", [
        ['🛒 Bozordan noyob asbob qidirish'],
        ['🏦 Auktsion'], ['🗃️ Mening noyob asboblarim'],
        ['⬅️ Orqaga']
    ]);
    exit;
}

if ($text == '🛒 Bozordan noyob asbob qidirish') {
    if ($users[$user_id]['coins'] < 10000) {
        sendMessage($chat_id, "❗️ Qidiruv uchun 10 000 tanga kerak!", [['⬅️ Orqaga']]);
        exit;
    }
    $users[$user_id]['coins'] -= 10000;
    $pool = [];
    foreach ($rare_def as $i=>$r) {
        if ($users['rare_counts'][$i] < $r[4]) for($j=0;$j<$r[3]*10;$j++) $pool[]=$i;
    }
    if (mt_rand(1,100)<=80 || !$pool) {
        sendMessage($chat_id, "😕 Bu safar siz hech narsa topa olmadingiz...", [['⚔️ Noyob Asboblar'], ['⬅️ Orqaga']]);
        saveUsers($users);
        exit;
    }
    $idx = $pool[array_rand($pool)];
    $asb = $rare_def[$idx];
    if (!isset($users[$user_id]['rare_items'])) $users[$user_id]['rare_items']=[];
    $users['rare_counts'][$idx]++;
    $asbob_raqami = $users['rare_counts'][$idx];
    $qolgan = $asb[4] - $users['rare_counts'][$idx];
    if (strpos($asb[2], 'Oddiy') === 0) {
        $noyoblilik = "Oddiy";
    } elseif ($asb[2] === 'Yaxshi') {
        $noyoblilik = "Yaxshi";
    } elseif ($asb[2] === 'Olliy') {
        $noyoblilik = "Olliy";
    } elseif ($asb[2] === 'Qudratli') {
        $noyoblilik = "Qudratli";
    } else {
        $noyoblilik = $asb[2];
    }
    $asbob_effekt = isset($asb[5]) ? $asb[5] : 'Effektsiz';
    $users[$user_id]['rare_items'][] = [
        'name'=>$asb[0], 'role'=>$asb[1], 'level'=>$asb[2],
        'num'=>$asbob_raqami, 'max'=>$asb[4], 'effect'=>$asbob_effekt
    ];
    saveUsers($users);
    sendMessage(
        $chat_id,
        "🎉 <b>Siz haqiqiy noyob asbob topdingiz!</b>\n\n"
        ."<b>{$asb[0]}</b>\n"
        ."Rol: <b>{$asb[1]}</b>\n"
        ."Daraja: <b>{$asb[2]}</b>\n"
        ."🔰 <b>Noyobliligi:</b> $noyoblilik\n"
        ."✨ <b>Effekti:</b> <i>$asbob_effekt</i>\n"
        ."🔢 <b>Bu asbobdan $asbob_raqami-chi bo‘lib topdingiz</b>\n"
        ."🗃️ <b>Qolgan nusxa:</b> $qolgan / {$asb[4]}",
        [['⚔️ Noyob Asboblar'], ['⬅️ Orqaga']]
    );
    exit;
}

// --- UNIVERSAL AUKTSION MODULI: barcha funksiyali, zamonaviy va xatosiz ---

if (!isset($_SESSION['auction'])) $_SESSION['auction'] = [];
if (!isset($_SESSION['auction_bids'])) $_SESSION['auction_bids'] = [];
if (!isset($_SESSION['auction_history'])) $_SESSION['auction_history'] = [];
$auction = &$_SESSION['auction'];
$bids = &$_SESSION['auction_bids'];
$history = &$_SESSION['auction_history'];

// --- LOT MUHLATI TEKSHIRISH (har safar update uchun) ---
foreach ($auction as $i=>$lot) {
    if (isset($lot['expires_at']) && $lot['expires_at']<time()) {
        // Lot muddati tugagan, asbob egasiga qaytariladi, tarixga yoziladi
        $users[$lot['seller']]['rare_items'][] = $lot['asbob'];
        $history[] = [
            'type'=>'expired',
            'asbob'=>$lot['asbob'],
            'user'=>$lot['seller'],
            'when'=>date("Y-m-d H:i:s"),
            'price'=>0
        ];
        array_splice($auction, $i, 1);
        saveUsers($users);
    }
}

// --- Auktsion menyusi ---
if ($text == '🏦 Auktsion') {
    $msg = "🏦 <b>AUKTSION MARKAZI</b>\n";
    $msg .= "Bu yerda noyob asboblarni sotish va xarid qilish mumkin!\n\n";
    if (count($auction) == 0) {
        $msg .= "⛔ Hozircha hech qanday asbob sotuvda emas.";
    } else {
        $msg .= "🛒 <b>Sotuvdagi asboblar:</b>\n";
        foreach ($auction as $i=>$lot) {
            $msg .= "▫️ <b>{$lot['asbob']['name']}</b> <code>#".($i+1)."</code>\n";
            $msg .= "   ├ 💠 <i>{$lot['asbob']['role']}</i> | {$lot['asbob']['level']}\n";
            $msg .= "   ├ ✨ {$lot['asbob']['effect']}\n";
            if (isset($lot['discount']) && $lot['discount']) $msg .= "   ├ 🔻 <b>Chegirma:</b> {$lot['discount']}%\n";
            if (isset($lot['expires_at']))  $msg .= "   ├ ⏳ <b>Muddat:</b> ".date("H:i", $lot['expires_at'])."\n";
            if (isset($bids[$lot['id']]) && count($bids[$lot['id']])) {
                $top = max($bids[$lot['id']]);
                $msg .= "   ├ 🏷️ <b>Eng yuqori taklif:</b> $top tanga\n";
            }
            $msg .= "   ├ 💰 <b>{$lot['price']}</b> tanga";
            if (isset($lot['buy_now'])) $msg .= " | <b>Tez xarid:</b> {$lot['buy_now']} tanga";
            $msg .= "\n   └ 👤 <code>{$lot['seller']}</code>";
            if ($lot['seller'] == $user_id) $msg .= " <b>(siz)</b>";
            $msg .= "\n";
        }
    }
    $btns = [
        ['➕ Sotuvga qo‘yish', '🛒 Xarid qilish'],
        ['🎫 Mening lotlarim', '🕓 Tarix', '🔍 Qidirish'],
        ['⬅️ Orqaga']
    ];
    sendMessage($chat_id, $msg, $btns);
    exit;
}
// --- Lot qidirish va filtrlash ---
if ($text == '🔍 Qidirish') {
    $_SESSION['auction_search'] = true;
    sendMessage($chat_id, "🔍 Qidiruv uchun asbob nomi, rol, daraja yoki minimal narx kiriting:", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_search'])) {
    if ($text == '⬅️ Orqaga') {
        unset($_SESSION['auction_search']);
        sendMessage($chat_id, "Bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    $msg = "🔍 <b>Qidiruv natijasi:</b>\n";
    $btns = [];
    $cnt = 0;
    $query = mb_strtolower($text);
    foreach ($auction as $i=>$lot) {
        if (
            strpos(mb_strtolower($lot['asbob']['name']), $query)!==false
            || strpos(mb_strtolower($lot['asbob']['role']), $query)!==false
            || strpos(mb_strtolower($lot['asbob']['level']), $query)!==false
            || (is_numeric($query) && $lot['price']>=intval($query))
        ) {
            $btns[] = ["Xarid: #$i {$lot['asbob']['name']}"];
            $msg .= "▫️ {$lot['asbob']['name']} | {$lot['asbob']['level']} | Narx: <b>{$lot['price']}</b>\n";
            $cnt++;
        }
    }
    if (!$cnt) $msg .= "⛔ Hech narsa topilmadi!";
    $btns[] = ['⬅️ Orqaga'];
    sendMessage($chat_id, $msg, $btns);
    unset($_SESSION['auction_search']);
    exit;
}

// --- Asbobni sotuvga qo‘yish (lot) ---
if ($text == '➕ Sotuvga qo‘yish') {
    $rare = $users[$user_id]['rare_items'] ?? [];
    if (!$rare) {
        sendMessage($chat_id, "❗ Sizda sotuvga qo‘yiladigan noyob asbob yo‘q!", [['🏦 Auktsion'],['⬅️ Orqaga']]);
        exit;
    }
    $_SESSION['auction_add_step'] = 1;
    sendMessage($chat_id, "📦 Qaysi asbobni sotuvga chiqarmoqchisiz?\n\nRo‘yxatdan tanlang:", array_map(function($k,$a){return ["#{$k} {$a['name']}"];}, array_keys($rare), $rare) + [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 1 && preg_match('/^#(\d+)/', $text, $m)) {
    $idx = intval($m[1]);
    $rare = $users[$user_id]['rare_items'] ?? [];
    if (!isset($rare[$idx])) {
        sendMessage($chat_id, "❗ Bunday asbob topilmadi!", [['🏦 Auktsion']]);
        unset($_SESSION['auction_add_step']); exit;
    }
    $_SESSION['auction_add_idx'] = $idx;
    $_SESSION['auction_add_step'] = 2;
    $asb = $rare[$idx];
    sendMessage($chat_id, "💰 <b>{$asb['name']}</b> uchun <b>minimal narx</b>ni kiriting (masalan: 25000):", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 2) {
    if ($text == '⬅️ Orqaga') {
        unset($_SESSION['auction_add_step'], $_SESSION['auction_add_idx']);
        sendMessage($chat_id, "❌ Bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    if (!is_numeric($text) || intval($text)<=0) {
        sendMessage($chat_id, "❗ Minimal narx faqat musbat butun son (tanga) bo‘lishi kerak!", [['⬅️ Orqaga']]);
        exit;
    }
    $_SESSION['auction_add_price'] = intval($text);
    $_SESSION['auction_add_step'] = 3;
    sendMessage($chat_id, "🔝 <b>Tez sotib olish (Buy now)</b> narxini istasangiz kiriting (yoki 0 deb yozing):", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 3) {
    if ($text == '⬅️ Orqaga') {
        $_SESSION['auction_add_step'] = 2;
        sendMessage($chat_id, "💰 Minimal narxni kiriting:", [['⬅️ Orqaga']]);
        exit;
    }
    if (!is_numeric($text) || intval($text)<0) {
        sendMessage($chat_id, "❗ Narx musbat yoki 0 bo‘lishi kerak!", [['⬅️ Orqaga']]);
        exit;
    }
    $_SESSION['auction_add_buy_now'] = intval($text);
    $_SESSION['auction_add_step'] = 4;
    sendMessage($chat_id, "🔻 Chegirma foizini kiriting (yoki 0):", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 4) {
    if ($text == '⬅️ Orqaga') {
        $_SESSION['auction_add_step'] = 3;
        sendMessage($chat_id, "🔝 Buy now narxini kiriting:", [['⬅️ Orqaga']]);
        exit;
    }
    if (!is_numeric($text) || intval($text)<0 || intval($text)>80) {
        sendMessage($chat_id, "❗ Chegirma 0 dan 80% gacha bo‘lishi mumkin!", [['⬅️ Orqaga']]);
        exit;
    }
    $_SESSION['auction_add_discount'] = intval($text);
    $_SESSION['auction_add_step'] = 5;
    sendMessage($chat_id, "⏳ Lot muddatini necha daqiqa davomida sotuvda bo‘lishini xohlaysiz? (masalan: 60, 1440 yoki 0):", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 5) {
    if ($text == '⬅️ Orqaga') {
        $_SESSION['auction_add_step'] = 4;
        sendMessage($chat_id, "🔻 Chegirma foizini kiriting (yoki 0):", [['⬅️ Orqaga']]);
        exit;
    }
    if (!is_numeric($text) || intval($text)<0 || intval($text)>10080) {
        sendMessage($chat_id, "❗ Muddat 0 (cheksiz) yoki 1-10080 (7 kun) daqiqa oraliqda!", [['⬅️ Orqaga']]);
        exit;
    }
    $idx = $_SESSION['auction_add_idx'];
    $rare = $users[$user_id]['rare_items'] ?? [];
    if (!isset($rare[$idx])) {
        sendMessage($chat_id, "❗ Asbob topilmadi yoki allaqachon sotilgan!", [['🏦 Auktsion']]);
        unset($_SESSION['auction_add_step'], $_SESSION['auction_add_idx'], $_SESSION['auction_add_price'], $_SESSION['auction_add_buy_now'], $_SESSION['auction_add_discount']); exit;
    }
    $asb = $rare[$idx];
    $narx = $_SESSION['auction_add_price'];
    $buy_now = $_SESSION['auction_add_buy_now'];
    $discount = $_SESSION['auction_add_discount'];
    $expires_at = intval($text)>0 ? (time() + intval($text)*60) : null;

    // Tasdiqlash
    $msg = "⚡ <b>{$asb['name']}</b> asbobini auktsionga quyidagicha qo‘ymoqchimisiz?\n";
    $msg .= "💰 Minimal narx: <b>$narx</b>\n";
    if ($buy_now) $msg .= "🔝 Tez xarid: <b>$buy_now</b>\n";
    if ($discount) $msg .= "🔻 Chegirma: <b>$discount%</b>\n";
    if ($expires_at) $msg .= "⏳ Muddat: ".date("Y-m-d H:i", $expires_at)."\n";
    $msg .= "Tasdiqlaysizmi?";
    $_SESSION['auction_add_expires'] = $expires_at;
    $_SESSION['auction_add_step'] = 6;
    sendMessage($chat_id, $msg, [['Tasdiqlash','Bekor qilish']]);
    exit;
}
if (isset($_SESSION['auction_add_step']) && $_SESSION['auction_add_step'] == 6) {
    if ($text == 'Bekor qilish' || $text == '⬅️ Orqaga') {
        unset($_SESSION['auction_add_step'], $_SESSION['auction_add_idx'], $_SESSION['auction_add_price'], $_SESSION['auction_add_buy_now'], $_SESSION['auction_add_discount'], $_SESSION['auction_add_expires']);
        sendMessage($chat_id, "❌ Bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    if ($text == 'Tasdiqlash') {
        $idx = $_SESSION['auction_add_idx'];
        $rare = $users[$user_id]['rare_items'] ?? [];
        if (!isset($rare[$idx])) {
            sendMessage($chat_id, "❗ Asbob topilmadi yoki allaqachon sotilgan!", [['🏦 Auktsion']]);
            unset($_SESSION['auction_add_step'], $_SESSION['auction_add_idx'], $_SESSION['auction_add_price'], $_SESSION['auction_add_buy_now'], $_SESSION['auction_add_discount'], $_SESSION['auction_add_expires']); exit;
        }
        $asb = $rare[$idx];
        $auction[] = [
            'asbob'=>$asb,
            'seller'=>$user_id,
            'price'=>$_SESSION['auction_add_price'],
            'buy_now'=>$_SESSION['auction_add_buy_now'],
            'discount'=>$_SESSION['auction_add_discount'],
            'expires_at'=>$_SESSION['auction_add_expires'],
            'id'=>uniqid()
        ];
        array_splice($users[$user_id]['rare_items'], $idx, 1);
        saveUsers($users);
        unset($_SESSION['auction_add_step'], $_SESSION['auction_add_idx'], $_SESSION['auction_add_price'], $_SESSION['auction_add_buy_now'], $_SESSION['auction_add_discount'], $_SESSION['auction_add_expires']);
        sendMessage($chat_id, "✅ <b>{$asb['name']}</b> auktsionga muvaffaqiyatli qo‘yildi!", [['🏦 Auktsion']]);
        exit;
    }
}

// --- Xarid qilish (Buy now va oddiy) ---
if ($text == '🛒 Xarid qilish') {
    $msg = "🛍️ <b>Sotuvdagi asboblar:</b>\n";
    $btns = [];
    $cnt = 0;
    foreach ($auction as $i=>$lot) {
        if ($lot['seller'] == $user_id) continue;
        $btn = "Xarid: #$i {$lot['asbob']['name']}";
        if (isset($lot['buy_now']) && $lot['buy_now']>0) $btn = "Tez xarid: #$i {$lot['asbob']['name']}";
        $btns[] = [$btn, "Taklif: #$i"];
        $msg .= "▫️ {$lot['asbob']['name']} | {$lot['asbob']['level']} | Narx: <b>{$lot['price']}</b>";
        if (isset($lot['buy_now']) && $lot['buy_now']>0) $msg .= " | Tez xarid: <b>{$lot['buy_now']}</b>";
        $msg .= "\n";
        $cnt++;
    }
    if (!$cnt) $msg .= "⛔ Xarid uchun boshqa odam loti yo‘q!";
    $btns[] = ['⬅️ Orqaga'];
    sendMessage($chat_id, $msg, $btns);
    exit;
}
if (preg_match('~^Tez xarid: #(\d+)~', $text, $m) || preg_match('~^Xarid: #(\d+)~', $text, $m)) {
    $lot_idx = intval($m[1]);
    if (!isset($auction[$lot_idx])) {
        sendMessage($chat_id, "❗ Lot topilmadi yoki allaqachon sotilgan!", [['🏦 Auktsion']]);
        exit;
    }
    $lot = $auction[$lot_idx];
    if ($lot['seller'] == $user_id) {
        sendMessage($chat_id, "❗ O‘z lotingizni xarid qila olmaysiz!", [['🏦 Auktsion']]);
        exit;
    }
    $narx = (strpos($text,'Tez xarid')!==false && isset($lot['buy_now']) && $lot['buy_now']>0) ? $lot['buy_now'] : $lot['price'];
    if ($users[$user_id]['coins'] < $narx) {
        sendMessage($chat_id, "❗ Sizda yetarli tanga yo‘q!", [['🏦 Auktsion']]);
        exit;
    }
    $_SESSION['auction_buy_idx'] = $lot_idx;
    $_SESSION['auction_buy_now'] = $narx;
    sendMessage($chat_id, "💳 <b>{$lot['asbob']['name']}</b> lotini <b>$narx tanga</b> sotib olishni tasdiqlaysizmi?", [['Tasdiqlash','Bekor qilish']]);
    exit;
}
if (isset($_SESSION['auction_buy_idx'])) {
    $lot_idx = $_SESSION['auction_buy_idx'];
    $narx = $_SESSION['auction_buy_now'];
    if ($text == 'Bekor qilish' || $text == '⬅️ Orqaga') {
        unset($_SESSION['auction_buy_idx'], $_SESSION['auction_buy_now']);
        sendMessage($chat_id, "❌ Xarid bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    if ($text == 'Tasdiqlash') {
        if (!isset($auction[$lot_idx])) {
            sendMessage($chat_id, "❗ Lot allaqachon sotilgan yoki topilmadi!", [['🏦 Auktsion']]);
            unset($_SESSION['auction_buy_idx'], $_SESSION['auction_buy_now']); exit;
        }
        $lot = $auction[$lot_idx];
        if ($users[$user_id]['coins'] < $narx) {
            sendMessage($chat_id, "❗ Sizda yetarli tanga yo‘q!", [['🏦 Auktsion']]);
            unset($_SESSION['auction_buy_idx'], $_SESSION['auction_buy_now']); exit;
        }
        $users[$user_id]['coins'] -= $narx;
        if (!isset($users[$lot['seller']]['coins'])) $users[$lot['seller']]['coins']=0;
        $users[$lot['seller']]['coins'] += $narx;
        if (!isset($users[$user_id]['rare_items'])) $users[$user_id]['rare_items'] = [];
        $users[$user_id]['rare_items'][] = $lot['asbob'];
        $history[] = [
            'type'=>'sold',
            'asbob'=>$lot['asbob'],
            'user'=>$user_id,
            'when'=>date("Y-m-d H:i:s"),
            'price'=>$narx
        ];
        sendMessage($chat_id, "🎉 <b>{$lot['asbob']['name']}</b> xarid qilindi va inventaringizga qo‘shildi!", [['🏦 Auktsion']]);
        sendMessage($lot['seller'], "💰 Sizning <b>{$lot['asbob']['name']}</b> asbobingiz sotildi va <b>$narx</b> tanga balansingizga qo‘shildi!", []);
        array_splice($auction, $lot_idx, 1);
        saveUsers($users);
        unset($_SESSION['auction_buy_idx'], $_SESSION['auction_buy_now']);
        exit;
    }
}
// --- Taklif (bid) qilish ---
if (preg_match('~^Taklif: #(\d+)~', $text, $m)) {
    $lot_idx = intval($m[1]);
    if (!isset($auction[$lot_idx])) {
        sendMessage($chat_id, "❗ Lot topilmadi!", [['🏦 Auktsion']]);
        exit;
    }
    $lot = $auction[$lot_idx];
    if ($lot['seller'] == $user_id) {
        sendMessage($chat_id, "❗ O‘z lotingizga taklif bera olmaysiz!", [['🏦 Auktsion']]);
        exit;
    }
    $_SESSION['auction_bid_idx'] = $lot_idx;
    sendMessage($chat_id, "💸 Taklif qilmoqchi bo‘lgan narxni kiriting (minimal: {$lot['price']}):", [['⬅️ Orqaga']]);
    exit;
}
if (isset($_SESSION['auction_bid_idx'])) {
    $lot_idx = $_SESSION['auction_bid_idx'];
    if ($text == '⬅️ Orqaga') {
        unset($_SESSION['auction_bid_idx']);
        sendMessage($chat_id, "Taklif bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    if (!isset($auction[$lot_idx])) {
        sendMessage($chat_id, "❗ Lot topilmadi!", [['🏦 Auktsion']]);
        unset($_SESSION['auction_bid_idx']); exit;
    }
    $lot = $auction[$lot_idx];
    if (!is_numeric($text) || intval($text)<$lot['price']) {
        sendMessage($chat_id, "❗ Taklif minimal narxdan kam bo‘lishi mumkin emas!", [['⬅️ Orqaga']]);
        exit;
    }
    if ($users[$user_id]['coins'] < intval($text)) {
        sendMessage($chat_id, "❗ Sizda yetarli tanga yo‘q!", [['⬅️ Orqaga']]);
        exit;
    }
    if (!isset($bids[$lot['id']])) $bids[$lot['id']] = [];
    $bids[$lot['id']][$user_id] = intval($text);
    sendMessage($chat_id, "✅ Taklif qabul qilindi! Agar egasi rozilik bersa, asbob sizga o‘tadi.", [['🏦 Auktsion']]);
    sendMessage($lot['seller'], "📢 Lot <b>{$lot['asbob']['name']}</b> uchun yangi taklif: <b>$text tanga</b> (user_id: $user_id)", []);
    unset($_SESSION['auction_bid_idx']);
    exit;
}

// --- Egasi uchun: eng yuqori bidni ko‘rish va "qabul qilish" ---
if ($text == '🎫 Mening lotlarim') {
    $btns = [];
    $msg = "🎫 <b>Sizning lotlaringiz:</b>\n";
    $has = false;
    foreach ($auction as $i=>$lot) {
        if ($lot['seller'] == $user_id) {
            $bid_btn = '';
            if (isset($bids[$lot['id']]) && count($bids[$lot['id']])) $bid_btn = " | 🏷️ Qabul: #$i";
            $btns[] = ["Olib tashlash: #$i {$lot['asbob']['name']}$bid_btn"];
            $msg .= "▫️ {$lot['asbob']['name']} | {$lot['asbob']['level']} | Narx: <b>{$lot['price']}</b>\n";
            $has = true;
        }
    }
    if (!$has) $msg .= "⛔ Sizda hozircha lot yo‘q!";
    $btns[] = ['⬅️ Orqaga'];
    sendMessage($chat_id, $msg, $btns);
    exit;
}
if (preg_match('~^Olib tashlash: #(\d+)~', $text, $m)) {
    $lot_idx = intval($m[1]);
    if (!isset($auction[$lot_idx]) || $auction[$lot_idx]['seller']!=$user_id) {
        sendMessage($chat_id, "❗ Lot topilmadi.", [['🏦 Auktsion']]);
        exit;
    }
    $_SESSION['auction_del_idx'] = $lot_idx;
    sendMessage($chat_id, "❓ Ushbu lotni bekor qilmoqchimisiz?\n<b>{$auction[$lot_idx]['asbob']['name']}</b> | Narx: {$auction[$lot_idx]['price']}", [['Tasdiqlash','Bekor qilish']]);
    exit;
}
if (isset($_SESSION['auction_del_idx'])) {
    $lot_idx = $_SESSION['auction_del_idx'];
    if ($text == 'Bekor qilish' || $text == '⬅️ Orqaga') {
        unset($_SESSION['auction_del_idx']);
        sendMessage($chat_id, "❌ Lot bekor qilinishi to‘xtatildi.", [['🏦 Auktsion']]);
        exit;
    }
    if ($text == 'Tasdiqlash') {
        $lot = $auction[$lot_idx];
        $users[$user_id]['rare_items'][] = $lot['asbob'];
        array_splice($auction, $lot_idx, 1);
        saveUsers($users);
        unset($_SESSION['auction_del_idx']);
        sendMessage($chat_id, "✅ Lot bekor qilindi va asbob inventaringizga qaytdi.", [['🏦 Auktsion']]);
        exit;
    }
}

// --- Egasi uchun eng yuqori taklifni qabul qilish ---
if (preg_match('~^🏷️ Qabul: #(\d+)~', $text, $m)) {
    $lot_idx = intval($m[1]);
    if (!isset($auction[$lot_idx]) || $auction[$lot_idx]['seller']!=$user_id) {
        sendMessage($chat_id, "❗ Lot topilmadi.", [['🏦 Auktsion']]);
        exit;
    }
    $lot = $auction[$lot_idx];
    if (!isset($bids[$lot['id']]) || !count($bids[$lot['id']])) {
        sendMessage($chat_id, "❗ Bu lot uchun taklif yo‘q!", [['🏦 Auktsion']]);
        exit;
    }
    // Eng yuqori taklifchi va narx
    $max_bid = max($bids[$lot['id']]);
    $max_uid = array_search($max_bid, $bids[$lot['id']]);
    $_SESSION['auction_accept_bid'] = [$lot_idx, $max_uid, $max_bid];
    sendMessage($chat_id, "👤 User <code>$max_uid</code> <b>$max_bid tanga</b> taklif qilgan. Qabul qilasizmi?", [['Tasdiqlash','Bekor qilish']]);
    exit;
}
if (isset($_SESSION['auction_accept_bid'])) {
    list($lot_idx, $max_uid, $max_bid) = $_SESSION['auction_accept_bid'];
    if ($text == 'Bekor qilish' || $text == '⬅️ Orqaga') {
        unset($_SESSION['auction_accept_bid']);
        sendMessage($chat_id, "❌ Qabul qilish bekor qilindi.", [['🏦 Auktsion']]);
        exit;
    }
    if ($text == 'Tasdiqlash') {
        if (!isset($auction[$lot_idx]) || !isset($bids[$auction[$lot_idx]['id']][$max_uid])) {
            sendMessage($chat_id, "❗ Taklif yoki lot topilmadi!", [['🏦 Auktsion']]);
            unset($_SESSION['auction_accept_bid']); exit;
        }
        if ($users[$max_uid]['coins'] < $max_bid) {
            sendMessage($chat_id, "❗ Xaridor balansida mablag‘ yetarli emas!", [['🏦 Auktsion']]);
            unset($bids[$auction[$lot_idx]['id']][$max_uid]);
            unset($_SESSION['auction_accept_bid']); exit;
        }
        $lot = $auction[$lot_idx];
        $users[$max_uid]['coins'] -= $max_bid;
        if (!isset($users[$user_id]['coins'])) $users[$user_id]['coins']=0;
        $users[$user_id]['coins'] += $max_bid;
        $users[$max_uid]['rare_items'][] = $lot['asbob'];
        $history[] = [
            'type'=>'sold_bid',
            'asbob'=>$lot['asbob'],
            'user'=>$max_uid,
            'when'=>date("Y-m-d H:i:s"),
            'price'=>$max_bid
        ];
        sendMessage($user_id, "✅ Lot <b>{$lot['asbob']['name']}</b> <b>$max_bid tanga</b> evaziga sotildi!", [['🏦 Auktsion']]);
        sendMessage($max_uid, "🎉 Sizning taklifingiz qabul qilindi. <b>{$lot['asbob']['name']}</b> inventaringizga qo‘shildi!", []);
        array_splice($auction, $lot_idx, 1);
        unset($bids[$lot['id']]);
        saveUsers($users);
        unset($_SESSION['auction_accept_bid']);
        exit;
    }
}

// --- Tarix (savdo va taklif) ---
if ($text == '🕓 Tarix') {
    $msg = "🕓 <b>Auktsion tarixi:</b>\n";
    $cnt = 0;
    foreach ($history as $h) {
        if ($h['user'] == $user_id) {
            $t = ($h['type']=='sold' || $h['type']=='sold_bid') ? "Sotib olindi" : "Muddati tugadi";
            $msg .= "▫️ {$h['asbob']['name']} | $t | {$h['when']}";
            if ($h['price']) $msg .= " | {$h['price']} tanga";
            $msg .= "\n";
            $cnt++;
        }
    }
    if (!$cnt) $msg .= "⛔ Sizda hali savdo tarixi yo‘q!";
    sendMessage($chat_id, $msg, [['🏦 Auktsion']]);
    exit;
}

if ($text == '🗃️ Mening noyob asboblarim') {
    $rare = $users[$user_id]['rare_items'] ?? [];

    if (empty($rare)) {
        sendMessage($chat_id, "❌ Sizda hali noyob asboblar yo‘q.", [
            ['⚔️ Noyob Asboblar'],
            ['⬅️ Orqaga']
        ]);
    } else {
        $msg = "🗃️ <b>Sizning noyob asboblaringiz:</b>\n\n";
        foreach ($rare as $index => $item) {
            $name = $item['name'];
            $level = $item['level'];
            $effect = $item['effect'] ?? '';
            $num = $item['num'] ?? null;
            $max = $item['max'] ?? null;

            $line = "🔹 <b>$name</b> | 🧬 <b>$level</b>";
            if ($effect) $line .= " | ✨ $effect";
            if ($num !== null && $max !== null) $line .= " | 📦 #$num/$max";
            $msg .= $line . "\n";
        }

        sendMessage($chat_id, $msg, [
            ['⬅️ Orqaga']
        ]);
    }
    exit;
}



// --- SHOPGA KIRISH BLOKI ---
if ($text == '🛒 Do‘konga kirish') {
    $shop_msg = "🛒 <b>Do‘kon:</b>\n";
    foreach ($shop as $item){
        $shop_msg .= "{$item['name']} — <b>{$item['cost']}</b> tanga\n<i>{$item['desc']}</i>\n\n";
    }
    $shop_buttons = array_map(function($i){ return [$i['name']]; }, $shop);
    $shop_buttons[] = ['⬅️ Orqaga'];
    $users[$user_id]['state'] = 'buy_item';
    saveUsers($users);
    sendMessage($chat_id, $shop_msg."\nTovarni tanlang:", $shop_buttons);
    exit;
}

// --- SHOPDAN ITEM SOTIB OLISH BLOKI ---
if (isset($users[$user_id]['state']) && $users[$user_id]['state'] == 'buy_item') {
    foreach ($shop as $item) {
        if ($text == $item['name']) {
            if ($users[$user_id]['coins'] < $item['cost']) {
                sendMessage($chat_id, "❌ Yetarli tangangiz yo‘q! Kerakli: {$item['cost']}, Sizda: {$users[$user_id]['coins']}", [['📦 Inventar', '⬅️ Orqaga']]);
                $users[$user_id]['state'] = '';
                exit;
            }
            $users[$user_id]['coins'] -= $item['cost'];
            $users[$user_id]['inventory'][] = $item['id'];
            $users[$user_id]['state'] = '';
            saveUsers($users);
            sendMessage($chat_id, "✅ <b>{$item['name']}</b> inventarga qo‘shildi!", [['📦 Inventar', '⬅️ Orqaga']]);
            exit;
        }
    }
    // Orqaga qaytish
    if ($text == '⬅️ Orqaga') {
        $users[$user_id]['state'] = '';
        saveUsers($users);
        sendMessage($chat_id, "Asosiy menyu", [['📦 Inventar']]);
        exit;
    }
}

// --- INVENTAR BLOKI --- //
if ($text == '📦 Inventar') {
    if (!isset($users[$user_id]['coins'])) $users[$user_id]['coins'] = 0;
    if (!isset($users[$user_id]['inventory'])) $users[$user_id]['inventory'] = [];
    $inv = $users[$user_id]['inventory'];
    $inv_msg = "🎒 <b>Inventar:</b>\n";
    if (empty($inv)) $inv_msg .= "Inventaringiz bo'sh.";
    else $inv_msg .= implode("\n", array_map(function($iid)use($items){return "- ".(isset($items[$iid])?$items[$iid]:$iid);}, $inv));
    $inv_msg .= "\n\n💰 <b>Tanga:</b> {$users[$user_id]['coins']}";
    // --- Tugmalarda item nomi chiqadi
    $inv_buttons = empty($inv)
        ? [['🛒 Do‘konga kirish'], ['⬅️ Orqaga']]
        : array_merge(
            array_map(function($iid)use($items){ return [($items[$iid] ?? $iid)]; }, $inv),
            [['🛒 Do‘konga kirish'], ['⬅️ Orqaga']]
        );
    $users[$user_id]['state'] = 'use_item';
    saveUsers($users);
    sendMessage($chat_id, $inv_msg."\n\nItemni ishlatish uchun ustiga bosing yoki do‘konga kiring:", $inv_buttons);
    exit;
}

// --- ITEM ISHLATISH BLOKI --- //
if (isset($users[$user_id]['state']) && $users[$user_id]['state'] == 'use_item') {
    // Foydalanuvchi tugmasi nomini bosadi, biz id ni topamiz
    $iid = array_search($text, $items); // nomdan id topiladi
    $inv = &$users[$user_id]['inventory'];
    if ($iid !== false && ($key = array_search($iid, $inv)) !== false) {
        unset($inv[$key]);
        switch($iid) {
            case "mini_potion": $msg = "🧃 HP +5!"; $users[$user_id]['stats']['hp'] += 5; break;
            case "snack":       $msg = "🍪 Energiya +1!"; $users[$user_id]['energy'] += 1; break;
            case "stone":       $msg = "🪨 Def +1!"; $users[$user_id]['stats']['def'] += 1; break;
            case "stick":       $msg = "🌿 Atk +1!"; $users[$user_id]['stats']['atk'] += 1; break;
            case "book":        $msg = "📕 XP +3!"; $users[$user_id]['xp'] += 3; break;
            default:            $msg = "Noma'lum buyum.";
        }
        saveUsers($users);
        sendMessage($chat_id, $msg, [['📦 Inventar', '⬅️ Orqaga']]);
    } else {
        sendMessage($chat_id, "Bu buyum sizda yo‘q!", [['📦 Inventar', '⬅️ Orqaga']]);
    }
    $users[$user_id]['state'] = '';
    exit;
}
    
if ($text == '🗡 Oʻynash') {
    $users[$user_id]['state'] = 'game_menu';
    saveUsers($users);
    sendMessage($chat_id, 
        "🎮 <b>O‘yin menyusi</b>\n\nDaraja: {$users[$user_id]['level']}  |  XP: {$users[$user_id]['xp']}\nEnergiya: {$users[$user_id]['energy']} ⚡️\n\nHarakat tanlang:",
        [
            ['🔍 Sarguzasht', '💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
            ['⬅️ Orqaga']
        ]
    );
    exit;
}
if ($users[$user_id]['state'] == 'game_menu') {
    if ($text == '🔍 Sarguzasht') {
        if ($users[$user_id]['energy'] <= 0) {
            sendMessage($chat_id, "Sizda energiya qolmagan! Dam olish orqali to‘ldiring. 💤", [
                ['💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
                ['⬅️ Orqaga']
            ]);
            exit;
        }
        $users[$user_id]['energy']--;
        list($msg, $levelup) = adventureEvent($users[$user_id], $users[$user_id]['adventure_log']);
        saveUsers($users);
        sendMessage($chat_id, 
            "🧭 <b>Sarguzasht natijasi</b>\n\n$msg\n\nStatlar:\n❤️ HP: {$users[$user_id]['stats']['hp']}\n💧 MP: {$users[$user_id]['stats']['mp']}\n⚔️ Atk: {$users[$user_id]['stats']['atk']}\n🛡 Def: {$users[$user_id]['stats']['def']}\n⚡️ Energiya: {$users[$user_id]['energy']}\n\nDaraja: {$users[$user_id]['level']} | XP: {$users[$user_id]['xp']}\n💰 Tanga: {$users[$user_id]['coins']}", 
            [
                ['🔍 Sarguzasht', '💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
                ['⬅️ Orqaga']
            ]
        );
        exit;
    } elseif ($text == '💤 Dam olish') {
        $users[$user_id]['energy'] += 3;
        if ($users[$user_id]['energy'] > 5) $users[$user_id]['energy'] = 5;
        $users[$user_id]['stats']['hp'] += 3;
        saveUsers($users);
        sendMessage($chat_id, 
            "😌 <b>Dam olish natijasi</b>\n\nSiz dam oldingiz va energiyangiz tiklandi!\n+3 HP, ⚡️ Energiya to‘ldi.\n\nHozirgi statlar:\n❤️ HP: {$users[$user_id]['stats']['hp']}\n⚡️ Energiya: {$users[$user_id]['energy']}\nDaraja: {$users[$user_id]['level']} | XP: {$users[$user_id]['xp']}\n💰 Tanga: {$users[$user_id]['coins']}", 
            [
                ['🔍 Sarguzasht', '💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
                ['⬅️ Orqaga']
            ]
        );
        exit;
    } elseif ($text == '📜 Oxirgi sarguzashtlar') {
        $log = $users[$user_id]['adventure_log'];
        if (!$log) $msg = "Sizda hali sarguzashtlar yo‘q!";
        else $msg = implode("\n\n", array_map(fn($x, $i)=>($i+1).". ".$x, $log, array_keys($log)));
        sendMessage($chat_id, 
            "📜 <b>Oxirgi 3 sarguzasht:</b>\n\n$msg", 
            [
                ['🔍 Sarguzasht', '💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
                ['⬅️ Orqaga']
            ]
        );
        exit;
    } else {
        sendMessage($chat_id, "Harakatni tanlang!", [
            ['🔍 Sarguzasht', '💤 Dam olish', '📜 Oxirgi sarguzashtlar'],
            ['⬅️ Orqaga']
        ]);
        exit;
    }
}
if ($text == '🥇 Reyting') {
    $top = $users;
    usort($top, fn($a, $b) => ($b['level']*100+$b['xp'])-($a['level']*100+$a['xp']));
    $msg = "🥇 <b>Eng kuchli 5 o‘yinchi:</b>\n";
    $i = 1;
    foreach ($top as $u) {
        if (!$u['registered']) continue;
        $msg .= "{$i}. <b>{$u['first_name']}</b> ({$u['role']}) - Level {$u['level']}, XP {$u['xp']}\n";
        $i++; if ($i > 5) break;
    }
    sendMessage($chat_id, $msg, [['⬅️ Orqaga']]);
    exit;
}
if ($text == '📊 Statistika') {
    $u = &$users[$user_id];
    $stat = $u['stats'];
    function bar($val, $max, $len = 10, $fill = "█", $empty = "░") {
        $pc = min(1, $max ? $val/$max : 0);
        $f = round($pc * $len);
        return str_repeat($fill, $f) . str_repeat($empty, $len - $f);
    }
    $xp_next = $u['level']*25 + 15;
    $last_event = (isset($u['adventure_log']) && count($u['adventure_log'])) ? end($u['adventure_log']) : "Siz hali sarguzashtda qatnashmagansiz!";
    $u['total_adventures'] = $u['total_adventures'] ?? 0;
    $u['total_wins'] = $u['total_wins'] ?? 0;
    $u['total_losses'] = $u['total_losses'] ?? 0;
    $max_hp = 120 + ($u['level']-1)*7; if ($u['role'] == "✨ Sehrgar") $max_hp = 80 + ($u['level']-1)*7; elseif ($u['role'] == "🏹 O‘qchi") $max_hp = 100 + ($u['level']-1)*7;
    $max_mp = 30 + ($u['level']-1)*3; if ($u['role'] == "✨ Sehrgar") $max_mp = 80 + ($u['level']-1)*3; elseif ($u['role'] == "🏹 O‘qchi") $max_mp = 40 + ($u['level']-1)*3;
    $msg = "👤 <b>{$u['first_name']}</b>  |  {$u['role']}
──────────────────────
🎚 <b>LEVEL:</b> {$u['level']}
🧬 <b>XP:</b> {$u['xp']} / $xp_next " . bar($u['xp'], $xp_next, 12) . "
──────────────────────
❤️ <b>HP:</b> {$stat['hp']} / $max_hp  " . bar($stat['hp'], $max_hp, 12, "🟥", "⬜️") . "
💧 <b>MP:</b> {$stat['mp']} / $max_mp  " . bar($stat['mp'], $max_mp, 12, "🟦", "⬜️") . "
⚔️ <b>ATK:</b> {$stat['atk']}   🛡 <b>DEF:</b> {$stat['def']}
⚡️ <b>Energiya:</b> {$u['energy']} / 5 " . bar($u['energy'], 5, 5, "🟩", "⬛️") . "
──────────────────────
💰 <b>Tanga:</b> {$u['coins']}
📈 <b>Umumiy sarguzashtlar:</b> {$u['total_adventures']}
🏅 <b>G‘alabalar:</b> {$u['total_wins']}
💀 <b>Mag‘lubiyatlar:</b> {$u['total_losses']}
──────────────────────
📝 <b>Oxirgi sarguzasht natijasi:</b>
$last_event
──────────────────────
📢 <i>Reytingga chiqing, sarguzasht qiling, do‘stlaringizga rekordlaringizni ko‘rsating va yangi imkoniyatlardan foydalaning!</i>
";
    sendMessage($chat_id, $msg, [['⬅️ Orqaga']]);
    exit;
}
if ($text == 'ℹ️ Qoʻllanma') {
    sendMessage($chat_id, "1. Ro‘yxatdan o‘ting (faqat bir marta).\n2. Rol tanlang (o‘zgartirish mumkin emas).\n3. Sarguzashtga chiqing va kuchayib boring.\n4. Level, XP va energiyani to‘plang!\n5. Reyting va yangi funksiyalarni o‘rganing!\n6. Har doim ⬅️ Orqaga bosib asosiy menyuga qaytishingiz mumkin.", [['⬅️ Orqaga']]);
    exit;
}
sendMessage($chat_id, "Asosiy menyu", mainMenu($users[$user_id]['registered'], isAdmin($user_id)));
exit;
?>
