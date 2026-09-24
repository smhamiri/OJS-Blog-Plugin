<?php

namespace APP\plugins\generic\blog\controllers\grid;

use PKP\controllers\grid\GridHandler;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\db\DAO;
use PKP\security\Role;
use PKP\controllers\grid\GridColumn;
use APP\plugins\generic\blog\controllers\grid\BlogGridRow;
use APP\plugins\generic\blog\controllers\grid\BlogGridCellProvider;
use APP\plugins\generic\blog\controllers\grid\form\BlogEntryForm;

class BlogGridHandler extends GridHandler
{
    protected static $plugin;

    public static function setPlugin($plugin)
    {
        self::$plugin = $plugin;
    }

    public function __construct($plugin = null)
    {
        parent::__construct();

        if ($plugin) {
            self::$plugin = $plugin;
        }

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
            ['fetchGrid', 'fetchRow', 'addBlogEntry', 'editBlogEntry', 'updateBlogEntry', 'delete']
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * OJS 3.5 grid initialization.
     * Keep database access out of initialize(); loadData() supplies the rows.
     */
    public function initialize($request, $args = null)
    {
        parent::initialize($request, $args);

        $this->setTitle('plugins.generic.blog.blog');
        $this->setEmptyRowText('plugins.generic.blog.noneCreated');

        $router = $request->getRouter();
        $this->addAction(
            new LinkAction(
                'addBlogEntry',
                new AjaxModal(
                    $router->url($request, null, null, 'addBlogEntry'),
                    __('plugins.generic.blog.addBlogEntry'),
                    'modal_add_item'
                ),
                __('plugins.generic.blog.addBlogEntry'),
                'add_item'
            )
        );

        $this->addColumn(new GridColumn(
            'title',
            'plugins.generic.blog.pageTitle',
            null,
            'controllers/grid/gridCell.tpl',
            new BlogGridCellProvider()
        ));
    }

    /**
     * OJS/PKP standard data-provider pattern.
     * The DAO result is converted by GridHandler itself.
     */
    protected function loadData($request, $filter)
    {
        // Diagnostic mode: intentionally avoid the database query.
        // If the grid loads, the problem is in BlogEntryDAO or its tables.
        return [];
    }

    protected function getRowInstance()
    {
        return new BlogGridRow();
    }

    public function addBlogEntry($args, $request)
    {
        return $this->editBlogEntry($args, $request);
    }

    public function editBlogEntry($args, $request)
    {
        $blogEntryId = $request->getUserVar('blogEntryId');
        $context = $request->getContext();

        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.blog.contextRequired'));
        }

        $blogEntryForm = new BlogEntryForm(self::$plugin, $context->getId(), $blogEntryId ?: null);
        $blogEntryForm->initData();
        return new JSONMessage(true, $blogEntryForm->fetch($request));
    }

    public function updateBlogEntry($args, $request)
    {
        $blogEntryId = $request->getUserVar('blogEntryId');
        $context = $request->getContext();

        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.blog.contextRequired'));
        }

        $blogEntryForm = new BlogEntryForm(self::$plugin, $context->getId(), $blogEntryId ?: null);
        $blogEntryForm->readInputData();

        if ($blogEntryForm->validate()) {
            $blogEntryForm->execute();
            return DAO::getDataChangedEvent();
        }

        return new JSONMessage(true, $blogEntryForm->fetch($request));
    }

    public function delete($args, $request)
    {
        $blogEntryId = $request->getUserVar('blogEntryId');
        if (!$blogEntryId) {
            return new JSONMessage(false);
        }

        $blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
        $blogEntry = $blogEntryDao->getById($blogEntryId);
        if ($blogEntry) {
            $blogEntryDao->deleteObject($blogEntry);
        }

        return DAO::getDataChangedEvent();
    }
}
