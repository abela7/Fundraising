<?php

declare(strict_types=1);

/**
 * @param 'list'|'settings'|'first-message'|'send' $active
 */
function dvc_paying_nav(string $active): void
{
    $items = [
        'list' => ['href' => 'pledge-paying.php', 'label' => 'Donors'],
        'settings' => ['href' => 'pledge-paying-settings.php', 'label' => 'Settings'],
        'first-message' => ['href' => 'pledge-paying-first-message.php', 'label' => 'First message'],
        'send' => ['href' => 'pledge-paying-send.php', 'label' => 'Send'],
    ];
    echo '<nav class="dvc-paying-nav" aria-label="Still paying campaign">';
    foreach ($items as $key => $item) {
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $cls = $key === $active ? 'active' : '';
        echo '<a href="' . $href . '" class="' . $cls . '">' . $label . '</a>';
    }
    echo '</nav>';
}
