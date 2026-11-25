// ============================================================
//  ORQAGA / OLDINGA (Undo / Redo)
//  Har bir o'zgarishda sahnaning to'liq "surati" (snapshot) saqlanadi.
//  Bu usul oddiy va ishonchli: har qanday yangi vosita avtomatik
//  ravishda undo/redo ni qo'llab-quvvatlaydi.
// ============================================================
export class History {
  constructor(editor, limit = 40) {
    this.editor = editor;
    this.limit = limit;
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
    this.editor.emit('historyChanged');
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
