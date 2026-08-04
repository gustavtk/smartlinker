<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service registry. Instantiates each service and calls register() if present.
 */
final class Slk_Init
{
    public static function get_services()
    {
        return [
            Slk_Base::class,
            Slk_Settings::class,
            Slk_Report::class,
            Slk_Keyword::class,
            Slk_Suggestion::class,
            Slk_ClickTracker::class,
            Slk_Error::class,
            Slk_URLChanger::class,
            Slk_CSV::class,
            Slk_TargetKeyword::class,
            Slk_SearchConsole::class,
            Slk_Sitemap::class,
            Slk_LinkMap::class,
            Slk_MoneyPage::class,
            Slk_AI::class,
            Slk_Cluster::class,
            Slk_Embedding::class,
            Slk_Opportunity::class,
            Slk_KeywordImport::class,
            Slk_Activity::class,
            Slk_Schedule::class,
            Slk_Anchor::class,
            Slk_Rejection::class,
            Slk_Diagnose::class,
            Slk_Equity::class,
            Slk_Cannibal::class,
            Slk_Placement::class,
            Slk_Setup::class,
            Slk_Scan::class,
            Slk_PostsColumn::class,
            Slk_History::class,
        ];
    }

    public static function register_services()
    {
        foreach (self::get_services() as $class) {
            $service = new $class();
            if (method_exists($service, 'register')) {
                $service->register();
            }
        }
    }
}
