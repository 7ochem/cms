<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\gql\types\generators;

use craft\elements\Entry as EntryElement;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use craft\gql\TypeManager;
use craft\gql\types\elements\Entry;
use craft\helpers\Gql;
use craft\helpers\Gql as GqlHelper;
use craft\models\EntryType as EntryTypeModel;

/**
 * Class EntryType
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.3.0
 */
class EntryType extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    /**
     * @inheritdoc
     */
    public static function generateTypes($context = null): array
    {
        $gqlTypes = [];

        $entryTypes = GqlHelper::getSchemaContainedEntryTypes();

        foreach ($entryTypes as $entryType) {
            // Generate a type for each entry type
            $type = static::generateType($entryType);
            $gqlTypes[$type->name] = $type;
        }

        return $gqlTypes;
    }

    /**
     * @inheritdoc
     */
    public static function generateType($context): ObjectType
    {
        /** @var EntryTypeModel $entryType */
        $typeName = EntryElement::gqlTypeNameByContext($context);

        if ($createdType = GqlEntityRegistry::getEntity($typeName)) {
            return $createdType;
        }

        $contentFieldGqlTypes = self::getContentFields($context);
        $entryTypeFields = array_merge(EntryInterface::getFieldDefinitions(), $contentFieldGqlTypes);

        return GqlEntityRegistry::createEntity($typeName, new Entry([
            'name' => $typeName,
            'fields' => function() use ($entryTypeFields, $typeName) {
                return TypeManager::prepareFieldDefinitions($entryTypeFields, $typeName);
            },
        ]));
    }
}
