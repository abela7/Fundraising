<?php

declare(strict_types=1);

$dvcCsrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$dvcDefaultMessage = CampaignGroupSettings::defaultFirstMessage();
?>
<div class="dvc-settings-card animate-fade-in" id="dvcFirstMessage">
    <div class="dvc-settings-head">
        <div>
            <h6><i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>First WhatsApp message</h6>
            <p>Write the hello message for still-paying pledge donors. Sending comes next — this step only saves the text and who should receive it.</p>
        </div>
    </div>
    <div class="dvc-settings-body">
        <div id="dvcMsgFlash" class="alert d-none" role="status"></div>
        <div class="dvc-var-row">
            <span class="dvc-var-label">Insert variable</span>
            <div class="dvc-var-btns">
                <button type="button" class="dvc-var-btn" data-token="{name}">Name</button>
                <button type="button" class="dvc-var-btn" data-token="{pledge_amount}">Pledge amount</button>
                <button type="button" class="dvc-var-btn" data-token="{total_paid}">Total paid</button>
                <button type="button" class="dvc-var-btn" data-token="{remaining_amount}">Remaining amount</button>
            </div>
        </div>
        <label class="form-label" for="dvcFirstMessageBody">Message</label>
        <textarea class="form-control dvc-am-text" id="dvcFirstMessageBody" rows="6" maxlength="4000" lang="am" dir="auto" placeholder="<?php echo htmlspecialchars($dvcDefaultMessage, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dvcDefaultMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="dvc-msg-meta">
            <span id="dvcMsgCount">0 / 4000</span>
            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetMessage">Reset to default</button>
        </div>
        <div class="dvc-preview">
            <div class="dvc-preview-label">Preview</div>
            <div class="dvc-preview-bubble dvc-am-text" id="dvcMsgPreview"></div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-primary" id="dvcSaveMessage">
                <i class="fas fa-save me-1"></i>Save first message
            </button>
            <span class="dvc-muted align-self-center">Not sent yet.</span>
        </div>
    </div>
</div>

<div class="dvc-settings-card animate-fade-in">
    <div class="dvc-settings-head">
        <div>
            <h6><i class="fas fa-user-check me-2" style="color: var(--primary);"></i>Who receives this message</h6>
            <p>Only pledge donors who are still paying. Choose everyone in this group, or pick people in the table below.</p>
        </div>
        <div class="dvc-select-count" id="dvcSelectCount">All still-paying donors</div>
    </div>
    <div class="dvc-settings-body">
        <div class="dvc-mode-row">
            <label class="dvc-mode-option">
                <input type="radio" name="dvcRecipientMode" id="dvcModeAll" value="all" checked>
                <span>
                    <strong>All still-paying donors</strong>
                    <small>Everyone in this group, including people not on this page.</small>
                </span>
            </label>
            <label class="dvc-mode-option">
                <input type="radio" name="dvcRecipientMode" id="dvcModeSelected" value="selected">
                <span>
                    <strong>Choose people</strong>
                    <small>Tick names in the table. Use Select page to tick everyone visible.</small>
                </span>
            </label>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="dvcSelectPage">Select this page</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="dvcClearSelected">Clear ticks</button>
            <button type="button" class="btn btn-primary btn-sm" id="dvcSaveRecipients">
                <i class="fas fa-save me-1"></i>Save recipients
            </button>
        </div>
        <input type="hidden" id="dvcCsrf" value="<?php echo $dvcCsrf; ?>">
    </div>
</div>
