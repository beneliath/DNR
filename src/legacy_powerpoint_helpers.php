<?php
declare(strict_types=1);

/** Bounded MS-CFB reader. Uploaded sectors are never extracted or executed. */
final class LegacyPowerPointReader
{
    private int $sectorSize;
    private int $sectorCount;
    private array $fat = [];
    private array $miniFat = [];
    private string $miniStream = '';
    private array $allocated = [];
    private array $miniAllocated = [];

    public function __construct(private string $data)
    {
        $major = $this->u16($data, 26);
        $shift = $this->u16($data, 30);
        if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
            || !in_array([$major, $shift], [[3, 9], [4, 12]], true)
            || $this->u16($data, 28) !== 65534 || $this->u16($data, 32) !== 6
            || substr($data, 34, 6) !== str_repeat("\0", 6) || $this->u32($data, 56) !== 4096) {
            throw new UnexpectedValueException('Invalid compound-file header.');
        }
        $this->sectorSize = 1 << $shift;
        if (strlen($data) % $this->sectorSize !== 0) throw new UnexpectedValueException('Truncated compound file.');
        $this->sectorCount = intdiv(strlen($data), $this->sectorSize) - 1;
        $fatCount = $this->u32($data, 44);
        if ($fatCount < 1 || $fatCount > (int) ceil($this->sectorCount / ($this->sectorSize / 4)) + 1) throw new UnexpectedValueException('Invalid FAT size.');
        $fatIds = array_values(unpack('V*', substr($data, 76, 436)));
        $next = $this->u32($data, 68);
        $difatCount = $this->u32($data, 72);
        if ($difatCount > $this->sectorCount) throw new UnexpectedValueException('Invalid DIFAT size.');
        for ($i = 0; $i < $difatCount; $i++) {
            $this->allocate($next);
            $entries = array_values(unpack('V*', $this->sector($next)));
            $next = array_pop($entries);
            array_push($fatIds, ...$entries);
        }
        if ($next !== 0xFFFFFFFE && !($difatCount === 0 && $next === 0xFFFFFFFF)) throw new UnexpectedValueException('Invalid DIFAT chain.');
        $fatIds = array_values(array_filter($fatIds, static fn(int $id): bool => $id !== 0xFFFFFFFF));
        if (count($fatIds) !== $fatCount) throw new UnexpectedValueException('Invalid FAT sector count.');
        foreach ($fatIds as $id) {
            $this->allocate($id);
            array_push($this->fat, ...array_values(unpack('V*', $this->sector($id))));
        }
        if (count($this->fat) < $this->sectorCount) throw new UnexpectedValueException('Incomplete allocation table.');
        foreach ($fatIds as $id) {
            if ($this->fat[$id] !== 0xFFFFFFFD) throw new UnexpectedValueException('Invalid FAT marker.');
        }
    }

    private function u16(string $bytes, int $offset): int
    {
        if (strlen($bytes) < $offset + 2) throw new UnexpectedValueException('Truncated integer.');
        return unpack('v', substr($bytes, $offset, 2))[1];
    }

    private function u32(string $bytes, int $offset): int
    {
        if (strlen($bytes) < $offset + 4) throw new UnexpectedValueException('Truncated integer.');
        return unpack('V', substr($bytes, $offset, 4))[1];
    }

    private function allocate(int $id): void
    {
        if ($id >= $this->sectorCount || isset($this->allocated[$id])) throw new UnexpectedValueException('Invalid or overlapping sector chain.');
        $this->allocated[$id] = true;
    }

    private function sector(int $id): string
    {
        if ($id >= $this->sectorCount) throw new UnexpectedValueException('Sector out of bounds.');
        return substr($this->data, ($id + 1) * $this->sectorSize, $this->sectorSize);
    }

    private function chain(int $id, ?int $size = null, bool $mini = false): string
    {
        $result = '';
        $seen = [];
        $blockSize = $mini ? 64 : $this->sectorSize;
        $limit = $size === null ? $this->sectorCount : (int) ceil($size / $blockSize);
        while ($id !== 0xFFFFFFFE) {
            if (isset($seen[$id]) || count($seen) >= $limit) throw new UnexpectedValueException('Invalid stream chain.');
            $seen[$id] = true;
            if ($mini) {
                if (isset($this->miniAllocated[$id]) || !isset($this->miniFat[$id]) || ($id + 1) * 64 > strlen($this->miniStream)) throw new UnexpectedValueException('Mini sector out of bounds.');
                $this->miniAllocated[$id] = true;
                $result .= substr($this->miniStream, $id * 64, 64);
                $id = $this->miniFat[$id];
            } else {
                $this->allocate($id);
                $result .= $this->sector($id);
                $id = $this->fat[$id];
            }
        }
        if ($size !== null && count($seen) !== $limit) throw new UnexpectedValueException('Truncated stream.');
        return $size === null ? $result : substr($result, 0, $size);
    }

    private function stream(string $entry, bool $root = false): string
    {
        $size = $this->u32($entry, 120);
        if ($this->u32($entry, 124) !== 0 || $size > strlen($this->data)) throw new UnexpectedValueException('Invalid stream size.');
        return $this->chain($this->u32($entry, 116), $size, !$root && $size < 4096);
    }

    public function validate(): void
    {
        $directory = $this->chain($this->u32($this->data, 48));
        $root = substr($directory, 0, 128);
        if (strlen($root) !== 128 || ord($root[66]) !== 5) throw new UnexpectedValueException('Missing root directory.');
        $this->miniStream = $this->stream($root, true);
        $miniCount = $this->u32($this->data, 64);
        if ($miniCount > (int) ceil(strlen($this->miniStream) / 64 * 4 / $this->sectorSize) + 1) throw new UnexpectedValueException('Invalid mini FAT size.');
        $miniBytes = $this->chain($this->u32($this->data, 60), $miniCount * $this->sectorSize);
        $this->miniFat = $miniBytes === '' ? [] : array_values(unpack('V*', $miniBytes));
        $queue = [$this->u32($root, 76)];
        $seen = []; $streams = [];
        while ($queue !== []) {
            $id = array_pop($queue);
            if ($id === 0xFFFFFFFF) continue;
            if ($id === 0 || isset($seen[$id]) || ($id + 1) * 128 > strlen($directory)) throw new UnexpectedValueException('Invalid directory tree.');
            $seen[$id] = true;
            $entry = substr($directory, $id * 128, 128);
            $length = $this->u16($entry, 64);
            if ($length < 2 || $length > 64 || $length % 2 !== 0 || substr($entry, $length - 2, 2) !== "\0\0") throw new UnexpectedValueException('Invalid directory name.');
            $name = mb_convert_encoding(substr($entry, 0, $length - 2), 'UTF-8', 'UTF-16LE');
            if (in_array($name, ['PowerPoint Document', 'Current User'], true)) {
                if (ord($entry[66]) !== 2 || isset($streams[$name])) throw new UnexpectedValueException('Invalid PowerPoint stream.');
                $streams[$name] = $this->stream($entry);
            }
            $queue[] = $this->u32($entry, 68); $queue[] = $this->u32($entry, 72);
        }
        $current = $streams['Current User'] ?? '';
        $document = $streams['PowerPoint Document'] ?? '';
        if ($this->u16($current, 2) !== 4086 || $this->u32($current, 4) < 20
            || $this->u32($current, 4) + 8 > strlen($current)) throw new UnexpectedValueException('Invalid CurrentUserAtom.');
        $editOffset = $this->u32($current, 16);
        $foundDocument = false; $foundEdit = false;
        for ($offset = 0; $offset < strlen($document);) {
            $type = $this->u16($document, $offset + 2);
            $length = $this->u32($document, $offset + 4);
            if ($length > strlen($document) - $offset - 8) throw new UnexpectedValueException('Truncated PowerPoint record.');
            if ($type === 1000 && ($this->u16($document, $offset) & 15) === 15) $foundDocument = true;
            if ($offset === $editOffset && $type === 4085 && $length >= 28) $foundEdit = true;
            $offset += 8 + $length;
        }
        if (!$foundDocument || !$foundEdit) throw new UnexpectedValueException('Missing PowerPoint document or current edit.');
    }
}

function isValidLegacyPowerPoint(string $data): bool
{
    try { (new LegacyPowerPointReader($data))->validate(); return true; }
    catch (UnexpectedValueException $exception) { return false; }
}
