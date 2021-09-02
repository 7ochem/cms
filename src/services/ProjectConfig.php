<?php
declare(strict_types=1);
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\errors\OperationAbortedException;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\ProjectConfigData;
use craft\models\ReadOnlyProjectConfigData;
use Symfony\Component\Yaml\Yaml;
use yii\base\Application;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\caching\ExpressionDependency;
use yii\web\ServerErrorHttpException;

/**
 * Project config service.
 * An instance of the ProjectConfig service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getProjectConfig()|`Craft::$app->projectConfig`]].
 *
 * @property-read bool $isApplyingExternalChanges
 * @property-read bool $isApplyingYamlChanges
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.1.0
 */
class ProjectConfig extends Component
{
    // Cache settings
    // -------------------------------------------------------------------------

    /**
     * The cache key that is used to store the modified time of the project config files, at the time they were last applied.
     */
    const CACHE_KEY = 'projectConfig:files';
    /**
     * The cache key that is used to store the modified time of the project config files, at the time they were last applied or ignored.
     *
     * @since 3.5.0
     */
    const IGNORE_CACHE_KEY = 'projectConfig:ignore';
    /**
     * The cache key that is used to store the loaded project config data.
     */
    const STORED_CACHE_KEY = 'projectConfig:internal';
    /**
     * The cache key that is used to store whether there were any issues writing the project config files out.
     *
     * @since 3.5.0
     */
    const FILE_ISSUES_CACHE_KEY = 'projectConfig:fileIssues';
    /**
     * The cache key that is used to store the current project config diff
     *
     * @since 3.5.8
     */
    const DIFF_CACHE_KEY = 'projectConfig:diff';
    /**
     * The duration that project config caches should be cached.
     */
    const CACHE_DURATION = 31536000; // 1 year
    /**
     * @var string Filename for base config file
     * @since 3.1.0
     */
    const CONFIG_FILENAME = 'project.yaml';
    /**
     * Filename for base config delta files
     *
     * @since 3.4.0
     */
    const CONFIG_DELTA_FILENAME = 'delta.yaml';
    /**
     * The project config key that Craft system info is stored at.
     *
     * @since 3.5.8
     */
    const CONFIG_SYSTEM = 'system';
    /**
     * The project config key that the Craft schema version is stored at.
     */
    const CONFIG_SCHEMA_VERSION_KEY = self::CONFIG_SYSTEM . '.schemaVersion';
    /**
     * The array key to use for signaling ordered-to-associative array conversion.
     *
     * @since 3.4.0
     */
    const CONFIG_ASSOC_KEY = '__assoc__';
    /**
     * @since 3.4.0
     * @deprecated in 3.5.0
     */
    const CONFIG_ALL_KEY = '__all__';
    /**
     * The project config key that Craft uses to store project config names.
     */
    const CONFIG_NAMES_KEY = 'meta.__names__';

    // Regexp patterns
    // -------------------------------------------------------------------------

    /**
     * Regexp pattern to determine a string that could be used as an UID.
     */
    const UID_PATTERN = '[a-zA-Z0-9_-]+';

    // Events
    // -------------------------------------------------------------------------

    /**
     * @event ConfigEvent The event that is triggered when an item is added to the config.
     *
     * ---
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_ADD_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also added in the database...
     * });
     * ```
     */
    const EVENT_ADD_ITEM = 'addItem';

    /**
     * @event ConfigEvent The event that is triggered when an item is updated in the config.
     *
     * ---
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_UPDATE_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also updated in the database...
     * });
     * ```
     */
    const EVENT_UPDATE_ITEM = 'updateItem';

    /**
     * @event ConfigEvent The event that is triggered when an item is removed from the config.
     *
     * ---
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_REMOVE_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also removed in the database...
     * });
     * ```
     */
    const EVENT_REMOVE_ITEM = 'removeItem';

    /**
     * @event Event The event that is triggered after pending project config file changes have been applied.
     */
    const EVENT_AFTER_APPLY_CHANGES = 'afterApplyChanges';

    /**
     * @event RebuildConfigEvent The event that is triggered when the project config is being rebuilt.
     *
     * ---
     *
     * ```php
     * use craft\events\RebuildConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $e) {
     *     // Add plugin's project config data...
     *    $e->config['myPlugin']['key'] = $value;
     * });
     * ```
     *
     * @since 3.1.20
     */
    const EVENT_REBUILD = 'rebuild';

    /**
     * @var bool Whether project config changes should be written to YAML files automatically.
     *
     * If set to `false`, you can manually write out project config YAML files using the `project-config/write` command.
     *
     * ::: warning
     * If this is set to `false`, Craft won’t have a strong grasp of whether the YAML files or database contain the most relevant
     * project config data, so there’s a chance that the Project Config utility will be a bit misleading.
     * :::
     *
     * @see updateYamlFiles()
     * @since 3.5.13
     */
    public bool $writeYamlAutomatically = true;

    /**
     * @var string The folder name to save the project config files in, within the `config/` folder.
     * @since 3.5.0
     */
    public string $folderName = 'project';

    /**
     * @var int The maximum number of project.yaml deltas to store in storage/config-backups/
     * @since 3.4.0
     */
    public int $maxDeltas = 50;

    /**
     * @var int The maximum number of times deferred events can be re-deferred before we give up on them
     * @see defer()
     * @see _applyChanges()
     */
    public int $maxDefers = 500;

    /**
     * @var bool Whether the project config is read-only.
     */
    public bool $readOnly = false;

    /**
     * @var bool Whether events generated by config changes should be muted.
     * @since 3.1.2
     */
    public bool $muteEvents = false;

    /**
     * @var bool Whether project config should force updates on entries that aren't new or being removed.
     */
    public bool $forceUpdate = false;

    /**
     * @var array A list of all external files.
     */
    private array $_configFileList = [];

    /**
     * @var bool Whether the config has been modified during the request and must be saved.
     */
    private bool $_isConfigModified = false;

    /**
     * @var bool Whether the config should be saved to DB after request
     */
    private bool $_updateInternalConfig = false;

    /**
     * @var bool Whether we’re listening for the request end, to update the config parse time caches.
     * @see updateParsedConfigTimes()
     */
    private bool $_waitingToUpdateParsedConfigTimes = false;

    /**
     * @var bool Whether external project config changes are currently being applied.
     * @see getIsApplyingExternalChanges()
     */
    private bool $_applyingExternalChanges = false;

    /**
     * @var bool Whether the config's dateModified timestamp has been updated by this request.
     */
    private bool $_timestampUpdated = false;

    /**
     * @var array Deferred config sync events
     * @see defer()
     * @see _applyChanges()
     */
    private array $_deferredEvents = [];

    /**
     * A running list of all the changes applied during this request
     *
     * @var array
     */
    private array $_appliedChanges = [];

    /**
     * @var ReadOnlyProjectConfigData|null Config as defined in the external config.
     */
    private ?ReadOnlyProjectConfigData $_externalConfig = null;

    /**
     * @var ReadOnlyProjectConfigData|null Current config as stored in database.
     */
    private ?ReadOnlyProjectConfigData $_internalConfig = null;

    /**
     * @var ProjectConfigData|null The currently working config - it consists of the current config plus any changes
     * applied during this request.
     */
    private ?ProjectConfigData $_currentWorkingConfig = null;

    /**
     * @var array[] Config change handlers
     * @see registerChangeEventHandler()
     * @see handleChangeEvent()
     * @since 3.4.0
     */
    private array $_changeEventHandlers = [];

    /**
     * @var array[] The specificity of change event handlers.
     * @see registerChangeEventHandler()
     * @see handleChangeEvent()
     * @see _sortChangeEventHandlers()
     * @since 3.4.0
     */
    private array $_changeEventHandlerSpecificity = [];

    /**
     * @var array[] The registration order of change event handlers.
     * @see registerChangeEventHandler()
     * @see handleChangeEvent()
     * @see _sortChangeEventHandlers()
     * @since 3.4.0
     */
    private array $_changeEventHandlerRegistrationOrder = [];

    /**
     * @var bool[] Whether the change event handlers have been sorted.
     * @see registerChangeEventHandler()
     * @see handleChangeEvent()
     * @see _sortChangeEventHandlers()
     * @since 3.4.0
     */
    private array $_sortedChangeEventHandlers = [];

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        if (isset($config['maxBackups'])) {
            $config['maxDeltas'] = ArrayHelper::remove($config, 'maxBackups');
            Craft::$app->getDeprecator()->log(__CLASS__ . '::maxBackups', '`' . __CLASS__ . '::maxBackups` has been deprecated. Use `maxDeltas` instead.');
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function() {
            $this->saveModifiedConfigData();
        }, null, false);

        $this->on(self::EVENT_ADD_ITEM, [$this, 'handleChangeEvent']);
        $this->on(self::EVENT_UPDATE_ITEM, [$this, 'handleChangeEvent']);
        $this->on(self::EVENT_REMOVE_ITEM, [$this, 'handleChangeEvent']);

        parent::init();
    }

    /**
     * Resets the internal state.
     *
     * @internal
     */
    public function reset(): void
    {
        $this->_internalConfig = null;
        $this->_externalConfig = null;
        $this->_currentWorkingConfig = null;
        $this->_configFileList = [];
        $this->_isConfigModified = false;
        $this->_updateInternalConfig = false;
        $this->_applyingExternalChanges = false;
        $this->_timestampUpdated = false;

        $this->init();
    }

    /**
     * Returns a config item value by its path.
     *
     * ---
     *
     * ```php
     * $value = Craft::$app->projectConfig->get('foo.bar');
     * ```
     *
     * @param string|null $path The config item path, or `null` if the entire config should be returned
     * @param bool $getFromExternalConfig whether data should be fetched from the working config instead of the loaded config. Defaults to `false`.
     * @return mixed The config item value
     */
    public function get(?string $path = null, bool $getFromExternalConfig = false)
    {
        if ($getFromExternalConfig) {
            $source = $this->getExternalConfig();
        } else {
            $source = $this->getCurrentWorkingConfig();
        }

        if ($path === null) {
            return $source->export();
        }

        return $source->get($path);
    }

    /**
     * Sets a config item value at the given path.
     *
     * ---
     *
     * ```php
     * Craft::$app->projectConfig->set('foo.bar', 'value');
     * ```
     *
     * @param string $path The config item path
     * @param mixed $value The config item value
     * @param string|null $message The message describing changes.
     * @param bool $updateTimestamp Whether the `dateModified` value should be updated, if it hasn’t been updated yet for this request
     * @param bool $rebuilding Whether the change should always be processed. This should only used when rebuilding.
     * @throws ErrorException
     * @throws Exception
     * @throws NotSupportedException if the service is set to read-only mode
     * @throws ServerErrorHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function set(string $path, $value, ?string $message = null, bool $updateTimestamp = true, bool $rebuilding = false): void
    {
        if (\is_array($value)) {
            $value = ProjectConfigHelper::cleanupConfig($value);
        }

        $valueHasChanged = $rebuilding;
        $workingConfig = $this->getCurrentWorkingConfig();
        $previousValue = $workingConfig->get($path);

        if (!$rebuilding && $value !== $previousValue) {
            if ($this->readOnly) {
                // If we're applying yaml changes that are coming in via external config, anyway, bail silently.
                if ($this->getIsApplyingExternalChanges() && $value === $this->getExternalConfig()->get($path)) {
                    return;
                }

                throw new NotSupportedException('Changes to the project config are not possible while in read-only mode.');
            }

            if ($updateTimestamp && !$this->_timestampUpdated) {
                $this->_timestampUpdated = true;
                $this->set('dateModified', DateTimeHelper::currentTimeStamp(), 'Update timestamp for project config');
            }

            $valueHasChanged = true;
        }

        if ($valueHasChanged) {
            $this->getCurrentWorkingConfig()->commitChanges($previousValue, $value, $path, $valueHasChanged, $message);
            $this->_saveConfigAfterRequest();
        }
    }

    /**
     * Removes a config item at the given path.
     *
     * ---
     * ```php
     * Craft::$app->projectConfig->remove('foo.bar');
     * ```
     *
     * @param string $path The config item path
     * @param string|null $message The message describing changes.
     */
    public function remove(string $path, ?string $message = null): void
    {
        $this->set($path, null, $message);
    }

    /**
     * Regenerates `project.yaml` based on the loaded project config.
     * @deprecated in 4.0.0. use [[regenerateExternalConfig()]] instead.
     */
    public function regenerateYamlFromConfig(): void
    {
        Craft::$app->getDeprecator()->log(__CLASS__ . '::regenerateYamlFromConfig()', '`' . __CLASS__ . '::regenerateYamlFromConfig()` has been deprecated. Use `regenerateExternalConfig()` instead.');
        $this->regenerateExternalConfig();
    }

    /**
     * Regenerates the external config based on the loaded project config.
     * @since 4.0.0
     */
    public function regenerateExternalConfig(): void
    {
        $this->_applyingExternalChanges = false;

        // Ensure we have the working config
        $this->getCurrentWorkingConfig();

        // And ensure we save it.
        $this->_saveConfigAfterRequest();
        $this->updateParsedConfigTimesAfterRequest();
        $this->saveModifiedConfigData(true);
    }

    /**
     * Applies changes in `project.yaml` to the project config.
     *
     * @deprecated in 4.0.0. Use [[applyExternalChanges()]] instead.
     */
    public function applyYamlChanges(): void
    {
        Craft::$app->getDeprecator()->log(__CLASS__ . '::applyYamlChanges()', '`' . __CLASS__ . '::applyYamlChanges()` has been deprecated. Use `applyExternalChanges()` instead.');
        $this->applyExternalChanges();
    }

    /**
     * Applies changes in external config to project config.
     *
     * @since 4.0.0
     */
    public function applyExternalChanges(): void
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'project-config-sync';

        if (!$mutex->acquire($lockName, 15)) {
            throw new Exception('Could not acquire a lock to apply project config changes.');
        }

        // Disable read/write splitting for the remainder of this request
        Craft::$app->getDb()->enableReplicas = false;

        // Start with a clean slate.
        $this->reset();

        $this->_applyingExternalChanges = true;
        $cache = Craft::$app->getCache();
        $cache->delete(self::CACHE_KEY);
        $cache->delete(self::IGNORE_CACHE_KEY);

        $changes = $this->_getPendingChanges();

        $this->_applyChanges($changes, $this->getCurrentWorkingConfig(), $this->getExternalConfig());
        $anyChangesApplied = (bool)(count($changes['newItems']) + count($changes['removedItems']) + count($changes['changedItems']));

        // Kill the cached config data
        $cache->delete(self::STORED_CACHE_KEY);
        if ($anyChangesApplied) {
            $this->updateConfigVersion();
        }

        $mutex->release($lockName);
    }

    /**
     * Applies given changes to the project config.
     *
     * @param array $configData
     */
    public function applyConfigChanges(array $configData): void
    {
        $this->_applyingExternalChanges = true;

        $changes = $this->_getPendingChanges($configData);
        $this->_applyChanges($changes, $this->getCurrentWorkingConfig(), new ReadOnlyProjectConfigData($configData));
    }

    /**
     * Returns whether project.yaml changes are currently being applied
     *
     * @return bool
     * @deprecated in 4.0.0. Use [[getIsApplyingExternalChanges()]] instead.
     */
    public function getIsApplyingYamlChanges(): bool
    {
        Craft::$app->getDeprecator()->log(__CLASS__ . '::getIsApplyingYamlChanges()', '`' . __CLASS__ . '::applyYamlChanges()` has been deprecated. Use `getIsApplyingExternalChanges()` instead.');
        return $this->getIsApplyingExternalChanges();
    }

    /**
     * Returns whether external changes are currently being applied
     *
     * @return bool
     * @since 4.0.0
     */
    public function getIsApplyingExternalChanges(): bool
    {
        return $this->_applyingExternalChanges;
    }

    /**
     * Returns whether project config YAML files appear to exist.
     *
     * @return bool
     * @since 3.5.13
     * @deprecated in 4.0.0. Use [[getDoesExternalConfigExist()]].
     */
    public function getDoesYamlExist(): bool
    {
        Craft::$app->getDeprecator()->log(__CLASS__ . '::getDoesYamlExist()', '`' . __CLASS__ . '::getDoesYamlExist()` has been deprecated. Use `getDoesExternalConfigExist()` instead.');
        return $this->getDoesExternalConfigExist();
    }

    /**
     * Returns whether external project config files appear to exist.
     *
     * @return bool
     * @since 4.0.0
     */
    public function getDoesExternalConfigExist(): bool
    {
        return file_exists(Craft::$app->getPath()->getProjectConfigFilePath());
    }

    /**
     * Returns whether a given path has pending changes that need to be applied to the loaded project config.
     *
     * @param string|null $path A specific config path that should be checked for pending changes.
     * If this is null, then `true` will be returned if there are *any* pending changes in external config.
     * @param bool $force Whether to check for changes even if it doesn’t look like anything has changed since
     * the last time [[ignorePendingChanges()]] has been called.
     * @return bool
     */
    public function areChangesPending(?string $path = null, bool $force = false): bool
    {
        // If the path is currently being processed, return true
        if ($path !== null && $this->getCurrentWorkingConfig()->getHasPathBeenModified($path)) {
            return true;
        }

        // If the file does not exist, but should, generate it
        if ($this->getHadFileWriteIssues() || !$this->getDoesExternalConfigExist()) {
            if ($this->writeYamlAutomatically) {
                $this->regenerateExternalConfig();
            }

            $this->saveModifiedConfigData();
            return false;
        }

        // If the file modification date hasn't changed, then no need to check the contents
        if (!$this->_areConfigFilesModified($force)) {
            return false;
        }

        if ($path !== null) {
            $oldValue = $this->getInternalConfig()->get($path);
            $newValue = $this->getExternalConfig()->get($path);
            return ProjectConfigHelper::encodeValueAsString($oldValue) !== ProjectConfigHelper::encodeValueAsString($newValue);
        }

        // If the file contents haven't changed, just update the cached file modification date
        if (!$this->_getPendingChanges(null, true)) {
            $this->updateParsedConfigTimes();
            return false;
        }

        // Clear the cached config, just in case it conflicts with what we've got here
        Craft::$app->getCache()->delete(self::STORED_CACHE_KEY);
        $this->_currentWorkingConfig = null;
        return true;
    }

    /**
     * Processes changes in the project config files for a given config item path.
     *
     * Note that this will only have an effect if external project config changes are currently getting [[getIsApplyingExternalChanges()|applied]].
     *
     * @param string $path The config item path
     * @param bool $force Whether the config change should be processed regardless of previous records,
     * or whether external changes are currently being applied
     */
    public function processConfigChanges(string $path, bool $force = false): void
    {
        if ($force || $this->getIsApplyingExternalChanges()) {
            $this->getCurrentWorkingConfig()->commitChanges($this->getInternalConfig()->get($path), $this->getExternalConfig()->get($path), $path, false, null, $force);
        }
    }

    /**
     * Updates the stored config after the request ends.
     */
    public function updateStoredConfigAfterRequest(): void
    {
        $this->_updateInternalConfig = true;
    }

    /**
     * Updates cached config file modified times after the request ends.
     */
    public function updateParsedConfigTimesAfterRequest(): void
    {
        if ($this->_waitingToUpdateParsedConfigTimes) {
            return;
        }

        Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'updateParsedConfigTimes']);
        $this->_waitingToUpdateParsedConfigTimes = true;
    }

    /**
     * Ignores any pending changes in the project config files.
     *
     * @since 3.5.0
     */
    public function ignorePendingChanges(): void
    {
        Craft::$app->getCache()->set(
            self::IGNORE_CACHE_KEY,
            $this->_getConfigFileModifiedTime(),
            self::CACHE_DURATION,
            $this->getCacheDependency()
        );
    }

    /**
     * Updates cached config file modified times immediately.
     *
     * @return bool
     */
    public function updateParsedConfigTimes(): bool
    {
        $time = $this->_getConfigFileModifiedTime();
        return !empty(Craft::$app->getCache()->multiSet([
            self::CACHE_KEY => $time,
            self::IGNORE_CACHE_KEY => $time,
        ], self::CACHE_DURATION));
    }

    /**
     * Saves all the config data that has been modified up to now.
     *
     * @param bool|null $writeExternalConfig Whether to update the external config. Defaults to [[$writeYamlAutomatically]].
     * @throws ErrorException
     */
    public function saveModifiedConfigData(?bool $writeExternalConfig = null): void
    {
        $this->_processProjectConfigNameChanges();

        if ($this->_isConfigModified) {
            $this->updateConfigVersion();

            if ($writeExternalConfig ?? $this->writeYamlAutomatically) {
                $this->updateYamlFiles();
            }
        }

        if (!$this->_updateInternalConfig) {
            return;
        }

        if (!empty($this->_appliedChanges)) {
            $deltaEntry = [
                'dateApplied' => date('Y-m-d H:i:s'),
                'changes' => [],
            ];

            $db = Craft::$app->getDb();

            foreach ($this->_appliedChanges as $changeSet) {
                // Allow modification of the array being looped over.
                $currentSet = $changeSet;

                if (!empty($changeSet['removed'])) {
                    $this->removeInternalConfigValuesByPaths(array_keys($changeSet['removed']));
                }

                if (!empty($changeSet['added'])) {
                    $isMysql = $db->getIsMysql();
                    $batch = [];
                    $pathsToInsert = [];
                    $additionalCleanupPaths = [];

                    foreach ($currentSet['added'] as $key => $value) {
                        // Prepare for storage
                        $dbValue = ProjectConfigHelper::encodeValueAsString($value);
                        if (!mb_check_encoding($value, 'UTF-8') || ($isMysql && StringHelper::containsMb4($dbValue))) {
                            $dbValue = 'base64:' . base64_encode($dbValue);
                        }
                        $batch[$key] = $dbValue;
                        $pathsToInsert[] = $key;

                        // Delete parent key, as it cannot hold a value AND be an array at the same time
                        $additionalCleanupPaths[pathinfo($key, PATHINFO_FILENAME)] = true;

                        // Prepare for delta
                        if (!empty($currentSet['removed']) && array_key_exists($key, $currentSet['removed'])) {
                            if (is_string($changeSet['removed'][$key])) {
                                $changeSet['removed'][$key] = StringHelper::decdec($changeSet['removed'][$key]);
                            }

                            $changeSet['removed'][$key] = Json::decodeIfJson($changeSet['removed'][$key]);

                            // Ensure types
                            if (is_bool($value)) {
                                $changeSet['removed'][$key] = (bool)$changeSet['removed'][$key];
                            } else if (is_int($value)) {
                                $changeSet['removed'][$key] = (int)$changeSet['removed'][$key];
                            }

                            if ($changeSet['removed'][$key] === $value) {
                                unset($changeSet['removed'][$key], $changeSet['added'][$key]);
                            } else if (array_key_exists($key, $changeSet['removed'])) {
                                $changeSet['changed'][$key] = [
                                    'from' => $changeSet['removed'][$key],
                                    'to' => $changeSet['added'][$key],
                                ];

                                unset($changeSet['removed'][$key], $changeSet['added'][$key]);
                            }
                        }
                    }

                    // Store in the DB
                    if (!empty($batch)) {
                        $this->removeInternalConfigValuesByPaths($pathsToInsert);
                        $this->removeInternalConfigValuesByPaths(array_keys($additionalCleanupPaths));
                        $this->persistInternalConfigValues($batch);
                    }
                }

                if (empty($changeSet['added'])) {
                    unset($changeSet['added']);
                }

                if (empty($changeSet['removed'])) {
                    unset($changeSet['removed']);
                }

                if (!empty($changeSet['added']) || !empty($changeSet['removed']) || !empty($changeSet['changed'])) {
                    $deltaEntry['changes'][] = $changeSet;
                }
            }

            if (!empty($deltaEntry['changes'])) {
                $this->storeYamlHistory($deltaEntry);
            }
        }
    }

    /**
     * Remove values from internal config by a list of paths.
     *
     * @param array $paths
     * @throws \yii\db\Exception
     */
    protected function removeInternalConfigValuesByPaths(array $paths): void
    {
        Db::delete(Table::PROJECTCONFIG, [
            'path' => $paths,
        ]);
    }

    /**
     * Persist an array of `$path => $value` to the internal config.
     *
     * @param array $values
     * @throws \yii\db\Exception
     */
    protected function persistInternalConfigValues(array $values): void
    {
        $batch = [];

        foreach ($values as $path => $value) {
            $batch[] = [$path, $value];
        }

        Db::batchInsert(Table::PROJECTCONFIG, ['path', 'value'], $batch, false);
    }

    /**
     * Returns a summary of all pending config changes.
     *
     * @return array
     */
    public function getPendingChangeSummary(): array
    {
        $pendingChanges = $this->_getPendingChanges();

        $summary = [];

        // Reduce all the small changes to overall item changes.
        foreach ($pendingChanges as $type => $changes) {
            $summary[$type] = [];
            foreach ($changes as $path) {
                $pathParts = explode('.', $path);
                if (count($pathParts) > 1) {
                    $summary[$type][$pathParts[0] . '.' . $pathParts[1]] = true;
                }
            }
        }

        return $summary;
    }

    /**
     * Returns whether all schema versions stored in the config are compatible with the actual codebase.
     * The schemas must match exactly to avoid unpredictable behavior that can occur when running migrations
     * and applying project config changes at the same time.
     *
     * @param array $issues Passed by reference and populated with issues on error in
     *                      the following format: `[$pluginName, $existingSchema, $incomingSchema]`
     * @return bool
     */
    public function getAreConfigSchemaVersionsCompatible(array &$issues = []): bool
    {
        $incomingSchema = (string)$this->getExternalConfig()->get(self::CONFIG_SCHEMA_VERSION_KEY);
        $existingSchema = Craft::$app->schemaVersion;

        // Compare existing Craft schema version with the one that is being applied.
        if (!version_compare($existingSchema, $incomingSchema, '=')) {
            $issues[] = [
                'cause' => 'Craft CMS',
                'existing' => $existingSchema,
                'incoming' => $incomingSchema,
            ];
        }

        $plugins = Craft::$app->getPlugins()->getAllPlugins();

        foreach ($plugins as $plugin) {
            $incomingSchema = (string)$this->getExternalConfig()->get(Plugins::CONFIG_PLUGINS_KEY . '.' . $plugin->handle . '.schemaVersion');
            $existingSchema = (string)$plugin->schemaVersion;

            // Compare existing plugin schema version with the one that is being applied.
            if ($incomingSchema && !version_compare($existingSchema, $incomingSchema, '=')) {
                $issues[] = [
                    'cause' => $plugin->name,
                    'existing' => $existingSchema,
                    'incoming' => $incomingSchema,
                ];
            }
        }

        return empty($issues);
    }

    // Config Change Event Registration
    // -------------------------------------------------------------------------

    /**
     * Attaches an event handler for when an item is added to the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     * use craft\helpers\Db;
     *
     * Craft::$app->projectConfig->onAdd('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Prep the row data
     *     $data = array_merge($event->newValue);
     *
     *     // See if the row already exists (maybe it was soft-deleted)
     *     $id = Db::idByUid('{{%tablename}}', $uid);
     *
     *     if ($id) {
     *         $data['dateDeleted'] = null;
     *         Craft::$app->db->createCommand()->update('{{%tablename}}', $data, [
     *             'id' => $id,
     *         ]);
     *     } else {
     *         $data['uid'] = $uid;
     *         Craft::$app->db->createCommand()->insert('{{%tablename}}', $data);
     *     }
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     * @return static self reference
     */
    public function onAdd(string $path, callable $handler, $data = null): self
    {
        $this->registerChangeEventHandler(self::EVENT_ADD_ITEM, $path, $handler, $data);
        return $this;
    }

    /**
     * Attaches an event handler for when an item is updated in the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     *
     * Craft::$app->projectConfig->onUpdate('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Update the item in the database
     *     $data = array_merge($event->newValue);
     *     Craft::$app->db->createCommand()->update('{{%tablename}}', $data, [
     *         'uid' => $uid,
     *     ]);
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     * @return static self reference
     */
    public function onUpdate(string $path, callable $handler, $data = null): self
    {
        $this->registerChangeEventHandler(self::EVENT_UPDATE_ITEM, $path, $handler, $data);
        return $this;
    }

    /**
     * Attaches an event handler for when an item is removed from the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     *
     * Craft::$app->projectConfig->onRemove('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Soft-delete the item from the database
     *     Craft::$app->db->createCommand()->softDelete('{{%tablename}}', [
     *         'uid' => $uid,
     *     ]);
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     * @return static self reference
     */
    public function onRemove(string $path, callable $handler, $data = null): self
    {
        $this->registerChangeEventHandler(self::EVENT_REMOVE_ITEM, $path, $handler, $data);
        return $this;
    }

    /**
     * Defers an event until all other project config changes have been processed.
     *
     * @param ConfigEvent $event
     * @param callable $handler
     * @since 3.1.13
     */
    public function defer(ConfigEvent $event, callable $handler): void
    {
        Craft::info('Deferring event handler for ' . $event->path, __METHOD__);
        $this->_deferredEvents[] = [$event, $event->tokenMatches, $handler];
    }

    /**
     * Registers a config change event listener, for a specific config path pattern.
     *
     * @param string $event The event name
     * @param string $path The config path pattern. Can contain `{uid}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     */
    public function registerChangeEventHandler(string $event, string $path, callable $handler, $data = null): void
    {
        $specificity = substr_count($path, '.');
        $pattern = '/^(?P<path>' . preg_quote($path, '/') . ')(?P<extra>\..+)?$/';
        $pattern = str_replace('\\{uid\\}', '(' . self::UID_PATTERN . ')', $pattern);

        $this->_changeEventHandlers[$event][] = [$pattern, $handler, $data];
        $this->_changeEventHandlerSpecificity[$event][] = $specificity;
        $this->_changeEventHandlerRegistrationOrder[$event][] = count($this->_changeEventHandlers[$event]);
        unset($this->_sortedChangeEventHandlers[$event]);
    }

    /**
     * Handles a config change event.
     *
     * @param ConfigEvent $event
     * @since 3.4.0
     */
    public function handleChangeEvent(ConfigEvent $event): void
    {
        if (empty($this->_changeEventHandlers[$event->name])) {
            return;
        }

        // Make sure the event handlers are sorted from least-to-most specific
        $this->_sortChangeEventHandlers($event->name);

        foreach ($this->_changeEventHandlers[$event->name] as [$pattern, $handler, $data]) {
            if (preg_match($pattern, $event->path, $matches)) {
                // Is this a nested path?
                if (isset($matches['extra'])) {
                    $path = $matches['path'];
                    $incomingConfig = $this->getIsApplyingExternalChanges() ? $this->getExternalConfig() : $this->getCurrentWorkingConfig();
                    $this->getCurrentWorkingConfig()->commitChanges($this->getInternalConfig()->get($path), $incomingConfig->get($path), $path);
                    continue;
                }

                // Chop off [0] (full match) and ['path'] & [1] (requested path)
                $event->tokenMatches = array_values(array_slice($matches, 3));

                // Set the event data
                $event->data = $data;

                $handler($event);

                $event->tokenMatches = null;
                $event->data = null;
            }
        }
    }

    /**
     * Ensures that the config change event handlers are sorted by least-to-most specific.
     *
     * @param string $event The event name
     * @since 3.4.0
     */
    private function _sortChangeEventHandlers(string $event): void
    {
        if (isset($this->_sortedChangeEventHandlers[$event])) {
            return;
        }

        array_multisort(
            $this->_changeEventHandlerSpecificity[$event], SORT_ASC, SORT_NUMERIC,
            $this->_changeEventHandlerRegistrationOrder[$event], SORT_ASC, SORT_NUMERIC,
            $this->_changeEventHandlers[$event]);

        $this->_sortedChangeEventHandlers[$event] = true;
    }

    /**
     * Rebuilds the project config from the current state in the database.
     *
     * @throws \Throwable if reasons
     * @since 3.1.20
     */
    public function rebuild(): void
    {
        $this->reset();

        $this->muteEvents = true;
        $readOnly = $this->readOnly;
        $this->readOnly = false;

        $config = $this->getInternalConfig()->export();
        $config['dateModified'] = DateTimeHelper::currentTimeStamp();
        $config[self::CONFIG_SYSTEM] = $this->_systemConfig($config[self::CONFIG_SYSTEM] ?? []);
        $config[Sites::CONFIG_SITEGROUP_KEY] = $this->_getSiteGroupData();
        $config[Sites::CONFIG_SITES_KEY] = $this->_getSiteData();
        $config[Sections::CONFIG_SECTIONS_KEY] = $this->_getSectionData();
        $config[Sections::CONFIG_ENTRYTYPES_KEY] = $this->_getEntryTypeData();
        $config[Fields::CONFIG_FIELDGROUP_KEY] = $this->_getFieldGroupData();
        $config[Fields::CONFIG_FIELDS_KEY] = $this->_getFieldData();
        $config[Matrix::CONFIG_BLOCKTYPE_KEY] = $this->_getMatrixBlockTypeData();
        $config[Volumes::CONFIG_VOLUME_KEY] = $this->_getVolumeData();
        $config[Categories::CONFIG_CATEGORYROUP_KEY] = $this->_getCategoryGroupData();
        $config[Tags::CONFIG_TAGGROUP_KEY] = $this->_getTagGroupData();
        $config[Users::CONFIG_USERS_KEY] = $this->_getUserData($config[Users::CONFIG_USERS_KEY] ?? []);
        $config[Globals::CONFIG_GLOBALSETS_KEY] = $this->_getGlobalSetData();
        $config[Plugins::CONFIG_PLUGINS_KEY] = $this->_getPluginData($config[Plugins::CONFIG_PLUGINS_KEY] ?? []);
        $config[AssetTransforms::CONFIG_TRANSFORM_KEY] = $this->_getTransformData();
        $config[Gql::CONFIG_GQL_KEY] = $this->_getGqlData();

        // Fire a 'rebuild' event
        $event = new RebuildConfigEvent([
            'config' => $config,
        ]);
        $this->trigger(self::EVENT_REBUILD, $event);

        // Process the changes
        foreach ($event->config as $path => $value) {
            $this->set($path, $value, 'Project config rebuild', false, true);
        }

        // Make sure we save it all.
        $this->_saveConfigAfterRequest();
        $this->updateConfigVersion();

        if ($this->writeYamlAutomatically) {
            $this->_processProjectConfigNameChanges();
            $this->updateYamlFiles();
        }

        // And now ensure that Project Config doesn't attempt to export the config again
        $this->_isConfigModified = false;
        $this->_updateInternalConfig = true;

        $this->readOnly = $readOnly;
        $this->muteEvents = false;
    }

    /**
     * Applies changes from a configuration array.
     *
     * @param array $changes array nested array with keys `removedItems`, `changedItems` and `newItems`
     * @param ReadOnlyProjectConfigData $existingConfig The config data repository that holds the current data
     * @param ReadOnlyProjectConfigData $incomingConfig The config data repository that holds the incoming data
     * @throws OperationAbortedException
     */
    private function _applyChanges(array $changes, ReadOnlyProjectConfigData $existingConfig, ReadOnlyProjectConfigData $incomingConfig): void
    {
        Craft::info('Looking for pending changes', __METHOD__);

        $processChanges = fn ($path) => $this->getCurrentWorkingConfig()->commitChanges($existingConfig->get($path), $incomingConfig->get($path), $path, false, null, true);

        // If we're parsing all the changes, we better work the actual config map.
        if (!empty($changes['removedItems'])) {
            Craft::info('Parsing ' . count($changes['removedItems']) . ' removed configuration items', __METHOD__);
            foreach ($changes['removedItems'] as $itemPath) {
                $processChanges($itemPath);
            }
        }

        if (!empty($changes['changedItems'])) {
            Craft::info('Parsing ' . count($changes['changedItems']) . ' changed configuration items', __METHOD__);
            foreach ($changes['changedItems'] as $itemPath) {
                $processChanges($itemPath);
            }
        }

        if (!empty($changes['newItems'])) {
            Craft::info('Parsing ' . count($changes['newItems']) . ' new configuration items', __METHOD__);
            foreach ($changes['newItems'] as $itemPath) {
                $processChanges($itemPath);
            }
        }

        $defers = -count($this->_deferredEvents);
        while (!empty($this->_deferredEvents)) {
            if ($defers > $this->maxDefers) {
                $paths = [];

                // Grab a list of all deferred event paths
                foreach ($this->_deferredEvents as [$deferredEvent]) {
                    // Save us the trouble of filtering out duplicates later
                    $paths[$deferredEvent->path] = true;
                }

                $message = "The following config paths could not be processed successfully:\n" . implode("\n", array_keys($paths));
                throw new OperationAbortedException($message);
            }

            /** @var ConfigEvent $event */
            /** @var string[]|null $tokenMatches */
            /** @var callable $handler */
            [$event, $tokenMatches, $handler] = array_shift($this->_deferredEvents);
            Craft::info('Re-triggering deferred event for ' . $event->path, __METHOD__);
            $event->tokenMatches = $tokenMatches;
            $handler($event);
            $event->tokenMatches = null;
            $defers++;
        }

        Craft::info('Finalizing configuration parsing', __METHOD__);

        // Fire an 'afterApplyChanges' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_APPLY_CHANGES)) {
            $this->trigger(self::EVENT_AFTER_APPLY_CHANGES);
        }

        $this->updateParsedConfigTimesAfterRequest();
        $this->_applyingExternalChanges = false;
    }

    /**
     * Retrieve a config file tree with modified times based on the main configuration file.
     *
     * @return int
     */
    private function _getConfigFileModifiedTime(): int
    {
        $path = Craft::$app->getPath()->getProjectConfigFilePath();
        if (!file_exists($path)) {
            return 0;
        }
        return filemtime($path);
    }

    /**
     * Load the config stored in the external storage.
     *
     * @return ReadOnlyProjectConfigData
     */
    private function _loadExternalConfig(): ReadOnlyProjectConfigData
    {
        // If the external config does not exist, just use the loaded config
        if ($this->getHadFileWriteIssues() || !$this->getDoesExternalConfigExist()) {
            return $this->getCurrentWorkingConfig();
        }

        $fileList = $this->_getConfigFileList();
        $generatedConfig = [];
        $projectConfigPathLength = strlen(Craft::$app->getPath()->getProjectConfigPath(false));

        foreach ($fileList as $filePath) {
            $yamlConfig = Yaml::parse(file_get_contents($filePath));
            $subPath = substr($filePath, $projectConfigPathLength + 1);

            if (StringHelper::countSubstrings($subPath, DIRECTORY_SEPARATOR) > 0) {
                $configPath = explode(DIRECTORY_SEPARATOR, $subPath);
                $filename = pathinfo(array_pop($configPath), PATHINFO_FILENAME);
                $insertionPoint = &$generatedConfig;

                foreach ($configPath as $pathSegment) {
                    if (!isset($insertionPoint[$pathSegment])) {
                        $insertionPoint[$pathSegment] = [];
                    }

                    $insertionPoint = &$insertionPoint[$pathSegment];
                }

                /** @var string $pathSegment */
                /** @phpstan-ignore-next-line */
                if ($pathSegment === $filename) {
                    $insertionPoint = array_merge($insertionPoint, $yamlConfig);
                } else {
                    // Is this in the <handle>--<uid> format?
                    if (preg_match('/^\w+--(' . StringHelper::UUID_PATTERN . ')$/', $filename, $match)) {
                        // Ignore the handle
                        $filename = $match[1];
                    }
                    $insertionPoint[$filename] = $yamlConfig;
                }
            } else {
                $generatedConfig = array_merge($generatedConfig, $yamlConfig);
            }
        }

        return new ReadOnlyProjectConfigData($generatedConfig);
    }

    /**
     * Return a nested array for pending config changes
     *
     * @param array|null $configData config data to use. If null, the config is fetched from the project config files.
     * @param bool $existsOnly whether to just return `true` or `false` depending on whether any changes are found.
     * @return array|bool
     */
    private function _getPendingChanges(?array $configData = null, bool $existsOnly = false)
    {
        $newItems = [];
        $changedItems = [];

        $currentConfig = $this->getCurrentWorkingConfig()->export();

        if ($configData === null) {
            $configData = $this->getExternalConfig()->export();
        }

        unset($configData['imports'], $currentConfig['imports']);

        // flatten both configs so we can compare them.
        $flatConfig = [];
        $flatCurrent = [];

        ProjectConfigHelper::flattenConfigArray($configData, '', $flatConfig);
        ProjectConfigHelper::flattenConfigArray($currentConfig, '', $flatCurrent);

        // Compare and if something is different, mark the immediate parent as changed.
        foreach ($flatConfig as $key => $value) {
            // Drop the last part of path
            $immediateParent = pathinfo($key, PATHINFO_FILENAME);

            if (!array_key_exists($key, $flatCurrent)) {
                if ($existsOnly) {
                    return true;
                }
                $newItems[] = $immediateParent;
            } else if ($this->forceUpdate || $flatCurrent[$key] !== $value) {
                if ($existsOnly) {
                    return true;
                }
                $changedItems[] = $immediateParent;
            }

            unset($flatCurrent[$key]);
        }

        if ($existsOnly) {
            return !empty($flatCurrent);
        }

        $removedItems = array_keys($flatCurrent);

        foreach ($removedItems as &$removedItem) {
            // Drop the last part of path
            $removedItem = pathinfo($removedItem, PATHINFO_FILENAME);
        }

        unset($removedItem);

        // Sort by number of dots to ensure deepest paths listed first
        $sorter = function($a, $b) {
            $aDepth = substr_count($a, '.');
            $bDepth = substr_count($b, '.');

            if ($aDepth === $bDepth) {
                return 0;
            }

            return $aDepth > $bDepth ? -1 : 1;
        };

        $newItems = array_unique($newItems);
        $removedItems = array_unique($removedItems);
        $changedItems = array_unique($changedItems);

        uasort($newItems, $sorter);
        uasort($removedItems, $sorter);
        uasort($changedItems, $sorter);

        return compact('newItems', 'removedItems', 'changedItems');
    }

    /**
     * Return true if the config files have been modified since last we checked.
     *
     * @param bool $force Whether to check for changes even if it doesn’t look like anything has changed since
     * the last time [[ignorePendingChanges()]] has been called.
     * @return bool
     */
    private function _areConfigFilesModified(bool $force): bool
    {
        $cachedModifiedTime = Craft::$app->getCache()->get($force ? self::CACHE_KEY : self::IGNORE_CACHE_KEY);
        return (
            !$cachedModifiedTime ||
            $this->_getConfigFileModifiedTime() !== $cachedModifiedTime
        );
    }

    /**
     * Figure out the entire list of yaml config files
     *
     * @return array
     */
    private function _getConfigFileList(): array
    {
        if (!empty($this->_configFileList)) {
            return $this->_configFileList;
        }

        return $this->_configFileList = $this->_findConfigFiles();
    }

    /**
     * Finds all of the `.yaml` files in the `config/project/` folder.
     *
     * @param string|null $path
     * @return string[]
     */
    private function _findConfigFiles(?string $path = null): array
    {
        if ($path === null) {
            $path = Craft::$app->getPath()->getProjectConfigPath(false);
        }
        if (!is_dir($path)) {
            return [];
        }
        return FileHelper::findFiles($path, [
            'only' => ['*.yaml'],
            'caseSensitive' => false,
        ]);
    }

    /**
     * Save configuration data after the request.
     *
     * @param array $data
     */
    private function _saveConfigAfterRequest(): void
    {
        $this->_isConfigModified = true;
    }

    /**
     * Store yaml history
     *
     * @param array $configData config data to be saved as history
     * @throws Exception
     */
    protected function storeYamlHistory(array $configData): void
    {
        $basePath = Craft::$app->getPath()->getConfigDeltaPath() . '/' . self::CONFIG_DELTA_FILENAME;

        // Go through all of them and move them forward.
        for ($i = $this->maxDeltas; $i > 0; $i--) {
            $thisFile = $basePath . ($i == 1 ? '' : '.' . ($i - 1));
            if (file_exists($thisFile)) {
                if ($i === $this->maxDeltas) {
                    @unlink($thisFile);
                } else {
                    @rename($thisFile, "$basePath.$i");
                }
            }
        }

        file_put_contents($basePath, Yaml::dump($configData, 20, 2));
    }

    /**
     * Create a Query object ready to retrieve internal project config values.
     *
     * @return Query
     */
    private function _createProjectConfigQuery(): Query
    {
        return (new Query())
            ->select(['path', 'value'])
            ->from([Table::PROJECTCONFIG]);
    }

    /**
     * Updates the config version used for cache invalidation.
     */
    protected function updateConfigVersion(): void
    {
        $info = Craft::$app->getInfo();
        $info->configVersion = StringHelper::randomString(12);
        Craft::$app->saveInfo($info, ['configVersion']);
    }

    /**
     * Update the config Yaml files with the buffered changes.
     *
     * @throws Exception if something goes wrong
     */
    protected function updateYamlFiles(): void
    {
        $config = ProjectConfigHelper::splitConfigIntoComponents($this->getCurrentWorkingConfig()->export());

        try {
            $basePath = Craft::$app->getPath()->getProjectConfigPath();

            // Delete everything except hidden files/folders
            FileHelper::clearDirectory($basePath, [
                'except' => ['.*', '.*/'],
            ]);

            $projectConfigNames = $this->getInternalConfig()->get(self::CONFIG_NAMES_KEY);

            $uids = [];
            $replacements = [];

            if (!empty($projectConfigNames)) {
                foreach ($projectConfigNames as $uid => $name) {
                    $uids[] = '/^(.*' . preg_quote($uid) . '.*)$/mi';
                    $replacements[] = '$1 # ' . $name;
                }
            }

            foreach ($config as $relativeFile => $configData) {
                $configData = ProjectConfigHelper::cleanupConfig($configData);
                ksort($configData);
                $filePath = $basePath . DIRECTORY_SEPARATOR . $relativeFile;
                $yamlContent = Yaml::dump($configData, 20, 2);

                if (!empty($uids)) {
                    $yamlContent = preg_replace($uids, $replacements, $yamlContent);
                }

                FileHelper::writeToFile($filePath, $yamlContent);
            }
        } catch (\Throwable $e) {
            Craft::$app->getCache()->set(self::FILE_ISSUES_CACHE_KEY, true, self::CACHE_DURATION);
            if (isset($basePath)) {
                // Try to delete everything (again?) so Craft doesn't apply half-baked project config data
                try {
                    FileHelper::clearDirectory($basePath, [
                        'except' => ['.*', '.*/'],
                    ]);
                } catch (\Throwable $e) {
                    // oh well
                }
            }
            throw new Exception('Unable to write new project config files', 0, $e);
        }

        Craft::$app->getCache()->delete(self::FILE_ISSUES_CACHE_KEY);
    }

    /**
     * Process any queued up project config name changes.
     *
     * @throws \yii\db\Exception
     */
    private function _processProjectConfigNameChanges(): void
    {
        if (!$this->readOnly) {
            foreach ($this->getCurrentWorkingConfig()->getProjectConfigNameChanges() as $uid => $name) {
                $this->set(self::CONFIG_NAMES_KEY . '.' . $uid, $name);
            }
        }
    }

    /**
     * Returns whether we have a record of issues writing out files to the project config folder.
     *
     * @return bool
     * @since 3.5.0
     */
    public function getHadFileWriteIssues(): bool
    {
        return $this->writeYamlAutomatically && Craft::$app->getCache()->get(self::FILE_ISSUES_CACHE_KEY);
    }

    /**
     * Update Craft's internal config store for a path with the new value. If the value
     * is null, it will be removed instead.
     *
     * @param string $path
     * @param mixed $oldValue
     * @param mixed $newValue
     * @param string|null $message message describing the changes made.
     */
    public function rememberAppliedChanges(string $path, $oldValue, $newValue, ?string $message = null): void
    {
        $appliedChanges = [];

        $modified = ProjectConfigHelper::encodeValueAsString($oldValue) !== ProjectConfigHelper::encodeValueAsString($newValue);

        if ($newValue !== null && ($oldValue === null || $modified)) {
            if (!is_scalar($newValue)) {
                $flatData = [];
                ProjectConfigHelper::flattenConfigArray($newValue, $path, $flatData);
            } else {
                $flatData = [$path => $newValue];
            }

            $appliedChanges['added'] = $flatData;
        }

        if ($oldValue && ($newValue === null || $modified)) {
            if (!is_scalar($oldValue)) {
                $flatData = [];
                ProjectConfigHelper::flattenConfigArray($oldValue, $path, $flatData);
            } else {
                $flatData = [$path => $oldValue];
            }

            $appliedChanges['removed'] = $flatData;
        }

        if ($message) {
            $appliedChanges['message'] = $message;
        }

        $this->_appliedChanges[] = $appliedChanges;
    }

    /**
     * Get the external project config data.
     *
     * @return ReadOnlyProjectConfigData
     */
    protected function getExternalConfig(): ReadOnlyProjectConfigData
    {
        if ($this->_externalConfig === null) {
            $this->_externalConfig = $this->_loadExternalConfig();
        }

        return $this->_externalConfig;
    }

    /**
     * Get the internal project config data.
     *
     * @return ReadOnlyProjectConfigData
     */
    protected function getInternalConfig(): ReadOnlyProjectConfigData
    {
        if ($this->_internalConfig === null) {
            $this->_internalConfig = $this->_loadInternalConfig();
        }

        return $this->_internalConfig;
    }

    /**
     * Get the current working project config data.
     *
     * @return ProjectConfigData
     */
    protected function getCurrentWorkingConfig(): ProjectConfigData
    {
        if ($this->_currentWorkingConfig === null) {
            $this->_currentWorkingConfig = new ProjectConfigData($this->getInternalConfig()->export());
        }

        return $this->_currentWorkingConfig;
    }

    /**
     * Load the config stored in the Db
     *
     * @return ReadOnlyProjectConfigData
     */
    private function _loadInternalConfig(): ReadOnlyProjectConfigData
    {
        if (!Craft::$app->getIsInstalled()) {
            return new ReadOnlyProjectConfigData();
        }

        if (Craft::$app->getIsInstalled() && version_compare(Craft::$app->getInfo()->schemaVersion, '3.1.1', '<')) {
            return new ReadOnlyProjectConfigData();
        }

        if (Craft::$app->getIsInstalled() && version_compare(Craft::$app->getInfo()->schemaVersion, '3.4.4', '<')) {
            $config = (new Query())
                ->select(['config'])
                ->from([Table::INFO])
                ->scalar();

            $data = [];

            if ($config) {
                // Try to decode it in case it contains any 4+ byte characters
                $config = StringHelper::decdec($config);
                if (strpos($config, '{') === 0) {
                    $data = Json::decode($config);
                } else {
                    $data = unserialize($config, ['allowed_classes' => false]);
                }
            }

            return new ReadOnlyProjectConfigData($data);
        }

        // See if we can get away with using the cached data
        $data = Craft::$app->getCache()->getOrSet(self::STORED_CACHE_KEY, function() {
            $data = [];
            // Load the project config data
            $rows = $this->_createProjectConfigQuery()->orderBy('path')->pairs();
            foreach ($rows as $path => $value) {
                $current = &$data;
                $segments = explode('.', $path);
                foreach ($segments as $segment) {
                    // If we're still traversing, enforce array to avoid errors.
                    if (!is_array($current)) {
                        $current = [];
                    }
                    if (!array_key_exists($segment, $current)) {
                        $current[$segment] = [];
                    }
                    $current = &$current[$segment];
                }
                $current = Json::decode(StringHelper::decdec($value));
            }
            return ProjectConfigHelper::cleanupConfig($data);
        }, null, $this->getCacheDependency());

        return new ReadOnlyProjectConfigData($data);
    }

    /**
     * Returns the cache dependency that should be used for project config caches.
     *
     * @return ExpressionDependency
     * @since 3.5.8
     */
    public function getCacheDependency(): ExpressionDependency
    {
        return new ExpressionDependency([
            'expression' => Craft::class . '::$app->getInfo()->configVersion',
        ]);
    }

    /**
     * Returns the system config array.
     *
     * @param array $data
     * @return array
     */
    private function _systemConfig(array $data): array
    {
        $data['schemaVersion'] = Craft::$app->schemaVersion;
        return $data;
    }

    /**
     * Return site data config array.
     *
     * @return array
     */
    private function _getSiteGroupData(): array
    {
        $data = [];
        foreach (Craft::$app->getSites()->getAllGroups() as $group) {
            $data[$group->uid] = $group->getConfig();
        }
        return $data;
    }

    /**
     * Return site data config array.
     *
     * @return array
     */
    private function _getSiteData(): array
    {
        $data = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $data[$site->uid] = $site->getConfig();
        }
        return $data;
    }

    /**
     * Return section data config array.
     *
     * @return array
     */
    private function _getSectionData(): array
    {
        $data = [];
        foreach (Craft::$app->getSections()->getAllSections() as $section) {
            $data[$section->uid] = $section->getConfig();
        }
        return $data;
    }

    /**
     * Return entry type data config array.
     *
     * @return array
     */
    private function _getEntryTypeData(): array
    {
        $data = [];
        foreach (Craft::$app->getSections()->getAllEntryTypes() as $entryType) {
            $data[$entryType->uid] = $entryType->getConfig();
        }
        return $data;
    }

    /**
     * Return field data config array.
     *
     * @return array
     */
    private function _getFieldGroupData(): array
    {
        $data = [];
        foreach (Craft::$app->getFields()->getAllGroups() as $group) {
            $data[$group->uid] = $group->getConfig();
        }
        return $data;
    }

    /**
     * Return field data config array.
     *
     * @return array
     */
    private function _getFieldData(): array
    {
        $data = [];
        $fieldsService = Craft::$app->getFields();
        foreach ($fieldsService->getAllFields('global') as $field) {
            $data[$field->uid] = $fieldsService->createFieldConfig($field);
        }
        return $data;
    }

    /**
     * Return matrix block type data config array.
     *
     * @return array
     */
    private function _getMatrixBlockTypeData(): array
    {
        $data = [];
        foreach (Craft::$app->getMatrix()->getAllBlockTypes() as $blockType) {
            $data[$blockType->uid] = $blockType->getConfig();
        }
        return $data;
    }

    /**
     * Return volume data config array.
     *
     * @return array
     */
    private function _getVolumeData(): array
    {
        $data = [];
        $volumesService = Craft::$app->getVolumes();
        foreach ($volumesService->getAllVolumes() as $volume) {
            $data[$volume->uid] = $volumesService->createVolumeConfig($volume);
        }
        return $data;
    }

    /**
     * Return user data config array.
     *
     * @param array $data
     * @return array
     */
    private function _getUserData(array $data): array
    {
        $fieldLayout = Craft::$app->getFields()->getLayoutByType(User::class);
        if ($fieldLayoutConfig = $fieldLayout->getConfig()) {
            $data['fieldLayouts'] = [
                $fieldLayout->uid => $fieldLayoutConfig,
            ];
        } else {
            unset($data['fieldLayouts']);
        }

        $data['groups'] = [];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $data['groups'][$group->uid] = $group->getConfig();
        }

        return $data;
    }

    /**
     * Return category group data config array.
     *
     * @return array
     */
    private function _getCategoryGroupData(): array
    {
        $data = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $data[$group->uid] = $group->getConfig();
        }
        return $data;
    }

    /**
     * Return tag group data config array.
     *
     * @return array
     */
    private function _getTagGroupData(): array
    {
        $data = [];
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $data[$group->uid] = $group->getConfig();
        }
        return $data;
    }

    /**
     * Return global set data config array.
     *
     * @return array
     */
    private function _getGlobalSetData(): array
    {
        $data = [];
        foreach (Craft::$app->getGlobals()->getAllSets() as $globalSet) {
            $data[$globalSet->uid] = $globalSet->getConfig();
        }
        return $data;
    }

    /**
     * Return plugin data config array
     *
     * @param array $currentPluginData
     * @return array
     */
    private function _getPluginData(array $currentPluginData): array
    {
        $plugins = (new Query())
            ->select([
                'handle',
                'schemaVersion',
            ])
            ->from([Table::PLUGINS])
            ->all();

        $pluginData = [];

        foreach ($plugins as $plugin) {
            $pluginData[$plugin['handle']] = array_merge(
                $currentPluginData[$plugin['handle']] ?? [],
                [
                    'schemaVersion' => $plugin['schemaVersion'],
                ]
            );
        }

        return $pluginData;
    }

    /**
     * Return asset transform config array
     *
     * @return array
     */
    private function _getTransformData(): array
    {
        $transformRows = (new Query())
            ->select([
                'name',
                'handle',
                'mode',
                'position',
                'width',
                'height',
                'format',
                'quality',
                'interlace',
                'uid',
            ])
            ->from([Table::ASSETTRANSFORMS])
            ->indexBy('uid')
            ->all();

        foreach ($transformRows as &$row) {
            unset($row['uid']);
            $row['width'] = (int)$row['width'] ?: null;
            $row['height'] = (int)$row['height'] ?: null;
            $row['quality'] = (int)$row['quality'] ?: null;
        }

        return $transformRows;
    }

    /**
     * Return GraphQL config array
     *
     * @return array
     */
    private function _getGqlData(): array
    {
        $gqlService = Craft::$app->getGql();
        $publicToken = $gqlService->getPublicToken();

        $data = [
            'schemas' => [],
            'publicToken' => [
                'enabled' => (bool)($publicToken->enabled ?? false),
                'expiryDate' => ($publicToken->expiryDate ?? false) ? $publicToken->expiryDate->getTimestamp() : null,
            ],
        ];

        foreach ($gqlService->getSchemas() as $schema) {
            $data['schemas'][$schema->uid] = $schema->getConfig();
        }

        return $data;
    }
}
