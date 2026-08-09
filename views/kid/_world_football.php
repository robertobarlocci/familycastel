<?php
/**
 * Football world scene — original layered SVG. Growth: tier 1 street pitch →
 * 2 training ground → 3 small stadium → 4 big stadium → 5 championship arena.
 * @var int $tier 1..5   @var string $characterEmoji
 */
?>
<svg class="world-scene" viewBox="0 0 800 420" role="img" preserveAspectRatio="xMidYMax slice"
     aria-label="<?= eattr(t('world.football_alt', ['tier' => (string) $tier])) ?>">
    <defs>
        <linearGradient id="bSky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#0d3b52"/>
            <stop offset=".6" stop-color="#1d6a7a"/>
            <stop offset="1" stop-color="#7fc7a1"/>
        </linearGradient>
        <linearGradient id="bStand" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#e6edf5"/>
            <stop offset="1" stop-color="#aebdd1"/>
        </linearGradient>
    </defs>

    <rect width="800" height="420" fill="url(#bSky)"/>
    <circle cx="150" cy="80" r="30" fill="#ffe9b3" opacity=".9"/>
    <g fill="#ffffff" opacity=".45">
        <ellipse cx="560" cy="70" rx="44" ry="13"/><ellipse cx="600" cy="62" rx="28" ry="10"/>
    </g>

    <!-- skyline silhouette -->
    <path d="M0 250 L60 250 L60 210 L110 210 L110 240 L170 240 L170 190 L220 190 L220 245 L800 245 L800 420 L0 420 Z"
          fill="#123043" opacity=".7"/>

    <?php if ($tier >= 3): ?>
        <!-- stadium bowl (grows with tier) -->
        <g>
            <path d="M120 260 Q400 <?= $tier >= 4 ? 150 : 190 ?> 680 260 L680 300 L120 300 Z" fill="url(#bStand)"/>
            <path d="M140 262 Q400 <?= $tier >= 4 ? 170 : 205 ?> 660 262 L660 292 L140 292 Z" fill="#5b7ca3"/>
            <!-- fans -->
            <g fill="#f6b93b" opacity=".9">
                <circle cx="240" cy="272" r="4"/><circle cx="280" cy="266" r="4"/><circle cx="330" cy="262" r="4"/>
                <circle cx="400" cy="258" r="4"/><circle cx="470" cy="262" r="4"/><circle cx="520" cy="266" r="4"/>
                <circle cx="560" cy="272" r="4"/>
            </g>
            <?php if ($tier >= 4): ?>
                <g fill="#e05260" opacity=".9">
                    <circle cx="260" cy="252" r="4"/><circle cx="310" cy="246" r="4"/><circle cx="360" cy="242" r="4"/>
                    <circle cx="440" cy="242" r="4"/><circle cx="490" cy="246" r="4"/><circle cx="540" cy="252" r="4"/>
                </g>
            <?php endif; ?>
        </g>
    <?php endif; ?>

    <?php if ($tier >= 2): ?>
        <!-- floodlights -->
        <g stroke="#d7e3f0" stroke-width="6" fill="#ffe9b3">
            <line x1="150" y1="290" x2="150" y2="170"/>
            <line x1="650" y1="290" x2="650" y2="170"/>
            <rect x="128" y="150" width="44" height="18" rx="4" stroke="none"/>
            <rect x="628" y="150" width="44" height="18" rx="4" stroke="none"/>
        </g>
    <?php endif; ?>

    <?php if ($tier >= 5): ?>
        <!-- championship: roof arc, trophy, fireworks -->
        <g>
            <path d="M100 250 Q400 96 700 250" fill="none" stroke="#f6b93b" stroke-width="10" stroke-linecap="round"/>
            <g transform="translate(400 128)">
                <path d="M-14 0 h28 v8 q0 14 -14 16 q-14 -2 -14 -16 Z" fill="#f6b93b"/>
                <rect x="-5" y="22" width="10" height="8" fill="#c98d10"/>
                <rect x="-10" y="30" width="20" height="5" rx="2" fill="#c98d10"/>
            </g>
            <g stroke="#ff7ab2" stroke-width="3" stroke-linecap="round">
                <path d="M240 110 l0 -14 M233 103 l-10 -10 M247 103 l10 -10"/>
            </g>
            <g stroke="#9be564" stroke-width="3" stroke-linecap="round">
                <path d="M580 96 l0 -14 M573 89 l-10 -10 M587 89 l10 -10"/>
            </g>
        </g>
    <?php endif; ?>

    <!-- pitch -->
    <path d="M0 300 L800 300 L800 420 L0 420 Z" fill="#2e8b57"/>
    <g fill="#37a065">
        <rect x="0" y="316" width="800" height="16"/><rect x="0" y="352" width="800" height="16"/><rect x="0" y="388" width="800" height="16"/>
    </g>
    <g stroke="#ffffff" stroke-width="3" fill="none" opacity=".9">
        <ellipse cx="400" cy="360" rx="70" ry="22"/>
        <line x1="400" y1="300" x2="400" y2="420"/>
    </g>

    <!-- goal -->
    <g stroke="#ffffff" stroke-width="4" fill="none">
        <path d="M600 372 L600 322 L720 322 L720 372"/>
        <path d="M604 368 L716 368" stroke-width="2" opacity=".6"/>
        <path d="M612 326 L612 366 M628 326 L628 366 M644 324 L644 366 M660 324 L660 366 M676 324 L676 366 M692 324 L692 366 M708 324 L708 366"
              stroke-width="1.5" opacity=".55"/>
    </g>

    <?php if ($tier === 1): ?>
        <!-- street pitch: cone markers -->
        <g fill="#ff9d3b">
            <path d="M180 388 L190 368 L200 388 Z"/><path d="M300 400 L310 380 L320 400 Z"/>
        </g>
    <?php endif; ?>

    <!-- ball + player -->
    <circle cx="452" cy="392" r="12" fill="#ffffff"/>
    <path d="M452 380 l6 8 -2 9 -8 0 -2 -9 Z" fill="#20303c" opacity=".85"/>
    <text x="400" y="398" text-anchor="middle" font-size="52" aria-hidden="true"><?= e($characterEmoji) ?></text>
</svg>
