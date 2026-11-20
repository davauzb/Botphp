-- StudyBot Pro — SQLite sxema (v2: hajm bo'yicha narxlash)

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER UNIQUE NOT NULL,
    username TEXT,
    state TEXT DEFAULT 'idle',        -- idle | awaiting_profile_*:<type>:<volume_key> | awaiting_topic:<type>:<volume_key>
    free_used INTEGER DEFAULT 0,      -- 0 = bepul buyurtma huquqi bor, 1 = ishlatilgan
    full_name TEXT,                   -- titul varag'i uchun: F.I.Sh
    university TEXT,                  -- titul varag'i uchun: universitet nomi
    group_name TEXT,                  -- titul varag'i uchun: guruh
    pending_design_key TEXT,          -- slayd buyurtma qilayotganda tanlangan dizayn (vaqtinchalik)
    balance INTEGER DEFAULT 0,        -- ichki hamyon balansi (so'mda)
    referred_by INTEGER,              -- kim taklif qilgan (telegram_id), agar mavjud bo'lsa
    referral_rewarded INTEGER DEFAULT 0, -- taklif qilgan userga bonus berilganmi (1 marta beriladi)
    referral_count INTEGER DEFAULT 0, -- shu user necha kishini taklif qilgani (mukofotlangan)
    referral_earned INTEGER DEFAULT 0, -- referaldan jami qancha bonus olgani (so'mda)
    last_request_at INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    type TEXT NOT NULL,               -- mustaqil_ish | slayd | referat
    topic TEXT NOT NULL,
    volume_key TEXT NOT NULL,         -- MUSTAQIL_ISH_VOLUMES / SLAYD_VOLUMES kalitlaridan biri
    price INTEGER NOT NULL,           -- so'mda, shu buyurtma uchun narx
    is_free INTEGER DEFAULT 0,
    design_key TEXT,                  -- slayd uchun tanlangan dizayn (PPTX_DESIGNS kaliti)
    rating INTEGER,                   -- foydalanuvchi bergan baho (1-5), hali baholanmagan bo'lsa NULL
    status TEXT DEFAULT 'awaiting_payment', -- awaiting_payment | pending | processing | done | failed | cancelled
    retries INTEGER DEFAULT 0,
    result_file TEXT,
    error_message TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status, id);

CREATE TABLE IF NOT EXISTS api_key_state (
    provider TEXT NOT NULL,
    api_key TEXT NOT NULL,
    fail_count INTEGER DEFAULT 0,
    disabled_until INTEGER DEFAULT 0, -- unix timestamp, 0 = faol
    last_used INTEGER DEFAULT 0,
    PRIMARY KEY (provider, api_key)
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    job_id INTEGER NOT NULL,          -- aynan qaysi buyurtma uchun to'lov
    amount INTEGER NOT NULL,          -- so'mda
    status TEXT DEFAULT 'pending',    -- pending | paid | cancelled
    provider TEXT,                    -- click | payme
    external_trans_id TEXT,           -- Click/Payme tomonidagi tranzaksiya ID
    click_prepare_id INTEGER,
    payme_state INTEGER DEFAULT 0,    -- 1=yaratildi, 2=to'landi, -1/-2=bekor qilindi
    create_time_ms INTEGER,
    perform_time_ms INTEGER DEFAULT 0,
    cancel_time_ms INTEGER DEFAULT 0,
    cancel_reason INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status, id);
CREATE INDEX IF NOT EXISTS idx_payments_external ON payments(provider, external_trans_id);
CREATE INDEX IF NOT EXISTS idx_payments_job ON payments(job_id);

-- Hamyon balansini to'ldirish so'rovlari (Click/Payme avtomatik YOKI chek yuborib qo'lda admin tasdiqlashi)
CREATE TABLE IF NOT EXISTS topups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    amount INTEGER NOT NULL,          -- so'mda
    method TEXT NOT NULL,             -- click | payme | manual
    status TEXT DEFAULT 'pending',    -- pending | approved | rejected
    external_trans_id TEXT,
    click_prepare_id INTEGER,
    payme_state INTEGER DEFAULT 0,
    create_time_ms INTEGER,
    perform_time_ms INTEGER DEFAULT 0,
    cancel_time_ms INTEGER DEFAULT 0,
    cancel_reason INTEGER,
    receipt_file_id TEXT,             -- manual usulda: Telegram'dagi chek rasmining file_id'si
    admin_note TEXT,
    resume_type TEXT,                 -- agar buyurtma davomida balans yetmasa: qaysi turga qaytiladi
    resume_volume_key TEXT,           -- va qaysi hajmga
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_topups_status ON topups(status, id);
CREATE INDEX IF NOT EXISTS idx_topups_external ON topups(method, external_trans_id);
