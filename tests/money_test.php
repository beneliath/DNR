<?php
declare(strict_types=1);
require_once __DIR__.'/../src/application_runtime.php';
use Dnr\Domain\Money;
if (Money::total(['0.10','0.20']) !== '0.30' || Money::amount('000123.4','Amount') !== '123.40'
    || Money::fromCents(Money::cents('9999999999.99')) !== '9999999999.99') {
    throw new RuntimeException('Fixed precision money changed a value.');
}
foreach (['-1','1.001','1e3','1,000.00','10000000000.00','NaN'] as $invalid) {
    try { Money::amount($invalid,'Amount'); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Invalid money was accepted.');
}
echo "Exact decimal money tests passed.\n";
