<?php
declare(strict_types=1);
require_once __DIR__ . '/record_merge_helpers.php';

function creationDuplicateWarning(mysqli $conn, string $kind, array $input): array
{
    $read=static fn($key)=>is_scalar($input[$key]??null)?mb_substr(trim((string)$input[$key]),0,255):'';
    $source=['id'=>0];
    $fields=$kind==='contact'?['contact_first_name','contact_last_name','contact_email','contact_phone']:['organization_name','email','phone','physical_address_line_1','physical_city','physical_state','physical_country'];
    foreach($fields as $field) $source[$field]=$read($field);
    if($kind==='organization' && $source['organization_name']==='') return ['matches'=>[],'token'=>''];
    if($kind==='contact' && $source['contact_first_name']==='' && $source['contact_last_name']==='' && $source['contact_email']==='' && $source['contact_phone']==='') return ['matches'=>[],'token'=>''];
    $phoneField=$kind==='contact'?'contact_phone':'phone';
    try { $source[$phoneField]=normalizePhoneNumber($read($phoneField.'_country_code') ?: applicationDefaultPhoneCountryCode(), $source[$phoneField]); }
    catch (InvalidArgumentException $ignored) { /* Ordinary validation reports malformed phone numbers. */ }
    $matches=recordDuplicateCandidates($conn,$kind,$source);
    return ['matches'=>$matches,'token'=>hash('sha256',json_encode([$kind,$source,array_column($matches,'id')],JSON_THROW_ON_ERROR))];
}

function creationDuplicatesAcknowledged(array $warning, array $input): bool
{
    return !$warning['matches'] || (($input['duplicate_distinct']??'')==='1' && is_string($input['duplicate_token']??null) && hash_equals($warning['token'],$input['duplicate_token']));
}

function renderCreationDuplicateWarning(array $warning, string $kind, string $return=''): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    ?><section data-duplicate-warning <?php echo $warning['matches']?'':'hidden'; ?> class="form-section">
    <h2>Possible Existing Records</h2><p>Review these matches before creating another record. A shared name or email does not necessarily mean the same person or organization.</p>
    <ul data-duplicate-matches><?php foreach($warning['matches'] as $match): ?>
    <li><a href="<?php echo $h(creationDuplicateDestination($kind,(int)$match['id'],$return)); ?>"><?php echo $h('Use Existing: '.$match['label']); ?></a> <?php echo $h(implode(' · ', array_filter([$match['email']??'', $match['phone']??'', $match['location']??'']))); ?></li><?php endforeach; ?></ul>
    <input type="hidden" name="duplicate_token" value="<?php echo $h($warning['token']); ?>">
    <label><input type="checkbox" name="duplicate_distinct" value="1"> These Are Different Records — Create a New One</label>
    </section><?php
}

function creationDuplicateDestination(string $kind,int $id,string $return): string
{
    return $return!=='' ? recordUrlWithQuery($return,[$kind==='contact'?'created_contact_id':'created_organization_id'=>$id]) : 'view_'.$kind.'.php?id='.$id;
}
