// ============================================================
//  SAQLASH: loyihalar IndexedDB da (katta modellar uchun ham joy yetadi),
//  ilova sozlamalari esa localStorage da saqlanadi.
// ============================================================
const DB_NAME = 'mini3d';
const STORE = 'projects';
let dbPromise = null;

function db() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const d = req.result;
      if (!d.objectStoreNames.contains(STORE)) d.createObjectStore(STORE, { keyPath: 'id' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbPromise;
}

function tx(mode, fn) {
  return db().then(
    (d) =>
      new Promise((resolve, reject) => {
        const t = d.transaction(STORE, mode);
        const store = t.objectStore(STORE);
        let result;
        Promise.resolve(fn(store)).then((r) => (result = r));
        t.oncomplete = () => resolve(result);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error);
      })
  );
}

const reqP = (req) => new Promise((res, rej) => ((req.onsuccess = () => res(req.result)), (req.onerror = () => rej(req.error))));

export const projects = {
  /** Loyihani saqlaydi: {id, name, updated, thumb, data} */
  save(project) {
    return tx('readwrite', (s) => reqP(s.put({ ...project, updated: Date.now() })));
  },
  get(id) {
    return tx('readonly', (s) => reqP(s.get(id)));
  },
  remove(id) {
    return tx('readwrite', (s) => reqP(s.delete(id)));
  },
  /** Ma'lumotlarsiz ro'yxat (tezroq) */
  async list() {
    const all = await tx('readonly', (s) => reqP(s.getAll()));
    return (all || [])
      .filter((p) => p.id !== '__autosave')
      .map(({ id, name, updated, thumb }) => ({ id, name, updated, thumb }))
      .sort((a, b) => b.updated - a.updated);
  },
};

// ---------- Ilova sozlamalari ----------
const SETTINGS_KEY = 'mini3d.settings';
export const defaultSettings = {
  lang: 'uz',
  snap: false,
  snapMove: 0.25,
  snapRotate: 15,
  snapScale: 0.1,
  space: 'world',
  quality: 'auto', // low | medium | high | auto
  autosave: true,
  showStats: true,
  invertPanel: false,
};

export function loadSettings() {
  try {
    return { ...defaultSettings, ...JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}') };
  } catch {
    return { ...defaultSettings };
  }
}

export function saveSettings(s) {
  try {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(s));
  } catch {
    /* xotira to'la yoki taqiqlangan */
  }
}
