<?php

namespace APP\plugins\generic\blog\classes;

use PKP\db\DAO;
use PKP\db\DAOResultFactory;

class BlogKeywordDAO extends DAO {


	public function getKeyword($row) {
		return $row['keyword'];
	}

	public function getKeywordId($row) {
		return $row['keyword_id'];
	}

	public function getKeywordsByEntryId($entryId) {
		$keywords = [];
		$sql = 'SELECT k.keyword FROM blog_entries_keywords b, blog_keywords k WHERE b.entry_id = ? AND b.keyword_id=k.keyword_id';
		$params = [(int) $entryId];
		$result = $this->retrieve($sql, $params);
		$resultFactory = new DAOResultFactory($result, $this, 'getKeyword', [], $sql, $params);

		$kw = [];
		while (!$resultFactory->eof()) {
			$kw[] = $resultFactory->next();
		}
		$keywords['en_US'] = $kw;
		return $keywords;
	}

	public function setKeywordsByEntryId($entryId, $keywords) {
		$k = is_array($keywords) && array_key_exists('keywords', $keywords) ? $keywords['keywords'] : $keywords;
		if ($k === null) {
			return;
		}
		if (!is_array($k)) {
			$k = [$k];
		}

		$this->update('DELETE FROM blog_entries_keywords WHERE entry_id = ?', [(int) $entryId]);

		foreach ($k as $keyword) {
			$keyword = trim((string) $keyword);
			if ($keyword === '') continue;

			$sql = 'SELECT keyword_id FROM blog_keywords WHERE keyword = ?';
			$params = [$keyword];
			$result = $this->retrieve($sql, $params);
			$resultFactory = new DAOResultFactory($result, $this, 'getKeywordId', [], $sql, $params);

			if ($resultFactory->getCount() != 0) {
				$keywordId = $resultFactory->next();
			} else {
				$this->update('INSERT INTO blog_keywords (keyword) VALUES (?)', [$keyword]);
				$keywordId = $this->getInsertId();
			}

			$this->update(
				'INSERT INTO blog_entries_keywords (entry_id, keyword_id) VALUES (?,?)',
				[(int) $entryId, (int) $keywordId]
			);
		}
	}

	public function getBlogKeywords($contextId, $year = null) {
		$kw = [];
		$sql = 'SELECT DISTINCT k.keyword AS keyword FROM blog_keywords k, blog_entries_keywords b, blog_entries e WHERE e.context_id = ? AND k.keyword_id=b.keyword_id AND b.entry_id=e.entry_id'
			. ($year ? ' AND YEAR(e.date_posted) = ? ' : '')
			. ' ORDER BY k.keyword';

		$params = [(int) $contextId];
		if ($year) $params[] = (int) $year;

		$result = $this->retrieve($sql, $params);
		$resultFactory = new DAOResultFactory($result, $this, 'getKeyword', [], $sql, $params);

		while (!$resultFactory->eof()) {
			$kw[] = $resultFactory->next();
		}
		return $kw;
	}
}
