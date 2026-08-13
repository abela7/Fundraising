<?php

declare(strict_types=1);

/**
 * @return array<string, array<string, mixed>>
 */
function dvc_campaign_group_catalog(): array
{
    return [
        'immediate' => [
            'group' => 'immediate',
            'file' => 'immediate.php',
            'title' => 'Immediate payers',
            'short' => 'Immediate payers',
            'description' => 'Donors who paid on the spot, with no pledge.',
            'icon' => 'fa-bolt',
            'tone' => 'immediate',
            'family' => 'other',
            'amount_key' => 'paid',
            'amount_label' => 'Total amount',
            'sort_by' => 'paid',
            'sort_order' => 'desc',
        ],
        'pledge_completed' => [
            'group' => 'pledge_completed',
            'file' => 'pledge-completed.php',
            'title' => 'Pledge donors — Completed',
            'short' => 'Completed',
            'description' => 'Donors who pledged and have paid in full.',
            'icon' => 'fa-flag-checkered',
            'tone' => 'completed',
            'family' => 'pledge',
            'amount_key' => 'paid',
            'amount_label' => 'Total amount',
            'sort_by' => 'paid',
            'sort_order' => 'desc',
        ],
        'pledge_paying' => [
            'group' => 'pledge_paying',
            'file' => 'pledge-paying.php',
            'title' => 'Pledge donors — Still paying',
            'short' => 'Still paying',
            'description' => 'Donors who pledged and have started paying.',
            'icon' => 'fa-person-walking',
            'tone' => 'paying',
            'family' => 'pledge',
            'amount_key' => 'remaining',
            'amount_label' => 'Total amount remaining',
            'sort_by' => 'balance',
            'sort_order' => 'desc',
        ],
        'pledge_not_started' => [
            'group' => 'pledge_not_started',
            'file' => 'pledge-not-started.php',
            'title' => 'Pledge donors — Not started',
            'short' => 'Not started',
            'description' => 'Donors who pledged but have not paid yet.',
            'icon' => 'fa-hourglass-start',
            'tone' => 'not-started',
            'family' => 'pledge',
            'amount_key' => 'pledged',
            'amount_label' => 'Total amount pledged',
            'sort_by' => 'pledged',
            'sort_order' => 'desc',
        ],
        'unclassified' => [
            'group' => 'unclassified',
            'file' => 'needs-review.php',
            'title' => 'Needs review',
            'short' => 'Needs review',
            'description' => 'Donors with no pledge and no payment.',
            'icon' => 'fa-clipboard-check',
            'tone' => 'review',
            'family' => 'other',
            'amount_key' => 'paid',
            'amount_label' => 'Total amount',
            'sort_by' => 'name',
            'sort_order' => 'asc',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function dvc_campaign_group_meta(string $group): array
{
    $catalog = dvc_campaign_group_catalog();

    return $catalog[$group] ?? $catalog['pledge_not_started'];
}

/**
 * @return list<array<string, mixed>>
 */
function dvc_pledge_group_nav(): array
{
    $catalog = dvc_campaign_group_catalog();

    return [
        $catalog['pledge_completed'],
        $catalog['pledge_paying'],
        $catalog['pledge_not_started'],
    ];
}
