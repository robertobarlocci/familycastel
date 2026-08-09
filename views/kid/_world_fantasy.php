<?php
/**
 * Fantasy world scene — original layered SVG. Server-rendered growth:
 * tier 1 camp → 2 village → 3 castle → 4 kingdom → 5 epic kingdom.
 * @var int $tier 1..5   @var string $characterEmoji
 */
?>
<svg class="world-scene" viewBox="0 0 800 420" role="img" preserveAspectRatio="xMidYMax slice"
     aria-label="<?= eattr(t('world.fantasy_alt', ['tier' => (string) $tier])) ?>">
    <defs>
        <linearGradient id="fSky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#2b2f6b"/>
            <stop offset=".55" stop-color="#7a4ea3"/>
            <stop offset="1" stop-color="#f0a06a"/>
        </linearGradient>
        <linearGradient id="fKeep" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#e8e3f2"/>
            <stop offset="1" stop-color="#b9aed1"/>
        </linearGradient>
    </defs>

    <rect width="800" height="420" fill="url(#fSky)"/>
    <circle cx="640" cy="90" r="34" fill="#ffe9b3" opacity=".95"/>
    <circle cx="620" cy="82" r="34" fill="url(#fSky)" opacity=".55"/>
    <g fill="#ffffff" opacity=".5">
        <ellipse cx="180" cy="70" rx="46" ry="14"/><ellipse cx="215" cy="62" rx="30" ry="11"/>
        <ellipse cx="470" cy="120" rx="38" ry="12"/>
    </g>

    <!-- far mountains (atmospheric silhouettes) -->
    <path d="M0 260 L110 150 L200 240 L290 130 L390 250 L800 250 L800 420 L0 420 Z" fill="#4a3f7a" opacity=".55"/>
    <path d="M300 260 L430 170 L540 255 L640 160 L760 260 L800 260 L800 420 L0 420 Z" fill="#3c3468" opacity=".75"/>

    <!-- mid hills -->
    <path d="M0 300 Q140 250 300 298 T620 296 T800 292 L800 420 L0 420 Z" fill="#2f5d46"/>

    <?php if ($tier === 1): ?>
        <!-- camp: tent + fire -->
        <path d="M330 320 L380 250 L430 320 Z" fill="#c96f3a"/>
        <path d="M368 320 L380 262 L392 320 Z" fill="#8a4526"/>
        <g>
            <circle cx="470" cy="316" r="7" fill="#ff9d3b"/>
            <path d="M463 318 Q470 296 477 318 Z" fill="#ffc23b"/>
            <rect x="452" y="318" width="36" height="5" rx="2" fill="#6d4a2f"/>
        </g>
    <?php endif; ?>

    <?php if ($tier === 2): ?>
        <!-- village: three huts -->
        <g>
            <rect x="300" y="286" width="56" height="38" rx="3" fill="#d9c9a3"/>
            <path d="M294 288 L328 258 L362 288 Z" fill="#a0522d"/>
            <rect x="322" y="302" width="14" height="22" fill="#6d4a2f"/>
        </g>
        <g>
            <rect x="392" y="292" width="48" height="32" rx="3" fill="#d9c9a3"/>
            <path d="M386 294 L416 268 L446 294 Z" fill="#a0522d"/>
        </g>
        <g>
            <rect x="470" y="296" width="44" height="28" rx="3" fill="#d9c9a3"/>
            <path d="M465 298 L492 274 L519 298 Z" fill="#a0522d"/>
        </g>
    <?php endif; ?>

    <?php if ($tier >= 3): ?>
        <!-- castle core: keep + two towers -->
        <g>
            <rect x="350" y="220" width="100" height="104" fill="url(#fKeep)"/>
            <rect x="350" y="212" width="100" height="12" fill="#9c8fc0"/>
            <rect x="352" y="204" width="12" height="10" fill="#9c8fc0"/><rect x="376" y="204" width="12" height="10" fill="#9c8fc0"/>
            <rect x="400" y="204" width="12" height="10" fill="#9c8fc0"/><rect x="424" y="204" width="12" height="10" fill="#9c8fc0"/>
            <path d="M384 324 L384 284 Q400 268 416 284 L416 324 Z" fill="#5b4a86"/>
            <rect x="318" y="240" width="34" height="84" fill="#cfc3e6"/>
            <path d="M314 240 L335 208 L356 240 Z" fill="#7a5aa8"/>
            <rect x="448" y="240" width="34" height="84" fill="#cfc3e6"/>
            <path d="M444 240 L465 208 L486 240 Z" fill="#7a5aa8"/>
        </g>
    <?php endif; ?>

    <?php if ($tier >= 4): ?>
        <!-- kingdom: outer walls + gate towers + flags -->
        <g>
            <rect x="230" y="276" width="90" height="48" fill="#b9aed1"/>
            <rect x="480" y="276" width="90" height="48" fill="#b9aed1"/>
            <rect x="226" y="268" width="98" height="10" fill="#9c8fc0"/>
            <rect x="476" y="268" width="98" height="10" fill="#9c8fc0"/>
            <rect x="212" y="232" width="26" height="92" fill="#cfc3e6"/>
            <path d="M208 232 L225 204 L242 232 Z" fill="#7a5aa8"/>
            <rect x="562" y="232" width="26" height="92" fill="#cfc3e6"/>
            <path d="M558 232 L575 204 L592 232 Z" fill="#7a5aa8"/>
            <path d="M335 208 L335 190 L360 197 L335 204 Z" fill="#f6b93b"/>
            <path d="M465 208 L465 190 L490 197 L465 204 Z" fill="#f6b93b"/>
        </g>
    <?php endif; ?>

    <?php if ($tier >= 5): ?>
        <!-- epic: grand spire, royal banners, guardian dragon silhouette -->
        <g>
            <rect x="390" y="150" width="20" height="70" fill="#cfc3e6"/>
            <path d="M384 150 L400 116 L416 150 Z" fill="#f6b93b"/>
            <path d="M400 116 L400 96 L426 104 L400 112 Z" fill="#e05260"/>
            <path d="M225 204 L225 186 L248 193 L225 200 Z" fill="#e05260"/>
            <path d="M575 204 L575 186 L598 193 L575 200 Z" fill="#e05260"/>
            <path d="M120 110 q18 -18 36 0 q14 -26 34 -6 q26 -10 30 12 q-34 8 -50 2 q-16 12 -34 4 q-14 2 -16 -12 Z"
                  fill="#241f45" opacity=".85"/>
        </g>
    <?php endif; ?>

    <!-- foreground meadow + path -->
    <path d="M0 324 Q200 300 420 322 T800 320 L800 420 L0 420 Z" fill="#3e7d54"/>
    <path d="M370 420 Q392 370 400 324 Q408 370 430 420 Z" fill="#d9c9a3" opacity=".85"/>
    <g fill="#69a97c">
        <ellipse cx="150" cy="356" rx="26" ry="8"/><ellipse cx="660" cy="366" rx="30" ry="9"/>
    </g>

    <!-- hero -->
    <text x="400" y="392" text-anchor="middle" font-size="52" aria-hidden="true"><?= e($characterEmoji) ?></text>
</svg>
