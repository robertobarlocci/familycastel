<?php

/**
 * The dark cloud that rains for ~2.5 seconds when the child lost Coins while
 * they were away — the negative counterpart to the confetti.
 *
 * Everything about it is CSS (see kid.css): no image, no inline style (the CSP
 * ships style-src 'self' with no 'unsafe-inline'), and no per-element JS
 * styling. The cloud is drawn from a rounded box plus two lobes rather than an
 * emoji, because ☁️/🌧️ render as a LIGHT cloud on most platforms and the whole
 * point is that this one is dark.
 *
 * The drop count is fixed at twelve so the stagger can live in :nth-child()
 * rules. `aria-hidden` and `pointer-events: none` throughout: it is pure
 * decoration and must never intercept a tap, even in the impossible case where
 * both of its teardowns fail.
 */

declare(strict_types=1);

?>
<div class="rain-fx" aria-hidden="true">
    <span class="rain-cloud"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
    <span class="rain-drop"></span>
</div>
