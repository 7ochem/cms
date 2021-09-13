/** global: Craft */
/** global: Garnish */
/**
 * Customize Sources modal
 */
Craft.CustomizeSourcesModal = Garnish.Modal.extend({
    elementIndex: null,
    $elementIndexSourcesContainer: null,

    $sidebar: null,
    $sourcesContainer: null,
    $sourceSettingsContainer: null,
    $newHeadingBtn: null,
    $newSourceBtn: null,
    $footer: null,
    $footerBtnContainer: null,
    $saveBtn: null,
    $cancelBtn: null,
    $saveSpinner: null,
    $loadingSpinner: null,

    sourceSort: null,
    sources: null,
    selectedSource: null,
    updateSourcesOnSave: false,

    elementTypeName: null,
    availableTableAttributes: null,

    conditionBuilderHtml: null,

    init: function(elementIndex, settings) {
        this.base();

        this.setSettings(settings, {
            resizable: true
        });

        this.elementIndex = elementIndex;
        this.$elementIndexSourcesContainer = this.elementIndex.$sidebar.children('nav').children('ul');

        const $container = $('<form class="modal customize-sources-modal"/>').appendTo(Garnish.$bod);

        this.$sidebar = $('<div class="cs-sidebar block-types"/>').appendTo($container);
        this.$sourcesContainer = $('<div class="sources">').appendTo(this.$sidebar);
        this.$sourceSettingsContainer = $('<div class="source-settings">').appendTo($container);

        this.$footer = $('<div class="footer"/>').appendTo($container);
        this.$footerBtnContainer = $('<div class="buttons right"/>').appendTo(this.$footer);
        this.$cancelBtn = $('<button/>', {
            type: 'button',
            class: 'btn',
            text: Craft.t('app', 'Cancel'),
        }).appendTo(this.$footerBtnContainer);
        this.$saveBtn = $('<button/>', {
            type: 'button',
            class: 'btn submit disabled',
            text: Craft.t('app', 'Save'),
        }).appendTo(this.$footerBtnContainer);
        this.$saveSpinner = $('<div class="spinner hidden"/>').appendTo(this.$footerBtnContainer);

        this.$loadingSpinner = $('<div class="spinner"/>').appendTo($container);

        this.setContainer($container);
        this.show();

        Craft.sendActionRequest('POST', 'element-index-settings/get-customize-sources-modal-data', {
            data: {
                elementType: this.elementIndex.elementType,
            },
        }).then(response => {
            this.$saveBtn.removeClass('disabled');
            this.buildModal(response.data);
        }).finally(() => {
            this.$loadingSpinner.remove();
        });

        this.addListener(this.$cancelBtn, 'click', 'hide');
        this.addListener(this.$saveBtn, 'click', 'save');
        this.addListener(this.$container, 'submit', 'save');
    },

    buildModal: function(response) {
        this.availableTableAttributes = response.availableTableAttributes;
        this.elementTypeName = response.elementTypeName;
        this.conditionBuilderHtml = response.conditionBuilderHtml;

        if (response.headHtml) {
            Craft.appendHeadHtml(response.headHtml);
        }
        if (response.bodyHtml) {
            Craft.appendFootHtml(response.bodyHtml);
        }

        // Create the source item sorter
        this.sourceSort = new Garnish.DragSort({
            handle: '.move',
            axis: 'y',
            onSortChange: () => {
                this.updateSourcesOnSave = true;
            },
        });

        // Create the sources
        this.sources = [];

        for (let i = 0; i < response.sources.length; i++) {
            this.sources.push(this.addSource(response.sources[i]));
        }

        if (!this.selectedSource && typeof this.sources[0] !== 'undefined') {
            this.sources[0].select();
        }

        const $menuBtnContainer = $('<div class="buttons left"/>').appendTo(this.$footer);
        const $menuBtn = $('<button/>', {
            type: 'button',
            class: 'btn menubtn add icon',
            'aria-label': Craft.t('app', 'Add…'),
            title: Craft.t('app', 'Add…'),
        }).appendTo($menuBtnContainer);

        const $menu = $('<div/>', {
            class: 'menu',
        }).appendTo($menuBtnContainer);
        const $ul  = $('<ul/>').append(
          $('<li/>').append(
            $('<a/>', {
                text: Craft.t('app', 'New heading'),
                'data-type': 'heading',
            })
          )
        ).appendTo($menu);

        if (response.conditionBuilderHtml) {
            $('<li/>').append(
              $('<a/>', {
                  text: Craft.t('app', 'New custom source'),
                  'data-type': 'custom',
              })
            ).appendTo($ul);
        }

        new Garnish.MenuBtn($menuBtn, {
            onOptionSelect: option => {
                const sourceData = {
                    type: $(option).data('type'),
                };
                if (sourceData.type === 'custom') {
                    sourceData.key = `custom:${Craft.uuid()}`;
                    sourceData.tableAttributes = [];
                    sourceData.availableTableAttributes = [];
                }
                const source = this.addSource(sourceData);
                Garnish.scrollContainerToElement(this.$sidebar, source.$item);
                source.select();
                this.updateSourcesOnSave = true;
            }
        });
    },

    addSource: function(sourceData) {
        const $item = $('<div class="customize-sources-item"/>').appendTo(this.$sourcesContainer);
        const $itemLabel = $('<div class="label"/>').appendTo($item);
        const $itemInput = $('<input type="hidden"/>').appendTo($item);
        $('<a class="move icon" title="' + Craft.t('app', 'Reorder') + '" role="button"></a>').appendTo($item);

        let source;

        if (sourceData.type === 'heading') {
            $item.addClass('heading');
            $itemInput.attr('name', 'sourceOrder[][heading]');
            source = new Craft.CustomizeSourcesModal.Heading(this, $item, $itemLabel, $itemInput, sourceData);
            source.updateItemLabel(sourceData.heading);
        } else {
            $itemInput.attr('name', 'sourceOrder[][key]').val(sourceData.key);
            if (sourceData.type === 'native') {
                source = new Craft.CustomizeSourcesModal.Source(this, $item, $itemLabel, $itemInput, sourceData);
            } else {
                source = new Craft.CustomizeSourcesModal.CustomSource(this, $item, $itemLabel, $itemInput, sourceData);
            }
            source.updateItemLabel(sourceData.label);

            // Select this by default?
            if ((this.elementIndex.sourceKey + '/').substr(0, sourceData.key.length + 1) === sourceData.key + '/') {
                source.select();
            }
        }

        this.sourceSort.addItems($item);
        return source;
    },

    save: function(ev) {
        if (ev) {
            ev.preventDefault();
        }

        if (this.$saveBtn.hasClass('disabled') || !this.$saveSpinner.hasClass('hidden')) {
            return;
        }

        this.$saveSpinner.removeClass('hidden');

        Craft.sendActionRequest('POST', 'element-index-settings/save-customize-sources-modal-settings', {
            data: this.$container.serialize() + '&elementType=' + this.elementIndex.elementType,
        }).then(() => {
            // Have any changes been made to the source list?
            if (this.updateSourcesOnSave) {
                if (this.$elementIndexSourcesContainer.length) {
                    let $lastSourceItem = null,
                      $pendingHeading;

                    for (let i = 0; i < this.sourceSort.$items.length; i++) {
                        const $item = this.sourceSort.$items.eq(i),
                          source = $item.data('source'),
                          $indexSourceItem = source.getIndexSourceItem();

                        if (!$indexSourceItem) {
                            continue;
                        }

                        if (source.isHeading()) {
                            $pendingHeading = $indexSourceItem;
                            continue;
                        }

                        if ($pendingHeading) {
                            this.appendIndexSourceItem($pendingHeading, $lastSourceItem);
                            $lastSourceItem = $pendingHeading;
                            $pendingHeading = null;
                        }

                        const isNew = !$indexSourceItem.parent().length;
                        this.appendIndexSourceItem($indexSourceItem, $lastSourceItem);
                        if (isNew) {
                            this.elementIndex.initSource($indexSourceItem.children('a'));
                        }
                        $lastSourceItem = $indexSourceItem;
                    }

                    // Remove any additional sources (most likely just old headings)
                    if ($lastSourceItem) {
                        const $extraSources = $lastSourceItem.nextAll();
                        this.elementIndex.sourceSelect.removeItems($extraSources);
                        $extraSources.remove();
                    }
                }
            }

            // If a source is selected, have the element index select that one by default on the next request
            if (this.selectedSource && this.selectedSource.sourceData.key) {
                this.elementIndex.selectSourceByKey(this.selectedSource.sourceData.key);
                this.elementIndex.updateElements();
            }

            Craft.cp.displayNotice(Craft.t('app', 'Source settings saved'));
            this.hide();
        }).catch(() => {
            Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
        }).finally(() => {
            this.$saveSpinner.addClass('hidden');
        });
    },

    appendIndexSourceItem: function($sourceItem, $lastSourceItem) {
        if (!$lastSourceItem) {
            $sourceItem.prependTo(this.$elementIndexSourcesContainer);
        } else {
            $sourceItem.insertAfter($lastSourceItem);
        }
    },

    destroy: function() {
        for (let i = 0; i < this.sources.length; i++) {
            this.sources[i].destroy();
        }

        delete this.sources;
        this.base();
    }
});

Craft.CustomizeSourcesModal.BaseSource = Garnish.Base.extend({
    modal: null,

    $item: null,
    $itemLabel: null,
    $itemInput: null,
    $settingsContainer: null,

    sourceData: null,

    init: function(modal, $item, $itemLabel, $itemInput, sourceData) {
        this.modal = modal;
        this.$item = $item;
        this.$itemLabel = $itemLabel;
        this.$itemInput = $itemInput;
        this.sourceData = sourceData;

        this.$item.data('source', this);

        this.addListener(this.$item, 'click', 'select');
    },

    isHeading: function() {
        return false;
    },

    isSelected: function() {
        return (this.modal.selectedSource === this);
    },

    select: function() {
        if (this.isSelected()) {
            return;
        }

        if (this.modal.selectedSource) {
            this.modal.selectedSource.deselect();
        }

        this.$item.addClass('sel');
        this.modal.selectedSource = this;

        if (!this.$settingsContainer) {
            this.$settingsContainer = $('<div/>').appendTo(this.modal.$sourceSettingsContainer);
            this.createSettings(this.$settingsContainer);
        } else {
            this.$settingsContainer.removeClass('hidden');
        }

        this.modal.$sourceSettingsContainer.scrollTop(0);
    },

    createSettings: function() {
    },

    getIndexSourceItem: function() {
    },

    deselect: function() {
        this.$item.removeClass('sel');
        this.modal.selectedSource = null;
        this.$settingsContainer.addClass('hidden');
    },

    updateItemLabel: function(val) {
        if (val) {
            this.$itemLabel.text(val);
        } else {
            this.$itemLabel.html('&nbsp;');
        }
    },

    destroy: function() {
        this.modal.sourceSort.removeItems(this.$item);
        this.modal.sources.splice($.inArray(this, this.modal.sources), 1);
        this.modal.updateSourcesOnSave = true;

        if (this.isSelected()) {
            this.deselect();

            if (this.modal.sources.length) {
                this.modal.sources[0].select();
            }
        }

        this.$item.data('source', null);
        this.$item.remove();

        if (this.$settingsContainer) {
            this.$settingsContainer.remove();
        }

        this.base();
    }
});

Craft.CustomizeSourcesModal.Source = Craft.CustomizeSourcesModal.BaseSource.extend({
    createSettings: function($container) {
        this.createTableAttributesField($container);
    },

    createTableAttributesField: function($container) {
        if (!this.sourceData.tableAttributes.length && !this.modal.availableTableAttributes.length) {
            return;
        }

        const $columnCheckboxes = $('<div/>');
        const selectedAttributes = [];

        $(`<input type="hidden" name="sources[${this.sourceData.key}][tableAttributes][]" value=""/>`).appendTo($columnCheckboxes);

        // Add the selected columns, in the selected order
        for (let i = 0; i < this.sourceData.tableAttributes.length; i++) {
            let [key, label] = this.sourceData.tableAttributes[i];
            $columnCheckboxes.append(this.createTableColumnOption(key, label, true));
            selectedAttributes.push(key);
        }

        // Add the rest
        const availableTableAttributes = this.modal.availableTableAttributes.slice(0);
        availableTableAttributes.push(...this.sourceData.availableTableAttributes);

        for (let i = 0; i < availableTableAttributes.length; i++) {
            const [key, label] = availableTableAttributes[i];
            if (!Craft.inArray(key, selectedAttributes)) {
                $columnCheckboxes.append(this.createTableColumnOption(key, label, false));
            }
        }

        new Garnish.DragSort($columnCheckboxes.children(), {
            handle: '.move',
            axis: 'y'
        });

        Craft.ui.createField($columnCheckboxes, {
            label: Craft.t('app', 'Table Columns'),
            instructions: Craft.t('app', 'Choose which table columns should be visible for this source, and in which order.')
        }).appendTo($container);
    },

    createTableColumnOption: function(key, label, checked) {
        return $('<div class="customize-sources-table-column"/>')
          .append('<div class="icon move"/>')
          .append(
            Craft.ui.createCheckbox({
                label: Craft.escapeHtml(label),
                name: 'sources[' + this.sourceData.key + '][tableAttributes][]',
                value: key,
                checked: checked,
            })
          );
    },

    getIndexSourceItem: function() {
        const $source = this.modal.elementIndex.getSourceByKey(this.sourceData.key);

        if ($source) {
            return $source.closest('li');
        }
    }
});

Craft.CustomizeSourcesModal.CustomSource = Craft.CustomizeSourcesModal.Source.extend({
    $labelInput: null,

    createSettings: function($container) {
        const $labelField = Craft.ui.createTextField({
            label: Craft.t('app', 'Label'),
            name: `sources[${this.sourceData.key}][label]`,
            value: this.sourceData.label,
        }).appendTo($container);
        this.$labelInput = $labelField.find('.text');

        Craft.ui.createField($('<div/>').append(this.modal.conditionBuilderHtml), {
            id: 'criteria',
            label: Craft.t('app', '{type} Criteria', {
                type: this.modal.elementTypeName,
            }),
        }).appendTo($container);

        this.createTableAttributesField($container);

        $container.append('<hr/>');

        this.$deleteBtn = $('<a class="error delete"/>').text(Craft.t('app', 'Delete custom source'))
          .appendTo($container);

        this.addListener(this.$labelInput, 'input', 'handleLabelInputChange');
        this.addListener(this.$deleteBtn, 'click', 'destroy');
    },

    select: function() {
        this.base();
        this.$labelInput.focus();
    },

    handleLabelInputChange: function() {
        this.updateItemLabel(this.$labelInput.val());
        this.modal.updateSourcesOnSave = true;
    },

    getIndexSourceItem: function() {
        let $source = this.base();
        if ($source || !this.$settingsContainer) {
            if (this.$settingsContainer) {
                $source.find('.label').text(this.$labelInput.val());
            }
            return $source;
        }
        return $('<li/>').append(
          $('<a/>', {
              'data-key': this.sourceData.key,
          }).append(
            $('<span/>', {
                class: 'label',
                text: this.$labelInput.val(),
            })
          )
        );
    },
});

Craft.CustomizeSourcesModal.Heading = Craft.CustomizeSourcesModal.BaseSource.extend({
    $labelInput: null,
    $deleteBtn: null,

    isHeading: function() {
        return true;
    },

    select: function() {
        this.base();
        this.$labelInput.focus();
    },

    createSettings: function($container) {
        const $labelField = Craft.ui.createTextField({
            label: Craft.t('app', 'Heading'),
            instructions: Craft.t('app', 'This can be left blank if you just want an unlabeled separator.'),
            value: this.sourceData.heading || '',
        }).appendTo($container);
        this.$labelInput = $labelField.find('.text');

        $container.append('<hr/>');

        this.$deleteBtn = $('<a class="error delete"/>').text(Craft.t('app', 'Delete heading'))
          .appendTo($container);

        this.addListener(this.$labelInput, 'input', 'handleLabelInputChange');
        this.addListener(this.$deleteBtn, 'click', 'destroy');
    },

    handleLabelInputChange: function() {
        this.updateItemLabel(this.$labelInput.val());
        this.modal.updateSourcesOnSave = true;
    },

    updateItemLabel: function(val) {
        this.$itemLabel.html((val ? Craft.escapeHtml(val) : '<em class="light">' + Craft.t('app', '(blank)') + '</em>') + '&nbsp;');
        this.$itemInput.val(val);
    },

    getIndexSourceItem: function() {
        const label = (this.$labelInput ? this.$labelInput.val() : null) || this.sourceData.heading || '';
        return $('<li class="heading"/>').append($('<span/>').text(label));
    },
});
