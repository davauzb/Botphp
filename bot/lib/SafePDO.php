<?php

/**
 * SQLite uchun xavfsiz PDO.
 *
 * Muammo: PDO'da `fetch()` / `fetchColumn()` dan keyin so'rov "ochiq" qolib, o'qish tranzaksiyasini ushlab turadi.
 * Ko'p jarayon parallel yozsa, keyingi UPDATE/INSERT "database is locked" (SQLITE_BUSY_SNAPSHOT) xatosini beradi va
 * busy_timeout ham yordam bermaydi. Yechim: SELECT natijasini darhol xotiraga olib, so'rovni yopamiz — o'qish
 * tranzaksiyasi hech qachon ochiq qolmaydi. Qo'shimcha: "locked/busy" xatosida qisqa kutib qayta uriniladi.
 */
class SafeStatement extends PDOStatement
{
    private ?array $rows = null;
    private int $pos = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $attempt = 0;
        while (true) {
            try {
                $ok = $params === null ? parent::execute() : parent::execute($params);
                break;
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                if (($code === 5 || $code === 6) && $attempt < 6) {
                    $attempt++;
                    parent::closeCursor();
                    usleep(random_int(60000, 300000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        $this->rows = null;
        $this->pos = 0;
        if ($ok && $this->columnCount() > 0) {
            $this->rows = parent::fetchAll(PDO::FETCH_ASSOC);
            parent::closeCursor();
        }
        return $ok;
    }

    private function shape(array $row, int $mode, $arg = null)
    {
        switch ($mode) {
            case PDO::FETCH_NUM:
                return array_values($row);
            case PDO::FETCH_BOTH:
                $out = [];
                $i = 0;
                foreach ($row as $k => $v) {
                    $out[$k] = $v;
                    $out[$i++] = $v;
                }
                return $out;
            case PDO::FETCH_OBJ:
                return (object)$row;
            case PDO::FETCH_COLUMN:
                $vals = array_values($row);
                return $vals[(int)($arg ?? 0)] ?? null;
            default:
                return $row;       // FETCH_ASSOC
        }
    }

    #[\ReturnTypeWillChange]
    public function fetch($mode = PDO::FETCH_BOTH, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        if ($this->rows === null) {
            return parent::fetch($mode, $cursorOrientation, $cursorOffset);
        }
        if (!isset($this->rows[$this->pos])) {
            return false;
        }
        return $this->shape($this->rows[$this->pos++], (int)$mode);
    }

    #[\ReturnTypeWillChange]
    public function fetchAll($mode = PDO::FETCH_BOTH, ...$args)
    {
        if ($this->rows === null) {
            return parent::fetchAll($mode, ...$args);
        }
        $rest = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);
        $m = (int)$mode & 0xFFFF;
        if ($m === PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($rest as $r) {
                $v = array_values($r);
                $out[$v[0]] = $v[1] ?? null;
            }
            return $out;
        }
        if ($m === PDO::FETCH_COLUMN) {
            return array_map(fn($r) => $this->shape($r, PDO::FETCH_COLUMN, $args[0] ?? 0), $rest);
        }
        return array_map(fn($r) => $this->shape($r, $m), $rest);
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn($column = 0)
    {
        if ($this->rows === null) {
            return parent::fetchColumn($column);
        }
        if (!isset($this->rows[$this->pos])) {
            return false;
        }
        $vals = array_values($this->rows[$this->pos++]);
        return $vals[(int)$column] ?? false;
    }

    #[\ReturnTypeWillChange]
    public function fetchObject($class = 'stdClass', $constructorArgs = [])
    {
        if ($this->rows === null) {
            return parent::fetchObject($class, $constructorArgs);
        }
        return isset($this->rows[$this->pos]) ? (object)$this->rows[$this->pos++] : false;
    }

    public function closeCursor(): bool
    {
        $this->rows = null;
        return true;
    }
}

class SafePDO extends PDO
{
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $st = $this->prepare($query);
        $st->execute();
        return $st;
    }
}
