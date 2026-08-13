<?php
/**
 * Money-based donor groups for the WhatsApp verification campaign.
 *
 * Groups follow actual pledged/paid/balance, not stored payment_status.
 */

declare(strict_types=1);

class DonorCampaignGroups
{
    public const IMMEDIATE = 'immediate';
    public const PLEDGE_COMPLETED = 'pledge_completed';
    public const PLEDGE_PAYING = 'pledge_paying';
    public const PLEDGE_NOT_STARTED = 'pledge_not_started';
    public const UNCLASSIFIED = 'unclassified';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::IMMEDIATE,
            self::PLEDGE_COMPLETED,
            self::PLEDGE_PAYING,
            self::PLEDGE_NOT_STARTED,
            self::UNCLASSIFIED,
        ];
    }

    public static function isValid(string $group): bool
    {
        return in_array($group, self::all(), true);
    }

    /**
     * SQL CASE that classifies a donors-table alias.
     */
    public static function sqlCase(string $alias = 'd'): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'd';

        return "
            CASE
                WHEN {$alias}.donor_type = 'immediate_payment'
                  OR ({$alias}.total_pledged <= 0.01 AND {$alias}.total_paid > 0.01)
                    THEN 'immediate'
                WHEN {$alias}.total_pledged > 0.01
                 AND {$alias}.total_paid > 0.01
                 AND {$alias}.balance <= 0.01
                    THEN 'pledge_completed'
                WHEN {$alias}.total_pledged > 0.01
                 AND {$alias}.total_paid > 0.01
                 AND {$alias}.balance > 0.01
                    THEN 'pledge_paying'
                WHEN {$alias}.total_pledged > 0.01
                 AND {$alias}.total_paid <= 0.01
                    THEN 'pledge_not_started'
                ELSE 'unclassified'
            END
        ";
    }
}
