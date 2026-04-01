<?php
namespace Canvastack\Origin\Library\Components\Table\Craft\Search\Config;

/**
 * SearchConfig - Configuration and constants for Search component
 *
 * @filesource SearchConfig.php
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class SearchConfig {
	
	// Class constants for magic strings
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
	
	private $info;
	private $table;
	private $filters;
	private $relations;
	private $foreignKeys;
	private $modelFilters;
	private $searchConnection;
	private $tableFromView;
	
	/**
	 * Constructor
	 *
	 * @param string $info Component identifier
	 * @param string $table Table name
	 * @param array $filters Filter configuration
	 * @param string|null $connection Database connection
	 */
	public function __construct($info, $table, $filters, $connection = null) {
		$this->info = $info;
		$this->table = $table;
		$this->filters = $filters;
		$this->searchConnection = $connection;
		$this->relations = $filters['relations'] ?? [];
		$this->foreignKeys = $filters['foreign_keys'] ?? [];
		$this->modelFilters = $filters['filter_model'] ?? [];
		$this->tableFromView = isset($filters['table_name']) && canvastack_string_contained($filters['table_name'], 'view_');
	}
	
	/**
	 * Get component info
	 *
	 * @return string
	 */
	public function getInfo() {
		return $this->info;
	}
	
	/**
	 * Get table name
	 *
	 * @return string
	 */
	public function getTable() {
		return $this->table;
	}
	
	/**
	 * Get filters
	 *
	 * @return array
	 */
	public function getFilters() {
		return $this->filters;
	}
	
	/**
	 * Get relations
	 *
	 * @return array
	 */
	public function getRelations() {
		return $this->relations;
	}
	
	/**
	 * Get foreign keys
	 *
	 * @return array
	 */
	public function getForeignKeys() {
		return $this->foreignKeys;
	}
	
	/**
	 * Get model filters
	 *
	 * @return array
	 */
	public function getModelFilters() {
		return $this->modelFilters;
	}
	
	/**
	 * Get database connection
	 *
	 * @return string|null
	 */
	public function getConnection() {
		return $this->searchConnection;
	}
	
	/**
	 * Check if table is from view
	 *
	 * @return bool
	 */
	public function isTableFromView() {
		return $this->tableFromView;
	}
	
	/**
	 * Get filter query
	 *
	 * @return array
	 */
	public function getFilterQuery() {
		return $this->filters['filter_query'] ?? [];
	}
}
