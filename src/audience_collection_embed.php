<?php
declare(strict_types=1);

function audienceCollectionEmbedDefaults(): array
{
    return ['show_title'=>true,'remove_css'=>false,'disable_javascript'=>false,'show_format'=>false,'show_archive'=>false,'badge'=>false,'badge_style'=>'light'];
}

function audienceCollectionEmbedOptions(mixed $input): array
{
    if (!is_array($input)) throw new InvalidArgumentException('Invalid embedded form settings.');
    $options=audienceCollectionEmbedDefaults();
    foreach ($options as $key=>$default) {
        if (!array_key_exists($key,$input)) continue;
        if ($key==='badge_style') {
            if (!in_array($input[$key],['light','dark'],true)) throw new InvalidArgumentException('Choose a light or dark badge.');
        } elseif (!is_bool($input[$key])) throw new InvalidArgumentException('Invalid embedded form option.');
        $options[$key]=$input[$key];
    }
    return $options;
}

/** Both installation methods use the published native form and its signed proof. */
function audienceCollectionEmbedCode(string $publicUrl,array $document): string
{
    $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $options=array_replace(audienceCollectionEmbedDefaults(),$document['embedded']??[]);
    if ($options['disable_javascript']) {
        return '<iframe src="'.$h($publicUrl).'" title="'.$h($document['title']).'" width="100%" height="720" frameborder="0" sandbox="allow-forms allow-same-origin allow-popups" referrerpolicy="origin"></iframe>';
    }
    return '<script src="'.$h($publicUrl.'&embed=1').'" defer></script>';
}
