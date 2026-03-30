<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Models\Admin\System\DynamicTables;
use Canvastack\Origin\Library\Components\Table\Craft\Method\Post;

/**
 * Created on 21 Apr 2021
 * Time Created	: 08:13:39
 *
 * @filesource	Builder.php
 *
 * @author		wisnuwidi@canvastack.com - 2021
 * @copyright	wisnuwidi
 * @email		wisnuwidi@canvastack.com
 */
 
class Builder {
	use Scripts;
	
	public $model;
	public $method = 'GET';
	
	protected function setMethod($method) {
		$this->method = $method;
	}
	
	protected function table($name, $columns = [], $attributes = [], $label = null) {
		$data = [];
		
		if (!empty($attributes[$name]['model'])) {
			if ('sql' === $attributes[$name]['model']) {
				$data[$name]['model'] = 'sql';
				$data[$name]['sql']   = $attributes[$name]['query'];
			} else {
				$model = new $attributes[$name]['model']();
				$data[$name]['model'] = $attributes[$name]['model'];
			}
		} else {
			$model = new DynamicTables(null, $this->connection);
			$model->setTable($name);
			$data[$name]['model']       = get_class($model);
			$attributes[$name]['model'] = get_class($model);
		}
		
		if (!empty($model)) {
			$this->model[$name]['type']   = 'model';
			$this->model[$name]['source'] = $model;
		} else {
			$this->model[$name]['type']   = 'sql';
			$this->model[$name]['source'] = $data[$name]['sql'];
		}
		
		if (!empty($attributes[$name])) {
			$tableID          = isset($attributes[$name]['attributes']['table_id']) ? $attributes[$name]['attributes']['table_id'] : 'datatable';
			$tableClass       = isset($attributes[$name]['attributes']['table_class']) ? $attributes[$name]['attributes']['table_class'] : 'table';
			$this->serverSide = isset($attributes[$name]['server_side']['status']) ? $attributes[$name]['server_side']['status'] : false;
			$this->customURL  = isset($attributes[$name]['server_side']['custom_url']) ? $attributes[$name]['server_side']['custom_url'] : null;
		}
		
		$data[$name]['name']       = $name;
		$data[$name]['columns']    = $columns[$name];
		$data[$name]['attributes'] = $attributes[$name];
		
		// FORMULATION
		if (!empty($data[$name]['attributes']['conditions']['formula'])) {
			if (!empty($data[$name]['columns']['lists'])) {
				$data[$name]['columns']['lists'] = $this->setFormulaColumns($data[$name]['columns']['lists'], $data[$name]);
			}
		}
		
		// RENDER DATA TABLE
		if (false !== $name) {
			$list = null;
			if (canvastack_string_contained($label, ':setLabelTable')) {
				$list = null;
				$label = str_replace(':setLabelTable', '', $label);
			} else {
				$list = ' List(s)';
			}
			
			if (empty($label)) {
				$titleText  = ucwords(str_replace('_', ' ', $name)) . $list;
			} else {
				$titleText  = ucwords(str_replace('_', ' ', $label)) . $list;
			}
			// SECURITY: Escape title text untuk mencegah XSS
			$safeTitleText = htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8');
			$tableTitle = '<div class="panel-heading"><div class="pull-left"><h3 class="panel-title">' . $safeTitleText . '</h3></div><div class="clearfix"></div></div>';
		}
		
		$baseTableAttributes = ['id' => $tableID, 'class' => $tableClass];
		$tableAttributes     = $baseTableAttributes;
		if (!empty($attributes[$name]['attributes']['add_attributes'])) {
			$tableAttributes = array_merge_recursive($baseTableAttributes, $attributes[$name]['attributes']['add_attributes']);
		}
		
		$table  = '<div class="panel-body no-padding">';
		$table .= '<table' . $this->setAttributes($tableAttributes) . '>';
		$table .= $this->header($data[$name]);
		$table .= '</table>';
		$table .= '</div>';
		// RENDER DATA TABLE
		
		$datatable_columns = $this->body($data[$name]);
		$html  = '<div class="row">';
		$html .= '<div class="col-md-12">';
		$html .= '<div class="panel">' . $tableTitle . '<br />';
		$html .= '<div class="relative canvastack-table-box-' . $tableID . '">';
		if (!empty($this->filter_contents[$tableID]['id']) && $tableID === $this->filter_contents[$tableID]['id']) {
			$html .= '<span class="canvastack-dt-search-box hide" id="canvastack-' . $tableID . '-search-box">' . $this->filterButton($this->filter_contents[$tableID]) . '</span>';
			$html .= $this->filterModalbox($this->filter_contents[$tableID]);
		}
		$html .= $table . $datatable_columns;
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';
		
		return $html;
	}
	
	private $columnManipulated = [];
	private function checkColumnLabel($check_labels, $columns) {
		$labels = [];
		foreach ($columns as $icol => $vcol) {
			if (!empty($this->labels[$vcol])) {
				$this->columnManipulated[$this->labels[$vcol]] = $vcol;
				$labels[$icol] = $this->labels[$vcol];
			} else {
				$this->columnManipulated[$vcol] = $vcol;
				$labels[$icol] = $vcol;
			}
		}
		
		return $labels;
	}
	
	/**
	 * Build table header with columns
	 * Refactored to reduce nesting from 5 to 2 levels
	 * 
	 * @param array $data Table data configuration
	 * @return string HTML for table header
	 */
	private function header($data = []) {
		$config = $this->prepareHeaderConfig($data);
		
		// Early return for empty columns
		if (empty($config['columns']) || !is_array($config['columns'])) {
			return '<thead></thead>';
		}
		
		if (!empty($config['mergeColumn'])) {
			return $this->buildMergedHeader($config);
		}
		
		return $this->buildStandardHeader($config);
	}
	
	/**
	 * Prepare header configuration from data
	 * 
	 * @param array $data Table data
	 * @return array Configuration array
	 */
	private function prepareHeaderConfig($data) {
		$columns = $data['columns'];
		$attributes = $data['attributes'];
		
		$sortable = $data['columns']['sortable'] ?? false;
		$hiddenColumn = $data['columns']['hidden_columns'] ?? [];
		$alignColumn = $this->extractAlignmentConfig($columns);
		$mergeColumn = $this->extractMergeConfig($columns);
		
		// Prepare columns list
		$columnsList = $columns['lists'] ?? $columns;
		$columnsList = $this->addSpecialColumns($columnsList, $attributes);
		
		if (!empty($this->labels)) {
			$columnsList = $this->checkColumnLabel($this->labels, $columnsList);
		}
		
		$dataColumns = $this->columnManipulated ?? [];
		
		// Extract colors
		[$columnColor, $headerColor] = $this->extractColorSettings($attributes);
		
		// Setup attributes for sortable columns
		$attributes['sortable_columns'] = $sortable;
		$attributes['attributes']['column']['id'] = [];
		$attributes['attributes']['column']['class'] = [];
		
		$widthColumn = $attributes['attributes']['column_width'] ?? [];
		
		return compact('columns', 'columnsList', 'attributes', 'sortable', 'hiddenColumn', 
		               'alignColumn', 'mergeColumn', 'dataColumns', 'columnColor', 
		               'headerColor', 'widthColumn');
	}
	
	/**
	 * Extract alignment configuration from columns
	 * 
	 * @param array $columns Column configuration
	 * @return array Alignment configuration
	 */
	private function extractAlignmentConfig($columns) {
		$alignColumn = [];
		
		if (empty($columns['align'])) {
			return $alignColumn;
		}
		
		foreach ($columns['align'] as $align => $column_data) {
			if ($column_data['header'] !== true) {
				continue;
			}
			
			foreach ($column_data['columns'] as $field) {
				$alignColumn['header'][$field] = $align;
			}
		}
		
		return $alignColumn;
	}
	
	/**
	 * Extract merge column configuration
	 * 
	 * @param array $columns Column configuration
	 * @return array|null Merge configuration
	 */
	private function extractMergeConfig($columns) {
		if (empty($columns['merge'])) {
			return null;
		}
		
		$mergeColumn = $columns['merge'];
		
		// Manipulation Column Merged Label
		if (!empty($this->labels)) {
			$merged_labels = [];
			foreach ($mergeColumn as $colmergename => $colmerged) {
				$merged_labels[$colmergename]['position'] = $colmerged['position'];
				$merged_labels[$colmergename]['counts'] = $colmerged['counts'];
				$merged_labels[$colmergename]['columns'] = $this->checkColumnLabel($this->labels, $colmerged['columns']);
			}
			if (!empty($merged_labels)) {
				$mergeColumn = $merged_labels;
			}
		}
		
		return $mergeColumn;
	}
	
	/**
	 * Add special columns (numbering, actions) to column list
	 * 
	 * @param array $columns Column list
	 * @param array $attributes Table attributes
	 * @return array Modified column list
	 */
	private function addSpecialColumns($columns, $attributes) {
		$numbering = $attributes['numbering'] ?? false;
		$actions = $attributes['actions'] ?? false;
		
		if (true === $numbering && !in_array('id', $columns)) {
			$columns = array_merge(['number_lists'], $columns);
		}
		
		if (!empty($actions)) {
			$columns[] = 'action';
		}
		
		return $columns;
	}
	
	/**
	 * Build merged header (with merge columns)
	 * 
	 * @param array $config Header configuration
	 * @return string HTML for merged header
	 */
	private function buildMergedHeader($config) {
		// Merge alignment classes if needed
		if (!empty($config['alignColumn']['header'])) {
			$config['attributes']['attributes']['column']['class'] = array_merge_recursive(
				$config['attributes']['attributes']['column']['class'],
				$config['alignColumn']['header']
			);
		}
		
		$headerContent = $this->mergeColumns(
			$config['mergeColumn'],
			$config['columnsList'],
			$config['attributes']
		);
		
		return '<thead>' . $headerContent . '</thead>';
	}
	
	/**
	 * Build standard header (no merge columns)
	 * 
	 * @param array $config Header configuration
	 * @return string HTML for standard header
	 */
	private function buildStandardHeader($config) {
		$headerTable = '<thead><tr>';
		
		foreach ($config['columnsList'] as $column) {
			$headerTable .= $this->renderStandardColumnHeader($column, $config);
		}
		
		return $headerTable . '</tr></thead>';
	}
	
	/**
	 * Render a single standard column header
	 * 
	 * @param string $column Column name
	 * @param array $config Header configuration
	 * @return string HTML for column header
	 */
	private function renderStandardColumnHeader($column, $config) {
		$headerLabel = htmlspecialchars(ucwords(str_replace('_', ' ', $column)), ENT_QUOTES, 'UTF-8');
		$columnLower = strtolower($column);
		
		// Build column ID
		$id = '';
		if (!empty($config['dataColumns'])) {
			$columnKey = $config['dataColumns'][$column] ?? $column;
			$id = $this->setAttributes(['id' => canvastack_decrypt(canvastack_encrypt($columnKey))]);
		} else {
			$id = $this->setAttributes(['id' => canvastack_decrypt(canvastack_encrypt($column))]);
		}
		
		// Special columns
		if (in_array($columnLower, ['no', 'id', 'nik'])) {
			return "<th width=\"50\"{$config['headerColor']}>{$headerLabel}</th>";
		}
		
		if ('number_lists' === $columnLower) {
			return '<th width="30"' . $config['headerColor'] . '>No</th>' .
			       '<th width="30"' . $config['headerColor'] . '>ID</th>';
		}
		
		// Standard column
		$class = $this->buildStandardColumnClass($column, $config);
		$width = $this->getColumnWidthFromConfig($column, $config['widthColumn']);
		$colorStyle = $this->getColumnColorStyle($column, $config['columnColor']);
		
		return "<th{$id}{$class}{$config['headerColor']}{$colorStyle}{$width}>{$headerLabel}</th>";
	}
	
	/**
	 * Build class attribute for standard column header
	 * 
	 * @param string $column Column name
	 * @param array $config Header configuration
	 * @return string Class attribute string
	 */
	private function buildStandardColumnClass($column, $config) {
		$classAttributes = '';
		
		if (in_array($column, $config['hiddenColumn'])) {
			$classAttributes .= ' canvastack-hide-column';
		}
		
		if (!empty($config['alignColumn']['header'][$column])) {
			$classAttributes .= $config['alignColumn']['header'][$column];
		}
		
		if ('action' === strtolower($column)) {
			$classAttributes .= ' canvastack-column-action';
		}
		
		if (!empty($classAttributes)) {
			return $this->setAttributes(['class' => $classAttributes]);
		}
		
		return '';
	}
	
	/**
	 * Get column width from configuration
	 * 
	 * @param string $column Column name
	 * @param array $widthColumn Width configuration
	 * @return string Width attribute string
	 */
	private function getColumnWidthFromConfig($column, $widthColumn) {
		$columnLower = strtolower($column);
		
		if (!empty($widthColumn[$columnLower])) {
			return ' width="' . $widthColumn[$columnLower] . '"';
		}
		
		return '';
	}
	
	/**
	 * Build merged column headers for complex table layouts
	 * Refactored to reduce nesting from 6 to 2 levels
	 * 
	 * @param array $mergeColumn Merge configuration
	 * @param array $columns Column list
	 * @param array $attributes Table attributes
	 * @return string HTML for merged headers
	 */
	private function mergeColumns($mergeColumn = [], $columns = [], $attributes = []) {
		if (empty($mergeColumn)) {
			return '';
		}
		
		$columns = $this->checkColumnLabel($this->labels, $columns);
		$dataColumns = $this->columnManipulated;
		
		[$columnColor, $headerColor] = $this->extractColorSettings($attributes);
		
		$mergedTable = $this->buildMergedTableRow($columns, $mergeColumn, $dataColumns, $columnColor, $headerColor, $attributes);
		$headerTable = $this->buildHeaderTableRow($columns, $dataColumns, $columnColor, $headerColor, $attributes);
		
		return $headerTable . $mergedTable;
	}
	
	/**
	 * Extract color settings from attributes
	 * 
	 * @param array $attributes Table attributes
	 * @return array [columnColor, headerColor]
	 */
	private function extractColorSettings($attributes) {
		$columnColor = [];
		$headerColor = null;
		
		if (!empty($attributes['attributes']['bg_color'])) {
			$tableColor = $this->backgroundColor($attributes['attributes']['bg_color']);
			$columnColor = $tableColor['columns'] ?? [];
			$headerColor = $tableColor['header'] ?? null;
		}
		
		return [$columnColor, $headerColor];
	}
	
	/**
	 * Build the merged table row (bottom row with actual column headers)
	 * 
	 * @param array $columns Column list (modified by reference)
	 * @param array $mergeColumn Merge configuration
	 * @param array $dataColumns Column manipulation data
	 * @param array $columnColor Column color settings
	 * @param string|null $headerColor Header color style
	 * @param array $attributes Table attributes
	 * @return string HTML for merged row
	 */
	private function buildMergedTableRow(&$columns, $mergeColumn, $dataColumns, $columnColor, $headerColor, $attributes) {
		$mergedTable = '<tr>';
		$setMergeText = '::merge::';
		
		foreach ($columns as $index => $column) {
			$matchedMerge = $this->findMatchingMergeColumn($column, $mergeColumn);
			
			if ($matchedMerge) {
				$mergedTable .= $this->renderMergedColumnHeader(
					$column,
					$matchedMerge,
					$dataColumns,
					$columnColor,
					$headerColor,
					$attributes
				);
				
				unset($columns[$index]);
				$columns[$index] = $matchedMerge['label'] . $setMergeText . $matchedMerge['counts'];
			}
		}
		
		return $mergedTable . '</tr>';
	}
	
	/**
	 * Find matching merge column configuration for a given column
	 * 
	 * @param string $column Column name to match
	 * @param array $mergeColumn Merge configuration
	 * @return array|null Matched merge data or null
	 */
	private function findMatchingMergeColumn($column, $mergeColumn) {
		foreach ($mergeColumn as $mergeLabel => $mergeData) {
			if (in_array($column, $mergeData['columns'])) {
				return [
					'label' => $mergeLabel,
					'counts' => $mergeData['counts'],
					'data' => $mergeData
				];
			}
		}
		return null;
	}
	
	/**
	 * Render a single merged column header with all attributes
	 * 
	 * @param string $column Column name
	 * @param array $mergeData Merge configuration data
	 * @param array $dataColumns Column manipulation data
	 * @param array $columnColor Column color settings
	 * @param string|null $headerColor Header color style
	 * @param array $attributes Table attributes
	 * @return string HTML for column header
	 */
	private function renderMergedColumnHeader($column, $mergeData, $dataColumns, $columnColor, $headerColor, $attributes) {
		$headerLabel = htmlspecialchars(ucwords(str_replace('_', ' ', $column)), ENT_QUOTES, 'UTF-8');
		$id = $this->buildColumnId($column, $dataColumns);
		$columnClass = $this->buildMergedColumnClass($column, $attributes);
		$colorStyle = $this->getColumnColorStyle($column, $columnColor);
		
		return "<th{$id}{$columnClass}{$headerColor}{$colorStyle}>{$headerLabel}</th>";
	}
	
	/**
	 * Build column ID attribute
	 * 
	 * @param string $column Column name
	 * @param array $dataColumns Column manipulation data
	 * @return string ID attribute string
	 */
	private function buildColumnId($column, $dataColumns) {
		if (!empty($dataColumns) && isset($dataColumns[$column])) {
			return $this->setAttributes(['id' => canvastack_decrypt(canvastack_encrypt($dataColumns[$column]))]);
		}
		return '';
	}
	
	/**
	 * Build column class attribute for merged columns
	 * 
	 * @param string $column Column name
	 * @param array $attributes Table attributes
	 * @return string Class attribute string
	 */
	private function buildMergedColumnClass($column, $attributes) {
		if (!empty($attributes['attributes']['column']['class'][$column])) {
			return $this->setAttributes(['class' => $attributes['attributes']['column']['class'][$column]]);
		}
		return '';
	}
	
	/**
	 * Get column color style attribute
	 * 
	 * @param string $column Column name
	 * @param array $columnColor Column color settings
	 * @return string Color style attribute
	 */
	private function getColumnColorStyle($column, $columnColor) {
		return !empty($columnColor[$column]) ? $columnColor[$column] : '';
	}
	
	/**
	 * Build the header table row (top row with merge labels and rowspan columns)
	 * 
	 * @param array $columns Column list (already modified with merge markers)
	 * @param array $dataColumns Column manipulation data
	 * @param array $columnColor Column color settings
	 * @param string|null $headerColor Header color style
	 * @param array $attributes Table attributes
	 * @return string HTML for header row
	 */
	private function buildHeaderTableRow($columns, $dataColumns, $columnColor, $headerColor, $attributes) {
		$columns = array_unique($columns);
		ksort($columns);
		
		$headerTable = '<tr>';
		$setMergeText = '::merge::';
		
		foreach ($columns as $index => $column) {
			if (str_contains($column, $setMergeText)) {
				$headerTable .= $this->renderMergeLabel($column, $headerColor);
			} else {
				$headerTable .= $this->renderRowspanColumn($column, $dataColumns, $columnColor, $headerColor, $attributes);
			}
		}
		
		return $headerTable . '</tr>';
	}
	
	/**
	 * Render merge label header (colspan header)
	 * 
	 * @param string $column Column with merge marker
	 * @param string|null $headerColor Header color style
	 * @return string HTML for merge label
	 */
	private function renderMergeLabel($column, $headerColor) {
		$setMergeText = '::merge::';
		$merge_label = explode($setMergeText, $column);
		$colspan = intval($merge_label[1]);
		$headerLabel = htmlspecialchars(ucwords(str_replace('_', ' ', $merge_label[0])), ENT_QUOTES, 'UTF-8');
		
		return "<th class=\"merge-column\" colspan=\"{$colspan}\"{$headerColor}>{$headerLabel}</th>";
	}
	
	/**
	 * Render rowspan column header (columns that span both rows)
	 * 
	 * @param string $column Column name
	 * @param array $dataColumns Column manipulation data
	 * @param array $columnColor Column color settings
	 * @param string|null $headerColor Header color style
	 * @param array $attributes Table attributes
	 * @return string HTML for rowspan column
	 */
	private function renderRowspanColumn($column, $dataColumns, $columnColor, $headerColor, $attributes) {
		$headerLabel = htmlspecialchars(ucwords(str_replace('_', ' ', $column)), ENT_QUOTES, 'UTF-8');
		$id = $this->buildColumnId($column, $dataColumns);
		
		$columnLower = strtolower($column);
		
		// Special columns: no, id, nik
		if (in_array($columnLower, ['no', 'id', 'nik'])) {
			return "<th rowspan=\"2\" width=\"50\"{$headerColor}>{$headerLabel}</th>";
		}
		
		// Special column: number_lists
		if ('number_lists' === $columnLower) {
			return "<th rowspan=\"2\" width=\"30\"{$headerColor}>No</th><th rowspan=\"2\" width=\"30\"{$headerColor}>ID</th>";
		}
		
		// Standard columns
		return $this->renderStandardRowspanColumn($column, $id, $headerLabel, $columnColor, $headerColor, $attributes);
	}
	
	/**
	 * Render standard rowspan column with all attributes
	 * 
	 * @param string $column Column name
	 * @param string $id ID attribute
	 * @param string $headerLabel Escaped header label
	 * @param array $columnColor Column color settings
	 * @param string|null $headerColor Header color style
	 * @param array $attributes Table attributes
	 * @return string HTML for standard rowspan column
	 */
	private function renderStandardRowspanColumn($column, $id, $headerLabel, $columnColor, $headerColor, $attributes) {
		$columnClass = $this->buildRowspanColumnClass($column, $attributes);
		$width = $this->getColumnWidth($column, $attributes);
		$colorStyle = $this->getColumnColorStyle($column, $columnColor);
		
		return "<th rowspan=\"2\"{$id}{$columnClass}{$headerColor}{$colorStyle}{$width}>{$headerLabel}</th>";
	}
	
	/**
	 * Build column class for rowspan columns
	 * 
	 * @param string $column Column name
	 * @param array $attributes Table attributes
	 * @return string Class attribute string
	 */
	private function buildRowspanColumnClass($column, $attributes) {
		$classAttributes = '';
		
		if (!empty($attributes['attributes']['column']['class'][$column])) {
			$classAttributes .= $attributes['attributes']['column']['class'][$column];
		}
		
		if ('action' === strtolower($column)) {
			$classAttributes .= ' canvastack-column-action';
		}
		
		if (!empty($classAttributes)) {
			return $this->setAttributes(['class' => $classAttributes]);
		}
		
		return '';
	}
	
	/**
	 * Get column width attribute
	 * 
	 * @param string $column Column name
	 * @param array $attributes Table attributes
	 * @return string Width attribute string
	 */
	private function getColumnWidth($column, $attributes) {
		$columnLower = strtolower($column);
		
		if (!empty($attributes['attributes']['column_width'][$columnLower])) {
			return ' width="' . $attributes['attributes']['column_width'][$columnLower] . '"';
		}
		
		return '';
	}
	
	private function setColumnElements($name, $column_data, $columns) {
		$element = [];
		if (!empty($column_data[$name])) {
			if (!empty($column_data[$name]['all::columns'])) {
				if (true === $column_data[$name]['all::columns']) {
					if (!empty($columns['columns']['lists'])) {
						foreach ($columns['columns']['lists'] as $clickList) {
							$element[$clickList] = true;
						}
					}
				}
			} else {
				foreach ($column_data[$name] as $clicKey) {
					$element[$clicKey] = true;
				}
			}
		}
		
		return $element;
	}
	
	private function setFormulaColumns($columns, $data) {
		return canvastack_set_formula_columns($columns, $data['attributes']['conditions']['formula']);
	}
	
	public $filter_contents  = [];
	protected $filter_object = [];
	/**
	 * Build table body configuration and DataTables script
	 * Refactored to reduce nesting from 5 to 2 levels
	 * 
	 * @param array $data Table data configuration
	 * @return string DataTables JavaScript
	 */
	private function body($data = []) {
		$config = $this->prepareBodyConfig($data);
		$columns = $this->prepareBodyColumns($config);
		
		// Build DataTables column configuration
		$dt_columns = $this->buildDataTableColumns($columns, $config);
		$dt_info = $this->buildDataTableInfo($data, $dt_columns, $config);
		
		// Add filter configuration if searchable
		if ($config['hasSearchable']) {
			$dt_info = $this->addFilterConfiguration($dt_info, $data, $config['tableID']);
		}
		
		$this->filter_contents[$config['tableID']] = $dt_info;
		
		return $this->renderDataTable($config['tableID'], $dt_columns, $dt_info, $config['hasSearchable']);
	}
	
	/**
	 * Prepare body configuration from data
	 * 
	 * @param array $data Table data
	 * @return array Configuration array
	 */
	private function prepareBodyConfig($data) {
		$attributes = $data['attributes'];
		$columnData = $data['columns'];
		
		return [
			'name' => $data['name'],
			'attributes' => $attributes,
			'columnData' => $columnData,
			'server_side' => $attributes['server_side']['status'],
			'tableID' => $attributes['attributes']['table_id'] ?? 'datatable',
			'actions' => $attributes['actions'] ?? false,
			'numbering' => $attributes['numbering'] ?? false,
			'hiddenColumn' => $columnData['hidden_columns'] ?? [],
			'hasSearchable' => !empty($columnData['searchable'])
		];
	}
	
	/**
	 * Prepare columns list with special columns
	 * 
	 * @param array $config Body configuration
	 * @return array Column list
	 */
	private function prepareBodyColumns($config) {
		$columns = $config['columnData']['lists'];
		
		if (true === $config['numbering']) {
			$columns = array_merge(['number_lists'], $columns);
		}
		
		if (!empty($config['actions'])) {
			$columns[] = 'action';
		}
		
		return $columns;
	}
	
	/**
	 * Build DataTables column configuration array
	 * 
	 * @param array $columns Column list
	 * @param array $config Body configuration
	 * @return array DataTables columns configuration
	 */
	private function buildDataTableColumns($columns, $config) {
		$alignment = $this->extractBodyAlignment($config['columnData']);
		$sortable = $this->setColumnElements('sortable', $config['columnData'], ['columns' => $config['columnData']]);
		$searchable = $this->setColumnElements('searchable', $config['columnData'], ['columns' => $config['columnData']]);
		$clickable = $this->setColumnElements('clickable', $config['columnData'], ['columns' => $config['columnData']]);
		
		$column_id = $this->prepareColumnId($config['server_side'], $columns);
		$formula_fields = $this->extractFormulaFields($config);
		
		$dt_columns = [];
		
		foreach ($columns as $column) {
			$columnConfig = $this->buildSingleColumnConfig(
				$column,
				$config,
				$alignment,
				$sortable,
				$searchable,
				$clickable,
				$formula_fields,
				$column_id
			);
			
			if (!empty($columnConfig)) {
				$dt_columns = array_merge($dt_columns, $columnConfig);
			}
		}
		
		return $dt_columns;
	}
	
	/**
	 * Extract body alignment configuration
	 * 
	 * @param array $columnData Column data
	 * @return array Alignment configuration
	 */
	private function extractBodyAlignment($columnData) {
		$alignment = [];
		
		if (empty($columnData['align'])) {
			return $alignment;
		}
		
		foreach ($columnData['align'] as $align => $col_data) {
			if ($col_data['body'] !== true) {
				continue;
			}
			
			foreach ($col_data['columns'] as $field) {
				$alignment['body'][$field] = $align;
			}
		}
		
		return $alignment;
	}
	
	/**
	 * Prepare column ID configuration for server-side processing
	 * 
	 * @param bool $server_side Server-side status
	 * @param array $columns Column list
	 * @return array Column ID configuration
	 */
	private function prepareColumnId($server_side, $columns) {
		if (false === $server_side) {
			return [];
		}
		
		$firstField = 'id';
		if (!in_array('id', $columns)) {
			$firstField = $columns[1] ?? 'id';
		}
		
		return [
			'data' => $firstField,
			'name' => $firstField
		];
	}
	
	/**
	 * Extract formula fields from configuration
	 * 
	 * @param array $config Body configuration
	 * @return array Formula fields
	 */
	private function extractFormulaFields($config) {
		$formula_fields = [];
		
		if (empty($config['attributes']['conditions']['formula'])) {
			return $formula_fields;
		}
		
		foreach ($config['attributes']['conditions']['formula'] as $formula) {
			$formula_fields[$formula['name']] = $formula['name'];
		}
		
		return $formula_fields;
	}
	
	/**
	 * Build configuration for a single column
	 * 
	 * @param string $column Column name
	 * @param array $config Body configuration
	 * @param array $alignment Alignment settings
	 * @param array $sortable Sortable settings
	 * @param array $searchable Searchable settings
	 * @param array $clickable Clickable settings
	 * @param array $formula_fields Formula fields
	 * @param array $column_id Column ID config
	 * @return array Column configuration(s)
	 */
	private function buildSingleColumnConfig($column, $config, $alignment, $sortable, $searchable, $clickable, $formula_fields, $column_id) {
		$jsonData = [
			'data' => $column,
			'name' => $column,
			'sortable' => false,
			'searchable' => false,
			'class' => 'auto-cut-text',
			'onclick' => 'return false'
		];
		
		// Apply hidden column class
		if (in_array($column, $config['hiddenColumn'])) {
			$jsonData['class'] = 'auto-cut-text canvastack-hide-column';
		}
		
		// Handle special column types
		if ('number_lists' === $column) {
			return $this->buildNumberListsColumn($column_id);
		}
		
		if (isset($formula_fields[$column])) {
			return [$this->buildFormulaColumn($column, $jsonData, $alignment, $clickable)];
		}
		
		return [$this->buildStandardBodyColumn($column, $jsonData, $alignment, $sortable, $searchable, $clickable)];
	}
	
	/**
	 * Build number lists column configuration
	 * 
	 * @param array $column_id Column ID config
	 * @return array Column configurations
	 */
	private function buildNumberListsColumn($column_id) {
		$numberColumn = [
			'data' => 'DT_RowIndex',
			'name' => 'DT_RowIndex',
			'sortable' => false,
			'searchable' => false,
			'class' => 'center un-clickable',
			'onclick' => 'return false'
		];
		
		$result = [$numberColumn];
		
		if (!empty($column_id)) {
			$result[] = $column_id;
		}
		
		return $result;
	}
	
	/**
	 * Build formula column configuration
	 * 
	 * @param string $column Column name
	 * @param array $jsonData Base column data
	 * @param array $alignment Alignment settings
	 * @param array $clickable Clickable settings
	 * @return array Column configuration
	 */
	private function buildFormulaColumn($column, $jsonData, $alignment, $clickable) {
		if (!empty($alignment['body'][$column])) {
			$jsonData['class'] .= " {$alignment['body'][$column]}";
		}
		
		if (!empty($clickable[$column])) {
			unset($jsonData['onclick']);
			$jsonData['class'] .= " clickable";
		}
		
		return $jsonData;
	}
	
	/**
	 * Build standard body column configuration
	 * 
	 * @param string $column Column name
	 * @param array $jsonData Base column data
	 * @param array $alignment Alignment settings
	 * @param array $sortable Sortable settings
	 * @param array $searchable Searchable settings
	 * @param array $clickable Clickable settings
	 * @return array Column configuration
	 */
	private function buildStandardBodyColumn($column, $jsonData, $alignment, $sortable, $searchable, $clickable) {
		if (!empty($alignment['body'][$column])) {
			$jsonData['class'] .= " {$alignment['body'][$column]}";
		}
		
		if (!empty($sortable[$column])) {
			$jsonData['sortable'] = $sortable[$column];
		}
		
		if (!empty($searchable[$column])) {
			$jsonData['searchable'] = $searchable[$column];
		}
		
		if (!empty($clickable[$column])) {
			unset($jsonData['onclick']);
			$jsonData['class'] .= " clickable";
		}
		
		return $jsonData;
	}
	
	/**
	 * Build DataTables info configuration
	 * 
	 * @param array $data Table data
	 * @param array $dt_columns DataTables columns
	 * @param array $config Body configuration
	 * @return array DataTables info
	 */
	private function buildDataTableInfo($data, $dt_columns, $config) {
		$new_data_columns = [];
		foreach ($dt_columns as $dtcols) {
			$new_data_columns[] = ($dtcols['name'] === 'DT_RowIndex') ? 'number_lists' : $dtcols['name'];
		}
		
		$dt_info = [
			'searchable' => [],
			'name' => $config['name']
		];
		
		if (!empty($data['columns']['sortable'])) {
			$dt_info['sortable'] = $data['columns']['sortable'];
		}
		
		if (!empty($data['attributes']['conditions'])) {
			$dt_info['conditions'] = $data['attributes']['conditions'];
			$dt_info['columns'] = $new_data_columns;
		}
		
		if (!empty($data['attributes']['on_load']['display_limit_rows'])) {
			$dt_info['onload_limit_rows'] = $data['attributes']['on_load']['display_limit_rows'];
		}
		
		if (!empty($data['attributes']['fixed_columns'])) {
			$dt_info['fixed_columns'] = $data['attributes']['fixed_columns'];
		}
		
		return $dt_info;
	}
	
	/**
	 * Add filter configuration to DataTables info
	 * 
	 * @param array $dt_info DataTables info
	 * @param array $data Table data
	 * @param string $tableID Table ID
	 * @return array Updated DataTables info
	 */
	private function addFilterConfiguration($dt_info, $data, $tableID) {
		$dt_info['searchable'] = $data['columns']['searchable'];
		
		if (empty($data['columns']['filters'])) {
			return $dt_info;
		}
		
		$search_data = $this->buildSearchData($data);
		[$data_model, $data_sql] = $this->extractModelOrSql($data);
		$filterQuery = $this->conditions['where'] ?? [];
		
		$searchInfoAttribute = "{$tableID}_CanvaStackFILTER";
		$search_object = new Search(
			$searchInfoAttribute,
			$data_model,
			$search_data,
			$data_sql,
			$this->connection,
			$filterQuery
		);
		
		$this->filter_object = $search_object;
		
		return $this->addFilterInfo($dt_info, $tableID, $searchInfoAttribute, $search_object, $data);
	}
	
	/**
	 * Build search data configuration
	 * 
	 * @param array $data Table data
	 * @return array Search data configuration
	 */
	private function buildSearchData($data) {
		$search_data = [
			'table_name' => $data['name'],
			'searchable' => $data['columns']['searchable'],
			'columns' => $data['columns']['filters'],
			'relations' => $data['columns']['relations'] ?? [],
			'foreign_keys' => $data['columns']['foreign_keys'] ?? []
		];
		
		if (!empty($data['columns']['filter_groups'])) {
			$search_data['filter_groups'] = $data['columns']['filter_groups'];
		}
		
		if (!empty($data['attributes']['filter_model'])) {
			$search_data['filter_model'] = $data['attributes']['filter_model'];
		}
		
		return $search_data;
	}
	
	/**
	 * Extract model or SQL from data
	 * 
	 * @param array $data Table data
	 * @return array [model, sql]
	 */
	private function extractModelOrSql($data) {
		if (!empty($data['sql'])) {
			return [null, $data['sql']];
		}
		return [$data['model'], null];
	}
	
	/**
	 * Add filter info to DataTables configuration
	 * 
	 * @param array $dt_info DataTables info
	 * @param string $tableID Table ID
	 * @param string $searchInfoAttribute Search attribute ID
	 * @param object $search_object Search object instance
	 * @param array $data Table data
	 * @return array Updated DataTables info
	 */
	private function addFilterInfo($dt_info, $tableID, $searchInfoAttribute, $search_object, $data) {
		$dt_info['id'] = $tableID;
		$dt_info['class'] = 'dt-button buttons-filter';
		$dt_info['attributes'] = [
			'id' => $searchInfoAttribute,
			'class' => "modal fade {$tableID}",
			'role' => 'dialog',
			'tabindex' => '-1',
			'aria-hidden' => 'true',
			'aria-controls' => $tableID,
			'aria-labelledby' => $tableID,
			'data-backdrop' => 'static',
			'data-keyboard' => 'true'
		];
		$dt_info['button_label'] = '<i class="fa fa-filter"></i> Filter';
		$dt_info['action_button_removed'] = $data['attributes']['buttons_removed'];
		$dt_info['modal_title'] = '<i class="fa fa-filter"></i> &nbsp; Filter';
		$dt_info['modal_content'] = $search_object->render($searchInfoAttribute, $dt_info['name'], $data['columns']['filters']);
		
		return $dt_info;
	}
	
	/**
	 * Render DataTables script
	 * 
	 * @param string $tableID Table ID
	 * @param array $dt_columns DataTables columns
	 * @param array $dt_info DataTables info
	 * @param bool $hasSearchable Has searchable columns
	 * @return string DataTables JavaScript
	 */
	private function renderDataTable($tableID, $dt_columns, $dt_info, $hasSearchable) {
		$filter_data = [];
		if (true === $hasSearchable) {
			$filter_data = $this->getFilterDataTables();
		}
		
		$dt_columns = canvastack_clear_json(json_encode($dt_columns));
		
		if ('GET' === $this->method) {
			return $this->datatables($tableID, $dt_columns, $dt_info, true, $filter_data);
		}
		
		// POST method (currently same as GET)
		return $this->datatables($tableID, $dt_columns, $dt_info, true, $filter_data);
	}
	
	private function getFilterDataTables() {
		$filter_strings = null;
		// SECURITY: Use Laravel request()
		$request = request();
		if (!$request->has('filters')) {
			return $filter_strings;
		}
		
		$input_filters = [];
		$_ajax_url     = 'renderDataTables';
		foreach ($request->query() as $name => $value) {
			if ('filters'!== $name && '' !== $value) {
				if (!is_array($value)) {
					if (
						$name !== $_ajax_url &&
						$name !== 'draw'     &&
						$name !== 'columns'  &&
						$name !== 'order'    &&
						$name !== 'start'    &&
						$name !== 'length'   &&
						$name !== 'search'   &&
						$name !== '_token'   &&
						$name !== '_'
					) {
						// SECURITY: URL encode
						$safeName  = urlencode($name);
						$safeValue = urlencode($value);
						$input_filters[] = "infil[{$safeName}]={$safeValue}";
					}
				}
			}
		}
		
		if (!empty($input_filters)) {
			$filter_strings = '&filters=true&' . implode('&', $input_filters);
		}
		
		return $filter_strings;
	}
	
	private function backgroundColor($attributes = []) {
		if (!empty($attributes)) {
			$tableDataColor = [];
			foreach ($attributes as $colorCode => $dataColor) {
				if (!empty($dataColor['text'])) $textColor = " color:{$dataColor['text']};";
				if (!empty($dataColor['columns'])) {
					foreach ($dataColor['columns'] as $columnName) {
						$tableDataColor['columns'][$columnName] = $this->setAttributes(['style' => "background-color:{$colorCode} !important;{$textColor}"]);
					}
				}
				
				if (empty($dataColor['columns'])) {
					if (true === $dataColor['header']) $tableDataColor['header'] = $this->setAttributes(['style' => "background-color:{$colorCode} !important;{$textColor}"]);
				}
			}
			
			return $tableDataColor;
		}
	}
	
	private function setAttributes($attributes = []) {
		$textAttribute = null;
		if (is_array($attributes)) {
			foreach ($attributes as $key => $value) {
				// SECURITY: Escape attribute values
				$safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
				$textAttribute .= " {$key}=\"{$safeValue}\"";
			}
		}
		
		return $textAttribute;
	}
}