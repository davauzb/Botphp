<?php
ob_start();
date_Default_timezone_set('Asia/Tashkent');
define("API_KEY",'6583579506:AAHBEOVW9cnJhF3IQ_0OZCIMovA_gtChWUU');
$admin = "1999997369";
$user = file_get_contents("admin/user.txt");
$bot = bot('getme',['bot'])->result->username;
$soat = date('H:i');
$sana = date("d.m.Y");

function getAdmin($chat){
$url = "https://api.telegram.org/bot".API_KEY."/getChatAdministrators?chat_id=@".$chat;
$result = file_get_contents($url);
$result = json_decode ($result);
return $result->ok;
}

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

function joinchat($id){
global $mid;
$array = array("inline_keyboard");
$kanallar=file_get_contents("admin/kanal.txt");
if($kanallar == null){
return true;
}else{
$ex = explode("\n",$kanallar);
for($i=0;$i<=count($ex) -1;$i++){
$first_line = $ex[$i];
$first_ex = explode("@",$first_line);
$url = $first_ex[1];
$ism=bot('getChat',['chat_id'=>"@".$url,])->result->title;
$ret = bot("getChatMember",[
"chat_id"=>"@$url",
"user_id"=>$id,
]);
$stat = $ret->result->status;
if((($stat=="creator" or $stat=="administrator" or $stat=="member"))){
$array['inline_keyboard']["$i"][0]['text'] = "✅ ". $ism;
$array['inline_keyboard']["$i"][0]['url'] = "https://t.me/$url";
}else{
$array['inline_keyboard']["$i"][0]['text'] = "❌ ". $ism;
$array['inline_keyboard']["$i"][0]['url'] = "https://t.me/$url";
$uns = true;
}
}
$array['inline_keyboard']["$i"][0]['text'] = "🔄 Tekshirish";
$array['inline_keyboard']["$i"][0]['callback_data'] = "check";
if($uns == true){
bot('sendMessage',[
'chat_id'=>$id,
'text'=>"<b>⚠️ Botdan to'liq foydalanish uchun quyidagi kanallarimizga obuna bo'ling!</b>",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
'reply_markup'=>json_encode($array),
]);
return false;
}else{
return true;
}}}

function number($cid){
$raqam = file_get_contents("number/$cid.txt");
if($raqam == true){
return true;
}else{
file_put_contents("step/$cid.step",'request_contact');
$getPhone = str_replace("getPhone","📱 Telefon raqamni yuborish",file_get_contents("tugma/getPhone.txt"));
$textPhone = str_replace(["textPhone","%first%","%last%","%id%","%hour%","%date%"],["📲 <b>Botdan ro'yxatdan o'tish uchun quyidagi tugma orqali telefon raqamingizni yuboring:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/textPhone.txt"));
bot("sendMessage",[
"chat_id"=>$cid,
'text'=>"$textPhone",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$getPhone","request_contact"=>true]],
]]),
]);
return false;
}}

$update = json_decode(file_get_contents('php://input'));
$message = $update->message;
$tx = $message->text;
$cid = $message->chat->id;
$mid = $message->message_id;
$text = $message->text;
$chat_id = $message->chat->id;
$message_id = $message->message_id;
$from_id = $message->from->id;
$name = $message->from->first_name;
$last = $message->from->last_name;
$username = $message->from->username;
$bio = $message->from->about;

$contact = $message->contact;
$contact_id = $contact->user_id;
$contact_user = $contact->username;
$contact_name = $contact->first_name;
$phone = $contact->phone_number;

$data = $update->callback_query->data;
$qid = $update->callback_query->id;
$cid2 = $update->callback_query->message->chat->id;
$mid2 = $update->callback_query->message->message_id;
$callback = $update->callback_query;
$callfrid = $update->callback_query->from->id;
$callname = $update->callback_query->from->first_name;
$calluser = $update->callback_query->from->username;
$surname = $update->callback_query->from->last_name;
$about = $update->callback_query->from->about;

$step = file_get_contents("step/$cid.step");
$pul = file_get_contents("pul/$cid.txt");
$solv = file_get_contents("pul/$cid.dat");
$odam = file_get_contents("odam/$cid.txt");
$ban = file_get_contents("ban/$cid.txt");
$baza = file_get_contents("azo.dat");
$saved = file_get_contents("step/pulbot.txt");
$tugma = file_get_contents("step/tugma.txt");
$matn = file_get_contents("step/matn.txt");

$turi = file_get_contents("number/turi.txt");
$raqam = file_get_contents("number/$cid.txt");

$valyuta = file_get_contents("admin/valyuta.txt");
$taklif = file_get_contents("admin/taklif.txt");
$narx = file_get_contents("admin/narx.txt");
$kanal = file_get_contents("admin/kanal.txt");
$vazifa = file_get_contents("admin/vazifa.txt");

//tugma
$earn = str_replace("earn","💵 Pul ishlash",file_get_contents("tugma/earn.txt"));
$solve = str_replace("solve","🏦 Pulni yechish",file_get_contents("tugma/solve.txt"));
$cabinet = str_replace("cabinet","💰 Hisobim",file_get_contents("tugma/cabinet.txt"));
$tolov = str_replace("tolov","📢 To'lovlar kanali",file_get_contents("tugma/tolov.txt"));
$support = str_replace("support","📨 Murojaat",file_get_contents("tugma/support.txt"));
$manual = str_replace("manual","📚 Qo'llanma",file_get_contents("tugma/manual.txt"));
$orqa = str_replace("back","◀️ Orqaga",file_get_contents("tugma/back.txt"));
$getPhone = str_replace("getPhone","📱 Telefon raqamni yuborish",file_get_contents("tugma/getPhone.txt"));
$check = str_replace("check","🔄 Tekshirish",file_get_contents("tugma/check.txt"));
$contiune = str_replace("contiune","✅ Davom etish",file_get_contents("tugma/contiune.txt"));
$share = str_replace("share","↗️ Ulashish",file_get_contents("tugma/share.txt"));
$cancellation = str_replace("cancellation","🚫 Bekor qilish",file_get_contents("tugma/cancellation.txt"));
$confirm = str_replace("confirm","✅ Tasdiqlash",file_get_contents("tugma/confirm.txt"));
$transition = str_replace("transition","🤖 Botga o'tish",file_get_contents("tugma/transition.txt"));

//matn
$manuals = str_replace(["manuals","%first%","%last%","%id%","%username%","%botname%","%user%","%balance%","%refcount%","%currency%","%solve%","%hour%","%date%"], ["<b>Qo'shilmadi!</b>",$name,$last,$cid,$username,$bot,$user,$pul,$odam,$valyuta,$solv,$soat,$sana],file_get_contents("matn/manuals.txt"));
$reklama = str_replace(["advertising","%first%","%last%","%id%","%username%","%botname%","%user%","%balance%","%refcount%","%currency%","%solve%","%hour%","%date%"], ["🤖 <b>Rasmiy botimiz:</b> @$bot",$name,$last,$cid,$username,$bot,$user,$pul,$odam,$valyuta,$solv,$soat,$sana],file_get_contents("matn/advertising.txt"));
$welcome = str_replace(["welcome","%first%","%last%","%id%","%hour%","%date%"],["🖥 <b>Asosiy menyudasiz.</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/welcome.txt"));
$subChannels = str_replace(["subChannels","%first%","%last%","%id%","%hour%","%date%"],["⚠️ <b>Botdan foydalanish uchun, quyidagi kanallarga obuna bo'ling:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/subChannels.txt"));
$tolovtext = str_replace(["tolovtext","%first%","%last%","%id%","%hour%","%date%"],["<b>⤵️ Quyidagi kanal orqali to'livlarni kuzatib boring:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/tolovtext.txt"));
$newRef = str_replace(["newRef","%reffirst%","%reflast%","%refpay%","%refid%","%currency%"],["📳 <b>Sizda yangi</b> <a href='tg://user?id=%refid%'>taklif</a> <b>mavjud!</b>",$name,$last,$taklif,$cid,$valyuta],file_get_contents("matn/newRef.txt"));
$checkRef = str_replace(["checkRef","%reffirst%","%reflast%","%refpay%","%refid%","%currency%"],["✅ <b>Hisobingizga %refpay% %currency% qo'shildi!</b>",$name,$last,$taklif,$cid,$valyuta],file_get_contents("matn/checkRef.txt"));
$backHome = str_replace(["backHome","%first%","%last%","%id%","%hour%","%date%"],["🖥 <b>Asosiy menyuga qaytdingiz.</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/backHome.txt"));
$textPhone = str_replace(["textPhone","%first%","%last%","%id%","%hour%","%date%"],["📲 <b>Botdan ro'yxatdan o'tish uchun quyidagi tugma orqali telefon raqamingizni yuboring:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/textPhone.txt"));
$conPhone = str_replace(["conPhone","%first%","%last%","%id%","%hour%","%date%","%phone%"],["<b>✅ Telefon raqamingiz qabul qilindi:</b> %phone%\n\n<i>Botdan foydalanish boshlash uchun quyidagi tugmani bosing:</i>",$name,$last,$cid,$soat,$sana,$phone],file_get_contents("matn/conPhone.txt"));
$noPhone = str_replace(["noPhone","%first%","%last%","%id%","%hour%","%date%"],["<b>Kechirasiz, Botdan faqat O'zbekiston fuqarolari foydalanishi mumkin.</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/noPhone.txt"));
$earnRef = str_replace(["earnRef","%first%","%last%","%id%","%hour%","%date%","%reflink%","%refpay%","%refcount%","%balance%","%currency%"],["🔗 <b>Sizning taklif havolangiz:</b>\n\n%reflink%\n\n<i>Yuqoridagi taklif havolangizni do'stlaringizga tarqating va har bir to'liq ro'yxatdan o'tgan taklifingiz uchun %refpay% %currency% hisobingizga qo'shiladi.</i>",$name,$last,$cid,$soat,$sana,"https://t.me/$bot?start=$cid",$taklif,$odam,$pul,$valyuta],file_get_contents("matn/earnRef.txt"));
$kabinet = str_replace(["cabinet","%first%","%last%","%id%","%hour%","%date%","%balance%","%solve%","%refcount%","%currency%"],["🔑 <b>Sizning ID raqamingiz:</b> <pre>%id%</pre>\n\n💵 <b>Asosiy balansingiz:</b> %balance% %currency%\n👤 <b>Takliflaringiz soni:</b> %refcount% ta\n\n💳 <b>Yechib olgan pullaringiz:</b> %solve% %currency%",$name,$last,$cid,$soat,$sana,$pul,$solv,$odam,$valyuta],file_get_contents("matn/cabinet.txt"));
$selectPayType = str_replace(["selectPayType","%first%","%last%","%id%","%hour%","%date%"],["👇 <b>Quyidagi to'lov tizimlaridan birini tanlang:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/selectPayType.txt"));
$minimum = str_replace(["minimum","%first%","%last%","%id%","%hour%","%date%","%balance%","%minimum%","%currency%"],["⛔ Jarayonni davom ettira olmaysiz!\n\nMinimal yechib olish miqdori: %minimum% %currency%",$name,$last,$cid,$soat,$sana,$pul,$narx,$valyuta],file_get_contents("matn/minimum.txt"));
$noChannel = str_replace(["noChannel","%first%","%last%","%id%","%hour%","%date%"],["To'lovlar kanali ulanmagan!",$name,$last,$cid,$soat,$sana],file_get_contents("matn/noChannel.txt"));
$sendCard = str_replace(["sendCard","%first%","%last%","%id%","%hour%","%date%"],["<b>Hamyoningiz raqamini yuboring:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/sendCard.txt"));
$accpeted = str_replace(["accpeted","%first%","%last%","%id%","%hour%","%date%","%phone%","%wallet%","%amount%"],["✅ <b>Qabul qilindi!</b>\n\n• <b>To'lov turi:</b> %wallet%\n• <b>Pul miqdori:</b> %amount%\n• <b>Hamyon raqamingiz:</b> %phone%\n\n<b>Ma'lumotlar to'g'ri ekanligiga ishonch hosil qilgan bo'lsangiz, ✅ Tasdiqlash tugmasini bosing!</b>",$name,$last,$cid,$soat,$sana,$num,$wallet,$text],file_get_contents("matn/accpeted.txt"));
$solveMoney = str_replace(["solveMoney","%first%","%last%","%id%","%hour%","%date%"],["<b>Qancha miqdorda pul yechib olmoqchisiz:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/solveMoney.txt"));
$solveMinimum = str_replace(["solveMinimum","%first%","%last%","%id%","%hour%","%date%","%balance%","%minimum%","%currency%"],["<b>Minimal yechib olish miqdori:</b> %minimum% %currency%\n\nQayta urunib ko'ring:",$name,$last,$cid,$soat,$sana,$pul,$narx,$valyuta],file_get_contents("matn/solveMinimum.txt"));
$lowBalance = str_replace(["lowBalance","%first%","%last%","%id%","%hour%","%date%"],["<b>Hisobingizda yetarli mablag' mavjud emas!</b>\n\nQayta urunib ko'ring:",$name,$last,$cid,$soat,$sana],file_get_contents("matn/lowBalance.txt"));
$accped = str_replace(["accped","%first%","%last%","%id%","%hour%","%date%"],["✅ <b>Qabul qilindi.</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/accped.txt"));
$canceled = str_replace(["canceled","%first%","%last%","%id%","%hour%","%date%"],["⛔ <b>Bekor qilindi.</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/canceled.txt"));
$hasBeenPaid = str_replace(["hasBeenPaid","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilindi.</b>",$name,$cid,$soat,$sana],file_get_contents("matn/hasBeenPaid.txt"));
$wasNotPaid = str_replace(["wasNotPaid","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilinmadi.</b>",$name,$cid,$soat,$sana],file_get_contents("matn/wasNotPaid.txt"));
$block = str_replace(["block","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilinmadi va botdan blocklandingiz.</b>",$name,$cid,$soat,$sana],file_get_contents("matn/block.txt"));
$BeenPaid = str_replace(["BeenPaid","%first%","%id%","%hour%","%date%","%phone%","%amount%","%advertising%"],["✅ <a href='tg://user?id=%id%'>%first%</a> <b>foydalanuvchi puli to'lab berildi.</b>\n\n• <b>Pul miqdori:</b> %amount%\n• <b>Hamyon raqami:</b> %phone%\n\n%advertising%",$ism,$id,$soat,$sana,$number,$miqdor,$reklama],file_get_contents("matn/BeenPaid.txt"));
$sendSuppMsg = str_replace(["sendSuppMsg","%first%","%last%","%id%","%hour%","%date%"],[" 📝 <b>Murojaat matnini yuboring:</b>",$name,$last,$cid,$soat,$sana],file_get_contents("matn/sendSuppMsg.txt"));
$SuppSend = str_replace(["SuppSend","%first%","%last%","%id%","%hour%","%date%"],["✅ <b>Murojaatingiz yuborildi.</b>\n\nTez orada javob qaytaramiz!",$name,$last,$cid,$soat,$sana],file_get_contents("matn/SuppSend.txt"));

//tugma
if(file_get_contents("tugma/earn.txt")){
}else{
if(file_put_contents("tugma/earn.txt",'earn'));
}
if(file_get_contents("tugma/solve.txt")){
}else{
if(file_put_contents("tugma/solve.txt",'solve'));
}
if(file_get_contents("tugma/cabinet.txt")){
}else{
if(file_put_contents("tugma/cabinet.txt",'cabinet'));
}
if(file_get_contents("tugma/tolov.txt")){
}else{
if(file_put_contents("tugma/tolov.txt",'tolov'));
}
if(file_get_contents("tugma/support.txt")){
}else{
if(file_put_contents("tugma/support.txt",'support'));
}
if(file_get_contents("tugma/manual.txt")){
}else{
if(file_put_contents("tugma/manual.txt",'manual'));
}
if(file_get_contents("tugma/back.txt")){
}else{
if(file_put_contents("tugma/back.txt",'back'));
}
if(file_get_contents("tugma/getPhone.txt")){
}else{
if(file_put_contents("tugma/getPhone.txt",'getPhone'));
}
if(file_get_contents("tugma/check.txt")){
}else{
if(file_put_contents("tugma/check.txt",'check'));
}
if(file_get_contents("tugma/contiune.txt")){
}else{
if(file_put_contents("tugma/contiune.txt",'contiune'));
}
if(file_get_contents("tugma/share.txt")){
}else{
if(file_put_contents("tugma/share.txt",'share'));
}
if(file_get_contents("tugma/cancellation.txt")){
}else{
if(file_put_contents("tugma/cancellation.txt",'cancellation'));
}
if(file_get_contents("tugma/confirm.txt")){
}else{
if(file_put_contents("tugma/confirm.txt",'confirm'));
}
if(file_get_contents("tugma/transition.txt")){
}else{
if(file_put_contents("tugma/transition.txt",'transition'));
}

//matn
if(file_get_contents("matn/welcome.txt")){
}else{
if(file_put_contents("matn/welcome.txt",'welcome'));
}
if(file_get_contents("matn/subChannels.txt")){
}else{
if(file_put_contents("matn/subChannels.txt",'subChannels'));
}
if(file_get_contents("matn/tolovtext.txt")){
}else{
if(file_put_contents("matn/tolovtext.txt",'tolovtext'));
}
if(file_get_contents("matn/newRef.txt")){
}else{
if(file_put_contents("matn/newRef.txt",'newRef'));
}
if(file_get_contents("matn/checkRef.txt")){
}else{
if(file_put_contents("matn/checkRef.txt",'checkRef'));
}
if(file_get_contents("matn/backHome.txt")){
}else{
if(file_put_contents("matn/backHome.txt",'backHome'));
}
if(file_get_contents("matn/textPhone.txt")){
}else{
if(file_put_contents("matn/textPhone.txt",'textPhone'));
}
if(file_get_contents("matn/conPhone.txt")){
}else{
if(file_put_contents("matn/conPhone.txt",'conPhone'));
}
if(file_get_contents("matn/noPhone.txt")){
}else{
if(file_put_contents("matn/noPhone.txt",'noPhone'));
}
if(file_get_contents("matn/earnRef.txt")){
}else{
if(file_put_contents("matn/earnRef.txt",'earnRef'));
}
if(file_get_contents("matn/cabinet.txt")){
}else{
if(file_put_contents("matn/cabinet.txt",'cabinet'));
}
if(file_get_contents("matn/selectPayType.txt")){
}else{
if(file_put_contents("matn/selectPayType.txt",'selectPayType'));
}
if(file_get_contents("matn/minimum.txt")){
}else{
if(file_put_contents("matn/minimum.txt",'minimum'));
}
if(file_get_contents("matn/noChannel.txt")){
}else{
if(file_put_contents("matn/noChannel.txt",'noChannel'));
}
if(file_get_contents("matn/sendCard.txt")){
}else{
if(file_put_contents("matn/sendCard.txt",'sendCard'));
}
if(file_get_contents("matn/accpeted.txt")){
}else{
if(file_put_contents("matn/accpeted.txt",'accpeted'));
}
if(file_get_contents("matn/solveMoney.txt")){
}else{
if(file_put_contents("matn/solveMoney.txt",'solveMoney'));
}
if(file_get_contents("matn/solveMinimum.txt")){
}else{
if(file_put_contents("matn/solveMinimum.txt",'solveMinimum'));
}
if(file_get_contents("matn/lowBalance.txt")){
}else{
if(file_put_contents("matn/lowBalance.txt",'lowBalance'));
}
if(file_get_contents("matn/accped.txt")){
}else{
if(file_put_contents("matn/accped.txt",'accped'));
}
if(file_get_contents("matn/canceled.txt")){
}else{
if(file_put_contents("matn/canceled.txt",'canceled'));
}
if(file_get_contents("matn/hasBeenPaid.txt")){
}else{
if(file_put_contents("matn/hasBeenPaid.txt",'hasBeenPaid'));
}
if(file_get_contents("matn/wasNotPaid.txt")){
}else{
if(file_put_contents("matn/wasNotPaid.txt",'wasNotPaid'));
}
if(file_get_contents("matn/block.txt")){
	}else{
		if(file_put_contents("matn/block.txt",'block'));
}
if(file_get_contents("matn/BeenPaid.txt")){
}else{
if(file_put_contents("matn/BeenPaid.txt",'BeenPaid'));
}
if(file_get_contents("matn/manuals.txt")){
}else{
if(file_put_contents("matn/manuals.txt",'manuals'));
}
if(file_get_contents("matn/advertising.txt")){
}else{
if(file_put_contents("matn/advertising.txt",'advertising'));
}
if(file_get_contents("matn/sendSuppMsg.txt")){
}else{
if(file_put_contents("matn/sendSuppMsg.txt",'sendSuppMsg'));
}
if(file_get_contents("matn/SuppSend.txt")){
}else{
if(file_put_contents("matn/SuppSend.txt",'SuppSend'));
}

//panel
if(file_get_contents("admin/taklif.txt")){
}else{
if(file_put_contents("admin/taklif.txt","250"));
}
if(file_get_contents("admin/valyuta.txt")){
}else{
if(file_put_contents("admin/valyuta.txt","so'm"));
}
if(file_get_contents("admin/narx.txt")){
}else{
if(file_put_contents("admin/narx.txt","3000"));
}
if(file_get_contents("admin/vazifa.txt")){
}else{
if(file_put_contents("admin/vazifa.txt","Kiritilmagan"));
}
if(file_get_contents("admin/user.txt")){
}else{
if(file_put_contents("admin/user.txt","Kiritilmagan"));
}

mkdir("step");
mkdir("ban");
mkdir("pul");
mkdir("odam");
mkdir("admin");
mkdir("tugma");
mkdir("matn");
mkdir("number");

$panel = json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"⚙ Asosiy sozlamalar"]],
[['text'=>"📢 Kanallar"],['text'=>"📊 Statistika"]],
[['text'=>"🔎 Foydalanuvchini boshqarish"]],
[['text'=>"🎛 Tugmalar"],['text'=>"📃 Matnlar"]],
[['text'=>"💳 To'lov tizimi"]],
[['text'=>"📨 Xabarnoma"],['text'=>"$orqa"]]
]]);

$menu = json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$earn"]],
[['text'=>"$cabinet"],['text'=>"$solve"]],
[['text'=>"$tolov"]],
[['text'=>"$support"],['text'=>"$manual"]],
]]);

$menus = json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$earn"]],
[['text'=>"$cabinet"],['text'=>"$solve"]],
[['text'=>"$tolov"]],
[['text'=>"$support"],['text'=>"$manual"]],
[['text'=>"🗄 Boshqarish"]]
]]);

$back = json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$orqa"]],
]]);

$boshqarish = json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🗄 Boshqarish"]],
]]);

if($text){
if($ban == "ban"){
if($cid == $admin){
}else{
}}else{}}

if($text){
$ban = file_get_contents("ban/$cid2.txt");
if($ban == "ban"){
if($cid2 == $admin){
}else{
}}else{}}

if($step=="request_contact"){
$phone = str_replace("+","","$phone");
if((strlen($phone)==12) and (mb_stripos($phone,"998")!==false)){
if($contact_id == $cid){
file_put_contents("number/$cid.txt","$phone");
bot("sendMessage",[
"chat_id"=>$cid,
"text"=>"$conPhone",
"parse_mode"=>"html",
	'reply_markup'=>json_encode([
	'inline_keyboard'=>[
[['text'=>"$contiune",'callback_data'=>"davom"]]
]])
]);
unlink("step/$cid.step");
}else{
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"$textPhone",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$getPhone","request_contact"=>true]],
]]),
]);
}}else{
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"$noPhone",
'parse_mode'=>'html',
'reply_to_message_id'=>$mid,
'reply_markup'=>json_encode([
'remove_keyboard'=>true,
]),
]);
file_put_contents("ban/$cid.txt",'ban');
unlink("step/$cid.step");
}}

if($data == "davom"){
$welcome = str_replace(["welcome","%first%","%last%","%id%","%hour%","%date%"],["🖥 <b>Asosiy menyudasiz.</b>",$callname,$surname,$cid2,$soat,$sana],file_get_contents("matn/welcome.txt"));
if($cid2 != $admin){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}else{
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
}}

if(isset($message)){
$pul = file_get_contents("pul/$cid.txt");
$mm = $pul + 0;
file_put_contents("pul/$cid.txt","$mm");
$solv = file_get_contents("pul/$cid.dat");
$m = $solv + 0;
file_put_contents("pul/$cid.dat","$m");
$odam = file_get_contents("odam/$cid.txt");
$kkd = $odam + 0;
file_put_contents("odam/$cid.txt","$kkd");
}

if(isset($callback)){
$get = file_get_contents("azo.dat");
if(mb_stripos($get,$callfrid)==false){
file_put_contents("azo.dat", "$get\n$callfrid");
bot('sendMessage',[
'chat_id'=>$admin,
'text'=>"<b>👤 Yangi obunachi botga qo'shildi!</b>",
'parse_mode'=>"html"
]);
}}

if(isset($message)){
$get = file_get_contents("azo.dat");
if(mb_stripos($get,$from_id)==false){
file_put_contents("azo.dat", "$get\n$from_id");
bot('sendMessage',[
'chat_id'=>$admin,
'text'=>"<b>👤 Yangi obunachi botga qo'shildi!</b>",
'parse_mode'=>"html"
]);
}}

if($text=="/start" and number($cid)=="true"){
if($cid != $admin){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
}}

if(mb_stripos($text,"/start")!==false){
$refid = explode(" ",$text);
$refid = $refid[1];
if(strlen($refid)>0 and $refid>0){
if($refid == $cid){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}else{
if(mb_stripos($baza,"$cid")!==false){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
}else{
bot('SendMessage',[
'chat_id'=>$refid,
'text'=>"$newRef",
'parse_mode'=>'html',
]);
file_put_contents("step/$cid.id","$refid");
file_put_contents("step/$cid.cid","$refid");
$joinkey = joinchat($cid);
if($joinkey != null){
$pulim = file_get_contents("pul/$refid.txt");
$a = $pulim + $taklif;
file_put_contents("pul/$refid.txt","$a");
$odam = file_get_contents("odam/$refid.txt");
$b = $odam + 1;
file_put_contents("odam/$refid.txt","$b");
bot('SendMessage',[
'chat_id'=>$refid,
'text'=>"$checkRef",
'parse_mode'=>'html',
]);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
unlink("step/$cid.id");
unlink("step/$cid.cid");
}}}}}

if($data == "check"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
if(joinchat($cid2)==true){
$refid = file_get_contents("step/$cid2.id");
$cid3 = file_get_contents("step/$cid2.cid");
if($refid !=$cid3){
if($cid2 != $admin){
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
}}else{
$pulim = file_get_contents("pul/$cid3.txt");
$a = $pulim + $taklif;
$odam = file_get_contents("odam/$cid3.txt");
$b = $odam + 1;
file_put_contents("pul/$cid3.txt","$a");
file_put_contents("odam/$cid3.txt","$b");
if($ccid != $admin){
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"$welcome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
}
bot('SendMessage',[
'chat_id'=>$cid3,
'text'=>"$checkRef",
'parse_mode'=>'html',
]);
unlink("step/$cid2.id");
unlink("step/$cid2.cid");
}}}

if($text == "$orqa"){
if($cid != $admin){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$backHome",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"$backHome",
'parse_mode'=>'html',
'reply_markup'=>$menus,
]);
unlink("step/$cid.step");
}}

if($text == "$earn" and number($cid)=="true"){
if(joinchat($cid)==true){
bot('SendPhoto',[
'chat_id'=>$cid,
'photo'=>"https://t.me/botim1chi/443",
'caption'=>"$earnRef",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$share",'url'=>"https://t.me/share/url?url=https://t.me/$bot?start=$cid"]]
]])
]);
}}

if($text == "$cabinet" and number($cid)=="true"){
if(joinchat($cid)==true){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$kabinet",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$solve",'callback_data'=>"yechish"]]
]])
]);
}}

$turi = file_get_contents("number/turi.txt");
$more = explode("\n",$turi);
$soni = substr_count($turi,"\n");
$keys=[];
for ($for = 1; $for <= $soni; $for++) {
$title=str_replace("\n","",$more[$for]);
$keys[]=["text"=>"$title","callback_data"=>"pay-$title"];
$keysboard2 = array_chunk($keys, 1);
$pay = json_encode([
'inline_keyboard'=>$keysboard2,
]);
}

if($data == "yechish"){
if($turi != null){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$selectPayType",
'parse_mode'=>'html',
'reply_markup'=>$pay
]);
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"To'lov tizimlari topilmadi!",
'show_alert'=>true,
]);
}}

if($text=="$solve" and number($cid)=="true"){
if(joinchat($cid)==true){
if($turi != null){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$selectPayType",
'parse_mode'=>'html',
'reply_markup'=>$pay
]);
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>To'lov tizimlari topilmadi!</b>",
'parse_mode'=>'html',
]);
}}}


if(mb_stripos($data, "pay-")!==false){
$ex = explode("-",$data);
$wallet = $ex[1];
$pul = file_get_contents("pul/$cid2.txt");
if($vazifa != "Kiritilmagan"){
if($pul >= $narx){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$sendCard",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$orqa"]],
]])
]);
file_put_contents("step/$cid2.step","wallet-$wallet");
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"$minimum",
'show_alert'=>true,
]);
}}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"$noChannel",
'show_alert'=>true,
]);
}}

if(mb_stripos($step, "wallet-")!==false){
$ex = explode("-",$step);
$wallet = $ex[1];
if($tx=="$orqa"){
unlink("step/$cid.step");
}else{
if(is_numeric($text)){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$solveMoney",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"$pul"]],
[['text'=>"$orqa"]],
]])
]);
file_put_contents("step/$cid.step","miqdor-$wallet-$text");
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$sendCard",
'parse_mode'=>'html',
]);
}}}

if(mb_stripos($step, "miqdor-")!==false){
$ex = explode("-",$step);
$wallet = $ex[1];
$num = $ex[2];
$accpeted = str_replace(["accpeted","%first%","%last%","%id%","%hour%","%date%","%phone%","%wallet%","%amount%"],["✅ <b>Qabul qilindi!</b>\n\n• <b>To'lov turi:</b> %wallet%\n• <b>Pul miqdori:</b> %amount%\n• <b>Hamyon raqamingiz:</b> %phone%\n\n<b>Ma'lumotlar to'g'ri ekanligiga ishonch hosil qilgan bo'lsangiz, ✅ Tasdiqlash tugmasini bosing!</b>",$name,$last,$cid,$soat,$sana,$num,$wallet,$text],file_get_contents("matn/accpeted.txt"));
if($tx=="$orqa"){
unlink("step/$cid.step");
}else{
if($text >= $narx){
if($pul >= $text){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$accpeted",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$confirm",'callback_data'=>"tasdiq-$wallet-$num-$text"]],
[['text'=>"$cancellation",'callback_data'=>"bekor"]]
]])
]);
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$lowBalance",
'parse_mode'=>'html',
]);
}}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$solveMinimum",
'parse_mode'=>'html',
]);
}}}

if($data == "bekor"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$canceled",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
}

if(mb_stripos($data, "tasdiq-")!==false){
$ex = explode("-",$data);
$wallet = $ex[1];
$number = $ex[2];
$miqdor = $ex[3];
$pul = file_get_contents("pul/$cid2.txt");
$m = $pul - $miqdor;
file_put_contents("pul/$cid2.txt",$m);
$solving = file_get_contents("pul/$cid2.dat");
$mm = $solving + $miqdor;
file_put_contents("pul/$cid2.dat",$mm);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"$accped",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"💵 <a href='https://t.me/$calluser'>$cid2</a> <b>pul yechib olmoqchi!</b>

• <b>To'lov turi:</b> $wallet
• <b>Pul miqdori:</b> $miqdor
• <b>Hamyon raqami:</b> $number
		
Foydalanuvchi pulini to'lab bermoqchi bo'lsangiz ✅ <b>To'landi</b> tugmasini bosing!",
'disable_web_page_preview'=>true,
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"🔔 Banlash",'callback_data'=>"block-$cid2-$callname"]],
[['text'=>"✅ To'landi",'callback_data'=>"tolandi-$cid2-$callname-$number-$miqdor"],['text'=>"❌ To'lanmadi",'callback_data'=>"tolanmadi-$cid2-$callname-$miqdor"]],
]])
]);
}


if(mb_stripos($data,"tolandi-")!==false){
$ex = explode("-",$data);
$id = $ex[1];
$ism = $ex[2];
$number = $ex[3];
$miqdor = $ex[4];
$BeenPaid = str_replace(["BeenPaid","%first%","%id%","%hour%","%date%","%phone%","%amount%","%advertising%"],["✅ <a href='tg://user?id=%id%'>%first%</a> <b>foydalanuvchi puli to'lab berildi.</b>\n\n• <b>Pul miqdori:</b> %amount%\n• <b>Hamyon raqami:</b> %phone%\n\n%advertising%",$ism,$id,$soat,$sana,$number,$miqdor,$reklama],file_get_contents("matn/BeenPaid.txt"));
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<a href='tg://user?id=$id'>$ism</a> <b>pullarini yechib olish haqidagi arizasi qabul qilindi.</b>",
'parse_mode'=>'html',
]);
$msg = bot('SendMessage',[
'chat_id'=>$vazifa,
'text'=>"$BeenPaid",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$transition",'url'=>"https://t.me/$bot"]],
]])
])->result->message_id;
$hasBeenPaid = str_replace(["hasBeenPaid","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilindi.</b>",$ism,$id,$soat,$sana],file_get_contents("matn/hasBeenPaid.txt"));
$kanal = str_replace("@","",$vazifa);
bot('SendMessage',[
'chat_id'=>$id,
'text'=>"$hasBeenPaid",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
	'inline_keyboard'=>[
[['text'=>"📢 To'lovlar kanali",'url'=>"https://t.me/$kanal/$msg"]],
]])
]);
}

if($text=="$tolov" and number($cid)=="true"){
if(joinchat($cid)==true){
if($vazifa=="Kiritilmagan"){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>To'lovlar kanali kiritilmagan!</b>",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
}else{
$kanal = str_replace("@","",$vazifa);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$tolovtext",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$tolov",'url'=>"https://t.me/$kanal"]]
]])
]);
}}}

if(mb_stripos($data,"tolanmadi-")!==false){
$ex = explode("-",$data);
$id = $ex[1];
$ism = $ex[2];
$miqdor = $ex[3];
$pul = file_get_contents("pul/$id.txt");
$m = $pul + $miqdor;
file_put_contents("pul/$id.txt",$m);
$solving = file_get_contents("pul/$id.dat");
$mm = $solving - $miqdor;
file_put_contents("pul/$id.dat",$mm);
$wasNotPaid = str_replace(["wasNotPaid","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilinmadi.</b>",$ism,$id,$soat,$sana],file_get_contents("matn/wasNotPaid.txt"));
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<a href='tg://user?id=$id'>$ism</a> <b>pullarini yechib olish haqidagi arizasi qabul qilinmadi.</b>",
'parse_mode'=>'html',
]);
bot('SendMessage',[
'chat_id'=>$id,
'text'=>"$wasNotPaid",
'parse_mode'=>'html',
]);
}

if(mb_stripos($data,"block-")!==false){
$ex = explode("-",$data);
$id = $ex[1];
$ism = $ex[2];
$block = str_replace(["block","%first%","%id%","%hour%","%date%"],["<b>Hurmatli %first%!\n\nPullaringizni yechib olish haqidagi arizangiz qabul qilinmadi va botdan blocklandingiz.</b>",$ism,$id,$soat,$sana],file_get_contents("matn/block.txt"));
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"⏱ <b>Yuklanmoqda...</b>",
'parse_mode'=>'html',
]);
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<a href='tg://user?id=$id'>$ism</a> <b>pullarini yechib olish haqidagi arizasi qabul qilinmadi va botdan blocklandi.</b>",
'parse_mode'=>'html',
]);
bot('SendMessage',[
'chat_id'=>$id,
'text'=>"$block",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'remove_keyboard'=>true,
])
]);
file_put_contents("ban/$id.txt",'ban');
}

if($text=="$manual" and number($cid)=="true"){
if(joinchat($cid)==true){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$manuals",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
}}

if($text == "$support" and number($cid)=="true"){
if(joinchat($cid)==true){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$sendSuppMsg",
'parse_mode'=>'html',
'reply_markup'=>$back,
]);
file_put_contents("step/$cid.step",'yordam');
}}

if($step == "yordam"){
if($tx=="$orqa"){
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<a href='https://t.me/$username'>$cid</a> <b>dan yangi xabar:</b> $text",
'disable_web_page_preview'=>true,
'parse_mode'=>'html',
]);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"$SuppSend",
'parse_mode'=>'html',
'reply_markup'=>$menu,
]);
unlink("step/$cid.step");
}}

if($text=="/panel" and $cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Boshqaruv panelidasiz.</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/alijonov.txt");
unlink("step/$cid.step");
unlink("step/tugma.txt");
unlink("step/matn.txt");
}

if($text=="🗄 Boshqarish" and $cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Boshqaruv panelidasiz.</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/alijonov.txt");
unlink("step/$cid.step");
unlink("step/tugma.txt");
unlink("step/matn.txt");
}

if($data == "yopish"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
}

if($text == "🔎 Foydalanuvchini boshqarish"){
if($cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Kerakli foydalanuvchining ID raqamini kiriting:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish
]);
file_put_contents("step/$cid.step",'iD');
}}

if($step == "iD"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if(file_exists("pul/$text.txt")){
file_put_contents("step/pulbot.txt","$text");
$pul = file_get_contents("pul/$text.txt");
$odam = file_get_contents("odam/$text.txt");
$ban = file_get_contents("ban/$text.txt");
if($ban == null){
$bans = "🔔 Banlash";
}
if($ban == "ban"){
$bans = "🔕 Bandan olish";
}
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Foydalanuvchi topildi!

ID:</b> <a href='tg://user?id=$text'>$text</a>
<b>Balans: $pul $valyuta
Takliflar: $odam ta</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$bans",'callback_data'=>"ban"]],
[['text'=>"➕ Pul qo'shish",'callback_data'=>"plus"],['text'=>"➖ Pul ayirish",'callback_data'=>"minus"]]
]])
]);
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Foydalanuvchi topilmadi.</b>

Qayta urinib ko'ring:",
'parse_mode'=>'html',
]);
}}}

if($data == "foydalanuvchi"){
$pul = file_get_contents("pul/$saved.txt");
$odam = file_get_contents("odam/$saved.txt");
$ban = file_get_contents("ban/$saved.txt");
if($ban == null){
$bans = "🔔 Banlash";
}
if($ban == "ban"){
$bans = "🔕 Bandan olish";
}
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Foydalanuvchi topildi!

ID:</b> <a href='tg://user?id=$saved'>$saved</a>
<b>Balans: $pul $valyuta
Takliflar: $odam ta</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$bans",'callback_data'=>"ban"]],
[['text'=>"➕ Pul qo'shish",'callback_data'=>"plus"],['text'=>"➖ Pul ayirish",'callback_data'=>"minus"]]
]])
]);
}

if($data == "plus"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<a href='tg://user?id=$saved'>$saved</a> <b>ning hisobiga qancha pul qo'shmoqchisiz?</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"foydalanuvchi"]]
]])
]);
file_put_contents("step/$cid2.step",'plus');
}

if($step == "plus"){
if($data=="Foydalanuvchi"){
unlink("step/$ccid.step");
}else{
if($cid == $admin){
if(is_numeric($text)=="true"){
bot('sendMessage',[
'chat_id'=>$saved,
'text'=>"<b>Adminlar tomonidan hisobingiz $text $valyuta to'ldirildi</b>",
'parse_mode'=>"html",
'reply_markup'=>$menu,
]);
bot('sendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Foydalanuvchi hisobiga $text $valyuta qo'shildi!</b>",
'parse_mode'=>"html",
'reply_markup'=>$panel,
]);
$pul = file_get_contents("pul/$saved.txt");
$miqdor = $pul + $text;
file_put_contents("pul/$saved.txt",$miqdor);
unlink("step/pulbot.txt");
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
]);
}}}}

if($data=="minus"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<a href='tg://user?id=$saved'>$saved</a> <b>ning hisobiga qancha pul ayirmoqchisiz?</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"foydalanuvchi"]]
]])
]);
file_put_contents("step/$cid2.step",'minus');
}

if($step == "minus"){
if($data=="foydalanuvchi"){
unlink("step/$ccid.step");
}else{
if($cid == $admin){
if(is_numeric($text)=="true"){
bot('sendMessage',[
'chat_id'=>$saved,
'text'=>"<b>Adminlar tomonidan hisobingizdan $text $valyuta olib tashlandi</b>",
'parse_mode'=>"html",
'reply_markup'=>$menu,
]);
bot('sendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Foydalanuvchi hisobidan $text $valyuta olib tashlandi!</b>",
'parse_mode'=>"html",
'reply_markup'=>$panel,
]);
$pul = file_get_contents("pul/$saved.txt");
$miqdor = $pul - $text;
file_put_contents("pul/$saved.txt",$miqdor);
unlink("step/pulbot.txt");
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
]);
}}}}

if($data=="ban"){
$ban = file_get_contents("ban/$saved.txt");
if($admin != $saved){
if($ban == "ban"){
unlink("ban/$saved.txt");
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Foydalanuvchi ($saved) bandan olindi!</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"foydalanuvchi"]]
]])
]);
}else{
file_put_contents("ban/$saved.txt",'ban');
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Foydalanuvchi ($saved) banlandi!</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"foydalanuvchi"]]
]])
]);
}}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"Asosiy adminlarni blocklash mumkin emas!",
'show_alert'=>true,
]);
}}

if($text == "📢 Kanallar"){
if($cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"🔐 Majburiy obunalar",'callback_data'=>"majburiy"]],
[['text'=>"*⃣ Qo'shimcha kanallar",'callback_data'=>"qoshimcha"]],
[['text'=>"Yopish",'callback_data'=>"yopish"]]
]])
]);
}}

if($data == "kanallar"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"🔐 Majburiy obunalar",'callback_data'=>"majburiy"]],
[['text'=>"*⃣ Qo'shimcha kanallar",'callback_data'=>"qoshimcha"]],
[['text'=>"Yopish",'callback_data'=>"yopish"]]
]])
]);
}

if($data == "majburiy"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2 + 1,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Majburiy obunalarni sozlash bo'limidasiz:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"➕ Qo'shish",'callback_data'=>"qoshish"]],
[['text'=>"📑 Ro'yxat",'callback_data'=>"royxat"],['text'=>"🗑 O'chirish",'callback_data'=>"ochirish"]],
[['text'=>"◀️ Orqaga",'callback_data'=>"kanallar"]]
]])
]);
}

if($data == "qoshish"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Kanalingiz userini kiriting:

Namuna:</b> @ORGBuilder",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","qo'shish");
}

if($step == "qo'shish"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
if(mb_stripos($text, "@")!==false){
if($kanal == null){
file_put_contents("admin/kanal.txt",$text);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>$text - kanal qo'shildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);
unlink("step/$cid.step");
}else{
file_put_contents("admin/kanal.txt","$kanal\n$text");
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>$text - kanal qo'shildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);
unlink("step/$cid.step");
}}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Kanalingiz useri yuboring:

Namuna:</b> @ORGBuilder",
'parse_mode'=>'html',
]);
}}}}}

if($data == "ochirish"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Kanallar o'chirildi</b>",
'parse_mode'=>'html',
]);
unlink("admin/kanal.txt");
}

if($data == "royxat"){
$soni = substr_count($kanal,"@");
if($kanal == null){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2 + 1,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"📂 <b>Kanallar ro'yxati bo'sh!</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"majburiy"]],
]])
]);
}else{
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2 + 1,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>📢 Kanallar ro'yxati:</b>

$kanal

<b>Ulangan kanallar soni:</b> $soni ta",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"majburiy"]],
]])
]);
}}

if($data == "qoshimcha"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2 + 1,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Quyidagilardan birini tanlang:

Hozirgi holat:
To'lovlar uchun kanal:</b> $vazifa",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"🆕️ To'lovlar uchun",'callback_data'=>"vazifa"]],
[['text'=>"◀️ Orqaga",'callback_data'=>"kanallar"]]
]])
]);
}

if($data == "vazifa"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Kanalingiz userini kiriting:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","vazifa");
}

if($step == "vazifa" and $cid == $admin){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if(mb_stripos($text,"@")!==false){
$get = bot('getChat',[
'chat_id'=>$text
]);
$types = $get->result->type;
$ch_name = $get->result->title;
$ch_user = $get->result->username;
if(getAdmin($ch_user)== true){
file_put_contents("admin/vazifa.txt","@$ch_user");
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Muvaffaqiyatli o'zgartirildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);
unlink("step/$cid.step");
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Bot ushbu kanalda admin emas yoki noto'g'ri kanal manzili yuborildi!</b>",
'parse_mode'=>'html',
]);
}}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"⚠️ <b>Kanal manzili kiritishda xatolik!</b>

Qayta urinib ko'ring:",
'parse_mode'=>'html',
]);
}}}

$delturi=file_get_contents("number/turi.txt");
$delmore = explode("\n",$delturi);
$delsoni = substr_count($delturi,"\n");
$key=[];
for ($delfor = 1; $delfor <= $delsoni; $delfor++) {
$title=str_replace("\n","",$delmore[$delfor]);
$key[]=["text"=>"$title - ni o'chirish","callback_data"=>"del-$title"];
$keyboard2 = array_chunk($key, 1);
$keyboard2[] = [['text'=>"➕ To'lov tizimi qo'shish",'callback_data'=>"new"]];
$pay = json_encode([
'inline_keyboard'=>$keyboard2,
]);
}

if($text == "💳 To'lov tizimi" and $cid==$admin){
if($turi == null){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"➕ To'lov tizimi qo'shish",'callback_data'=>"new"]],
]])
]);
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$pay
]);
}}

if($data == "hamyon"){
if($turi == null){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"➕ To'lov tizimi qo'shish",'callback_data'=>"new"]],
]])
]);
}else{
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$pay
]);
}}

if(mb_stripos($data,"del-")!==false){
$ex = explode("-",$data);
$tur = $ex[1];
$k = str_replace("\n".$tur."","",$turi);
file_put_contents("number/turi.txt",$k);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2 + 1,
'text'=>"<b>Kuting...</b>",
'parse_mode'=>'html',
]);
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"$tur - <b>To'lov tizimi olib tashlandi.</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"hamyon"]],
]])
]);
}

if($data == "new"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Yangi to'lov tizimi nomini yuboring:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish
]);
file_put_contents("step/$cid2.step",'turi');
}

if($step == "turi"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
file_put_contents("number/turi.txt","$turi\n$text");
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Yangi to'lov tizimi qo'shildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
}}}}

if($text == "📨 Xabarnoma" and $cid==$admin){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Yuboriladigan xabar turini tanlang;</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Oddiy xabar",'callback_data'=>"send"],['text'=>"Forward xabar",'callback_data'=>"forsend"]],
[['text'=>"Foydalanuvchiga xabar",'callback_data'=>"user"]],
]])
]);
}

if($data == "user"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Foydalanuvchi iD raqamini kiriting:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step",'user');
}

if($step == "user"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(is_numeric($text)=="true"){
file_put_contents("step/pulbot.txt",$text);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Xabaringizni kiriting:</b>",
'parse_mode'=>'html',
]);
file_put_contents("step/$cid.step",'xabar');
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
]);
}}}}

if($step == "xabar"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
bot('SendMessage',[
'chat_id'=>$saved,
'text'=>"$text",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Xabaringiz yuborildi ✅</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
unlink("step/pulbot.txt");
}}}

if($data == "send"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"*Xabaringizni kiriting:*",
'parse_mode'=>"markdown",
'reply_markup'=>$boshqarish
]);
file_put_contents("step/$cid2.step","users");
}
if($step=="users"){
if($cid == $admin){
$lich = file_get_contents("azo.dat");
$lichka = explode("\n",$lich);
foreach($lichka as $lichkalar){
$okuser=bot("sendmessage",[
'chat_id'=>$lichkalar,
'text'=>$text,
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
}}}
if($okuser){
bot("sendmessage",[
'chat_id'=>$admin,
'text'=>"<b>Hammaga yuborildi ✅</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);
unlink("step/$cid.step");
}

if($data == "forsend"){
$lich=substr_count($user_id,"\n");
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>$lich ta foydalanuvchiga yuboriladigan forward xabarni yuboring:</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🗄 Boshqarish"]],
]])
]);
file_put_contents("step/$cid2.step","forward");
}
if($step=="forward"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if(in_array($cid,$admin)){
$lich=substr_count($user_id,"\n");
bot('sendmessage',[
'chat_id'=>$cid,
'text'=>"<b>$lich ta foydalanuvchiga xabar yuborish boshlandi!</b>",
'parse_mode'=>"html",
'reply_markup'=>$panel,
]);
$lichka = explode("\n",$user_id);
foreach($lichka as $lichkalar){
$fors=bot("forwardMessage",[
'from_chat_id'=>$cid,
'chat_id'=>$lichkalar,
'message_id'=>$mid,
]);
unlink("step/$cid.step");
}}}}
if($fors){
$lich=substr_count($user_id,"\n");
bot("sendmessage",[
'chat_id'=>$cid,
'text'=>"<b>$lich ta foydalanuvchiga muvaffaqiyatli yuborildi</b>",
'parse_mode'=>'html',
]);
unlink("step/$cid.step");
}

if($text == "📊 Statistika" and $cid==$admin){
$baza = file_get_contents("azo.dat");
$obsh = substr_count($baza,"\n");
$load = sys_getloadavg();
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>💡 O'rtacha yuklanish:</b> <code>$load[0]</code>

👥 <b>Foydalanuvchilar: $obsh ta</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Yopish",'callback_data'=>"yopish"]]
]])
]);
}

if($text == "⚙ Asosiy sozlamalar"){
if($cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Quyidagilardan birini tanlamg:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Hozirgi holat",'callback_data'=>"holat"]],
[['text'=>"🔗 Taklif narxi",'callback_data'=>"taklif"],['text'=>"💶 Valyuta",'callback_data'=>"valyuta"]],
[['text'=>"💵 Minimal pul yechish narxi",'callback_data'=>"narx"]],
[['text'=>"📎 Admin useri",'callback_data'=>"admin"],['text'=>"Yopish",'callback_data'=>"yopish"]]
]])
]);
}}

if($data == "asosiy"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Quyidagilardan birini tanlamg:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Hozirgi holat",'callback_data'=>"holat"]],
[['text'=>"🔗 Taklif narxi",'callback_data'=>"taklif"],['text'=>"💶 Valyuta",'callback_data'=>"valyuta"]],
[['text'=>"💵 Minimal pul yechish narxi",'callback_data'=>"narx"]],
[['text'=>"📎 Admin useri",'callback_data'=>"admin"],['text'=>"Yopish",'callback_data'=>"yopish"]]
]])
]);
}

if($data == "holat"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"<b>Hozirgi holat:

1. Valyuta:</b> $valyuta
<b>2. Taklif narxi:</b> $taklif $valyuta
<b>3. Pul yechish narxi:</b> $narx $valyuta
<b>4. Admin useri:</b> $user",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"◀️ Orqaga",'callback_data'=>"asosiy"]]
]])
]);
}

if($data == "taklif"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Taklif narxini yuboring:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","taklifpul");
}

if($step == "taklifpul"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
file_put_contents("admin/taklif.txt",$text);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Muvaffaqiyatli o'zgartirildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
}}}}

if($data == "valyuta"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Pul birligini yuboring:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","valyuta");
}

if($step == "valyuta"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
file_put_contents("admin/valyuta.txt",$text);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Muvaffaqiyatli o'zgartirildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
}}}}

if($data == "narx"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Minimal pul yechish narxini yuboring:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","narx");
}

if($step == "narx"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
file_put_contents("admin/narx.txt",$text);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Muvaffaqiyatli o'zgartirildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
}}}}

if($data == "admin"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>Admin userini yuboring:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid2.step","admin-user");
}

if($step == "admin-user"){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if($cid == $admin){
if(isset($text)){
file_put_contents("admin/user.txt",$text);
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Muvaffaqiyatli o'zgartirildi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
}}}}

if($text == "🎛 Tugmalar" and $cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Tugma kodini kiriting:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid.step","tugma-kodi");
}

if($step == "tugma-kodi" and $cid == $admin){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if(file_exists("tugma/$text.txt")){
file_put_contents("step/tugma.txt",$text);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<pre>$text</pre> <b>qabul qilindi.</b>

<i>Ushbu kod uchun qiymatni kiriting:</i>",
'parse_mode'=>'html',
]);
file_put_contents("step/$cid.step",'tugma-qiymat');
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Mavjud bo'lmagan kod tugma kodini kiritdingiz!</b>

<i><a href='$shaxsiy'>Qo'llanmada</a> ko'rsatilgan kodlardan foydalaning:</i>",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
}}}

if($step=="tugma-qiymat" and $cid == $admin){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
file_put_contents("tugma/$tugma.txt",$text);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>O'zgartirish yakunlandi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
unlink("step/tugma.txt");
}}

if($text == "📃 Matnlar" and $cid == $admin){
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Matn kodini kiriting:</b>",
'parse_mode'=>'html',
'reply_markup'=>$boshqarish,
]);
file_put_contents("step/$cid.step","matn-kodi");
}

if($step == "matn-kodi" and $cid == $admin){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
if(file_exists("matn/$text.txt")){
file_put_contents("step/matn.txt",$text);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<pre>$text</pre> <b>qabul qilindi.</b>

<i>Ushbu kod uchun qiymatni kiriting:</i>",
'parse_mode'=>'html',
]);
file_put_contents("step/$cid.step",'matn-qiymat');
}else{
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>Mavjud bo'lmagan kod matn kodini kiritdingiz!</b>

<i><a href='$shaxsiy'>Qo'llanmada</a> ko'rsatilgan kodlardan foydalaning:</i>",
'parse_mode'=>'html',
'disable_web_page_preview'=>true,
]);
}}}

if($step == "matn-qiymat" and $cid == $admin){
if($tx=="🗄 Boshqarish"){
unlink("step/$cid.step");
}else{
file_put_contents("matn/$matn.txt",$text);
bot('SendMessage',[
'chat_id'=>$admin,
'text'=>"<b>O'zgartirish yakunlandi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
unlink("step/$cid.step");
unlink("step/matn.txt");
}}

?>