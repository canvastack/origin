<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Library\Components\Table\Craft\Search\Config\SearchConfig;
use Canvastack\Origin\Library\Components\Table\Craft\Search\QueryBuilder;
use Canvastack\Origin\Library\Components\Table\Craft\Search\FormGenerator;
use Canvastack\Origin\Library\Components\Table\Craft\Search\ScriptGenerator;
use Canvastack\Origin\Library\Components\Table\Craft\Search\ModalRenderer;
use Illuminate\Support\Facades\Log;

/**
 * Search - Main orchestrator for table search functionality
 * 
 * Created on 24 Apr 2021
 * Time Created : 20:51:52
 * Refactored: Split into multiple classes for better maintainability
 *
 * @filesource Search.php
 *
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class Search {
	
	// Backward compatibility: Keep constants in main class
	const EXPORT_CLASS_PREFIX = 'export_';
	const FILTER_MODAL_SUFFIX = 'CanvaStackFILTERmodalBOX';
	const FILTER_FIELD_SUFFIX = 'CanvaStackFILTERField';
	const SCRIPT_NODE_PREFIX = 'canvastackScriptNode::';
	const LOADER_PREFIX = 'CanvaStackInpLdr';
	
	const VALID_FIELD_TYPES = [
		'selectbox', 'text', 'date', 'datetime', 
		'time', 'daterange', 'checkbox', 'radiobox',
		'string', 'smallint'
	];
	
	// Component instances
	private $config;
	private $queryBuilder;
	private $formGenerator;
	private $scriptGenerator;
	private $modalRenderer;
	
	// Original properties (for backward compatibility)
	private $model;
	private $filters;
	private $input_relations;
	private $sql;
	private $table;
	private $tableFromView = false;
	private $info;
	private $searchConnection;
	private $model_filters = [];
	private $data = [];
	private $html = false;
	
	// Public property (backward compatibility)
	public $add_scripts = [];
	
	/**
	 * Constructor - Initialize Search component
	 *
	 * @param string $info Component identifier
	 * @param string|null $model Model class name
	 * @param array $filters Filter configuration
	 * @param string|null $sql Custom SQL query
	 * @param string|null $connection Database connection name
	 * @param array $filterQuery Additional filter query
	 * @throws \InvalidArgumentException If invalid parameters provided
	 */
	public function __construct($info, $model = null, $filters = [], $sql = null, $connection = null, $filterQuery = []) {
		// Validate inputs
		$this->validateConstructorInputs($info, $filters, $connection);
		
		if (!empty($connection)) {
			$this->searchConnection = $connection;
		}
		
		if (isset($filters['table_name']) && canvastack_string_contained($filters['table_name'], 'view_')) {
			$this->tableFromView = true;
		}
		
		$this->info = $info;
		
		if (!empty($model)) {
			$model = new $model();
		}
		
		if (!empty($filters['filter_model'])) {
			$this->model_filters = $filters['filter_model'];
			$this->model = $model->where($this->model_filters);
		} else {
			$this->model = $model;
		}
		
		$this->table = $filters['table_name'] ?? '';
		$this->filters = $filters;
		$this->sql = $sql;
		
		// Initialize components
		$this->config = new SearchConfig($info, $this->table, $filters, $connection);
		$this->queryBuilder = new QueryBuilder($this->config);
		$this->formGenerator = new FormGenerator($this->config);
		$this->scriptGenerator = new ScriptGenerator($this->config);
		$this->modalRenderer = new ModalRenderer($this->config);
		
		if (!empty($filters['filter_groups'])) {
			$this->getFilterData($filters['filter_groups']);
		}
		
		if (!empty($filterQuery)) {
			$this->filters['filter_query'] = canvastack_filter_data_normalizer($filterQuery);
		}
	}
	
	/**
	 * Validate constructor inputs
	 *
	 * @param mixed $info
	 * @param mixed $filters
	 * @param mixed $connection
	 * @throws \InvalidArgumentException
	 */
	private function validateConstructorInputs($info, $filters, $connection) {
		if (empty($info) || !is_string($info)) {
			throw new \InvalidArgumentException('Info parameter must be a non-empty string');
		}
		
		if (!is_array($filters)) {
			throw new \InvalidArgumentException('Filters must be an array');
		}
		
		if (!empty($connection) && !is_string($connection)) {
			throw new \InvalidArgumentException('Connection must be a string');
		}
		
		// Validate required filter keys
		$requiredKeys = ['table_name', 'relations', 'foreign_keys'];
		foreach ($requiredKeys as $key) {
			if (!isset($filters[$key])) {
				throw new \InvalidArgumentException("Missing required filter key: {$key}");
			}
		}
	}
	
	/**
	 * Render search box
	 *
	 * @param string $info Component identifier
	 * @param string $table Table name
	 * @param array $fields Field list
	 * @return array Rendered data
	 */
	public function render($info, string $table, array $fields) {
		if ($this->info === $info) {
			$this->search_box($info, $table, $this->getColumnInfo($table, $fields), $this->model);
			
			$data = [];
			$data['name'] = ucwords(str_replace('-', ' ', canvastack_clean_strings($table)));
			$data['html'] = $this->html;
			
			return $data;
		}
	}
	
	/**
	 * Get filter data and build relations
	 *
	 * @param array $data Filter groups
	 * @return void
	 */
	private function getFilterData($data) {
		$all_columns = $this->collectAllColumns();
		$data = $this->processFilterRows($data, $all_columns);
		$this->data = $data;
		
		$input_relations = $this->buildInputRelations($data);
		$this->setInputRelations($input_relations);
	}
	
	/**
	 * Collect all columns from filters - optimized
	 *
	 * @return array Column mapping
	 */
	private function collectAllColumns() {
		$columns = $this->filters['columns'] ?? [];
		return !empty($columns) ? array_combine($columns, $columns) : [];
	}
	
	/**
	 * Process filter rows and build relists
	 *
	 * @param array $data Filter data
	 * @param array $all_columns All columns
	 * @return array Processed data
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
	 * @param array $data Filter data
	 * @return array Input relations
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
	 * @param array $data Filter data
	 * @return array Input relations
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
	 * @param array $data Filter data
	 * @return array Input relation
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
	 * @param array $input_relations Input relations
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
	
	/**
	 * Main search box generation
	 *
	 * @param string $info Component info
	 * @param string $tablename Table name
	 * @param array $data Column data
	 * @param mixed $model Model instance
	 * @return void
	 */
	private function search_box($info, $tablename, $data, $model) {
		$filterQuery = $this->config->getFilterQuery();
		$this->formGenerator->setupSearchFields($this->data);
		
		$script_elements = [];
		
		if (!empty($this->input_relations['type'])) {
			$script_elements = $this->processInputRelations($info, $tablename);
		} else {
			$script_elements = $this->processDefaultData($info, $data);
		}
		
		// Generate modal HTML
		$this->modalRenderer->generateModalHTML(
			$info, 
			$tablename, 
			$this->formGenerator->getFormElements(),
			$this->scriptGenerator,
			$script_elements,
			$filterQuery
		);
		
		$this->html = $this->modalRenderer->getHtml();
		$this->add_scripts = $this->scriptGenerator->getScripts();
	}
	
	/**
	 * Process input relations (SECURE - XSS protected)
	 * OPTIMIZED: Batch load all field values in single query
	 *
	 * @param string $info Component info
	 * @param string $tablename Table name
	 * @return array Script elements
	 */
	private function processInputRelations($info, $tablename) {
		$script_elements = [];
		$inputRelations = $this->prepareInputRelations();
		$this->input_relations['type'] = $inputRelations;
		
		$open_field = $this->input_relations['lists'][0] ?? null;
		
		if (!empty($open_field)) {
			// OPTIMIZATION: Batch load all field values in single query
			$allFields = array_keys($this->input_relations['type']);
			$this->formGenerator->batchLoadFieldValues($tablename, $allFields, $open_field, $this->queryBuilder);
			
			foreach ($this->input_relations['type'] as $field => $type) {
				$values = $this->formGenerator->getFieldValuesFromCache($field, $open_field);
				$attributes = $this->formGenerator->buildFieldAttributes($field, $info, $values);
				
				// FIX XSS: Escape field label
				$field_label = $this->formGenerator->escapeHtml(ucwords(canvastack_clean_strings($field, ' ')));
				$values = $this->formGenerator->prepareFieldOptions($field, $type, $values, $field_label);
				
				$this->formGenerator->generateFormElement($field, $type, $values, $attributes);
				$script_elements[$info][$field] = $type;
			}
		}
		
		return $script_elements;
	}
	
	/**
	 * Prepare input relations
	 *
	 * @return array Prepared relations
	 */
	private function prepareInputRelations() {
		$inputRelations = [];
		$searchFields = $this->formGenerator->getSearchFields();
		
		foreach ($this->input_relations['type'] as $inputFields => $inputType) {
			if (!empty($searchFields[$inputFields])) {
				$inputRelations[$searchFields[$inputFields]] = $inputType;
			}
		}
		
		return $inputRelations;
	}
	
	/**
	 * Process default data
	 *
	 * @param string $info Component info
	 * @param array $data Column data
	 * @return array Script elements
	 */
	private function processDefaultData($info, $data) {
		$script_elements = [];
		
		foreach ($data as $field => $type) {
			$this->formGenerator->generateDefaultFormElement($field, $type);
			$script_elements[$info][$field] = $type;
		}
		
		return $script_elements;
	}
	
	/**
	 * Get column information for table fields
	 *
	 * @param string $table Table name
	 * @param array $fields Field names
	 * @return array Column type information
	 */
	private function getColumnInfo(string $table, array $fields) {
		$columns = [];
		foreach ($this->getColumns($table) as $column) {
			if (false === $this->tableFromView) {
				$columns[$column] = $this->getColumnType($table, $column);
			}
		}
		
		$info = [];
		foreach ($fields as $field) {
			if (!empty($columns[$field])) {
				$info[$field] = $columns[$field];
			}
		}
		
		return $info;
	}
	
	/**
	 * Get columns for table
	 *
	 * @param string $table Table name
	 * @return array Column names
	 */
	private function getColumns($table) {
		$connection = $this->searchConnection ?? 'mysql';
		return canvastack_get_table_columns($table, $connection);
	}
	
	/**
	 * Get column type
	 *
	 * @param string $table Table name
	 * @param string $column Column name
	 * @return string Column type
	 */
	private function getColumnType($table, $column) {
		$connection = $this->searchConnection ?? 'mysql';
		return canvastack_get_table_column_type($table, $column, $connection);
	}
}
