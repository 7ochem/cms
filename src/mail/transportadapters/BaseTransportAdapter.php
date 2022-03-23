<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\mail\transportadapters;

use craft\base\ConfigurableComponent;

/**
 * Php implements a PHP Mail transport adapter into Craft’s mailer.
 *
 * @method string getAttributeLabel(string $attribute)
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
abstract class BaseTransportAdapter extends ConfigurableComponent implements TransportAdapterInterface
{
}
