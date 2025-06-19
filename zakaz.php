<?php 
ini_set('display_errors',1);

ob_start();
define('API_KEY','6728105149:AAHBshZKUZ5e41NpemY6pVFVGQ8iu-Ba9ec');

$tolovkanal="-1002152142480";
$auksionkanal="-1002152142480";
$bot=bot('getMe',['bot'])->result->username;
$admin= "1999997369";

echo file_get_contents('https://api.telegram.org/bot'.API_KEY.'/setwebhook?url='.$_SERVER["SERVER_NAME"].''.$_SERVER["SCRIPT_NAME"].'&allowed_updates=["message","edited_message","callback_query","my_chat_member","chat_member"]');


function bot($method,$datas=[]){
$url = "https://api.telegram.org/bot".API_KEY."/".$method;
$ch = curl_init();
curl_setopt($ch,CURLOPT_URL,$url);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$datas);
$res = curl_exec($ch);
if(curl_error($ch)){
var_dump(curl_error($ch));
}else{
return json_decode($res);
}
}


$db = mysqli_connect("localhost","67590ff43cae0_auk","qwert123","67590ff43cae0_auk");
if($db){
echo "Ulandi</br>";
}else{
echo "error";
}


mysqli_query($db,"create table users(
id int(2) auto_increment primary key,
user_id varchar(300),
odam varchar(400),
myref varchar(400),
sana varchar(300),
balans varchar(700),
plus varchar(700),
minus varchar(700),
gamebalans varchar(700)
)");


mysqli_query($db,"create table step(
id int(2) auto_increment primary key,
userid varchar(500),
text varchar(800)
)");



mysqli_query($db,"create table ban(
id int(2) auto_increment primary key,
user_id varchar(300),
sana varchar(500)
)");

mysqli_query($db," create table uzanubis(
id int(2) auto_increment primary key,
mid varchar(500),
soni varchar(500),
start varchar(500),
vaqt varchar(500),
status varchar(500),
send varchar(500),
holat varchar(500),
creator varchar(400)
)");

$update = json_decode(file_get_contents("php://input"));
$message = $update->message;
if($update->message){
$cid = $message->chat->id;
$mid = $message->message_id;
$text = $message->text;  
$type = $update->message->chat->type;
$uid= $message->from->id;
$from_id = $update->callback_query->from->id;
$contact=$message->contact;
$nomer=$contact->phone_number;
$conid=$contact->user_id;
$namey=$message->from->first_name;
$name1 = str_replace(["[","]","(",")","*","_","`","<",">","/"],["","","","","","","","","",""],$namey);
$name="<a href='tg://user?id=$uid'>$name1</a>";
}


if($update->callback_query){
$data=$update->callback_query->data;
$mid= $update->callback_query->message->message_id;
$cid= $update->callback_query->message->chat->id;
$uid= $update->callback_query->from->id;
$qid = $update->callback_query->id;
$type=$update->callback_query->message->chat->type;
$namey= $update->callback_query->from->first_name;
$name1 = str_replace(["[","]","(",")","*","_","`","<",">","/"],["","","","","","","","","",""],$namey);
$name="<a href='tg://user?id=$uid'>$name1</a>";
}

date_default_timezone_set("asia/Tashkent");
$sana=date("d.m.Y");


if($type=="private"){
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$uid'");
    $row = mysqli_fetch_assoc($result);
if($row){
}else{
mysqli_query($db, "INSERT INTO users(user_id,balans,odam,plus,minus,gamebalans,sana) VALUES ('$uid','0','0','0','0','1','$sana')");
mysqli_query($db, "INSERT INTO step(userid,text) VALUES ('$uid','null')");
}
}


$botdel = $update->my_chat_member->new_chat_member;  
$botdelid = $update->my_chat_member->chat->id;  
$userstatus= $botdel->status;  
if($botdel){  
if($userstatus=="kicked"){  
mysqli_query($db, "DELETE FROM users WHERE user_id='$botdelid'");
}
}

if($text){
$result = mysqli_query($db,"SELECT * FROM uzanubis");
$rew = mysqli_fetch_assoc($result);
if($rew){
}else{
mysqli_query($db,"INSERT INTO uzanubis (mid,soni,start,vaqt,status,send,holat,creator) VALUES ('1000','0','00:00','00:00','passive','800','copyMessage','$admin')");
}
}




$menu=json_encode(['resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"👨‍⚖ Auksion"],['text'=>"🪪 Shaxsiy kabinet"]],
[['text'=>"👥 Referal"],['text'=>"🎮Kazino&Games"]],
[['text'=>"🎁 Bonus"],['text'=>"🏝Orolcha"]],
[['text'=>"📊 Statistika"],['text'=>"➡️ 2 - qisim"]],
]
]);


$kabinet=json_encode(['inline_keyboard'=>[
[['callback_data'=>"toldirish",'text'=>"📥 To'ldirish"],['callback_data'=>"yechish",'text'=>"📤 Yechib Olish"]],
[['callback_data'=>"promokod",'text'=>"🎟Promo aktivlash"],['callback_data'=>"invest",'text'=>"💱Qayta invest"]],
]
]);



$auksion=json_encode(['inline_keyboard'=>[
[['callback_data'=>"startauksion",'text'=>"🧑‍⚖️ Auksionni boshlash"]],
[['url'=>"https://t.me/",'text'=>"👀 Auksionni kuzatish"]],
]
]);


$games=json_encode(['inline_keyboard'=>[
[['callback_data'=>"sandiq",'text'=>"🔐 Sandiq"]],
[['callback_data'=>"ruletka",'text'=>"💈 Ruletka"]],
]
]);

$toldirish=json_encode(['inline_keyboard'=>[
[['callback_data'=>"qiwi",'text'=>"🥝Qiwi"],['callback_data'=>"karta",'text'=>"🅿️ayeer"]],
[['callback_data'=>"alfa",'text'=>"💳 Alfa bank"]],
]
]);

$yechish=json_encode(['inline_keyboard'=>[
[['callback_data'=>"yech_qiwi",'text'=>"🥝Qiwi"],['callback_data'=>"yech_karta",'text'=>"🅿️ayeer"]],
]
]);

$statics=json_encode(['inline_keyboard'=>[
[['url'=>"https://t.me/",'text'=>"👨‍⚖️ Admin"]],
[['url'=>"https://t.me/",'text'=>"🐍Bot buyurtma qilish"]],
[['url'=>"https://t.me/",'text'=>"💳 To'lovlar"],['url'=>"https://t.me/",'text'=>"💬 Chat"]],
[['url'=>"https://t.me/",'text'=>"🎩 Auksion Kanal"],['url'=>"https://t.me/",'text'=>"🤝Homiy"]],
[['callback_data'=>"yechganlar",'text'=>"📤 Yechganlar"]],
[['callback_data'=>"referallar",'text'=>"👥 Referallar"]],
]
]);

$diqqat=json_encode(['inline_keyboard'=>[
[['url'=>"https://t.me/",'text'=>"👨‍⚖️ Admin"]],
[['url'=>"https://t.me/",'text'=>"💬 Rasmiy guruhimiz"]],
]
]);


$refs=json_encode(['inline_keyboard'=>[
[['callback_data'=>"referallar",'text'=>"👥Top referallar"]],
]
]);

$back=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🔙Orqaga"],],
]
]);

$toldirback=json_encode(['inline_keyboard'=>[
[['url'=>"https://t.me/",'text'=>"👨‍⚖️ Admin Orqali To'ldirish"]],
[['callback_data'=>"toldirish",'text'=>"◀️ Orqaga"]],
]
]);

$sandiq=json_encode(['inline_keyboard'=>[
[['callback_data'=>"sandiq=1",'text'=>"1₽"],['callback_data'=>"sandiq=2",'text'=>"2₽"],['callback_data'=>"sandiq=5",'text'=>"5₽"],['callback_data'=>"sandiq=10",'text'=>"10₽"]],
[['callback_data'=>"sandiq=25",'text'=>"25₽"],['callback_data'=>"sandiq=50",'text'=>"50₽"],['callback_data'=>"sandiq=100",'text'=>"100₽"],['callback_data'=>"sandiq=250",'text'=>"250₽"]],
]
]);

$ower=json_encode(['resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🏖️Orol sotib olish"],['text'=>"🤝Savdo"]],
[['text'=>"🔙Orqaga"]],
]
]);


$oower=json_encode(['inline_keyboard'=>[
[['callback_data'=>"orolima",'text'=>"🏝 Xazina qidirish"]],
[['callback_data'=>"back",'text'=>"◀️ Orqaga"]],
]
]);

$ttttf=json_encode(['inline_keyboard'=>[
[['callback_data'=>"vip1",'text'=>"🏖️1 "],['callback_data'=>"vip3",'text'=>"🏜️3 "]],
[['callback_data'=>"vip2",'text'=>"🏝️ 2"],['callback_data'=>"vip4",'text'=>"🏔️ 4 "]],
[['callback_data'=>"back",'text'=>"◀️ Orqaga"]],
 ]
 ]);


$mttr=json_encode(['inline_keyboard'=>[
[['callback_data'=>"oolima",'text'=>"🔄Obmen"]],
[['callback_data'=>"back",'text'=>"◀️ Orqaga"]],
]
]);

$gggcv=json_encode(['inline_keyboard'=>[
[['callback_data'=>"back",'text'=>"◀️ Orqaga"]],
]
]);


function uzanubis($cid,$text,$menu){
bot('sendMessage', [
'chat_id' =>$cid,
'parse_mode'=>'HTML',
'text' =>$text,
'disable_web_page_preview'=>true,
'reply_markup'=>$menu
]);
}

function delete(){
	global $cid,$mid;
bot('deleteMessage',['chat_id'=>$cid,'message_id'=>$mid]);
}


$auksionstart=file_get_contents("admin/auksion.start");
if(!$auksionstart){
file_put_contents("admin/auksion.start","finish");
}

$yechilgan=file_get_contents("admin/yechilgan.pul");
if($yechilgan){
}else{
file_put_contents("admin/yechilgan.pul","0");
}
mkdir("admin");
mkdir("step");
mkdir("user");
mkdir("game");

function step($txt){
global $uid;
file_put_contents("step/$uid.step",$txt);
}



$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$uid'");
$row = mysqli_fetch_assoc($result);
$gamebalans=$row['gamebalans'];
$balans=$row['balans'];
$plus=$row['plus'];
$minus=$row['minus'];
$odam=$row['odam'];
$myref=$row['myref'];


$step=file_get_contents("step/$uid.step");



function joinchannel($uid){
$kanal1 = bot("getChatMember",[
"chat_id"=>"-1002152142480",
"user_id"=>$uid,
])->result->status;
$kanal2= bot("getChatMember",[
"chat_id"=>"-1002152142480",
"user_id"=>$uid,
])->result->status;
$kanal3 = bot("getChatMember",[
"chat_id"=>"-1002152142480",
"user_id"=>$uid,
])->result->status;
$gurux = bot("getChatMember",[
"chat_id"=>"-1002152142480",
"user_id"=>$uid,
])->result->status;
if(($kanal1=="creator" or $kanal1=="administrator" or $kanal1=="member") and ($kanal2=="creator" or $kanal2=="administrator" or $kanal2=="member") and ($kanal3=="creator" or $kanal3=="administrator" or $kanal3=="member") and ($gurux=="creator" or $gurux=="administrator" or $gurux=="member")){
return true;
}else{
bot("sendMessage",[
"chat_id"=>$uid,
"text"=>"<b>👋  Auksionda ishtirok etib 5000₱ yutib olish uchun quydagi kanallarga obuna bo'ling! ⤵️</b>",
"parse_mode"=>"html",
"disable_web_page_preview"=>true,
"reply_markup"=>json_encode([
"inline_keyboard"=>[
[["text"=>"💳 To'lovlar","url"=>"https://t.me/auksion_pay2024"],["text"=>"💬 Rasmiy guruh","url"=>"https://t.me/auksion_cht2024"]],
[["text"=>"🎩 Auksion Kanali","url"=>"https://t.me/auksion_rasmiy_2024"],['url'=>"https://t.me/auksion_ropfo",'text'=>"🤝 Homiy"]],
]
]),
]);
exit();
}
}

$ids=file_get_contents("users.id");
if(mb_stripos($ids,$uid)!==false){
}else{
file_put_contents("users.id",$ids."\n".$uid);
}

if($type=="private"){

if(mb_stripos($text,"/start ")!==false){
$id=explode(" ",$text)[1];
if($id==$uid){
uzanubis($cid,"👋 Assalomu alaykum, $name1
👨‍⚖️ Rasmiy Auksionga xush kelibsiz!

📰 Auksion juda oddiy ishlangan
├─Hisobni to'ldiring! 💳 
├─Auksionda qatnashing
├─G'alaba qozoning   👑 
└─Pulni yechib oling 📥 

💬 Rasmiy guruh: <a href='https://t.me/Auksionchat2024'>AuksionChat</a>
💳 To'lovlar kanali: <a href='https://t.me/+sSdDwHZEBpxhN2Ni'>AuksionPay</a>",$menu);
}else{
if(mb_stripos($ids,$uid)!==false){
uzanubis($cid,"👋 Assalomu alaykum, $name1
👨‍⚖️ Rasmiy Auksionga xush kelibsiz!

📰 Auksion juda oddiy ishlangan
├─Hisobni to'ldiring! 💳 
├─Auksionda qatnashing
├─G'alaba qozoning   👑 
└─Pulni yechib oling 📥 

💬 Rasmiy guruh: <a href='https://t.me/Auksionchat2024'>AuksionChat</a>
💳 To'lovlar kanali: <a href='https://t.me/+sSdDwHZEBpxhN2Ni'>AuksionPay</a>",$menu);
}else{
uzanubis($cid,"👋 Assalomu alaykum, $name1
👨‍⚖️ Rasmiy Auksionga xush kelibsiz!

📰 Auksion juda oddiy ishlangan
├─Hisobni to'ldiring! 💳 
├─Auksionda qatnashing
├─G'alaba qozoning   👑 
└─Pulni yechib oling 📥 

💬 Rasmiy guruh: <a href='https://t.me/Auksionchat2024'>AuksionChat</a>
💳 To'lovlar kanali: <a href='https://t.me/+sSdDwHZEBpxhN2Ni'>AuksionPay</a>",$menu);
uzanubis($id,"👥Sizda yangi referal mavjud va sizga 1₽ berildi.",$menu);
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$id'");
$row = mysqli_fetch_assoc($result);
$gamebalans=$row['gamebalans'];
$odam=$row['odam'];
$a=$odam+1;
$b=$gamebalans+1;
mysqli_query($db,"UPDATE users SET odam='$a' WHERE user_id='$id'");
mysqli_query($db,"UPDATE users SET gamebalans='$b' WHERE user_id='$id'");
file_put_contents("users.id",$ids."\n".$uid);
}
}
}



if($text=="/start" or $text=="🔙Orqaga"){
step(" ");
uzanubis($cid,"👋 Assalomu alaykum, $name1
👨‍⚖️ Rasmiy Auksionga xush kelibsiz!

📰 Auksion juda oddiy ishlangan
├─Hisobni to'ldiring! 💳 
├─Auksionda qatnashing
├─G'alaba qozoning   👑 
└─Pulni yechib oling 📥 

💬 Rasmiy guruh: <a href='https://t.me/Auksionchat2024'>AuksionChat</a>
💳 To'lovlar kanali: <a href='https://t.me/+sSdDwHZEBpxhN2Ni'>AuksionPay</a>",$menu);
exit();
}

if(joinchannel($uid)==true){

if($data=="back"){
delete();
unlink("step/$uid.step");
uzanubis($cid,"<b>👋 O'zingizga kerakli bo'limni tanlang!</b>",$menu);
exit();
}

if($text == "➡️ 2 - qisim"){
step(" ");
uzanubis($cid," 👋 Assalomu alaykum, $name1
👨‍⚖️ Rasmiy Auksionga xush kelibsiz!

📰 Auksion juda oddiy ishlangan
├─Hisobni to'ldiring! 💳 
├─Auksionda qatnashing
├─G'alaba qozoning   👑 
└─Pulni yechib oling 📥 

💬 Rasmiy guruh: <a href='https://t.me/Auksionchat2024'>AuksionChat</a>
💳 To'lovlar kanali: <a href='https://t.me/+sSdDwHZEBpxhN2Ni'>AuksionPay</a>",$ower);
exit();
}

if($text=="🏖️Orol sotib olish"){
step(" ");
uzanubis($cid,"🏖️Orol xarid qiling va kunlik daromad olishni boshlang",$ttttf);
exit();
}


 if($text == "🎁 Bonus"){
$ttime = date('d',strtotime('2 hour'));	
$bonustime = file_get_contents("bonus/$cid.txt");
$bonus = rand(1,10);
if($bonustime == $ttime){
bot('sendmessage',[
'chat_id'=>$cid,
'text'=>"_📛 Siz kunlik bonusni olib bo'lgansiz❗_️",
'parse_mode'=>'markdown',
]);
}else{
$pul = file_get_contents("step/$cid.dat");
$rr=$gamebalans+$sum;
file_put_contents("step/$cid.dat","$rr");
file_put_contents("bonus/$cid.txt","$ttime");
bot('sendmessage',[
'chat_id'=>$cid,
'text'=>"*👏 Kunlik bonus $rr ₱ taqdim etildi keyingisi ertaga mavjud bo'ladi*️",
'parse_mode'=>'markdown',
]);
}
}

if($text=="🏝Orolcha"){
step(" ");
uzanubis($cid,"🏝 Va nixoyat bizning
 orolchamizga tashrif buyurdingiz.
 Orolda siz oldindan yashirilgan xazinani 
topishingiz mumkun. Orol sizga o'z hisobingizni 
10 barobargacha ko'paytirish imkonini beradi yoki aksincha.
 Marxamad xazina qidirish tugmasini bosing!!!",$oower);
exit();
}


if($text=="🤝Savdo"){
step(" ");
uzanubis($cid,"Siz yiğgan Mtt ni ₱ ga almoash tir moqchi msiz
100 Mtt = 1 ₱  Mtt ni almash tirish uchun almashish  tugmasini bosing",$mttr);
exit();
}


if($data=="oolima"){
delete();
uzanubis($cid,"🔄Almashish soʻrovi adminga yuboril di 24 soat ichida hisobizga tushadi",$gggcv);
}

if($data=="orolima"){
delete();
$go=file_get_contents("game/$uid.ruletka");
if(!$go){
$go="0";
}
if(mb_stripos(file_get_contents("game/ruletka.limit"),$uid)!==false){
uzanubis($cid,"🚫Sizda limit tugagan. 1 kunda 5ta mumkin",$menu);
unlink("game/$uid.ruletka");
}else{
uzanubis($cid,"🏝Xazina qidirish narxi: 5₽! 
🏝Xazina qidirish o'yin balansingizdan hisoblanadi! 
🏝Siz xazina topishingiz mumkin: 0₽ dan 25₽gacha ! 
🏝Yutuq o'yin balansingizga tushadi! 
🏝Sizning o'yin balansingiz: $gamebalans ₽",json_encode(['inline_keyboard'=>[
[['callback_data'=>"orolli",'text'=>"🏝️ Oroldan xazinani qidirish"]],
]
]));
}
}

if($data=="orolli"){
delete();
$go=file_get_contents("game/$uid.ruletka");
if(!$go){
$go="0";
}
if($go>3){
if(mb_stripos(file_get_contents("game/ruletka.limit"),$uid)!==false){
}else{
file_put_contents("game/ruletka.limit",file_get_contents("game/ruletka.limit")."\n$uid");
}
}
$a=$go+1;
file_put_contents("game/$uid.ruletka",$a);
if($gamebalans>5){
$minus=$gamebalans-5;
mysqli_query($db, "UPDATE users SET gamebalans='$minus' WHERE user_id='$uid'");
$rand=rand(0,20);
if($rand=="5" or $rand=="15" or $rand=="10"){
$plus=$gamebalans+$rand;
mysqli_query($db, "UPDATE users SET gamebalans='$plus' WHERE user_id='$uid'");
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🎉 Tabriklaymiz siz oroldan  $rand ₽ topdingiz",
'show_alert'=>true,
]);
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"😔Afsuski siz oroldan  0₽ topdiz.",
'show_alert'=>true,
]);
}
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🚫Hisobingizda yetarli mablag mavjud emas!",
'show_alert'=>true,
]);
}
}




if($text=="👥 Referal"){
step(" ");
uzanubis($cid,"👥 Referal orqali pul ishlash:
   
💳 Siz hamkorlar orqali quydagilarni olasiz:   
1️⃣ 1₽ xar bir taklif qilgan do'stingiz uchun
2️⃣ 10% do'stingizni sarmoyasi uchun
====================================    
🔗 Sizning hamkorlik havolangiz:
https://t.me/$bot?start=$uid
✅ Do'stlaringiz soni: $odam",$refs);
exit();
}

if($text=="📊 Statistika"){
step(" ");
$use = mysqli_query($db, "SELECT * FROM `users`");
$users = mysqli_num_rows($use);
$to = mysqli_query($db, "SELECT * FROM `users` WHERE sana='$sana'");
$today = mysqli_num_rows($to);
$yechilgan=file_get_contents("admin/yechilgan.pul");
uzanubis($cid,"👨‍⚖️ Auksionimizning statistikasi:

👥 Botdagi o'yinchilar: $users
👥 Bugun qo'shilganlar: $today
📤 Yechib olingan pullar: $yechilgan ₽
🛎 Ishga tushgan sana: 01.05.2023",$statics);
exit();
}

if($text=="🎮Kazino&Games"){
step(" ");
uzanubis($cid,"🎲 Bu yerda o'ynab pulingizni 10 barobar yoki undanxam ko'proq summaga ko'paytiring, Xozircha kazinoda 2 turdagi o'yin mavjud!
Iltimos O'yin turini tanlanga: 👇",$games);
exit();
}

if($data=="sandiq"){
delete();
uzanubis($cid,"🔒 Sandiq narxini tanlang
🔒 Siz ikki barobar ko'p ₽ yutishingiz 
🔒 Yoki sandiq bo'sh bo'lishi mumkun
🎲 Ehtimollik: 50%",$sandiq);
}

if(mb_stripos($data,"sandiq=")!==false){
$stavka=explode("=",$data)[1];
delete();
$win=$stavka*2;
uzanubis($cid,"🔒 Sandiq narxini tanlang
🔒 Siz ikki barobar ko'p ₽ yutishingiz 
🔒 Yoki sandiq bo'sh bo'lishi mumkun
🎲 Ehtimollik: 50%
     
💳 Sizning O'yin balansingiz: $gamebalans ₽
🏹 Sizning stafkangiz: $stavka ₽
🎰 Mumkun bo'lgan yutuq: $win ₽",json_encode(['inline_keyboard'=>[
[['callback_data'=>"sandiq=1",'text'=>"1₽"],['callback_data'=>"sandiq=2",'text'=>"2₽"],['callback_data'=>"sandiq=5",'text'=>"5₽"],['callback_data'=>"sandiq=10",'text'=>"10₽"]],
[['callback_data'=>"sandiq=25",'text'=>"25₽"],['callback_data'=>"sandiq=50",'text'=>"50₽"],['callback_data'=>"sandiq=100",'text'=>"100₽"],['callback_data'=>"sandiq=250",'text'=>"250₽"]],
[['callback_data'=>"open=$stavka",'text'=>"🔓Ochish $stavka ₽"]],
]
]));
}

if(mb_stripos($data,"open=")!==false){
$stavka=explode("=",$data)[1];
if($gamebalans>$stavka){
$minus=$gamebalans-$stavka;
$win=$stavka*2;
mysqli_query($db, "UPDATE users SET gamebalans='$minus' WHERE user_id='$uid'");
delete();
$rand=rand(111,999);
if($rand=="777"){
$plus=$minus+$win;
mysqli_query($db, "UPDATE users SET gamebalans='$plus' WHERE user_id='$uid'");
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🎉Siz $win ₽ Yutdingiz",
'show_alert'=>true,
]);
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"😔Sandiq bo'sh ekan.",
'show_alert'=>true,
]);
}
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🚫Hisobingizda yetarli mablag mavjud emas!",
'show_alert'=>true,
]);
}
}

if($data=="ruletka"){
delete();
$go=file_get_contents("game/$uid.ruletka");
if(!$go){
$go="0";
}
if(mb_stripos(file_get_contents("game/ruletka.limit"),$uid)!==false){
uzanubis($cid,"🚫Sizda limit tugagan. 1 kunda 5ta   mumkin",$menu);
unlink("game/$uid.ruletka");
}else{
uzanubis($cid,"💈 Ruletka

Aylantirish narxi - 5₽. 
Yutuq o'yin balansiga tushadi
Bugun aylatirdingiz: $go/5

💳 O'yin uchun balansingiz: $gamebalans ₽

Ruletkada 6 ta yutuq bor:
0₽ | 0₽ | 0₽ | 5₽ | 10₽ | 15₽",json_encode(['inline_keyboard'=>[
[['callback_data'=>"buyruletka",'text'=>"💈Aylantirishni sotib olish 5₽"]],
]
]));
}
}

if($data=="buyruletka"){
delete();
$go=file_get_contents("game/$uid.ruletka");
if(!$go){
$go="0";
}
if($go>3){
if(mb_stripos(file_get_contents("game/ruletka.limit"),$uid)!==false){
}else{
file_put_contents("game/ruletka.limit",file_get_contents("game/ruletka.limit")."\n$uid");
}
}
$a=$go+1;
file_put_contents("game/$uid.ruletka",$a);
if($gamebalans>5){
$minus=$gamebalans-5;
mysqli_query($db, "UPDATE users SET gamebalans='$minus' WHERE user_id='$uid'");
$rand=rand(0,20);
if($rand=="5" or $rand=="15" or $rand=="10"){
$plus=$gamebalans+$rand;
mysqli_query($db, "UPDATE users SET gamebalans='$plus' WHERE user_id='$uid'");
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🎉Siz $rand ₽ Yutdingiz",
'show_alert'=>true,
]);
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"😔Afsuski sizga 0₽ tushdi.",
'show_alert'=>true,
]);
}
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🚫Hisobingizda yetarli mablag mavjud emas!",
'show_alert'=>true,
]);
}
}

if($text=="🪪 Shaxsiy kabinet"){
uzanubis($cid,"🪪 Ismingiz: $name1
🆔 ID raqam: $uid
==========================
🎲 O'yin balansingiz: $gamebalans ₽
💳 Yechish balansingiz: $balans ₽
==========================
📥 Sarmoyangiz: $plus ₽
📤 Daromadingiz: $minus ₽",$kabinet);
step(" ");
exit();
}

if($data=="promokod"){
delete();
uzanubis($cid,"<b>🎟Promo Kodni kiriting!</b>",$back);
step("promokod");
exit();
}

$promokods=file_get_contents("admin/promo.kod");
if($step=="promokod"){
if(mb_stripos($promokods,$text)!==false){
$x=explode($text,$promokods)[1];
$x=explode("\n",$x)[0];
$sum=explode("-",$x)[1];
$plus=$gamebalans+$sum;
mysqli_query($db, "UPDATE users SET gamebalans='$plus' WHERE user_id='$uid'");
uzanubis($cid,"<b>✅Promod Kod</b> Aktivlashtirildi.
💰Summa: $sum ₽
🎲O'yin balansingiz: $plus ₽",$menu);
uzanubis($tolovkanal,"🎟 <a href='tg://user?id=$uid'>Foydalanuvchi</a> <b>promokodni aktivlashtirdi va xaridlar balansi uchun $sum ₽ oldi.</b>",$no);
$a=str_replace("$text-$sum\n","",$promokods);
file_put_contents("admin/promo.kod",$a);
step("");
}else{
uzanubis($cid,"🎟<b>Promo Kod</b> Mavjud emas. Yoki avval aktivlashtirilgan.",$back);
}
}


if($data == "toldirish"){
delete();
uzanubis($cid,"💳 Sarmoya kiritish uchun quydagi tizimlardan o'zingizga qulay usulni tanlang",$toldirish);
exit(); 
}

if($data == "qiwi"){
delete();
uzanubis($cid,"To'ldirish usuli: 🥝 QIWI
  
🥝️ QIWI orqali ushbu hisobga 20 rubldan ko'proq miqdorda pul yuboring +7 999 772 7517
💬️ Sizning komentariyagiz: AUK$uid",$toldirback);
exit(); 
}

if($data == "karta"){
delete();
uzanubis($cid,"To'ldirish usuli: Payeer
  
🅿️ayeer orqali ushbu hisobga 20₱dan ko'proq miqdorda pul yuboring: 
P1107589625
💬️ Sizning komentariyagiz: AUK$uid

💱 150 so'm = 1₽",$toldirback);
exit(); 
}


 if($data == "alfa"){
delete();
uzanubis($cid,"To'ldirish usuli: Payme
orqali ushbu hisobga 20₱dan ko'proq miqdorda pul yuboring: 
+998936739195
💬️ Sizning komentariyagiz: AUK$uid

💱 150 so'm = 1₽",$toldirback);
exit(); 
}


if($data == "yechish"){
if($balans>0){
delete();
uzanubis($cid,"💳 Pulni Chiqarib olish uchun quydagi tizimlardan o'zingizga qulay usulni tanlang",$yechish);
exit(); 
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🚫Minimal pul yechish: 1 rubl!",
'show_alert'=>true,
]);
}
}


if(mb_stripos($data,"yech_")!==false){
$tur=explode("_",$data)[1];
if($balans>0){
if($tur=="qiwi"){
$t="🥝Qiwi";
}elseif($tur=="karta"){
$t="🅿️ayeer ";
}
delete();
uzanubis($cid,"<b>$t</b> raqamingizni yuboring!",$back);
step("yech|$t|$tur");
exit();
exit();
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"🚫Minimal pul yechish: 1 rubl!",
'show_alert'=>true,
]);
}
}



if(mb_stripos($step,"yech|")!==false){
$dd=explode("|",$step);
$t=$dd[1];
$tur=$dd[2];
uzanubis($cid,"<b>💸Summani kiriting!</b>",$back);
file_put_contents("user/".$uid.".".$tur."",$text);
step("yechpul|$t|$tur");
exit();
}

if(mb_stripos($step,"yechpul|")!==false){
$dd=explode("|",$step);
$t=$dd[1];
$tur=$dd[2];
if($tur=="karta"){
$sum=$text*130;
$t2="UZS($text ₽)";
}elseif($tur=="qiwi"){
$sum=$text;
$t2="₽";
}
if(is_numeric($text)){
$balanss=$balans+1;
if($balanss>$text){
uzanubis($tolovkanal,"💳<a href='tg://user?id=$uid'>Foydalanuvchi</a> <b>pul yechish uchun ariza yubordi.</b>",$no);
uzanubis($cid,"<b>✅$t orqali pul yechish uchun Zayavka yuborildi!</b>",$menu);
$minuss=$balans-$text;
$bb=$minus+$text;
mysqli_query($db, "UPDATE users SET minus='$bb' WHERE user_id='$uid'");
mysqli_query($db, "UPDATE users SET balans='$minuss' WHERE user_id='$uid'");
$raqam=file_get_contents("user/".$uid.".".$tur);
bot('sendmessage',[
'chat_id'=>$admin,
'parse_mode'=>"html",
'text'=>"<a href='tg://user?id=$uid'>$name1</a>
<b>$t orqali $sum $t2 Yechib Olmoqchi.</b>
$t raqami: $raqam",
'parse_mode'=>'html',
'reply_markup'=>json_encode(['inline_keyboard'=>[
[['callback_data'=>"tolandi=$uid=$t=$text=$tur",'text'=>"✅ To'lov Qilindi"]],
]
])
]);
step(" ");
}else{
uzanubis($cid,"🚫Hisobingizda yetarli mablag' mavjud emas.",$back);
}
}else{
uzanubis($cid,"🚫Faqat <b>Raqam</b> yuboring...",$back);
}
}

if(mb_stripos($data,"tolandi=")!==false){
bot('editMessageReplyMarkup',['chat_id'=>$cid,
'message_id'=>$mid,false]);
$dd=explode("=",$data);
$userid=$dd[1];
$summa=$dd[3];
$t=$dd[2];
$tur=$dd[4];
if($tur=="karta"){
$sum=$summa*130;
$t2="UZS($summa ₽)";
}elseif($tur=="qiwi"){
$sum=$summa;
$t2="₽";
}
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"✅To'lov Malumoti To'lovlar Kanaliga Joylandi!
$userid $t $tur $summa",
'show_alert'=>true,
]);
uzanubis($tolovkanal,"🤑 <a href='tg://user?id=$userid'>Foydalanuvchi</a>
<b>$t Raqamiga $sum $t2 Yechib oldi.</b>",$no);
$plus=$yechilgan+$summa;
file_put_contents("admin/yechilgan.pul",$plus);
}

if($data=="invest"){
delete();
uzanubis($cid,"<b>🔥 Qayta invistitsiya uchun: +10% bonus</b>

👉 Qayta investitsiya miqdorini kiriting:",$back);
step("invest");
exit();
exit();
}

if($step=="invest"){
$text=str_replace(["-","+","×","÷","*"],["","","","",""],$text);
if(is_numeric($text)){
$balanss=$balans+1;
if($balanss>$text){
$y=$text/100*10;
$u=$text+$y;
$plus=$gamebalans+$u;
$minus=$balans-$text;
mysqli_query($db,"UPDATE users SET gamebalans='$plus' WHERE user_id='$uid'");
mysqli_query($db,"UPDATE users SET balans='$minus' WHERE user_id='$uid'");
uzanubis($cid,"<b>💱Siz qayta invest qildingiz.</b>
==========================
🎲 O'yin balansingiz: $plus ₽
💳 Yechish balansingiz: $minus ₽
==========================",$menu);
step(" ");
}else{
uzanubis($cid,"🚫Hisobingizda yetarli mablag' mavjud emas.",$back);
}
}else{
uzanubis($cid,"Qayta investitsiya qilish uchun miqdorni kiriting!",$back);
}
}




if($text=="👨‍⚖ Auksion"){
step(" ");
uzanubis($cid,"👨‍⚖️ Auktsion qoidalari:
⚜️ Auksionni 1₽dan boshlashingiz mumkun
⚜️ Auktsion 2 ta garovga yetganda tugashi mumkin
⚜️ Har qanday ishtirokchi oldingi garovni oshirishi va Liderga aylanishi mumkin
⚜️ Maksimal o'sish bosqichi-10 rubl
⚜️ Garov ko'tarilgandan so'ng, auksion 10 daqiqaga uzaytiriladi
⚜️ Taymer nolga yetgandan so'ng, pul oxirgi pul tikgan kishiga o'tkaziladi
⚜️ Foydalanuvchi ketma-ket pul tika olmaydi
⚜️ Auksion tugaganda g'olib bankni yechish balansiga oladi
⚜️ Agar xechkim boshlang'ich garovni buzmasa auksion 12 soatda tugaydi va pullarini 150% qilib yechish balansiga oladi

👨‍⚖ Eng kuchlilar g'alaba qozonadi!",$auksion);
exit();
}



if($data=="startauksion"){
delete();
if($auksionstart=="start"){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❗Auksion allaqachon boshlangan!",
'show_alert'=>false,
]);
exit();
}else{
if($gamebalans>0){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"◀️Botimizga boshlang'ich garov uchun miqdorni yuboring!",
'show_alert'=>true,
]);
uzanubis($cid,"👉 Auksionni boshlash uchun boshlang'ich garovni kiriting:",$back);
step("stavka");
exit();
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❗O'yin balansida dastlabki garov uchun mablag' yetarli emas!",
'show_alert'=>false,
]);
}
}
}


if($step=="stavka"){
if(is_numeric($text) and $text>0){
$tex=$text+1;
$txt=$text+10;
for($o=$tex;$o<=$txt;$o++){
$keyboards[]=["text"=>"$o ₽","callback_data"=>"stavka|$o"];
$key=array_chunk($keyboards, 5);
$key[]=[['callback_data'=>"balansim",'text'=>"💳 Mening balansim"]];
$key[]=[['url'=>"https://t.me/$bot",'text'=>"◀️ Botga Kirish"]];
$stavki=json_encode(['inline_keyboard'=>$key]);
}
uzanubis($auksionkanal,"✅ <a href='tg://user?id=$uid'>$name1</a> auksionni $text ₽ bilan boshladi!",$no);
$aumid=bot('sendMessage',[
'chat_id'=>$auksionkanal,
'text'=>"👨🏻‍⚖️ Auksion

⚜️ Holati: Boshlangan
⏱ Qolgan vaqt: 12:00:00
💰 Auksion banki: $text rubl
🔨 Garovlar soni: 1

👑 Lider: <a href='tg://user?id=$uid'>$name1</a> Tikdi $text rubl!

👇 Garovni oshirish uchun miqdorini tanlang:",
'parse_mode'=>'HTML',
'reply_markup'=>$stavki
])->result->message_id;
$time=date("H:i",strtotime("+30 minutes"));
$minus=$gamebalans-$text;
mysqli_query($db,"UPDATE users SET gamebalans='$minus' WHERE user_id='$uid'");
uzanubis($cid,"✅ Siz Auksionni $text ₽ bilan boshlab berdingiz!",$menu);
file_put_contents("admin/last.id","$uid");
file_put_contents("admin/garov.lar","1");
file_put_contents("admin/bank.txt",$text);
file_put_contents("admin/last.stavka","$text");
file_put_contents("admin/auksion.start","start");
file_put_contents("admin/auksion.mid","$aumid");
file_put_contents("admin/auksion.time","$time");
file_put_contents("admin/hour.txt","12");
file_put_contents("admin/hour.txt2","10");
step(" ");
exit();
}else{
uzanubis($cid,"Raqamni kiriting:
Minimal 1₽",$back);
exit();
}
}

}
}

$soat=date("H:i");
$garovv=file_get_contents("admin/garov.lar");
$atime=file_get_contents("admin/auksion.time");
$ho=file_get_contents("admin/hour.txt");
if($soat==$atime and $garovv=="1" and $ho!="0"){
if(!$ho){
$ho=12;
}
$t=$ho-1;
file_put_contents("admin/hour.txt",$t);
$stavka=file_get_contents("admin/last.stavka");
$last=file_get_contents("admin/last.id");
$bank=file_get_contents("admin/bank.txt");
$tex=$stavka+1;
$txt=$stavka+10;
for($o=$tex;$o<=$txt;$o++){
$keyboards[]=["text"=>"$o ₽","callback_data"=>"stavka|$o"];
$key=array_chunk($keyboards, 5);
$key[]=[['callback_data'=>"balansim",'text'=>"💳 Mening balansim"]];
$key[]=[['url'=>"https://t.me/$bot",'text'=>"◀️ Botga Kirish"]];
$stavki=json_encode(['inline_keyboard'=>$key]);
}
$yname = bot ('getChatMember', [
'chat_id'=> $last,
'user_id'=> $last
])->result->user->first_name;
bot('editMessageText',[
'chat_id'=>$auksionkanal,
'message_id'=>file_get_contents("admin/auksion.mid"),
'text'=>"👨🏻‍⚖️ Auksion

⚜️ Holati: Boshlangan
⏱ Qolgan vaqt: $t:00:00
💰 Auksion banki: $bank rubl
🔨 Garovlar soni: 1

👑 Lider: <a href='tg://user?id=$last'>$yname</a> Tikdi $stavka rubl!

👇 Garovni oshirish uchun miqdorini tanlang:",
'parse_mode'=>'HTML',
'reply_markup'=>$stavki
]);
$time=date("H:i",strtotime("+60 minutes"));
file_put_contents("admin/auksion.time","$time");
$timme=date("H:i",strtotime("+720 minutes"));
file_put_contents("admin/auksion.end2","$timme");
}



$garovv=file_get_contents("admin/garov.lar");
$atime=file_get_contents("admin/auksion.time");
$hoo=file_get_contents("admin/hour.txt2");
if($soat==$atime and $hoo!="0"){

if(!$hoo){
$hoo=10;
}
$t=$hoo-1;
file_put_contents("admin/hour.txt2",$t);
$stavka=file_get_contents("admin/last.stavka");
$last=file_get_contents("admin/last.id");
$bank=file_get_contents("admin/bank.txt");
$tex=$stavka+1;
$txt=$stavka+10;
for($o=$tex;$o<=$txt;$o++){
$keyboards[]=["text"=>"$o ₽","callback_data"=>"stavka|$o"];
$key=array_chunk($keyboards, 5);
$key[]=[['callback_data'=>"balansim",'text'=>"💳 Mening balansim"]];
$key[]=[['url'=>"https://t.me/$bot",'text'=>"◀️ Botga Kirish"]];
$stavki=json_encode(['inline_keyboard'=>$key]);
}
$yname = bot ('getChatMember', [
'chat_id'=> $last,
'user_id'=> $last
])->result->user->first_name;
bot('editMessageText',[
'chat_id'=>$auksionkanal,
'message_id'=>file_get_contents("admin/auksion.mid"),
'text'=>"👨🏻‍⚖️ Auksion

⚜️ Holati: Boshlangan
⏱ Qolgan vaqt: $t:00
💰 Auksion banki: $bank rubl
🔨 Garovlar soni: $garovv

👑 Lider: <a href='tg://user?id=$last'>$yname</a> Tikdi $stavka rubl!

👇 Garovni oshirish uchun miqdorini tanlang:",
'parse_mode'=>'HTML',
'reply_markup'=>$stavki
]);
$time=date("H:i",strtotime("+1 minutes"));
file_put_contents("admin/auksion.time","$time");
}




if(joinchannel($uid)==true){
if(mb_stripos($data,"stavka|")!==false){
$stavka=explode("|",$data)[1];
$last=file_get_contents("admin/last.id");
if($last==$uid){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❗Siz ketma-ket 2 ta garov tikolmaysiz!",
'show_alert'=>false,
]);
}else{
$gamebalanss=$gamebalans+1;
if($gamebalanss>$stavka){
$bankk=file_get_contents("admin/bank.txt");
$laststavka=file_get_contents("admin/last.stavka");
$garovv=file_get_contents("admin/garov.lar");
$garov=$garovv+1;
file_put_contents("admin/garov.lar","$garov");
file_put_contents("admin/last.stavka","$stavka");
$time=date("H:i",strtotime("+1 minutes"));
file_put_contents("admin/auksion.time","$time");
$timme=date("H:i",strtotime("+10 minutes"));
file_put_contents("admin/auksion.end","$timme");

file_put_contents("admin/last.id","$uid");
file_put_contents("admin/hour.txt2","10");
$tex=$stavka+1;
$txt=$stavka+10;
for($o=$tex;$o<=$txt;$o++){
$keyboards[]=["text"=>"$o ₽","callback_data"=>"stavka|$o"];
$key=array_chunk($keyboards, 5);
$key[]=[['callback_data'=>"balansim",'text'=>"💳 Mening balansim"]];
$key[]=[['url'=>"https://t.me/$bot",'text'=>"◀️ Botga Kirish"]];
$stavki=json_encode(['inline_keyboard'=>$key]);
}
$bank=$bankk+$stavka;
file_put_contents("admin/bank.txt",$bank);
bot('editMessageText',[
'chat_id'=>$cid,
'message_id'=>$mid,
'text'=>"👨🏻‍⚖️ Auksion

⚜️ Holati: Boshlangan
⏱ Qolgan vaqt: 10:00
💰 Auksion banki: $bank rubl
🔨 Garovlar soni: $garov

👑 Lider: <a href='tg://user?id=$uid'>$name1</a> Tikdi $stavka rubl!

👇 Garovni oshirish uchun miqdorini tanlang:",
'parse_mode'=>'HTML',
'reply_markup'=>$stavki
]);
$minnus=$gamebalans-$stavka;
mysqli_query($db,"UPDATE users SET gamebalans='$minnus' WHERE user_id='$uid'");

uzanubis($auksionkanal,"<a href='tg://user?id=$uid'>$name1</a> Garovni $stavka rublga oshirdi!",$no);
exit();
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❗O'yin balansida dastlabki garov uchun mablag' yetarli emas!",
'show_alert'=>false,
]);
}
}
}
}


$end1=file_get_contents("admin/auksion.end2");
$ho2=file_get_contents("admin/hour.txt");
if($soat==$end1 or $ho2=="0"){
if($auksionstart=="start"){
$last=file_get_contents("admin/last.id");
$bankk=file_get_contents("admin/bank.txt");
$laststavka=file_get_contents("admin/last.stavka");
$winn=$bankk/100*150;
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$last'");
$row = mysqli_fetch_assoc($result);
$gamebalans=$row['balans'];
$win=$gamebalans+$winn;
mysqli_query($db,"UPDATE users SET balans='$win' WHERE user_id='$last'");
$namei= bot ('getChatMember', [
'chat_id'=> $last,
'user_id'=> $last
])->result->user->first_name;
uzanubis($auksionkanal,"🧑‍⚖️Auksion Tugadi!

👑Lider: <a href='tg://user?id=$last'>$namei</a> tikdi <b>$laststavka</b> rubl!
💰Auksion Banki: <b>$bankk</b> ₽!
💳G'olib auksion bankining 90%ni oldi - <b>$winn</b>₽",$no);
uzanubis($last,"📢Hurmatli <a href='tg://user?id=$last'>$namei</a> siz <b>🧑‍⚖️Auksionda</b> g'olib bo'ldingiz!
💰Auksion Banki: <b>$bankk</b> ₽!
💳Siz auksion bankining 90%ni oldingiz - <b>$winn</b>₽
🎲O'yin balansingiz: <b>$win</b>₽",$menu);
bot('deleteMessage',[
'chat_id'=>$auksionkanal,
'message_id'=>file_get_contents("admin/auksion.mid")
]);
unlink("admin/last.id");
unlink("admin/garov.lar");
unlink("admin/bank.txt");
unlink("admin/last.stavka");
unlink("admin/auksion.start");
unlink("admin/auksion.mid");
unlink("admin/auksion.time");
unlink("admin/hour.txt");
unlink("admin/hour.txt2");
unlink("admin/auksion.end2");
exit();
}
}

$end=file_get_contents("admin/auksion.end");
$ho=file_get_contents("admin/hour.txt2");
if($soat==$end or $ho=="0"){
if($auksionstart=="start"){
$last=file_get_contents("admin/last.id");
$bankk=file_get_contents("admin/bank.txt");
$laststavka=file_get_contents("admin/last.stavka");
$winn=$bankk/100*90;
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$last'");
$row = mysqli_fetch_assoc($result);
$gamebalans=$row['balans'];
$win=$gamebalans+$winn;
mysqli_query($db,"UPDATE users SET balans='$win' WHERE user_id='$last'");
$namei= bot ('getChatMember', [
'chat_id'=> $last,
'user_id'=> $last
])->result->user->first_name;
uzanubis($auksionkanal,"🧑‍⚖️Auksion Tugadi!

👑Lider: <a href='tg://user?id=$last'>$namei</a> tikdi <b>$laststavka</b> rubl!
💰Auksion Banki: <b>$bankk</b> ₽!
💳G'olib auksion bankining 90%ni oldi - <b>$winn</b>₽",$no);
uzanubis($last,"📢Hurmatli <a href='tg://user?id=$last'>$namei</a> siz <b>🧑‍⚖️Auksionda</b> g'olib bo'ldingiz!
💰Auksion Banki: <b>$bankk</b> ₽!
💳Siz auksion bankining 90%ni oldingiz - <b>$winn</b>₽
🎲O'yin balansingiz: <b>$win</b>₽",$menu);
bot('deleteMessage',[
'chat_id'=>$auksionkanal,
'message_id'=>file_get_contents("admin/auksion.mid")
]);
unlink("admin/last.id");
unlink("admin/garov.lar");
unlink("admin/bank.txt");
unlink("admin/last.stavka");
unlink("admin/auksion.start");
unlink("admin/auksion.mid");
unlink("admin/auksion.time");
unlink("admin/hour.txt");
unlink("admin/hour.txt2");
unlink("admin/auksion.end");
exit();
}
}


if($data=="balansim"){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"💳 Sizning o'yin balansingiz: $gamebalans ₽",
'show_alert'=>false,
]);
}


if(joinchannel($uid)==true){
if($data == "referallar"){
$top = mysqli_query($db,"SELECT * FROM `users` ORDER BY odam DESC  LIMIT 20");
$i =1;
$text = "👥 Eng ko'p referallar:\n\n";
while($row = mysqli_fetch_array($top)){
$userid = $row['user_id'];
$soni = $row["odam"];
$nomi = bot ('getChatMember', [
'chat_id'=> $userid,
'user_id'=> $userid
])->result->user->first_name;
$nomi = str_replace(["[","]","(",")","*","_","`"],["","","","","","",""],$nomi);
if(strlen($nomi)<31){
$namee=$nomi;
}else{
$namee=$userid;
}
if($soni>0){
$text.="<b>$i)</b> <a href='tg://user?id=$userid'>$namee</a> - <b>$soni</b> referal\n";
}
$i++;
}
delete();
if(mb_stripos($text,"1)")!==false){
uzanubis($cid,$text,$menu);
exit(); 
}else{
uzanubis($cid,"👥Referallar mavjud emas!",$menu);
exit(); 
}
}

if($data == "yechganlar"){
$top = mysqli_query($db,"SELECT * FROM `users` ORDER BY minus DESC  LIMIT 20");
$i =1;
$text = "💳Ko'p pul yechib olganlar:\n\n";
while($row = mysqli_fetch_array($top)){
$userid = $row['user_id'];
$yech = $row["minus"];
$nomi = bot ('getChatMember', [
'chat_id'=> $userid,
'user_id'=> $userid
])->result->user->first_name;
$nomi = str_replace(["[","]","(",")","*","_","`"],["","","","","","",""],$nomi);
if(strlen($nomi)<31){
$namee=$nomi;
}else{
$namee=$userid;
}
if($yech>0){
$text.="<b>$i)</b> <a href='tg://user?id=$userid'>$namee</a> - <b>$yech</b>₽\n";
}
$i++;
}
delete();
if(mb_stripos($text,"1)")!==false){
uzanubis($cid,$text,$menu);
exit(); 
}else{
uzanubis($cid,"💳Botdan pul yechganlar mavjud emas!",$menu);
exit(); 
}
}

}
/////ADMIN PANEL/////
$panel=json_encode(['inline_keyboard'=>[
[['callback_data'=>"pulplus",'text'=>"💰Pul Berish➕"],['callback_data'=>"pulminus",'text'=>"💰Pul Ayirish➖"]],
[['callback_data'=>"stat",'text'=>"📊Statistika"],['callback_data'=>"send",'text'=>"↗️Xabar Yuborish"]],
[['callback_data'=>"exit",'text'=>"🚪Yopish"]],
]
]);






if($text=="/panel" and $uid==$admin){
uzanubis($admin,"🛠️Administrator Paneli",$panel);
step("");
exit();
}

if($data=="panel"){
delete();
uzanubis($admin,"🛠️Administrator Paneli",$panel);
step(" ");
exit();
}

if($data=="exit"){
delete();
exit();
}

if($data=="stat"){
$use = mysqli_query($db, "SELECT * FROM `users`");
$users = mysqli_num_rows($use);
$leftt = mysqli_query($db, "SELECT * FROM `ban`");
$lefted= mysqli_num_rows($leftt);
$bann=file_get_contents("banned.ids");
$bans=substr_count($bann,"\n");
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"📊Statistika
👤Aktivlar: $users
🚪Chiqib ketganlar: $lefted
🚫Banlanganlar: $bans",
'show_alert'=>true]);
}


if($data=="pulplus"){
delete();
uzanubis($cid,"❗<b>Foydalanuvchi</b> 🆔️+PUL miqdorini yubroing.",$backp);
step("pulber");
}

if($step=="pulber"){
$id=explode("+",$text)[0];
$pul=explode("+",$text)[1];
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$id'");
$row = mysqli_fetch_assoc($result);
$ba=$row['gamebalans'];
$win=$ba+$pul;
mysqli_query($db,"UPDATE users SET gamebalans='$win' WHERE user_id='$id'");
uzanubis($cid,"<b><a href='tg://user?id=$id'>👤Foydalanuvchi</a> Hisobi $pul ₽ga to'ldirildi!</b>",$backp); 
uzanubis($tolovkanal,"<b><a href='tg://user?id=$id'>👤Foydalanuvchi</a> Hisobini $pul ₽ga to'ldirildi!</b>",$backp); 

}

if($data=="pulminus"){
delete();
uzanubis($cid,"❗<b>Foydalanuvchi</b> 🆔️-PUL miqdorini yubroing.",$backp);
step("pulol");
}

if($step=="pulol"){
$id=explode("-",$text)[0];
$pul=explode("-",$text)[1];
$result = mysqli_query($db,"SELECT * FROM users WHERE user_id = '$id'");
$row = mysqli_fetch_assoc($result);
$ba=$row['gamebalans'];
$win=$ba-$pul;
mysqli_query($db,"UPDATE users SET gamebalans='$win' WHERE user_id='$id'");
uzanubis($cid,"<b><a href='tg://user?id=$id'>👤Foydalanuvchi</a> Hisobidan $pul ₽ Olib tashlandi!</b>",$backp); 
}





if($data=="send" and $uid==$admin){
delete();
$result=mysqli_query($db, "SELECT * FROM uzanubis"); 
$row= mysqli_fetch_assoc($result);
if($row['status']=="passive"){
bot('sendMessage', [
'chat_id' => $uid,
'parse_mode'=>'HTML',
'text' =>"❗<b>Xabar</b> Turini tanlang.",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[["text"=>"📝Xabar","callback_data"=>"sms_post"]],
[["text"=>"🗂Forward","callback_data"=>"sms_forward"]],
[["text"=>"🔙Orqaga","callback_data"=>"panel"]]
]
]),
]); 
exit();
}else{
uzanubis($cid,"❌<b>Xabar Yuborilmoqda. Tugashini kuting!</b>",$backp);
exit();
}
}



if(mb_stripos($data,"sms_")!==false){
delete();
$tur=explode("_",$data)[1];
$result=mysqli_query($db, "SELECT * FROM uzanubis"); 
$row= mysqli_fetch_assoc($result);
if($row['status']=="passive"){
uzanubis($cid,"❗<b>Xabarni</b> Yuboring.",$backp);
step("send|$tur");
}else{
uzanubis($cid,"❌<b>Xabar Yuborilmoqda. Tugashini kuting!</b>",$backp);
exit();
}
}

if(mb_stripos($step,"send|")!==false){
$tur=explode("|",$step)[1];
if($tur=="post"){
$uz="CopyMessage";
}elseif($tur=="forward"){
$uz="ForwardMessage";
}
$vt=date('H:i', strtotime("1 minutes"));
$soat=date('H:i');
uzanubis($cid,"✅<b>Xabar yuborishga tayyor.
⏳Start: $vt da</b>",$backp); 
bot('editMessageReplyMarkup',['chat_id'=>$cid,
'message_id'=>$mid-1,false]);
$result=mysqli_query($db, "SELECT * FROM `uzanubis`"); 
$bor=mysqli_num_rows($result);
if($bor>0){
mysqli_query($db, "UPDATE `uzanubis` SET `mid`='$mid', `start`='$soat'");  
mysqli_query($db, "UPDATE `uzanubis` SET `soni`='0', `vaqt`='$vt', `status`='active', `send`='0', `holat`='$uz', `creator`='$uid'");  
}else{
mysqli_query($db, "INSERT INTO `uzanubis` (`mid`,`start`,`soni`,`vaqt`,`status`,`send`,`holat`,`creator`) VALUES('$mid', '$vt', 0, '$soat', 'active', 0, '$uz','$uid')");
}
$keyb=$update->message->reply_markup;
if(isset($keyb)){
file_put_contents("key.txt",file_get_contents('php://input'));
} 
unlink("step/$cid.step");
exit();
}


?>