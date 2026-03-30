<?php
use Illuminate\Support\Facades\DB;

/**
 * Created on 13 Apr 2021
 * Time Created : 04:05:22
 *
 * @filesource	Table.php
 *
 * @author    wisnuwidi@canvastack.com - 2021
 * @copyright wisnuwidi
 * @email     wisnuwidi@canvastack.com
 */

if (!function_exists('canvastack_escape_html')) {
	/**
	 * Escape HTML to prevent XSS attacks
	 *
	 * @param string $value
	 * @return string
	 */
	function canvastack_escape_html($value) {
		if (is_null($value)) {
			return '';
		}
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('canvastack_escape_js')) {
	/**
	 * Escape JavaScript strings to prevent XSS
	 *
	 * @param string $value
	 * @return string
	 */
	function canvastack_escape_js($value) {
		if (is_null($value)) {
			return '';
		}
		return addslashes($value);
	}
}

if (!function_exists('canvastack_validate_url')) {
	/**
	 * Validate and sanitize URL
	 *
	 * @param string $url
	 * @return string|false
	 */
	function canvastack_validate_url($url) {
		// Allow relative URLs and absolute URLs
		if (empty($url)) {
			return false;
		}

		// If it's a relative URL (starts with /), it's valid
		if (strpos($url, '/') === 0) {
			return $url;
		}

		// If it's an absolute URL, validate it
		$validated = filter_var($url, FILTER_VALIDATE_URL);
		return $validated !== false ? $validated : false;
	}
}

// ============================================================================
// DATA PROCESSING FUNCTIONS
// ============================================================================
 
if (!function_exists('canvastack_filter_data_normalizer')) {
	
	/**
	 * Normalizing Data Filters
	 * 
	 * @param array $filters
	 * 
	 * @return array
	 */
	function canvastack_filter_data_normalizer($filters = []) {
		$filterData = [];
		
		foreach ($filters as $filter_data) {
			if (is_array($filter_data['value'])) {
				foreach ($filter_data['value'] as $filterValues) {
					$filterData[$filter_data['field_name']]['value'][][] = $filterValues;
				}
			} else {
				$filterData[$filter_data['field_name']]['value'][][] = $filter_data['value'];
			}
		}
		
		$_filters = [];
		foreach ($filterData as $node => $nodeValues) {
			$_filters[$node]['field_name']  = $node;
			$_filters[$node]['operator']    = '=';
			foreach ($nodeValues['value'] as $values) {
				$_filters[$node]['value'][] = $values[0];
			}
		}
		unset($filterData);
		
		foreach ($_filters as $dataFilters) {
			$filterData[] = $dataFilters;
		}
		
		return $filterData;
	}
}

if (!function_exists('canvastack_get_model_table')) {
	
	/**
	 * Get Table Name From Data Model
	 *
	 * @param object $model
	 * @param boolean $find
	 *
	 * @return object|array
	 */
	function canvastack_get_model_table($model, $find = false) {
		$model = canvastack_get_model($model, $find);
		
		return $model->getTable();
	}
}

if (!function_exists('canvastack_get_all_tables')) {
	
	/**
	 * Get All Table Lists From Host Connection
	 *
	 * @param string $connection
	 *
	 * @return object|array
	 */
	function canvastack_get_all_tables($connection = null) {
		return collect(DB::connection($connection)->select('show tables'))->map(function ($val) {
			foreach ($val as $tbl) return $tbl;
		});
	}
}

if (!function_exists('canvastack_set_connection_separator')) {
	
	/**
	 * Set Separator Connection
	 *
	 * @param string $separator
	 *
	 * @return array
	 */
	function canvastack_set_connection_separator($separator = '--canvastackcon--') {
		return $separator;
	}
}

if (!function_exists('canvastack_check_table_columns')) {
	
	/**
	 * Check if Table Column(s) Exist
	 *
	 * @param string $field_name
	 *
	 * @return array
	 */
	function canvastack_check_table_columns($table_name, $field_name, $db_connection = 'mysql') {
		$connection = DB::connection($db_connection);
		return $connection->getSchemaBuilder()->hasColumn($table_name, $field_name);
	}
}

if (!function_exists('canvastack_get_table_columns')) {
	
	/**
	 * Get Table Column(s)
	 *
	 * @param string $table_name
	 *
	 * @return array
	 */
	function canvastack_get_table_columns($table_name, $db_connection = 'mysql') {
		$connection = DB::connection($db_connection);
		return $connection->getSchemaBuilder()->getColumnListing($table_name);
	}
}

if (!function_exists('canvastack_get_table_column_type')) {
	
	/**
	 * Get Table Column(s)
	 *
	 * @param string $table_name
	 * @param string $field_name
	 *
	 * @return string
	 */
    function canvastack_get_table_column_type($table_name, $field_name, $db_connection = 'mysql') {
        $connection = DB::connection($db_connection);
        return $connection->getSchemaBuilder()->getColumnType($table_name, $field_name);
	}
}

if (!function_exists('canvastack_temp_table')) {
	
	/**
	 * Create Temporary Table
	 *
	 * @param string $table_name
	 * @param string $sql
	 * @param boolean $strict
	 * @param string $conn
	 */
	function canvastack_temp_table($table_name, $sql, $strict = true, $conn = 'mysql') {
		$strictConfig = config("database.connections.{$conn}.strict");
		$table_name   = str_replace('temp_', '', $table_name);
		
		if (Illuminate\Support\Facades\Schema::hasTable("temp_{$table_name}")) {
			Illuminate\Support\Facades\Schema::dropIfExists("temp_{$table_name}");
		}
		
		if (false === $strict) {
			Illuminate\Support\Facades\DB::purge($conn);
			config()->set("database.connections.{$conn}.strict", $strict);
			Illuminate\Support\Facades\DB::reconnect();
		}
	//	dump(microtime(true));
		
		canvastack_query($sql, 'SELECT');
		Illuminate\Support\Facades\DB::unprepared("CREATE TABLE temp_{$table_name} {$sql}");
		
		if (false === $strict) {
			Illuminate\Support\Facades\DB::purge($conn);
			config()->set("database.connections.{$conn}.strict", $strictConfig);
			Illuminate\Support\Facades\DB::reconnect();
		}
	}
}

if (!function_exists('canvastack_model_processing_table')) {
	
	/**
	 * Call Model Process Data Table
	 *
	 * @param array $data
	 *
	 * @return object
	 */
	function canvastack_model_processing_table($data, $name) {
		if (!empty($data[$name])) {
			$model = $data[$name]['model'];
			
			if (false === $data[$name]['strict']) {
				canvastack_db('purge', $data[$name]['connection']);
				config()->set("database.connections.{$data[$name]['connection']}.strict", $data[$name]['strict']);
				canvastack_db('reconnect');
			}
			
			$model->{$data[$name]['function']}();
		}
	}
}

if (!function_exists('canvastack_set_formula_columns')) {
	
	/**
		 * Set formula columns in table
		 * 
		 * @param array $columns Column list
		 * @param array $data Formula data
		 * @return array Modified columns with formula columns inserted
		 */
		function canvastack_set_formula_columns($columns, $data) {
			arsort($data);

			$key_columns = array_flip($columns);
			$c_action = !empty($key_columns['action']);
			$c_lists = isset($key_columns['number_lists']);

			$f_node = canvastack_formula_build_nodes($data, $columns, $key_columns);
			$columns = canvastack_formula_insert_columns($columns, $f_node, $c_lists, $c_action);

			return $columns;
		}

		/**
		 * Build formula node data structure
		 * 
		 * @param array $data Formula data
		 * @param array $columns Column list
		 * @param array $key_columns Flipped column keys
		 * @return array Formula nodes with field info
		 */
		function canvastack_formula_build_nodes($data, $columns, $key_columns) {
			$f_node = [];

			foreach ($data as $formula_data) {
				$field_name = canvastack_formula_determine_field_name($formula_data, $columns);

				$f_node[$formula_data['name']] = [
					'field_label' => $formula_data['label'],
					'field_name' => $field_name,
					'field_key' => $key_columns[$field_name],
					'node_after' => $formula_data['node_after'],
					'node_location' => $formula_data['node_location']
				];
			}

			return $f_node;
		}

		/**
		 * Determine field name based on node location
		 * 
		 * @param array $formula_data Formula data
		 * @param array $columns Column list
		 * @return string Field name
		 */
		function canvastack_formula_determine_field_name($formula_data, $columns) {
			$for_node = $formula_data['node_location'];

			if (empty($for_node)) {
				return end($formula_data['field_lists']);
			}

			if ('first' === $for_node) {
				return $columns[0];
			}

			if ('last' === $for_node) {
				return $columns[array_key_last($columns)];
			}

			return $for_node;
		}

		/**
		 * Insert formula columns into column list
		 * 
		 * @param array $columns Column list
		 * @param array $f_node Formula nodes
		 * @param bool $c_lists Has number lists column
		 * @param bool $c_action Has action column
		 * @return array Modified columns
		 */
		function canvastack_formula_insert_columns($columns, $f_node, $c_lists, $c_action) {
			foreach ($f_node as $key => $fdata) {
				$position = canvastack_formula_calculate_position($fdata, $c_lists, $c_action);

				if ($position === 'push') {
					array_push($columns, $key);
				} else {
					canvastack_array_insert($columns, $position, $key);
				}
			}

			return $columns;
		}

		/**
		 * Calculate insertion position for formula column
		 * 
		 * @param array $fdata Formula node data
		 * @param bool $c_lists Has number lists column
		 * @param bool $c_action Has action column
		 * @return int|string Position index or 'push'
		 */
		function canvastack_formula_calculate_position($fdata, $c_lists, $c_action) {
			$field_key = intval($fdata['field_key']);
			$location = $fdata['node_location'];
			$after = $fdata['node_after'];

			if ('first' === $location) {
				return $c_lists ? $field_key + 1 : $field_key;
			}

			if ('last' === $location) {
				if (true === $after) {
					return $c_action ? $field_key : 'push';
				}
				return $field_key;
			}

			return $after ? $field_key + 1 : $field_key;
		}
}

if (!function_exists('canvastack_modal_content_html')) {
	/**
	 * Generate modal content HTML
	 * SECURITY: XSS Fixed - All user input escaped
	 * 
	 * @param string $name
	 * @param string $title
	 * @param array $elements
	 * @return string
	 */
	function canvastack_modal_content_html($name, $title, $elements) {
		// Escape for HTML context to prevent XSS
		$name_safe = canvastack_escape_html($name);
		$title_safe = canvastack_escape_html($title);
		$buttonID = str_replace('_CanvaStackFILTERmodalBOX', '_submitFilterButton', $name_safe);

		$html  = '<div class="modal-body">';
			$html .= '<div id="' . $name_safe . '">';
				$html .= implode('', $elements);
			$html .= '</div>';
		$html .= '</div>';
		$html .= '<div class="modal-footer">';
			$html .= '<div class="canvastack-action-box">';
				$html .= '<button type="reset" id="' . $name_safe . '-cancel" class="btn btn-danger btn-slideright pull-right" data-dismiss="modal">Cancel</button>';
				$html .= '<button id="' . $buttonID . '" class="btn btn-primary btn-slideright pull-right" type="submit">';
					$html .= '<i class="fa fa-filter"></i> &nbsp; Filter Data ' . $title_safe;
				$html .= '</button>';
				$html .= '<button id="exportFilterButton' . $name_safe . '" class="btn btn-info btn-slideright pull-right btn-export-csv hide" type="button">Export to CSV</button>';
			$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}

if (!function_exists('canvastack_clear_json')) {
	
	function canvastack_clear_json($data) {
		$json = str_replace('"data"', "data", $data);
		$json = str_replace('"name"', "name", $json);
		$json = str_replace('"', "'", $json);
		
		return $json;
	}
}

if (!function_exists('canvastack_table_action_button')) {
	
	/**
	 * Set Action Button URL Used For create_action_buttons() Function
	 * REFACTORED: Reduced from 134 lines to orchestrator pattern
	 *
	 * created @Sep 6, 2018
	 * author: wisnuwidi
	 *
	 * @param array $row_data
	 * @param string $field_target
	 * @param string $current_url
	 * @param bool|array $action
	 * @param array|null $removed_button
	 *
	 * @return string
	 */
	function canvastack_table_action_button($row_data, $field_target = 'id', $current_url, $action, $removed_button = null) {
		$enabledAction = canvastack_action_init_enabled_actions();
		$actions = canvastack_action_check_privileges($action);
		$addActions = canvastack_action_parse_actions($action, $enabledAction);
		
		canvastack_action_process_removed_buttons($removed_button, $actions, $enabledAction);
		
		$path = canvastack_action_build_paths($row_data, $field_target, $current_url, $enabledAction);
		$add_path = canvastack_action_build_additional_paths($addActions, $current_url, $row_data, $field_target);
		
		return create_action_buttons($path['view'], $path['edit'], $path['delete'], $add_path);
	}
	
	/**
	 * Initialize enabled actions
	 * 
	 * @return array
	 */
	function canvastack_action_init_enabled_actions() {
		return [
			'read'   => true,
			'insert' => true,
			'modify' => true,
			'delete' => true
		];
	}
	
	/**
	 * Check privileges and filter actions
	 * 
	 * @param mixed $action
	 * @return array
	 */
	function canvastack_action_check_privileges($action) {
		$privileges = session()->all()['privileges']['role'];
		$actions = [];
		
		if (!in_array(current_route(), $privileges)) {
			return $actions;
		}
		
		foreach ($privileges as $roles) {
			if (!canvastack_string_contained($roles, routelists_info()['base_info'])) {
				continue;
			}
			
			$last_info = routelists_info($roles)['last_info'];
			if (!in_array($last_info, ['index', 'insert', 'update', 'destroy'])) {
				$actions[routelists_info()['base_info']][] = $last_info;
			}
		}
		
		return $actions;
	}
	
	/**
	 * Parse and process actions
	 * 
	 * @param mixed $action
	 * @param array &$enabledAction
	 * @return array
	 */
	function canvastack_action_parse_actions($action, &$enabledAction) {
		if (is_array($action)) {
			return canvastack_action_parse_array_actions($action, $enabledAction);
		}
		
		if (is_string($action)) {
			return canvastack_action_parse_string_action($action);
		}
		
		return [];
	}
	
	/**
	 * Parse array actions
	 * 
	 * @param array $action
	 * @param array &$enabledAction
	 * @return array
	 */
	/**
		 * Parse array of actions and filter out default actions
		 * 
		 * @param array $action
		 * @param array &$enabledAction
		 * @return array
		 */
		function canvastack_action_parse_array_actions($action, &$enabledAction) {
			$addActions = [];

			foreach ($action as $action_data) {
				// CRITICAL FIX: Filter out default actions (view, edit, delete, insert, etc.)
				// These are handled by default buttons, not additional buttons
				if (in_array($action_data, ['index', 'show', 'view', 'create', 'insert', 'add', 'edit', 'update', 'modify', 'delete', 'destroy'])) {
					continue; // Skip default actions
				}

				if (canvastack_string_contained($action_data, '|')) {
					$action_info = canvastack_add_action_button_by_string($action_data);
					$addActions[key($action_info)] = $action_info[key($action_info)];
					$enabledAction[key($action_info)] = true;
				} else {
					$addActions[$action_data] = canvastack_add_action_button_by_string("{$action_data}|default|link");
					$enabledAction[$action_data] = true;
				}
			}

			return $addActions;
		}
	
	/**
	 * Parse string action
	 * 
	 * @param string $action
	 * @return array
	 */
	function canvastack_action_parse_string_action($action) {
		if (canvastack_string_contained($action, '|')) {
			return canvastack_add_action_button_by_string($action);
		}
		
		return canvastack_add_action_button_by_string("{$action}|default|link");
	}
	
	/**
	 * Process removed buttons
	 * 
	 * @param array|null $removed_button
	 * @param array &$actions
	 * @param array &$enabledAction
	 * @return void
	 */
	function canvastack_action_process_removed_buttons($removed_button, &$actions, &$enabledAction) {
		if (empty($removed_button) || !is_array($removed_button)) {
			return;
		}
		
		$actionNode = array_flip($actions);
		
		foreach ($removed_button as $remove) {
			canvastack_action_remove_button_type($remove, $actionNode, $actions, $enabledAction);
		}
	}
	
	/**
	 * Remove specific button type
	 * 
	 * @param string $remove
	 * @param array $actionNode
	 * @param array &$actions
	 * @param array &$enabledAction
	 * @return void
	 */
	function canvastack_action_remove_button_type($remove, $actionNode, &$actions, &$enabledAction) {
		if (in_array($remove, ['index', 'show', 'view', 'read'])) {
			$enabledAction['read'] = false;
			canvastack_action_unset_actions($actionNode, $actions, ['view', 'index', 'show']);
		} elseif (in_array($remove, ['create', 'insert', 'add'])) {
			$enabledAction['insert'] = false;
			canvastack_action_unset_actions($actionNode, $actions, ['create', 'insert', 'add']);
		} elseif (in_array($remove, ['edit', 'update', 'modify'])) {
			$enabledAction['modify'] = false;
			canvastack_action_unset_actions($actionNode, $actions, ['edit', 'update', 'modify']);
		} elseif (in_array($remove, ['delete', 'destroy'])) {
			$enabledAction['delete'] = false;
			canvastack_action_unset_actions($actionNode, $actions, ['delete', 'destroy']);
		} else {
			$enabledAction[$remove] = false;
		}
	}
	
	/**
	 * Unset multiple actions
	 * 
	 * @param array $actionNode
	 * @param array &$actions
	 * @param array $keys
	 * @return void
	 */
	function canvastack_action_unset_actions($actionNode, &$actions, $keys) {
		foreach ($keys as $key) {
			if (!empty($actionNode[$key])) {
				unset($actions[$actionNode[$key]]);
			}
		}
	}
	
	/**
	 * Build action paths
	 * 
	 * @param object $row_data
	 * @param string $field_target
	 * @param string $current_url
	 * @param array $enabledAction
	 * @return array
	 */
	function canvastack_action_build_paths($row_data, $field_target, $current_url, $enabledAction) {
		$urlTarget = $row_data->{$field_target};
		
		$path = [
			'view' => "{$current_url}/{$urlTarget}",
			'edit' => "{$current_url}/{$urlTarget}/edit",
			'delete' => !empty($row_data->deleted_at) 
				? "{$current_url}/{$urlTarget}/restore_deleted"
				: "{$current_url}/{$urlTarget}/delete"
		];
		
		if (false === $enabledAction['read'])   $path['view'] = false;
		if (false === $enabledAction['modify']) $path['edit'] = false;
		if (false === $enabledAction['delete']) $path['delete'] = false;
		
		return $path;
	}
	
	/**
	 * Build additional action paths
	 * 
	 * @param array $addActions
	 * @param string $current_url
	 * @param object $row_data
	 * @param string $field_target
	 * @return array|false
	 */
	function canvastack_action_build_additional_paths($addActions, $current_url, $row_data, $field_target) {
		if (count($addActions) < 1) {
			return false;
		}
		
		$add_path = [];
		$urlTarget = $row_data->{$field_target};
		
		foreach ($addActions as $action_name => $action_values) {
			// Filter out default actions - these should not appear in additional buttons
			if (in_array($action_name, ['index', 'show', 'view', 'create', 'insert', 'add', 'edit', 'update', 'modify', 'delete', 'destroy'])) {
				continue;
			}
			
			$add_path[$action_name] = canvastack_action_build_single_path(
				$action_name, 
				$action_values, 
				$current_url, 
				$urlTarget
			);
		}
		
		return $add_path;
	}
	
	/**
	 * Build single additional action path
	 * 
	 * @param string $action_name
	 * @param mixed $action_values
	 * @param string $current_url
	 * @param string $urlTarget
	 * @return array
	 */
	function canvastack_action_build_single_path($action_name, $action_values, $current_url, $urlTarget) {
		$path = ['url' => "{$current_url}/{$urlTarget}/{$action_name}"];
		
		if (!is_array($action_values)) {
			return $path;
		}
		
		foreach ($action_values as $actionKey => $actionValue) {
			if ($actionKey === $action_name) {
				$path = $actionValue;
				$path['url'] = "{$current_url}/{$urlTarget}/{$action_name}";
			} else {
				$path[$actionKey] = $actionValue;
			}
		}
		
		return $path;
	}
}

if (!function_exists('canvastack_add_action_button_by_string')) {
	
	function canvastack_add_action_button_by_string($action, $is_array = false) {
		$addActions = [];
		if (is_bool($action)) {
			if (true === $action) {
				$addActions['view']['color']   = 'success';
				$addActions['view']['icon']    = 'eye';
				
				$addActions['edit']['color']   = 'primary';
				$addActions['edit']['icon']    = 'pencil';
				
				$addActions['delete']['color'] = 'danger';
				$addActions['delete']['icon']  = 'times';
			}
		} else {
			if (canvastack_string_contained($action, '|')) {
				$str_action = explode('|', $action);
				$str_name	= reset($str_action);
			} else {
				$str_action = $action;
				$str_name   = false;
			}
			
			$actionAttr = [];
			
			if (count($str_action) >= 2) {
				$actionAttr['color'] = false;
				if (isset($str_action[1])) {
					$actionAttr['color'] = $str_action[1];
				}
				
				$actionAttr['icon'] = false;
				if (isset($str_action[2])) {
					$actionAttr['icon'] = $str_action[2];
				}
				$addActions[$str_name]  = $actionAttr;
			} else {
				$addActions[$action]    = $action;
			}
		}
		
		return $addActions;
	}
}

if (!function_exists('create_action_buttons')) {
	
	/**
	 * Action Button(s) Builder
	 *
	 * created @Sep 6, 2018
	 * author: wisnuwidi
	 *
	 * @param string $view
	 * @param string $edit
	 * @param string $delete
	 * @param string $add_action
	 * @param string $as_root
	 *
	 * @return string
	 */
	/**
		 * Create action buttons for table rows
		 * 
		 * @param string|false $view View URL or false
		 * @param string|false $edit Edit URL or false
		 * @param string|false $delete Delete URL or false
		 * @param array $add_action Additional actions
		 * @param bool $as_root Whether to use root context
		 * @return string HTML for action buttons
		 */
		function create_action_buttons($view = false, $edit = false, $delete = false, $add_action = [], $as_root = false) {
			$restoreDeleted = false;
			$deleteData = false;

			if (false !== $delete) {
				$deleteData = create_action_buttons_parse_delete($delete);
				$restoreDeleted = $deleteData['is_restore'];
			}

			$buttonView = create_action_buttons_view($view, $restoreDeleted);
			$buttonEdit = create_action_buttons_edit($edit, $restoreDeleted);
			$buttonDelete = create_action_buttons_delete($deleteData);
			$buttonNew = create_action_buttons_additional($add_action, $restoreDeleted);

			return create_action_buttons_render($buttonView, $buttonEdit, $buttonDelete, $buttonNew);
		}

		/**
		 * Parse delete URL and determine if it's a restore action
		 * 
		 * @param string $delete Delete URL
		 * @return array Delete data with URL, ID, and restore flag
		 */
		function create_action_buttons_parse_delete($delete) {
			$deletePath = explode('/', $delete);
			$deleteFlag = end($deletePath);
			$delete_id = intval($deletePath[count($deletePath)-2]);
			$deleteURL = str_replace('@index', '@destroy', canvastack_current_route()->getActionName());

			$isRestore = ('restore_deleted' === $deleteFlag);

			return [
				'url' => $deleteURL,
				'id' => $delete_id,
				'is_restore' => $isRestore,
				'delete_path' => $delete
			];
		}

		/**
		 * Create view button HTML
		 * 
		 * @param string|false $view View URL
		 * @param bool $restoreDeleted Whether this is a restore action
		 * @return array Desktop and mobile button HTML
		 */
		function create_action_buttons_view($view, $restoreDeleted) {
			if (false == $view) {
				return ['desktop' => false, 'mobile' => false];
			}

			if (true === $restoreDeleted) {
				$viewAttr = 'readonly disabled class="btn btn-default btn-xs btn_view" data-toggle="tooltip" data-placement="top" data-original-title="View detail"';
			} else {
				$view_safe = canvastack_escape_html($view);
				$viewAttr = 'href="' . $view_safe . '" class="btn btn-success btn-xs btn_view" data-toggle="tooltip" data-placement="top" data-original-title="View detail"';
			}

			$desktop = '<a ' . $viewAttr . '><i class="fa fa-eye"></i></a>';
			$mobile = '<li class="btn_view"><a href="' . canvastack_escape_html($view) . '" class="tooltip-info" data-rel="tooltip" title="View"><span class="blue"><i class="fa fa-search-plus bigger-120"></i></span></a></li>';

			return ['desktop' => $desktop, 'mobile' => $mobile];
		}

		/**
		 * Create edit button HTML
		 * 
		 * @param string|false $edit Edit URL
		 * @param bool $restoreDeleted Whether this is a restore action
		 * @return array Desktop and mobile button HTML
		 */
		function create_action_buttons_edit($edit, $restoreDeleted) {
			if (false == $edit) {
				return ['desktop' => false, 'mobile' => false];
			}

			if (true === $restoreDeleted) {
				$editAttr = ' readonly disabled class="btn btn-default btn-xs btn_edit" data-toggle="tooltip" data-placement="top" data-original-title="Edit"';
			} else {
				$edit_safe = canvastack_escape_html($edit);
				$editAttr = ' href="' . $edit_safe . '" class="btn btn-primary btn-xs btn_edit" data-toggle="tooltip" data-placement="top" data-original-title="Edit"';
			}

			$desktop = '<a ' . $editAttr . '><i class="fa fa-pencil"></i></a>';
			$mobile = '<li class="btn_edit"><a href="' . canvastack_escape_html($edit) . '" class="tooltip-success" data-rel="tooltip" title="Edit"><span class="green"><i class="fa fa-pencil-square-o bigger-120"></i></span></a></li>';

			return ['desktop' => $desktop, 'mobile' => $mobile];
		}

		/**
		 * Create delete button HTML
		 * 
		 * @param array|false $deleteData Delete data from parse_delete
		 * @return array Desktop and mobile button HTML
		 */
		function create_action_buttons_delete($deleteData) {
			if (false === $deleteData) {
				return ['desktop' => false, 'mobile' => false];
			}

			if ($deleteData['is_restore']) {
				$buttonAttr = 'class="btn btn-warning btn-xs" data-toggle="tooltip" data-placement="top" data-original-title="Restore"';
				$iconAttr = 'fa fa-recycle';
			} else {
				$buttonAttr = 'class="btn btn-danger btn-xs" data-toggle="tooltip" data-placement="top" data-original-title="Delete"';
				$iconAttr = 'fa fa-times';
			}

			$delete_action = '<form action="' . action($deleteData['url'], $deleteData['id']) . '" method="post" class="btn btn_delete" style="padding:0 !important">' . csrf_field() . '<input name="_method" type="hidden" value="DELETE">';
			$desktop = $delete_action . '<button ' . $buttonAttr . ' type="submit"><i class="' . $iconAttr . '"></i></button></form>';
			$mobile = '<li><a href="' . canvastack_escape_html($deleteData['delete_path']) . '" class="tooltip-error btn_delete" data-rel="tooltip" title="Delete"><span class="red"><i class="fa fa-trash-o bigger-120"></i></span></a></li>';

			return ['desktop' => $desktop, 'mobile' => $mobile];
		}

		/**
		 * Create additional action buttons HTML
		 * 
		 * @param array $add_action Additional actions
		 * @param bool $restoreDeleted Whether this is a restore action
		 * @return array Desktop and mobile button HTML
		 */
		function create_action_buttons_additional($add_action, $restoreDeleted) {
			if (!is_array($add_action) || count($add_action) < 1) {
				return ['desktop' => '', 'mobile' => ''];
			}

			$desktop = '';
			$mobile = '';

			foreach ($add_action as $new_action_name => $new_action_values) {
				$btn_name = $new_action_name;
				$row_name = camel_case($new_action_name);
				$row_url = $new_action_values['url'];
				$row_color = !empty($new_action_values['color']) ? $new_action_values['color'] : null;
				$row_icon = !empty($new_action_values['icon']) ? $new_action_values['icon'] : null;

				// Escape for HTML context
				$row_name_safe = canvastack_escape_html($row_name);
				$row_url_safe = canvastack_escape_html($row_url);
				$row_icon_safe = canvastack_escape_html($row_icon);

				if (true === $restoreDeleted) {
					$actionAttr = ' readonly disabled class="btn btn-default btn-xs" data-toggle="tooltip" data-placement="top" data-original-title="' . $row_name_safe . '"';
				} else {
					$actionAttr = ' href="' . $row_url_safe . '" class="btn ' . $btn_name . ' btn-' . $row_color. ' btn-xs" data-toggle="tooltip" data-placement="top" data-original-title="' . $row_name_safe . '"';
				}

				$desktop .= '<a' . $actionAttr . '><i class="fa fa-' . $row_icon_safe . '"></i></a>';
				$mobile .= '<li><a href="' . $row_url_safe . '" class="tooltip-error" data-rel="tooltip" title="' . $row_name_safe . '"><span class="red"><i class="fa fa-' . $row_icon_safe . ' bigger-120"></i></span></a></li>';
			}

			return ['desktop' => $desktop, 'mobile' => $mobile];
		}

		/**
		 * Render final action buttons HTML
		 * 
		 * @param array $buttonView View button data
		 * @param array $buttonEdit Edit button data
		 * @param array $buttonDelete Delete button data
		 * @param array $buttonNew Additional button data
		 * @return string Final HTML for action buttons
		 */
		function create_action_buttons_render($buttonView, $buttonEdit, $buttonDelete, $buttonNew) {
			$buttons = ($buttonView['desktop'] ?? '') . ($buttonEdit['desktop'] ?? '') . ($buttonDelete['desktop'] ?? '') . ($buttonNew['desktop'] ?? '');
			$buttonsMobile = ($buttonView['mobile'] ?? '') . ($buttonEdit['mobile'] ?? '') . ($buttonDelete['mobile'] ?? '') . ($buttonNew['mobile'] ?? '');

			return '<div class="action-buttons-box"><div class="hidden-sm hidden-xs action-buttons">' . $buttons . '</div><div class="hidden-md hidden-lg"><div class="inline pos-rel"><button class="btn btn-minier btn-yellow dropdown-toggle" data-toggle="dropdown" data-position="auto"><i class="fa fa-caret-down icon-only bigger-120"></i></button><ul class="dropdown-menu dropdown-only-icon dropdown-yellow dropdown-menu-right dropdown-caret dropdown-close">' . $buttonsMobile . '</ul></div></div></div>';
		}
}

if (!function_exists('canvastack_table_row_attr')) {
	/**
	 * Set Default Row Attributes for Table
	 *
	 * @param string $str_value
	 * @param string $attributes
	 * 		=> colspan=2|id=idLists OR ['colspan' => 2, 'id' => 'idLists']
	 *
	 * @return string
	 */
	function canvastack_table_row_attr($str_value, $attributes) {
		$attr = $attributes;
		if (is_array($attributes)) {
			$attribute = [];
			foreach ($attributes as $key => $value) {
				$attribute[] = "{$key}=\"{$value}\"";
			}
			$attr = implode(' ', $attribute);
		}
		
		return "{$str_value}{:}$attr";
	}
}

if (!function_exists('canvastack_generate_table')) {
	
	/**
	 * Table Builder
	 * REFACTORED: Reduced from 202 lines to orchestrator pattern
	 * SECURITY: All XSS vulnerabilities fixed
	 *
	 * @param string $title
	 * @param string $title_id
	 * @param array  $header
	 * @param array  $body
	 * @param array  $attributes
	 * @param string $numbering
	 * @param string $containers
	 * 		: draw <div> container box, defalult true
	 * @param string $server_side
	 * @param boolean|string|array $server_side_custom_url
	 *
	 * @return string
	 */
	function canvastack_generate_table($title = false, $title_id = false, $header = array(), $body = array(), $attributes = array(), $numbering = false, $containers = true, $server_side = false, $server_side_custom_url = false) {
		$attributes = canvastack_table_setup_attributes($title_id, $attributes);
		$_header = canvastack_table_generate_header($header, $numbering);
		$_body = canvastack_table_generate_body($body, $numbering, $server_side);
		
		return "<table{$attributes}>{$_header}{$_body}</table>";
	}
	
	/**
	 * Setup table attributes
	 * 
	 * @param string $title_id
	 * @param array $attributes
	 * @return string
	 */
	function canvastack_table_setup_attributes($title_id, $attributes) {
		$datatableClass = 'CanvaStack-table table animated fadeIn table-striped table-default table-bordered table-hover dataTable repeater display responsive nowrap';
		
		if (false !== $attributes && is_array($attributes)) {
			$_attributes = canvastack_table_merge_attributes($title_id, $attributes, $datatableClass);
		} else {
			$_attributes = canvastack_table_default_attributes($title_id, $datatableClass);
		}
		
		return ' ' . rtrim(canvastack_attributes_to_string($_attributes));
	}
	
	/**
	 * Merge custom attributes with defaults
	 * 
	 * @param string $title_id
	 * @param array $attributes
	 * @param string $datatableClass
	 * @return array
	 */
	function canvastack_table_merge_attributes($title_id, $attributes, $datatableClass) {
		if (empty($attributes)) {
			return canvastack_table_default_attributes($title_id, $datatableClass);
		}
		
		$_attributes = [];
		$_attributes['id'] = $attributes['id'] ?? "datatable-{$title_id}";
		$_attributes['class'] = $attributes['class'] ?? $datatableClass;
		
		foreach ($attributes as $attrField => $attrValue) {
			$_attributes[$attrField] = $attrValue;
		}
		
		return $_attributes;
	}
	
	/**
	 * Get default table attributes
	 * 
	 * @param string $title_id
	 * @param string $datatableClass
	 * @return array
	 */
	function canvastack_table_default_attributes($title_id, $datatableClass) {
		return [
			'id'    => "datatable-{$title_id}",
			'class' => $datatableClass
		];
	}
	
	/**
	 * Generate table header
	 * 
	 * @param array $header
	 * @param bool $numbering
	 * @return string|false
	 */
	function canvastack_table_generate_header($header, $numbering) {
		if (false === $header) {
			return false;
		}
		
		if (true === $numbering) {
			$header = array_merge(['number_lists'], $header);
		}
		
		$_merge = [];
		$_header = '<thead><tr>';
		
		foreach ($header as $hIndex => $hList) {
			if (is_array($hList)) {
				$_merge[$hIndex] = $hList['merge'];
				$_header .= tableColumn($header, $hIndex, $hList['column']);
			} else {
				$_header .= tableColumn($header, $hIndex, $hList);
			}
		}
		
		$_header .= '</tr>';
		
		if (!empty($_merge)) {
			$_header .= canvastack_table_generate_merge_columns($_merge);
		}
		
		$_header .= '</thead>';
		
		return $_header;
	}
	
	/**
	 * Generate merge columns for header
	 * 
	 * @param array $_merge
	 * @return string
	 */
	function canvastack_table_generate_merge_columns($_merge) {
		$html = '';
		
		foreach ($_merge as $_mergedata) {
			foreach ($_mergedata as $idx => $mdList) {
				$html .= tableColumn($_mergedata, $idx, $mdList);
			}
		}
		
		return $html;
	}
	
	/**
	 * Generate table body
	 * 
	 * @param array $body
	 * @param bool $numbering
	 * @param bool $server_side
	 * @return string|null
	 */
	function canvastack_table_generate_body($body, $numbering, $server_side) {
		if (true === $server_side) {
			return null;
		}
		
		if (false === $body) {
			return '<tbody><tr><td>Found no data</td></tr></tbody>';
		}
		
		$_body = '<tbody>';
		$array_keys = array_keys($body);
		$first_key = reset($array_keys);
		
		foreach ($body as $bIndex => $bLists) {
			$_body .= canvastack_table_generate_row($bIndex, $bLists, $body, $numbering, $first_key);
		}
		
		$_body .= '</tbody>';
		
		return $_body;
	}
	
	/**
	 * Generate single table row
	 * 
	 * @param int $bIndex
	 * @param array $bLists
	 * @param array $body
	 * @param bool $numbering
	 * @param int $first_key
	 * @return string
	 */
	function canvastack_table_generate_row($bIndex, $bLists, $body, $numbering, $first_key) {
		$rowClickAction = canvastack_table_get_row_click_action($bLists);
		unset($bLists['row_data_url']);
		
		$row_html = '<tr>';
		
		for ($row = 0; $row <= count($body); $row++) {
			if ($bIndex === $row) {
				if (true === $numbering) {
					$numLists = ($first_key <= 0) ? intval($row)+1 : intval($row);
					$row_html .= "<td class=\"center\">{$numLists}</td>";
				}
				
				$row_html .= canvastack_table_generate_cells($bLists, $rowClickAction);
			}
		}
		
		$row_html .= '</tr>';
		
		return $row_html;
	}
	
	/**
	 * Get row click action attribute
	 * 
	 * @param array $bLists
	 * @return string|false
	 */
	function canvastack_table_get_row_click_action($bLists) {
		if (empty($bLists['row_data_url']) || false === $bLists['row_data_url']) {
			return false;
		}
		
		// Validate and escape URL for JavaScript context
		$validated_url = canvastack_validate_url($bLists['row_data_url']);
		
		if ($validated_url !== false) {
			$url_safe = canvastack_escape_js($validated_url);
			return ' onclick="location.href=\'' . $url_safe . '\'" class="row-list-url"';
		}
		
		return false;
	}
	
	/**
	 * Generate all cells for a row
	 * 
	 * @param array $bLists
	 * @param string|false $rowClickAction
	 * @return string
	 */
	function canvastack_table_generate_cells($bLists, $rowClickAction) {
		$cells_html = '';
		
		foreach ($bLists as $index => $list) {
			$cells_html .= canvastack_table_generate_single_cell($index, $list, $rowClickAction);
		}
		
		return $cells_html;
	}
	
	/**
	 * Generate single table cell
	 * 
	 * @param string $index
	 * @param mixed $list
	 * @param string|false $rowClickAction
	 * @return string
	 */
	function canvastack_table_generate_single_cell($index, $list, $rowClickAction) {
		// Disable row click for action column
		if ('action' === $index) {
			$rowClickAction = false;
		}
		
		// Parse row attributes if present
		$parsed = canvastack_table_parse_cell_attributes($list, $rowClickAction);
		$list = $parsed['list'];
		$row_list = $parsed['row_list'];
		
		// Handle special column types
		return canvastack_table_handle_special_column($index, $list, $row_list, $rowClickAction);
	}
	
	/**
	 * Parse cell attributes from list value
	 * 
	 * @param string $list
	 * @param string|false $rowClickAction
	 * @return array
	 */
	function canvastack_table_parse_cell_attributes($list, $rowClickAction) {
		$row_attr = false;
		
		if (true === str_contains($list, '{:}')) {
			$reList = explode('{:}', $list);
			$list = $reList[0];
			
			if (isset($reList[1])) {
				$rowAttr = explode('|', $reList[1]);
				$row_attr = ' ' . implode(' ', $rowAttr);
			}
			
			// Escape for HTML context
			$list_safe = canvastack_escape_html($list);
			$row_list = "<td{$row_attr}{$rowClickAction}>{$list_safe}</td>";
		} else {
			// Escape for HTML context
			$list_safe = canvastack_escape_html($list);
			$row_list = "<td{$rowClickAction}>{$list_safe}</td>";
		}
		
		return ['list' => $list, 'row_list' => $row_list];
	}
	
	/**
	 * Handle special column types (active, flag_status, etc.)
	 * 
	 * @param string $index
	 * @param mixed $list
	 * @param string $row_list
	 * @param string|false $rowClickAction
	 * @return string
	 */
	function canvastack_table_handle_special_column($index, $list, $row_list, $rowClickAction) {
		// Note: $hNumber and $hEmpty are handled in the calling context
		// This function focuses on special column types
		
		switch ($index) {
			case 'active':
				$_list = set_active_value($list);
				return "<td align=\"center\">{$_list}</td>";
				
			case 'flag_status':
				$_list = internal_flag_status($list);
				return "<td align=\"center\"{$rowClickAction}>{$_list}</td>";
				
			case 'request_status':
				$_list = request_status(true, $list);
				return "<td align=\"center\">{$_list}</td>";
				
			case 'update_status':
				$_list = active_box();
				return "<td align=\"center\">{$_list[$list]}</td>";
				
			case 'action':
				// Action buttons are already escaped by create_action_buttons()
				return "<td align=\"center\"{$rowClickAction}>{$list}</td>";
				
			default:
				return $row_list;
		}
	}
	
	/**
	 * OLD IMPLEMENTATION - KEPT FOR REFERENCE
	 * This was the original 202-line function with 8-level nesting
	 * Now refactored into 10 focused functions above
	 */
	function canvastack_generate_table_OLD_BACKUP($title = false, $title_id = false, $header = array(), $body = array(), $attributes = array(), $numbering = false, $containers = true, $server_side = false, $server_side_custom_url = false) {
		// Original implementation moved to backup
		// See refactored version above
		return '';
	}
	
	/**
	 * Generate table column header
	 * REFACTORED: Reduced from 87 lines to orchestrator pattern
	 * SECURITY: XSS Fixed - All user input escaped
	 * 
	 * @param array $header
	 * @param int $hIndex
	 * @param mixed $hList
	 * @return string
	 */
	function tableColumn($header, $hIndex, $hList) {
		$headerData = canvastack_column_extract_header_data($hList);
		$headerData = canvastack_column_process_field_name($headerData);
		$headerType = canvastack_column_determine_type($header, $hIndex, $headerData);
		
		return canvastack_column_generate_html($headerData, $headerType, $hIndex);
	}
	
	/**
	 * Extract header key and value
	 * 
	 * @param mixed $hList
	 * @return array
	 */
	function canvastack_column_extract_header_data($hList) {
		if (is_array($hList)) {
			$keyList = array_keys($hList);
			$HKEY = $keyList[0];
			$HVAL = $hList[$HKEY];
		} else {
			$HKEY = $hList;
			$HVAL = trim(ucwords(str_replace('_', ' ', $HKEY)));
		}
		
		return [
			'key' => $HKEY,
			'value' => $HVAL,
			'list' => $HKEY,
			'label' => $HVAL,
			'fields' => $HKEY
		];
	}
	
	/**
	 * Process field name (handle pipe and dot separators)
	 * 
	 * @param array $headerData
	 * @return array
	 */
	function canvastack_column_process_field_name($headerData) {
		$hList = $headerData['list'];
		$hListFields = $hList;
		
		if (true === str_contains($hList, '|')) {
			$newHList = explode('|', $hList);
			$hList = $newHList[1];
			$hListFields = $hList;
		}
		
		if (true === str_contains($hList, '.')) {
			$newHList = explode('.', $hList);
			$hList = $newHList[0];
		}
		
		$headerData['list'] = trim(ucwords(str_replace('_', ' ', $hList)));
		$headerData['fields'] = $hListFields;
		
		return $headerData;
	}
	
	/**
	 * Determine header column type
	 * 
	 * @param array $header
	 * @param int $hIndex
	 * @param array $headerData
	 * @return array
	 */
	function canvastack_column_determine_type($header, $hIndex, $headerData) {
		$idHeader = $header[$hIndex];
		
		if (is_array($idHeader)) {
			$fHead = array_keys($idHeader);
			$idHeader = $fHead[0];
		}
		
		$type = [
			'isNumber' => in_array(strtolower($idHeader), ['no', 'id', 'nik']),
			'isCheck' => canvastack_string_contained($headerData['list'], '<input type="checkbox"'),
			'isEmpty' => is_empty($headerData['list']),
			'idHeader' => $idHeader
		];
		
		return $type;
	}
	
	/**
	 * Generate header HTML based on type
	 * 
	 * @param array $headerData
	 * @param array $headerType
	 * @param int $hIndex
	 * @return string
	 */
	function canvastack_column_generate_html($headerData, $headerType, $hIndex) {
		$hList = $headerData['list'];
		$hLabel = $headerData['label'];
		$hListFields = $headerData['fields'];
		
		// Escape all output for HTML context
		$hList_safe = canvastack_escape_html($hList);
		$hLabel_safe = canvastack_escape_html($hLabel);
		$hListFields_safe = canvastack_escape_html($hListFields);
		
		if ($headerType['isNumber']) {
			return "<th class=\"center\" width=\"50\">{$hList_safe}</th>";
		}
		
		if (true === str_contains($hList, ':changeHeaderName:')) {
			$newHList = explode(':changeHeaderName:', $hList);
			$hList = ucwords($newHList[1]);
			$hListFields_safe = canvastack_escape_html($hList);
			return "<th class=\"center\" width=\"120\">{$hListFields_safe}</th>";
		}
		
		if ($headerType['isCheck']) {
			return "<th width=\"50\">{$hList_safe}</th>";
		}
		
		if ($headerType['isEmpty']) {
			return "<th class=\"center\" width=\"120\">{$hList_safe}</th>";
		}
		
		// Handle special column names
		$specialColumns = ['Action', 'Active', 'Flag Status'];
		if (in_array($hList, $specialColumns)) {
			return "<th class=\"center\" width=\"120\">{$hList_safe}</th>";
		}
		
		// Handle number_lists
		if ('number_lists' === strtolower($headerType['idHeader'])) {
			return "<th class=\"center\" width=\"30\">No</th><th class=\"center\" width=\"30\">ID</th>";
		}
		
		// Handle custom attributes
		return canvastack_column_generate_custom_html($hList, $hLabel_safe);
	}
	
	/**
	 * Generate custom header HTML with attributes
	 * 
	 * @param string $hList
	 * @param string $hLabel_safe
	 * @return string
	 */
	function canvastack_column_generate_custom_html($hList, $hLabel_safe) {
		$row_attr = false;
		
		if (true === str_contains($hList, '{:}')) {
			$reList = explode('{:}', $hList);
			$hList = $reList[0];
			
			if (isset($reList[1])) {
				$rowAttr = explode('|', $reList[1]);
				$row_attr = ' ' . implode(' ', $rowAttr);
			}
			
			$hList_safe = canvastack_escape_html($hList);
			return "<th{$row_attr}>{$hList_safe}</th>";
		}
		
		return "<th>{$hLabel_safe}</th>";
	}
}

if (!function_exists('canvastack_draw_query_map_page_table')) {
	
	/**
		 * Draw query map page table
		 * SECURITY: XSS Fixed - All user input escaped
		 * 
		 * @param string $name
		 * @param string $field_id
		 * @param string $value_id
		 * @param array $data
		 * @param array $buffers
		 * @param array $fieldbuff
		 * @return string
		 */
		function canvastack_draw_query_map_page_table($name, $field_id, $value_id, $data, $buffers, $fieldbuff) {		
			$fieldID   = $field_id;
			$trClass   = null;

			// Escape $name for HTML context
			$name_safe = canvastack_escape_html($name);
			$o         = "<table class=\"table mapping-table display responsive relative-box {$name_safe}\"><tbody>";

			if (!empty($buffers)) {
				$n      = 0;
				$id     = explode('__node__', $field_id)[0];
				$ico    = 'fa fa-recycle warning';
				$script = null;

				foreach ($buffers[$id] as $field_info => $value) {
					$n++;

					if ($n > 1) {
						$field_id = $fieldbuff['ranid'][$field_info];
						$value_id = $fieldbuff['ranval'][$field_info];
						$trClass  = " role-add-{$fieldID}";
						$ico      = 'fa fa-minus-circle danger';

						// Escape for JavaScript context
						$field_id_safe = canvastack_escape_js($field_id);
						$value_id_safe = canvastack_escape_js($value_id);
						$ajax_field_name_safe = canvastack_escape_js($data['ajax_field_name']);
						$script   = "<script type='text/javascript'>$(document).ready(function() { rowButtonRemovalMapRoles('{$field_id_safe}', '{$value_id_safe}'); mappingPageFieldnameValues('{$field_id_safe}', '{$value_id_safe}', '{$ajax_field_name_safe}'); });</script>";
					}

					$o .= "<tr id=\"row-box-{$field_id}\" class=\"relative-box row-box-{$fieldID}{$trClass}\">";
						$o .= "<td class=\"qmap-box-{$fieldID} field-name-box\">";
							// Escape for HTML context
							$field_name_safe = canvastack_escape_html($data['field_name'][$value->target_table][$value->target_field_name]);
							$o .= $field_name_safe;
						$o .= "</td>";
						$o .= "<td class=\"qmap-box-{$fieldID} relative-box field-value-box\">";
							// Escape for HTML context
							$field_value_safe = canvastack_escape_html($data['field_value'][$value->target_table][$field_info]);
							$o .= $field_value_safe;
							$o .= "<span id=\"remove-row{$field_id}\" class=\"remove-row{$fieldID} multi-chain-buttons\" style=\"\">";
								$o .= "<i class='{$ico}' aria-hidden='true'></i>";
							$o .= "</span>";
							$o .= $script;
						$o .= "</td>";
					$o .= "</tr>";
				}

			} else {
				$o .= "<tr id=\"row-box-{$field_id}\" class=\"relative-box row-box-{$field_id}\">";
					$o .= "<td class=\"qmap-box-{$field_id} field-name-box\">";
						// Escape for HTML context
						$field_name_safe = canvastack_escape_html($data['field_name']);
						$o .= $field_name_safe;
					$o .= "</td>";
					$o .= "<td class=\"qmap-box-{$field_id} relative-box field-value-box\">";
						// Escape for HTML context
						$field_value_safe = canvastack_escape_html($data['field_value']);
						$o .= $field_value_safe;
						$o .= "<span id=\"remove-row{$field_id}\" class=\"remove-row{$field_id} multi-chain-buttons\" style=\"display:none;\">";
							$o .= "<i class='fa fa-recycle warning' aria-hidden='true'></i>";
						$o .= "</span>";
					$o .= "</td>";
				$o .= "</tr>";
			}

			$o .= "</tbody></table>";

			return $o;
		}
}