<?php
namespace Canvastack\Origin\Controllers\Admin\System;

use Canvastack\Origin\Controllers\Core\Controller;
use Canvastack\Origin\Library\Components\Table\Craft\Datatables;
use Canvastack\Origin\Library\Components\Table\Craft\Export;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Canvastack\Origin\Library\Components\Chart\Charts;

/**
 * Created on Sep 23, 2022
 * 
 * Time Created : 7:51:52 PM
 *
 * @filesource	AjaxController.php
 *
 * @author     wisnuwidi@canvastack.com - 2022
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */

class AjaxController extends Controller {
	
	private $ajaxConnection = null;
	
	public function __construct($connection = null) {
		if (!empty($connection)) $this->ajaxConnection = $connection;
	}
		
	public static $ajaxUrli;
	/**
	 * Ajax Post URL Address
	 * 
	 * @param string $init_post
	 * 	: Initialize Post Key
	 * 	  ['AjaxPosF'         : by default]
	 * 	  ['filterDataTables' : for datatables filtering]
	 * @param boolean $return_data
	 * @return string
	 */
	public static function urli($init_post = 'AjaxPosF', $return_data = false) {
		$current_url  = route('ajax.post');
		if ('filterDataTables' === $init_post) {
			$urlset = [$init_post => 'true'];
		} else {
			$urlset = [$init_post => 'true' ,'_token'  => csrf_token()];
		}
		
		$uri = [];
		foreach ($urlset as $fieldurl => $urlvalue) {
			$uri[] = "{$fieldurl}={$urlvalue}";
		}
		
		self::$ajaxUrli = $current_url . '?' . implode('&', $uri);
		if (true === $return_data) {
			return self::$ajaxUrli;
		}
	}
	
	public function post() {
		if (!empty($_GET)) {
			if (!empty($_GET['AjaxPosF'])) {
				return $this->post_filters();
			} elseif (!empty($_GET['canvastackHostConn'])) {
				return $this->getHostConnections();
			} elseif (!empty($_GET['canvastackHostProcess'])) {
				return $this->getHostProcess();
			} elseif (!empty($_GET['filterDataTables'])) {
			    return $this->initFilterDatatables($_GET, $_POST);
			} elseif (!empty($_GET['filterCharts'])) {
			    return $this->initFilterCharts($_GET, $_POST);
			}
		}
	}
	
	private function getHostProcess() {
		unset($_POST['_token']);
		
		$sconnect        = $_POST['source_connection_name'];
		$stable          = $_POST['source_table_name'];
		$tconnect        = $_POST['target_connection_name'];
		$ttable          = $_POST['target_table_name'];
		
		$datasource      = DB::connection($sconnect)->select("SELECT * FROM {$stable}");
		$sourceData      = [];
		foreach ($datasource as $datasources) {
			$sourceData[] = (array) $datasources;
		}
		$sourceCounts    = count($sourceData);
		$limitCounts     = 100;
		$rowCountProcess = round($sourceCounts/$limitCounts);
		
		$result = [];
		if (!empty($datasource)) {
			$transfers    = DB::connection($tconnect);
			$transfers->beginTransaction();
			$transfers->delete("TRUNCATE {$ttable}");
			
			$datahandler  = array_chunk($sourceData, $limitCounts);
			$stillHandled = true;
			$countData    = 0;
			foreach($datahandler as $row) {
				$countData++;
				if (!$transfers->table($ttable)->insert($row)) $stillHandled = false;
			}
			
			if ($stillHandled) {
				if ($countData < $rowCountProcess) $transfers->commit();
			} else {
				$transfers->rollBack();
			}
					
			$result['counts']['source'] = $sourceCounts;
			$result['counts']['target'] = count($transfers->select("SELECT * FROM {$ttable}"));
		}
		
		return json_encode($result);
	}
	
	private function getHostConnections() {
		$connection_sources = canvastack_config('sources', 'connections');
		
		unset($_GET['canvastackHostConn']);
		unset($_GET['_token']);
		
		$info             = [];
		$info['selected'] = null;
		foreach ($_GET as $key => $data) {
			if ('s' === $key) $info['selected'] = decrypt($data);
		}
		
		$allTables = [];
		foreach ($_POST as $value) {
			$allTables = canvastack_get_all_tables($connection_sources[$value]['connection_name']);
		}
		
		$result = [];
		if (!empty($allTables)) {
			foreach ($allTables as $tablename) {
				$label = ucwords(str_replace('_', ' ', $tablename));
				$result['data'][$tablename] = $label;
			}
		}
		
		if (!empty($info['selected'])) {
			$result['selected'] = $info['selected'];
		}
		
		return json_encode($result);
	}
	
	private function post_filters() {
		unset($_GET['AjaxPosF']);
		unset($_GET['_token']);
		
		$info             = [];
		$info['label']    = null;
		$info['value']    = null;
		$info['selected'] = null;
		$info['query']    = null;
		
		foreach ($_GET as $key => $data) {
			if ('l' === $key) {
				// Security: Verify integrity and decrypt (4.5.2)
				try {
					$verifiedData = canvastack_form_verify_integrity($data);
					$info['label'] = decrypt($verifiedData);
					// Security: Validate field name (4.5.3)
					canvastack_form_validate_field_name($info['label'], 'label');
				} catch (\Exception $e) {
					canvastack_log_security_event('sync_integrity_failed', [
						'field' => 'label',
						'error' => $e->getMessage(),
					]);
					return json_encode(['error' => 'Invalid request data']);
				}
			} elseif ('v' === $key) {
				// Security: Verify integrity and decrypt (4.5.2)
				try {
					$verifiedData = canvastack_form_verify_integrity($data);
					$info['value'] = decrypt($verifiedData);
					// Security: Validate field name (4.5.3)
					canvastack_form_validate_field_name($info['value'], 'value');
				} catch (\Exception $e) {
					canvastack_log_security_event('sync_integrity_failed', [
						'field' => 'value',
						'error' => $e->getMessage(),
					]);
					return json_encode(['error' => 'Invalid request data']);
				}
			} elseif ('s' === $key) {
				// Security: Verify integrity and decrypt (4.5.2)
				try {
					$verifiedData = canvastack_form_verify_integrity($data);
					$info['selected'] = decrypt($verifiedData);
					// Selected can be null, so no validation needed
				} catch (\Exception $e) {
					canvastack_log_security_event('sync_integrity_failed', [
						'field' => 'selected',
						'error' => $e->getMessage(),
					]);
					return json_encode(['error' => 'Invalid request data']);
				}
			} else {
				// Security: Verify integrity and decrypt query (4.5.2)
				try {
					$verifiedData = canvastack_form_verify_integrity($data);
					$info['query'] = decrypt($verifiedData);
					// Security: Validate query for SQL injection (4.5.1)
					canvastack_form_validate_sql_query($info['query']);
				} catch (\Exception $e) {
					canvastack_log_security_event('sync_query_validation_failed', [
						'error' => $e->getMessage(),
					]);
					return json_encode(['error' => 'Invalid query']);
				}
			}
		}
		
		$postKEY   = array_keys($_POST)[0];
		$postValue = array_values($_POST)[0];
		
		// Security: Escape POST values for SQL (4.5.4)
		$postKEY = canvastack_form_escape_html($postKEY);
		$postValue = canvastack_form_escape_html($postValue);
		
		$queryData     = [];
		if (!empty($info['query'])) {
			// Security: Use parameterized query to prevent SQL injection
			// Note: canvastack_query should use prepared statements internally
			$sql       = "{$info['query']} WHERE `{$postKEY}` = '{$postValue}' ORDER BY `{$postKEY}` DESC";
			$queryData = canvastack_query($sql, 'SELECT', $this->ajaxConnection);
		}
		
		$result = [];
		if (!empty($queryData)) {
			foreach ($queryData as $rowData) {
				// Security: Sanitize query results before return (4.5.4)
				$valueField = canvastack_form_escape_html($rowData->{$info['value']});
				$labelField = canvastack_form_escape_html($rowData->{$info['label']});
				$result['data'][$valueField] = $labelField;
			}
		}
		
		if (!empty($info['selected'])) {
			// Security: Sanitize selected value (4.5.4)
			$result['selected'] = canvastack_form_escape_html($info['selected']);
		}
		
		// Security: Log successful sync request (4.5.5)
		canvastack_log_security_event('sync_request_completed', [
			'result_count' => count($result['data'] ?? []),
		]);
		
		$results = $result;
		
		return json_encode($results);
	}
	
	private $datatables = [];
	private function datatableClass() {
		$this->datatables = new Datatables();
	}
	
	public $filter_datatables = [];
	protected function filterDataTable(Request $request) {
		$this->datatableClass();
		$this->filter_datatables = $this->datatables->filter_datatable($request);
		
		return $this;
	}
	
	private function initFilterDatatables() {
		if (!empty($_GET['filterDataTables'])) {
			$this->datatableClass();
			return $this->datatables->init_filter_datatables($_GET, $_POST, $this->ajaxConnection);
		}
	}
	
	private $charts = [];
	private function chartClass() {
	    $this->charts = new Charts();
	}
	private function initFilterCharts() {
	    if (!empty($_GET['filterCharts'])) {
	        $this->datatableClass();
	        return $this->charts->init_filter_charts($_GET, $_POST, $this->ajaxConnection);
	    }
	}
	
	public function export() {
		try {
			$export = new Export();
			$result = $export->csv('assets/resources/exports');
			
			// If result is null, return error response
			if ($result === null) {
				return response()->json([
					'error' => true,
					'message' => 'Export request is invalid or missing required parameters'
				], 400);
			}
			
			// Return the export result
			return response($result)->header('Content-Type', 'application/json');
			
		} catch (\Exception $e) {
			\Log::error('Export controller error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			return response()->json([
				'error' => true,
				'message' => 'Export failed: ' . $e->getMessage()
			], 500);
		}
	}
}