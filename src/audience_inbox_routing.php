<?php
declare(strict_types=1);

/** A signed plus-address selects an Account, never a sender/name/database parameter. */
function audienceInboxAddress(string $gateway,string $account,int $route,string $secret):string
{
    $gateway=strtolower(trim($gateway));
    if(!filter_var($gateway,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Configure the existing inbound gateway before connecting Inbox email replies.');
    [$local,$domain]=explode('@',$gateway,2);
    $local=explode('+',$local,2)[0].'+mm.'.substr(hash('sha256',$account),0,12).'.'.$route.'.'.bin2hex(base64_decode(strtr(accountMailMarkerTag($account,'M',$route,$secret),'-_','+/').'==',true));
    if(strlen($local)>64)throw new InvalidArgumentException('The inbound gateway address is too long for signed Inbox routing.');
    return $local.'@'.$domain;
}

/** Resolve only Inbox-shaped addresses; malformed capabilities force ordinary mail review. */
function audienceInboxAddressMarkers(array $message,callable $accountForDigest):string
{
    $text='';
    foreach(['to_addresses','cc_addresses']as$field){
        $addresses=$message[$field]??[];
        if(is_string($addresses))$addresses=json_decode($addresses,true)??[];
        if(!is_array($addresses)||count($addresses)>50)return "\n[MOED@invalid]";
        foreach($addresses as$address){
            if(!is_string($address))continue;
            if(!str_contains(strtolower($address),'+mm.'))continue;
            $address=strtolower($address);
            if(!preg_match('/\A[^@+]{1,64}\+mm\.([a-f0-9]{12})\.([1-9][0-9]{0,9})\.([a-f0-9]{32})@[^@\s]{1,253}\z/D',$address,$m)||(int)$m[2]>2147483647)return "\n[MOED@invalid]";
            $account=$accountForDigest($m[1]);
            if(!is_string($account)||!preg_match('/\A[a-z][a-z0-9-]{2,63}\z/D',$account))return "\n[MOED@invalid]";
            $text.="\n[MOED@".$account.'#M'.$m[2].'.'.rtrim(strtr(base64_encode(hex2bin($m[3])),'+/','-_'),'=').']';
        }
    }
    return $text;
}
