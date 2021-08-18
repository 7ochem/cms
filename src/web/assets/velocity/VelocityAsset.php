<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\web\assets\velocity;

use yii\web\AssetBundle;

/**
 * Velocity asset bundle.
 */
class VelocityAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@lib/velocity';

        $this->js = [
            'velocity.js',
        ];

        parent::init();
    }
}
