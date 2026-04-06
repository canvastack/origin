<?php
namespace Canvastack\Origin\Controllers\Admin\System\Includes;

use Canvastack\Origin\Models\Admin\System\Modules;
use Canvastack\Origin\Models\Admin\System\Privilege;
use Canvastack\Origin\Library\Constants\SafeHtml;

/**
 * Created on Jan 19, 2018
 * Time Created	: 17:58:08
 *
 * @filesource	Privileges.php
 *
 * @author		wisnuwidi@canvastack.com - 2021
 * @copyright	wisnuwidi
 * @email		wisnuwidi@canvastack.com
 */

trait Privileges {
	
	private $roles              = [];
	private $group_privileges   = [];
	private $menu_privileges    = [];
	private $viewIndexPrivilege = false;
	private $admin_privilege    = 'admin_privilege';
	private $index_privilege    = 'index_privilege';
	private $table_privilege	 = 'base_group_privilege';
		
	private function check_data($group_id, $module_id) {
		$data = canvastack_query($this->table_privilege)
			->where('group_id', $group_id)
			->where('module_id', $module_id)
			->first();
		
		return $data;
	}
	
	private function check_group($group_id) {
		$data = canvastack_query($this->table_privilege)
			->where('group_id', $group_id)
			->first();
		
		return $data;
	}
	
	private function get_group_privileges($group_id) {
		$this->group_privileges = canvastack_query($this->table_privilege)->where('group_id', $group_id)->get();
	}
	
	private function privileges_before_insert($request, $group) {
		
		$dataRequest = $request->all();
		if (true === is_multiplatform()) {
			$platform_key	= $dataRequest[$this->platform_key];
		}
		
		if (isset($dataRequest['modules'])) {
			if (!empty($group)) {
				
				foreach ($dataRequest as $modules => $dataModules) {
					if ('modules' === $modules) {
						
						foreach ($dataModules as $pageName => $dataRoutes) {
							foreach ($dataRoutes as $modulePrivileges) {
								foreach ($modulePrivileges as $privilege => $moduleId) {
									
									$privilege_info = false;
									
									if (8 === intval($privilege)) $privilege_info = 'read';
									if (4 === intval($privilege)) $privilege_info = 'insert';
									if (2 === intval($privilege)) $privilege_info = 'update';
									if (1 === intval($privilege)) $privilege_info = 'delete';
									
									$this->roles[$moduleId]['group_id']  = intval($group->id);
									$this->roles[$moduleId]['module_id'] = intval($moduleId);
									if (true === is_multiplatform()) $this->roles[$moduleId][$this->platform_key]	= intval($platform_key);
									$this->roles[$moduleId][$pageName][$privilege_info] = intval($privilege);
								}
							}
						}
					}
				}
			}
			
		} else {
			$this->roles['setnull']['group_id']  = intval($group->id);
		}
		
		$request->offsetUnset('modules');
	}
	
	private function privileges_after_insert($data) {
		$nullset = null;
		$groups  = false;
		$IDP     = $this->index_privilege;
		$ADP     = $this->admin_privilege;
		
		if (isset($data['setnull'])) {
			$nullGroup = intval($data['setnull']['group_id']);
			canvastack_query($this->table_privilege)->where('group_id', $nullGroup)->update([$IDP => $nullset, $ADP => $nullset]);
		} else {
			
			foreach ($data as $moduleId => $roles) $groups = $roles['group_id'];
			canvastack_query($this->table_privilege)->where('group_id', $groups)->update([$IDP => $nullset, $ADP => $nullset]);
			
			$request = [];
			foreach ($data as $moduleId => $roles) {
				$request['group_id']  = $roles['group_id'];
				$request['module_id'] = $roles['module_id'];
				$request[$IDP]        = $nullset;
				$request[$ADP]        = $nullset;
				
				foreach ($roles as $role_info => $role_value) {
					if ($IDP === $role_info || $ADP === $role_info) {
						$request[$role_info] = implode(':', array_values($role_value));
					}
				}
				
				$check_role	= $this->check_data($request['group_id'], $request['module_id']);
				if (intval($moduleId) === intval($request['module_id'])) {
					
					if (is_empty($check_role)) {
						// Kalo data ada yang baru
						canvastack_insert(new Privilege, $request, true);
					} else {
						// Kalo data sudah ada di database table
						if (intval($check_role->module_id) === intval($request['module_id'])) {
							canvastack_update(Privilege::find($check_role->id), $request, true);
						}
					}
				}
			}
		}
	}
	
	protected $module_class = [];
	/**
	 * Render Modular Menu Data
	 *
	 * created @Sep 11, 2018
	 * author: wisnuwidi
	 */
	private function get_menu() {
		$this->module_class = Modules::where('active', 1)->get();
		$modules            = $this->module_class;
		$menuObj            = $modules;
		$routeData          = [];
		$parentMenu         = [];
		$mainMenu           = [];
		
		foreach ($menuObj as $menuArray) {
			$menuData = $menuArray->getAttributes();
			
			$routeData[$menuData['route_path']]['id']    = $menuData['id'];
			$routeData[$menuData['route_path']]['name']  = $menuData['module_name'];
			$routeData[$menuData['route_path']]['route'] = $menuData['route_path'];
			$routeData[$menuData['route_path']]['url']   = route("{$menuData['route_path']}.index");
			$routeData[$menuData['route_path']]['icon']  = $menuData['icon'];
		}
		
		foreach ($routeData as $key => $value) {
			$key = explode('.', $key);
			
			if (count($key) === 1) {
				$parentMenu[$key[0]]        = $key[0];
				$mainMenu[$key[0]][$key[0]] = $value;
			}
			if (count($key) === 2 && !empty($key[1])) {
				$parentMenu[$key[0]][$key[1]] = $key[1];
				$mainMenu[$key[0]][$key[1]]   = $value;
			}
			if (count($key) === 3 && !empty($key[2])) {
				$parentMenu[$key[0]][$key[1]][$key[2]] = $key[2];
				$mainMenu[$key[0]][$key[1]][$key[2]]   = $value;
			}
			if (count($key) === 4 && !empty($key[3])) {
				$parentMenu[$key[0]][$key[1]][$key[2]][$key[3]] = $key[3];
				$mainMenu[$key[0]][$key[1]][$key[2]][$key[3]]   = $value;
			}
		}
		
		$this->menu_privileges = canvastack_array_to_object_recursive($mainMenu);
	}
	
	/**
	 * Centering Row Table Attributes
	 *
	 * created @Sep 11, 2018
	 * author: wisnuwidi
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private function _center($string) {
		return canvastack_table_row_attr($string, ['align' => 'center', 'valign' => 'middle', 'class' => 'privilege-subheader']);
	}
	
	public $module_privileges = [];
	/**
	 * Check Module Privileges
	 *
	 * created @Dec 10, 2018
	 * author: wisnuwidi
	 *
	 * @param string $index : index privileges [ $this->index_privilege ]
	 * @param string $admin : admin privileges [ $this->admin_privilege ]
	 *
	 * @access: [ 8:read|select, 4:write|insert, 2:modify|update, 1:destroy|delete ]
	 *
	 * @return array
	 */
	private function check_module_privileges($index, $admin) {
		$roles = [];
		$urli  = explode('/', url()->current());
		if ('edit' === last($urli)) {
			unset($urli[count($urli)-1]);
			$this->id = intval(last($urli));
		}
		
		if (isset($this->id)) $this->get_group_privileges($this->id);
		
		if (count($this->group_privileges) >= 1) {
			foreach ($this->group_privileges as $role) {
				// INFO: [ 8:read|select, 4:write|insert, 2:modify|update, 1:destroy|delete ]
				
				$frontend = explode(':', $role->{$index});
				foreach ($frontend as $index_role) $roles[$index][$role->module_id][$index_role] = $index_role;
				
				$backend  = explode(':', $role->{$admin});
				foreach ($backend  as $admin_role) $roles[$admin][$role->module_id][$admin_role] = $admin_role;
			}
		}
		
		$this->module_privileges = $roles;
	}
	
	/**
	 * Get Module Privileges
	 *
	 * created @Dec 10, 2018
	 * author: wisnuwidi
	 *
	 * @return array
	 */
	private function get_module_privileges() {
		return $this->check_module_privileges($this->index_privilege, $this->admin_privilege);
	}
	
	/**
	 * Render Checkboxes Privilege.
	 *
	 * @tutorial: Note for Privileges Data Value Information [ 8: read|select, 4: write|insert, 2: modify|update, 1: destroy|delete ]
	 *
	 * @param string $module_name
	 * @param array $module_data
	 * @param string $icon
	 *
	 * @return array[]
	 */
	private function _checkboxes($module_name, $module_data, $icon, $indentClass = 'privilege-indent-0') {
		$this->get_module_privileges();
		$routeName = strtolower($module_data->route);
		
		// Frontend Privileges
		if (true === $this->viewIndexPrivilege) {
			$IDP                    = $this->index_privilege;
			$checkedIndex           = [];
			$checkedIndex['read']   = canvastack_form_checkList("modules[{$IDP}][{$routeName}][8]", $module_data->id, false, false, 'success read-select privilege-visible-checkbox');
			$checkedIndex['write']  = canvastack_form_checkList("modules[{$IDP}][{$routeName}][4]", $module_data->id, false, false, 'lilac write-insert privilege-visible-checkbox');
			$checkedIndex['modify'] = canvastack_form_checkList("modules[{$IDP}][{$routeName}][2]", $module_data->id, false, false, 'warning modify-update privilege-visible-checkbox');
			$checkedIndex['delete'] = canvastack_form_checkList("modules[{$IDP}][{$routeName}][1]", $module_data->id, false, false, 'danger delete-destroy privilege-visible-checkbox');
			
			if (isset($this->module_privileges[$IDP][$module_data->id])) {
				if (isset($this->module_privileges[$IDP][$module_data->id]['8']) && $this->module_privileges[$IDP][$module_data->id]['8'] >= 1)
					$checkedIndex['read']   = canvastack_form_checkList("modules[{$IDP}][{$routeName}][8]", $module_data->id, false, true, 'success read-select privilege-visible-checkbox');
				if (isset($this->module_privileges[$IDP][$module_data->id]['4']) && $this->module_privileges[$IDP][$module_data->id]['4'] >= 1)
					$checkedIndex['write']  = canvastack_form_checkList("modules[{$IDP}][{$routeName}][4]", $module_data->id, false, true, 'lilac write-insert privilege-visible-checkbox');
				if (isset($this->module_privileges[$IDP][$module_data->id]['2']) && $this->module_privileges[$IDP][$module_data->id]['2'] >= 1)
					$checkedIndex['modify'] = canvastack_form_checkList("modules[{$IDP}][{$routeName}][2]", $module_data->id, false, true, 'warning modify-update privilege-visible-checkbox');
				if (isset($this->module_privileges[$IDP][$module_data->id]['1']) && $this->module_privileges[$IDP][$module_data->id]['1'] >= 1)
					$checkedIndex['delete'] = canvastack_form_checkList("modules[{$IDP}][{$routeName}][1]", $module_data->id, false, true, 'danger delete-destroy privilege-visible-checkbox');
			}
		}
		
		// Backend Privileges
		$ADP                    = $this->admin_privilege;
		$checkedAdmin           = [];
		$checkedAdmin['read']	= canvastack_form_checkList("modules[{$ADP}][{$routeName}][8]", $module_data->id, false, false, 'success read-select privilege-visible-checkbox');
		$checkedAdmin['write']	= canvastack_form_checkList("modules[{$ADP}][{$routeName}][4]", $module_data->id, false, false, 'lilac write-insert privilege-visible-checkbox');
		$checkedAdmin['modify']	= canvastack_form_checkList("modules[{$ADP}][{$routeName}][2]", $module_data->id, false, false, 'warning modify-update privilege-visible-checkbox');
		$checkedAdmin['delete']	= canvastack_form_checkList("modules[{$ADP}][{$routeName}][1]", $module_data->id, false, false, 'danger delete-destroy privilege-visible-checkbox');
		
		if (isset($this->module_privileges[$ADP][$module_data->id])) {
			// Backend Privileges
			if (isset($this->module_privileges[$ADP][$module_data->id]['8']) && $this->module_privileges[$ADP][$module_data->id]['8'] >= 1)
				$checkedAdmin['read']   = canvastack_form_checkList("modules[{$ADP}][{$routeName}][8]", $module_data->id, false, true, 'success read-select privilege-visible-checkbox');
			if (isset($this->module_privileges[$ADP][$module_data->id]['4']) && $this->module_privileges[$ADP][$module_data->id]['4'] >= 1)
				$checkedAdmin['write']  = canvastack_form_checkList("modules[{$ADP}][{$routeName}][4]", $module_data->id, false, true, 'lilac write-insert privilege-visible-checkbox');
			if (isset($this->module_privileges[$ADP][$module_data->id]['2']) && $this->module_privileges[$ADP][$module_data->id]['2'] >= 1)
				$checkedAdmin['modify'] = canvastack_form_checkList("modules[{$ADP}][{$routeName}][2]", $module_data->id, false, true, 'warning modify-update privilege-visible-checkbox');
			if (isset($this->module_privileges[$ADP][$module_data->id]['1']) && $this->module_privileges[$ADP][$module_data->id]['1'] >= 1)
				$checkedAdmin['delete'] = canvastack_form_checkList("modules[{$ADP}][{$routeName}][1]", $module_data->id, false, true, 'danger delete-destroy privilege-visible-checkbox');
		}
		
		$opt                = ['align' => 'center', 'id' => strtolower($module_name) . '-row', 'class' => 'privilege-checkbox-cell'];
		$resultBox          = [];
		// Concatenate icon with module name then mark as safe HTML
		$headContent        = SafeHtml::unmark($icon) . $module_name;
		$resultBox['head']  = [canvastack_table_row_attr(SafeHtml::mark($headContent), ['class' => 'privilege-module-name ' . $indentClass, 'id' => strtolower($module_name) . '-row'])];
		$resultBox['admin'] = [
			canvastack_table_row_attr($checkedAdmin['read'],   $opt),
			canvastack_table_row_attr($checkedAdmin['write'],  $opt),
			canvastack_table_row_attr($checkedAdmin['modify'], $opt),
			canvastack_table_row_attr($checkedAdmin['delete'], $opt)
		];
		if (true === $this->viewIndexPrivilege) {
			$resultBox['index'] = [
				canvastack_table_row_attr($checkedIndex['read'],   $opt),
				canvastack_table_row_attr($checkedIndex['write'],  $opt),
				canvastack_table_row_attr($checkedIndex['modify'], $opt),
				canvastack_table_row_attr($checkedIndex['delete'], $opt)
			];
		} else {
			$resultBox['index'] = [];
		}
		
		$o = array_merge_recursive($resultBox['head'], $resultBox['admin'], $resultBox['index']);
		
		return $o;
	}
	
	/**
	 * Render Group Privileges Table
	 *
	 * created @Sep 11, 2018
	 * author: wisnuwidi
	 *
	 * @return string
	 */
	private function group_privilege() {
		$rowData     = [];
		$row_table   = [];
		$icon        = SafeHtml::mark('<i class="fa fa-caret-right privilege-icon"></i>');
		$dataCenter  = [
			$this->_center('Read'),
			$this->_center('Insert'),
			$this->_center('Update'),
			$this->_center('Delete'),
		];
		
		// Calculate total columns dynamically
		$totalColumns = 1; // Module Name column
		$totalColumns += 4; // Backend Privilege (4 columns)
		if (true === $this->viewIndexPrivilege) {
			$totalColumns += 4; // Frontend Privilege (4 columns)
		}
		
		foreach ($this->menu_privileges as $parent => $childs) {
			$parent_title	= ucwords(str_replace('_', ' ', $parent));
			if (!empty($childs->name)) $parent_title = $childs->name;
			// Unmark icon, concatenate, then mark as safe HTML
			$parentContent = SafeHtml::unmark($icon) . $parent_title;
			$row_table[]	= [canvastack_table_row_attr(SafeHtml::mark($parentContent), ['class' => 'privilege-module-name privilege-indent-0 privilege-group-row', 'colspan' => $totalColumns])];
			
			foreach ($childs as $child_name => $data_module) {
				if (isset($data_module->id) === false) {
					$child_title	= ucwords(str_replace('_', ' ', $child_name));
					if (!empty($data_module->name)) $child_title = $data_module->name;
					
					// Unmark icon, concatenate, then mark as safe HTML
					$childContent = SafeHtml::unmark($icon) . $child_title;
					$row_table[]	= [canvastack_table_row_attr(SafeHtml::mark($childContent), ['class' => 'privilege-module-name privilege-indent-1', 'colspan' => $totalColumns])];
					foreach ($data_module as $module_name => $module_data) {
						
						if (!empty($module_data->id)) {
							$module_title = ucwords(str_replace('_', ' ', $module_name));
							if (!empty($module_data->name)) $module_title = $module_data->name;
							
							$row_table[] = $this->_checkboxes($module_title, $module_data, $icon, 'privilege-indent-2');
						} else {
							
							$module_title = ucwords(str_replace('_', ' ', $module_name));
							if (!empty($module_data->name)) $module_title = $module_data->name;
							
							// Unmark icon, concatenate, then mark as safe HTML
							$moduleContent = SafeHtml::unmark($icon) . $module_title;
							$row_table[] = [canvastack_table_row_attr(SafeHtml::mark($moduleContent), ['class' => 'privilege-module-name privilege-indent-2', 'colspan' => $totalColumns])];
							foreach ($module_data as $third_name => $third_data) {
								$third_title = ucwords(str_replace('_', ' ', $third_name));
								if (!empty($third_data->name)) $third_title = $third_data->name;
								
								$row_table[] = $this->_checkboxes($third_title, $third_data, $icon, 'privilege-indent-3');
							}
						}
					}
				} else {
					
					$child_title = ucwords(str_replace('_', ' ', $child_name));
					if (!empty($data_module->name)) $child_title = $data_module->name;
					
					$row_table[] = $this->_checkboxes($child_title, $data_module, $icon, 'privilege-indent-1');
				}
			}
		}
		
		// Build header with proper structure for rowspan
		// We need to use the 'merge' key to create second header row
		$header = [];
		
		// Module Name column with rowspan=2 and merge for second row
		$header[] = [
			'column' => canvastack_table_row_attr('Module Name', ['rowspan' => 2, 'class' => 'privilege-header-module']),
			'merge'  => $dataCenter // Backend sub-headers (Read, Insert, Update, Delete)
		];
		
		// Backend Privilege header (colspan=4)
		$header[] = canvastack_table_row_attr('Backend Privilege', ['colspan' => 4, 'class' => 'privilege-header-backend']);
		
		// Frontend Privilege header (colspan=4) if enabled
		if (true === $this->viewIndexPrivilege) {
			// Add Frontend sub-headers to the merge array of first column
			$header[0]['merge'] = array_merge($header[0]['merge'], $dataCenter);
			$header[] = canvastack_table_row_attr('Frontend Privilege', ['colspan' => 4, 'class' => 'privilege-header-frontend']);
		}
		
		$title_id = 'group_privileges_' . canvastack_random_strings(50, false);
		
		// Add privilege-table class to table attributes
		$tableAttributes = [
			'id'    => "datatable-{$title_id}",
			'class' => 'table privilege-table'
		];
		
		// Generate table with custom class
		$tableHtml = canvastack_generate_table('Set Role Module Page', $title_id, $header, $row_table, $tableAttributes, false, false);
				
		// Wrap table with privilege-table-container and add script
		return '<div class="privilege-table-container">' . $tableHtml . '</div>';
	}
}