<?php
// Required: $editorId, $editorName, $editorLabel, $editorValue.
// $editorValue contains HTML. Include RichText.php first.
?>
<div class="g-rich" data-rich-editor>
    <label for="<?= eve_e($editorId) ?>"><?= eve_e($editorLabel) ?></label>
    <textarea id="<?= eve_e($editorId) ?>" name="<?= eve_e($editorName) ?>" rows="5" maxlength="60000" data-rich-source><?= eve_e($editorValue) ?></textarea>
    <p class="g-rich-help">Use the toolbar to format text. Pasted content is inserted as plain text.</p>
</div>
