<?php
namespace modules\cachepurge;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use yii\base\Event;
use yii\base\Module as BaseModule;

/**
 * Purges the Cloudways cache when content changes.
 *
 * Inactive unless CLOUDWAYS_ACCESS_TOKEN is set, so local and staging
 * environments do nothing without the env vars.
 */
class Module extends BaseModule
{
    private bool $queued = false;

    public function init(): void
    {
        parent::init();

        if (!App::env('CLOUDWAYS_ACCESS_TOKEN')) {
            return;
        }

        $handler = function(Event $e) {
            $el = $e->sender;
            if (ElementHelper::isDraftOrRevision($el) || $el->propagating || $el->resaving) {
                return;
            }
            $this->schedulePurge();
        };

        foreach ([Entry::class, GlobalSet::class, Asset::class] as $class) {
            Event::on($class, Element::EVENT_AFTER_SAVE, $handler);
            Event::on($class, Element::EVENT_AFTER_DELETE, $handler);
        }
    }

    // One purge per request, even if a save touches many elements
    private function schedulePurge(): void
    {
        if ($this->queued) {
            return;
        }
        $this->queued = true;
        Craft::$app->getQueue()->push(new PurgeJob());
    }
}
