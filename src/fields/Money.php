<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\gql\types\Money as MoneyType;
use craft\gql\types\Number as NumberType;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\MoneyHelper;
use craft\validators\MoneyValidator;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money as MoneyLibrary;

/**
 * Money represents a Money field.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 *
 * @property-read array $contentGqlMutationArgumentType
 * @property-read array[] $elementValidationRules
 * @property-read string[] $contentColumnType
 * @property-read null|string $settingsHtml
 * @property-read null $elementConditionRuleType
 * @property-read mixed $contentGqlType
 */
class Money extends Field implements PreviewableFieldInterface, SortableFieldInterface
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Money');
    }

    /**
     * @inheritdoc
     */
    public static function valueType(): string
    {
        return MoneyLibrary::class . '|null';
    }

    /**
     * @var string The default currency
     */
    public string $currency = 'USD';

    /**
     * @var int|float|null The default value for new elements
     */
    public $defaultValue;

    /**
     * @var int|float|null The minimum allowed number
     */
    public $min = 0;

    /**
     * @var int|float|null The maximum allowed number
     */
    public $max;

    /**
     * @var int|null The size of the field
     */
    public ?int $size = null;

    /**
     * @var ISOCurrencies
     */
    private ISOCurrencies $_isoCurrencies;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        if (!isset($this->_isoCurrencies)) {
            $this->_isoCurrencies = new ISOCurrencies();
        }

        // Config normalization
        foreach (['defaultValue', 'min', 'max'] as $name) {
            if (isset($config[$name])) {
                $config[$name] = $this->_normalizeNumber($config[$name]);
            }
        }

        if (isset($config['size']) && !is_numeric($config['size'])) {
            $config['size'] = null;
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['defaultValue', 'min', 'max'], 'number'];
        $rules[] = [['currency'], 'required'];
        $rules[] = [['currency'], 'string', 'max' => 3];
        $rules[] = [['size'], 'integer'];
        $rules[] = [
            ['max'],
            'compare',
            'compareAttribute' => 'min',
            'operator' => '>=',
        ];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        foreach (['defaultValue', 'min', 'max'] as $attr) {
            if ($this->$attr === null) {
                continue;
            }

            $this->$attr = MoneyHelper::toDecimal(new MoneyLibrary($this->$attr, new Currency($this->currency)));
        }

        return Craft::$app->getView()->renderTemplate('_components/fieldtypes/Money/settings',
            [
                'field' => $this,
                'currencies' => $this->_isoCurrencies,
                'subUnits' => $this->_isoCurrencies->subunitFor(new Currency($this->currency)),
            ]);
    }

    /**
     * @inheritdoc
     */
    public function getContentColumnType(): string
    {
        $min = $this->min ?? null;
        $max = $this->max ?? null;

        return Db::getNumericalColumnType($min, $max, 0);
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue($value, ?ElementInterface $element = null)
    {
        if ($value instanceof MoneyLibrary) {
            return $value;
        }

        if ($value === null && isset($this->defaultValue) && $this->isFresh($element)) {
            $value = $this->defaultValue;
        }

        if (is_array($value)) {
            // Was this submitted with a locale ID?
            $value['locale'] = $value['locale'] ?? Craft::$app->getFormattingLocale()->id;
            $value['value'] = $value['value'] ?? null;
            $value['currency'] = $this->currency;

            return MoneyHelper::toMoney($value);
        }

        return new MoneyLibrary($value, new Currency($this->currency));
    }

    /**
     * @param $value
     * @param ElementInterface|null $element
     * @return string|null
     */
    public function serializeValue($value, ElementInterface $element = null): ?string
    {
        if (!$value) {
            return null;
        }

        /** @var MoneyLibrary $value */
        return $value->getAmount();
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function _normalizeNumber($value): ?string
    {
        $currency = new Currency($this->currency);

        // Was this submitted with a locale ID? (This means the data is coming from the settings form)
        if (isset($value['locale'], $value['value'])) {
            if ($value['value'] === '') {
                return null;
            }
            $value['currency'] = $this->currency;

            $value = MoneyHelper::toMoney($value);
            return $value ? $value->getAmount() : null;
        }

        if ($value === '') {
            return null;
        }

        return (new MoneyLibrary($value, $currency))->getAmount();
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml($value, ?ElementInterface $element = null): string
    {
        $id = Html::id($this->handle);
        $view = Craft::$app->getView();
        $namespacedId = $view->namespaceInputId($id);

        $js = <<<JS
(function() {
    \$('#$namespacedId').on('keydown', ev => {
        if (
            !Garnish.isCtrlKeyPressed(ev) &&
            ![
                9, // tab,
                13, // return / enter
                27, // esc
                8, 46, // backspace, delete
                37, 38, 39, 40, // arrows
                173, 189, 109, // minus, subtract
                190, 110, // period, decimal
                188, // comma
                48, 49, 50, 51, 52, 53, 54, 55, 56, 57, // 0-9
                96, 97, 98, 99, 100, 101, 102, 103, 104, 105, // numpad 0-9
            ].includes(ev.which)
        ) {
            ev.preventDefault();
        }
    });
})();
JS;

        $view->registerJs($js);

        $decimals = null;

        if ($value instanceof MoneyLibrary) {
            $decimals = $this->_isoCurrencies->subunitFor($value->getCurrency());
            $value = MoneyHelper::toNumber($value);
        }

        if ($decimals === null) {
            $decimals = $this->_isoCurrencies->subunitFor(new Currency($this->currency));
        }

        $defaultValue = null;
        if (isset($this->defaultValue)) {
            $defaultValue = MoneyHelper::toNumber(new MoneyLibrary($this->defaultValue, new Currency($this->currency)));
        }

        $currencyLabel = Craft::t('app', '({currencyCode}) {currencySymbol}', [
            'currencyCode' => $this->currency,
            'currencySymbol' => Craft::$app->getFormattingLocale()->getCurrencySymbol($this->currency),
        ]);

        return $view->renderTemplate('_components/fieldtypes/Money/input', [
            'id' => $id,
            'currency' => $this->currency,
            'currencyLabel' => $currencyLabel,
            'decimals' => $decimals,
            'defaultValue' => $defaultValue,
            'describedBy' => $this->describedBy,
            'field' => $this,
            'value' => $value,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return [
            [MoneyValidator::class, 'min' => $this->min, 'max' => $this->max],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getElementConditionRuleType()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        return MoneyHelper::toString($value) ?: '';
    }

    /**
     * @param ElementQueryInterface $query
     * @param $value
     * @return void
     */
    public function modifyElementsQuery(ElementQueryInterface $query, $value): void
    {
        /** @var ElementQuery $query */
        if ($value !== null) {
            $column = ElementHelper::fieldColumnFromField($this);
            $query->subQuery->andWhere(Db::parseMoneyParam("content.$column", $this->currency, $value));
        }
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType()
    {
        return MoneyType::getType();
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlMutationArgumentType()
    {
        return [
            'name' => $this->handle,
            'type' => MoneyType::getType(),
            'description' => $this->instructions,
        ];
    }
}
