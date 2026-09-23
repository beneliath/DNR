<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_job_helpers.php';
$calls=0;
$value=aiCoachRetryDatabase(static function()use(&$calls):int { if (++$calls<3) throw new mysqli_sql_exception('Synthetic deadlock',1213); return 42; });
if ($calls!==3 || $value!==42) throw new RuntimeException('Deadlock replay did not recover');
$calls=0;
try { aiCoachRetryDatabase(static function()use(&$calls):void { $calls++; throw new mysqli_sql_exception('Synthetic deadlock',1213); }); throw new LogicException('Persistent error was hidden'); }
catch(mysqli_sql_exception $expected) { if ($calls!==3) throw new RuntimeException('Retry is not bounded'); }
$calls=0;
try { aiCoachRetryDatabase(static function()use(&$calls):void { $calls++; throw new mysqli_sql_exception('Permission denied',1142); }); throw new LogicException('Permission error was hidden'); }
catch(mysqli_sql_exception $expected) { if ($calls!==1) throw new RuntimeException('Non-deadlock operation replayed'); }
echo "Coach database retries passed: recovery, bounded failure, and no replay of other errors.\n";
