<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';

use Dnr\Security\PasswordPolicy;

function expectSecurityRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Security runtime regression test failed: {$message}\n");
        exit(1);
    }
}

function runSecurityRuntimeProcess(array $command, string $input = ''): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return ['status' => 1, 'stdout' => '', 'stderr' => 'Unable to start child process.'];
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

$validPassword = 'Correct-Horse-42';
expectSecurityRuntime(
    PasswordPolicy::validationError($validPassword) === null,
    'a valid password should satisfy the shared policy.'
);
expectSecurityRuntime(
    PasswordPolicy::validationError('too-short') !== null,
    'passwords shorter than twelve characters should be rejected.'
);
expectSecurityRuntime(
    PasswordPolicy::validationError(str_repeat('a', 72)) === null
        && str_contains(
            (string) PasswordPolicy::validationError(str_repeat('a', 73)),
            '72 UTF-8 bytes or fewer'
        ),
    'the bcrypt byte boundary should be enforced before hashing.'
);
expectSecurityRuntime(
    PasswordPolicy::validationError([]) !== null,
    'array-valued request input should be rejected without a type error.'
);
expectSecurityRuntime(
    PasswordPolicy::validationError("invalid-utf8-\xFF-value") !== null,
    'invalid UTF-8 password input should be rejected.'
);

$hash = PasswordPolicy::hash($validPassword);
expectSecurityRuntime(
    PasswordPolicy::verify($validPassword, $hash)
        && !PasswordPolicy::verify($validPassword . '-wrong', $hash),
    'shared password verification should accept only the original password.'
);

$bcryptPrefix = str_repeat('p', 72);
$rawBcryptHash = password_hash($bcryptPrefix . 'first', PASSWORD_BCRYPT);
expectSecurityRuntime(
    is_string($rawBcryptHash)
        && password_verify($bcryptPrefix . 'second', $rawBcryptHash)
        && !PasswordPolicy::verify($bcryptPrefix . 'second', $rawBcryptHash),
    'verification should reject an overlong bcrypt alias even when native verification accepts it.'
);

$root = dirname(__DIR__);
$productionIni = $root . '/docker/php-production.ini';
$productionIniContents = file_get_contents($productionIni);
$apacheSecurity = file_get_contents($root . '/docker/apache-security.conf');
$dockerfile = file_get_contents($root . '/Dockerfile');
$cliHelper = $root . '/scripts/cli_input.php';

expectSecurityRuntime(
    is_string($productionIniContents)
        && str_contains($productionIniContents, 'zend.exception_ignore_args=On'),
    'the production configuration should omit function arguments from exception traces.'
);
$exceptionConfiguration = runSecurityRuntimeProcess([
    PHP_BINARY,
    '-c',
    $productionIni,
    '-r',
    'exit(ini_get("zend.exception_ignore_args") === "1" ? 0 : 1);',
]);
expectSecurityRuntime(
    $exceptionConfiguration['status'] === 0,
    'the production runtime should enable exception argument redaction.'
);

$cliConfiguration = runSecurityRuntimeProcess([
    PHP_BINARY,
    '-c',
    $productionIni,
    '-r',
    'exit(function_exists("shell_exec") || function_exists("proc_open") ? 1 : 0);',
]);
expectSecurityRuntime(
    $cliConfiguration['status'] === 0,
    'the production configuration should disable process execution by default.'
);
$passwordCliConfiguration = runSecurityRuntimeProcess([
    PHP_BINARY,
    '-c',
    $productionIni,
    '-d',
    'disable_functions=exec,passthru,system,proc_open,popen',
    '-r',
    'exit(function_exists("shell_exec") && !function_exists("proc_open") ? 0 : 1);',
]);
expectSecurityRuntime(
    $passwordCliConfiguration['status'] === 0,
    'password commands should restore shell_exec without restoring other process functions.'
);
expectSecurityRuntime(
    is_string($apacheSecurity)
        && !str_contains($apacheSecurity, 'disable_functions')
        && is_string($dockerfile)
        && str_contains($dockerfile, 'COPY --chmod=0644 scripts/cli_input.php /opt/dnr/bin/cli_input.php')
        && str_contains($dockerfile, 'scripts/password_cli_entrypoint.sh'),
    'the password helper and its narrowly scoped production entry point should ship in the image.'
);
foreach ([
    'create_admin.php',
    'set_password.php',
    'cli_input.php',
    'migrate_passwords.php',
    'check_schema.php',
    'process_geocode_queue.php',
    'process_inbound_mail.php',
    'process_email_outbox.php',
    'restore_database.php',
] as $runtimeScript) {
    expectSecurityRuntime(
        str_contains(
            $dockerfile,
            "COPY --chmod=0644 scripts/{$runtimeScript} /opt/dnr/bin/{$runtimeScript}"
        ),
        "{$runtimeScript} should be readable by the unprivileged runtime user."
    );
}
expectSecurityRuntime(
    str_contains($dockerfile, 'RUN install -d -m 0755 /opt/dnr/bin')
        && strpos($dockerfile, 'RUN install -d -m 0755 /opt/dnr/bin')
            < strpos($dockerfile, 'COPY --chmod=0644 scripts/create_admin.php'),
    'the runtime script directory should be traversable before unprivileged scripts are copied.'
);

$passwordEntrypoint = file_get_contents($root . '/scripts/password_cli_entrypoint.sh');
expectSecurityRuntime(
    is_string($passwordEntrypoint)
        && str_contains(
            $passwordEntrypoint,
            'disable_functions=exec,passthru,system,proc_open,popen'
        )
        && !str_contains(
            $passwordEntrypoint,
            'disable_functions=exec,passthru,shell_exec,system,proc_open,popen'
        ),
    'password commands should restore shell_exec only, leaving other process functions disabled.'
);

$redirectedInput = runSecurityRuntimeProcess([
    PHP_BINARY,
    '-r',
    'require $argv[1]; echo readHiddenCliValue("Secret: ");',
    $cliHelper,
], "redirected-secret\n");
expectSecurityRuntime(
    $redirectedInput['status'] === 0
        && $redirectedInput['stdout'] === 'Secret: redirected-secret',
    'non-interactive secret input should remain available for secure automation.'
);

echo "Security runtime regression tests passed.\n";
