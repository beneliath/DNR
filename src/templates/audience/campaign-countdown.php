<?php if($campaignCountdownAt instanceof DateTimeInterface): ?>
<span class="campaign-countdown" data-campaign-countdown="<?= $h($campaignCountdownAt->format('c')) ?>" role="timer" aria-live="off" hidden>
    <span class="campaign-countdown-label" data-campaign-countdown-label>Sends in</span>
    <span class="campaign-countdown-values" aria-hidden="true"><?php foreach(['days','hours','minutes','seconds']as$unit): ?><span data-campaign-countdown-value>00</span><?php endforeach; ?></span>
    <span class="campaign-countdown-units" aria-hidden="true"><?php foreach(['days','hours','minutes','seconds']as$unit): ?><span><?= $unit ?></span><?php endforeach; ?></span>
</span>
<?php endif; ?>
