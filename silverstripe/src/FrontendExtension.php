<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Extension;

/**
 * Injects Talaria browser SDK into public pages (ContentController).
 *
 * @extends Extension<\SilverStripe\CMS\Controllers\ContentController>
 */
class FrontendExtension extends Extension
{
    public function onAfterInit(): void
    {
        if (!Config::enableBrowserFrontend()) {
            return;
        }

        RequirementsHelper::inject('silverstripe-frontend');
    }
}
