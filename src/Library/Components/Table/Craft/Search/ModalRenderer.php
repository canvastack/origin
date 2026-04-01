<?php
namespace Canvastack\Origin\Library\Components\Table\Craft\Search;

use Canvastack\Origin\Library\Components\Table\Craft\Search\Config\SearchConfig;

/**
 * ModalRenderer - HTML modal generation for Search component
 *
 * @filesource ModalRenderer.php
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class ModalRenderer {
	
	private $config;
	private $html = false;
	
	/**
	 * Constructor
	 *
	 * @param SearchConfig $config Configuration object
	 */
	public function __construct(SearchConfig $config) {
		$this->config = $config;
	}
	
	/**
	 * Generate modal HTML (SECURE - XSS protected)
	 *
	 * @param string $info Component info
	 * @param string $tablename Table name
	 * @param array $formElements Form elements
	 * @param ScriptGenerator $scriptGenerator Script generator instance
	 * @param array $script_elements Script elements
	 * @param array $filterQuery Filter query
	 * @return void
	 */
	public function generateModalHTML($info, $tablename, $formElements, $scriptGenerator, $script_elements, $filterQuery) {
		// FIX XSS: Escape tablename for display
		$boxTitle = $this->escapeHtml(ucwords(str_replace('-', ' ', canvastack_clean_strings($tablename))));
		$boxName = $info . 'modalBOX';
		
		// Generate scripts
		$scriptGenerator->generateScripts($script_elements, $tablename, $boxName, $filterQuery);
		
		// Generate modal HTML
		$this->html = canvastack_modal_content_html($boxName, $boxTitle, $formElements);
	}
	
	/**
	 * Escape HTML to prevent XSS
	 *
	 * @param string $value Value to escape
	 * @return string Escaped value
	 */
	public function escapeHtml($value) {
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
	
	/**
	 * Get generated HTML
	 *
	 * @return string|false
	 */
	public function getHtml() {
		return $this->html;
	}
}
