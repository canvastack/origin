<?php
namespace Canvastack\Origin\Library\Components\Table\Craft;

use Canvastack\Origin\Models\Admin\System\DynamicTables;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

/**
 * Created on Dec 7, 2022
 * 
 * Time Created : 11:46:28 PM
 * Filename     : Export.php
 *
 * @filesource Export.php	
 *
 * @author     wisnuwidi @CanvaStack - 2022
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class Export {
	
	public  $delimeter    = '|';
	private $exportPath   = 'assets/resources/exports';
	private $allowedTypes = ['csv'];
	private $maxRecords   = 10000; // Prevent memory exhaustion
	
	/**
	 * Export data to CSV format
	 *
	 * @param string|null $path Custom export path
	 * @param string|null $link Data source link
	 * @return string|null JSON response with export path
	 */
	public function csv($path = null, $link = null) {
		return $this->process('csv', $path, $link);
	}
	
	/**
	 * Sanitize field name to prevent SQL injection
	 *
	 * @param string $fieldName Raw field name
	 * @return string Sanitized field name
	 */
	private function sanitizeFieldName($fieldName) {
		// Only allow alphanumeric, underscore, and dot for table.column notation
		return preg_replace('/[^a-zA-Z0-9_.]/', '', $fieldName);
	}

	
	/**
	 * Sanitize filter value
	 *
	 * @param mixed $value Raw value
	 * @return mixed Sanitized value
	 */
	private function sanitizeValue($value) {
		if (is_array($value)) {
			return array_map([$this, 'sanitizeValue'], $value);
		}
		
		// Strip tags and trim
		return is_string($value) ? trim(strip_tags($value)) : $value;
	}
	
	/**
	 * Validate export path to prevent path traversal
	 *
	 * @param string $path Path to validate
	 * @return string Validated path
	 * @throws \InvalidArgumentException
	 */
	private function validatePath($path) {
		// Remove any path traversal attempts
		$path = str_replace(['../', '..\\', '...'], '', $path);
		$path = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $path);
		
		// Ensure path doesn't start with /
		$path = ltrim($path, '/\\');
		
		if (empty($path)) {
			throw new \InvalidArgumentException('Invalid export path provided');
		}
		
		return $path;
	}
	
	/**
	 * Normalize filter data structure
	 *
	 * @param array $filters Raw filter data
	 * @return array Normalized filter data
	 */
	private function normalizeFilters($filters = []) {
		if (empty($filters) || !is_array($filters)) {
			return [];
		}
		
		$filterData = [];
		
		foreach ($filters as $filterItem) {
			if (!isset($filterItem['field_name']) || !isset($filterItem['value'])) {
				continue;
			}
			
			$fieldName = $this->sanitizeFieldName($filterItem['field_name']);
			
			if (is_array($filterItem['value'])) {
				foreach ($filterItem['value'] as $filterValue) {
					$filterData[$fieldName]['value'][][] = $this->sanitizeValue($filterValue);
				}
			} else {
				$filterData[$fieldName]['value'][][] = $this->sanitizeValue($filterItem['value']);
			}
		}
		
		$normalizedFilters = [];
		foreach ($filterData as $node => $nodeValues) {
			$normalizedFilters[$node]['field_name'] = $node;
			$normalizedFilters[$node]['operator']   = '=';
			foreach ($nodeValues['value'] as $values) {
				$normalizedFilters[$node]['value'][] = $values[0];
			}
		}
		
		$result = [];
		foreach ($normalizedFilters as $dataFilters) {
			$result[] = $dataFilters;
		}
		
		return $result;
	}

	
	/**
	 * Process export request
	 *
	 * @param string $type Export type (csv, etc)
	 * @param string|null $path Custom export path
	 * @param string|null $link Data source link
	 * @return string|null JSON response with export path
	 */
	private function process($type = 'csv', $path = null, $link = null) {
		try {
			// Validate export type
			if (!in_array($type, $this->allowedTypes)) {
				throw new \InvalidArgumentException("Unsupported export type: {$type}");
			}
			
			// Validate and set path
			if (empty($path)) {
				$path = $this->exportPath;
			} else {
				$path = $this->validatePath($path);
				$this->exportPath = $path;
			}
			
			// Check if export is requested
			$exportRequested = !empty($_GET['exportDataTables']) ? $_GET['exportDataTables'] : null;
			if (empty($exportRequested) || $exportRequested !== 'true') {
				return null;
			}
			
			// Get and validate link
			$encryptedLink = !empty($_POST['lurExp']) ? $_POST['lurExp'] : null;
			if (!empty($encryptedLink)) {
				$link = canvastack_decrypt($encryptedLink);
			}
			unset($_POST['lurExp']);
			unset($_POST['exportData']);
			
			if (empty($link)) {
				throw new \InvalidArgumentException('Export link is required');
			}
			
			// Get filter data from page
			$filterPage = $this->extractPageFilters();
			
			// Get table and model information
			$tableSource = !empty($_GET['difta']['name']) ? $_GET['difta']['name'] : null;
			$modelSource = !empty($_GET['difta']['source']) ? $_GET['difta']['source'] : null;
			$token       = !empty($_POST['_token']) ? $_POST['_token'] : null;
			unset($_POST['_token']);
			
			if (empty($tableSource) || empty($modelSource) || empty($token)) {
				throw new \InvalidArgumentException('Missing required export parameters');
			}
			
			// Sanitize table name
			$tableSource = $this->sanitizeFieldName($tableSource);
			
			// Get and merge filters
			$filters = $this->mergeFilters($filterPage);
			
			// Process dynamic table export
			if ($modelSource === 'dynamics') {
				$data = $this->exportDynamicTable($tableSource, $link, $filters);
				
				if (!empty($data)) {
					$user = auth()->user()->username ?? 'anonymous';
					$time = date('Ymd');
					
					// Sanitize username
					$user = preg_replace('/[^a-zA-Z0-9_-]/', '', $user);
					
					$exportPath = "{$path}/{$user}/{$token}/{$time}/{$tableSource}";
					$filename   = "{$user}-{$tableSource}";
					
					if ($type === 'csv') {
						return $this->exportCSV($data['export'], $exportPath, $filename);
					}
				}
			}
			
			return null;
			
		} catch (\Exception $e) {
			Log::error('Export process failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			return json_encode([
				'error' => true,
				'message' => 'Export failed: ' . $e->getMessage()
			]);
		}
	}

	
	/**
	 * Extract and normalize page filters
	 *
	 * @return array Normalized page filters
	 */
	private function extractPageFilters() {
		$filterPage = [];
		$filterExpData = !empty($_POST['ftrExp']) ? $_POST['ftrExp'] : null;
		
		if (!empty($filterExpData)) {
			$fDataPost = canvastack_filter_data_normalizer($filterExpData);
			unset($_POST['ftrExp']);
			
			if (!empty($fDataPost) && is_array($fDataPost)) {
				foreach ($fDataPost as $filterPostData) {
					if (isset($filterPostData['field_name']) && isset($filterPostData['value'])) {
						$fieldName = $this->sanitizeFieldName($filterPostData['field_name']);
						$filterPage[$fieldName] = $this->sanitizeValue($filterPostData['value']);
					}
				}
			}
		}
		
		return $filterPage;
	}
	
	/**
	 * Merge page filters with request filters
	 *
	 * @param array $filterPage Page filters
	 * @return array Merged filters
	 */
	private function mergeFilters($filterPage) {
		$requestData = $_POST;
		
		if (empty($filterPage)) {
			return $requestData;
		}
		
		$postsInitPage = [];
		foreach ($filterPage as $fpageName => $fpageValues) {
			$postsInitPage[$fpageName] = $fpageValues;
			
			if (isset($requestData[$fpageName])) {
				$postsInitPage[$fpageName] = $requestData[$fpageName];
				unset($requestData[$fpageName]);
			}
		}
		
		return array_merge_recursive($postsInitPage, $requestData);
	}

	
	/**
	 * Export data from dynamic table
	 *
	 * @param string $tableSource Table name
	 * @param string $link Data source link
	 * @param array $filters Query filters
	 * @return array Export data structure
	 */
	private function exportDynamicTable($tableSource, $link, $filters) {
		$model = new DynamicTables(null, $link);
		$model->setTable($tableSource);
		
		// Apply filters if provided
		if (!empty(array_filter($filters))) {
			$model = $this->applyFilters($model, $filters);
		}
		
		// Check record count to prevent memory issues
		$totalRecords = $model->count();
		if ($totalRecords > $this->maxRecords) {
			Log::warning("Export exceeds max records", [
				'table' => $tableSource,
				'count' => $totalRecords,
				'max' => $this->maxRecords
			]);
		}
		
		$data = [
			$tableSource => [
				'model' => get_class($model),
				'export' => [
					'head' => [],
					'values' => []
				]
			]
		];
		
		// Use chunk to prevent memory exhaustion
		$index = 0;
		$model->chunk(1000, function ($records) use (&$data, $tableSource, &$index) {
			foreach ($records as $record) {
				foreach ($record->getAttributes() as $fieldname => $fieldvalue) {
					$data[$tableSource]['export']['head'][$fieldname] = $fieldname;
					$data[$tableSource]['export']['values'][$index][$fieldname] = $fieldvalue;
				}
				$index++;
			}
		});
		
		return $data[$tableSource];
	}

	
	/**
	 * Apply filters to query builder
	 *
	 * @param mixed $model Query builder instance
	 * @param array $filters Filters to apply
	 * @return mixed Modified query builder
	 */
	private function applyFilters($model, $filters) {
		$filterData = [];
		
		// Separate simple and array filters
		foreach ($filters as $fieldName => $fieldValue) {
			if (empty($fieldValue)) {
				continue;
			}
			
			$sanitizedFieldName = $this->sanitizeFieldName($fieldName);
			
			if (is_array($fieldValue)) {
				$cleanValues = [];
				foreach ($fieldValue as $n => $fvalue) {
					if (!empty($fvalue)) {
						$cleanValues[$n] = $this->sanitizeValue($fvalue);
					}
				}
				if (!empty($cleanValues)) {
					$filterData[$sanitizedFieldName] = $cleanValues;
				}
			} else {
				$filterData[$sanitizedFieldName] = $this->sanitizeValue($fieldValue);
			}
		}
		
		// Apply simple where conditions
		$simpleFilters = [];
		foreach ($filterData as $fieldData => $fieldValues) {
			if (!is_array($fieldValues)) {
				$simpleFilters[$fieldData] = $fieldValues;
			}
		}
		
		if (!empty($simpleFilters)) {
			$model = $model->where($simpleFilters);
		}
		
		// Apply whereIn conditions
		foreach ($filterData as $fieldData => $fieldValues) {
			if (is_array($fieldValues)) {
				$model->whereIn($fieldData, $fieldValues);
			}
		}
		
		return $model;
	}

	
	/**
	 * Generate export file
	 *
	 * @param string $type Export type
	 * @param array $data Export data
	 * @param string|null $path Export path
	 * @param string $filename Export filename
	 * @return string JSON response with export path
	 * @throws \RuntimeException
	 */
	private function generate($type = 'csv', $data = [], $path = null, $filename = 'canvastackExportData') {
		try {
			// Validate inputs
			if (empty($data) || !isset($data['head']) || !isset($data['values'])) {
				throw new \InvalidArgumentException('Invalid export data structure');
			}
			
			// Sanitize filename
			$filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
			if (empty($filename)) {
				$filename = 'canvastackExportData';
			}
			
			// Validate path
			$path = $this->validatePath($path);
			
			$pathFile = public_path();
			$fullPath = $pathFile . DIRECTORY_SEPARATOR . $path;
			
			// Create directory with secure permissions (0755 instead of 0777)
			if (!file_exists($fullPath)) {
				if (!canvastack_make_dir($fullPath, 0755, true, true)) {
					throw new \RuntimeException("Failed to create export directory: {$fullPath}");
				}
			}
			
			$filepath = $fullPath . DIRECTORY_SEPARATOR . "{$filename}.{$type}";
			$filepath = str_replace(['\\/', '/\\'], DIRECTORY_SEPARATOR, $filepath);
			
			$headers = [
				'Content-Type'        => 'text/' . $type,
				'Content-Disposition' => 'attachment; filename="' . $filename . '.' . $type . '"',
				'Pragma'              => 'no-cache',
				'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
				'Expires'             => '0'
			];
			
			$columns = $data['head'];
			$values  = $data['values'];
			
			// Prepare rows
			$rows = [];
			foreach ($values as $i => $valueData) {
				foreach ($valueData as $fieldname => $value) {
					$rows[$i][$fieldname] = $value;
				}
			}
			
			// Create file based on type
			if ($type === 'csv') {
				$this->createFileCSV($filepath, $columns, $rows);
			}
			
			// Generate download URL
			$uri = $this->generateDownloadUrl($filepath, $path, $filename, $type);
			
			// Return download information (removed Response::streamDownload as it was incorrectly used)
			return json_encode([
				'canvastackExportStreamPath' => $uri,
				'filename' => "{$filename}.{$type}",
				'headers' => $headers
			]);
			
		} catch (\Exception $e) {
			Log::error('Export generation failed', [
				'error' => $e->getMessage(),
				'type' => $type,
				'filename' => $filename
			]);
			
			throw new \RuntimeException('Failed to generate export: ' . $e->getMessage());
		}
	}

	
	/**
	 * Generate download URL for exported file
	 *
	 * @param string $filepath Full file path
	 * @param string $path Relative path
	 * @param string $filename Filename
	 * @param string $type File type
	 * @return string Download URL
	 */
	private function generateDownloadUrl($filepath, $path, $filename, $type) {
		$baseUrl = canvastack_config('baseURL');
		
		if (false === canvastack_string_contained($baseUrl, 'public')) {
			return url($baseUrl . '/' . $path . '/' . $filename . '.' . $type);
		} else {
			$publicPath = explode('public', $filepath);
			if (isset($publicPath[1])) {
				return url()->asset(str_replace('\\', '/', $publicPath[1]));
			}
			
			// Fallback
			return url($path . '/' . $filename . '.' . $type);
		}
	}
	
	/**
	 * Create CSV file from data
	 *
	 * @param string $filepath Full file path
	 * @param array $columns Column headers
	 * @param array $rows Data rows
	 * @return bool Success status
	 * @throws \RuntimeException
	 */
	private function createFileCSV($filepath, $columns, $rows) {
		$handle = null;
		
		try {
			// Prepare column headers
			$columnHeaders = [];
			foreach ($columns as $column) {
				$columnLabel = ucwords(str_replace('_', ' ', $column));
				$columnHeaders[] = $columnLabel;
			}
			
			// Open file for writing
			$handle = fopen($filepath, 'w');
			if ($handle === false) {
				throw new \RuntimeException("Failed to open file for writing: {$filepath}");
			}
			
			// Write headers
			fputcsv($handle, $columnHeaders, $this->delimeter);
			
			// Write data rows
			foreach ($rows as $row) {
				// Sanitize row data - replace semicolons and ensure proper encoding
				$sanitizedRow = array_map(function($value) {
					if (is_null($value)) {
						return '';
					}
					return str_replace(';', ' ', (string)$value);
				}, $row);
				
				fputcsv($handle, $sanitizedRow, $this->delimeter);
			}
			
			fclose($handle);
			
			// Set secure file permissions (0644 instead of default)
			chmod($filepath, 0644);
			
			return true;
			
		} catch (\Exception $e) {
			if (isset($handle) && is_resource($handle)) {
				fclose($handle);
			}
			
			Log::error('CSV file creation failed', [
				'error' => $e->getMessage(),
				'filepath' => $filepath
			]);
			
			throw new \RuntimeException('Failed to create CSV file: ' . $e->getMessage());
		}
	}
	
	/**
	 * Export to CSV format
	 *
	 * @param array $data Export data
	 * @param string|null $path Export path
	 * @param string $filename Export filename
	 * @return string JSON response with export path
	 */
	private function exportCSV($data, $path = null, $filename = 'canvastackExportDataCSV') {
		return $this->generate('csv', $data, $path, $filename);
	}
}
