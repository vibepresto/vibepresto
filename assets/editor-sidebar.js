(function (wp) {
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var components = wp.components;

    var data = window.VibePrestoEditorSidebar;
    if (! data) {
        return;
    }

    function FileField(props) {
        return el(
            components.BaseControl,
            {
                label: props.label,
                help: props.help || undefined,
            },
            el('input', {
                type: 'file',
                name: props.name,
                accept: props.accept || undefined,
                multiple: !!props.multiple,
            })
        );
    }

    function renderAssignmentTab(context) {
        var strings = context.strings;
        var bundleOptions = context.bundleOptions;
        var selectedBundleId = context.selectedBundleId;
        var onBundleChange = context.onBundleChange;
        var activeBundle = context.activeBundle;
        var links = context.links;

        return [
            el(components.SelectControl, {
                key: 'bundle-select',
                label: strings.assignTitle,
                help: strings.assignHelp,
                value: String(selectedBundleId),
                options: bundleOptions,
                onChange: onBundleChange,
            }),
            activeBundle ? el(
                Fragment,
                { key: 'active-bundle' },
                el('p', null, el('strong', null, strings.activeTitle)),
                el('p', null, activeBundle.version_label || activeBundle.title),
                el('p', null, strings.activeDescription),
                el('p', null, strings.lineage + ': ' + activeBundle.lineage_name),
                el('p', null, strings.version + ': ' + String(activeBundle.version_number || 1)),
                el('p', null, strings.mode + ': ' + activeBundle.mode),
                el('p', null, strings.entryHtml + ': ', el('code', null, activeBundle.entry_html)),
                links.preview ? el('p', null, strings.frontendUrl + ': ', el('a', {
                    href: links.preview,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }, strings.openPage)) : null
            ) : el('p', { key: 'no-bundle' }, strings.noBundleActive),
            el(
                'p',
                { key: 'assignment-actions' },
                links.preview ? el(components.Button, {
                    variant: 'secondary',
                    href: links.preview,
                    target: '_blank'
                }, strings.previewPage) : null,
                links.preview ? ' ' : null,
                el(components.Button, {
                    variant: 'link',
                    href: links.manageBundles
                }, strings.manageBundles)
            )
        ];
    }

    function renderUploadTab(context) {
        var strings = context.strings;
        var links = context.links;
        var nonces = context.nonces;
        var postId = context.postId;
        var uploadMode = context.uploadMode;
        var setUploadMode = context.setUploadMode;
        var assignOnUpload = context.assignOnUpload;
        var setAssignOnUpload = context.setAssignOnUpload;

        return [
            el('p', { key: 'upload-help' }, strings.uploadHelp),
            postId < 1 ? el(components.Notice, {
                key: 'save-first',
                status: 'info',
                isDismissible: false,
            }, strings.saveFirstToUpload) : null,
            postId > 0 ? el(
                'form',
                {
                    key: 'upload-form',
                    method: 'post',
                    action: links.adminPost,
                    encType: 'multipart/form-data',
                },
                el('input', {
                    type: 'hidden',
                    name: 'action',
                    value: 'vibepresto_create_bundle',
                }),
                el('input', {
                    type: 'hidden',
                    name: 'page_id',
                    value: String(postId),
                }),
                el('input', {
                    type: 'hidden',
                    name: '_wpnonce',
                    value: nonces.createBundle,
                }),
                el('input', {
                    type: 'hidden',
                    name: 'upload_mode',
                    value: uploadMode,
                }),
                assignOnUpload ? el('input', {
                    type: 'hidden',
                    name: 'assign_to_page',
                    value: '1',
                }) : null,
                el(components.TextControl, {
                    label: strings.displayName,
                    name: 'display_name',
                    placeholder: strings.displayNamePlaceholder,
                }),
                el(components.RadioControl, {
                    label: strings.uploadMode,
                    selected: uploadMode,
                    options: [
                        { label: strings.zipBundle, value: 'zip' },
                        { label: strings.separateFiles, value: 'separate' }
                    ],
                    onChange: setUploadMode,
                }),
                uploadMode === 'zip' ? el(FileField, {
                    label: strings.zipBundle,
                    name: 'bundle_zip',
                    accept: '.zip',
                    help: strings.zipDescription,
                }) : null,
                uploadMode === 'separate' ? el(Fragment, null,
                    el(FileField, {
                        label: strings.htmlFile,
                        name: 'bundle_html',
                        accept: '.html,.htm',
                    }),
                    el(FileField, {
                        label: strings.cssFile,
                        name: 'bundle_css',
                        accept: '.css',
                    }),
                    el(FileField, {
                        label: strings.jsFile,
                        name: 'bundle_js',
                        accept: '.js',
                    }),
                    el(FileField, {
                        label: strings.extraAssets,
                        name: 'bundle_assets[]',
                        multiple: true,
                        help: strings.assetsDescription,
                    })
                ) : null,
                el(components.CheckboxControl, {
                    label: strings.assignOnUpload,
                    checked: assignOnUpload,
                    onChange: setAssignOnUpload,
                }),
                el(components.Button, {
                    variant: 'primary',
                    type: 'submit',
                }, strings.uploadButton)
            ) : null
        ];
    }

    function SidebarPanel() {
        var metaKey = data.metaKey;
        var bundles = data.bundles || [];
        var strings = data.strings || {};
        var notice = data.notice;
        var links = data.links || {};
        var nonces = data.nonces || {};
        var postMeta = useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('meta') || {};
        }, []);
        var selectedBundleId = Number(postMeta[metaKey] || 0);
        var activeBundle = bundles.find(function (bundle) {
            return Number(bundle.id) === selectedBundleId;
        }) || null;
        var uploadState = useState('zip');
        var uploadMode = uploadState[0];
        var setUploadMode = uploadState[1];
        var assignState = useState(data.postId > 0);
        var assignOnUpload = assignState[0];
        var setAssignOnUpload = assignState[1];
        var dispatch = useDispatch('core/editor');

        function onBundleChange(value) {
            var next = {};
            next[metaKey] = Number(value || 0);

            dispatch.editPost({
                meta: Object.assign({}, postMeta, next)
            });
        }

        var bundleOptions = [{
            label: strings.normalRendering,
            value: '0'
        }].concat(bundles.map(function (bundle) {
            return {
                label: (bundle.version_label || bundle.title) + ' (' + bundle.mode + ')',
                value: String(bundle.id)
            };
        }));

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'vibepresto-takeover',
                title: strings.panelTitle,
            },
            notice ? el(components.Notice, {
                status: notice.type === 'success' ? 'success' : 'error',
                isDismissible: false,
            }, notice.message) : null,
            el('p', null, strings.panelHelp),
            el(components.TabPanel, {
                className: 'vibepresto-sidebar-tabs',
                activeClass: 'is-active',
                tabs: [
                    {
                        name: 'assignment',
                        title: strings.assignmentTab,
                        className: 'vibepresto-sidebar-tab-assignment',
                    },
                    {
                        name: 'upload',
                        title: strings.uploadTab,
                        className: 'vibepresto-sidebar-tab-upload',
                    }
                ]
            }, function (tab) {
                if (tab.name === 'upload') {
                    return renderUploadTab({
                        strings: strings,
                        links: links,
                        nonces: nonces,
                        postId: data.postId,
                        uploadMode: uploadMode,
                        setUploadMode: setUploadMode,
                        assignOnUpload: assignOnUpload,
                        setAssignOnUpload: setAssignOnUpload,
                    });
                }

                return renderAssignmentTab({
                    strings: strings,
                    bundleOptions: bundleOptions,
                    selectedBundleId: selectedBundleId,
                    onBundleChange: onBundleChange,
                    activeBundle: activeBundle,
                    links: links,
                });
            })
        );
    }

    registerPlugin('vibepresto-editor-sidebar', {
        render: SidebarPanel,
    });
}(window.wp));
