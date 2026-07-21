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

        RequirementsHelper::inject('silverstripe-cms');
    }
}
