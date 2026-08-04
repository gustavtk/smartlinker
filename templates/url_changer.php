<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $changes */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('URL Changer', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Replace a URL everywhere it appears in your content in one operation — perfect for changed permalinks, moved pages, or updated affiliate links. Optionally leave a 301 redirect so old links keep working.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['done'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(
                esc_html__('Done — replaced %1$d occurrence(s) across %2$d post(s).', 'smartlinker'),
                (int) ($_GET['occ'] ?? 0),
                (int) ($_GET['posts'] ?? 0)
            ); ?></p>
        </div>
    <?php elseif (!empty($_GET['err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please enter two different, valid URLs.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['rmredir'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Redirect removed.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-form-card">
    <form method="post" action="">
        <?php wp_nonce_field('slk_url_change'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="slk-old-url"><?php esc_html_e('Old URL', 'smartlinker'); ?></label></th>
                <td>
                    <input type="url" id="slk-old-url" name="old_url" class="large-text code" placeholder="https://example.com/old-page/" required />
                    <p class="description"><?php esc_html_e('The exact URL currently used in your links.', 'smartlinker'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="slk-new-url"><?php esc_html_e('New URL', 'smartlinker'); ?></label></th>
                <td><input type="url" id="slk-new-url" name="new_url" class="large-text code" placeholder="https://example.com/new-page/" required /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Redirect', 'smartlinker'); ?></th>
                <td>
                    <label><input type="checkbox" name="add_redirect" value="1" checked />
                        <?php esc_html_e('Add a 301 redirect from the old URL to the new one', 'smartlinker'); ?></label>
                    <p class="description"><?php esc_html_e('Recommended when the old URL was a page on this site, so existing bookmarks and search results keep working.', 'smartlinker'); ?></p>
                </td>
            </tr>
        </table>
        <div class="slk-url-preview" style="display:none;"></div>

        <p>
            <button type="button" class="button slk-url-preview-btn"><?php esc_html_e('Preview changes', 'smartlinker'); ?></button>
            <button type="submit" name="slk_url_change" value="1" class="button button-primary"
                onclick="return confirm('<?php echo esc_js(__('This will update your post content across the site. Continue?', 'smartlinker')); ?>');">
                <?php esc_html_e('Find &amp; Replace URL', 'smartlinker'); ?>
            </button>
        </p>
    </form>
    </div>

    <h2><?php esc_html_e('Change history', 'smartlinker'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Old URL', 'smartlinker'); ?></th>
                <th><?php esc_html_e('New URL', 'smartlinker'); ?></th>
                <th><?php esc_html_e('Posts', 'smartlinker'); ?></th>
                <th><?php esc_html_e('Occurrences', 'smartlinker'); ?></th>
                <th><?php esc_html_e('Redirect', 'smartlinker'); ?></th>
                <th><?php esc_html_e('When', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($changes)) : ?>
            <tr><td colspan="6"><?php esc_html_e('No URL changes yet.', 'smartlinker'); ?></td></tr>
        <?php else : foreach ($changes as $c) :
            $rm = wp_nonce_url(admin_url('admin.php?page=smartlinker_url_changer&slk_del_redirect=' . $c->id), 'slk_del_redirect');
            ?>
            <tr>
                <td class="code" style="word-break:break-all;"><?php echo esc_html($c->old_url); ?></td>
                <td class="code" style="word-break:break-all;"><a href="<?php echo esc_url($c->new_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($c->new_url); ?></a></td>
                <td><?php echo (int) $c->posts_changed; ?></td>
                <td><?php echo (int) $c->occurrences; ?></td>
                <td>
                    <?php if ($c->redirect) : ?>
                        <span style="color:#1a7f37;"><?php esc_html_e('301 active', 'smartlinker'); ?></span>
                        &nbsp;<a href="<?php echo esc_url($rm); ?>" style="color:#b32d2e;"><?php esc_html_e('remove', 'smartlinker'); ?></a>
                    <?php else : ?>
                        <span style="color:#646970;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($c->created); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
