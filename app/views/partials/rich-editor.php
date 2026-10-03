<?php
// Required: $editorId, $editorName, $editorLabel, $editorValue.
// Optional: $editorFormat ('plain' or 'html'). Include RichText.php first.
$editorFormat = $editorFormat ?? 'plain';
?>
<div class="g-rich" data-rich-editor>
    <label for="<?= eve_e($editorId) ?>"><?= eve_e($editorLabel) ?></label>
    <textarea id="<?= eve_e($editorId) ?>" name="<?= eve_e($editorName) ?>" rows="5" maxlength="60000" data-rich-source data-format="<?= eve_e($editorFormat) ?>"><?= eve_e($editorValue) ?></textarea>
    <input type="hidden" name="<?= eve_e($editorName) ?>_format" value="<?= $editorFormat === 'html' ? 'html' : 'plain' ?>" data-rich-format>
    <p class="g-rich-help">Use the toolbar to format text. Pasted content is inserted as plain text.</p>
</div>
