// ============================================================
//  ORQAGA / OLDINGA (Undo / Redo)
//  Har bir o'zgarishda sahnaning to'liq "surati" (snapshot) saqlanadi.
//  Bu usul oddiy va ishonchli: har qanday yangi vosita avtomatik
//  ravishda undo/redo ni qo'llab-quvvatlaydi.
// ============================================================
export class History {
  constructor(editor, limit = 60, budgetMB = 80) {
    this.editor = editor;
    this.limit = limit;
    this.budget = budgetMB * 1024 * 1024;
    this.undoStack = [];
    this.redoStack = [];
    this.current = null; // hozirgi holat surati
    this.busy = false;
  }

  reset() {
    this.undoStack = [];
    this.redoStack = [];
    this.current = this.editor.snapshot();
    this.editor.emit('historyChanged');
  }

  /** O'zgarishdan keyin chaqiriladi. */
  push(label = '') {
    if (this.busy) return;
    const snap = this.editor.snapshot();
    if (this.current) {
      this.undoStack.push({ snap: this.current, label });
      if (this.undoStack.length > this.limit) this.undoStack.shift();
    }
    this.current = snap;
    this.redoStack = [];
    this._trim();
    this.editor.emit('historyChanged');
  }

  /**
   * Xotira chegarasi: suratlardagi (takrorlanmas) massivlar hajmi budjetdan
   * oshsa, eng eski qadamlar o'chiriladi. Katta sculpt modellarida muhim.
   */
  _trim() {
    const bytes = () => {
      const seen = new Set();
      let total = 0;
      const count = (snap) => {
        for (const g of snap.content.geometries || []) {
          const attrs = g.data?.attributes || {};
          const arrs = Object.values(attrs).map((a) => a.array);
          if (g.data?.index) arrs.push(g.data.index.array);
          for (const a of arrs) {
            if (!a || seen.has(a)) continue;
            seen.add(a);
            total += a.byteLength ?? a.length * 8;
          }
        }
      };
      if (this.current) count(this.current);
      this.undoStack.forEach((i) => count(i.snap));
      this.redoStack.forEach((i) => count(i.snap));
      return total;
    };
    while (this.undoStack.length > 1 && bytes() > this.budget) this.undoStack.shift();
  }

  get canUndo() {
    return this.undoStack.length > 0;
  }
  get canRedo() {
    return this.redoStack.length > 0;
  }

  async undo() {
    if (!this.canUndo || this.busy) return;
    const item = this.undoStack.pop();
    this.redoStack.push({ snap: this.current, label: item.label });
    this.current = item.snap;
    await this._restore(item.snap);
    return item.label;
  }

  async redo() {
    if (!this.canRedo || this.busy) return;
    const item = this.redoStack.pop();
    this.undoStack.push({ snap: this.current, label: item.label });
    this.current = item.snap;
    await this._restore(item.snap);
    return item.label;
  }

  async _restore(snap) {
    this.busy = true;
    try {
      await this.editor.restoreSnapshot(snap);
    } finally {
      this.busy = false;
      this.editor.emit('historyChanged');
    }
  }
}
