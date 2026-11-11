<div class="audience-notice" role="alert" data-campaign-size-warning>
    <strong>Email may be clipped in Gmail</strong>
    <p>The personalized HTML sample is <?= number_format($messageSize['html_bytes']/1000,1) ?> KB. Gmail can hide content after about 102 KB, including footer links. Reduce the HTML below 100 KB and review again.</p>
    <a href="<?= $h($url(['view'=>'designer','id'=>$id])) ?>">Review Email Content</a>
</div>
