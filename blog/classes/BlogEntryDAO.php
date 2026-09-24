<?php

namespace APP\plugins\generic\blog\classes;

use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\core\DataObject;
use APP\plugins\generic\blog\classes\BlogEntry;
use PKP\db\DAOResultFactory;

class BlogEntryDAO extends DAO {

	public function ensureStatusColumn(): void {
		try {
			$result = $this->retrieve(
				"SELECT COUNT(*) AS count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'blog_entries' AND column_name = 'is_enabled'"
			);
			$exists = $result ? (int) $result->fields['count'] : 0;
			if ($exists === 0) {
				$this->update("ALTER TABLE blog_entries ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1");
			}
		} catch (\Throwable $e) {
			error_log('JEDL Blog Plugin: could not ensure is_enabled column: ' . $e->getMessage());
		}
	}

	public function getById($blogEntryId) {
		$this->ensureStatusColumn();
		$params = [(int) $blogEntryId];
		$sql = 'SELECT * FROM blog_entries WHERE entry_id = ?';
		$result = $this->retrieve($sql, $params);
		$resultFactory = new DAOResultFactory($result, $this, '_fromRow', [], $sql, $params);
		return $resultFactory->getCount() ? $resultFactory->next() : null;
	}

	public function getEntriesByContextId($contextId, $keyword = null, $year = null, $pagingParams = null, $includeDisabled = false) {
		$this->ensureStatusColumn();
		$params = [(int) $contextId];
		if ($keyword) $params[] = $keyword;
		if ($year) $params[] = (int) $year;

		$pagingSql = '';
		if ($pagingParams) {
			$pagingSql = ' LIMIT ' . (int) $pagingParams['offset'] . ', ' . (int) $pagingParams['count'];
		}

		$sql = 'SELECT DISTINCT e.* FROM blog_entries e'
			. ($keyword ? ', blog_keywords k, blog_entries_keywords b' : '')
			. ' WHERE e.context_id = ? '
			. (!$includeDisabled ? ' AND e.is_enabled = 1 ' : '')
			. ($keyword ? ' AND e.entry_id=b.entry_id AND k.keyword_id=b.keyword_id AND k.keyword = ?' : '')
			. ($year ? ' AND YEAR(e.date_posted) = ? ' : '')
			. ' ORDER BY e.date_posted DESC '
			. $pagingSql;

		$result = $this->retrieve($sql, $params);
		return new DAOResultFactory($result, $this, '_fromRow', [], $sql, $params);
	}

	/**
	 * Return only enabled entries for the public Blog page.
	 * Uses a dedicated SQL query so public rendering does not depend on
	 * BlogEntry::isEnabled() or per-row status lookups.
	 */
	public function getPublicEntriesByContextId($contextId) {
		$this->ensureStatusColumn();
		$params = [(int) $contextId];
		$sql = 'SELECT * FROM blog_entries WHERE context_id = ? AND is_enabled = 1 ORDER BY date_posted DESC';
		$result = $this->retrieve($sql, $params);
		return new DAOResultFactory($result, $this, '_fromRow', [], $sql, $params);
	}

	public function getEntryYears($contextId, $keyword = null) {
		$params = [(int) $contextId];
		if ($keyword) $params[] = $keyword;

		$sql = 'SELECT DISTINCT YEAR(e.date_posted) AS year FROM blog_entries e '
			. ($keyword ? ', blog_keywords k, blog_entries_keywords b' : '')
			. ' WHERE e.context_id = ? '
			. ($keyword ? ' AND e.entry_id=b.entry_id AND k.keyword_id=b.keyword_id AND k.keyword = ?' : '')
			. ' ORDER BY year DESC';

		$result = $this->retrieve($sql, $params);
		$resultFactory = new DAOResultFactory($result, $this, '_getYear', [], $sql, $params);
		$years = [];
		while (!$resultFactory->eof()) {
			$years[] = $resultFactory->next();
		}
		return $years;
	}

	public function insertObject($blogEntry): int {
		$this->update(
			'INSERT INTO blog_entries (context_id, title, content, byline, date_posted, is_enabled) VALUES (?,?,?,?,?,1)',
			[
				(int) $blogEntry->getContextId(),
				$blogEntry->getTitle(),
				$blogEntry->getContent(),
				$blogEntry->getByline(),
				$blogEntry->getDatePosted(),
			]
		);
		$blogEntry->setId($this->getInsertId());
		return $blogEntry->getId();
	}

	public function updateObject($blogEntry) {
		$this->update(
			'UPDATE blog_entries SET context_id = ?, title = ?, content = ?, byline = ?, date_posted = ? WHERE entry_id = ?',
			[
				(int) $blogEntry->getContextId(),
				$blogEntry->getTitle(),
				$blogEntry->getContent(),
				$blogEntry->getByline(),
				$blogEntry->getDatePosted(),
				(int) $blogEntry->getId(),
			]
		);
	}

	public function deleteById($entryId) {
		$this->update('DELETE FROM blog_entries_keywords WHERE entry_id = ?', [(int) $entryId]);
		$this->update('DELETE FROM blog_entries WHERE entry_id = ?', [(int) $entryId]);
	}

	public function deleteObject($blogEntry) {
		if ($blogEntry) {
			$this->deleteById($blogEntry->getId());
		}
	}

	public function setEnabled($entryId, bool $enabled): void {
		$this->ensureStatusColumn();
		$this->update('UPDATE blog_entries SET is_enabled = ? WHERE entry_id = ?', [$enabled ? 1 : 0, (int) $entryId]);
	}

	public function isEnabled($entryId): bool {
		$this->ensureStatusColumn();
		$result = $this->retrieve('SELECT is_enabled FROM blog_entries WHERE entry_id = ?', [(int) $entryId]);
		return $result && !$result->EOF ? ((int) $result->fields['is_enabled'] === 1) : false;
	}

	public function newDataObject() {
		return new BlogEntry();
	}

	public function _getCount($row) {
		return $row['COUNT(*)'];
	}

	public function _getYear($row) {
		return $row['year'];
	}

	public function _fromRow(array $row): DataObject {
		$blogEntry = $this->newDataObject();
		$blogEntry->setId($row['entry_id']);
		$blogEntry->setContextId($row['context_id']);
		$blogEntry->setTitle($row['title']);
		$blogEntry->setContent($row['content']);
		$blogEntry->setByline($row['byline']);
		$blogEntry->setDatePosted($row['date_posted']);
		$blogEntry->setEnabled(isset($row['is_enabled']) ? (bool) $row['is_enabled'] : true);

		$blogKeywordDao = DAORegistry::getDAO('BlogKeywordDAO');
		$blogEntry->setKeywords($blogKeywordDao->getKeywordsByEntryId($blogEntry->getId()));
		return $blogEntry;
	}
	public function getCountByContextId($contextId, $keyword = null, $year = null) {
		$params = [(int) $contextId];
		if ($keyword) $params[] = $keyword;
		if ($year) $params[] = (int) $year;

		$sql = 'SELECT COUNT(*) AS count FROM blog_entries e '
			. ($keyword ? ', blog_keywords k, blog_entries_keywords b ' : '')
			. ' WHERE e.context_id = ? '
			. ($keyword ? ' AND e.entry_id=b.entry_id AND k.keyword_id=b.keyword_id AND k.keyword = ? ' : '')
			. ($year ? ' AND YEAR(e.date_posted) = ? ' : '');

		$result = $this->retrieve($sql, $params);
		return $result ? (int) $result->fields['count'] : 0;
	}
}
