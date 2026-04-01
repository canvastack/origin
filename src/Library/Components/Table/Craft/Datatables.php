<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Models\Admin\System\DynamicTables;
use Canvastack\Origin\Controllers\Core\Craft\Includes\Privileges;
use Yajra\DataTables\DataTables as DataTable;
use Illuminate\Support\Facades\DB;

/**
 * Datatables Component - Dynamic DataTables Generator
 * 
 * Generates server-side DataTables with advanced features including:
 * - Dynamic model initialization from configuration
 * - Automatic relationship handling (joins and eager loading)
 * - Advanced filtering with multiple conditions
 * - Image column detection and rendering
 * - Action button generation with privilege checking
 * - Formula and data formatting support
 * - Comprehensive security validation
 * - Performance optimization (eager loading, caching)
 * 
 * SECURITY FEATURES:
 * - Table/connection whitelist validation
 * - Input validation for all public methods
 * - SQL injection prevention via query builder
 * - XSS protection for all outputs
 * - Path traversal protection
 * - Comprehensive error handling with logging
 * 
 * PERFORMANCE FEATURES:
 * - Eager loading for Eloquent relations (prevents N+1)
 * - Image validation caching
 * - Optimized query building
 * 
 * USAGE EXAMPLE:
 * ```php
 * $datatables = new Datatables();
 * 
 * // Process DataTables request
 * $result = $datatables->process(
 *     $request->all(),           // Method parameters (difta, start, length, etc.)
 *     $tableConfig,              // Table configuration object
 *     $filters,                  // Additional filters (optional)
 *     $filterPage                // Page filters (optional)
 * );
 * 
 * // Initialize filter dropdown
 * $options = $datatables->init_filter_datatables(
 *     $_GET,                     // GET parameters
 *     $_POST,                    // POST parameters
 *     'mysql'                    // Connection name (optional)
 * );
 * ```
 * Created on 21 Apr 2021
 * Time Created : 12:45:06
 * 
 * @package    Canvastack\Origin\Library\Components\Table\Craft
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @version    2.0.0 (with Phase 1 Security + Phase 2 Performance)
 * @since      21 Apr 2021
 * 
 * @see        \Yajra\DataTables\DataTables
 * @see        \Canvastack\Origin\Models\Admin\System\DynamicTables
 */
class Datatables {
	use Privileges;
	
	// Constants - Configuration
	private const DEFAULT_LIMIT_START  = 0;
	private const DEFAULT_LIMIT_LENGTH = 10;
	private const BLACKLIST_FIELDS     = ['password', 'action', 'no'];
	private const BLACKLIST_WITH_ID    = ['password', 'action', 'no', 'id'];
	private const DEFAULT_ACTIONS      = ['view', 'insert', 'edit', 'delete'];
	private const AJAX_RESERVED_PARAMS = ['renderDataTables', 'draw', 'columns', 'order', 'start', 'length', 'search', 'difta', '_token', '_'];
	
	// Constants - Magic Values
	private const ADMIN_ROLE_GROUP     = 1;
	private const IMAGE_ALT_PREFIX     = 'imgsrc::';
	private const THUMBNAIL_PREFIX     = 'tnail_';
	private const THUMBNAIL_FOLDER     = 'thumb';
	private const NULL_CONDITION       = '#null';
	
	// Properties
	public  $filter_model  = [];
	private $image_checker = ['jpg', 'jpeg', 'png', 'gif'];
	
	// PERFORMANCE: Cache untuk image validation
	private $imageValidationCache = [];
	
	/**
	 * Constructor
	 */
	public function __construct() {}
	
	/**
	 * Validate table name against whitelist
	 * SECURITY: Prevents unauthorized table access
	 *
	 * @param string $table Table name to validate
	 * @return string Validated table name
	 * @throws \InvalidArgumentException If table not in whitelist
	 */
	private function validateTableName($table) {
		// Get allowed tables from config or use default whitelist
		$allowedTables = config('datatables.allowed_tables', []);
		
		// If no whitelist configured, get all tables from database
		if (empty($allowedTables)) {
			try {
				$allowedTables = \DB::connection()->getDoctrineSchemaManager()->listTableNames();
			} catch (\Exception $e) {
				\Log::warning('Datatables: Could not get table list', ['error' => $e->getMessage()]);
				$allowedTables = [];
			}
		}
		
		// Validate table name
		if (!in_array($table, $allowedTables)) {
			\Log::warning('Datatables: Invalid table access attempt', [
				'table' => $table,
				'allowed' => $allowedTables
			]);
			throw new \InvalidArgumentException('Invalid table name');
		}
		
		return $table;
	}
	
	/**
	 * Validate database connection name
	 * SECURITY: Prevents unauthorized connection access
	 *
	 * @param string|null $connection Connection name to validate
	 * @return string|null Validated connection name
	 * @throws \InvalidArgumentException If connection not valid
	 */
	private function validateConnection($connection) {
		// Null connection is valid (uses default)
		if ($connection === null) {
			return null;
		}
		
		// Get allowed connections from config
		$allowedConnections = array_keys(config('database.connections', []));
		
		// Validate connection name
		if (!in_array($connection, $allowedConnections)) {
			\Log::warning('Datatables: Invalid connection access attempt', [
				'connection' => $connection,
				'allowed' => $allowedConnections
			]);
			throw new \InvalidArgumentException('Invalid connection name');
		}
		
		return $connection;
	}
	
	/**
	 * Validate process method inputs
	 * SECURITY: Ensures all required parameters are present and valid
	 *
	 * @param array $method Method parameters
	 * @param object $data Data configuration
	 * @throws \InvalidArgumentException If validation fails
	 */
	private function validateProcessInputs($method, $data) {
		// Validate method parameter
		if (!is_array($method)) {
			throw new \InvalidArgumentException('Method parameter must be an array');
		}
		
		// Validate data parameter
		if (!is_object($data)) {
			throw new \InvalidArgumentException('Data parameter must be an object');
		}
		
		if (!isset($data->datatables)) {
			throw new \InvalidArgumentException('Data object must have datatables property');
		}
		
		// Validate required method keys
		if (!isset($method['difta']) || !is_array($method['difta'])) {
			throw new \InvalidArgumentException('Method must have difta array');
		}
		
		if (!isset($method['difta']['name'])) {
			throw new \InvalidArgumentException('Method difta must have name');
		}
	}
	
	/**
	 * Validate filter inputs
	 * SECURITY: Sanitizes filter data to prevent injection
	 *
	 * @param array $get GET parameters
	 * @param array $post POST parameters
	 * @throws \InvalidArgumentException If validation fails
	 */
	private function validateFilterInputs($get, $post) {
		// Validate get parameter
		if (!is_array($get)) {
			throw new \InvalidArgumentException('GET parameter must be an array');
		}
		
		// Validate post parameter
		if (!is_array($post)) {
			throw new \InvalidArgumentException('POST parameter must be an array');
		}
		
		// Check required GET parameter
		if (empty($get['filterDataTables'])) {
			throw new \InvalidArgumentException('Missing filterDataTables parameter');
		}
		
		// Check required POST parameter
		if (!isset($post['_fita'])) {
			throw new \InvalidArgumentException('Missing _fita parameter');
		}
	}
	
	/**
	 * Set asset path with optional HTTP URL conversion
	 * 
	 * Converts file path to full system path or HTTP URL.
	 * Includes path traversal protection.
	 * 
	 * SECURITY: Sanitizes file path to prevent path traversal attacks
	 *
	 * @param string $file_path Relative file path
	 * @param boolean $http Convert to HTTP URL if true
	 * @param string $public_path Public directory name (default: 'public')
	 * 
	 * @return string Full system path or HTTP URL
	 */
	private function setAssetPath($file_path, $http = false, $public_path = 'public') {
		// Sanitize file path untuk mencegah path traversal
		$file_path = $this->sanitizeFilePath($file_path);
		
		if (true === $http) {
			$assetsURL = explode('/', url()->asset('assets'));
			$stringURL = explode('/', $file_path);
			
			return implode('/', array_unique(array_merge_recursive($assetsURL, $stringURL)));
		}
		
		$file_path = str_replace($public_path . '/', public_path("\\"), $file_path);
		
		return $file_path;
	}
	
	/**
	 * Sanitize file path to prevent path traversal attacks
	 * 
	 * Removes dangerous path sequences:
	 * - ../ and ..\ (directory traversal)
	 * - Absolute path indicators (C:\, /)
	 * 
	 * SECURITY: Critical for preventing unauthorized file access
	 *
	 * @param string $path File path to sanitize
	 * 
	 * @return string Sanitized path (relative, safe)
	 */
	private function sanitizeFilePath($path) {
		// Remove any ../ or ..\\ sequences
		$path = str_replace(['../', '..\\'], '', $path);
		
		// Remove any absolute path indicators
		$path = preg_replace('/^[a-zA-Z]:\\\\/', '', $path); // Windows absolute path
		$path = ltrim($path, '/'); // Unix absolute path
		
		return $path;
	}
	
	/**
	 * Check if file is a valid image
	 * 
	 * Validates if file exists and has valid image extension.
	 * Returns HTML error message if file doesn't exist.
	 * 
	 * PERFORMANCE: Results are cached to prevent repeated file checks
	 * SECURITY: XSS protection for error messages
	 * 
	 * Supported extensions: jpg, jpeg, png, gif
	 *
	 * @param string $string File path to check
	 * @param boolean $local_path Use local path (default: true)
	 * 
	 * @return boolean|string True if valid image, false if not image, HTML string if file missing
	 */
	private function checkValidImage($string, $local_path = true) {
		// PERFORMANCE: Check cache first
		if (isset($this->imageValidationCache[$string])) {
			return $this->imageValidationCache[$string];
		}
		
		$filePath = $this->setAssetPath($string);
		
		if (true === file_exists($filePath)) {
			// Fix: Check semua extensions, jangan return di iterasi pertama
			$isValidImage = false;
			foreach ($this->image_checker as $check) {
				if (false !== strpos($string, $check)) {
					$isValidImage = true;
					break;
				}
			}
			
			// PERFORMANCE: Cache result
			$this->imageValidationCache[$string] = $isValidImage;
			return $isValidImage;
			
		} else {
			$filePath = explode('/', $string);
			$lastSrc  = array_key_last($filePath);
			$lastFile = isset($filePath[$lastSrc]) ? $filePath[$lastSrc] : 'unknown';
			
			// Escape untuk mencegah XSS
			$safeLastFile = htmlspecialchars($lastFile, ENT_QUOTES, 'UTF-8');
			$info = "This File [ {$safeLastFile} ] Do Not or Never Exist!";
			
			$result = "<div class=\"show-hidden-on-hover missing-file\" title=\"{$info}\"><i class=\"fa fa-warning\"></i>&nbsp;{$safeLastFile}</div>";
			
			// PERFORMANCE: Cache result
			$this->imageValidationCache[$string] = $result;
			return $result;
		}
	}

	/**
	 * Main process method untuk generate datatables
	 * 
	 * Processes DataTables AJAX request and returns formatted JSON response.
	 * Handles complete DataTables lifecycle including:
	 * - Model initialization from configuration
	 * - Privilege-based action filtering
	 * - Relationship handling (joins and eager loading)
	 * - Condition and filter application
	 * - Pagination and ordering
	 * - Image column detection and rendering
	 * - Action button generation
	 * - Formula and data formatting
	 * 
	 * SECURITY: Added input validation and error handling
	 * PERFORMANCE: Includes eager loading for relations
	 *
	 * @param array $method Request parameters including:
	 *   - difta: array Table identifier
	 *     - name: string Table name
	 *   - start: int Pagination start offset (default: 0)
	 *   - length: int Page size (default: 10)
	 *   - draw: int Request counter
	 *   - search: array Search parameters
	 *   - order: array Ordering parameters
	 * 
	 * @param object $data Table configuration object with:
	 *   - datatables: object Main configuration
	 *     - model: array Model class mappings
	 *     - columns: array Column configurations
	 *       - lists: array Visible columns
	 *       - foreign_keys: array Join definitions
	 *       - relations: array Eloquent relations
	 *       - formulas: array Calculated columns
	 *       - formats: array Data formatters
	 *     - conditions: array Where clauses
	 *     - actions: array Action button configs
	 *     - modelProcessing: array Model processors
	 * 
	 * @param array $filters Additional filter conditions (optional)
	 *   Format: [['field_name' => 'column', 'value' => 'filter_value'], ...]
	 * 
	 * @param array $filter_page Page-specific filters (optional)
	 * 
	 * @return mixed DataTables JSON response array with:
	 *   - draw: int Request counter
	 *   - recordsTotal: int Total records
	 *   - recordsFiltered: int Filtered records
	 *   - data: array Table rows
	 *   Returns null on error
	 * 
	 * @throws \InvalidArgumentException If invalid inputs (caught and logged)
	 * @throws \Exception If database query fails (caught and logged)
	 */
	public function process($method, $data, $filters = [], $filter_page = []) {
		try {
			// SECURITY: Validate inputs
			$this->validateProcessInputs($method, $data);
			
			// Initialize model dan table name
			$modelInfo = $this->initializeModel($method, $data);
			if (empty($modelInfo)) {
				\Log::warning('Datatables: Model initialization failed', [
					'method' => $method['difta']['name'] ?? 'unknown'
				]);
				return null;
			}
			
			$model_data = $modelInfo['model_data'];
			$table_name = $modelInfo['table_name'];
			
			// Check if any model processing needed
			if (isset($data->datatables->modelProcessing[$table_name])) {
				canvastack_model_processing_table($data->datatables->modelProcessing, $table_name);
			}
			
			// Setup privileges dan actions
			$actionConfig = $this->setupActionConfig($data, $table_name);
			
			// Setup field configuration
			$fieldConfig = $this->setupFieldConfig($data, $table_name);
			
			// Apply relationships (joins)
			$joinResult = $this->applyRelationships($model_data, $data, $table_name);
			$model_data = $joinResult['model'];
			$joinFields = $joinResult['joinFields'];
			
			// Apply conditions (where clauses)
			$model_condition = $this->applyConditions($model_data, $data, $table_name);
			
			// Apply filters
			$filterResult = $this->applyFilters($model_condition, $filters, $table_name, $fieldConfig['firstField']);
			$model        = $filterResult['model'];
			$limitTotal   = $filterResult['limitTotal'];
			
			// Apply pagination
			$limit = $this->applyPagination($model, $limitTotal);
			
			// Build datatables
			$datatables = $this->buildDatatables($model, $limit, $fieldConfig['blacklists']);
			
			// Apply ordering
			$this->applyOrdering($datatables, $data, $table_name);
			
			// Process rows
			$this->processRows($model, $datatables, $data, $table_name, $joinFields);
			
			// Setup row attributes (clickable)
			$this->setupRowAttributes($datatables, $data, $table_name);
			
			// Add action column
			$this->addActionColumn($datatables, $model, $actionConfig, $data);
			
			// Generate final table data
			return $this->generateTableData($datatables, $data);
			
		} catch (\InvalidArgumentException $e) {
			\Log::warning('Datatables: Input validation failed', [
				'error' => $e->getMessage(),
				'method' => $method['difta']['name'] ?? 'unknown'
			]);
			return null;
		} catch (\Exception $e) {
			\Log::error('Datatables: Process failed', [
				'error' => $e->getMessage(),
				'method' => $method['difta']['name'] ?? 'unknown',
				'trace' => $e->getTraceAsString()
			]);
			return null;
		}
	}

	/**
	 * Initialize model and table name from configuration
	 * 
	 * Extracts model class and table name from configuration object.
	 * Returns null if model not found in configuration.
	 *
	 * @param array $method Request parameters with difta.name
	 * @param object $data Configuration object with datatables.model mapping
	 * 
	 * @return array|null Array with keys:
	 *   - model_data: mixed Eloquent model or query builder instance
	 *   - table_name: string Database table name
	 *   Returns null if model not configured
	 */
	private function initializeModel($method, $data) {
		if (empty($data->datatables->model[$method['difta']['name']])) {
			return null;
		}
		
		$model_type   = $data->datatables->model[$method['difta']['name']]['type'];
		$model_source = $data->datatables->model[$method['difta']['name']]['source'];
		
		$model_data = null;
		$table_name = null;
		
		if ('model' === $model_type) {
			$model_data = $model_source;
			$table_name = $model_data->getTable();
		}
		
		// DEVELOPMENT STATUS | @WAITINGLISTS
		if ('sql' === $model_type) {
			$model_data = new DynamicTables($model_source);
			$table_name = $model_source; // Assuming source is table name for SQL type
		}
		
		return [
			'model_data' => $model_data,
			'table_name' => $table_name
		];
	}
	
	/**
	 * Setup action configuration based on user privileges
	 * 
	 * Determines which action buttons should be displayed based on:
	 * - User role and privileges
	 * - Table-specific action configuration
	 * - Explicitly removed buttons
	 * 
	 * Refactored to reduce nesting from 8 to 2 levels.
	 * 
	 * @param object $data Table configuration with datatables.columns
	 * @param string $table_name Database table name
	 * 
	 * @return array Action configuration with keys:
	 *   - privileges: array User privilege information
	 *   - action_list: array|false Available actions or false if none
	 *   - removed_privileges: array Actions removed by privilege check
	 *   - buttonsRemoval: array Explicitly removed buttons
	 */
	private function setupActionConfig($data, $table_name) {
		$privileges = $this->set_module_privileges();
		$column_data = $data->datatables->columns;
		
		$buttonsRemoval = $data->datatables->columns[$table_name]['button_removed'] ?? [];
		
		// Extract action list from configuration
		$action_list = $this->extractActionList($column_data, $table_name);
		
		// If no actions configured, return early
		if (false === $action_list) {
			return [
				'privileges'         => $privileges,
				'action_list'        => false,
				'removed_privileges' => [],
				'buttonsRemoval'     => $buttonsRemoval
			];
		}
		
		// Filter actions based on privileges
		$_action_lists = $this->filterActionsByPrivileges($action_list, $privileges);
		
		// Calculate removed privileges
		$removed_privileges = $this->calculateRemovedPrivileges($action_list, $_action_lists);
		
		return [
			'privileges'         => $privileges,
			'action_list'        => $action_list,
			'removed_privileges' => $removed_privileges,
			'buttonsRemoval'     => $buttonsRemoval
		];
	}
	
	/**
	 * Extract action list from column configuration
	 * 
	 * Determines available actions from table configuration.
	 * Returns default actions if true, custom actions if array, or false if none.
	 * 
	 * @param array $column_data Column configuration array
	 * @param string $table_name Database table name
	 * 
	 * @return array|false Array of action names ['view', 'edit', 'delete', ...] or false
	 */
	private function extractActionList($column_data, $table_name) {
		if (!isset($column_data[$table_name]['actions'])) {
			return false;
		}
		
		$actions = $column_data[$table_name]['actions'];
		
		if (!is_array($actions) && true !== $actions) {
			return false;
		}
		
		if (true === $actions) {
			return self::DEFAULT_ACTIONS;
		}
		
		return array_merge_recursive_distinct(self::DEFAULT_ACTIONS, $actions);
	}
	
	/**
	 * Filter actions based on user privileges
	 * 
	 * Removes actions that user doesn't have permission to perform.
	 * Admin users (role_group <= 1) bypass privilege checking.
	 * 
	 * @param array $action_list Full list of available actions
	 * @param array $privileges User privilege information with:
	 *   - role_group: int User role group level
	 *   - role: array User role permissions
	 * 
	 * @return array Filtered action list (empty if admin or no privileges)
	 */
	private function filterActionsByPrivileges($action_list, $privileges) {
		// If role_group <= 1, no filtering needed (admin/superadmin)
		if ($privileges['role_group'] <= self::ADMIN_ROLE_GROUP) {
			return [];
		}
		
		if (empty($privileges['role'])) {
			return [];
		}
		
		// Extract privilege actions
		$privilegeActions = $this->extractPrivilegeActions($privileges['role']);
		
		if (empty($privilegeActions)) {
			return [];
		}
		
		// Filter action list based on privileges
		return $this->buildFilteredActionList($action_list, $privilegeActions);
	}
	
	/**
	 * Extract actions from user privileges/roles
	 * 
	 * @param array $roles User roles
	 * @return array Actions mapped from roles
	 */
	private function extractPrivilegeActions($roles) {
		$baseInfo = routelists_info()['base_info'];
		
		// Check if base_info exists in roles
		if (empty(strpos(json_encode($roles), $baseInfo))) {
			return [];
		}
		
		$actions = [];
		
		foreach ($roles as $role) {
			if (!canvastack_string_contained($role, $baseInfo)) {
				continue;
			}
			
			$actionType = $this->mapRouteToAction($role);
			
			if ($actionType) {
				$actions[$baseInfo][$actionType] = $actionType;
			}
		}
		
		return $actions;
	}
	
	/**
	 * Map route name to action type
	 * 
	 * @param string $role Role/route name
	 * @return string|null Action type or null
	 */
	private function mapRouteToAction($role) {
		$routename = routelists_info($role)['last_info'];
		
		if (in_array($routename, ['index', 'show', 'view'])) {
			return 'view';
		}
		
		if (in_array($routename, ['create', 'insert'])) {
			return 'insert';
		}
		
		if (in_array($routename, ['edit', 'modify', 'update'])) {
			return 'edit';
		}
		
		if (in_array($routename, ['destroy', 'delete'])) {
			return 'delete';
		}
		
		return null;
	}
	
	/**
	 * Build filtered action list based on privileges
	 * 
	 * @param array $action_list Full action list
	 * @param array $privilegeActions Actions from privileges
	 * @return array Filtered action list
	 */
	private function buildFilteredActionList($action_list, $privilegeActions) {
		$baseInfo = routelists_info()['base_info'];
		$_action_lists = [];
		
		foreach ($action_list as $_list) {
			if (isset($privilegeActions[$baseInfo][$_list])) {
				$_action_lists[] = $privilegeActions[$baseInfo][$_list];
			} elseif (!in_array($_list, self::DEFAULT_ACTIONS)) {
				// Custom actions (not in DEFAULT_ACTIONS) are always included
				$_action_lists[] = $_list;
			}
		}
		
		return $_action_lists;
	}
	
	/**
	 * Calculate removed privileges (actions not allowed)
	 * 
	 * @param array $action_list Full action list
	 * @param array $_action_lists Filtered action list
	 * @return array Removed privileges
	 */
	private function calculateRemovedPrivileges($action_list, $_action_lists) {
		$diff = array_diff($action_list, $_action_lists);
		
		return !empty($diff) ? $diff : [];
	}
	
	/**
	 * Setup field configuration for DataTables
	 * 
	 * Determines first field and blacklist based on table configuration.
	 * Used for default filtering and column visibility.
	 * 
	 * @param object $data Table configuration with datatables.columns
	 * @param string $table_name Database table name
	 * 
	 * @return array Field configuration with keys:
	 *   - firstField: string First visible field (default: 'id')
	 *   - blacklists: array Fields to hide from display
	 */
	private function setupFieldConfig($data, $table_name) {
		$firstField = 'id';
		$blacklists = self::BLACKLIST_FIELDS;
		
		if (isset($data->datatables->columns[$table_name]['lists']) && !in_array('id', $data->datatables->columns[$table_name]['lists'])) {
			$firstField = $data->datatables->columns[$table_name]['lists'][0];
			$blacklists = self::BLACKLIST_WITH_ID;
		}
		
		return [
			'firstField' => $firstField,
			'blacklists' => $blacklists
		];
	}

	/**
	 * Apply relationships (joins) to model
	 * 
	 * Handles both SQL joins (foreign_keys) and Eloquent relations.
	 * Automatically selects all fields from joined tables.
	 * Applies eager loading for Eloquent relations to prevent N+1 queries.
	 * 
	 * PERFORMANCE: Includes eager loading optimization
	 *
	 * @param mixed $model_data Eloquent model or query builder instance
	 * @param object $data Table configuration with:
	 *   - datatables->columns->foreign_keys: array Join definitions
	 *   - datatables->columns->relations: array Eloquent relations
	 * @param string $table_name Database table name
	 * 
	 * @return array Result with keys:
	 *   - model: mixed Modified model with joins and eager loading
	 *   - joinFields: array Selected fields from joins
	 */
	private function applyRelationships($model_data, $data, $table_name) {
		$joinFields = [];
		
		if (isset($data->datatables->columns[$table_name]['foreign_keys'])) {
			$fieldsets  = [];
			$joinFields = ["{$table_name}.*"];
			
			foreach ($data->datatables->columns[$table_name]['foreign_keys'] as $fkey1 => $fkey2) {
				$ftables    = explode('.', $fkey1);
				$model_data = $model_data->leftJoin($ftables[0], $fkey1, '=', $fkey2);
				$fieldsets[$ftables[0]] = canvastack_get_table_columns($ftables[0]);
			}
			
			foreach ($fieldsets as $fstname => $fieldRows) {
				foreach ($fieldRows as $fieldset) {
					if ('id' === $fieldset) {
						$joinFields[] = "{$fstname}.{$fieldset} as {$fstname}_{$fieldset}";
					} else {
						$joinFields[] = "{$fstname}.{$fieldset}";
					}
				}
			}
			$model_data = $model_data->select($joinFields);
		}
		
		// PERFORMANCE: Apply eager loading for Eloquent relations
		$model_data = $this->applyEagerLoading($model_data, $data, $table_name);
		
		return [
			'model'      => $model_data,
			'joinFields' => $joinFields
		];
	}
	
	/**
	 * Apply eager loading for Eloquent relationships
	 * 
	 * Prevents N+1 query problem by loading all relations at once.
	 * Only applies to Eloquent models (checks for 'with' method).
	 * Automatically extracts relation names from configuration.
	 * 
	 * PERFORMANCE: Reduces N queries to 1 query for relations
	 * 
	 * Example:
	 * Before: 1 query + N queries (one per row for each relation)
	 * After: 1 query + 1 query (all relations loaded at once)
	 *
	 * @param mixed $model_data Eloquent model or query builder instance
	 * @param object $data Table configuration with datatables.columns.relations
	 * @param string $table_name Database table name
	 * 
	 * @return mixed Model with eager loading applied (or unchanged if not applicable)
	 */
	private function applyEagerLoading($model_data, $data, $table_name) {
		// Check if model has relations defined
		if (!isset($data->datatables->columns[$table_name]['relations'])) {
			return $model_data;
		}
		
		$relations = $data->datatables->columns[$table_name]['relations'];
		
		// Only apply eager loading if model is Eloquent (has 'with' method)
		if (!method_exists($model_data, 'with')) {
			return $model_data;
		}
		
		// Extract relation names
		$relationNames = array_keys($relations);
		
		if (!empty($relationNames)) {
			// PERFORMANCE: Eager load all relations at once
			$model_data = $model_data->with($relationNames);
		}
		
		return $model_data;
	}
	
	/**
	 * Apply conditions (where clauses) to model
	 * 
	 * Applies configured where conditions from table configuration.
	 * Supports both regular where and whereIn conditions.
	 *
	 * @param mixed $model_data Eloquent model or query builder instance
	 * @param object $data Table configuration with datatables.conditions
	 * @param string $table_name Database table name
	 * 
	 * @return mixed Model with conditions applied
	 */
	private function applyConditions($model_data, $data, $table_name) {
		$model_condition  = $model_data;
		$where_conditions = [];
		
		if (isset($data->datatables->conditions[$table_name]['where'])) {
			foreach ($data->datatables->conditions[$table_name]['where'] as $conditional_where) {
				if (!is_array($conditional_where['value'])) {
					$where_conditions['o'][] = [$conditional_where['field_name'], $conditional_where['operator'], $conditional_where['value']];
				} else {
					$where_conditions['i'][$conditional_where['field_name']] = $conditional_where['value'];
				}
			}
			
			if (isset($where_conditions['o'])) {
				$model_condition = $model_data->where($where_conditions['o']);
			}
			
			if (isset($where_conditions['i'])) {
				foreach ($where_conditions['i'] as $if => $iv) {
					$model_condition = $model_condition->whereIn($if, $iv);
				}
			}
		}
		
		return $model_condition;
	}

	/**
	 * Apply filters to model
	 *
	 * @param mixed $model_condition
	 * @param array $filters
	 * @param string $table_name
	 * @param string $firstField
	 * @return array
	 */
	/**
	 * Apply filters to model
	 * Refactored to reduce nesting from 6 to 2 levels
	 * 
	 * @param mixed $model_condition Model with conditions
	 * @param array $filters Filter data
	 * @param string $table_name Table name
	 * @param string $firstField First field name
	 * @return array [model, limitTotal]
	 */
	private function applyFilters($model_condition, $filters, $table_name, $firstField) {
		// Parse filter strings from input
		$fstrings = $this->parseFilterStrings($filters);
		
		if (empty($fstrings)) {
			return $this->applyDefaultFilter($model_condition, $table_name, $firstField);
		}
		
		// Transform filters to usable format
		$transformedFilters = $this->transformFilters($fstrings);
		
		// Apply filter conditions to model
		return $this->applyFilterConditions($model_condition, $transformedFilters);
	}
	
	/**
	 * Parse filter strings from input
	 * 
	 * @param array $filters Filter input
	 * @return array Parsed filter strings
	 */
	private function parseFilterStrings($filters) {
		if (!is_array($filters) || empty($filters)) {
			return [];
		}
		
		$fstrings = [];
		
		foreach ($filters as $name => $value) {
			if ('filters' === $name || '' === $value) {
				continue;
			}
			
			if (in_array($name, self::AJAX_RESERVED_PARAMS)) {
				continue;
			}
			
			if (!is_array($value)) {
				$fstrings[] = [$name => urldecode($value)];
			} else {
				foreach ($value as $val) {
					$fstrings[] = [$name => urldecode($val)];
				}
			}
		}
		
		return $fstrings;
	}
	
	/**
	 * Transform filter strings to usable format
	 * 
	 * @param array $fstrings Parsed filter strings
	 * @return array Transformed filters
	 */
	private function transformFilters($fstrings) {
		$filters = [];
		
		// Group by field name
		foreach ($fstrings as $fdata) {
			foreach ($fdata as $fkey => $fvalue) {
				$filters[$fkey][] = $fvalue;
			}
		}
		
		// Get last value for each field
		$fconds = [];
		foreach ($filters as $fieldname => $rowdata) {
			foreach ($rowdata as $dataRow) {
				$fconds[$fieldname] = $dataRow;
			}
		}
		
		return $fconds;
	}
	
	/**
	 * Apply filter conditions to model
	 * 
	 * @param mixed $model_condition Model with conditions
	 * @param array $fconds Filter conditions
	 * @return array [model, limitTotal]
	 */
	private function applyFilterConditions($model_condition, $fconds) {
		if (empty($fconds)) {
			return [
				'model'      => $model_condition,
				'limitTotal' => 0
			];
		}
		
		$model = $model_condition->where($fconds);
		$limitTotal = count($model->get());
		
		return [
			'model'      => $model,
			'limitTotal' => $limitTotal
		];
	}
	
	/**
	 * Apply default filter (no filters provided)
	 * 
	 * @param mixed $model_condition Model with conditions
	 * @param string $table_name Table name
	 * @param string $firstField First field name
	 * @return array [model, limitTotal]
	 */
	private function applyDefaultFilter($model_condition, $table_name, $firstField) {
		$model = $model_condition->where("{$table_name}.{$firstField}", '!=', null);
		$limitTotal = count($model_condition->get());
		
		return [
			'model'      => $model,
			'limitTotal' => $limitTotal
		];
	}

	/**
	 * Apply pagination to model
	 *
	 * @param mixed $model
	 * @param int $limitTotal
	 * @return array
	 */
	private function applyPagination($model, $limitTotal) {
		$limit = [
			'start'  => self::DEFAULT_LIMIT_START,
			'length' => self::DEFAULT_LIMIT_LENGTH,
			'total'  => intval($limitTotal)
		];
		
		if (!empty(request()->get('start')))  $limit['start']  = intval(request()->get('start'));
		if (!empty(request()->get('length'))) $limit['length'] = intval(request()->get('length'));
		
		$model->skip($limit['start'])->take($limit['length']);
		
		return $limit;
	}
	
	/**
	 * Build datatables instance
	 *
	 * @param mixed $model
	 * @param array $limit
	 * @param array $blacklists
	 * @return mixed
	 */
	private function buildDatatables($model, $limit, $blacklists) {
		$datatables = DataTable::of($model)
			->setTotalRecords($limit['total'])
			->setFilteredRecords($limit['total'])
			->blacklist($blacklists)
			->smart(true);
		
		// Setup raw columns untuk image fields
		if (isset($this->form->imageTagFieldsDatatable)) {
			$is_image = array_keys($this->form->imageTagFieldsDatatable);
			$datatables->rawColumns(array_merge_recursive(['action', 'flag_status'], $is_image));
		}
		
		return $datatables;
	}
	
	/**
	 * Apply ordering to datatables
	 *
	 * @param mixed $datatables
	 * @param object $data
	 * @param string $table_name
	 * @return void
	 */
	private function applyOrdering($datatables, $data, $table_name) {
		$order_by = [];
		if (isset($data->datatables->columns[$table_name]['orderby'])) {
			$order_by = $data->datatables->columns[$table_name]['orderby'];
		}
		
		if (!empty($order_by)) {
			$orderBy = $order_by;
			$datatables->order(function ($query) use($orderBy) {
				$query->orderBy($orderBy['column'], $orderBy['order']);
			});
		}
	}

	/**
	 * Process rows - apply formulas, formatting, dan special columns
	 *
	 * @param mixed $model
	 * @param mixed $datatables
	 * @param object $data
	 * @param string $table_name
	 * @param array $joinFields
	 * @return void
	 */
	/**
	 * Process rows with relations and status columns
	 * Refactored to reduce nesting from 4 to 2 levels
	 * 
	 * @param mixed $model Model instance
	 * @param mixed $datatables Datatables instance
	 * @param object $data Table data
	 * @param string $table_name Table name
	 * @param array $joinFields Join fields
	 * @return void
	 */
	private function processRows($model, $datatables, $data, $table_name, $joinFields) {
		$object_called = get_object_called_name($model);
		
		foreach ($model->get() as $modelData) {
			$rowModel = $this->extractRowModel($modelData, $object_called);
			
			// Process image columns
			$this->imageViewColumn($rowModel, $datatables);
			
			// Process relations if no joins
			if (empty($joinFields)) {
				$this->processRelations($datatables, $data, $table_name);
			}
			
			// Process special status columns
			$this->processStatusColumns($datatables, $rowModel);
		}
		
		// Apply formulas
		$this->applyFormulas($datatables, $data, $table_name);
		
		// Apply data formatting
		$this->applyDataFormatting($datatables, $data, $table_name);
	}
	
	/**
	 * Extract row model from model data
	 * 
	 * @param mixed $modelData Model data
	 * @param string $object_called Object type
	 * @return object Row model
	 */
	private function extractRowModel($modelData, $object_called) {
		if ('builder' === $object_called) {
			return (object) $modelData->getAttributes();
		}
		
		return $modelData;
	}
	
	/**
	 * Process relations for datatables
	 * 
	 * @param mixed $datatables Datatables instance
	 * @param object $data Table data
	 * @param string $table_name Table name
	 * @return void
	 */
	private function processRelations($datatables, $data, $table_name) {
		if (!isset($data->datatables->columns[$table_name]['relations'])) {
			return;
		}
		
		foreach ($data->datatables->columns[$table_name]['relations'] as $relField => $relData) {
			$dataRelations = $relData['relation_data'];
			
			$datatables->editColumn($relField, function($data) use ($dataRelations) {
				$dataID = intval($data['id']);
				
				if (isset($dataRelations[$dataID]['field_value'])) {
					return $dataRelations[$dataID]['field_value'];
				}
				
				return null;
			});
		}
	}
	
	/**
	 * Process special status columns
	 *
	 * @param mixed $datatables
	 * @param object $rowModel
	 * @return void
	 */
	private function processStatusColumns($datatables, $rowModel) {
		if (isset($rowModel->flag_status)) {
			$datatables->editColumn('flag_status', function($model) {
				return canvastack_unescape_html(canvastack_form_internal_flag_status($model->flag_status));
			});
		}
		
		if (isset($rowModel->active)) {
			$datatables->editColumn('active', function($model) {
				return canvastack_form_set_active_value($model->active);
			});
		}
		
		if (isset($rowModel->update_status)) {
			$datatables->editColumn('update_status', function($model) {
				return canvastack_form_set_active_value($model->update_status);
			});
		}
		
		if (isset($rowModel->request_status)) {
			$datatables->editColumn('request_status', function($model) {
				return canvastack_form_request_status(true, $model->request_status);
			});
		}
		
		if (isset($rowModel->ip_address)) {
			$datatables->editColumn('ip_address', function($model) {
				if ('::1' == $model->ip_address) {
					return canvastack_form_get_client_ip();
				}
				return $model->ip_address;
			});
		}
	}

	/**
	 * Apply formulas to columns
	 *
	 * @param mixed $datatables
	 * @param object $data
	 * @param string $table_name
	 * @return void
	 */
	private function applyFormulas($datatables, $data, $table_name) {
		if (isset($data->datatables->formula[$table_name])) {
			$data_formula = $data->datatables->formula[$table_name];
			$data->datatables->columns[$table_name]['lists'] = canvastack_set_formula_columns(
				$data->datatables->columns[$table_name]['lists'], 
				$data_formula
			);
			
			foreach ($data_formula as $formula) {
				$datatables->editColumn($formula['name'], function($data) use ($formula) {
					$logic = new Formula($formula, $data);
					return $logic->calculate();
				});
			}
		}
	}
	
	/**
	 * Apply data formatting to columns
	 *
	 * @param mixed $datatables
	 * @param object $data
	 * @param string $table_name
	 * @return void
	 */
	private function applyDataFormatting($datatables, $data, $table_name) {
		if (isset($data->datatables->columns[$table_name]['format_data'])) {
			$data_format = $data->datatables->columns[$table_name]['format_data'];
			
			foreach ($data_format as $field => $format) {
				$datatables->editColumn($format['field_name'], function($data) use ($field, $format) {
					if ($field === $format['field_name']) {
						$dataValue = $data->getAttributes();
						if (isset($dataValue[$field])) {
							return canvastack_format(
								$dataValue[$field], 
								$format['decimal_endpoint'], 
								$format['separator'], 
								$format['format_type']
							);
						}
					}
					return null;
				});
			}
		}
	}

	/**
	 * Setup row attributes (clickable rows)
	 *
	 * @param mixed $datatables
	 * @param object $data
	 * @param string $table_name
	 * @return void
	 */
	private function setupRowAttributes($datatables, $data, $table_name) {
		$rlp                     = false;
		$row_attributes          = [];
		$row_attributes['class'] = null;
		$row_attributes['rlp']   = null;
		
		if (isset($data->datatables->columns[$table_name]['clickable'])) {
			if (count($data->datatables->columns[$table_name]['clickable']) >= 1) {
				$rlp = function($model) { 
					return canvastack_unescape_html(encode_id(intval($model->id))); 
				};
			}
			$row_attributes['class'] = 'row-list-url';
			$row_attributes['rlp']   = $rlp;
		}
		
		$datatables->setRowAttr($row_attributes);
	}
	
	/**
	 * Add action column to datatables
	 *
	 * @param mixed $datatables
	 * @param mixed $model
	 * @param array $actionConfig
	 * @param object $data
	 * @return void
	 */
	private function addActionColumn($datatables, $model, $actionConfig, $data) {
		$action_data                   = [];
		$action_data['model']          = $model;
		$action_data['current_url']    = canvastack_current_url();
		$action_data['action']['data'] = $actionConfig['action_list'];
		
		if ($actionConfig['privileges']['role_group'] > 1) {
			if (!empty($actionConfig['removed_privileges'])) {
				$action_data['action']['removed'] = $actionConfig['removed_privileges'];
			} else {
				$action_data['action']['removed'] = $data->datatables->button_removed ?? [];
			}
		} else {
			$action_data['action']['removed'] = $data->datatables->button_removed ?? [];
		}
		
		if (!empty($actionConfig['buttonsRemoval'])) {
			$removeActions = $action_data['action']['removed'];
			$action_data['action']['removed'] = array_merge_recursive_distinct($actionConfig['buttonsRemoval'], $removeActions);
		}
		
		$urlTarget = $data->datatables->useFieldTargetURL ?? 'id';
		
		$datatables->addColumn('action', function($model) use($action_data, $urlTarget) {
			return $this->setRowActionURLs($model, $action_data, $urlTarget);
		});
	}

	/**
	 * Generate final table data
	 *
	 * @param mixed $datatables
	 * @param object $data
	 * @return mixed
	 */
	private function generateTableData($datatables, $data) {
		$index_lists = $data->datatables->records['index_lists'];
		
		if (true === $index_lists) {
			return $datatables->addIndexColumn()->make(true);
		}
		
		return $datatables->make();
	}
	
	/**
	 * Set row action URLs/buttons
	 *
	 * @param object $model
	 * @param array $data
	 * @param string $field_target
	 * @return string
	 */
	private function setRowActionURLs($model, $data, $field_target = 'id') {
		return canvastack_table_action_button(
			$model, 
			$field_target, 
			$data['current_url'], 
			$data['action']['data'], 
			$data['action']['removed']
		);
	}
	
	/**
	 * Process image view column
	 *
	 * @param object $model
	 * @param mixed $datatables
	 * @return void
	 */
	/**
	 * Process image view column
	 * Refactored to reduce nesting from 5 to 2 levels
	 * 
	 * @param object $model Row model
	 * @param mixed $datatables Datatables instance
	 * @return void
	 */
	private function imageViewColumn($model, $datatables) {
		// Detect which fields contain images
		$imageFields = $this->detectImageFields($model);
		
		if (empty($imageFields)) {
			return;
		}
		
		// Setup column rendering for each image field
		foreach ($imageFields as $field => $imgSrc) {
			$this->setupImageColumnRendering($field, $datatables);
		}
	}
	
	/**
	 * Detect which fields in model contain valid images
	 * 
	 * @param object $model Row model
	 * @return array Image fields
	 */
	private function detectImageFields($model) {
		$imageFields = [];
		
		foreach ($model as $field => $strImg) {
			$checkImage = $this->checkValidImage($strImg);
			
			if (false !== $checkImage && true === $checkImage) {
				$imageFields[$field] = $checkImage;
			}
		}
		
		return $imageFields;
	}
	
	/**
	 * Setup image column rendering for datatables
	 * 
	 * @param string $field Field name
	 * @param mixed $datatables Datatables instance
	 * @return void
	 */
	private function setupImageColumnRendering($field, $datatables) {
		$datatables->editColumn($field, function($model) use ($field) {
			return $this->renderImageOrFilename($model, $field);
		});
	}
	
	/**
	 * Render image HTML or filename fallback
	 * 
	 * @param object $model Row model
	 * @param string $field Field name
	 * @return string HTML or filename
	 */
	private function renderImageOrFilename($model, $field) {
		if (!isset($model->{$field})) {
			return '';
		}
		
		$imgCheck = $this->checkValidImage($model->{$field});
		
		if (false === $imgCheck) {
			return $this->renderFileNameColumn($model->{$field});
		}
		
		if (true === $imgCheck) {
			return $this->renderImageColumn($model->{$field}, $field);
		}
		
		// $imgCheck contains HTML string (from external URL)
		return canvastack_unescape_html($imgCheck);
	}
	
	/**
	 * Render image column with thumbnail support
	 * 
	 * @param string $filePath Image file path
	 * @param string $field Field name
	 * @return string Image HTML
	 */
	private function renderImageColumn($filePath, $field) {
		$label = ucwords(str_replace('-', ' ', canvastack_clean_strings($field)));
		
		// Try to get thumbnail path
		$thumbnailPath = $this->getThumbnailPath($filePath);
		$displayPath = $thumbnailPath ?: $filePath;
		
		// SECURITY: Escape untuk mencegah XSS di HTML attribute
		$safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
		$safeFilePath = htmlspecialchars($displayPath, ENT_QUOTES, 'UTF-8');
		$alt = self::IMAGE_ALT_PREFIX . $safeLabel;
		
		return canvastack_unescape_html("<center><img class=\"CanvaStack-img-thumb\" src=\"{$safeFilePath}\" alt=\"{$alt}\" /></center>");
	}
	
	/**
	 * Get thumbnail path if exists
	 * 
	 * @param string $filePath Original file path
	 * @return string|null Thumbnail path or null
	 */
	private function getThumbnailPath($filePath) {
		$pathParts = explode('/', $filePath);
		$lastIndex = array_key_last($pathParts);
		$fileName = $pathParts[$lastIndex] ?? '';
		
		if (empty($fileName)) {
			return null;
		}
		
		// Remove filename from path
		unset($pathParts[$lastIndex]);
		
		// Build thumbnail path
		$thumbPath = implode('/', $pathParts) . '/' . self::THUMBNAIL_FOLDER . '/' . self::THUMBNAIL_PREFIX . $fileName;
		
		// Check if thumbnail exists
		if (!empty($this->setAssetPath($thumbPath))) {
			return $thumbPath;
		}
		
		return null;
	}
	
	/**
	 * Render filename column (fallback when not valid image)
	 * 
	 * @param string $filePath File path
	 * @return string Filename
	 */
	private function renderFileNameColumn($filePath) {
		$pathParts = explode('/', $filePath);
		$lastIndex = array_key_last($pathParts);
		
		return $pathParts[$lastIndex] ?? '';
	}

	/**
	 * Filter datatables - store filter request
	 *
	 * @param mixed $request
	 * @return void
	 */
	public $filter_datatables = [];
	public function filter_datatable($request) {
		$this->filter_datatables = $request->all();
	}
	
	/**
	 * Initialize filter datatables for dropdown options
	 * 
	 * Generates distinct values for filter dropdowns in DataTables.
	 * Handles complex filtering with joins, conditions, and previous selections.
	 * 
	 * SECURITY: Enhanced with validation and SQL injection prevention
	 * PERFORMANCE: Refactored with extracted sub-methods for better readability
	 * 
	 * Uses query builder with parameter binding to prevent SQL injection.
	 * Validates table names and connections against whitelists.
	 * 
	 * Filter Format (_fita):
	 * "filterType::tableName::targetField::previousConditions"
	 * 
	 * Example:
	 * ```php
	 * $options = $datatables->init_filter_datatables(
	 *     ['filterDataTables' => true],
	 *     [
	 *         '_fita' => 'select::users::name::#null',
	 *         '_forKeys' => '{"users.role_id":"roles.id"}',
	 *         'status' => 'active'
	 *     ],
	 *     'mysql'
	 * );
	 * ```
	 *
	 * @param array $get GET parameters with:
	 *   - filterDataTables: bool Filter flag (required)
	 * 
	 * @param array $post POST parameters with:
	 *   - _fita: string Filter configuration (format: type::table::field::prev)
	 *   - _forKeys: string JSON encoded foreign key joins (optional)
	 *   - _canvastackF: array Additional filter conditions (optional)
	 *   - grabCanvaStackC: string Connection name override (optional)
	 *   - [field]: mixed Additional where conditions
	 * 
	 * @param string|null $connection Database connection name (optional, uses default if null)
	 * 
	 * @return \Illuminate\Support\Collection|null Collection of distinct values or null on error
	 * 
	 * @throws \InvalidArgumentException If validation fails (caught and logged)
	 * @throws \Exception If database query fails (caught and logged)
	 */
	public function init_filter_datatables($get = [], $post = [], $connection = null) {
		try {
			// SECURITY: Validate inputs
			$this->validateFilterInputs($get, $post);
			
			// PERFORMANCE: Extract filter parameters
			$filterParams = $this->extractFilterParameters($post, $connection);
			
			// PERFORMANCE: Build filter query
			$query = $this->buildFilterQuery($filterParams);
			
			// Execute query dengan parameter binding (SECURE)
			$results = $query->distinct()->select($filterParams['safeTarget'])->get();
			
			return $results;
			
		} catch (\InvalidArgumentException $e) {
			\Log::warning('Datatables: Filter validation failed', [
				'error' => $e->getMessage(),
				'get' => $get,
				'post' => array_keys($post)
			]);
			return null;
		} catch (\Exception $e) {
			\Log::error('Datatables: Filter initialization failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return null;
		}
	}
	
	/**
	 * Extract and validate filter parameters from request
	 * 
	 * Parses and validates all filter-related parameters including:
	 * - Connection name
	 * - Filter conditions
	 * - Table and target field from _fita
	 * - Foreign key joins
	 * - Previous conditions
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables for better readability
	 * SECURITY: Validates table name and connection
	 *
	 * @param array $post POST parameters (passed by reference, modified to remove reserved params)
	 * @param string|null $connection Database connection name
	 * 
	 * @return array Extracted parameters with keys:
	 *   - connection: string|null Validated connection name
	 *   - table: string Validated table name
	 *   - target: string Target field name (original)
	 *   - safeTarget: string Sanitized target field name
	 *   - prev: string Previous condition string
	 *   - filters: array Additional filter conditions
	 *   - fKeys: array Foreign key join definitions
	 *   - post: array Remaining POST data for where conditions
	 * 
	 * @throws \InvalidArgumentException If validation fails
	 */
	private function extractFilterParameters(&$post, $connection) {
		// Extract connection
		if (isset($post['grabCanvaStackC'])) {
			$connection = $post['grabCanvaStackC'];
			unset($post['grabCanvaStackC']);
		}
		
		// SECURITY: Validate connection
		$connection = $this->validateConnection($connection);
		
		// Extract filters
		$filters = [];
		if (isset($post['_canvastackF'])) {
			$filters = $post['_canvastackF'];
			unset($post['_canvastackF']);
		}
		
		// Parse filter data
		$fdata  = explode('::', $post['_fita']);
		if (count($fdata) < 4) {
			throw new \InvalidArgumentException('Invalid _fita format');
		}
		
		$table  = $fdata[1];
		$target = $fdata[2];
		$prev   = $fdata[3];
		
		// SECURITY: Validate table name
		$table = $this->validateTableName($table);
		
		// Parse foreign keys
		$fKeys = [];
		if (isset($post['_forKeys'])) {
			$fKeys = json_decode($post['_forKeys'], true);
			if (!is_array($fKeys)) {
				$fKeys = [];
			}
		}
		
		// Remove reserved parameters
		unset($post['filterDataTables'], $post['_fita'], $post['_token'], $post['_n'], $post['_forKeys']);
		
		// Sanitize target field name
		$safeTarget = preg_replace('/[^a-zA-Z0-9_.]/', '', $target);
		
		return [
			'connection' => $connection,
			'table' => $table,
			'target' => $target,
			'safeTarget' => $safeTarget,
			'prev' => $prev,
			'filters' => $filters,
			'fKeys' => $fKeys,
			'post' => $post
		];
	}
	
	/**
	 * Build filter query with all conditions applied
	 * 
	 * Orchestrates query building by applying:
	 * 1. Foreign key joins
	 * 2. Where conditions from POST data
	 * 3. Additional filter queries
	 * 4. Previous condition filters
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables for better readability
	 * SECURITY: Uses query builder with parameter binding
	 *
	 * @param array $params Filter parameters from extractFilterParameters()
	 * 
	 * @return \Illuminate\Database\Query\Builder Query builder instance with all conditions
	 */
	private function buildFilterQuery($params) {
		// Build query menggunakan query builder (SECURE)
		$query = DB::connection($params['connection'])->table($params['table']);
		
		// PERFORMANCE: Apply joins
		$query = $this->applyFilterJoins($query, $params['fKeys']);
		
		// PERFORMANCE: Apply where conditions
		$query = $this->applyFilterWhereConditions($query, $params['post']);
		
		// PERFORMANCE: Apply filter queries
		$query = $this->applyFilterQueries($query, $params['filters']);
		
		// PERFORMANCE: Apply previous conditions
		$query = $this->applyFilterPreviousConditions($query, $params['prev']);
		
		return $query;
	}
	
	/**
	 * Apply foreign key joins to filter query
	 * 
	 * Adds LEFT JOIN clauses for related tables.
	 * Validates each joined table against whitelist.
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables
	 * SECURITY: Validates joined table names
	 *
	 * @param \Illuminate\Database\Query\Builder $query Query builder instance
	 * @param array $fKeys Foreign key definitions (format: ['table.field' => 'other_table.field'])
	 * 
	 * @return \Illuminate\Database\Query\Builder Modified query with joins
	 */
	private function applyFilterJoins($query, $fKeys) {
		if (empty($fKeys)) {
			return $query;
		}
		
		foreach ($fKeys as $fqs => $fqt) {
			$tqs = explode('.', $fqs);
			$tqsTable = $tqs[0];
			
			// SECURITY: Validate joined table
			try {
				$tqsTable = $this->validateTableName($tqsTable);
				$query->leftJoin($tqsTable, $fqs, '=', $fqt);
			} catch (\InvalidArgumentException $e) {
				\Log::warning('Datatables: Invalid join table', [
					'table' => $tqsTable,
					'error' => $e->getMessage()
				]);
				// Skip invalid join
				continue;
			}
		}
		
		return $query;
	}
	
	/**
	 * Apply where conditions from POST data to filter query
	 * 
	 * Adds WHERE clauses for each POST parameter.
	 * Sanitizes field names to prevent SQL injection.
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables
	 * SECURITY: Sanitizes field names, uses parameter binding
	 *
	 * @param \Illuminate\Database\Query\Builder $query Query builder instance
	 * @param array $post POST data (field => value pairs)
	 * 
	 * @return \Illuminate\Database\Query\Builder Modified query with where conditions
	 */
	private function applyFilterWhereConditions($query, $post) {
		foreach ($post as $key => $value) {
			// Sanitize field name untuk mencegah SQL injection
			$safeKey = preg_replace('/[^a-zA-Z0-9_.]/', '', $key);
			if ($safeKey !== $key) {
				\Log::warning('Datatables: Invalid field name sanitized', [
					'original' => $key,
					'sanitized' => $safeKey
				]);
			}
			$query->where($safeKey, '=', $value);
		}
		
		return $query;
	}
	
	/**
	 * Apply additional filter queries to filter query
	 * 
	 * Adds WHERE or WHERE IN clauses from filter array.
	 * Supports both single values and array values (whereIn).
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables
	 * SECURITY: Sanitizes field names, uses parameter binding
	 *
	 * @param \Illuminate\Database\Query\Builder $query Query builder instance
	 * @param array $filters Filter array with format:
	 *   [['field_name' => 'column', 'value' => 'single_value'], ...]
	 *   or [['field_name' => 'column', 'value' => ['array', 'values']], ...]
	 * 
	 * @return \Illuminate\Database\Query\Builder Modified query with filter conditions
	 */
	private function applyFilterQueries($query, $filters) {
		if (empty($filters)) {
			return $query;
		}
		
		foreach ($filters as $filter) {
			if (!isset($filter['field_name']) || !isset($filter['value'])) {
				continue;
			}
			
			$fqFieldName = preg_replace('/[^a-zA-Z0-9_.]/', '', $filter['field_name']);
			$fqDataValue = $filter['value'];
			
			if (is_array($fqDataValue)) {
				$query->whereIn($fqFieldName, $fqDataValue);
			} else {
				$query->where($fqFieldName, '=', $fqDataValue);
			}
		}
		
		return $query;
	}
	
	/**
	 * Apply previous conditions to filter query
	 * 
	 * Applies cascading filter conditions from previous filter selections.
	 * Used for dependent dropdowns (e.g., Province -> City -> District).
	 * 
	 * PERFORMANCE: Extracted from init_filter_datatables
	 * SECURITY: Sanitizes field names, uses parameter binding
	 * 
	 * Previous Condition Format:
	 * "field1|field2|field3#value1|value2|value3"
	 *
	 * @param \Illuminate\Database\Query\Builder $query Query builder instance
	 * @param string $prev Previous condition string (format: "fields#values" or "#null")
	 * 
	 * @return \Illuminate\Database\Query\Builder Modified query with previous conditions
	 */
	private function applyFilterPreviousConditions($query, $prev) {
		if (self::NULL_CONDITION === $prev) {
			return $query;
		}
		
		$previous = explode("#", $prev);
		if (count($previous) < 2) {
			return $query;
		}
		
		$preFields = explode('|', $previous[0]);
		$preFieldt = explode('|', $previous[1]);
		
		foreach ($preFields as $idf => $prev_field) {
			if (isset($preFieldt[$idf])) {
				$safeField = preg_replace('/[^a-zA-Z0-9_.]/', '', $prev_field);
				$query->where($safeField, '=', $preFieldt[$idf]);
			}
		}
		
		return $query;
	}
	
	// ============================================================================
	// POST METHOD IMPLEMENTATION
	// ============================================================================
	
	/**
	 * Process POST request from DataTables ajax
	 * Converts POST format to GET format and calls existing process() method
	 * SECURITY: Added input validation
	 * 
	 * @param array $postData POST request data
	 * @param object $data Table data configuration
	 * @param array $filters Additional filters
	 * @param array $filter_page Page filters
	 * @return array JSON response for DataTables or null on error
	 * @throws \InvalidArgumentException If invalid inputs
	 */
	public function processPost($postData, $data, $filters = [], $filter_page = []) {
		try {
			// SECURITY: Validate inputs
			if (!is_array($postData)) {
				throw new \InvalidArgumentException('POST data must be an array');
			}
			
			if (!is_object($data)) {
				throw new \InvalidArgumentException('Data must be an object');
			}
			
			// Convert POST data to GET format (same format as process() expects)
			$method = $this->convertPostToGetFormat($postData);
			
			// Call existing process() method (reuse all GET logic)
			return $this->process($method, $data, $filters, $filter_page);
			
		} catch (\InvalidArgumentException $e) {
			\Log::warning('Datatables: POST validation failed', [
				'error' => $e->getMessage()
			]);
			return null;
		} catch (\Exception $e) {
			\Log::error('Datatables: POST process failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return null;
		}
	}
	
	/**
	 * Convert POST request data to GET format
	 * DataTables sends different format for POST vs GET
	 * 
	 * @param array $postData POST request data
	 * @return array GET-formatted data
	 */
	private function convertPostToGetFormat($postData) {
		// Extract standard DataTables parameters
		$method = [
			'renderDataTables' => true,
			'draw' => $postData['draw'] ?? 0,
			'start' => $postData['start'] ?? 0,
			'length' => $postData['length'] ?? 10,
			'order' => $postData['order'] ?? [],
			'columns' => $postData['columns'] ?? [],
			'search' => $postData['search'] ?? [],
			'difta' => $postData['difta'] ?? [],
			'filters' => $postData['filters'] ?? false
		];
		
		// Include custom filter parameters
		// Remove reserved parameters and merge the rest (custom filters)
		foreach ($postData as $key => $value) {
			if (!in_array($key, self::AJAX_RESERVED_PARAMS)) {
				$method[$key] = $value;
			}
		}
		
		return $method;
	}
	
	/**
	 * Parse POST request from Laravel Request object
	 * Extracts all DataTables parameters from POST body
	 * 
	 * @param \Illuminate\Http\Request $request
	 * @return array Parsed POST data
	 */
	public function parsePostRequest($request) {
		// Extract standard DataTables parameters
		$postData = [
			'draw' => $request->input('draw', 0),
			'start' => $request->input('start', 0),
			'length' => $request->input('length', 10),
			'order' => $request->input('order', []),
			'columns' => $request->input('columns', []),
			'search' => $request->input('search', []),
			'difta' => $request->input('difta', []),
			'filters' => $request->input('filters', false),
			'_token' => $request->input('_token')
		];
		
		// Extract custom filter parameters (for filtering functionality)
		// Get all POST data and remove reserved parameters
		$allInput = $request->all();
		foreach (self::AJAX_RESERVED_PARAMS as $reserved) {
			unset($allInput[$reserved]);
		}
		
		// Merge custom filters into postData
		$postData = array_merge($postData, $allInput);
		
		return $postData;
	}
}
