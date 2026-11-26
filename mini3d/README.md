# Mini 3D Muharrir (Android)

Telefonda 3D model yaratish uchun yengil muharrir. Ilova to'liq oflayn ishlaydi.
3D qism [three.js](https://threejs.org) asosida, Android qismi esa WebView.

**Tayyor APK:** [`release/Mini3D-v2.1.apk`](release/Mini3D-v2.1.apk) (Android 7.0 va undan yuqori)

## v2.1 da yangi

| Imkoniyat | Tavsif |
|---|---|
| **Organik tana** | Guruhdagi shar/kapsula/silindrlardan bitta silliq, choksiz "teri" yasaydi (SDF + marching cubes). Ranglar silliq aralashadi, avtomatik UV. Natijani darhol sculpt qilish mumkin |
| **Naqshlar** | Protsedural teksturalar: ajdar tangachasi, lava yoriqlari, pat tolalari — chetlari nur sochishi mumkin |
| **Maxluq qismlari** | Pat (tishli, egilgan, rang o'tishi bilan) va shox/tirnoq (egilgan, uchi ingichka) |
| **Yangi feniks-ajdar** | Organik tana + tangacha + 3 qatorli patli qanotlar + oltin zirh va tirnoqlar + olov |

## v2.0 da yangi

| Imkoniyat | Tavsif |
|---|---|
| **Haykaltaroshlik (Sculpt)** | 8 ta cho'tka: chizish, loy, shishirish, silliqlash, tortish, tekislash, siqish, chuqur chiziq. Radius/kuch, teskari rejim, X simmetriya, "detal qo'shish" (×4), loy shar/blok/kapsula. Bir barmoq — shakllantirish, ikki barmoq — kamera. 600 ming uchburchakkacha |
| **Silliqlash (Subdivision)** | Burchakli shakllarni 1–3 daraja yumaloqlash, qirralarni saqlash burchagi bilan |
| **Effektlar** | Olov, uchqun, tutun, sehr, qor, energiya — har birining rang/zichlik/tezlik/o'lchami sozlanadi; guruhga qo'shilsa obyekt bilan birga harakatlanadi |
| **Nur taratish (Bloom)** | Yonib turgan materiallar va olov atrofida haqiqiy nur |
| **Animatsiya** | Vaqt chizig'i, kalit kadrlar (silliq interpolyatsiya), tayyor harakatlar: qanot qoqish, chayqalish, suzish, aylanish, nafas, titrash |
| **Video** | Animatsiyani MP4/WebM videoga yozish (Telegram, Instagram uchun) |
| **GLB animatsiya bilan** | Eksport qilingan GLB da animatsiya ham bo'ladi |
| **Aylanish markazi (pivot)** | Qanot yelkadan, eshik ilmoqdan aylanishi uchun |
| **Namunalar** | Olov feniks-ajdar, gulxan, sehrli kristall — darhol ochib ko'rish mumkin |
| **Tezlik** | Undo xotirasi ixcham (o'zgarmagan ma'lumotlar qayta ishlatiladi), BVH bilan tez nur kesishuvi |

## Imkoniyatlar

| Bo'lim | Nimalar bor |
|---|---|
| **Shakllar (23 ta)** | Kub, shar, silindr, konus, tor, tekislik, kapsula, piramida, ikosaedr, dodekaedr, oktaedr, tetraedr, tugun, doira, yassi halqa, quvur, yulduz, yurak, ark, zina, prujina, tishli g'ildirak, 3D matn. Har birining parametrlari (o'lcham, bo'laklar soni va h.k.) keyin ham o'zgartiriladi |
| **Yorug'lik** | Nuqtali chiroq, projektor, quyosh, osmon nuri, umumiy nur. Rang, kuch, soya |
| **Tahrirlash** | Siljitish / aylantirish / o'lcham gizmosi, aniq raqamli qiymatlar, to'rga yopishish, dunyo/lokal koordinatalar, ierarxiya (guruhlar, ota obyekt), yashirish, qulflash |
| **Materiallar** | 6 xil tur (PBR, shisha/lak, xira, multfilm, yoritilmagan, normal), 12 ta tayyor andoza (oltin, kumush, shisha, neon...), rang, metallik, g'adir-budurlik, shaffoflik, nur sochish, karkas, rasmdan tekstura |
| **Vositalar** | Boolean (qo'shish / ayirish / kesishma), massiv (chiziq yoki aylana bo'ylab), ko'zgu, yerga qo'yish, birlashtirish, silliq/qirrali yuzalar, shaklni muzlatish |
| **Fayllar** | Loyihalarni saqlash/ochish (rasmli ro'yxat bilan), avtomatik saqlash, **import:** GLB, glTF, OBJ, STL, PLY; **eksport:** GLB, glTF, OBJ, STL (3D printer uchun), PLY, PNG rasm, loyiha fayli (.m3d.json) |
| **Boshqalar** | Cheksiz orqaga/oldinga (undo/redo), kamera ko'rinishlari (yuqori, old, yon...), namoyish uchun avtomatik aylanish, sahna sozlamalari (fon, to'r, soyalar, tuman, yorqinlik), grafika sifati, 3 til (o'zbek, ingliz, rus), planshet va klaviatura qo'llab-quvvatlanadi |

Eksport qilingan fayllar telefonning **Download/Mini3D** papkasiga tushadi.

## Loyiha tuzilishi

```
mini3d/
├── web/                     ← muharrirning o'zi (JavaScript)
│   ├── public/              index.html, style.css (dizayn)
│   ├── src/
│   │   ├── main.js          kirish nuqtasi
│   │   ├── editor.js        3D yadro: sahna, kamera, tanlash, saqlash
│   │   ├── primitives.js    SHAKLLAR reestri
│   │   ├── materials.js     material turlari va andozalar
│   │   ├── tools.js         modellash vositalari (boolean, subdivision, pivot...)
│   │   ├── sculpt.js        haykaltaroshlik cho'tkalari
│   │   ├── effects.js       zarracha effektlari (olov, tutun...)
│   │   ├── animation.js     kalit kadrlar, tayyor harakatlar, video
│   │   ├── samples.js       namuna sahnalar
│   │   ├── organic.js       organik tana (SDF + marching cubes)
│   │   ├── textures.js      protsedural naqshlar
│   │   ├── io.js            import / eksport
│   │   ├── history.js       undo / redo
│   │   ├── storage.js       loyihalarni saqlash (IndexedDB)
│   │   ├── i18n.js          tarjimalar
│   │   ├── icons.js         belgilar
│   │   └── ui/              interfeys (panellar, menyular)
│   └── build.mjs            yig'ish skripti
├── android/                 ← Android ilova (Java, WebView)
└── release/                 ← tayyor APK
```

## Yig'ish (build)

Kerak: Node.js 18+, JDK 17+, Android SDK (platform 35).

```bash
cd mini3d/web
npm install
npm run build          # veb qismni android/app/src/main/assets ga yozadi

cd ../android
./gradlew assembleRelease
# APK: android/app/build/outputs/apk/release/app-release.apk
```

Brauzerda sinash (telefonsiz): `cd mini3d/web && npm run dev`, so'ng http://localhost:8000 ni oching.

**GitHub Actions:** `mini3d/` papkasidagi har bir o'zgarishdan keyin APK avtomatik yig'iladi
(Actions → *Mini3D APK* → Artifacts).

> **Imzo kaliti haqida.** Ilovani o'chirmasdan yangilash uchun har safar bir xil kalit bilan imzolash kerak.
> O'z kalitingizni yarating (`keytool -genkeypair -keystore mini3d.jks -alias mini3d ...`),
> keyin `MINI3D_KEYSTORE`, `MINI3D_KEYSTORE_PASSWORD` muhit o'zgaruvchilarini bering
> (GitHub'da: `MINI3D_KEYSTORE_BASE64` va `MINI3D_KEYSTORE_PASSWORD` secret'lari).
> Kalit faylini repoga qo'shmang.

## Qanday kengaytirish mumkin

Ilova qismlarga ajratilgan, yangi imkoniyat qo'shish uchun odatda bitta faylni tahrirlash kifoya.

### Yangi shakl qo'shish (`src/primitives.js`)

```js
registerPrimitive({
  id: 'tabletop',               // noyob nom
  label: 'prim.tabletop',       // tarjima kaliti (i18n.js ga qo'shing)
  icon: 'cube',                 // icons.js dagi belgi
  category: 'shape',            // 'basic' | 'extra' | 'shape'
  params: [                     // panelda avtomatik paydo bo'ladi
    n('width', 'p.width', 1.2, 0.1, 10),
    n('height', 'p.height', 0.8, 0.1, 10),
  ],
  build: (p) => toPlain(new THREE.BoxGeometry(p.width, 0.05, p.width).translate(0, p.height, 0)),
});
```

"Qo'shish" menyusi, xususiyatlar paneli, saqlash va undo hammasi avtomatik ishlaydi.

### Yangi vosita qo'shish (`src/tools.js`)

```js
registerTool({
  label: 'Tasodifiy rang',
  icon: 'palette',
  run(editor, obj) {
    obj.traverse((o) => o.material?.color?.setHSL(Math.random(), 0.7, 0.55));
    editor.commit('random-color');  // undo tarixiga yozish
  },
});
```

Vosita "Vositalar" (sehrli tayoqcha) menyusida paydo bo'ladi.

### Yangi sculpt cho'tkasi (`src/sculpt.js`)

`brushes` ro'yxatiga `{ id, label, icon }` qo'shing va `_dab()` ichidagi `switch` ga shu `id` uchun
uchni qanday siljitish formulasini yozing (`dx, dy, dz`).

### Yangi effekt (`src/effects.js`)

```js
registerEffect({
  id: 'bubbles', label: 'Pufakchalar', icon: 'sphere', blending: 'normal',
  params: [...common({ rate: 20, life: 4, size: 0.2, speed: 0.5, radius: 1, c0: '#fff', c1: '#9ad', c2: '#58a' })],
  spawn(p, P, rnd) { p.x = (rnd() - 0.5) * P.radius; p.vy = P.speed; p.size = P.size; },
  update(p, dt, P, t) { p.vx = Math.sin(t * 2 + p.seed * 9) * 0.2; p.curSize = p.size; },
});
```

### Yangi tayyor harakat (`src/animation.js`)

`motions` obyektiga tur qo'shing va `applyMotion()` ga uning formulasini yozing.

### Boshqalar

- **Material andozasi:** `materials.js` → `materialPresets` ro'yxatiga element qo'shing.
- **Yangi til:** `i18n.js` → `languages` va yangi lug'at obyekti.
- **Dizayn/ranglar:** `public/style.css` boshidagi `:root` o'zgaruvchilari.
- **Yangi eksport formati:** `io.js` → `exportFormats` va `exportModel()`.

### Keyingi qadamlar uchun g'oyalar

- Suyaklar (skeleton/rig) va teri bog'lash — personajni pozaga keltirish
- Sculpt uchun: niqob (mask), alfa-cho'tkalar, dinamik topologiya, voxel remesh
- Rang bilan bo'yash (vertex paint / texture paint)
- Bir nechta obyektni birdaniga tanlash
- Tayyor modellar kutubxonasi
- AR rejimida ko'rish (ARCore)
