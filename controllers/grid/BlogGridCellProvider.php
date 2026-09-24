<?php

namespace APP\plugins\generic\blog\controllers\grid;

use PKP\controllers\grid\GridCellProvider;

class BlogGridCellProvider extends GridCellProvider {
	function getCellActions($request, $row, $column, $position = GRID_ACTION_POSITION_DEFAULT) {
		return parent::getCellActions($request, $row, $column, $position);
	}

	function getTemplateVarsFromRowColumn($row, $column) {
		$blogEntry = $row->getData();
		return ['label' => $blogEntry->getTitle()];
	}
}
