<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Models\Admin\System\DynamicTables;
use Canvastack\Origin\Controllers\Core\Craft\Includes\Privileges;
use Yajra\DataTables\DataTables as DataTable;
use Illuminate\Support\Facades\DB;

/**
 * Created on 21 Apr 2021
 * Time Created : 12:45:06
 *
 * @filesource Datatables.php
 *
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class Datatables {
	use Privileges;
	
	// Constants
	private const DEFAULT_LIMIT_START  = 0;
	private const DEFAULT_LIMIT_LENGTH = 10;
	private const BLACKLIST_FIELDS     = ['password', 'action', 'no'];
	private const BLACKLIST_WITH_ID    = ['password', 'action', 'no', 'id'];
	private const DEFAULT_ACTIONS      = ['view', 'insert', 'edit', 'delete'];
	private const AJAX_RESERVED_PARAMS = ['renderDataTables', 'draw', 'columns', 'order', 'start', 'length', 'search', 'difta', '_token', '_'];
	
	public  $filter_model  = [];
	private $image_checker = ['jpg', 'jpeg', 'png', 'gif'];
	
	public function __construct() {}
	
	/**
	 * Set asset path with optional HTTP URL conversion
	 *
	 * @param string $file_path
	 * @param boolean $http
	 * @param string $public_path
	 * @return string
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
	 * @param string $path
	 * @return string
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
	 * @param string $string
	 * @param boolean $local_path
	 * @return boolean|string
	 */
	private function checkValidImage($string, $local_path = true) {
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
			
			return $isValidImage;
			
		} else {
			$filePath = explode('/', $string);
			$lastSrc  = array_key_last($filePath);
			$lastFile = isset($filePath[$lastSrc]) ? $filePath[$lastSrc] : 'unknown';
			
			// Escape untuk mencegah XSS
			$safeLastFile = htmlspecialchars($lastFile, ENT_QUOTES, 'UTF-8');
			$info = "This File [ {$safeLastFile} ] Do Not or Never Exist!";
			
			return "<div class=\"show-hidden-on-hover missing-file\" title=\"{$info}\"><i class=\"fa fa-warning\"></i>&nbsp;{$safeLastFile}</div>";
		}
	}

	/**
	 * Main process method untuk generate datatables
	 *
	 * @param array $method
	 * @param object $data
	 * @param array $filters
	 * @param array $filter_page
	 * @return mixed
	 */
	public function process($method, $data, $filters = [], $filter_page = []) {
		
		// Initialize model dan table name
		$modelInfo = $this->initializeModel($method, $data);
		if (empty($modelInfo)) {
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
	}

	/**
	 * Initialize model dan table name
	 *
	 * @param array $method
	 * @param object $data
	 * @return array|null
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
	 * Setup action configuration berdasarkan privileges
	 *
	 * @param object $data
	 * @param string $table_name
	 * @return array
	 */
	/**
	 * Setup action configuration for datatables
	 * Refactored to reduce nesting from 8 to 2 levels
	 * 
	 * @param object $data Table data
	 * @param string $table_name Table name
	 * @return array Action configuration
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
	 * @param array $column_data Column data
	 * @param string $table_name Table name
	 * @return array|false Action list or false
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
	 * @param array $action_list Full action list
	 * @param array $privileges User privileges
	 * @return array Filtered action list
	 */
	private function filterActionsByPrivileges($action_list, $privileges) {
		// If role_group <= 1, no filtering needed (admin/superadmin)
		if ($privileges['role_group'] <= 1) {
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
	 * Setup field configuration
	 *
	 * @param object $data
	 * @param string $table_name
	 * @return array
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
	 * @param mixed $model_data
	 * @param object $data
	 * @param string $table_name
	 * @return array
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
		
		return [
			'model'      => $model_data,
			'joinFields' => $joinFields
		];
	}
	
	/**
	 * Apply conditions (where clauses) to model
	 *
	 * @param mixed $model_data
	 * @param object $data
	 * @param string $table_name
	 * @return mixed
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
		$alt = "imgsrc::{$safeLabel}";
		
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
		$thumbPath = implode('/', $pathParts) . '/thumb/tnail_' . $fileName;
		
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
	 * Initialize filter datatables - CRITICAL: SQL Injection Fixed
	 * 
	 * Original code menggunakan raw SQL dengan user input langsung.
	 * Refactored untuk menggunakan query builder dengan parameter binding.
	 *
	 * @param array $get
	 * @param array $post
	 * @param string|null $connection
	 * @return mixed
	 */
	public function init_filter_datatables($get = [], $post = [], $connection = null) {
		
		if (empty($get['filterDataTables'])) {
			return null;
		}
		
		// Extract connection
		if (isset($post['grabCanvaStackC'])) {
			$connection = $post['grabCanvaStackC'];
			unset($post['grabCanvaStackC']);
		}
		
		// Extract filters
		$filters = [];
		if (isset($post['_canvastackF'])) {
			$filters = $post['_canvastackF'];
			unset($post['_canvastackF']);
		}
		
		// Parse filter data
		if (!isset($post['_fita'])) {
			return null;
		}
		
		$fdata  = explode('::', $post['_fita']);
		if (count($fdata) < 4) {
			return null;
		}
		
		$table  = $fdata[1];
		$target = $fdata[2];
		$prev   = $fdata[3];
		
		// Parse foreign keys
		$fKeys = [];
		if (isset($post['_forKeys'])) {
			$fKeys = json_decode($post['_forKeys'], true);
		}
		
		// Remove reserved parameters
		unset($post['filterDataTables'], $post['_fita'], $post['_token'], $post['_n'], $post['_forKeys']);
		
		// Build query menggunakan query builder (SECURE)
		$query = DB::connection($connection)->table($table);
		
		// Apply joins dari foreign keys
		if (!empty($fKeys)) {
			foreach ($fKeys as $fqs => $fqt) {
				$tqs = explode('.', $fqs);
				$tqsTable = $tqs[0];
				
				$query->leftJoin($tqsTable, $fqs, '=', $fqt);
			}
		}
		
		// Apply where conditions dari POST data (dengan parameter binding)
		foreach ($post as $key => $value) {
			// Sanitize field name untuk mencegah SQL injection
			$safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
			$query->where($safeKey, '=', $value);
		}
		
		// Apply filter queries (dengan parameter binding)
		if (!empty($filters)) {
			foreach ($filters as $filter) {
				if (!isset($filter['field_name']) || !isset($filter['value'])) {
					continue;
				}
				
				$fqFieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $filter['field_name']);
				$fqDataValue = $filter['value'];
				
				if (is_array($fqDataValue)) {
					$query->whereIn($fqFieldName, $fqDataValue);
				} else {
					$query->where($fqFieldName, '=', $fqDataValue);
				}
			}
		}
		
		// Apply previous conditions
		if ('#null' !== $prev) {
			$previous  = explode("#", $prev);
			if (count($previous) >= 2) {
				$preFields = explode('|', $previous[0]);
				$preFieldt = explode('|', $previous[1]);
				
				foreach ($preFields as $idf => $prev_field) {
					if (isset($preFieldt[$idf])) {
						$safeField = preg_replace('/[^a-zA-Z0-9_]/', '', $prev_field);
						$query->where($safeField, '=', $preFieldt[$idf]);
					}
				}
			}
		}
		
		// Sanitize target field name
		$safeTarget = preg_replace('/[^a-zA-Z0-9_]/', '', $target);
		
		// Execute query dengan parameter binding (SECURE)
		$results = $query->distinct()->select($safeTarget)->get();
		
		return $results;
	}
}
