# Release Notes for Craft CMS 4.1 (WIP)

### Added
- The `AdminTable` Vue component can now be included into other Vue apps, in addition to being used as a standalone app. ([#11107](https://github.com/craftcms/cms/pull/11107))
- Added a `one()` alias for `first()` to collections. ([#11134](https://github.com/craftcms/cms/discussions/11134))
- Added `craft\console\controllers\UsersController::$activate`.
- Added `craft\elements\conditions\ElementCondition::$sourceKey`.
- Added `craft\elements\db\ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS`. ([#11262](https://github.com/craftcms/cms/discussions/11262))
- Added `craft\events\PopulateElementsEvent`.
- Added `craft\helpers\DateTimeHelper::now()`.
- Added `craft\helpers\DateTimeHelper::pause()`. ([#11130](https://github.com/craftcms/cms/pull/11130))
- Added `craft\helpers\DateTimeHelper::resume()`. ([#11130](https://github.com/craftcms/cms/pull/11130))

### Changed
- Improved pagination UI accessibility. ([#11126](https://github.com/craftcms/cms/pull/11126))
- Improved element index accessibility. ([#11169](https://github.com/craftcms/cms/pull/11169))
- Live Preview now always shows a “Refresh” button, regardless of whether the preview target has auto-refresh enabled. ([#11160](https://github.com/craftcms/cms/discussions/11160)) 
- Entry Type condition rules now allow multiple selections. ([#11124](https://github.com/craftcms/cms/pull/11124))
- Element index filters now only show condition rules for the custom fields that are used by the field layouts in the selected source, if a native source is selected. ([#11187](https://github.com/craftcms/cms/discussions/11187))
- Sites’ Language settings now display the locale IDs as option hints, rather than the languages’ native names. ([#11195](https://github.com/craftcms/cms/discussions/11195))
- Selectize options can now specify searchable `keywords` that won’t be visible in the UI.
- Selectize inputs will now include their options’ values as search keywords.
- Newly-created entries now get placeholder Post Date set on them, so they get sorted appropriately when querying for entries ordered by `postDate`. ([#11272](https://github.com/craftcms/cms/issues/11272)) 
- The `users/create` command now asks whether the user should be activated when saved.
- Deprecation messages are now consistently referred to as “deprecation warnings” in the control panel.

### Removed
- Removed `craft\elements\conditions\entries\EntryTypeCondition::$sectionUid`.
- Removed `craft\elements\conditions\entries\EntryTypeCondition::$entryTypeUid`.
