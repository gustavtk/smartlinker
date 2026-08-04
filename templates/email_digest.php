<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $data */
$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
$admin = admin_url('admin.php?page=smartlinker');

// Inline styles only: every mail client strips <style> blocks sooner or later.
$wrap = 'margin:0;padding:24px 12px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;';
$card = 'max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;padding:28px;color:#1f2430;';
$h1   = 'margin:0 0 4px;font-size:19px;font-weight:600;color:#111827;';
$sub  = 'margin:0 0 22px;font-size:13px;color:#6b7280;';
$h2   = 'margin:26px 0 10px;font-size:14px;font-weight:600;color:#111827;';
$p    = 'margin:0 0 12px;font-size:14px;line-height:1.55;color:#374151;';
$li   = 'font-size:13px;line-height:1.55;color:#374151;margin:0 0 7px;';
$muted = 'font-size:12px;color:#9ca3af;';
$btn  = 'display:inline-block;padding:10px 18px;background:#1a73e8;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;';
$cell = 'padding:10px 12px;background:#f8f9fb;border-radius:8px;text-align:center;';
?>
<div style="<?php echo esc_attr($wrap); ?>">
  <div style="<?php echo esc_attr($card); ?>">

    <h1 style="<?php echo esc_attr($h1); ?>"><?php echo esc_html($site); ?> — <?php esc_html_e('internal links', 'smartlinker'); ?></h1>
    <p style="<?php echo esc_attr($sub); ?>">
        <?php
        echo esc_html(sprintf(
            /* translators: %s: date and time */
            __('Scanned %s', 'smartlinker'),
            mysql2date('j M Y, H:i', $data['generated'])
        ));
        ?>
    </p>

    <?php if (!empty($data['first_run'])) : ?>
        <p style="<?php echo esc_attr($p); ?>">
            <?php esc_html_e('This is the first scheduled scan, so there is nothing to compare against yet. Here is where the site stands — from the next run onward this email will only report what changed.', 'smartlinker'); ?>
        </p>
    <?php endif; ?>

    <!-- Totals -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="margin:0 0 4px;">
      <tr>
        <td width="33%" style="<?php echo esc_attr($cell); ?>">
          <div style="font-size:22px;font-weight:600;color:#111827;"><?php echo esc_html(number_format_i18n($data['stats']['internal'])); ?></div>
          <div style="<?php echo esc_attr($muted); ?>"><?php esc_html_e('internal links', 'smartlinker'); ?></div>
        </td>
        <td width="33%" style="<?php echo esc_attr($cell); ?>">
          <div style="font-size:22px;font-weight:600;color:<?php echo $data['stats']['broken'] ? '#b91c1c' : '#111827'; ?>;"><?php echo esc_html(number_format_i18n($data['stats']['broken'])); ?></div>
          <div style="<?php echo esc_attr($muted); ?>"><?php esc_html_e('broken', 'smartlinker'); ?></div>
        </td>
        <td width="33%" style="<?php echo esc_attr($cell); ?>">
          <div style="font-size:22px;font-weight:600;color:#111827;"><?php echo esc_html(number_format_i18n($data['stats']['orphaned'])); ?></div>
          <div style="<?php echo esc_attr($muted); ?>"><?php esc_html_e('orphans', 'smartlinker'); ?></div>
        </td>
      </tr>
    </table>

    <?php if (empty($data['has_changes']) && empty($data['first_run'])) : ?>
        <h2 style="<?php echo esc_attr($h2); ?>"><?php esc_html_e('Nothing changed', 'smartlinker'); ?></h2>
        <p style="<?php echo esc_attr($p); ?>">
            <?php esc_html_e('No links broke, no new orphans appeared, and no new linking opportunities turned up since the last scan.', 'smartlinker'); ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($data['new_broken'])) : ?>
        <h2 style="<?php echo esc_attr($h2); ?>">
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of links */
                _n('%d link broke since the last scan', '%d links broke since the last scan', $data['new_broken_total'], 'smartlinker'),
                $data['new_broken_total']
            ));
            ?>
        </h2>
        <ul style="margin:0 0 12px;padding-left:18px;">
            <?php foreach ($data['new_broken'] as $b) : ?>
                <li style="<?php echo esc_attr($li); ?>">
                    <strong><?php echo esc_html($b['anchor'] ?: $b['url']); ?></strong>
                    <?php esc_html_e('in', 'smartlinker'); ?>
                    <a href="<?php echo esc_url($b['edit_url']); ?>" style="color:#1a73e8;"><?php echo esc_html($b['title']); ?></a>
                    <div style="<?php echo esc_attr($muted); ?>"><?php echo esc_html($b['url']); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($data['new_broken_total'] > count($data['new_broken'])) : ?>
            <p style="<?php echo esc_attr($muted); ?>">
                <?php
                echo esc_html(sprintf(
                    /* translators: %d: number of further links */
                    __('…and %d more.', 'smartlinker'),
                    $data['new_broken_total'] - count($data['new_broken'])
                ));
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($data['fixed_broken'])) : ?>
        <p style="<?php echo esc_attr($p); ?>">
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of links */
                _n('%d broken link was fixed.', '%d broken links were fixed.', $data['fixed_broken'], 'smartlinker'),
                $data['fixed_broken']
            ));
            ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($data['new_orphans'])) : ?>
        <h2 style="<?php echo esc_attr($h2); ?>">
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of posts */
                _n('%d post has no links pointing to it', '%d posts have no links pointing to them', $data['new_orphans_total'], 'smartlinker'),
                $data['new_orphans_total']
            ));
            ?>
        </h2>
        <ul style="margin:0 0 12px;padding-left:18px;">
            <?php foreach ($data['new_orphans'] as $o) : ?>
                <li style="<?php echo esc_attr($li); ?>">
                    <a href="<?php echo esc_url($o['url']); ?>" style="color:#1a73e8;"><?php echo esc_html($o['title']); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($data['top_opportunities'])) : ?>
        <h2 style="<?php echo esc_attr($h2); ?>">
            <?php
            if (!empty($data['new_opportunities'])) {
                echo esc_html(sprintf(
                    /* translators: %d: number of opportunities */
                    _n('%d new linking opportunity', '%d new linking opportunities', $data['new_opportunities'], 'smartlinker'),
                    $data['new_opportunities']
                ));
            } else {
                esc_html_e('Linking opportunities waiting', 'smartlinker');
            }
            ?>
        </h2>
        <ul style="margin:0 0 12px;padding-left:18px;">
            <?php foreach ($data['top_opportunities'] as $o) : ?>
                <li style="<?php echo esc_attr($li); ?>">
                    <?php echo esc_html($o['source_title']); ?> →
                    <strong><?php echo esc_html($o['phrase']); ?></strong> →
                    <a href="<?php echo esc_url($o['url']); ?>" style="color:#1a73e8;"><?php echo esc_html($o['target_title']); ?></a>
                    <span style="<?php echo esc_attr($muted); ?>">(<?php echo esc_html($o['match']); ?>%)</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="<?php echo esc_attr($muted); ?>">
            <?php
            echo esc_html(sprintf(
                /* translators: %d: total opportunities */
                __('%d in the full list.', 'smartlinker'),
                $data['opportunity_count']
            ));
            ?>
        </p>
    <?php endif; ?>

    <p style="margin:26px 0 0;">
        <a href="<?php echo esc_url($admin); ?>" style="<?php echo esc_attr($btn); ?>"><?php esc_html_e('Open SmartLinker', 'smartlinker'); ?></a>
    </p>

    <p style="margin:22px 0 0;<?php echo esc_attr($muted); ?>">
        <?php
        printf(
            /* translators: %s: settings URL */
            esc_html__('You are getting this because scheduled scans are on. Change or switch them off under Settings → Scheduled Scans: %s', 'smartlinker'),
            '<a href="' . esc_url(admin_url('admin.php?page=smartlinker_settings&tab=digest')) . '" style="color:#9ca3af;">' . esc_html__('digest settings', 'smartlinker') . '</a>'
        );
        ?>
    </p>

  </div>
</div>
