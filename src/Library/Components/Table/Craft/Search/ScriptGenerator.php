<?php
namespace Canvastack\Origin\Library\Components\Table\Craft\Search;

use Canvastack\Origin\Library\Components\Table\Craft\Search\Config\SearchConfig;

/**
 * ScriptGenerator - JavaScript generation for Search component
 *
 * @filesource ScriptGenerator.php
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class ScriptGenerator {
	
	private $config;
	private $scriptToHTML;
	private $addScripts = [];
	
	/**
	 * Constructor
	 *
	 * @param SearchConfig $config Configuration object
	 */
	public function __construct(SearchConfig $config) {
		$this->config = $config;
		$this->scriptToHTML = SearchConfig::SCRIPT_NODE_PREFIX;
	}
	
	/**
	 * Generate scripts for cascading selects
	 * REFACTORED: Reduced complexity by extracting sub-methods
	 *
	 * @param array $element_scripts Element scripts
	 * @param string $table Table name
	 * @param string $node Node identifier
	 * @param array $filters Additional filters
	 * @return void
	 */
	public function generateScripts($element_scripts, $table, $node, $filters = []) {
		$current_template = canvastack_template_config('admin.' . canvastack_current_template());

		// FIX: Validate template config
		if (empty($current_template) || !is_array($current_template)) {
			\Log::warning('Search: Invalid template config', [
				'template' => canvastack_current_template(),
				'node' => $node
			]);
			return;
		}

		unset($current_template['position']);

		$nodElm = str_replace('modalBOX', '', $node);
		$scriptElements = array_keys($element_scripts[$nodElm]);

		// Generate field scripts
		$this->generateFieldScripts($node, $scriptElements, $table, $filters);

		// Load template assets
		$this->loadTemplateAssets($element_scripts[$nodElm], $current_template);
	}

	/**
	 * Generate field scripts for cascading selects
	 *
	 * @param string $node Node identifier
	 * @param array $scriptElements Script elements
	 * @param string $table Table name
	 * @param array $filters Additional filters
	 * @return void
	 */
	private function generateFieldScripts($node, $scriptElements, $table, $filters) {
		$fields = ['others' => $scriptElements];

		$this->scriptConfig($node, $scriptElements);

		foreach ($scriptElements as $index => $field) {
			unset($scriptElements[$index]);
			$fields['current'] = [$index => $field];
			$this->scriptNextData($node, $field, $fields, $table, $filters);
		}
	}

	/**
	 * Load template assets (CSS/JS)
	 *
	 * @param array $elementScripts Element scripts
	 * @param array $current_template Current template config
	 * @return void
	 */
	private function loadTemplateAssets($elementScripts, $current_template) {
		foreach ($elementScripts as $type) {
			if ('selectbox' === $type || 'smallint' === $type) {
				$type = 'select';
			}

			foreach ($current_template as $element => $data) {
				if ($element === $type) {
					$this->loadAssetsByType($data);
				}
			}
		}
	}

	/**
	 * Load assets by type (JS/CSS)
	 *
	 * @param array $data Asset data
	 * @return void
	 */
	private function loadAssetsByType($data) {
		foreach ($data as $script_type => $script_paths) {
			if ('js' === $script_type) {
				foreach ($script_paths as $script_path) {
					$this->addScripts['js'][] = canvastack_script_check_string_path(str_replace('last:js', 'js', $script_path));
				}
			} else {
				foreach ($script_paths as $script_path) {
					$this->addScripts['css'][] = canvastack_script_check_string_path(str_replace('last:css', 'css', $script_path));
				}
			}
		}
	}

	
	/**
	 * Generate script configuration for field loaders
	 *
	 * @param string $node Node identifier
	 * @param array $fields Field list
	 * @return void
	 */
	public function scriptConfig($node, $fields) {
		$FieldSets = [];
		if (!empty($fields)) {
			foreach ($fields as $index => $field) {
				if ($index >= 1) $FieldSets[] = "loader('{$field}');";
			}
		}
		$fieldScripts = implode('', $FieldSets);
		
		$this->addScripts['add_js'][] = $this->scriptToHTML . $fieldScripts;
	}
	
	/**
	 * Generate script for next data with cascading logic
	 *
	 * @param string $node Node identifier
	 * @param string $identity Field identity
	 * @param array $fields Field configuration
	 * @param string $table Table name
	 * @param array $filters Additional filters
	 * @return void
	 */
	public function scriptNextData($node, $identity, $fields, $table, $filters = []) {
		// Setup node names (SECURE - sanitize for JavaScript)
		$nodeNames = $this->setupNodeNames($node, $identity, $fields);
		
		// Setup field targets
		$targets = $this->setupFieldTargets($fields, $nodeNames);
		
		// Build nest data
		$nests = $this->buildNestData($fields, $nodeNames);
		
		// Build scripts
		$nestScript = $this->buildNextScript($nests, $nodeNames, $targets);
		$ajaxSuccess = $this->buildAjaxScript($identity, $table, $targets, $nests, $nodeNames, $filters);
		
		// Generate main script
		$script = $this->buildMainScript($node, $identity, $targets, $nodeNames, $nestScript, $ajaxSuccess);
		
		$this->addScripts['add_js'][] = $this->scriptToHTML . $script;
	}
	
	/**
	 * Setup node names (SECURE - sanitize for JavaScript)
	 *
	 * @param string $node Node identifier
	 * @param string $identity Field identity
	 * @param array $fields Field configuration
	 * @return array Node names
	 */
	public function setupNodeNames($node, $identity, $fields) {
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
	 * @param array $fields Field configuration
	 * @param array $nodeNames Node names
	 * @return array Target configuration
	 */
	public function setupFieldTargets($fields, $nodeNames) {
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
	 * @param array $fields Field configuration
	 * @param array $nodeNames Node names
	 * @return array Nest data
	 */
	public function buildNestData($fields, $nodeNames) {
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
	 * @param array $nests Nest configuration
	 * @param array $nodeNames Node names
	 * @param array $targets Target configuration
	 * @return string|null JavaScript code
	 */
	public function buildNextScript($nests, $nodeNames, $targets) {
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
		$iNode_safe = $this->escapeJs($nodeNames['iNode']);
		
		$scriptLines = [];
		$scriptLines[] = "var _nx{$nextNode} = '{$next_target_safe}';";
		$scriptLines[] = "var _reident{$nextNode} = _nx{$nextNode}.replace('_', ' ');";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').empty()";
		$scriptLines[] = ".append('<option value=\"\">No Data ' + ucwords(_reident{$nextNode}) + ' Found</option>')";
		$scriptLines[] = ".prop('disabled', true).trigger('chosen:updated');";
		
		$scriptLines[] = "if (null != '{$nest_safe}' && '' != '{$nest_safe}') {";
		$scriptLines[] = "var _spldt{$iNode} = '{$nest_safe}';";
		$scriptLines[] = "var _spl{$iNode} = _spldt{$iNode}.split('|');";
		$scriptLines[] = "$.each(_spl{$iNode}, function(i, obj) {";
		$scriptLines[] = "if (null != obj && '{$iNode_safe}' != obj) {";
		$scriptLines[] = "var _reident{$iNode} = obj.replace('_', ' ');";
		$scriptLines[] = "$('#' + obj).empty()";
		$scriptLines[] = ".append('<option value=\"\">No Data ' + ucwords(_reident{$iNode}) + ' Found</option>')";
		$scriptLines[] = ".prop('disabled', true).trigger('chosen:updated');";
		$scriptLines[] = "}";
		$scriptLines[] = "});";
		$scriptLines[] = "}";
		
		return implode('', $scriptLines);
	}
	
	/**
	 * Build AJAX script (SECURE - XSS protected)
	 * FIX: Properly escape JSON data for JavaScript context
	 * REFACTORED: Reduced complexity by extracting sub-methods
	 *
	 * @param string $identity Field identifier
	 * @param string $table Table name
	 * @param array $targets Target field configuration
	 * @param array $nests Nested field relationships
	 * @param array $nodeNames Node naming configuration
	 * @param array $filters Additional filters
	 * @return string|null Generated JavaScript code
	 */
	public function buildAjaxScript($identity, $table, $targets, $nests, $nodeNames, $filters) {
		$next_target = $targets['next_target'];
		
		if (empty($next_target)) {
			return null;
		}
		
		// Escape values for JavaScript
		$escapedValues = $this->escapeAjaxValues($identity, $table, $next_target, $nests);
		
		// Build AJAX data object
		$ajax_data = $this->buildAjaxDataObject($escapedValues, $nodeNames, $filters);
		
		// Get AJAX configuration
		$uri = canvastack_get_ajax_urli('filterDataTables', $this->config->getConnection());
		
		// Build success handler
		$ajaxSuccess = $this->buildAjaxSuccessHandler(
			$escapedValues['next_target'], 
			$targets['nextNode'], 
			$nodeNames['iNode'], 
			$nests['prevscript'], 
			$uri, 
			$ajax_data
		);
		
		return $ajaxSuccess;
	}
	
	/**
	 * Escape values for AJAX script
	 *
	 * @param string $identity Field identifier
	 * @param string $table Table name
	 * @param string $next_target Next target field
	 * @param array $nests Nest configuration
	 * @return array Escaped values
	 */
	private function escapeAjaxValues($identity, $table, $next_target, $nests) {
		return [
			'identity' => $this->escapeJs($identity),
			'table' => $this->escapeJs($table),
			'next_target' => $this->escapeJs($next_target),
			'prev' => $this->escapeJs($nests['prev']),
			'nest' => $this->escapeJs($nests['nest'])
		];
	}
	
	/**
	 * Build AJAX data object
	 *
	 * @param array $escapedValues Escaped values
	 * @param array $nodeNames Node names
	 * @param array $filters Additional filters
	 * @return string JavaScript data object
	 */
	private function buildAjaxDataObject($escapedValues, $nodeNames, $filters) {
		$iNode = $nodeNames['iNode'];
		$forkey = $this->config->getForeignKeys();
		$forkeys = $this->encodeJsonForJs($forkey);
		$token = csrf_token();
		
		$ajaxConnection = '';
		if (!empty($this->config->getConnection())) {
			$connection_safe = $this->escapeJs($this->config->getConnection());
			$ajaxConnection = ",'grabCanvaStackC':'{$connection_safe}'";
		}
		
		$canvastackF = '';
		if (!empty($filters)) {
			$canvastackFilters = $this->encodeJsonForJs($filters);
			$canvastackF = ",'_canvastackF':{$canvastackFilters}";
		}
		
		$fitaValue = "{$token}::{$escapedValues['table']}::{$escapedValues['next_target']}::{$escapedValues['prev']}#' + _prevS{$iNode} + '::{$escapedValues['nest']}";
		
		return "{'{$escapedValues['identity']}':_val{$iNode},'_fita':'{$fitaValue}','_token':'{$token}','_n':'{$escapedValues['nest']}','_forKeys':{$forkeys}{$ajaxConnection}{$canvastackF}}";
	}
	
	/**
	 * Build AJAX success handler script with error handling
	 *
	 * @param string $next_target_safe Escaped next target
	 * @param string $nextNode Next node identifier
	 * @param string $iNode Identity node
	 * @param string $prevscript Previous script
	 * @param string $uri AJAX URI
	 * @param string $ajax_data AJAX data object
	 * @return string JavaScript code
	 */
	public function buildAjaxSuccessHandler($next_target_safe, $nextNode, $iNode, $prevscript, $uri, $ajax_data) {
		$target = ucwords(str_replace('_', ' ', $next_target_safe));
		
		$scriptLines = [];
		$scriptLines[] = "var _next{$next_target_safe} = '{$target}';";
		$scriptLines[] = "var _prevS{$iNode} = {$prevscript};";
		$scriptLines[] = "$.ajax ({";
		$scriptLines[] = "type: 'POST',";
		$scriptLines[] = "url: '{$uri}',";
		$scriptLines[] = "data: {$ajax_data},";
		$scriptLines[] = "dataType: 'json',";
		$scriptLines[] = "beforeSend: function() {";
		$scriptLines[] = "$('#" . SearchConfig::LOADER_PREFIX . "{$next_target_safe}').show();";
		$scriptLines[] = "},";
		$scriptLines[] = "success: function(data) {";
		$scriptLines[] = "if (data) {";
		$scriptLines[] = "if ('' != '{$next_target_safe}' && null != '{$next_target_safe}') {";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').removeAttr('disabled').trigger('chosen:updated');";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').empty();";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').append('<option value=\"\">Select ' + _next{$next_target_safe} + '</option>').trigger('chosen:updated');";
		$scriptLines[] = "$.each(data, function(key, value) {";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').append('<option value=\"'+ value.{$next_target_safe} +'\">' + value.{$next_target_safe} + '</option>').trigger('chosen:updated');";
		$scriptLines[] = "});";
		$scriptLines[] = "}";
		$scriptLines[] = "}";
		$scriptLines[] = "},";
		$scriptLines[] = "error: function(xhr, status, error) {";
		$scriptLines[] = "console.error('Search filter load failed:', {status: status, error: error, target: '{$next_target_safe}'});";
		$scriptLines[] = "var errorMsg = 'Failed to load ' + _next{$next_target_safe} + ' options. ';";
		$scriptLines[] = "if (xhr.status === 404) { errorMsg += 'Endpoint not found.'; }";
		$scriptLines[] = "else if (xhr.status === 500) { errorMsg += 'Server error.'; }";
		$scriptLines[] = "else if (xhr.status === 0) { errorMsg += 'Network error.'; }";
		$scriptLines[] = "else { errorMsg += 'Please try again.'; }";
		$scriptLines[] = "$('select#{$next_target_safe}.{$nextNode}').empty()";
		$scriptLines[] = ".append('<option value=\"\">Error: ' + errorMsg + '</option>')";
		$scriptLines[] = ".prop('disabled', true).trigger('chosen:updated');";
		$scriptLines[] = "},";
		$scriptLines[] = "complete: function() {";
		$scriptLines[] = "$('#" . SearchConfig::LOADER_PREFIX . "{$next_target_safe}').hide();";
		$scriptLines[] = "}";
		$scriptLines[] = "});";
		
		return implode('', $scriptLines);
	}
	
	/**
	 * Build main script (SECURE - XSS protected)
	 *
	 * @param string $node Node identifier
	 * @param string $identity Field identity
	 * @param array $targets Target configuration
	 * @param array $nodeNames Node names
	 * @param string|null $nestScript Nest script
	 * @param string|null $ajaxSuccess AJAX success handler
	 * @return string|null JavaScript code
	 */
	public function buildMainScript($node, $identity, $targets, $nodeNames, $nestScript, $ajaxSuccess) {
		if (empty($identity)) {
			return null;
		}
		
		// FIX XSS: Escape for JavaScript
		$identity_safe = $this->escapeJs($identity);
		$node_safe = $this->escapeJs($node);
		
		$firstNode = $nodeNames['firstNode'];
		$iNode = $nodeNames['iNode'];
		$nextTargets = $targets['nexTargets'];
		$curTargets = $targets['curTargets'];
		$firstTarget = $targets['firstTarget'];
		$lastTarget = $targets['lastTarget'];
		
		$scriptLines = [];
		$scriptLines[] = "jQuery(function($) {";
		$scriptLines[] = "$('#{$node_safe}').children('div.form-group').each(function () {";
		$scriptLines[] = "$(this).find('select#{$identity_safe}.{$firstNode}').change(function () {";
		
		// Build target clearing logic
		if (!empty($nextTargets)) {
			$scriptLines[] = $this->buildTargetClearingLogic($nextTargets, $curTargets, $identity, $firstTarget, $lastTarget, $node_safe);
		}
		
		$scriptLines[] = "var _val{$iNode} = $(this).val();";
		$scriptLines[] = "if (_val{$iNode} != '0' && _val{$iNode} != null && _val{$iNode} != '') {";
		$scriptLines[] = $ajaxSuccess;
		$scriptLines[] = "} else {";
		$scriptLines[] = $nestScript;
		$scriptLines[] = "}";
		$scriptLines[] = "});";
		$scriptLines[] = "});";
		$scriptLines[] = "});";
		
		return implode('', $scriptLines);
	}
	
	/**
	 * Build target clearing logic for cascading selects
	 *
	 * @param array $nextTargets Next targets
	 * @param string $curTargets Current targets
	 * @param string $identity Field identity
	 * @param string $firstTarget First target
	 * @param string $lastTarget Last target
	 * @param string $node_safe Escaped node name
	 * @return string JavaScript code
	 */
	public function buildTargetClearingLogic($nextTargets, $curTargets, $identity, $firstTarget, $lastTarget, $node_safe) {
		$scriptLines = [];
		$curN = 0;
		
		foreach ($nextTargets as $n => $nextElement) {
			if ($curTargets === $nextElement) {
				$curN = $n;
			}
			$curNode = $curN + 1;
			
			if ($n > $curNode) {
				if ($lastTarget !== $nextElement) {
					if ($identity === $firstTarget) {
						$scriptLines[] = "if ($(this).val() != '') { $('button#exportFilterButton{$node_safe}').removeClass('hide'); } else { $('button#exportFilterButton{$node_safe}').addClass('hide'); }";
						$scriptLines[] = "$('select#{$lastTarget}').empty().trigger('chosen:updated');";
					}
					
					if ($identity !== $lastTarget) {
						$nextElement_safe = $this->escapeJs($nextElement);
						$scriptLines[] = "$('select#{$nextElement_safe}').empty().trigger('chosen:updated');";
					}
				}
			}
		}
		
		return implode('', $scriptLines);
	}
	
	/**
	 * Encode data to JSON safe for JavaScript context
	 * FIX XSS: Prevent XSS via JSON injection
	 *
	 * @param mixed $data Data to encode
	 * @return string JSON string safe for JavaScript
	 */
	public function encodeJsonForJs($data) {
		return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
	
	/**
	 * Escape string for JavaScript context - prevent XSS
	 *
	 * @param string $value Value to escape
	 * @return string Escaped value
	 */
	public function escapeJs($value) {
		if ($value === null) {
			return '';
		}
		
		// Escape for JavaScript string context
		return addslashes((string)$value);
	}
	
	/**
	 * Clean dash characters from string
	 *
	 * @param string $string Input string
	 * @return string Cleaned string
	 */
	public function cleardash($string) {
		return str_replace('-', '_', $string);
	}
	
	/**
	 * Get generated scripts
	 *
	 * @return array
	 */
	public function getScripts() {
		return $this->addScripts;
	}
}
