<?php

declare(strict_types=1);

namespace Dnr\Infrastructure;

/** Reads an executed SELECT without buffering its entire result in PHP. */
final class StatementRows
{
    /** @return \Generator<int, array<string, mixed>> */
    public static function stream(\mysqli_stmt $statement): \Generator
    {
        $metadata = $statement->result_metadata();
        if ($metadata === false) {
            throw new \RuntimeException('The statement does not have a row result.');
        }
        $row = [];
        $bindings = [];
        foreach ($metadata->fetch_fields() as $field) {
            $row[$field->name] = null;
            $bindings[] = &$row[$field->name];
        }
        $metadata->free();
        $statement->bind_result(...$bindings);
        try {
            while (($status = $statement->fetch()) !== null) {
                if ($status === false) {
                    throw new \RuntimeException('Unable to read the next result row.');
                }
                // Copy values so the next fetch cannot mutate a yielded row.
                $values = [];
                foreach ($row as $name => $value) {
                    $values[$name] = $value;
                }
                yield $values;
            }
        } finally {
            $statement->free_result();
        }
    }
}
