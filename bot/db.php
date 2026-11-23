<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/SafePDO.php';
require_once __DIR__ . '/lib/EditorSecurity.php';
require_once __DIR__ . '/lib/AdminSecurity.php';
require_once __DIR__ . '/lib/Engine.php';
require_once __DIR__ . '/lib/Designs.php';
require_once __DIR__ . '/lib/Promo.php';
require_once __DIR__ . '/lib/DocStore.php';
require_once __DIR__ . '/lib/I18n.php';
require_once __DIR__ . '/lib/StudentFlow.php';
require_once __DIR__ . '/lib/AdminEvents.php';
require_once __DIR__ . '/lib/Maintenance.php';
require_once __DIR__ . '/lib/DocTypes.php';
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/AdminPro.php';
require_once __DIR__ . '/lib/Growth.php';
require_once __DIR__ . '/lib/AIKeys.php';
require_once __DIR__ . '/lib/Uniq.php';
require_once __DIR__ . '/lib/Requirements.php';
require_once __DIR__ . '/lib/MiniApp.php';
require_once __DIR__ . '/lib/Translate.php';
require_once __DIR__ . '/lib/Userbot.php';
require_once __DIR__ . '/lib/PartRewrite.php';
require_once __DIR__ . '/lib/DocPdf.php';
require_once __DIR__ . '/lib/Express.php';
require_once __DIR__ . '/lib/Loyalty.php';
require_once __DIR__ . '/lib/GroupOrder.php';
require_once __DIR__ . '/lib/Crossword.php';
require_once __DIR__ . '/lib/PromoDays.php';

/**
 * Har chaqiruvda yangi PDO ulanish qaytaradi.
 * WAL + busy_timeout — bir vaqtda ko'p so'rov (webhook + worker) kelganda
 * "database is locked" xatosining oldini oladi: yozuvchi tugaguncha
 * boshqasi xato bermay kutadi.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new SafePDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_TIMEOUT => 15]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['SafeStatement']);
    $pdo->exec('PRAGMA journal_mode = WAL;');   // ko'p o'quvchi + bitta yozuvchi parallel ishlaydi
    $pdo->exec('PRAGMA busy_timeout = 15000;'); // parallel jarayonlar: qulflangan bo'lsa 15 soniyagacha kutadi
    $pdo->exec('PRAGMA synchronous = NORMAL;'); // WAL bilan xavfsiz va ancha tezroq
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $schemaVersion = 21;
    // "Yangi baza" ni fayl mavjudligi bilan EMAS, jadval borligi bilan aniqlaymiz: PDO ulanganda fayl darrov yaratiladi,
    // shuning uchun birinchi so'rov install.sql ni yarim yozib yiqilsa (yoki ikki so'rov bir vaqtda kelsa) baza abadiy
    // jadvalsiz qolardi. install.sql dagi barcha buyruqlar IF NOT EXISTS — qayta bajarilsa ham xavfsiz.
    $isNew = !$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    $curVersion = $isNew ? 0 : (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($isNew) {
        $pdo->exec(file_get_contents(__DIR__ . '/install.sql'));
    } elseif ($curVersion < $schemaVersion) {
        migrateSchema($pdo);      // faqat sxema yangilanganda (har so'rovda emas — ko'p jarayonli yuklamada bazani qulflamaslik uchun)
    }
    if ($curVersion < $schemaVersion) {
        try {
            EditorSecurity::ensureSchema($pdo);
            AdminSecurity::ensureSchema($pdo);    // admin panel jadvallari + users.banned ustuni
            Engine::ensureSchema($pdo);
            Promo::ensureSchema($pdo);            // promokodlar
            I18n::ensureSchema($pdo);             // users.lang, jobs.lang
            DocStore::ensureSchema($pdo);         // tahrirlanadigan hujjatlar
            StudentFlow::ensureSchema($pdo);      // jobs.student, users.order_student/first_name/last_name
            AdminEvents::ensureSchema($pdo);      // admin xabarlari
            Maintenance::ensureSchema($pdo);      // rassilka jadvallari
            Settings::ensureSchema($pdo);         // admin paneldagi sozlamalar
            AdminPro::ensureSchema($pdo);         // rollar, yordam, eslatmalar, AI sarfi
            Growth::ensureSchema($pdo);           // obuna, agentlar, marketing, talablar, unikallik ustunlari
            AIKeys::ensureSchema($pdo);           // paneldan qo'shilgan AI kalitlari
            Uniq::ensureSchema($pdo);             // unikallik izlari
            $pdo->exec('PRAGMA user_version = ' . $schemaVersion);
        } catch (Throwable $e) {
            logError('engine', 'Qo\'shimcha jadvallar yaratilmadi: ' . $e->getMessage());
        }
    }

    return $pdo;
}

/**
 * Eski o'rnatilgan botlarda yangi ustunlar yo'q bo'lishi mumkin (masalan, siz avval
 * boshqa versiyani ishga tushirgan bo'lsangiz). Bu funksiya ularni xavfsiz qo'shadi —
 * ustun allaqachon mavjud bo'lsa, xato e'tiborsiz qoldiriladi va bot yiqilmaydi.
 */
function migrateSchema(PDO $pdo): void
{
    $columns = [
        'users' => [
            'full_name TEXT', 'university TEXT', 'group_name TEXT', 'balance INTEGER DEFAULT 0', 'pending_design_key TEXT',
            'referred_by INTEGER', 'referral_rewarded INTEGER DEFAULT 0', 'referral_count INTEGER DEFAULT 0', 'referral_earned INTEGER DEFAULT 0',
        ],
        'jobs' => ['design_key TEXT', 'rating INTEGER'],
    ];
    foreach ($columns as $table => $defs) {
        foreach ($defs as $def) {
            try {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $def");
            } catch (Throwable $e) {
                // "duplicate column name" — ustun allaqachon bor, bu normal holat
            }
        }
    }

    // Eski o'rnatilgan botlarda "topups" jadvali umuman yo'q bo'lishi mumkin — CREATE TABLE
    // IF NOT EXISTS xavfsiz, mavjud bo'lsa hech narsa qilmaydi.
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS topups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                method TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                external_trans_id TEXT,
                click_prepare_id INTEGER,
                payme_state INTEGER DEFAULT 0,
                create_time_ms INTEGER,
                perform_time_ms INTEGER DEFAULT 0,
                cancel_time_ms INTEGER DEFAULT 0,
                cancel_reason INTEGER,
                receipt_file_id TEXT,
                admin_note TEXT,
                resume_type TEXT,
                resume_volume_key TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_topups_status ON topups(status, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_topups_external ON topups(method, external_trans_id)');
    } catch (Throwable $e) {
        logError('migration', 'topups jadvalini yaratishda xato: ' . $e->getMessage());
    }
}

function seedApiKeys(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO api_key_state (provider, api_key) VALUES (:p, :k)'
    );
    foreach (AI_PROVIDERS as $provider) {
        foreach ($provider['keys'] as $key) {
            $stmt->execute(['p' => $provider['name'], 'k' => $key]);
        }
    }
}

function nowTs(): int
{
    return time();
}

function logError(string $context, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] [$context] $message" . PHP_EOL;
    @file_put_contents(LOG_DIR . 'app.log', $line, FILE_APPEND);
}
