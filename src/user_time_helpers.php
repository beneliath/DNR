<?php
declare(strict_types=1);

/** Display preferences never change storage, scheduling or business-date timezones. */
function userTimezoneOptions():array
{
    static $options;
    if($options===null){
        $options=['UTC'=>'UTC'];
        foreach(DateTimeZone::listIdentifiers(DateTimeZone::ALL)as$name)$options[$name]=str_replace(['_','/'],[' ',' / '],$name);
        ksort($options);
    }
    return $options;
}

function validateUserTimezone(mixed $value):?string
{
    if($value===null||$value==='')return null;
    if(!is_string($value)||!isset(userTimezoneOptions()[$value]))throw new InvalidArgumentException('Choose a timezone from the list.');
    return $value;
}

function userDisplayTimezoneName():string
{
    $value=$_SESSION['display_timezone']??null;
    return is_string($value)&&isset(userTimezoneOptions()[$value])?$value:'UTC';
}

function userDisplayTimezone():DateTimeZone
{
    return new DateTimeZone(userDisplayTimezoneName());
}

/** Accept known instants only; date-only business values have no timezone. */
function userDisplayInstant(mixed $value):?DateTimeImmutable
{
    try{
        if($value instanceof DateTimeInterface)$instant=DateTimeImmutable::createFromInterface($value);
        elseif(is_int($value))$instant=new DateTimeImmutable('@'.$value);
        elseif(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:\s?(?:Z|UTC|[+-]\d{2}:?\d{2}))?$/D',$value)){
            $instant=new DateTimeImmutable($value,new DateTimeZone('UTC'));
            $errors=DateTimeImmutable::getLastErrors();
            if($errors&&($errors['warning_count']||$errors['error_count']))return null;
        }else return null;
        return $instant->setTimezone(userDisplayTimezone());
    }catch(Throwable){return null;}
}

function formatUserTimestamp(mixed $value,string $format='M j, Y, g:i A'):string
{
    $instant=userDisplayInstant($value);
    return $instant?$instant->format($format).(preg_match('/(?<!\\\\)T/',$format)?'':' '.$instant->format('T')):(is_scalar($value)?(string)$value:'');
}
