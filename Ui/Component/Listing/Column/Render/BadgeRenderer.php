<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column\Render;

/**
 * Shared status-badge markup for OIDC admin grid columns.
 *
 * Used by ActiveStatus, OnlineStatus, PkceStatus and JwksStatus so
 * semantically equivalent states (active/inactive, online/offline,
 * configured/not set, ...) render with the same colors and markup instead of
 * each column hardcoding its own inline style.
 */
class BadgeRenderer
{
    private const DEFAULT_SYMBOLS = [
        'success' => '&#10003;',
        'warning' => '&#9888;',
        'neutral' => '&#8212;',
        'danger'  => '&#10007;',
    ];

    /**
     * @param string $state 'success'|'warning'|'neutral'|'danger'
     * @param string $label already-translated display text (no HTML)
     * @param string|null $symbol overrides the default symbol for $state (e.g. a bullet for on/off pairs)
     */
    public function render(string $state, string $label, ?string $symbol = null): string
    {
        $symbol ??= self::DEFAULT_SYMBOLS[$state] ?? '';

        return sprintf(
            '<span class="m2oidc-badge m2oidc-badge--%s">%s %s</span>',
            $state,
            $symbol,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
}
