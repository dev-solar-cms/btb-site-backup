<?php
/**
 * Liste des avertissements, regroupés par gravité puis par type, avec explication et action conseillée.
 *
 * @var array $warnings [[niveau, code, chemin], …]
 * @var array $counts   [niveau => nombre]
 */

use BTB\SiteBackup\Config;
use BTB\SiteBackup\Support\Warnings;

defined('ABSPATH') || exit;

if (!$warnings) {
    return;
}
$groups = Warnings::group($warnings);
?>
<div class="btb-warnings">
    <?php foreach (Config::LEVELS as $lvl => $level) :
        if (empty($groups[$lvl])) {
            continue;
        }
        foreach ($groups[$lvl] as $code => $paths) :
            $def = Config::CODES[$code] ?? ['moyen', $code, '', '']; ?>
            <div class="btb-wgroup" style="border-left-color:<?php echo esc_attr($level[1]); ?>">
                <p>
                    <span class="btb-level" style="background:<?php echo esc_attr($level[1]); ?>"><?php echo esc_html($level[0]); ?></span>
                    <strong><?php echo esc_html($def[1]); ?></strong> — <?php echo (int) count($paths); ?> élément(s)
                </p>
                <p><?php echo esc_html($def[2]); ?><br><em>À faire : <?php echo esc_html($def[3]); ?></em></p>
                <details>
                    <summary>Voir la liste</summary>
                    <ul class="btb-paths">
                        <?php foreach ($paths as $path) : ?>
                            <li><code><?php echo esc_html($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
        <?php endforeach;
    endforeach; ?>

    <?php $total = array_sum($counts);
    if ($total > count($warnings)) : ?>
        <p class="description"><?php echo (int) ($total - count($warnings)); ?> autre(s) avertissement(s) non détaillé(s).</p>
    <?php endif; ?>
</div>
