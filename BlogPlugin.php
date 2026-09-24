<?php

/**
 * @file BlogPlugin.php
 *
 * @package plugins.generic.blog
 */

namespace APP\plugins\generic\blog;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\navigationMenu\NavigationMenuItem;
use APP\plugins\generic\blog\classes\BlogEntryDAO;
use APP\plugins\generic\blog\classes\BlogKeywordDAO;
use APP\plugins\generic\blog\pages\BlogHandler;

class BlogPlugin extends GenericPlugin
{
    public function getDisplayName()
    {
        return __('plugins.generic.blog.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.blog.description');
    }

    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled()) {
            $blogEntryDao = new BlogEntryDAO();
            DAORegistry::registerDAO('BlogEntryDAO', $blogEntryDao);
            DAORegistry::registerDAO('BlogKeywordDAO', new BlogKeywordDAO());
            $blogEntryDao->ensureStatusColumn();

            // Public blog page: /index.php/{journalPath}/blog
            Hook::add('LoadHandler', [$this, 'callbackHandleContent']);

            // Keep the original Website Settings Blog tab available.
            Hook::add('Template::Settings::website', [$this, 'callbackShowWebsiteSettingsTabs']);

            // Do not create navigation items directly from register().
            // OJS can load plugins before a journal request context exists.
            // Instead, run the idempotent menu check from TemplateManager::display
            // when a real frontend request/context is available.
            Hook::add('TemplateManager::display', [$this, 'callbackEnsurePostNavigationMenu']);
        }

        return true;
    }

    /**
     * Display the Blog management grid in the standard OJS plugin modal.
     */
    /**
     * OJS 3.5 safe static manager.
     *
     * This version intentionally keeps the exact working behavior of v3.1.0.7:
     * no DAO query, no BlogEntryForm construction, and no GridHandler/fetchGrid
     * request from the manage endpoint. We will add database functionality only
     * after this manager is confirmed stable.
     */
    /**
     * OJS 3.5 compatibility manager with a minimal database read.
     *
     * This step intentionally avoids GridHandler/fetchGrid and BlogEntryForm.
     * It only verifies that the BlogEntryDAO can read the current context
     * without triggering the legacy grid code.
     */
    public function manage($args, $request): JSONMessage
    {
        /*
         * v3.2.0.3
         * GET: render the manager.
         * POST: save an entry, then immediately render the refreshed manager.
         *
         * No legacy GridHandler or BlogEntryForm is used.
         */
        // Delete an entry from the Blog Manager.
        if ($request->getUserVar('deleteBlogEntry')) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false, 'Security check failed. Please reload the Blog Manager and try again.');
            }

            $context = $request->getContext();
            $entryId = (int) $request->getUserVar('blogEntryId');
            if (!$context || $entryId <= 0) {
                return new JSONMessage(false, 'Invalid blog entry or journal context.');
            }

            try {
                $blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
                $entry = $blogEntryDao->getById($entryId);
                if (!$entry || (int) $entry->getContextId() !== (int) $context->getId()) {
                    return new JSONMessage(false, 'The selected blog entry could not be found.');
                }
                $blogEntryDao->deleteObject($entry);
                return new JSONMessage(true, $this->renderManager($request, 'Blog entry deleted successfully.', 'success'));
            } catch (\Throwable $e) {
                return new JSONMessage(true, $this->renderManager($request, 'The blog entry could not be deleted: ' . $e->getMessage(), 'error'));
            }
        }

        // Enable/disable an entry without deleting it.
        if ($request->getUserVar('toggleBlogEntry')) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false, 'Security check failed. Please reload the Blog Manager and try again.');
            }

            $context = $request->getContext();
            $entryId = (int) $request->getUserVar('blogEntryId');
            if (!$context || $entryId <= 0) {
                return new JSONMessage(false, 'Invalid blog entry or journal context.');
            }

            try {
                $blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
                $entry = $blogEntryDao->getById($entryId);
                if (!$entry || (int) $entry->getContextId() !== (int) $context->getId()) {
                    return new JSONMessage(false, 'The selected blog entry could not be found.');
                }
                $newEnabled = !$entry->isEnabled();
                $blogEntryDao->setEnabled($entryId, $newEnabled);
                return new JSONMessage(true, $this->renderManager(
                    $request,
                    $newEnabled ? 'Blog entry enabled successfully.' : 'Blog entry disabled successfully.',
                    'success'
                ));
            } catch (\Throwable $e) {
                return new JSONMessage(true, $this->renderManager($request, 'The blog entry status could not be changed: ' . $e->getMessage(), 'error'));
            }
        }

        if ($request->getUserVar('saveBlogEntry')) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(
                    false,
                    'Security check failed. Please reload the Blog Manager and try again.'
                );
            }

            $context = $request->getContext();
            if (!$context) {
                return new JSONMessage(false, 'No journal context was available.');
            }

            $title = trim((string) $request->getUserVar('title'));
            $byline = trim((string) $request->getUserVar('byline'));
            $content = trim((string) $request->getUserVar('content'));
            $datePosted = trim((string) $request->getUserVar('datePosted'));
            $keywordsInput = trim((string) $request->getUserVar('keywords'));

            $errors = [];

            if ($title === '') {
                $errors[] = 'Title is required.';
            }

            if ($content === '') {
                $errors[] = 'Content is required.';
            }

            if ($datePosted === '') {
                $errors[] = 'Date Posted is required.';
            }

            $timestamp = false;
            if ($datePosted !== '') {
                $timestamp = strtotime(str_replace('T', ' ', $datePosted));
                if ($timestamp === false) {
                    $errors[] = 'Date Posted is not valid.';
                }
            }

            if ($errors) {
                return new JSONMessage(
                    true,
                    $this->renderManager(
                        $request,
                        implode(' ', $errors),
                        'error',
                        $title,
                        $byline,
                        $content,
                        $datePosted,
                        $keywordsInput
                    )
                );
            }

            try {
                $blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');

                $blogEntry = $blogEntryDao->newDataObject();
                $blogEntry->setContextId((int) $context->getId());
                $blogEntry->setTitle($title);
                $blogEntry->setByline($byline);
                $blogEntry->setContent($content);
                $blogEntry->setDatePosted(date('Y-m-d H:i:s', $timestamp));

                $entryId = $blogEntryDao->insertObject($blogEntry);

                if ($keywordsInput !== '') {
                    $blogKeywordDao = DAORegistry::getDAO('BlogKeywordDAO');

                    if (method_exists($blogKeywordDao, 'insertKeyword') &&
                        method_exists($blogKeywordDao, 'insertEntryKeyword')) {

                        $keywords = preg_split('/[,;]+/', $keywordsInput);
                        $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));

                        foreach ($keywords as $keyword) {
                            $keywordId = $blogKeywordDao->insertKeyword($keyword);
                            if ($keywordId) {
                                $blogKeywordDao->insertEntryKeyword($entryId, $keywordId);
                            }
                        }
                    }
                }

                // Important: return the refreshed manager, not just a success message.
                return new JSONMessage(
                    true,
                    $this->renderManager($request, 'Blog entry saved successfully.', 'success')
                );
            } catch (\Throwable $e) {
                return new JSONMessage(
                    true,
                    $this->renderManager(
                        $request,
                        'The blog entry could not be saved: ' .
                        $e->getMessage(),
                        'error',
                        $title,
                        $byline,
                        $content,
                        $datePosted,
                        $keywordsInput
                    )
                );
            }
        }

        return new JSONMessage(true, $this->renderManager($request));
    }

    /**
     * Render the complete Blog Manager.
     *
     * The displayed count is deliberately derived from the same result set
     * used for the visible list. This prevents a stale/mismatched count from
     * being shown after a successful save.
     */
    protected function renderManager(
        $request,
        $message = '',
        $messageType = 'info',
        $oldTitle = '',
        $oldByline = '',
        $oldContent = '',
        $oldDatePosted = '',
        $oldKeywords = ''
    ): string {
        $context = $request->getContext();
        $entries = [];

        if ($context) {
            $blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
            $resultFactory = $blogEntryDao->getEntriesByContextId($context->getId(), null, null, null, true);
            while ($entry = $resultFactory->next()) {
                $entries[] = $entry;
            }
        }

        $count = count($entries);
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $router = $request->getRouter();
        $saveUrl = $router->url(
            request: $request,
            op: 'manage',
            params: ['plugin' => $this->getName(), 'category' => $this->getCategory(), 'action' => 'index']
        );
        $saveUrlEscaped = $escape($saveUrl);

        $baseUrl = $request->getBaseUrl();
        $contextPath = $context ? (string) $context->getPath() : '';
        $uploadUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_API,
            $contextPath ?: Application::SITE_CONTEXT_PATH,
            '_uploadPublicFile'
        );

        $html = '<style>'
            . '.jedl-blog-manager{font-family:inherit;color:#263238}.jedl-blog-manager *{box-sizing:border-box}'
            . '.jedl-blog-topbar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px;margin-bottom:20px}'
            . '.jedl-blog-heading{justify-self:start}.jedl-blog-public-wrap{justify-self:center}'
            . '.jedl-blog-count{justify-self:end;background:#fff;border:1px solid #dfe7e4;border-radius:10px;padding:12px 16px;box-shadow:0 4px 14px rgba(31,61,52,.06);font-weight:600;color:#29443d}'
            . '.jedl-blog-columns{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(340px,.85fr);gap:20px;align-items:start}'
            . '.jedl-blog-column{min-width:0}'
            . '.jedl-blog-card{background:#fff;border:1px solid #dfe7e4;border-radius:12px;padding:20px;margin:0 0 20px;box-shadow:0 5px 18px rgba(31,61,52,.08)}'
            . '.jedl-blog-section-title{font-size:18px;font-weight:700;color:#184d43;margin:0 0 15px}'
            . '.jedl-blog-card h4{color:#184d43;margin:0 0 16px;font-size:18px}'
            . '.jedl-blog-label{display:block;font-weight:600;color:#29443d;margin:0 0 7px}.jedl-blog-required{color:#c62828}'
            . '.jedl-blog-input,.jedl-blog-textarea{width:100%;border:1px solid #c9d8d3;border-radius:8px;background:#fbfdfc;padding:10px 12px;font:inherit;color:#263238;box-shadow:inset 0 1px 2px rgba(0,0,0,.04),0 2px 7px rgba(31,61,52,.05);transition:border-color .18s,box-shadow .18s,background .18s}'
            . '.jedl-blog-input:focus,.jedl-blog-textarea:focus{outline:none;border-color:#2f8f83;background:#fff;box-shadow:0 0 0 3px rgba(47,143,131,.14),0 4px 12px rgba(31,61,52,.08)}'
            . '.jedl-blog-textarea{min-height:220px;resize:vertical;line-height:1.55}.jedl-blog-editor-wrap{width:100%;margin:0}.jedl-blog-editor{border:1px solid #c9d8d3;border-radius:8px;background:#fff;box-shadow:0 3px 10px rgba(31,61,52,.06);overflow:hidden}.jedl-blog-editor-toolbar{display:flex;flex-wrap:wrap;gap:4px;padding:8px;background:#f4f8f6;border-bottom:1px solid #d9e5e1}.jedl-blog-editor-toolbar button{border:1px solid #c9d8d3;background:#fff;color:#29443d;border-radius:5px;min-width:34px;height:32px;padding:0 8px;font-weight:600;cursor:pointer}.jedl-blog-editor-toolbar button:hover{background:#edf7f4;border-color:#8db8ae}.jedl-blog-editor-toolbar .jedl-blog-tool-sep{width:1px;height:24px;background:#cbd9d5;margin:4px 3px}.jedl-blog-editor-area{min-height:330px;padding:14px 16px;outline:none;line-height:1.65;overflow:auto}.jedl-blog-editor-area:empty:before{content:"Write your blog content here...";color:#9aa9a4}.jedl-blog-editor-area img{max-width:100%;height:auto}.jedl-blog-editor-area a{color:#176b60;text-decoration:underline}.jedl-blog-field{margin-bottom:16px}'
            . '.jedl-blog-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px}'
            . '.jedl-blog-btn{border:0;border-radius:8px;padding:10px 17px;font-weight:600;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,.10);transition:transform .15s,box-shadow .15s,opacity .15s}'
            . '.jedl-blog-btn:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.14)}.jedl-blog-btn:disabled{opacity:.65;cursor:not-allowed;transform:none}'
            . '.jedl-blog-save{background:#247a6e;color:#fff}.jedl-blog-clear{background:#eef3f1;color:#34534c;border:1px solid #d2dfdb}'
            . '.jedl-blog-public{display:inline-block;background:#536aa0;color:#fff!important;border-radius:8px;padding:10px 16px;text-decoration:none!important;font-weight:600;box-shadow:0 4px 10px rgba(83,106,160,.22)}.jedl-blog-public:hover{background:#44598b}'
            . '.jedl-blog-table-wrap{border:1px solid #dfe7e4;border-radius:10px;overflow:auto;box-shadow:0 4px 14px rgba(31,61,52,.06)}'
            . '.jedl-blog-table{width:100%;border-collapse:collapse;background:#fff}.jedl-blog-table th{background:#edf7f4;color:#24554c;text-align:left;padding:11px;border-bottom:1px solid #d8e7e2;white-space:nowrap}.jedl-blog-table td{padding:11px;border-bottom:1px solid #edf1ef;vertical-align:middle}.jedl-blog-table tr:last-child td{border-bottom:0}.jedl-blog-table a{color:#176b60;font-weight:600;text-decoration:none}.jedl-blog-table a:hover{text-decoration:underline}.jedl-blog-action-form{display:inline;margin:0}.jedl-blog-action-btn{border:0;border-radius:6px;padding:7px 10px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}.jedl-blog-toggle{background:#eef3f1;color:#24554c;border:1px solid #ccdcd7}.jedl-blog-delete{background:#fcebea;color:#a12622;border:1px solid #efc8c5}.jedl-blog-status-enabled{display:inline-block;background:#e7f6ed;color:#176b43;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}.jedl-blog-status-disabled{display:inline-block;background:#f1f1f1;color:#777;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}'
            . '.jedl-blog-form-column .jedl-blog-card{position:sticky;top:10px}'
            . '@media(max-width:900px){.jedl-blog-topbar{grid-template-columns:1fr}.jedl-blog-heading,.jedl-blog-public-wrap,.jedl-blog-count{justify-self:stretch}.jedl-blog-public{display:block;text-align:center}.jedl-blog-columns{grid-template-columns:1fr}.jedl-blog-form-column .jedl-blog-card{position:static}}'
            . '</style>'
            . '<div id="jedlBlogManagerRoot" class="jedl-blog-manager" style="padding:20px;">'
            . '<div class="jedl-blog-topbar">'
            . '<h3 class="jedl-blog-heading" style="margin:0;color:#184d43;">Blog Management</h3>'
            . '<div class="jedl-blog-public-wrap">';

        if ($context) {
            $blogUrl = $request->getDispatcher()->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'blog',
                null,
                []
            );
            $html .= '<a href="' . $escape($blogUrl) . '" target="_blank" rel="noopener" class="jedl-blog-public">Open Public Blog</a>';
        }

        $html .= '</div><div class="jedl-blog-count"><strong>Blog entries in this journal:</strong> ' . $count . '</div></div>';

        if ($message !== '') {
            $class = $messageType === 'success' ? 'pkp_notification_success' : ($messageType === 'error' ? 'pkp_notification_error' : 'pkp_notification_info');
            $html .= '<div class="pkp_notification ' . $class . '" style="margin-bottom:18px;">' . $escape($message) . '</div>';
        }

        $html .= '<div class="jedl-blog-columns">'
            . '<div class="jedl-blog-column jedl-blog-entries-column"><div class="jedl-blog-card">'
            . '<div class="jedl-blog-section-title">Blog Entries</div>';

        if (!$entries) {
            $html .= '<div class="pkp_notification pkp_notification_info"><strong>No blog entries have been created.</strong><p style="margin-bottom:0;">Create your first blog entry using the form on the right.</p></div>';
        } else {
            $html .= '<div class="jedl-blog-table-wrap"><table class="jedl-blog-table"><thead><tr><th>Title</th><th>Byline</th><th>Date</th><th>Status</th><th>Read</th><th>Disable</th><th>Delete</th></tr></thead><tbody>';
            foreach ($entries as $entry) {
                $entryId = (int) $entry->getId();
                $entryUrl = $request->getDispatcher()->url(
                    $request,
                    \PKP\core\PKPApplication::ROUTE_PAGE,
                    $context->getPath(),
                    'blog',
                    'view',
                    [$entryId]
                );
                $enabled = $entry->isEnabled();
                $statusLabel = $enabled ? 'Enabled' : 'Disabled';
                $statusClass = $enabled ? 'jedl-blog-status-enabled' : 'jedl-blog-status-disabled';
                $toggleLabel = $enabled ? 'Disable' : 'Enable';
                $toggleAction = $enabled ? 'Disable this blog post from the public Blog?' : 'Enable this blog post on the public Blog?';
                $html .= '<tr>'
                    . '<td><a href="' . $escape($entryUrl) . '" target="_blank" rel="noopener">' . $escape($entry->getTitle()) . '</a></td>'
                    . '<td>' . $escape($entry->getByline()) . '</td>'
                    . '<td>' . $escape($this->formatBlogDate($entry->getDatePosted())) . '</td>'
                    . '<td><span class="' . $statusClass . '">' . $statusLabel . '</span></td>'
                    . '<td><a href="' . $escape($entryUrl) . '" target="_blank" rel="noopener">Read</a></td>'
                    . '<td><form class="jedl-blog-action-form" method="post" action="' . $saveUrlEscaped . '" data-confirm="' . $escape($toggleAction) . '">'
                    . '<input type="hidden" name="blogEntryId" value="' . $entryId . '"><input type="hidden" name="toggleBlogEntry" value="1"><input type="hidden" name="csrfToken" value="">'
                    . '<button type="submit" class="jedl-blog-action-btn jedl-blog-toggle">' . $toggleLabel . '</button></form></td>'
                    . '<td><form class="jedl-blog-action-form" method="post" action="' . $saveUrlEscaped . '" data-confirm="Delete this blog post permanently? This cannot be undone.">'
                    . '<input type="hidden" name="blogEntryId" value="' . $entryId . '"><input type="hidden" name="deleteBlogEntry" value="1"><input type="hidden" name="csrfToken" value="">'
                    . '<button type="submit" class="jedl-blog-action-btn jedl-blog-delete">Delete</button></form></td>'
                    . '</tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div></div>';

        $contextId = $context ? (int) $context->getId() : 0;

        $html .= '<div class="jedl-blog-column jedl-blog-form-column"><div class="jedl-blog-card">'
            . '<h4>Add Blog Entry</h4>'
            . '<form id="jedlBlogEntryForm" method="post" action="' . $saveUrlEscaped . '" onsubmit="document.getElementById(\'jedlBlogContent\').value=document.getElementById(\'jedlBlogContentEditor\').innerHTML;">'
            . '<input type="hidden" name="contextId" value="' . $contextId . '">'
            . '<input type="hidden" name="saveBlogEntry" value="1"><input type="hidden" name="csrfToken" value="">'
            . '<div class="jedl-blog-field"><label class="jedl-blog-label" for="jedlBlogTitle">Title <span class="jedl-blog-required">*</span></label><input id="jedlBlogTitle" name="title" type="text" maxlength="255" required value="' . $escape($oldTitle) . '" class="jedl-blog-input"></div>'
            . '<div class="jedl-blog-field"><label class="jedl-blog-label" for="jedlBlogByline">Byline</label><input id="jedlBlogByline" name="byline" type="text" maxlength="255" value="' . $escape($oldByline) . '" class="jedl-blog-input"></div>'
            . '<div class="jedl-blog-field"><label class="jedl-blog-label" for="jedlBlogContentEditor">Content <span class="jedl-blog-required">*</span></label><div class="jedl-blog-editor-wrap"><div class="jedl-blog-editor"><div class="jedl-blog-editor-toolbar" role="toolbar" aria-label="Content formatting toolbar">'
            . '<button type="button" title="Bold" onmousedown="event.preventDefault();" onclick="document.execCommand(\'bold\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">B</button>'
            . '<button type="button" title="Italic" onmousedown="event.preventDefault();" onclick="document.execCommand(\'italic\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();"><i>I</i></button>'
            . '<button type="button" title="Underline" onmousedown="event.preventDefault();" onclick="document.execCommand(\'underline\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();"><u>U</u></button>'
            . '<button type="button" title="Strikethrough" onmousedown="event.preventDefault();" onclick="document.execCommand(\'strikeThrough\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">S</button>'
            . '<span class="jedl-blog-tool-sep"></span>'
            . '<button type="button" title="Heading 1" onmousedown="event.preventDefault();" onclick="document.execCommand(\'formatBlock\',false,\'<h2>\');document.getElementById(\'jedlBlogContentEditor\').focus();">H1</button>'
            . '<button type="button" title="Heading 2" onmousedown="event.preventDefault();" onclick="document.execCommand(\'formatBlock\',false,\'<h3>\');document.getElementById(\'jedlBlogContentEditor\').focus();">H2</button>'
            . '<button type="button" title="Paragraph" onmousedown="event.preventDefault();" onclick="document.execCommand(\'formatBlock\',false,\'<p>\');document.getElementById(\'jedlBlogContentEditor\').focus();">P</button>'
            . '<span class="jedl-blog-tool-sep"></span>'
            . '<button type="button" title="Bulleted list" onmousedown="event.preventDefault();" onclick="document.execCommand(\'insertUnorderedList\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">• List</button>'
            . '<button type="button" title="Numbered list" onmousedown="event.preventDefault();" onclick="document.execCommand(\'insertOrderedList\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">1. List</button>'
            . '<button type="button" title="Align left" onmousedown="event.preventDefault();" onclick="document.execCommand(\'justifyLeft\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">L</button>'
            . '<button type="button" title="Align center" onmousedown="event.preventDefault();" onclick="document.execCommand(\'justifyCenter\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">C</button>'
            . '<button type="button" title="Align right" onmousedown="event.preventDefault();" onclick="document.execCommand(\'justifyRight\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">R</button>'
            . '<span class="jedl-blog-tool-sep"></span>'
            . '<button type="button" title="Insert link" onmousedown="event.preventDefault();" onclick="var u=prompt(\'Enter URL:\',\'https://\');if(u){document.execCommand(\'createLink\',false,u);}document.getElementById(\'jedlBlogContentEditor\').focus();">🔗 Link</button>'
            . '<button type="button" title="Insert image by URL" onmousedown="event.preventDefault();" onclick="var u=prompt(\'Image URL:\',\'https://\');if(u){document.execCommand(\'insertImage\',false,u);}document.getElementById(\'jedlBlogContentEditor\').focus();">🖼 Image</button>'
            . '<button type="button" title="Remove formatting" onmousedown="event.preventDefault();" onclick="document.execCommand(\'removeFormat\',false,null);document.getElementById(\'jedlBlogContentEditor\').focus();">Clear</button>'
            . '<span class="jedl-blog-tool-sep"></span>'
            . '<button type="button" title="View HTML source" onmousedown="event.preventDefault();" onclick="var e=document.getElementById(\'jedlBlogContentEditor\');var h=prompt(\'HTML source:\',e.innerHTML);if(h!==null){e.innerHTML=h;e.focus();}">&lt;/&gt;</button>'
            . '</div><div id="jedlBlogContentEditor" class="jedl-blog-editor-area" contenteditable="true" role="textbox" aria-multiline="true" oninput="document.getElementById(\'jedlBlogContent\').value=this.innerHTML;" onblur="document.getElementById(\'jedlBlogContent\').value=this.innerHTML;">' . $oldContent . '</div><textarea id="jedlBlogContent" name="content" required class="jedl-blog-textarea" style="display:none;">' . $escape($oldContent) . '</textarea></div></div></div>'
            . '<div class="jedl-blog-field"><label class="jedl-blog-label" for="jedlBlogDatePosted">Date Posted <span class="jedl-blog-required">*</span></label><input id="jedlBlogDatePosted" name="datePosted" type="datetime-local" required value="' . $escape($oldDatePosted) . '" class="jedl-blog-input"></div>'
            . '<div class="jedl-blog-field"><label class="jedl-blog-label" for="jedlBlogKeywords">Keywords</label><input id="jedlBlogKeywords" name="keywords" type="text" maxlength="1000" value="' . $escape($oldKeywords) . '" placeholder="keyword1, keyword2" class="jedl-blog-input"></div>'
            . '<div class="jedl-blog-actions"><button type="submit" class="jedl-blog-btn jedl-blog-save">Save Blog Entry</button><button type="button" class="jedl-blog-btn jedl-blog-clear" id="jedlBlogClear">Clear Form</button></div>'
            . '</form>'
            . '<script>(function(){if(window.jedlBlogManagerBound){return;}window.jedlBlogManagerBound=true;function tokenFor(form){var field=form.querySelector("[name=csrfToken]");if(field&&field.value){return field.value;}var candidates=document.querySelectorAll("input[name=csrfToken]");for(var i=0;i<candidates.length;i++){if(candidates[i].value){if(field){field.value=candidates[i].value;}return candidates[i].value;}}if(typeof window.csrfToken === "string" && window.csrfToken){if(field){field.value=window.csrfToken;}return window.csrfToken;}var meta=document.querySelector("meta[name=csrf-token]");if(meta&&meta.content){if(field){field.value=meta.content;}return meta.content;}return "";}function replaceContent(form,response){if(response&&response.content!==undefined){var root=document.getElementById("jedlBlogManagerRoot");if(root){root.outerHTML=response.content;return true;}var c=form.closest(".pkp_modal_content")||form.closest(".pkp_modal");if(c){c.innerHTML=response.content;return true;}return false;}return false;}document.addEventListener("submit",function(e){var form=e.target;if(!form||!form.matches("#jedlBlogEntryForm,.jedl-blog-action-form")){return;}e.preventDefault();var token=tokenFor(form);if(!token){alert("OJS CSRF token was not found. Please reload the page and try again.");return;}var confirmText=form.getAttribute("data-confirm");if(confirmText&&!window.confirm(confirmText)){return;}var button=form.querySelector("button[type=submit]");var original=button?button.textContent:"";if(button){button.disabled=true;button.textContent=form.classList.contains("jedl-blog-action-form")?"Working...":"Saving...";}var x=new XMLHttpRequest();x.open("POST",form.action,true);x.setRequestHeader("X-Requested-With","XMLHttpRequest");x.setRequestHeader("Accept","application/json");x.onreadystatechange=function(){if(x.readyState!==4){return;}try{var r=JSON.parse(x.responseText);if(!replaceContent(form,r)){throw new Error("Invalid OJS response.");}}catch(err){if(button){button.disabled=false;button.textContent=original;}alert("Blog action failed: "+err.message);}};x.send(new FormData(form));},false);document.addEventListener("click",function(e){var clear=e.target.closest?e.target.closest("#jedlBlogClear"):null;if(clear){var f=document.getElementById("jedlBlogEntryForm");if(f){f.reset();var e=document.getElementById(\"jedlBlogContentEditor\");if(e){e.innerHTML=\"\";}var c=document.getElementById(\"jedlBlogContent\");if(c){c.value=\"\";}}}},false);})();</script>'
            . '<p style="margin-top:16px;font-size:12px;color:#666;">JEDL Blog Plugin v3.2.5.0</p>'
            . '</div></div></div>';

        return $html;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);

        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $ajaxModal = new AjaxModal(
            $router->url(
                request: $request,
                op: 'manage',
                params: [
                    'plugin' => $this->getName(),
                    'category' => $this->getCategory(),
                    'action' => 'index',
                ]
            ),
            $this->getDisplayName()
        );

        array_unshift(
            $actions,
            new LinkAction(
                'settings',
                $ajaxModal,
                __('plugins.generic.blog.editAddContent'),
                null
            )
        );

        return $actions;
    }

    /**
     * Ensure a "Post" item linking to the public Blog index exists in the
     * journal's primary navigation menu.
     *
     * The menu item is stored using OJS's native navigation-menu tables and
     * NMI_TYPE_CUSTOM path "blog", so OJS's navigation service generates
     * the correct journal URL for the current context.
     */
    /**
     * Ensure the Post navigation item when OJS has a real journal/frontend
     * request context. This is more reliable than doing it inside register(),
     * because register() can run before the journal context is available.
     */
    public function callbackEnsurePostNavigationMenu($hookName, $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if (!$context || (int) $context->getId() <= 0) {
            return Hook::CONTINUE;
        }

        // The hook may run for frontend and backend templates. The menu
        // operation is idempotent, so creating/checking it here is reliable
        // even when a theme uses a template path that does not start with
        // frontend/.
        $this->ensurePostNavigationMenu((int) $context->getId());
        return Hook::CONTINUE;
    }

    protected function formatBlogDate($date): string
    {
        $date = (string) $date;
        try {
            return (new \DateTime($date))->format('F j, Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    protected function ensurePostNavigationMenu(int $contextId): void
    {
        try {
            /** @var \PKP\navigationMenu\NavigationMenuDAO $navigationMenuDao */
            $navigationMenuDao = DAORegistry::getDAO('NavigationMenuDAO');
            /** @var \PKP\navigationMenu\NavigationMenuItemDAO $navigationMenuItemDao */
            $navigationMenuItemDao = DAORegistry::getDAO('NavigationMenuItemDAO');
            /** @var \PKP\navigationMenu\NavigationMenuItemAssignmentDAO $assignmentDao */
            $assignmentDao = DAORegistry::getDAO('NavigationMenuItemAssignmentDAO');

            $request = Application::get()->getRequest();
            $context = $request ? $request->getContext() : null;
            if (!$context || (int) $context->getId() !== $contextId) {
                return;
            }

            // OJS stores Remote URL values in navigation_menu_item_settings
            // under the localized "remoteUrl" setting. Use the journal's
            // primary locale so the menu item behaves exactly like an item
            // created through Website > Navigation > Add Item > Remote URL.
            $primaryLocale = (string) $context->getPrimaryLocale();
            $blogUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'blog'
            );

            // Find the journal's primary navigation menu. OJS 3.5 returns a
            // DAOResultFactory here, but handle an array as a compatibility
            // fallback as well.
            $primaryMenu = null;
            $primaryMenus = $navigationMenuDao->getByArea($contextId, 'primary');
            if ($primaryMenus instanceof DAOResultFactory) {
                $primaryMenu = $primaryMenus->next();
            } elseif (is_array($primaryMenus) && !empty($primaryMenus)) {
                $primaryMenu = reset($primaryMenus);
            }

            if (!$primaryMenu) {
                $primaryMenu = $navigationMenuDao->newDataObject();
                $primaryMenu->setTitle('Primary Navigation Menu');
                $primaryMenu->setAreaName('primary');
                $primaryMenu->setContextId($contextId);
                $navigationMenuDao->insertObject($primaryMenu);
            }

            // Find an existing Post item. Older plugin builds created it as
            // NMI_TYPE_CUSTOM with path "blog". Reuse that item and convert it
            // in-place to NMI_TYPE_REMOTE_URL rather than creating duplicates.
            $menuItem = $navigationMenuItemDao->getByPath($contextId, 'blog');

            if (!$menuItem) {
                // It may already have been converted to Remote URL on an
                // earlier request. Search all context items for the Post title.
                $items = $navigationMenuItemDao->getByContextId($contextId);
                while ($candidate = $items->next()) {
                    $candidateTitle = (string) ($candidate->getLocalizedTitle() ?? '');
                    if ($candidateTitle === 'Post' && in_array(
                        $candidate->getType(),
                        [NavigationMenuItem::NMI_TYPE_CUSTOM, NavigationMenuItem::NMI_TYPE_REMOTE_URL],
                        true
                    )) {
                        $menuItem = $candidate;
                        break;
                    }
                }
            }

            if (!$menuItem) {
                $menuItem = $navigationMenuItemDao->newDataObject();
                $menuItem->setContextId($contextId);
                $menuItem->setPath(null);
                $menuItem->setType(NavigationMenuItem::NMI_TYPE_REMOTE_URL);
                $menuItem->setTitle('Post', $primaryLocale);
                $menuItem->setRemoteUrl($blogUrl, $primaryLocale);
                $navigationMenuItemDao->insertObject($menuItem);
            } else {
                // Convert the old Custom Page item to a native Remote URL item.
                $menuItem->setPath(null);
                $menuItem->setType(NavigationMenuItem::NMI_TYPE_REMOTE_URL);
                $menuItem->setTitle('Post', $primaryLocale);
                $menuItem->setRemoteUrl($blogUrl, $primaryLocale);
                $navigationMenuItemDao->updateObject($menuItem);
            }

            $menuId = (int) $primaryMenu->getId();
            $itemId = (int) $menuItem->getId();
            if ($menuId <= 0 || $itemId <= 0) {
                throw new \RuntimeException('Navigation menu or item ID was not created.');
            }

            $existingAssignment = $assignmentDao->getByNMIIdAndMenuIdAndParentId(
                $itemId,
                $menuId,
                null
            );
            if ($existingAssignment) {
                return;
            }

            $sequence = 0;
            $assignments = $assignmentDao->getByMenuIdAndParentId($menuId, null);
            if ($assignments instanceof DAOResultFactory) {
                while ($assignment = $assignments->next()) {
                    $sequence = max($sequence, (int) $assignment->getSequence() + 1);
                }
            } elseif (is_iterable($assignments)) {
                foreach ($assignments as $assignment) {
                    $sequence = max($sequence, (int) $assignment->getSequence() + 1);
                }
            }

            $assignment = $assignmentDao->newDataObject();
            $assignment->setMenuItemId($itemId);
            $assignment->setMenuId($menuId);
            $assignment->setParentId(null);
            $assignment->setSequence($sequence);
            $assignmentDao->insertObject($assignment);
        } catch (\Throwable $e) {
            error_log('JEDL Blog Plugin: could not create/update Post Remote URL navigation item: ' . $e->getMessage());
        }
    }

    public function callbackShowWebsiteSettingsTabs($hookName, $args)
    {
        $templateMgr = $args[1];
        $output = &$args[2];
        $output .= $templateMgr->fetch($this->getTemplateResource('blogTab.tpl'));
        return Hook::CONTINUE;
    }

    /**
     * Register the public Blog page handler.
     *
     * The stable OJS 3.5 router supports loading a plugin page handler from
     * the plugin handler path. We set both the handler file/class and args[3]
     * so this build remains compatible with the OJS 3.5.0-5 routing behavior.
     */
    public function getHandlerPath(): string
    {
        return $this->getPluginPath() . '/pages';
    }

    public function callbackHandleContent($hookName, $args): bool
    {
        $page = $args[0] ?? '';
        $op = $args[1] ?? '';
        $handler =& $args[3];

        if ($page !== 'blog' || !in_array($op, ['', 'index', 'view'], true)) {
            return Hook::CONTINUE;
        }

        // PKPHandler/BlogHandler has a no-argument constructor on OJS 3.5.
        // Passing the plugin to the constructor causes a PHP 500 on public routes.
        BlogHandler::setPlugin($this);
        $handler = new BlogHandler();
        return Hook::ABORT;
    }

    public function setupGridHandler($hookName, $params)
    {
        $component = &$params[0];
        $componentInstance = &$params[2];

        if ($component === 'plugins.generic.blog.controllers.grid.BlogGridHandler') {
            import($component);
            $componentInstance = new controllers\grid\BlogGridHandler($this);
            return true;
        }

        return false;
    }

    public function getJavaScriptURL($request)
    {
        return $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js';
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\blog\\BlogPlugin', '\\BlogPlugin');
}
