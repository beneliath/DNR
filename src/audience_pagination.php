<?php
declare(strict_types=1);
require_once __DIR__.'/pagination_helpers.php';

/** Each account, signed-in user, list and pagination parameter owns its preference. */
function audienceRememberPageSizes(array $input,string $view,int $userId): array
{
    if($userId<1)return $input;
    $changed=$input['page_size_change']??null;unset($input['page_size_change']);
    $context=in_array($view,['jobs','job'],true)&&is_string($input['kind']??null)?$input['kind']:'';
    foreach(['per_page','list_size','segment_size','activity_size','history_size','plan_size','message_size']as$key){
        $preference='mm_'.substr(hash('sha256',currentAccountKey().':'.$userId.':'.$view.':'.$context.':'.$key),0,24);
        if(!array_key_exists($key,$input)&&!isset($_COOKIE['dnr_rows_per_page_'.$preference]))continue;
        $requested=$input[$key]??null;
        // Filter links and old tabs may contain a stale size. Only the size selector
        // replaces an existing Connections preference.
        if($view==='contacts'&&$key==='per_page'&&$changed!==$key&&paginationPageSize($_COOKIE['dnr_rows_per_page_'.$preference]??null,0,[10,25,50,100])>0)$requested=null;
        // These names already include account and user identity. A primary-account
        // preference must also reach its supported root URLs on localhost.
        $cookiePath=accountIsPrimary()?'/':accountCookiePath();
        $size=paginationPageSizePreference($preference,$requested,0,[10,25,50,100],$cookiePath);
        if($size>0)$input[$key]=$size;
    }
    return $input;
}

/** MessageMonkey page sizes, shared by list queries and their controls. */
function audiencePageSize(array $input, string $key='per_page', int $default=25): int
{
    $size=filter_var($input[$key]??$default,FILTER_VALIDATE_INT);
    return in_array($size,[10,25,50,100],true)?$size:$default;
}

/** Render the Connections pagination, preserving the caller's complete filter URL. */
function audiencePagination(array $options): void
{
    $total=max(0,(int)$options['total']);
    $size=audiencePageSize(['size'=>$options['size']??25],'size');
    $pages=max(1,(int)ceil($total/$size));
    $page=max(1,min($pages,(int)($options['page']??1)));
    $pageKey=$options['page_key']??'page';$sizeKey=$options['size_key']??'per_page';
    $builder=$options['url'];$link=static fn(array $changes=[])=>$builder(array_replace($options['params']??[],$changes));$base=$link([]);
    parse_str((string)(parse_url($base,PHP_URL_QUERY)??''),$params);
    unset($params[$pageKey],$params[$sizeKey],$params['page_size_change']);$params[$pageKey]=1;
    $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $inputs=static function(array $values,string $prefix='')use(&$inputs,$escape):void{
        foreach($values as$key=>$value){$name=$prefix===''?(string)$key:$prefix.'['.$key.']';
            if(is_array($value))$inputs($value,$name);
            elseif($value!==null)echo '<input type="hidden" name="'.$escape($name).'" value="'.$escape($value).'">';
        }
    };
    require __DIR__.'/templates/audience/list-pagination.php';
}
