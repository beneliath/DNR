<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dnr\Http\RequestInput;

function expectRequestInput(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Request input test failed: {$message}\n");
        exit(1);
    }
}

$input = [
    'query' => '  alpha beta  ',
    'array_query' => ['unexpected'],
    'id' => '42',
    'bad_id' => ['42'],
    'mode' => 'active',
    'ids' => ['2', '2', 5, '0', '-1', ['9']],
];

expectRequestInput(
    RequestInput::string($input, 'query') === 'alpha beta'
        && RequestInput::string($input, 'array_query', 'fallback') === 'fallback'
        && RequestInput::string(['value' => 'abcdef'], 'value', '', 3) === 'abc',
    'scalar strings should be trimmed and bounded while repeated keys are rejected.'
);
expectRequestInput(
    RequestInput::positiveInt($input, 'id') === 42
        && RequestInput::positiveInt($input, 'bad_id') === null
        && RequestInput::positiveInt(['id' => '0'], 'id') === null,
    'positive identifiers should reject arrays, zero, and negative values.'
);
expectRequestInput(
    RequestInput::enum($input, 'mode', ['active', 'archived']) === 'active'
        && RequestInput::enum(['mode' => ['active']], 'mode', ['active'], 'safe') === 'safe'
        && RequestInput::positiveIntList($input, 'ids') === [2, 5],
    'allowlisted values and identifier lists should have stable scalar-only behavior.'
);

echo "Request input tests passed.\n";
