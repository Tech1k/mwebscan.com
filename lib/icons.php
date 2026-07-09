<?php
// Inline SVG icons. Kept inline (not <img>) so they inherit currentColor and
// scale with font-size, adapting to the light/dark theme wherever they're used.

/**
 * The MWEB mark (Litecoin project asset), as an inline glyph that inherits the
 * surrounding text colour and size. Use next to "MWEB" references.
 *
 *   <?php echo mweb_icon(); ?> MWEB
 *   <h3><?php echo mweb_icon('mweb-icon', 'MWEB'); ?> Peg-in</h3>
 *
 * @param string $class extra CSS class(es) for sizing/spacing
 * @param string $label accessible label (aria-label)
 */
function mweb_icon($class = 'mweb-icon', $label = 'MWEB')
{
    $cls = htmlspecialchars($class, ENT_QUOTES);
    $lbl = htmlspecialchars($label, ENT_QUOTES);
    return '<svg class="' . $cls . '" xmlns="http://www.w3.org/2000/svg" '
        . 'viewBox="291 226 36 36" fill="none" stroke="currentColor" stroke-width="2.5" '
        . 'stroke-linecap="round" stroke-linejoin="round" width="1em" height="1em" '
        . 'role="img" aria-label="' . $lbl . '" '
        . 'style="vertical-align:-0.14em; margin-right:0.3em;">'
        . '<path d="M301.921903,248.666801 L305.974805,252.626918 C308.824073,255.196225 311.554034,255.205436 314.164687,252.65455 C316.77534,250.103664 316.765913,247.436203 314.136407,244.652164 L310.083505,240.692048"/>'
        . '<path d="M310.077921,240.697503 L314.130824,244.65762 C316.980092,247.226927 319.710052,247.236138 322.320705,244.685252 C324.931358,242.134367 324.921931,239.466905 322.292425,236.682867 L318.239523,232.72275"/>'
        . '<path d="M316.621397,238.778699 L312.568495,234.818582 C309.719227,232.249275 306.989266,232.240064 304.378613,234.79095 C301.76796,237.341836 301.777387,240.009297 304.406893,242.793336 L308.459795,246.753452"/>'
        . '<path d="M308.465379,246.747997 L304.412476,242.78788 C301.563208,240.218573 298.833248,240.209362 296.222595,242.760248 C293.611942,245.311133 293.621369,247.978595 296.250875,250.762633 L300.303777,254.72275"/>'
        . '</svg>';
}
