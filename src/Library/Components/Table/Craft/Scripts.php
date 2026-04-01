<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

/**
 * Created on 22 May 2021
 * Time Created : 00:29:19
 *
 * @filesource Scripts.php
 *
 * @author    wisnuwidi@canvastack.com - 2021
 * @copyright wisnuwidi
 * @email     wisnuwidi@canvastack.com
 */
 
trait Scripts {
	
	private const MAX_ROWS_LIMIT = 999999999; // Safe limit untuk 32-bit dan 64-bit systems
	private const DEFAULT_ROWS_LIMIT = 10;
	private const HASH_CALCULATION_DIVISOR = 80; // 8*800/80 = 80
	
	private $datatablesMode = 'GET';
	private $strictGetUrls  = true;
	private $strictColumns  = true;
	
	/**
	 * Javascript Config for Rendering Datatables
	 *
	 * created @Oct 11, 2018
	 * author: wisnuwidi
	 *
	 * @param string $attr_id
	 * @param string $columns
	 * @param array $data_info
	 * @param boolean $server_side
	 * @param boolean|array $filters
	 * @param boolean|string|array $custom_link
	 *
	 * @return string
	 */
	protected function datatables($attr_id, $columns, $data_info = [], $server_side = false, $filters = false, $custom_link = false) {
		// Input validation
		if (empty($attr_id) || empty($columns)) {
			trigger_error('datatables(): attr_id and columns are required', E_USER_WARNING);
			return '';
		}
		
		$varTableID   = $this->sanitizeTableId($attr_id);
		$current_url  = url(canvastack_current_route()->uri);
		
		$buttonset    = $this->buildButtonSet($attr_id);
		$fixedColumn  = $this->buildFixedColumnConfig($data_info);
		$lengthMenu   = $this->buildLengthMenu($data_info);
		
		$defaultConfig = $this->buildDefaultConfig($fixedColumn, $lengthMenu, $buttonset);
		$jsConditional = $this->buildConditionalColumns($varTableID, $data_info);
		
		$js = '<script type="text/javascript">jQuery(function($) {';
		
		if (false !== $server_side) {
			$js .= $this->buildServerSideDataTable(
				$attr_id,
				$varTableID,
				$columns,
				$data_info,
				$current_url,
				$defaultConfig,
				$jsConditional,
				$filters,
				$custom_link
			);
		} else {
			$js .= $this->buildClientSideDataTable($attr_id, $varTableID, $columns, $defaultConfig);
		}
		
		$documentLoad = $this->buildDocumentLoadScript($attr_id, $filters, $data_info, $current_url);
		$js .= '});' . $documentLoad . '</script>';
		
		return $js;
	}

	/**
	 * Sanitize table ID for use in JavaScript variable names
	 *
	 * @param string $attr_id
	 * @return string
	 */
	private function sanitizeTableId($attr_id) {
		$varTableID = explode('-', $attr_id);
		return implode('', $varTableID);
	}
	
	/**
	 * Sanitize string for JavaScript context
	 * Uses addslashes for simple and efficient escaping
	 *
	 * @param string $value
	 * @return string
	 */
	private function sanitizeJsValue($value) {
		return addslashes($value);
	}
	
	/**
	 * Validate and get array value with default
	 *
	 * @param array $array
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function getArrayValue($array, $key, $default = null) {
		return isset($array[$key]) ? $array[$key] : $default;
	}
	
	/**
	 * Build button set configuration
	 *
	 * @param string $attr_id
	 * @return string
	 */
	private function buildButtonSet($attr_id) {
		$buttonConfig = 'exportOptions:{columns:":visible:not(:last-child)"}';
		return $this->setButtons($attr_id, [
			'excel|text:"<i class=\"fa fa-external-link\" aria-hidden=\"true\"></i> <u>E</u>xcel"|key:{key:"e",altKey:true}',
			'csv|'   . $buttonConfig,
			'pdf|'   . $buttonConfig,
			'copy|'  . $buttonConfig,
			'print|' . $buttonConfig
		]);
	}
	
	/**
	 * Build fixed column configuration
	 *
	 * @param array $data_info
	 * @return string
	 */
	private function buildFixedColumnConfig($data_info) {
		if (empty($data_info['fixed_columns'])) {
			return '';
		}
		
		$fixedColumnData = json_encode($data_info['fixed_columns']);
		return 'scrollY:300,scrollX:true,scrollCollapse:true,fixedColumns:' . $fixedColumnData . ',';
	}
	
	/**
	 * Build length menu configuration
	 *
	 * @param array $data_info
	 * @return string
	 */
	private function buildLengthMenu($data_info) {
		$allLimitRows    = self::MAX_ROWS_LIMIT;
		$limitRowsData   = [10, 25, 50, 100, 250, 500, 1000, $allLimitRows];
		$onloadRowsLimit = [self::DEFAULT_ROWS_LIMIT];
		
		if (!empty($data_info['onload_limit_rows'])) {
			if (is_string($data_info['onload_limit_rows'])) {
				if (in_array(strtolower($data_info['onload_limit_rows']), ['*', 'all'])) {
					unset($limitRowsData[array_search(end($limitRowsData), $limitRowsData)]);
					$onloadRowsLimit = [$allLimitRows];
				}
			} else {
				unset($limitRowsData[array_search($data_info['onload_limit_rows'], $limitRowsData)]);
				$onloadRowsLimit = [intval($data_info['onload_limit_rows'])];
			}
			
			$limitRowsData = array_merge_recursive($onloadRowsLimit, $limitRowsData);
		}
		
		$limitRowsDataString = [];
		foreach ($limitRowsData as $row => $limit) {
			$limitRowsDataString[$row] = ($allLimitRows === $limit) 
				? "Show All" 
				: (string) $limit . ' Rows';
		}
		
		$lengthMenu = json_encode([$limitRowsData, $limitRowsDataString]);
		return "lengthMenu :{$lengthMenu}, ";
	}
	
	/**
	 * Build default DataTable configuration
	 *
	 * @param string $fixedColumn
	 * @param string $lengthMenu
	 * @param string $buttonset
	 * @return string
	 */
	private function buildDefaultConfig($fixedColumn, $lengthMenu, $buttonset) {
		$config = [
			$fixedColumn,
			'"searching"    :true,',
			'"processing"   :true,',
			'"retrieve"     :false,',
			'"paginate"     :true,',
			'"searchDelay"  :1000,',
			'"bDeferRender" :true,',
			'"responsive"   :false,',
			'"autoWidth"    :false,',
			'"dom"          :"lBfrtip",',
			$lengthMenu,
			'"buttons"  :' . $buttonset . ','
		];
		
		return implode('', $config);
	}
	
	/**
	 * Build conditional columns JavaScript
	 *
	 * @param string $varTableID
	 * @param array $data_info
	 * @return string|null
	 */
	private function buildConditionalColumns($varTableID, $data_info) {
		if (empty($data_info['conditions']['columns'])) {
			return null;
		}
		
		return $this->conditionalColumns(
			"CanvaStack_{$varTableID}_dt", 
			$data_info['conditions']['columns'], 
			$data_info['columns']
		);
	}

	/**
	 * Build server-side DataTable configuration
	 *
	 * @param string $attr_id
	 * @param string $varTableID
	 * @param string $columns
	 * @param array $data_info
	 * @param string $current_url
	 * @param string $defaultConfig
	 * @param string|null $jsConditional
	 * @param mixed $filters
	 * @param mixed $custom_link
	 * @return string
	 */
	private function buildServerSideDataTable($attr_id, $varTableID, $columns, $data_info, $current_url, $defaultConfig, $jsConditional, $filters, $custom_link) {
		// Validate required data
		$dataName = $data_info['name'] ?? 'unknown';
		
		// Build URL dengan proper encoding
		$diftaParams = http_build_query([
			'difta' => [
				'name' => $dataName,
				'source' => 'dynamics'
			]
		]);
		$link_url = "renderDataTables=true&{$diftaParams}";
		
		if (false !== $custom_link) {
			if (is_array($custom_link) && count($custom_link) >= 2) {
				$link_url = urlencode($custom_link[0]) . "=" . urlencode($custom_link[1]);
			} elseif (is_string($custom_link)) {
				$link_url = urlencode($custom_link) . "=true";
			}
		}
		
		$scriptURI    = "{$current_url}?{$link_url}";
		$colDefs      = ",columnDefs:[{target:[1],visible:false,searchable:false,className:'control hidden-column'}";
		$orderColumn  = ",order:[[1,'desc']]{$colDefs}]";
		$columns      = ",columns:{$columns}{$orderColumn}";
		
		$url_path     = url(canvastack_current_route()->uri);
		$hash         = hash_code_id();
		$hashDivisor  = self::HASH_CALCULATION_DIVISOR;
		
		// Escape untuk keamanan JavaScript
		$safeHash = addslashes($hash);
		$safeUrlPath = addslashes($url_path);
		
		// Format asli: parseInt(string - number) akan auto-convert string ke number dulu
		$clickAction  = ".on('click','td.clickable', function(){ var getRLP = $(this).parent('tr').attr('rlp'); if(getRLP != false) { var _rlp = parseInt(getRLP.replace('{$safeHash}','')-{$hashDivisor}); window.location='{$safeUrlPath}/'+_rlp+'/edit'; } });";
		
		$initComplete = ',' . $this->initComplete($attr_id, false);
		$responsive   = "rowReorder :{selector:'td:nth-child(2)'},responsive: false,";
		
		$ajax = $this->buildAjaxConfig($attr_id, $scriptURI, $filters);
		
		// Build filter button append (harus tanpa semicolon agar chaining bekerja)
		$filterButton = '';
		if (false !== $filters) {
			$filterButton = "$('div#{$attr_id}_wrapper>.dt-buttons').append('<span class=\"CanvaStack_{$attr_id}_canvastack-dt-filter-box\"></span>')";
		}
		
		$js = "CanvaStack_{$varTableID}_dt = $('#{$attr_id}').DataTable({ {$responsive} {$defaultConfig} 'serverSide':true,{$ajax}{$columns}{$initComplete}{$jsConditional} }){$clickAction}{$filterButton}";
		
		return $js;
	}
	
	/**
	 * Build AJAX configuration for DataTables
	 *
	 * @param string $attr_id
	 * @param string $scriptURI
	 * @param boolean|array|null $filters
	 * @return string
	 */
	private function buildAjaxConfig($attr_id, $scriptURI, $filters) {
		if (!empty($this->method)) {
			$this->datatablesMode = $this->method;
		}
		
		// Normalize filters
		$filterString = '';
		if (false !== $filters && null !== $filters) {
			if (is_array($filters) && !empty($filters)) {
				$filterString = '&' . http_build_query(['filters' => $filters]);
			}
		}
		
		if ('POST' === $this->datatablesMode) {
			$token = csrf_token();
			$safeScriptURI = addslashes($scriptURI);
			return "ajax:{url:'{$safeScriptURI}{$filterString}',type:'POST',headers:{'X-CSRF-TOKEN': '{$token}'} }";
		}
		
		// GET mode with URL optimization
		$idString = str_replace('-', '', $attr_id);
		$ajaxLimitGetURLs = '';
		
		if (true === $this->strictGetUrls) {
			$strictColumns = $this->strictColumns ? 'true' : 'false';
			$ajaxLimitGetURLs = ",data: function (data) {var canvastackDUDC{$idString} = data; deleteUnnecessaryDatatableComponents(canvastackDUDC{$idString}, {$strictColumns})}";
		}
		
		$safeScriptURI = addslashes($scriptURI);
		return "ajax:{ url:'{$safeScriptURI}{$filterString}'{$ajaxLimitGetURLs} }";
	}
	
	/**
	 * Build client-side DataTable configuration
	 *
	 * @param string $attr_id
	 * @param string $varTableID
	 * @param string $columns
	 * @param string $defaultConfig
	 * @return string
	 */
	private function buildClientSideDataTable($attr_id, $varTableID, $columns, $defaultConfig) {
		return "CanvaStack_{$varTableID}_dt = $('#{$attr_id}').DataTable({ {$defaultConfig}columns:{$columns} });";
	}
	
	/**
	 * Build document load script
	 *
	 * @param string $attr_id
	 * @param boolean|array|null $filters
	 * @param array $data_info
	 * @param string $current_url
	 * @return string
	 */
	private function buildDocumentLoadScript($attr_id, $filters, $data_info, $current_url) {
		$varTableID = $this->sanitizeTableId($attr_id);
		$filterJs   = '';
		
		// PENTING: Check harus dilakukan SEBELUM normalize filters
		if (false !== $filters) {
			// Validate data_info has name
			$dataName = $data_info['name'] ?? 'unknown';
			
			$diftaParams = http_build_query([
				'difta' => [
					'name' => $dataName,
					'source' => 'dynamics'
				]
			]);
			
			// ✅ FIX: Proper URL building - check if query string exists
			$separator = strpos($current_url, '?') !== false ? '&' : '?';
			$scriptURI = "{$current_url}{$separator}renderDataTables=true&{$diftaParams}";
			
			// Normalize filters (dilakukan SETELAH check)
			if (is_array($filters) && empty($filters)) {
				$filters = null;
			}
			
			$filterJs = $this->filter($attr_id, $scriptURI);
			
			$exportParams = http_build_query([
				'exportDataTables' => 'true',
				'difta' => [
					'name' => $dataName,
					'source' => 'dynamics'
				]
			]);
			$exportURI = route('ajax.export') . "?{$exportParams}";
			$connection = !empty($this->connection) ? "::{$this->connection}" : '';
			$filterJs .= '; ' . $this->export($attr_id . $connection, $exportURI);
		}
		
		$jsOrder = '';
		$hiddenColumn = '';
		$fixedColumn  = "$('.dtfc-fixed-left').last().addClass('last-of-scrool-column-table');";
		
		return "$(document).ready(function() { $('#{$attr_id}').wrap('<div class=\"canvastack-wrapper-table\"></div>'); {$filterJs} {$jsOrder} {$hiddenColumn} {$fixedColumn} });";
	}

	/**
	 * Get jQuery selector for contains/not contains match
	 *
	 * @param string $data
	 * @param string|null $match_contained
	 * @return string|null
	 */
	private function getJsContainMatch($data, $match_contained = null) {
		$isNegativeMatch = in_array($match_contained, ['!=', '!=='], true);
		$isPositiveMatch = in_array($match_contained, ['==', '==='], true);
		
		// Escape data untuk keamanan
		$safeData = addslashes($data);
		
		if ($isPositiveMatch) {
			return ":contains(\"{$safeData}\")";
		}
		
		if ($isNegativeMatch) {
			return ":not(:contains(\"{$safeData}\"))";
		}
		
		return null;
	}
	
	/**
	 * Build conditional columns JavaScript
	 *
	 * @param string $tableIdentity
	 * @param array $data
	 * @param array $columns
	 * @return string|null
	 */
	private function conditionalColumns($tableIdentity, $data, $columns) {
		if (empty($data)) {
			return null;
		}
		
		$icols = array_flip($columns);
		$data = $this->mapColumnIndices($data, $icols);
		
		$js = ", 'createdRow': function(row, data, dataIndex, cells) {";
		
		foreach ($data as $condition) {
			if (empty($condition['logic_operator'])) {
				continue;
			}
			
			$js .= $this->buildConditionCheck($condition);
			$js .= $this->applyConditionAction($condition, $tableIdentity);
			$js .= "}";
		}
		
		$js .= "}";
		
		return $js;
	}
	
	/**
	 * Map column names to their indices
	 *
	 * @param array $data
	 * @param array $icols
	 * @return array
	 */
	private function mapColumnIndices($data, $icols) {
		foreach ($data as $idx => $_data) {
			// Validate required keys
			if (!isset($_data['field_name'])) {
				continue;
			}
			
			$data[$idx]['node']['field_name'] = $icols[$_data['field_name']] ?? null;
			
			if (isset($_data['field_target']) && !empty($icols[$_data['field_target']])) {
				$data[$idx]['node']['field_target'] = $icols[$_data['field_target']];
			} else {
				$data[$idx]['node']['field_target'] = null;
			}
		}
		
		return $data;
	}
	
	/**
	 * Build condition check JavaScript
	 *
	 * @param array $condition
	 * @return string
	 */
	private function buildConditionCheck($condition) {
		// Validate condition structure
		if (!isset($condition['field_name']) || !isset($condition['value'])) {
			return '';
		}
		
		$conditionValue = $condition['value'];
		
		if (canvastack_string_contained($condition['value'], '|')) {
			$conditionValue = explode('|', $condition['value']);
		}
		
		// Direct comparison operators
		if (in_array($condition['logic_operator'], ['=', '==', '===', '<', '<=', '>', '>='], true)) {
			// Escape value untuk keamanan
			$safeValue = addslashes($condition['value']);
			return "if (data.{$condition['field_name']} {$condition['logic_operator']} '{$safeValue}') {";
		}
		
		// LIKE / NOT LIKE operators
		$isNot = in_array($condition['logic_operator'], ['NOT LIKE'], true) ? '!' : '';
		$jsConds = [];
		
		if (is_array($conditionValue)) {
			foreach ($conditionValue as $condVal) {
				$safeCondVal = addslashes($condVal);
				$jsConds[] = "{$isNot}~data.{$condition['field_name']}.indexOf('{$safeCondVal}')";
			}
			$jsCond = implode(' && ', $jsConds);
		} else {
			$safeValue = addslashes($conditionValue);
			$jsCond = "{$isNot}~data.{$condition['field_name']}.indexOf('{$safeValue}')";
		}
		
		return "if ({$jsCond}) {";
	}

	/**
	 * Apply condition action to row/cell/column
	 *
	 * @param array $condition
	 * @param string $tableIdentity
	 * @return string
	 */
	private function applyConditionAction($condition, $tableIdentity) {
		$target = $condition['field_target'];
		
		if ('row' === $target) {
			return $this->applyRowAction($condition);
		}
		
		if ('cell' === $target) {
			return $this->applyCellAction($condition, $tableIdentity);
		}
		
		if ('column' === $target) {
			return $this->applyColumnAction($condition);
		}
		
		// Default case: target is specific field
		return $this->applyFieldTargetAction($condition, $tableIdentity);
	}
	
	/**
	 * Apply action to entire row
	 *
	 * @param array $condition
	 * @return string
	 */
	private function applyRowAction($condition) {
		// Validate condition has required keys
		if (!isset($condition['rule']) || !isset($condition['action'])) {
			return '';
		}
		
		$safeRule = addslashes($condition['rule']);
		$safeAction = addslashes($condition['action']);
		return "$(row).children('td').css({'{$safeRule}': '{$safeAction}'});";
	}
	
	/**
	 * Apply action to specific cell
	 *
	 * @param array $condition
	 * @param string $tableIdentity
	 * @return string
	 */
	private function applyCellAction($condition, $tableIdentity) {
		$rule = $condition['rule'] ?? null;
		$fieldName = $condition['node']['field_name'] ?? null;
		
		if (null === $fieldName || null === $rule) {
			return '';
		}
		
		$cellSelector = "$(cells[\"{$fieldName}\"])";
		
		if ('prefix&suffix' === $rule) {
			// Validate action is array with 2 elements
			if (!is_array($condition['action']) || count($condition['action']) < 2) {
				return '';
			}
			$safePrefix = addslashes($condition['action'][0]);
			$safeSuffix = addslashes($condition['action'][1]);
			return "{$cellSelector}.text(\"{$safePrefix}\" + data.{$condition['field_name']} + \"{$safeSuffix}\");";
		}
		
		if ('prefix' === $rule) {
			$safeAction = addslashes($condition['action']);
			return "{$cellSelector}.text(\"{$safeAction}\" + data.{$condition['field_name']});";
		}
		
		if ('suffix' === $rule) {
			$safeAction = addslashes($condition['action']);
			return "{$cellSelector}.text(data.{$condition['field_name']} + \"{$safeAction}\");";
		}
		
		if ('replace' === $rule) {
			return $this->applyReplaceAction($cellSelector, $condition);
		}
		
		// Default CSS rule
		$safeRule = addslashes($condition['rule']);
		$safeAction = addslashes($condition['action']);
		return "{$cellSelector}.css({'{$safeRule}': '{$safeAction}'});";
	}
	
	/**
	 * Apply action to column
	 *
	 * @param array $condition
	 * @return string
	 */
	private function applyColumnAction($condition) {
		$rule = $condition['rule'] ?? null;
		$fieldName = $condition['node']['field_name'] ?? null;
		
		if (null === $fieldName || null === $rule) {
			return '';
		}
		
		$cellSelector = "$(cells[\"{$fieldName}\"])";
		
		if ('prefix&suffix' === $rule) {
			// Validate action is array with 2 elements
			if (!is_array($condition['action']) || count($condition['action']) < 2) {
				return '';
			}
			$safePrefix = addslashes($condition['action'][0]);
			$safeSuffix = addslashes($condition['action'][1]);
			return "{$cellSelector}.text(\"{$safePrefix}\" + data.{$condition['field_name']} + \"{$safeSuffix}\");";
		}
		
		if ('prefix' === $rule) {
			$safeAction = addslashes($condition['action']);
			return "{$cellSelector}.text(\"{$safeAction}\" + data.{$condition['field_name']});";
		}
		
		if ('suffix' === $rule) {
			$safeAction = addslashes($condition['action']);
			return "{$cellSelector}.text(data.{$condition['field_name']} + \"{$safeAction}\");";
		}
		
		if ('replace' === $rule) {
			return $this->applyReplaceAction($cellSelector, $condition);
		}
		
		// Default CSS rule
		$safeRule = addslashes($condition['rule']);
		$safeAction = addslashes($condition['action']);
		return "{$cellSelector}.css({'{$safeRule}': '{$safeAction}'});";
	}

	/**
	 * Apply action to field target (non-row, non-cell, non-column)
	 *
	 * @param array $condition
	 * @param string $tableIdentity
	 * @return string
	 */
	private function applyFieldTargetAction($condition, $tableIdentity) {
		$fieldTarget = $condition['node']['field_target'] ?? null;
		$rule = $condition['rule'] ?? null;
		
		if (null === $fieldTarget || null === $rule) {
			return '';
		}
		
		$cellSelector = "$(cells[\"{$fieldTarget}\"])";
		
		if ('replace' === $rule) {
			$action = $condition['action'] ?? '';
			
			// Handle URL/AJAX button replacement
			if (canvastack_string_contained($action, 'url::') || canvastack_string_contained($action, 'ajax::')) {
				return $this->buildButtonReplacement($tableIdentity, $action, $fieldTarget);
			}
			
			// Handle type conversion
			return $this->applyReplaceAction($cellSelector, $condition);
		}
		
		// Default CSS rule (not prefix/suffix)
		if (!in_array($rule, ['prefix', 'suffix', 'prefix&suffix'], true)) {
			$safeRule = addslashes($condition['rule']);
			$safeAction = addslashes($condition['action'] ?? '');
			return "{$cellSelector}.css({'{$safeRule}': '{$safeAction}'});";
		}
		
		return '';
	}
	
	/**
	 * Apply replace action (integer, float, or text)
	 *
	 * @param string $cellSelector
	 * @param array $condition
	 * @return string
	 */
	private function applyReplaceAction($cellSelector, $condition) {
		$action = $condition['action'] ?? '';
		
		if ('integer' === $action) {
			return "{$cellSelector}.text(parseInt({$cellSelector}.text()));";
		}
		
		if ('float' === $action || canvastack_string_contained($action, 'float')) {
			$decimals = 2;
			
			if (canvastack_string_contained($action, '|')) {
				$condAcFloat = explode('|', $action);
				$decimals = intval($condAcFloat[1] ?? 2);
			}
			
			return "{$cellSelector}.text(parseFloat({$cellSelector}.text()).toFixed({$decimals}));";
		}
		
		// Text replacement - escape untuk keamanan
		$safeAction = addslashes($action);
		return "{$cellSelector}.text('{$safeAction}');";
	}
	
	/**
	 * Build button replacement JavaScript for URL/AJAX actions
	 *
	 * @param string $tableIdentity
	 * @param string $action
	 * @param string $fieldTarget
	 * @return string
	 */
	private function buildButtonReplacement($tableIdentity, $action, $fieldTarget) {
		$tableIdentityParts = explode('_', $tableIdentity);
		$node_table = isset($tableIdentityParts[1]) ? $tableIdentityParts[1] : 'table';
		
		$node_buttons = explode('::', $action);
		if (count($node_buttons) < 2) {
			return '';
		}
		
		$action_buttons = explode('|', $node_buttons[1]);
		
		if (count($action_buttons) < 3) {
			trigger_error('buildButtonReplacement(): Invalid action format, expected 3 parts', E_USER_WARNING);
			return '';
		}
		
		$button = [
			'name'  => $action_buttons[0],
			'class' => "btn {$action_buttons[0]} btn-{$action_buttons[1]} btn-xs",
			'icon'  => "fa fa-{$action_buttons[2]}",
			'token' => csrf_token()
		];
		
		$js = "$(cells[\"{$fieldTarget}\"]).each(function() {";
		$js .= "var anchorNode{$node_table} = $(this).children().find('.action-buttons').find('.{$button['name']}');";
		
		if ('ajax' === $node_buttons[0]) {
			$js .= $this->buildAjaxButtonHandler($node_table, $button, $tableIdentity);
		}
		
		$js .= "anchorNode{$node_table}.removeClass().addClass('{$button['class']}').find('i.fa').removeClass().addClass('{$button['icon']}');";
		$js .= "});";
		
		return $js;
	}
	
	/**
	 * Build AJAX button handler JavaScript
	 *
	 * @param string $node_table
	 * @param array $button
	 * @param string $tableIdentity
	 * @return string
	 */
	private function buildAjaxButtonHandler($node_table, $button, $tableIdentity) {
		// Escape token untuk keamanan
		$safeToken = addslashes($button['token']);
		
		$js = "var dataURLi{$node_table} = anchorNode{$node_table}.attr('href').split('/');";
		$js .= "var anchorValue{$node_table} = dataURLi{$node_table}[dataURLi{$node_table}.length-2];";
		$js .= "var dataValue{$node_table} = {'_token':'{$safeToken}',data:anchorValue{$node_table}};";
		$js .= "var anchorUrl{$node_table} = anchorNode{$node_table}.attr('href').replace(anchorValue{$node_table} + '/' + dataURLi{$node_table}[dataURLi{$node_table}.length-1], dataURLi{$node_table}[dataURLi{$node_table}.length-1]);";
		
		$js .= "anchorNode{$node_table}.removeAttr('href');";
		$js .= "anchorNode{$node_table}.click(function() {";
		$js .= "$.ajax({";
		$js .= "url: anchorUrl{$node_table},";
		$js .= "type: 'post',";
		$js .= "data: dataValue{$node_table},";
		$js .= "success: function (response) {";
		$js .= "{$tableIdentity}.draw();";
		$js .= "},";
		$js .= "error: function(jqXHR, textStatus, errorThrown) {";
		$js .= "console.log(textStatus, errorThrown);";
		$js .= "}";
		$js .= "});";
		$js .= "});";
		
		return $js;
	}

	/**
	 * Escape string for safe use in JavaScript (Advanced)
	 * Uses json_encode for proper escaping with special character handling
	 * 
	 * NOTE: For most cases, use addslashes() instead. This method is for complex scenarios.
	 * 
	 * Use addslashes() for:
	 * - Simple string values in JavaScript
	 * - CSS property names and values
	 * - URLs and identifiers
	 * 
	 * Use this method (escapeJsString) for:
	 * - Complex user-generated content with special characters
	 * - Data that may contain newlines, tabs, or control characters
	 * - When you need JSON-compatible escaping
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function escapeJsString($value) {
		return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
	
	/**
	 * Build filter button HTML
	 *
	 * @param array $data
	 * @return string|false
	 */
	protected function filterButton(array $data) {
		if (empty($data['searchable'])) {
			return false;
		}
		
		if (!empty($data['searchable']['all::columns'])) {
			if (false === $data['searchable']['all::columns']) {
				return false;
			}
		}
		
		if (false !== $data['searchable'] && !empty($data['class'])) {
			$btn_class = $data['class'];
			if (empty($data['class'])) {
				$btn_class = 'btn btn-primary btn-flat btn-lg mt-3';
			}
			
			// Validate required fields
			if (empty($data['id']) || empty($data['button_label'])) {
				return false;
			}
			
			// Escape class and id attributes, but NOT button_label (contains HTML icons)
			$safeClass = htmlspecialchars($btn_class, ENT_QUOTES, 'UTF-8');
			$safeId = htmlspecialchars($data['id'], ENT_QUOTES, 'UTF-8');
			
			// Note: button_label may contain HTML (icons), so we don't escape it
			// Caller is responsible for sanitizing button_label if it comes from user input
			return '<button type="button" class="' . $safeClass . ' ' . $safeId . '" data-toggle="modal" data-target=".' . $safeId . '">' . $data['button_label'] . '</button>';
		}
		
		return false;
	}
	
	/**
	 * Build filter modal box HTML
	 *
	 * @param array $data
	 * @return string|false
	 */
	protected function filterModalbox(array $data) {
		if (empty($data['searchable'])) {
			return false;
		}
		
		if (!empty($data['searchable']['all::columns'])) {
			if (false === $data['searchable']['all::columns']) {
				return false;
			}
		}
		
		if (empty($data['modal_content']['html']) || empty($data['id'])) {
			return false;
		}
		
		$current_url = url(canvastack_current_route()->uri);
		$attributes = '';
		
		if (!empty($data['attributes'])) {
			foreach ($data['attributes'] as $key => $attr) {
				$safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
				$safeAttr = htmlspecialchars($attr, ENT_QUOTES, 'UTF-8');
				$attributes .= " {$safeKey}=\"{$safeAttr}\"";
			}
		}
		
		$title = $data['modal_title'] ?? '';
		$name = $data['modal_content']['name'] ?? '';
		$content = $data['modal_content']['html'] ?? '';
		
		// Escape untuk HTML attributes (id dan action URL)
		$safeId = htmlspecialchars($data['id'], ENT_QUOTES, 'UTF-8');
		$safeCurrentUrl = htmlspecialchars($current_url, ENT_QUOTES, 'UTF-8');
		$token = csrf_token();
		
		// Note: title dan name bisa berisi HTML (icons), jadi tidak di-escape
		// Sama seperti button_label, caller bertanggung jawab untuk sanitasi jika dari user input
		
		$html  = '<div ' . $attributes . '>';
		$html .= '<div id="' . $safeId . '_CanvaStackFILTERFormBox" class="modal-dialog modal-lg" role="document">';
		$html .= '<form action="' . $safeCurrentUrl . '?renderDataTables=true&filters=true" method="GET" id="' . $safeId . '_CanvaStackFILTERForm" role="form">';
		$html .= '<div class="modal-content">';
		$html .= '<div id="' . $safeId . '_CanvaStackProcessing" class="dataTables_processing" style="display:none"></div>';
		$html .= '<div class="modal-header">';
		$html .= '<h5 class="modal-title" id="">' . $title . ' Data ' . $name . '</h5>';
		$html .= '<button type="button" class="close" data-dismiss="modal" aria-label="Close">';
		$html .= '<span aria-hidden="true">&times;</span>';
		$html .= '</button>';
		$html .= '</div>';
		$html .= '<input type="hidden" name="_token" value="' . $token . '" />';
		$html .= $content; // Content should be pre-sanitized by caller
		$html .= '</div>';
		$html .= '</form>';
		$html .= '</div>';
		$html .= '</div>';
		
		return $html;
	}

	/**
	 * Build export functionality JavaScript
	 *
	 * @param string $id
	 * @param string $url
	 * @param string $type
	 * @param string $delimeter
	 * @return string
	 */
	private function export($id, $url, $type = 'csv', $delimeter = '|') {
		$connection = null;
		
		if (canvastack_string_contained($id, '::')) {
			$stringID   = explode('::', $id);
			$id         = $stringID[0];
			$connection = canvastack_encrypt($stringID[1]);
		}
		
		$varTableID = $this->sanitizeTableId($id);
		$modalID    = "{$id}_CanvaStackFILTERmodalBOX";
		$filterID   = "{$id}_CanvaStackFILTER";
		$exportID   = 'export_' . str_replace('-', '_', $id) . '_CanvaStackFILTERField';
		$token      = csrf_token();
		
		$filters = [];
		if (!empty($this->conditions['where'])) {
			$filters = $this->conditions['where'];
		}
		$filter = json_encode($filters);
		
		// ✅ FIX: Escape JSON untuk JavaScript context to prevent injection
		$safeFilter = addslashes($filter);
		
		// Escape strings untuk JavaScript
		$safeModalId = addslashes($modalID);
		$safeExportId = addslashes($exportID);
		$safeFilterId = addslashes($filterID);
		$safeToken = addslashes($token);
		$safeUrl = addslashes($url);
		$safeConnection = addslashes($connection);
		
		return "exportFromModal('{$safeModalId}', '{$safeExportId}', '{$safeFilterId}', '{$safeToken}', '{$safeUrl}', '{$safeConnection}', JSON.parse('{$safeFilter}'));";
	}
	
	/**
	 * Build filter functionality JavaScript
	 *
	 * @param string $id
	 * @param string $url
	 * @return string
	 */
	private function filter($id, $url) {
		$varTableID = $this->sanitizeTableId($id);
		
		// Escape untuk JavaScript
		$safeId = addslashes($id);
		$safeUrl = addslashes($url);
		
		return "canvastackDataTableFilters('{$safeId}', '{$safeUrl}', CanvaStack_{$varTableID}_dt);";
	}
	
	/**
	 * Build initComplete callback for DataTables
	 *
	 * @param string $id
	 * @param boolean|string $location
	 * @return string
	 */
	private function initComplete($id, $location = 'footer') {
		$safeId = addslashes($id);
		
		if (false === $location) {
			return "initComplete: function() {document.getElementById('{$safeId}').deleteTFoot();}";
		}
		
		if (true === $location) {
			$location = 'footer';
		}
		
		$js  = "initComplete: function() {";
		$js .= "this.api().columns().every(function(n) {";
		$js .= "if (n > 1) {";
		$js .= "var column = this;";
		$js .= "var input  = document.createElement(\"input\");";
		$js .= "$(input).attr({";
		$js .= "'class':'form-control',";
		$js .= "'placeholder': 'search'";
		$js .= "}).appendTo($(column.{$location}()).empty()).on('change', function () {";
		$js .= "column.search($(this).val(), false, false, true).draw();";
		$js .= "});";
		$js .= "}";
		$js .= "});";
		$js .= "}";
		
		return $js;
	}

	/** 
	 * Set Buttons configuration for DataTables
	 * 
	 * @param string $id
	 * @param array $button_sets
	 * @return string
	 * 
	 * @example
	 * $buttonset = '[
	 *   {
	 *     extend:"collection",
	 *     exportOptions:{columns:":visible:not(:last-child)"},
	 *     text:"<i class=\"fa fa-external-link\" aria-hidden=\"true\"></i> <u>E</u>xport",
	 *     buttons:[{text:"Excel",buttons:"excel"}, "csv", "pdf"],
	 *     key:{key:"e",altKey:true}
	 *   },
	 *   "copy",
	 *   "print"
	 * ]';
	 */
	private function setButtons($id, $button_sets = []) {
		if (empty($button_sets)) {
			return '[]';
		}
		
		$buttons = [];
		
		foreach ($button_sets as $button) {
			$button = trim($button);
			$options = [];
			
			if (canvastack_string_contained($button, '|')) {
				$splits = explode('|', $button);
				
				foreach ($splits as $split) {
					if (canvastack_string_contained($split, ':')) {
						$options[] = $split;
					} else {
						$button = $split;
					}
				}
			}
			
			$option = !empty($options) ? implode(',', $options) : '';
			$buttons[] = '{extend:"' . $button . '", ' . $option . '}';
		}
		
		return '[' . implode(',', $buttons) . ']';
	}
}
