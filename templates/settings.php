<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $s @var array $enabled_types @var array $public_types @var array $tabs @var string $tab */

/**
 * One setting row: label (+ optional "?" help bubble) on the left,
 * control on the right.
 */
if (!function_exists('slk_row')) :
function slk_row($label, $desc, $control, $wide = false, $help = '')
{
    echo '<div class="slk-row">';
    echo '<div class="slk-row-main"><div class="slk-row-label">' . esc_html($label);
    // $help may be a string or an array of paragraphs.
    if (!empty($help)) {
        echo Slk_Admin::help($help);
    }
    echo '</div>';
    if ($desc !== '') {
        echo '<div class="slk-row-desc">' . esc_html($desc) . '</div>';
    }
    echo '</div>';
    echo '<div class="slk-row-control' . ($wide ? ' slk-wide' : '') . '">' . $control . '</div>';
    echo '</div>';
}
endif;

$base = admin_url('admin.php?page=smartlinker_settings');
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Settings', 'smartlinker'); ?></h1>

    <?php
    /*
     * Every tab is rendered up front and switched in the browser, so clicking
     * one costs nothing at all — no request, no spinner. That is only
     * defensible because these panels are cheap: the most expensive thing on
     * any of them is the AI processing count at under 3ms. Do NOT copy this to
     * the Reports tabs, where each one runs real queries and rendering all
     * eight would make the first load slower than the clicks it saves.
     */
    ?>
    <div class="slk-tabs slk-tabs-underline slk-settings-tabs">
        <?php foreach ($tabs as $key => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $key, $base)); ?>"
               data-slk-tab="<?php echo esc_attr($key); ?>"
               class="slk-tab<?php echo $tab === $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <?php
    $msg = isset($_GET['ai_msg']) ? sanitize_key($_GET['ai_msg']) : '';
    if ($msg === 'disconnected') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('AI disconnected — your API key has been removed.', 'smartlinker'); ?></p></div>
    <?php elseif ($msg === 'cache') : ?>
        <div class="notice notice-success is-dismissible"><p><?php /* translators: %d: number of posts cleared */ printf(esc_html__('Cleared cached AI results for %d posts.', 'smartlinker'), (int) ($_GET['n'] ?? 0)); ?></p></div>
    <?php elseif ($msg === 'errors') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Error log cleared.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <?php foreach (array_keys($tabs) as $panel) : ?>
    <div class="slk-tabpanel" data-slk-panel="<?php echo esc_attr($panel); ?>"<?php echo $panel === $tab ? '' : ' hidden'; ?>>
    <form method="post" action="<?php echo esc_url(add_query_arg('tab', $panel, $base)); ?>">
        <?php wp_nonce_field('slk_save_settings', 'slk_settings_nonce'); ?>
        <input type="hidden" name="slk_tab" value="<?php echo esc_attr($panel); ?>" />

        <?php if ($panel === 'general') : ?>

            <div class="slk-section-title"><?php esc_html_e('Content', 'smartlinker'); ?></div>
            <div class="slk-row">
                <div class="slk-row-main">
                    <div class="slk-row-label"><?php esc_html_e('Post types', 'smartlinker'); ?></div>
                    <div class="slk-row-desc"><?php esc_html_e('Which content SmartLinker analyzes and suggests links for.', 'smartlinker'); ?></div>
                </div>
            </div>
            <div class="slk-toggle-grid">
                <?php foreach ($public_types as $pt) :
                    if (in_array($pt->name, ['attachment'], true)) {
                        continue;
                    } ?>
                    <div class="slk-row">
                        <div class="slk-row-main"><div class="slk-row-label" style="font-weight:500;"><?php echo esc_html($pt->labels->name); ?></div></div>
                        <div class="slk-row-control">
                            <?php echo Slk_Admin::toggle('slk[post_types][]', in_array($pt->name, $enabled_types, true), $pt->name); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="slk-section-title"><?php esc_html_e('Suggestions', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Suggestions per post', 'smartlinker'), __('Maximum number of suggestions shown in the editor.', 'smartlinker'),
                '<input type="number" min="1" max="100" name="slk[suggestion_limit]" value="' . esc_attr($s['suggestion_limit']) . '" />');
            slk_row(__('Minimum keyword length', 'smartlinker'), __('Ignore phrases shorter than this many characters.', 'smartlinker'),
                '<input type="number" min="2" max="20" name="slk[min_keyword_length]" value="' . esc_attr($s['min_keyword_length']) . '" />',
                false,
                __('Stops very short words from becoming links. Raise it if you get noisy suggestions like "the" or "SEO"; lower it if you use short brand names.', 'smartlinker'));
            slk_row(__('Match word variants', 'smartlinker'), __('Match plurals and verb tenses (stemming) when finding suggestions.', 'smartlinker'),
                Slk_Admin::toggle('slk[use_stemming]', !empty($s['use_stemming'])), false,
                [
                    __('Matches different forms of the same word when looking for linking opportunities.', 'smartlinker'),
                    __('With this on, a page titled "Coffee Grinder" is still suggested where your text says "coffee grinders", and "running" matches "run". Plurals and verb tenses are treated as the same word.', 'smartlinker'),
                    __('Switch it off if you only ever want exact wording to match. You will get noticeably fewer suggestions.', 'smartlinker'),
                ]);
            slk_row(__('Link to taxonomy pages', 'smartlinker'), __('Also suggest links to category and tag archive pages.', 'smartlinker'),
                Slk_Admin::toggle('slk[link_taxonomies]', !empty($s['link_taxonomies'])), false,
                __('Lets SmartLinker suggest links to category and tag archives, not just posts and pages. Useful for topic hubs; turn off if your archives are thin.', 'smartlinker'));
            ?>

            <div class="slk-section-title"><?php esc_html_e('Inserted links', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Open in new tab', 'smartlinker'), __('Add target="_blank" to links SmartLinker inserts.', 'smartlinker'),
                Slk_Admin::toggle('slk[links_open_new_tab]', !empty($s['links_open_new_tab'])));
            slk_row(__('Nofollow', 'smartlinker'), __('Add rel="nofollow" to inserted links.', 'smartlinker'),
                Slk_Admin::toggle('slk[links_nofollow]', !empty($s['links_nofollow'])), false,
                __('Tells search engines not to pass ranking value through these links. Normally leave this OFF for internal links — passing value between your own pages is the point.', 'smartlinker'));
            slk_row(__('Auto-linking', 'smartlinker'), __('Apply your auto-link keyword rules on the frontend.', 'smartlinker'),
                Slk_Admin::toggle('slk[autolink_enabled]', !empty($s['autolink_enabled'])), false,
                [
                    __('Applies your keyword rules from the Auto-Linking page to your content.', 'smartlinker'),
                    __('Auto-links are added while a page is being displayed and are never written into your stored posts. That means switching this off removes every auto-link across the site instantly, with no cleanup needed.', 'smartlinker'),
                    __('This is different from suggestions, which insert a real link into the post content and stay there permanently once you accept them.', 'smartlinker'),
                ]);
            slk_row(__('Track clicks', 'smartlinker'), __('Record clicks on internal links for the reports.', 'smartlinker'),
                Slk_Admin::toggle('slk[track_clicks]', !empty($s['track_clicks'])), false,
                __('Records a row in your database each time a visitor clicks an internal link SmartLinker added. Powers the Link Clicks report. No personal data is stored.', 'smartlinker'));
            ?>

            <?php
            slk_row(__('Favour pages that need links', 'smartlinker'), __('Promote starved pages when two suggestions are equally good.', 'smartlinker'),
                '<input type="number" min="0" max="0.5" step="0.05" name="slk[equity_boost]" value="' . esc_attr($s['equity_boost']) . '" />',
                false,
                [
                    __('SmartLinker knows which of your pages barely any links point at (Reports → Link Equity). When two candidates are about equally relevant, this tips the order towards the one that actually needs the link.', 'smartlinker'),
                    __('It changes the ORDER only, never the confidence figure you see. Confidence answers "is this the right link" — that is a claim about correctness, and it stays honest. A neglected page is not a better match, it is a more useful one.', 'smartlinker'),
                    __('0.15 lets a starved page overtake a rival scoring up to 15% higher, which reorders near-equals without ever lifting a poor match above a good one. Set to 0 to switch off.', 'smartlinker'),
                ]);
            ?>

            <div class="slk-section-title"><?php esc_html_e('Learning from rejections', 'smartlinker'); ?></div>
            <p class="description" style="margin:-4px 0 10px;">
                <?php esc_html_e('When you reject a suggestion, SmartLinker remembers. Reject the same thing on enough different posts and it stops being offered anywhere, so you do not have to turn down the same bad anchor once per post forever.', 'smartlinker'); ?>
                <?php
                printf(
                    ' <a href="%s">%s</a>',
                    esc_url(Slk_Reports::url('anchors', ['show' => 'suppressed'])),
                    esc_html__('See what is currently suppressed', 'smartlinker')
                );
                ?>
            </p>
            <?php
            slk_row(__('Retire an anchor + destination after', 'smartlinker'), __('Distinct posts that must reject the same pairing.', 'smartlinker'),
                '<input type="number" min="0" max="50" name="slk[reject_pair_threshold]" value="' . esc_attr($s['reject_pair_threshold']) . '" /> '
                . '<span class="description">' . esc_html__('posts', 'smartlinker') . '</span>',
                false,
                [
                    __('The narrow rule: it only stops that exact anchor pointing at that exact page. The same words pointing somewhere else are unaffected.', 'smartlinker'),
                    __('Rejections are counted per post, not per click — turning the same suggestion down three times on one post is one opinion, not three.', 'smartlinker'),
                    __('Set to 0 to switch this off.', 'smartlinker'),
                ]);

            slk_row(__('Retire an anchor everywhere after', 'smartlinker'), __('Distinct posts that must reject the same anchor, whatever it pointed at.', 'smartlinker'),
                '<input type="number" min="0" max="50" name="slk[reject_anchor_threshold]" value="' . esc_attr($s['reject_anchor_threshold']) . '" /> '
                . '<span class="description">' . esc_html__('posts', 'smartlinker') . '</span>',
                false,
                [
                    __('The broad rule: when you keep rejecting the same words no matter where they point, the words themselves are the problem, and the anchor is retired site-wide.', 'smartlinker'),
                    __('Raise it on a large site, where three rejections is a smaller share of your content and may not mean much. Lower it if you want the engine to take the hint faster.', 'smartlinker'),
                    __('Set to 0 to switch this off. Nothing already learned is lost — it simply stops being applied.', 'smartlinker'),
                ]);
            ?>

            <div class="slk-section-title"><?php esc_html_e('Removing the plugin', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Delete all data if the plugin is deleted', 'smartlinker'), __('Off by default. Deactivating never deletes anything either way.', 'smartlinker'),
                Slk_Admin::toggle('slk[delete_data_on_uninstall]', !empty($s['delete_data_on_uninstall'])),
                false,
                [
                    __('Leave this OFF unless you are sure. People delete a plugin to reinstall it, to move hosts, or to test a conflict — and with this on, any of those wipes work you cannot get back.', 'smartlinker'),
                    __('What would be removed: the link index, auto-link rules, target keywords, the activity log and its undo history, click stats, URL-change history, Search Console data, imported external sites, every setting on this page, and the semantic index stored against each post.', 'smartlinker'),
                    __('What is never touched, whatever this is set to: your posts and pages, and any links already written into their content. Links SmartLinker inserted stay inserted — they are ordinary links in your content, not plugin data.', 'smartlinker'),
                    __('With this off, deleting the plugin leaves its database tables behind. They cost a little space and nothing else, and they are still there if you reinstall.', 'smartlinker'),
                ]);
            ?>

            <div class="slk-section-title"><?php esc_html_e('Click tracking', 'smartlinker'); ?></div>
            <?php
            slk_row(
                __('Keep clicks for', 'smartlinker'),
                __('How long a recorded click stays in the database, in days.', 'smartlinker'),
                '<input type="number" min="0" max="3650" step="30" name="slk[clicks_keep_days]" value="' . esc_attr($s['clicks_keep_days']) . '" /> '
                    . '<span class="description">' . esc_html__('days', 'smartlinker') . '</span>',
                false,
                [
                    __('This is the only table SmartLinker fills from visitor activity rather than your own, so its size follows your traffic. One row per click, kept forever, is how a small plugin quietly becomes a large database.', 'smartlinker'),
                    __('The reports only look back 30 days, so a year is generous. Old rows are removed once a day, in batches, so a long-neglected table cannot stall the site while it catches up.', 'smartlinker'),
                    __('Set it to 0 to keep every click indefinitely. Nothing will break, but nothing will clean up after it either.', 'smartlinker'),
                ]
            );
            ?>

            <div class="slk-section-title"><?php esc_html_e('Activity log', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Changes to keep', 'smartlinker'), __('How many applied changes stay undoable on the Activity page.', 'smartlinker'),
                '<input type="number" min="0" max="5000" step="10" name="slk[activity_keep]" value="' . esc_attr($s['activity_keep']) . '" />',
                false,
                [
                    __('There is no time limit on undo. An entry stays undoable until the post is next edited, or until this many newer changes have pushed it off the list.', 'smartlinker'),
                    __('Each entry stores a full copy of the post as it was before the change, so this is a disk trade rather than a clock. Raise it if you apply links in large batches and want a longer safety net; 300 covers roughly two weeks at twenty applies a day.', 'smartlinker'),
                    __('Set it to 0 to switch the log off entirely — nothing is recorded, and one-click applies from the reports can no longer be undone.', 'smartlinker'),
                ]);
            ?>

        <?php elseif ($panel === 'ignoring') : ?>

            <div class="slk-section-title"><?php esc_html_e('Exclusions', 'smartlinker'); ?></div>
            <p class="description"><?php esc_html_e('Content excluded here is never suggested as a link target and is skipped by auto-linking.', 'smartlinker'); ?></p>
            <?php
            slk_row(__('Ignore words', 'smartlinker'), __('Comma-separated stop words excluded from suggestions.', 'smartlinker'),
                '<input type="text" name="slk[ignore_words]" value="' . esc_attr($s['ignore_words']) . '" />', true,
                __('Common filler words that should never become anchor text on their own. Add industry words that create noisy matches on your site.', 'smartlinker'));
            slk_row(__('Excluded post IDs', 'smartlinker'), __('Comma-separated post IDs never suggested as link targets.', 'smartlinker'),
                '<input type="text" name="slk[excluded_post_ids]" value="' . esc_attr($s['excluded_post_ids']) . '" />', true,
                __('Find a post ID by opening it in the editor and reading the post=123 number in the browser address bar. Example: 12, 48, 91', 'smartlinker'));
            slk_row(__('Excluded categories / tags', 'smartlinker'), __('Comma-separated term IDs or slugs — useful for legal pages or products.', 'smartlinker'),
                '<input type="text" name="slk[excluded_terms]" value="' . esc_attr($s['excluded_terms']) . '" placeholder="uncategorized, 42" />', true,
                __('Every post in these categories or tags is skipped. Use the slug (the URL-friendly name, e.g. "legal") or the numeric term ID.', 'smartlinker'));
            ?>

        <?php elseif ($panel === 'digest') :
            $next = Slk_Schedule::next_run();
            $last = Slk_Schedule::last_run();
            $test = wp_nonce_url(add_query_arg(['tab' => 'digest', 'slk_digest_test' => 1], $base), 'slk_digest_test');
            $days = [
                0 => __('Sunday', 'smartlinker'), 1 => __('Monday', 'smartlinker'),
                2 => __('Tuesday', 'smartlinker'), 3 => __('Wednesday', 'smartlinker'),
                4 => __('Thursday', 'smartlinker'), 5 => __('Friday', 'smartlinker'),
                6 => __('Saturday', 'smartlinker'),
            ];
            ?>

            <div class="slk-section-title"><?php esc_html_e('Scheduled scans', 'smartlinker'); ?></div>
            <p class="description">
                <?php esc_html_e('The reports are only as fresh as the last time you pressed Scan. Turn this on and SmartLinker re-checks your links on a schedule and emails you what changed — not the totals you have already seen, but the links that broke, the posts that lost their last inbound link, and the new opportunities worth acting on.', 'smartlinker'); ?>
            </p>

            <?php if (!empty($_GET['digest_sent'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scan run and a digest sent to the addresses below.', 'smartlinker'); ?></p></div>
            <?php elseif (!empty($_GET['digest_failed'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('The scan ran, but WordPress could not send the email. That is usually the site\'s mail setup rather than SmartLinker — an SMTP plugin normally fixes it.', 'smartlinker'); ?></p></div>
            <?php endif; ?>

            <?php
            slk_row(__('Scheduled scans', 'smartlinker'), __('Re-scan on a schedule and email a summary.', 'smartlinker'),
                Slk_Admin::toggle('slk[digest_enabled]', !empty($s['digest_enabled'])), false,
                [
                    __('Runs through WP-Cron, which WordPress triggers on ordinary page visits. On a quiet site the run can drift late; a real server cron job pointed at wp-cron.php makes it exact.', 'smartlinker'),
                    __('Nothing is changed on your site by a scheduled run. It only re-checks links and rebuilds the opportunity list — every fix stays a decision you make.', 'smartlinker'),
                ]);

            slk_row(__('How often', 'smartlinker'), __('Daily suits a busy site; weekly is enough for most.', 'smartlinker'),
                '<select name="slk[digest_frequency]">'
                . '<option value="daily"' . selected($s['digest_frequency'], 'daily', false) . '>' . esc_html__('Daily', 'smartlinker') . '</option>'
                . '<option value="weekly"' . selected($s['digest_frequency'], 'weekly', false) . '>' . esc_html__('Weekly', 'smartlinker') . '</option>'
                . '</select>');

            $day_select = '<select name="slk[digest_day]">';
            foreach ($days as $n => $label) {
                $day_select .= '<option value="' . esc_attr($n) . '"' . selected((int) $s['digest_day'], $n, false) . '>' . esc_html($label) . '</option>';
            }
            $day_select .= '</select>';
            slk_row(__('Day of the week', 'smartlinker'), __('Used only when the frequency is weekly. Runs around 6am site time.', 'smartlinker'), $day_select);

            slk_row(__('Send to', 'smartlinker'), __('Comma-separated. Leave blank to use the site admin address.', 'smartlinker'),
                '<input type="text" name="slk[digest_recipients]" value="' . esc_attr($s['digest_recipients']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '" />',
                true,
                __('Anything that is not a valid email address is dropped when you save, rather than silently failing later.', 'smartlinker'));

            slk_row(__('Only email when something changed', 'smartlinker'), __('Stay quiet on weeks where nothing broke and nothing new turned up.', 'smartlinker'),
                Slk_Admin::toggle('slk[digest_only_changes]', !empty($s['digest_only_changes'])), false,
                __('Recommended. A digest that arrives every week saying "nothing to report" stops being read, and then the one that matters gets missed too.', 'smartlinker'));

            slk_row(__('Re-check links', 'smartlinker'), __('Look for links that have started returning errors.', 'smartlinker'),
                Slk_Admin::toggle('slk[digest_scan_broken]', !empty($s['digest_scan_broken'])));

            slk_row(__('Rebuild link opportunities', 'smartlinker'), __('Refresh the site-wide worklist so new posts are included.', 'smartlinker'),
                Slk_Admin::toggle('slk[digest_scan_opportunities]', !empty($s['digest_scan_opportunities'])));
            ?>

            <div class="slk-section-title"><?php esc_html_e('Status', 'smartlinker'); ?></div>
            <table class="slk-table" style="max-width:560px;">
                <tbody>
                    <tr class="slk-tr">
                        <td><?php esc_html_e('Next scheduled run', 'smartlinker'); ?></td>
                        <td>
                            <?php if ($next) : ?>
                                <?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'j M Y, H:i')); ?>
                                <span class="slk-title-meta"><?php echo esc_html(human_time_diff($next) . ' ' . __('from now', 'smartlinker')); ?></span>
                            <?php else : ?>
                                <span class="slk-metric-zero"><?php esc_html_e('not scheduled', 'smartlinker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="slk-tr">
                        <td><?php esc_html_e('Last run', 'smartlinker'); ?></td>
                        <td>
                            <?php if ($last) : ?>
                                <?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $last), 'j M Y, H:i')); ?>
                            <?php else : ?>
                                <span class="slk-metric-zero"><?php esc_html_e('never', 'smartlinker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="slk-tr">
                        <td><?php esc_html_e('WP-Cron', 'smartlinker'); ?></td>
                        <td>
                            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                                <span class="slk-badge slk-badge-warn"><?php esc_html_e('disabled in wp-config', 'smartlinker'); ?></span>
                                <span class="slk-title-meta"><?php esc_html_e('A server cron job must call wp-cron.php, or scheduled scans will never fire.', 'smartlinker'); ?></span>
                            <?php else : ?>
                                <span class="slk-badge slk-badge-good"><?php esc_html_e('active', 'smartlinker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:14px;">
                <a class="button" href="<?php echo esc_url($test); ?>"><?php esc_html_e('Run a scan and send me the digest now', 'smartlinker'); ?></a>
                <span class="description"><?php esc_html_e('Sends even if nothing changed, so you can confirm the email arrives.', 'smartlinker'); ?></span>
            </p>

        <?php elseif ($panel === 'ai') :
            $configured = Slk_AI::is_configured();
            $stats = Slk_AI::processing_stats();
            $errors = Slk_AI::errors();
            $disconnect = wp_nonce_url(add_query_arg(['tab' => 'ai', 'slk_ai_disconnect' => 1], $base), 'slk_ai_disconnect');
            $clear_cache = wp_nonce_url(add_query_arg(['tab' => 'ai', 'slk_ai_clear_cache' => 1], $base), 'slk_ai_clear_cache');
            $clear_errors = wp_nonce_url(add_query_arg(['tab' => 'ai', 'slk_ai_clear_errors' => 1], $base), 'slk_ai_clear_errors');
            ?>

            <div class="slk-section-title"><?php esc_html_e('Connection', 'smartlinker'); ?></div>
            <?php
            if ($configured) {
                slk_row(
                    __('SmartLinker AI', 'smartlinker'),
                    __('Connected to OpenAI with your own API key. Requests are billed to your OpenAI account.', 'smartlinker'),
                    '<a href="' . esc_url($disconnect) . '" class="button slk-btn-danger" onclick="return confirm(\''
                        . esc_js(__('Remove your API key and turn AI off?', 'smartlinker')) . '\');">'
                        . esc_html__('Disconnect', 'smartlinker') . '</a>'
                );
            }
            $key_placeholder = $configured
                ? esc_attr__('•••••••• (saved — leave blank to keep)', 'smartlinker')
                : 'sk-…';
            slk_row(__('OpenAI API key', 'smartlinker'), __('Stored in your site database. Leave blank to keep the existing key.', 'smartlinker'),
                '<input type="password" autocomplete="off" name="slk[openai_api_key]" placeholder="' . $key_placeholder . '" />', true,
                [
                    __('SmartLinker uses your own OpenAI account rather than reselling credits, so you pay OpenAI\'s normal rates directly and there is no subscription on top.', 'smartlinker'),
                    __('Create a key at platform.openai.com under API keys, then paste it here. It is stored in your site\'s database and is never shown again — leave the field blank when saving to keep the existing key.', 'smartlinker'),
                    __('Requests go straight from your server to OpenAI, so your content is never routed through us or any third party.', 'smartlinker'),
                ]);
            slk_row(__('Model', 'smartlinker'), __('Which OpenAI model analyses your content.', 'smartlinker'),
                '<select name="slk[openai_model]">'
                . '<option value="gpt-4o-mini"' . selected($s['openai_model'], 'gpt-4o-mini', false) . '>GPT-4o Mini</option>'
                . '<option value="gpt-4o"' . selected($s['openai_model'], 'gpt-4o', false) . '>GPT-4o</option>'
                . '<option value="gpt-4.1-mini"' . selected($s['openai_model'], 'gpt-4.1-mini', false) . '>GPT-4.1 Mini</option>'
                . '<option value="gpt-4.1"' . selected($s['openai_model'], 'gpt-4.1', false) . '>GPT-4.1</option>'
                . '</select>', false,
                [
                    __('Which OpenAI model reads your content and decides which internal links belong in it.', 'smartlinker'),
                    __('GPT-4o Mini is fast and inexpensive and is the right default for almost every site — it handles ordinary blog and marketing content well.', 'smartlinker'),
                    __('The larger models understand nuance better but cost noticeably more per scan. They are worth considering only for highly technical or specialist writing where subtle topic differences matter.', 'smartlinker'),
                ]);
            ?>

            <div class="slk-section-title"><?php esc_html_e('Suggestion behaviour', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Use AI-powered suggestions', 'smartlinker'), __('Show the “AI Suggestions” button in the post editor.', 'smartlinker'),
                Slk_Admin::toggle('slk[use_ai]', !empty($s['use_ai'])), false,
                [
                    __('This setting tells SmartLinker to use AI when generating link suggestions, instead of relying on keyword matching alone.', 'smartlinker'),
                    __('When active, the AI reads the meaning of your article and compares it against every other page on your site, so it can recommend links that share a topic even when the exact words never match.', 'smartlinker'),
                    __('Each scan makes one request to OpenAI and is billed to your own account. Results are cached, so re-opening the same post costs nothing until you edit it.', 'smartlinker'),
                ]);
            slk_row(__('Prefer page title as anchor', 'smartlinker'), __('Use the target page’s title as the anchor when it appears in your text.', 'smartlinker'),
                Slk_Admin::toggle('slk[ai_prefer_title_anchor]', !empty($s['ai_prefer_title_anchor'])), false,
                [
                    __('Controls the wording SmartLinker uses for the clickable text of an AI suggestion.', 'smartlinker'),
                    __('Left to itself the AI sometimes picks a long phrase such as "dark roasts shine as espresso". With this setting on, SmartLinker uses the target page\'s own name instead — "Espresso" — whenever that name actually appears in your text.', 'smartlinker'),
                    __('Shorter, more precise anchor text reads better and gives search engines a clearer signal about the page you are linking to. Recommended for most sites.', 'smartlinker'),
                ]);
            slk_row(__('Only show top suggestions', 'smartlinker'), __('Cap the list to the highest-scoring suggestions only.', 'smartlinker'),
                Slk_Admin::toggle('slk[ai_only_top]', !empty($s['ai_only_top'])), false,
                __('Shows only the best few matches instead of everything above the threshold. Useful if you prefer a handful of strong links per post rather than a long list to sift through.', 'smartlinker'));
            slk_row(__('How many top suggestions', 'smartlinker'), __('Used when “Only show top suggestions” is on.', 'smartlinker'),
                '<input type="number" min="1" max="50" name="slk[ai_top_n]" value="' . esc_attr($s['ai_top_n']) . '" />');
            slk_row(
                __('Minimum match score', 'smartlinker'),
                __('Hide AI suggestions scoring below this relevance threshold.', 'smartlinker'),
                '<div class="slk-range"><input type="range" min="0" max="100" step="5" name="slk[ai_min_match]" value="'
                    . esc_attr($s['ai_min_match']) . '" oninput="this.nextElementSibling.textContent=this.value+\'% match\'" />'
                    . '<span class="slk-range-val">' . esc_html($s['ai_min_match']) . '% match</span></div>',
                false,
                [
                    __('Every AI suggestion is given a relevance score from 0 to 100, describing how closely the target page matches the surrounding text. Anything scoring below this threshold is hidden.', 'smartlinker'),
                    __('Around 50 is a sensible balance. Raise it towards 70–80 if suggestions feel loosely related and you only want obvious wins; lower it if you would rather see more options and judge them yourself.', 'smartlinker'),
                    __('This filter is applied to results SmartLinker has already saved, so moving the slider re-filters instantly and never triggers another billed API call.', 'smartlinker'),
                ]
            );
            slk_row(__('Don’t process posts older than', 'smartlinker'), __('Skip older content when running AI analysis.', 'smartlinker'),
                '<select name="slk[ai_max_age]">'
                . '<option value="0"' . selected((int) $s['ai_max_age'], 0, false) . '>' . esc_html__('No limit', 'smartlinker') . '</option>'
                . '<option value="180"' . selected((int) $s['ai_max_age'], 180, false) . '>' . esc_html__('6 months', 'smartlinker') . '</option>'
                . '<option value="365"' . selected((int) $s['ai_max_age'], 365, false) . '>' . esc_html__('1 year', 'smartlinker') . '</option>'
                . '<option value="730"' . selected((int) $s['ai_max_age'], 730, false) . '>' . esc_html__('2 years', 'smartlinker') . '</option>'
                . '</select>', false,
                __('Skips older posts when running AI analysis, which keeps costs down on large archives. Posts older than this simply return no AI suggestions.', 'smartlinker'));
            ?>

            <div class="slk-section-title"><?php esc_html_e('Performance & data', 'smartlinker'); ?></div>
            <?php
            slk_row(__('Cache AI results', 'smartlinker'), __('Reuse results until a post’s content changes.', 'smartlinker'),
                Slk_Admin::toggle('slk[ai_cache]', !empty($s['ai_cache'])), false,
                [
                    __('Saves the AI\'s analysis of each post and reuses it until that post\'s content changes.', 'smartlinker'),
                    __('With caching on, re-opening a post you have already analysed is instant and costs nothing. SmartLinker automatically discards the saved result the moment you edit the post, so you never see stale suggestions.', 'smartlinker'),
                    __('Turning this off means every single scan calls OpenAI again and is billed again. Strongly recommended to leave on.', 'smartlinker'),
                ]);
            slk_row(__('Request timeout', 'smartlinker'), __('Seconds to wait for the OpenAI API before giving up.', 'smartlinker'),
                '<input type="number" min="5" max="180" name="slk[ai_timeout]" value="' . esc_attr($s['ai_timeout']) . '" />', false,
                __('Raise this if long posts fail with a timeout error. Lower it if you would rather fail fast than keep the editor waiting.', 'smartlinker'));
            slk_row(__('Clear cached AI data', 'smartlinker'), __('Delete every stored AI result so the next run re-analyses from scratch.', 'smartlinker'),
                '<a href="' . esc_url($clear_cache) . '" class="button slk-btn-danger" onclick="return confirm(\''
                    . esc_js(__('Clear all cached AI results?', 'smartlinker')) . '\');">' . esc_html__('Clear data', 'smartlinker') . '</a>',
                false,
                __('Deletes every saved AI result. Your settings and links are untouched — the next scan of each post simply calls the API again. Use this if you changed model and want fresh analysis.', 'smartlinker'));
            ?>

            <div class="slk-section-title"><?php esc_html_e('Content processing status', 'smartlinker'); ?></div>
            <div class="slk-stat-tiles">
                <div class="slk-tile">
                    <span class="slk-tile-cap"><?php esc_html_e('Processable posts', 'smartlinker'); ?></span>
                    <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['total'])); ?></span>
                </div>
                <div class="slk-tile">
                    <span class="slk-tile-cap"><?php esc_html_e('Posts analysed', 'smartlinker'); ?></span>
                    <span class="slk-tile-num slk-good"><?php echo esc_html(number_format_i18n($stats['analysed'])); ?></span>
                </div>
                <div class="slk-tile">
                    <span class="slk-tile-cap"><?php esc_html_e('Remaining', 'smartlinker'); ?></span>
                    <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['remaining'])); ?></span>
                </div>
                <div class="slk-tile">
                    <span class="slk-tile-cap"><?php esc_html_e('Logged errors', 'smartlinker'); ?></span>
                    <span class="slk-tile-num <?php echo $stats['errors'] ? 'slk-bad' : ''; ?>"><?php echo esc_html(number_format_i18n($stats['errors'])); ?></span>
                </div>
            </div>

            <div class="slk-section-title"><?php esc_html_e('System error log', 'smartlinker'); ?></div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:180px;"><?php esc_html_e('Date / time', 'smartlinker'); ?></th>
                        <th><?php esc_html_e('Error message', 'smartlinker'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Error data', 'smartlinker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($errors)) : ?>
                    <tr><td colspan="3"><?php esc_html_e('No errors logged.', 'smartlinker'); ?></td></tr>
                <?php else : foreach ($errors as $e) : ?>
                    <tr>
                        <td><?php echo esc_html($e['time']); ?></td>
                        <td><?php echo esc_html($e['message']); ?></td>
                        <td><?php echo esc_html($e['data']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php if (!empty($errors)) : ?>
                <p style="margin-top:10px;"><a href="<?php echo esc_url($clear_errors); ?>" class="button"><?php esc_html_e('Clear error log', 'smartlinker'); ?></a></p>
            <?php endif; ?>

        <?php endif; ?>

        <p class="slk-save-row">
            <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Save Settings', 'smartlinker'); ?></button>
            <?php /* Filled in after saving; plain text, no chrome of its own. */ ?>
            <span class="slk-save-status" role="status" aria-live="polite"></span>
        </p>
    </form>
    </div>
    <?php endforeach; ?>
</div>
