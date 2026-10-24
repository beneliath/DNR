<?php
declare(strict_types=1);
require __DIR__.'/../src/audience_helpers.php';
$checks=0;
$expect=static function(bool $ok)use(&$checks):void{if(!$ok)throw new RuntimeException('Unsubscribe address validation failed.');++$checks;};
$expect(audienceUnsubscribeAddresses(" One@Example.test \r\ntwo@example.test\n\none@example.test ")===['one@example.test','two@example.test']);
foreach(['',"\n \n",'Email Address',"one@example.test\nbad",'a@example.test,b@example.test',"one@example.test\nBcc: outside@example.test",str_repeat('a',255).'@example.test',implode("\n",array_map(static fn($i)=>'person'.$i.'@example.test',range(1,5001)))]as$input){
    try{audienceUnsubscribeAddresses($input);}catch(InvalidArgumentException){++$checks;continue;}throw new RuntimeException('Invalid address list was accepted.');
}
try{audienceUnsubscribeAddresses("one@example.test\n\nbad");}catch(InvalidArgumentException $e){$expect(str_contains($e->getMessage(),'line 3'));}
echo "One-address-per-line unsubscribe validation: $checks checks passed.\n";
