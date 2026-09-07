<?php
$custom_link = $custom_link ?? [];
$custom_value = static fn(string $field): string => htmlspecialchars(is_string($custom_link[$field] ?? null) ? $custom_link[$field] : '', ENT_QUOTES, 'UTF-8');
?>
<div class="speaker-custom-link-row" data-custom-link-row>
    <input type="hidden" name="custom_links[<?php echo $custom_index; ?>][key]" value="<?php echo $custom_value('key'); ?>" data-custom-link-key>
    <div class="form-group">
        <label for="custom_link_<?php echo $custom_index; ?>_label">Link Name</label>
        <input type="text" id="custom_link_<?php echo $custom_index; ?>_label" name="custom_links[<?php echo $custom_index; ?>][label]" maxlength="255" placeholder="e.g. Video Channel" value="<?php echo $custom_value('label'); ?>" data-custom-link-label>
    </div>
    <div class="form-group">
        <label for="custom_link_<?php echo $custom_index; ?>_url">URL</label>
        <input type="url" id="custom_link_<?php echo $custom_index; ?>_url" name="custom_links[<?php echo $custom_index; ?>][url]" maxlength="<?php echo SPEAKER_URL_MAX_LENGTH; ?>" placeholder="https://example.com" value="<?php echo $custom_value('url'); ?>" aria-describedby="speaker-custom-links-help" data-custom-link-url>
    </div>
    <button type="button" class="button-secondary" aria-label="Remove custom link" data-remove-custom-link>Remove</button>
</div>
