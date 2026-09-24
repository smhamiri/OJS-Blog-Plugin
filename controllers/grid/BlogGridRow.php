<?php

namespace APP\plugins\generic\blog\controllers\grid;

use PKP\controllers\grid\GridRow;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RemoteActionConfirmationModal;

class BlogGridRow extends GridRow {

	public function initialize($request, $template = null) {
		parent::initialize($request, $template);

		$blogEntryId = $this->getId();
		if (!empty($blogEntryId)) {
			$router = $request->getRouter();

			$this->addAction(
				new LinkAction(
					'editBlogEntry',
					new AjaxModal(
						$router->url($request, null, null, 'editBlogEntry', null, ['blogEntryId' => $blogEntryId]),
						__('grid.action.edit'),
						'modal_edit',
						true
					),
					__('grid.action.edit'),
					'edit'
				)
			);

			$this->addAction(
				new LinkAction(
					'delete',
					new RemoteActionConfirmationModal(
						$request->getSession(),
						__('common.confirmDelete'),
						__('grid.action.delete'),
						$router->url($request, null, null, 'delete', null, ['blogEntryId' => $blogEntryId]),
						'modal_delete'
					),
					__('grid.action.delete'),
					'delete'
				)
			);
		}
	}
}
