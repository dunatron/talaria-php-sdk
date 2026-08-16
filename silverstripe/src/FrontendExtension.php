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

        $extraTags = [];
        try {
            $owner = $this->getOwner();
            if (method_exists($owner, 'data')) {
                $record = $owner->data();
                $className = (is_object($record) && isset($record->ClassName))
                    ? (string) $record->ClassName
                    : '';
                if ($className !== '') {
                    if (strlen($className) > 128) {
                        $className = substr($className, 0, 128);
                    }
                    $extraTags['entity'] = $className;
                }
            }
        } catch (\Throwable) {
        }

        RequirementsHelper::inject('silverstripe-frontend', $extraTags);
    }
}
