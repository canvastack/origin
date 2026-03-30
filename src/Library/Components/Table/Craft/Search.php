<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Library\Components\Form\Objects as Form;
use Illuminate\Support\Facades\Request;
/**
 * Created on 24 Apr 2021
 * Time Created : 20:51:52
 *
 * @filesource Search.php
 *
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class Search {
	
	private $model;
	private $form;
	private $filters;
	private $input_relations;
	private $relations;
	private $foreign_keys;
	private $sql;
	private $table;
	private $tableFromView = false;
	private $info;
	private $searchConnection;
	
	private $model_filters = [];
	public function __construct($info, $model = null, $filters = [], $sql = null, $connection = null, $filterQuery = []) {
		if (!empty($connection)) $this->searchConnection = $connection;
		
		if (canvastack_string_contained($filters['table_name'], 'view_'))  $this->tableFromView = true;
		
		$this->info = $info;
		if (!empty($model)) $model = new $model();
		
		if (!empty($filters['filter_model'])) {
			$this->model_filters = $filters['filter_model'];
			$this->model         = $model->where($this->model_filters);
		} else $this->model      = $model;
		
		$this->form         = new Form();
		$this->table        = $filters['table_name'];
		$this->relations    = $filters['relations'];
		$this->foreign_keys = $filters['foreign_keys'];
		$this->filters      = $filters;
		$this->sql          = $sql;
		
		if (!empty($filters['filter_groups'])) $this->getFilterData($filters['filter_groups']);
		if (!empty($filterQuery)) $this->filters['filter_query'] = canvastack_filter_data_normalizer($filterQuery);
	}
	
	public function render($info, string $table, array $fields) {
		if ($this->info === $info) {
			$this->search_box($info, $table, $this->getColumnInfo($table, $fields), $this->model);
			
			$data         = [];
			$data['name'] = ucwords(str_replace('-', ' ', canvastack_clean_strings($table)));
			$data['html'] = $this->html;
			
			return $data;
		}
	}
	
	private function select($sql, $connection = null) {
		return canvastack_query($sql, 'SELECT', $connection);
	}
	
	private $data = [];
	private function getFilterData($data) {
		$all_columns = $this->collectAllColumns();
		$data = $this->processFilterRows($data, $all_columns);
		$this->data = $data;
		
		$input_relations = $this->buildInputRelations($data);
		$this->setInputRelations($input_relations);
	}
	
	/**
	 * Collect all columns from filters
	 *
	 * @return array
	 */
	private function collectAllColumns() {
		$all_columns = [];
		$columns = $this->filters['columns'] ?? [];
		
		foreach ($columns as $col) {
			$all_columns[$col] = $col;
		}
		
		return $all_columns;
	}
	
	/**
	 * Process filter rows and build relists
	 *
	 * @param array $data
	 * @param array $all_columns
	 * @return array
	 */
	private function processFilterRows($data, $all_columns) {
		$processed = [];
		
		foreach ($data as $key => $row) {
			$column = $row['column'] ?? '';
			$type = $row['type'] ?? 'text';
			$relate = $row['relate'] ?? false;
			
			// Determine relate value
			if (!empty($relate)) {
				$relate = (true === $relate) ? array_keys($all_columns) : $relate;
			}
			
			$processed[$column] = [
				'name' => $column,
				'type' => $type,
				'relate' => $relate
			];
		}
		
		return $processed;
	}
	
	/**
	 * Build input relations from processed data
	 *
	 * @param array $data
	 * @return array
	 */
	private function buildInputRelations($data) {
		if (count($data) >= 2) {
			return $this->buildMultipleInputRelations($data);
		}
		
		return $this->buildSingleInputRelation($data);
	}
	
	/**
	 * Build input relations for multiple data
	 *
	 * @param array $data
	 * @return array
	 */
	private function buildMultipleInputRelations($data) {
		$input_relations = ['lists' => [], 'type' => []];
		
		foreach ($data as $column => $row) {
			$relate = $row['relate'] ?? false;
			
			if (false === $relate) {
				continue;
			}
			
			foreach ((array)$relate as $relation) {
				$input_relations['lists'][] = $relation;
				$input_relations['type'][$relation] = $data[$relation]['type'] ?? 'text';
			}
		}
		
		return $input_relations;
	}
	
	/**
	 * Build input relation for single data
	 *
	 * @param array $data
	 * @return array
	 */
	private function buildSingleInputRelation($data) {
		$the_only_data = array_keys($data);
		$first_key = $the_only_data[0] ?? null;
		
		if ($first_key === null) {
			return ['lists' => [], 'type' => []];
		}
		
		return [
			'lists' => [$first_key],
			'type' => [$first_key => 'selectbox']
		];
	}
	
	/**
	 * Set input relations to class property
	 *
	 * @param array $input_relations
	 * @return void
	 */
	private function setInputRelations($input_relations) {
		if (!empty($input_relations['lists'])) {
			$this->input_relations['lists'] = array_unique($input_relations['lists']);
		}
		
		if (!empty($input_relations['type'])) {
			$this->input_relations['type'] = $input_relations['type'];
		}
	}
	
	private $selections = [];
	private function selections($table, $fields = [], $condition = null) {
		// Check relations first
		$strfields = implode(',', $fields);
		if ($this->hasRelationData($strfields)) {
			return $this;
		}
		
		// Build query with security
		if (!empty($strfields)) {
			$where = $this->buildWhereClause($table, $condition);
			$query = $this->executeSecureQuery($table, $strfields, $where);
			$this->processQueryResults($query, $fields);
		}
		
		return $this;
	}
	
	/**
	 * Check if relation data exists
	 *
	 * @param string $strfields
	 * @return bool
	 */
	private function hasRelationData($strfields) {
		if (empty($this->relations)) {
			return false;
		}
		
		if (empty($this->relations[$strfields]['relation_data'])) {
			return false;
		}
		
		foreach ($this->relations[$strfields]['relation_data'] as $relationData) {
			$fieldValue = $relationData['field_value'] ?? null;
			if ($fieldValue !== null) {
				$this->selections[$strfields][$fieldValue] = $fieldValue;
			}
		}
		
		return true;
	}
	
	/**
	 * Build WHERE clause dengan security (prevent SQL injection)
	 *
	 * @param string $table
	 * @param mixed $condition
	 * @return string
	 */
	private function buildWhereClause($table, $condition) {
		$where = '';
		
		if (!empty($condition)) {
			$table = $this->sanitizeIdentifier($table);
			$where = "WHERE `{$table}`.id IS NOT NULL ";
		}
		
		if (!empty($this->model_filters)) {
			$where = $this->buildModelFiltersWhere();
		}
		
		if (!empty($this->filters['filter_query'])) {
			$where = $this->buildFilterQueryWhere();
		}
		
		return $where;
	}
	
	/**
	 * Build WHERE clause dari model filters (SECURE)
	 *
	 * @return string
	 */
	private function buildModelFiltersWhere() {
		$mf_where = [];
		$n = 0;
		
		foreach ($this->model_filters as $mf_field => $mf_values) {
			$n++;
			$mf_cond = ($n <= 1) ? 'WHERE ' : 'AND ';
			
			// Sanitize field name
			$mf_field = $this->sanitizeIdentifier($mf_field);
			
			if (!is_array($mf_values)) {
				// Single value - use prepared statement style
				$mf_values = $this->escapeValue($mf_values);
				$mf_where[] = "{$mf_cond}`{$mf_field}` = '{$mf_values}'";
			} else {
				// Multiple values - use IN clause
				$escaped_values = array_map([$this, 'escapeValue'], $mf_values);
				$mf_value = implode("', '", $escaped_values);
				$mf_where[] = "{$mf_cond}`{$mf_field}` IN ('{$mf_value}')";
			}
		}
		
		return implode(' ', $mf_where);
	}
	
	/**
	 * Build WHERE clause dari filter query (SECURE)
	 *
	 * @return string
	 */
	private function buildFilterQueryWhere() {
		$filterQueries = [];
		
		foreach ($this->filters['filter_query'] as $i => $fqData) {
			$fqFieldName = $fqData['field_name'] ?? '';
			$fqDataValue = $fqData['value'] ?? '';
			
			// Sanitize field name
			$fqFieldName = $this->sanitizeIdentifier($fqFieldName);
			
			if (is_array($fqDataValue)) {
				// Escape all values in array
				$escaped_values = array_map([$this, 'escapeValue'], $fqDataValue);
				$fQdataValue = implode("', '", $escaped_values);
				
				if (count($fqDataValue) >= 2) {
					$filterQueries[$i] = "`{$fqFieldName}` IN ('{$fQdataValue}')";
				} else {
					$filterQueries[$i] = "`{$fqFieldName}` = '{$fQdataValue}'";
				}
			} else {
				// Escape single value
				$fqDataValue = $this->escapeValue($fqDataValue);
				$filterQueries[$i] = "`{$fqFieldName}` = '{$fqDataValue}'";
			}
		}
		
		$filterQuery = implode(' AND ', $filterQueries);
		return "WHERE {$filterQuery}";
	}
	
	/**
	 * Execute secure query
	 *
	 * @param string $table
	 * @param string $strfields
	 * @param string $where
	 * @return array
	 */
	private function executeSecureQuery($table, $strfields, $where) {
		$table = $this->sanitizeIdentifier($table);
		$sql = "SELECT {$strfields} FROM `{$table}` {$where} GROUP BY {$strfields};";
		return $this->select($sql, $this->searchConnection);
	}
	
	/**
	 * Process query results
	 *
	 * @param array $query
	 * @param array $fields
	 * @return void
	 */
	private function processQueryResults($query, $fields) {
		if (empty($query)) {
			return;
		}
		
		$selections = [];
		foreach ($query as $rows) {
			foreach ($rows as $fieldname => $fieldvalue) {
				$selections[$fieldname][$fieldvalue] = $fieldvalue;
			}
		}
		
		foreach ($fields as $field) {
			if (isset($selections[$field])) {
				$this->selections[$field] = array_unique($selections[$field]);
			}
		}
	}
	
	/**
	 * Sanitize identifier (table/column name) - prevent SQL injection
	 *
	 * @param string $identifier
	 * @return string
	 */
	private function sanitizeIdentifier($identifier) {
		// Only allow alphanumeric, underscore, and dot
		return preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
	}
	
	/**
	 * Escape value for SQL - prevent SQL injection
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function escapeValue($value) {
		if ($value === null) {
			return '';
		}
		
		// Use addslashes for basic escaping
		// In production, use DB::connection()->getPdo()->quote() for better security
		return addslashes((string)$value);
	}
	
	private function set_first_selectbox($name, $field_value, $field) {
		$values[$field]      = null;
		$field_value[$field] = $this->selections($name, [$field]);
		if (!empty($field_value[$field]->selections[$field])) {
			if (!empty($field_value[$field]->selections[$field])) {
				$values[$field] = $field_value[$field]->selections[$field];
			}
		}
		
		return $values[$field];
	}
	
	private $html         = false;
	private $searchFields = [];
	private function search_box($info, $tablename, $data, $model) {
		$this->setupFormConfig();
		$filterQuery = $this->getFilterQuery();
		$this->setupSearchFields();
		
		$script_elements = [];
		
		if (!empty($this->input_relations['type'])) {
			$script_elements = $this->processInputRelations($info, $tablename);
		} else {
			$script_elements = $this->processDefaultData($info, $data);
		}
		
		$this->generateModalHTML($info, $tablename, $script_elements, $filterQuery);
	}
	
	/**
	 * Setup form configuration
	 *
	 * @return void
	 */
	private function setupFormConfig() {
		$this->form->excludeFields = ['password_field'];
		$this->form->hideFields = ['id'];
	}
	
	/**
	 * Get filter query
	 *
	 * @return array
	 */
	private function getFilterQuery() {
		return $this->filters['filter_query'] ?? [];
	}
	
	/**
	 * Setup search fields
	 *
	 * @return void
	 */
	private function setupSearchFields() {
		foreach (array_keys($this->data) as $dataFields) {
			$this->searchFields[$dataFields] = $dataFields;
		}
	}
	
	/**
	 * Process input relations (SECURE - XSS protected)
	 *
	 * @param string $info
	 * @param string $tablename
	 * @return array
	 */
	private function processInputRelations($info, $tablename) {
		$script_elements = [];
		$inputRelations = $this->prepareInputRelations();
		$this->input_relations['type'] = $inputRelations;
		
		$open_field = $this->input_relations['lists'][0] ?? null;
		
		if (!empty($open_field)) {
			foreach ($this->input_relations['type'] as $field => $type) {
				$values = $this->prepareFieldValues($field, $open_field, $tablename);
				$attributes = $this->buildFieldAttributes($field, $info, $values);
				
				// FIX XSS: Escape field label
				$field_label = $this->escapeHtml(ucwords(canvastack_clean_strings($field, ' ')));
				$values = $this->prepareFieldOptions($field, $type, $values, $field_label);
				
				$this->generateFormElement($field, $type, $values, $attributes);
				$script_elements[$info][$field] = $type;
			}
		}
		
		return $script_elements;
	}
	
	/**
	 * Prepare input relations
	 *
	 * @return array
	 */
	private function prepareInputRelations() {
		$inputRelations = [];
		
		foreach ($this->input_relations['type'] as $inputFields => $inputType) {
			if (!empty($this->searchFields[$inputFields])) {
				$inputRelations[$this->searchFields[$inputFields]] = $inputType;
			}
		}
		
		return $inputRelations;
	}
	
	/**
	 * Prepare field values
	 *
	 * @param string $field
	 * @param string $open_field
	 * @param string $tablename
	 * @return array|null
	 */
	private function prepareFieldValues($field, $open_field, $tablename) {
		$values = null;
		
		if ($open_field === $field) {
			$field_value = [];
			$values = $this->set_first_selectbox($tablename, $field_value, $field);
		}
		
		return $values;
	}
	
	/**
	 * Build field attributes
	 *
	 * @param string $field
	 * @param string $info
	 * @param mixed $values
	 * @return array
	 */
	private function buildFieldAttributes($field, $info, $values) {
		$classFieldInfo = "{$this->cleardash($info)}Field";
		$attributes = [
			'id' => $field,
			'class' => "{$field}_{$classFieldInfo} export_{$classFieldInfo}"
		];
		
		if (empty($values)) {
			$attributes['disabled'] = 'disabled';
		}
		
		return $attributes;
	}
	
	/**
	 * Prepare field options (SECURE - XSS protected)
	 *
	 * @param string $field
	 * @param string $type
	 * @param mixed $values
	 * @param string $field_label (already escaped)
	 * @return array
	 */
	private function prepareFieldOptions($field, $type, $values, $field_label) {
		if ('selectbox' === $type) {
			if (null === $values) {
				$values = [null => 'No Data ' . $field_label . ' Found'];
			} else {
				$values[null] = 'Select ' . $field_label;
			}
			ksort($values);
		}
		
		if ('radiobox' === $type) {
			if (null !== $values && count($values) > 1) {
				$values[null] = 'Clear!';
			}
		}
		
		return $values;
	}
	
	/**
	 * Generate form element based on type
	 *
	 * @param string $field
	 * @param string $type
	 * @param mixed $values
	 * @param array $attributes
	 * @return void
	 */
	private function generateFormElement($field, $type, $values, $attributes) {
		switch ($type) {
			case 'selectbox':
				$this->form->selectbox($field, $values, false, $attributes, true, false);
				break;
			case 'date':
				$this->form->date($field, $values, $attributes);
				break;
			case 'datetime':
				$this->form->date($field, $values, $attributes);
				break;
			case 'checkbox':
				if ($this->shouldRenderCheckbox($values)) {
					$this->form->checkbox($field, $values);
				}
				break;
			case 'radiobox':
				if ($this->shouldRenderRadiobox($values)) {
					$this->form->radiobox($field, $values);
				}
				break;
			default:
				if (!empty($values)) {
					$this->form->text($field, $values, ['id' => $field]);
				}
		}
	}
	
	/**
	 * Check if checkbox should be rendered
	 *
	 * @param mixed $values
	 * @return bool
	 */
	private function shouldRenderCheckbox($values) {
		if (empty($values)) {
			return false;
		}
		
		return !in_array('', $values) || !in_array(null, $values);
	}
	
	/**
	 * Check if radiobox should be rendered
	 *
	 * @param mixed $values
	 * @return bool
	 */
	private function shouldRenderRadiobox($values) {
		if (empty($values)) {
			return false;
		}
		
		return !in_array('', $values) || !in_array(null, $values);
	}
	
	/**
	 * Process default data
	 *
	 * @param string $info
	 * @param array $data
	 * @return array
	 */
	private function processDefaultData($info, $data) {
		$script_elements = [];
		
		foreach ($data as $field => $type) {
			$this->generateDefaultFormElement($field, $type);
			$script_elements[$info][$field] = $type;
		}
		
		return $script_elements;
	}
	
	/**
	 * Generate default form element
	 *
	 * @param string $field
	 * @param string $type
	 * @return void
	 */
	private function generateDefaultFormElement($field, $type) {
		$attributes = ['id' => $field];
		
		switch ($type) {
			case 'string':
			case 'text':
				$this->form->text($field, null, $attributes);
				break;
			case 'smallint':
				$this->form->selectbox($field, [], false, $attributes);
				break;
			case 'date':
				$this->form->date($field, null, $attributes);
				break;
			case 'datetime':
				$this->form->datetime($field, null, $attributes);
				break;
			case 'time':
				$this->form->time($field, null, $attributes);
				break;
			case 'daterange':
				$this->form->daterange($field, null, $attributes);
				break;
			default:
				$this->form->text($field, null, $attributes);
		}
	}
	
	/**
	 * Generate modal HTML (SECURE - XSS protected)
	 *
	 * @param string $info
	 * @param string $tablename
	 * @param array $script_elements
	 * @param array $filterQuery
	 * @return void
	 */
	private function generateModalHTML($info, $tablename, $script_elements, $filterQuery) {
		// FIX XSS: Escape tablename for display
		$boxTitle = $this->escapeHtml(ucwords(str_replace('-', ' ', canvastack_clean_strings($tablename))));
		$boxName = $info . 'modalBOX';
		
		$this->addScriptsTemplate($script_elements, $tablename, $boxName, $filterQuery);
		$this->html = canvastack_modal_content_html($boxName, $boxTitle, $this->form->elements);
	}
	
	/**
	 * Escape HTML to prevent XSS
	 *
	 * @param string $value
	 * @return string
	 */
	private function escapeHtml($value) {
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
	
	public $add_scripts  = [];
	private function addScriptsTemplate(array $element_scripts, string $table, $node, $filters = []) {
		$current_template = canvastack_template_config('admin.' . canvastack_current_template());
		unset($current_template['position']);
		
		$nodElm           = str_replace('modalBOX', '', $node);
		$fields           = [];
		$scriptElements   = array_keys($element_scripts[$nodElm]);
		$fields['others'] = $scriptElements;
		
		$this->script_config($node, $scriptElements);
		foreach ($scriptElements as $index => $field) {
			unset($scriptElements[$index]);
			
			$fields['current'] = [$index => $field];
			
			$this->script_next_data($node, $field, $fields, $table, $filters);
		}
		
		foreach ($element_scripts[$nodElm] as $type) {
			if ('selectbox' === $type || 'smallint' === $type) $type = 'select';
			
			foreach ($current_template as $element => $data) {
				if ($element === $type) {
					foreach ($data as $script_type => $script_paths) {
						if ('js' === $script_type) {
							foreach ($script_paths as $script_path) {
								$this->add_scripts['js'][]  = canvastack_script_check_string_path(str_replace('last:js', 'js', $script_path));
							}
						} else {
							foreach ($script_paths as $script_path) {
								$this->add_scripts['css'][] = canvastack_script_check_string_path(str_replace('last:css', 'css', $script_path));
							}
						}
					}
				}
			}
		}
	}
	
	private $scriptToHTML = 'canvastackScriptNode::';
	private function script_next_data($node, $identity, $fields, $table, $filters = []) {
		// Setup node names (SECURE - sanitize for JavaScript)
		$nodeNames = $this->setupNodeNames($node, $identity, $fields);
		
		// Setup field targets
		$targets = $this->setupFieldTargets($fields, $nodeNames);
		
		// Build nest data
		$nests = $this->buildNestData($fields, $nodeNames);
		
		// Build scripts
		$nesCript = $this->buildNextScript($nests, $nodeNames, $targets);
		$ajaxSuccess = $this->buildAjaxScript($identity, $table, $targets, $nests, $nodeNames, $filters);
		
		// Generate main script
		$script = $this->buildMainScript($node, $identity, $targets, $nodeNames, $nesCript, $ajaxSuccess);
		
		$this->add_scripts['add_js'][] = "{$this->scriptToHTML}{$script}";
	}
	
	/**
	 * Setup node names (SECURE - sanitize for JavaScript)
	 *
	 * @param string $node
	 * @param string $identity
	 * @param array $fields
	 * @return array
	 */
	private function setupNodeNames($node, $identity, $fields) {
		// FIX XSS: Sanitize for JavaScript context
		$identity = $this->escapeJs($identity);
		$node = $this->escapeJs($node);
		
		$currKey = key($fields['current']);
		$iNode = $this->cleardash(str_replace('modalBOX', $identity, $node));
		$fNode = $this->cleardash(str_replace('modalBOX', 'Field', $node));
		$firstNode = "{$identity}_{$fNode}";
		
		return [
			'currKey' => $currKey,
			'iNode' => $iNode,
			'fNode' => $fNode,
			'firstNode' => $firstNode
		];
	}
	
	/**
	 * Setup field targets
	 *
	 * @param array $fields
	 * @param array $nodeNames
	 * @return array
	 */
	private function setupFieldTargets($fields, $nodeNames) {
		$fieldsets = $fields['others'] ?? [];
		$currKey = $nodeNames['currKey'];
		$fNode = $nodeNames['fNode'];
		
		$next_target = null;
		$nextNode = null;
		$curTargets = null;
		$nexTargets = [];
		
		if (!empty($fieldsets[$currKey + 1])) {
			$next_target = $fieldsets[key($fields['current']) + 1];
			$nextNode = "{$next_target}_{$fNode}";
			$curTargets = $fieldsets[key($fields['current'])];
			$nexTargets = $fieldsets;
		}
		
		$firstTarget = $fieldsets[0] ?? null;
		$lastTarget = $fieldsets[count($fieldsets) - 2] ?? null;
		
		return [
			'next_target' => $next_target,
			'nextNode' => $nextNode,
			'curTargets' => $curTargets,
			'nexTargets' => $nexTargets,
			'firstTarget' => $firstTarget,
			'lastTarget' => $lastTarget,
			'fieldsets' => $fieldsets
		];
	}
	
	/**
	 * Build nest data (prev/next)
	 *
	 * @param array $fields
	 * @param array $nodeNames
	 * @return array
	 */
	private function buildNestData($fields, $nodeNames) {
		$currKey = $nodeNames['currKey'];
		$fNode = $nodeNames['fNode'];
		
		$nests = ['prev' => [], 'next' => []];
		$prev = null;
		$prevscript = "null";
		$prevscripts = [];
		
		foreach ($fields['others'] as $idx => $value) {
			if ($idx < $currKey) {
				$nests['prev'][$idx] = $value;
			} else {
				if ($idx !== $currKey + 1) {
					$nests['next'][$idx] = $value;
				}
			}
		}
		
		if (!empty($nests['prev'])) {
			$prev = implode('|', $nests['prev']);
			foreach ($nests['prev'] as $preval) {
				// FIX XSS: Escape for JavaScript
				$preval = $this->escapeJs($preval);
				$prevNode = "{$preval}_{$fNode}";
				$prevscripts[] = "$('select#{$preval}.{$prevNode}').val()";
			}
			$prevscript = implode("+'|'+", $prevscripts);
		}
		
		$nest = null;
		if (!empty($nests['next'])) {
			$nest = implode('|', $nests['next']);
		}
		
		return [
			'nests' => $nests,
			'prev' => $prev,
			'prevscript' => $prevscript,
			'nest' => $nest
		];
	}
	
	/**
	 * Build next script (SECURE - XSS protected)
	 *
	 * @param array $nests
	 * @param array $nodeNames
	 * @param array $targets
	 * @return string
	 */
	private function buildNextScript($nests, $nodeNames, $targets) {
		$nest = $nests['nest'];
		$iNode = $nodeNames['iNode'];
		$nextNode = $targets['nextNode'];
		$next_target = $targets['next_target'];
		
		if (empty($nests['nests']['next'])) {
			return null;
		}
		
		// FIX XSS: Escape for JavaScript context
		$next_target_safe = $this->escapeJs($next_target);
		$nest_safe = $this->escapeJs($nest);
		
		$nesCript = "var _nx{$nextNode} = '{$next_target_safe}';";
		$nesCript .= "var _reident{$nextNode} = _nx{$nextNode}.replace('_', ' ');";
		$nesCript .= "$('select#{$next_target_safe}.{$nextNode}').empty()";
		$nesCript .= ".append('<option value=\"\">No Data ' + ucwords(_reident{$nextNode}) + ' Found</option>')";
		$nesCript .= ".prop('disabled', true).trigger('chosen:updated');";
		
		$nesCript .= "if (null != '{$nest_safe}' && '' != '{$nest_safe}') {";
		$nesCript .= "var _spldt{$iNode} = '{$nest_safe}';";
		$nesCript .= "var _spl{$iNode} = _spldt{$iNode}.split('|');";
		$nesCript .= "$.each(_spl{$iNode}, function(i, obj) {";
		$nesCript .= "if (null != obj && '{$this->escapeJs($nodeNames['iNode'])}' != obj) {";
		$nesCript .= "var _reident{$iNode} = obj.replace('_', ' ');";
		$nesCript .= "$('#' + obj).empty()";
		$nesCript .= ".append('<option value=\"\">No Data ' + ucwords(_reident{$iNode}) + ' Found</option>')";
		$nesCript .= ".prop('disabled', true).trigger('chosen:updated');";
		$nesCript .= "}";
		$nesCript .= "});";
		$nesCript .= "}";
		
		return $nesCript;
	}
	
	/**
	 * Build AJAX script (SECURE - XSS protected)
	 *
	 * @param string $identity
	 * @param string $table
	 * @param array $targets
	 * @param array $nests
	 * @param array $nodeNames
	 * @param array $filters
	 * @return string|null
	 */
	private function buildAjaxScript($identity, $table, $targets, $nests, $nodeNames, $filters) {
		$next_target = $targets['next_target'];
		
		if (empty($next_target)) {
			return null;
		}
		
		// FIX XSS: Escape all values for JavaScript
		$identity_safe = $this->escapeJs($identity);
		$table_safe = $this->escapeJs($table);
		$next_target_safe = $this->escapeJs($next_target);
		$prev_safe = $this->escapeJs($nests['prev']);
		$nest_safe = $this->escapeJs($nests['nest']);
		
		$iNode = $nodeNames['iNode'];
		$nextNode = $targets['nextNode'];
		$prevscript = $nests['prevscript'];
		
		$forkey = [];
		if (!empty($this->foreign_keys)) {
			$forkey = $this->foreign_keys;
		}
		$forkeys = json_encode($forkey);
		
		$uri = canvastack_get_ajax_urli('filterDataTables', $this->searchConnection);
		$token = csrf_token();
		$target = ucwords(str_replace('_', ' ', $next_target));
		
		$ajaxConnection = '';
		if (!empty($this->searchConnection)) {
			$connection_safe = $this->escapeJs($this->searchConnection);
			$ajaxConnection = ",'grabCanvaStackC':'{$connection_safe}'";
		}
		
		$canvastackF = '';
		if (!empty($filters)) {
			$canvastackFilters = json_encode($filters);
			$canvastackF = ",'_canvastackF':{$canvastackFilters}";
		}
		
		$ajax_data = "{'{$identity_safe}':_val{$iNode},'_fita':'{$token}::{$table_safe}::{$next_target_safe}::{$prev_safe}#' + _prevS{$iNode} + '::{$nest_safe}','_token':'{$token}','_n':'{$nest_safe}','_forKeys':'{$forkeys}'{$ajaxConnection}{$canvastackF}}";
		
		$ajaxSuccess = "var _next{$next_target_safe} = '{$target}';";
		$ajaxSuccess .= "var _prevS{$iNode} = {$prevscript};";
		$ajaxSuccess .= "$.ajax ({";
		$ajaxSuccess .= "type: 'POST',";
		$ajaxSuccess .= "url: '{$uri}',";
		$ajaxSuccess .= "data: {$ajax_data},";
		$ajaxSuccess .= "dataType: 'json',";
		$ajaxSuccess .= "beforeSend: function() {";
		$ajaxSuccess .= "$('#CanvaStackInpLdr{$next_target_safe}').show();";
		$ajaxSuccess .= "},";
		$ajaxSuccess .= "success: function(data) {";
		$ajaxSuccess .= "if (data) {";
		$ajaxSuccess .= "if ('' != '{$next_target_safe}' && null != '{$next_target_safe}') {";
		$ajaxSuccess .= "$('select#{$next_target_safe}.{$nextNode}').removeAttr('disabled').trigger('chosen:updated');";
		$ajaxSuccess .= "$('select#{$next_target_safe}.{$nextNode}').empty();";
		$ajaxSuccess .= "$('select#{$next_target_safe}.{$nextNode}').append('<option value=\"\">Select ' + _next{$next_target_safe} + '</option>').trigger('chosen:updated');";
		$ajaxSuccess .= "$.each(data, function(key, value) {";
		$ajaxSuccess .= "$('select#{$next_target_safe}.{$nextNode}').append('<option value=\"'+ value.{$next_target_safe} +'\">' + value.{$next_target_safe} + '</option>').trigger('chosen:updated');";
		$ajaxSuccess .= "});";
		$ajaxSuccess .= "}";
		$ajaxSuccess .= "}";
		$ajaxSuccess .= "},";
		$ajaxSuccess .= "complete: function() {";
		$ajaxSuccess .= "$('#CanvaStackInpLdr{$next_target_safe}').hide();";
		$ajaxSuccess .= "}";
		$ajaxSuccess .= "});";
		
		return $ajaxSuccess;
	}
	
	/**
	 * Build main script (SECURE - XSS protected)
	 *
	 * @param string $node
	 * @param string $identity
	 * @param array $targets
	 * @param array $nodeNames
	 * @param string $nesCript
	 * @param string $ajaxSuccess
	 * @return string|null
	 */
	private function buildMainScript($node, $identity, $targets, $nodeNames, $nesCript, $ajaxSuccess) {
		if (empty($identity)) {
			return null;
		}
		
		// FIX XSS: Escape for JavaScript
		$identity_safe = $this->escapeJs($identity);
		$node_safe = $this->escapeJs($node);
		
		$firstNode = $nodeNames['firstNode'];
		$iNode = $nodeNames['iNode'];
		$nexTargets = $targets['nexTargets'];
		$curTargets = $targets['curTargets'];
		$firstTarget = $targets['firstTarget'];
		$lastTarget = $targets['lastTarget'];
		
		$script = "jQuery(function($) {";
		$script .= "$('#{$node_safe}').children('div.form-group').each(function () {";
		$script .= "$(this).find('select#{$identity_safe}.{$firstNode}').change(function () {";
		
		if (!empty($nexTargets)) {
			$curN = 0;
			foreach ($nexTargets as $n => $nextElement) {
				if ($curTargets === $nextElement) {
					$curN = $n;
				}
				$curNode = $curN + 1;
				
				if ($n > $curNode) {
					if ($lastTarget !== $nextElement) {
						if ($identity === $firstTarget) {
							$script .= "if ($(this).val() != '') { $('button#exportFilterButton{$node_safe}').removeClass('hide'); } else { $('button#exportFilterButton{$node_safe}').addClass('hide'); }";
							$script .= "$('select#{$lastTarget}').empty().trigger('chosen:updated');";
						}
						
						if ($identity !== $lastTarget) {
							$nextElement_safe = $this->escapeJs($nextElement);
							$script .= "$('select#{$nextElement_safe}').empty().trigger('chosen:updated');";
						}
					}
				}
			}
		}
		
		$script .= "var _val{$iNode} = $(this).val();";
		$script .= "if (_val{$iNode} != '0' && _val{$iNode} != null && _val{$iNode} != '') {";
		$script .= "{$ajaxSuccess}";
		$script .= "} else {";
		$script .= "{$nesCript}";
		$script .= "}";
		$script .= "});";
		$script .= "});";
		$script .= "});";
		
		return $script;
	}
	
	/**
	 * Escape string for JavaScript context - prevent XSS
	 *
	 * @param string $value
	 * @return string
	 */
	private function escapeJs($value) {
		if ($value === null) {
			return '';
		}
		
		// Escape for JavaScript string context
		return addslashes((string)$value);
	}
	
	private function cleardash($string) {
		return str_replace('-', '_', $string);
	}
	
	private function script_config($node, $fields) {
		$FieldSets = [];
		if (!empty($fields)) {
			foreach ($fields as $index => $field) {
				if ($index >= 1) $FieldSets[] = "loader('{$field}');";
			}
		}
		$fieldScripts = implode('', $FieldSets);
				
		$this->add_scripts['add_js'][] = "{$this->scriptToHTML}{$fieldScripts}";
	}
	
	private function getColumnInfo(string $table, array $fields) {
		$columns = [];
		foreach ($this->getColumns($table) as $column) {
		    if (false === $this->tableFromView) $columns[$column] = $this->getColumnType($table, $column);
		}
		
		$info = [];
		foreach ($fields as $field) {
			if (!empty($columns[$field])) {
				$info[$field] = $columns[$field];
			}
		}
		
		return $info;
	}
	
	private function getColumns($table) {
		$connection = 'mysql';
		if (!empty($this->searchConnection)) $connection = $this->searchConnection;
		
		return canvastack_get_table_columns($table, $connection);
	}
	
	private function getColumnType($table, $column) {
		$connection = 'mysql';
		if (!empty($this->searchConnection)) $connection = $this->searchConnection;
		
		return canvastack_get_table_column_type($table, $column, $connection);
	}
}