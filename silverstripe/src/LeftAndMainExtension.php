<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Extension;

/**
 * Injects Talaria browser SDK into CMS admin (LeftAndMain).
 *
 * @extends Extension<\SilverStripe\Admin\LeftAndMain>
 */
class LeftAndMainExtension extends Extension
{
    public function onAfterInit(): void
    {
        if (!Config::enableBrowserCms()) {
            return;
        }

        $extraTags = [];
        $section = self::resolveSectionTag($this->getOwner());
        if ($section !== null) {
            $extraTags['ss.section'] = $section;
        }

        RequirementsHelper::inject('silverstripe-cms', $extraTags);
    }

    private static function resolveSectionTag(object $owner): ?string
    {
        $class = $owner::class;
        $short = strrchr($class, '\\');
        $short = is_string($short) ? substr($short, 1) : $class;
        if (!is_string($short) || $short === '') {
            return null;
        }

        // Keep tag values low-cardinality and within server limits.
        if (strlen($short) > 128) {
            $short = substr($short, 0, 128);
        }

        return $short;
    }
}
