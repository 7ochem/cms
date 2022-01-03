<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\FieldLayoutElement;
use craft\base\Model;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * FieldLayoutTab model class.
 *
 * @property FieldLayoutElement[]|null $elements The tab’s layout elements
 * @property FieldLayout|null $layout The tab’s layout
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class FieldLayoutTab extends Model
{
    /**
     * Creates a new field layout tab from the given config.
     *
     * @param array $config
     * @return self
     * @since 3.5.0
     */
    public static function createFromConfig(array $config): self
    {
        static::updateConfig($config);
        return new self($config);
    }

    /**
     * Updates a field layout tab’s config to the new format.
     *
     * @param array $config
     * @since 3.5.0
     */
    public static function updateConfig(array &$config): void
    {
        if (!array_key_exists('fields', $config)) {
            return;
        }

        $config['elements'] = [];

        ArrayHelper::multisort($config['fields'], 'sortOrder');
        foreach ($config['fields'] as $fieldUid => $fieldConfig) {
            $config['elements'][] = [
                'type' => CustomField::class,
                'fieldUid' => $fieldUid,
                'required' => (bool)$fieldConfig['required'],
            ];
        }

        unset($config['fields']);
    }

    /**
     * @var int|null ID
     */
    public ?int $id = null;

    /**
     * @var int|null Layout ID
     */
    public ?int $layoutId = null;

    /**
     * @var string|null Name
     */
    public ?string $name = null;

    /**
     * @var int|null Sort order
     */
    public ?int $sortOrder = null;

    /**
     * @var string|null UID
     */
    public ?string $uid = null;

    /**
     * @var FieldLayout
     * @see getLayout()
     * @see setLayout()
     */
    private FieldLayout $_layout;

    /**
     * @var FieldLayoutElement[] The tab’s layout elements
     * @see getElements()
     * @see setElements()
     */
    private array $_elements;

    /**
     * @var FieldInterface[]|null
     * @see getFields()
     * @see setFields()
     */
    private ?array $_fields = null;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Config normalization
        if (isset($config['elements']) && is_string($config['elements'])) {
            $config['elements'] = Json::decode($config['elements']);
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!isset($this->_elements)) {
            $this->setElements(array_map(function(FieldInterface $field) {
                return Craft::createObject([
                    'class' => CustomField::class,
                    'required' => $field->required,
                ], [$field]);
            }, $this->getFields()));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['id', 'layoutId'], 'number', 'integerOnly' => true];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['sortOrder'], 'string', 'max' => 4];
        return $rules;
    }

    /**
     * Returns the field layout config for this field layout tab.
     *
     * @return array|null
     * @since 3.5.0
     */
    public function getConfig(): ?array
    {
        if (empty($this->_elements)) {
            return null;
        }

        return [
            'name' => $this->name,
            'sortOrder' => (int)$this->sortOrder,
            'elements' => $this->getElementConfigs(),
        ];
    }

    /**
     * Returns the field layout configs for this field layout’s tabs.
     *
     * @return array[]
     * @since 3.5.0
     */
    public function getElementConfigs(): array
    {
        $elementConfigs = [];
        foreach ($this->getElements() as $layoutElement) {
            $elementConfigs[] = ['type' => get_class($layoutElement)] + $layoutElement->toArray();
        }
        return $elementConfigs;
    }

    /**
     * Returns the tab’s layout.
     *
     * @return FieldLayout The tab’s layout.
     * @throws InvalidConfigException if [[layoutId]] is set but invalid
     */
    public function getLayout(): FieldLayout
    {
        if (isset($this->_layout)) {
            return $this->_layout;
        }

        if (!$this->layoutId) {
            throw new InvalidConfigException('Field layout tab is missing its field layout.');
        }

        if (($this->_layout = Craft::$app->getFields()->getLayoutById($this->layoutId)) === null) {
            throw new InvalidConfigException('Invalid layout ID: ' . $this->layoutId);
        }

        return $this->_layout;
    }

    /**
     * Sets the tab’s layout.
     *
     * @param FieldLayout $layout The tab’s layout.
     */
    public function setLayout(FieldLayout $layout): void
    {
        $this->_layout = $layout;
    }

    /**
     * Returns the tab’s layout elements.
     *
     * @return FieldLayoutElement[]
     * @since 4.0.0
     */
    public function getElements(): array
    {
        return $this->_elements ?? [];
    }

    /**
     * Sets the tab’s layout elements.
     *
     * @param FieldLayoutElement[] $elements
     * @since 4.0.0
     */
    public function setElements(array $elements): void
    {
        $fieldsService = Craft::$app->getFields();
        $this->_elements = [];

        foreach ($elements as $layoutElement) {
            if (is_array($layoutElement)) {
                try {
                    $layoutElement = $fieldsService->createLayoutElement($layoutElement);
                } catch (InvalidArgumentException $e) {
                    Craft::warning('Invalid field layout element config: ' . $e->getMessage(), __METHOD__);
                    Craft::$app->getErrorHandler()->logException($e);
                    continue;
                }
            }

            $layoutElement->setLayout($this->getLayout());
            $this->_elements[] = $layoutElement;
        }
    }

    /**
     * Returns the custom fields included in this tab.
     *
     * @return FieldInterface[]
     */
    public function getFields(): array
    {
        if (isset($this->_fields)) {
            return $this->_fields;
        }

        $this->_fields = [];

        foreach ($this->getLayout()->getFields() as $field) {
            if ($field->tabId == $this->id) {
                $this->_fields[] = $field;
            }
        }

        return $this->_fields;
    }

    /**
     * Sets the custom fields included in this tab.
     *
     * @param FieldInterface[] $fields
     */
    public function setFields(array $fields): void
    {
        ArrayHelper::multisort($fields, 'sortOrder');
        $this->_fields = $fields;

        $this->setElements(array_map(function(FieldInterface $field) {
            return Craft::createObject([
                'class' => CustomField::class,
                'required' => $field->required,
            ], [$field]);
        }, $this->_fields));

        // Clear the field layout's field cache
        if (isset($this->_layout)) {
            $this->_layout->setFields(null);
        }
    }

    /**
     * Returns the tab’s HTML ID.
     *
     * @return string
     */
    public function getHtmlId(): string
    {
        return 'tab-' . StringHelper::toKebabCase($this->name);
    }

    /**
     * Returns whether the given element has any validation errors for the custom fields included in this tab.
     *
     * @param ElementInterface $element
     * @return bool
     * @since 3.4.0
     */
    public function elementHasErrors(ElementInterface $element): bool
    {
        if (!$element->hasErrors()) {
            return false;
        }

        foreach ($this->getElements() as $layoutElement) {
            if ($layoutElement instanceof BaseField && $element->hasErrors($layoutElement->attribute() . '.*')) {
                return true;
            }
        }

        return false;
    }
}
