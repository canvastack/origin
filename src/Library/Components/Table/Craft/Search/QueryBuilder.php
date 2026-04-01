<?php
namespace Canvastack\Origin\Library\Components\Table\Craft\Search;

use Canvastack\Origin\Library\Components\Table\Craft\Search\Config\SearchConfig;
use Illuminate\Support\Facades\Log;

/**
 * QueryBuilder - SQL query building and execution for Search component
 *
 * @filesource QueryBuilder.php
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class QueryBuilder {
	
	private $config;
	private $queryCache = [];
	private $selections = [];
	
	/**
	 * Constructor
	 *
	 * @param SearchConfig $config Configuration object
	 */
	public function __construct(SearchConfig $config) {
		$this->config = $config;
	}
	
	/**
	 * Execute SQL SELECT query with error handling
	 *
	 * @param string $sql SQL query
	 * @param string|null $connection Database connection
	 * @return array Query results
	 */
	public function select($sql, $connection = null) {
		try {
			return canvastack_query($sql, 'SELECT', $connection);
		} catch (\Exception $e) {
			Log::error('Search query failed', [
				'sql' => $sql,
				'connection' => $connection,
				'error' => $e->getMessage()
			]);
			
			return [];
		}
	}
	
	/**
	 * Get selections for fields with caching
	 *
	 * @param string $table Table name
	 * @param array $fields Field names
	 * @param mixed $condition WHERE conditions
	 * @return $this
	 */
	public function selections($table, $fields = [], $condition = null) {
		// Generate cache key
		$cacheKey = md5($table . implode(',', $fields) . serialize($condition));
		
		// Check cache first
		if (isset($this->queryCache[$cacheKey])) {
			$this->selections = array_merge($this->selections, $this->queryCache[$cacheKey]);
			return $this;
		}
		
		// Check relations first
		$strfields = implode(',', $fields);
		if ($this->hasRelationData($strfields)) {
			return $this;
		}
		
		// Build query with security - FIX: Sanitize field names
		if (!empty($fields)) {
			$where = $this->buildWhereClause($table, $condition);
			$query = $this->executeSecureQuery($table, $fields, $where);
			$this->processQueryResults($query, $fields);
			
			// Cache results
			$this->queryCache[$cacheKey] = $this->selections;
		}
		
		return $this;
	}

	/**
	 * Batch selections for multiple fields - OPTIMIZED
	 * Executes single query for all fields instead of N queries
	 *
	 * @param string $table Table name
	 * @param array $fields Field names
	 * @param mixed $condition WHERE conditions
	 * @return $this
	 */
	public function batchSelections($table, $fields = [], $condition = null) {
		if (empty($fields)) {
			return $this;
		}

		// Generate cache key for batch
		$cacheKey = md5($table . implode(',', $fields) . serialize($condition));

		// Check cache first
		if (isset($this->queryCache[$cacheKey])) {
			$this->selections = array_merge($this->selections, $this->queryCache[$cacheKey]);
			return $this;
		}

		// Build single query for all fields
		$where = $this->buildWhereClause($table, $condition);
		$query = $this->executeSecureQuery($table, $fields, $where);
		$this->processQueryResults($query, $fields);

		// Cache results
		$this->queryCache[$cacheKey] = $this->selections;

		return $this;
	}

	
	/**
	 * Check if relation data exists
	 *
	 * @param string $strfields
	 * @return bool
	 */
	public function hasRelationData($strfields) {
		$relations = $this->config->getRelations();
		
		if (empty($relations)) {
			return false;
		}
		
		if (empty($relations[$strfields]['relation_data'])) {
			return false;
		}
		
		foreach ($relations[$strfields]['relation_data'] as $relationData) {
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
	public function buildWhereClause($table, $condition) {
		$where = '';
		
		if (!empty($condition)) {
			$table = $this->sanitizeIdentifier($table);
			$where = "WHERE `{$table}`.id IS NOT NULL ";
		}
		
		$modelFilters = $this->config->getModelFilters();
		if (!empty($modelFilters)) {
			$where = $this->buildModelFiltersWhere($modelFilters);
		}
		
		$filterQuery = $this->config->getFilterQuery();
		if (!empty($filterQuery)) {
			$where = $this->buildFilterQueryWhere($filterQuery);
		}
		
		return $where;
	}
	
	/**
	 * Build WHERE clause dari model filters (SECURE)
	 *
	 * @param array $modelFilters Model filters
	 * @return string
	 */
	public function buildModelFiltersWhere($modelFilters) {
		$mf_where = [];
		$n = 0;
		
		foreach ($modelFilters as $mf_field => $mf_values) {
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
	 * @param array $filterQuery Filter query
	 * @return string
	 */
	public function buildFilterQueryWhere($filterQuery) {
		$filterQueries = [];
		
		foreach ($filterQuery as $i => $fqData) {
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
	 * Execute secure query - FIX: Sanitize field names to prevent SQL injection
	 *
	 * @param string $table Table name
	 * @param array $fields Field names (changed from string to array)
	 * @param string $where WHERE clause
	 * @return array Query results
	 */
	public function executeSecureQuery($table, $fields, $where) {
		$table = $this->sanitizeIdentifier($table);
		
		// FIX SQL INJECTION: Sanitize each field name
		$sanitizedFields = [];
		foreach ($fields as $field) {
			$sanitizedFields[] = '`' . $this->sanitizeIdentifier($field) . '`';
		}
		$strfields = implode(', ', $sanitizedFields);
		
		$sql = "SELECT {$strfields} FROM `{$table}` {$where} GROUP BY {$strfields}";
		
		return $this->select($sql, $this->config->getConnection());
	}
	
	/**
	 * Process query results
	 *
	 * @param array $query
	 * @param array $fields
	 * @return void
	 */
	public function processQueryResults($query, $fields) {
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
	public function sanitizeIdentifier($identifier) {
		// Only allow alphanumeric, underscore, and dot
		return preg_replace('/[^a-zA-Z0-9_.]/', '', $identifier);
	}
	
	/**
	 * Escape value for SQL - prevent SQL injection
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function escapeValue($value) {
		if ($value === null) {
			return '';
		}
		
		// Use addslashes for basic escaping
		// In production, use DB::connection()->getPdo()->quote() for better security
		return addslashes((string)$value);
	}
	
	/**
	 * Get selections
	 *
	 * @return array
	 */
	public function getSelections() {
		return $this->selections;
	}
	
	/**
	 * Clear query cache
	 *
	 * @return void
	 */
	public function clearCache() {
		$this->queryCache = [];
	}
}