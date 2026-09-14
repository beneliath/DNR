<?php

declare(strict_types=1);

namespace Dnr\Infrastructure;

/** Read-only discovery; human read flags are not ingestion state. */
interface InboundMailbox
{
    public function uidValidity(): int;
    public function uidNext(): int;
    /** @return list<int> */
    public function uidsBetween(int $first, int $last): array;
    public function fetchRawMessage(int $uid): string;
    public function abort(): void;
}
