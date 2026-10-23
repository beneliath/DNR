<?php
declare(strict_types=1);

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
    unset($params[$pageKey],$params[$sizeKey]);$params[$pageKey]=1;
    $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $inputs=static function(array $values,string $prefix='')use(&$inputs,$escape):void{
        foreach($values as$key=>$value){$name=$prefix===''?(string)$key:$prefix.'['.$key.']';
            if(is_array($value))$inputs($value,$name);
            elseif($value!==null)echo '<input type="hidden" name="'.$escape($name).'" value="'.$escape($value).'">';
        }
    };
    require __DIR__.'/templates/audience/list-pagination.php';
}
