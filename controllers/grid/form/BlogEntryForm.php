<?php

namespace APP\plugins\generic\blog\controllers\grid\form;

use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidator;
use PKP\db\DAORegistry;
use APP\template\TemplateManager;

class BlogEntryForm extends Form {
	var $contextId;
	var $blogEntryId;
	var $plugin;

	public function __construct($blogPlugin, $contextId, $blogEntryId = null) {
		parent::__construct($blogPlugin->getTemplateResource('editBlogEntryForm.tpl'));

		$this->contextId = $contextId;
		$this->blogEntryId = $blogEntryId;
		$this->plugin = $blogPlugin;

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
		$this->addCheck(new FormValidator($this, 'title', 'required', 'plugins.generic.blog.nameRequired'));
		$this->addCheck(new FormValidator($this, 'content', 'required', 'plugins.generic.blog.nameRequired'));
		$this->addCheck(new FormValidator($this, 'datePosted', 'required', 'plugins.generic.blog.nameRequired'));
	}

	public function initData() {
		if ($this->blogEntryId) {
			$blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
			$blogKeywordDao = DAORegistry::getDAO('BlogKeywordDAO');
			$blogEntry = $blogEntryDao->getById($this->blogEntryId);

			if ($blogEntry) {
				$this->setData('title', $blogEntry->getTitle());
				$this->setData('content', $blogEntry->getContent());
				$this->setData('byline', $blogEntry->getByline());
				$this->setData('datePosted', $blogEntry->getDatePosted());
				$this->setData('keywords', $blogKeywordDao->getKeywordsByEntryId($this->blogEntryId));
			}
		}
	}

	public function readInputData() {
		$this->readUserVars(['title', 'content', 'byline', 'keywords', 'datePosted']);
	}

	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager();
		$templateMgr->assign([
			'blogEntryId' => $this->blogEntryId,
			'pluginJavaScriptURL' => $this->plugin->getJavaScriptURL($request),
		]);
		return parent::fetch($request, $template, $display);
	}

	public function execute() {
		$blogEntryDao = DAORegistry::getDAO('BlogEntryDAO');
		$blogKeywordDao = DAORegistry::getDAO('BlogKeywordDAO');

		if ($this->blogEntryId) {
			$blogEntry = $blogEntryDao->getById($this->blogEntryId);
			if (!$blogEntry) {
				return;
			}
		} else {
			$blogEntry = $blogEntryDao->newDataObject();
			$blogEntry->setContextId($this->contextId);
		}

		$blogEntry->setTitle($this->getData('title'));
		$blogEntry->setContent($this->getData('content'));
		$blogEntry->setByline((string) $this->getData('byline'));
		$blogEntry->setDatePosted($this->getData('datePosted'));

		if ($this->blogEntryId) {
			$blogEntryDao->updateObject($blogEntry);
		} else {
			$this->blogEntryId = $blogEntryDao->insertObject($blogEntry);
		}

		$blogKeywordDao->setKeywordsByEntryId($this->blogEntryId, $this->getData('keywords'));
	}
}
