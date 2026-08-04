<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy support: expose category / tag / custom-taxonomy archive pages as
 * link targets so suggestions can propose links to them, not just to posts.
 */
class Slk_Term
{
    /**
     * Public taxonomies SmartLinker links to. Filterable.
     */
    public static function taxonomies()
    {
        $taxes = get_taxonomies(['public' => true], 'names');
        // Post formats aren't useful link targets.
        unset($taxes['post_format']);
        return apply_filters('slk_link_taxonomies', array_values($taxes));
    }

    /**
     * Candidate term targets: [{term_id, name, url, taxonomy, taxonomy_label}].
     * Cached per request. Only terms with at least one post.
     */
    public static function candidates()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];

        $taxes = self::taxonomies();
        if (empty($taxes)) {
            return $cache;
        }

        $terms = get_terms([
            'taxonomy'   => $taxes,
            'hide_empty' => true,
            'number'     => 2000,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return $cache;
        }

        $labels = [];
        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            if (!isset($labels[$term->taxonomy])) {
                $tax_obj = get_taxonomy($term->taxonomy);
                $labels[$term->taxonomy] = $tax_obj ? $tax_obj->labels->singular_name : $term->taxonomy;
            }
            $cache[] = (object) [
                'term_id'        => (int) $term->term_id,
                'name'           => $term->name,
                'url'            => $link,
                'taxonomy'       => $term->taxonomy,
                'taxonomy_label' => $labels[$term->taxonomy],
                'count'          => (int) $term->count,
            ];
        }
        return $cache;
    }
}
