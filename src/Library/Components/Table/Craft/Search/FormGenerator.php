<?php
namespace Canvastack\Origin\Library\Components\Table\Craft\Search;

use Canvastack\Origin\Library\Components\Form\Objects as Form;
use Canvastack\Origin\Library\Components\Table\Craft\Search\Config\SearchConfig;
use Illuminate\Support\Facades\Log;

/**
 * FormGenerator - Form field generation for Search component
 *
 * @filesource FormGenerator.php
 * @author     wisnuwidi@canvastack.com - 2021
 * @copyright  wisnuwidi
 * @email      wisnuwidi@canvastack.com
 */
class FormGenerator {
	
	private $form;
	private $config;
	private $searchFields = [];
	private $fieldValuesCache = [];
	
	/**
	 * Constructor
	 *
	 * @param SearchConfig $config Configuration object
	 */
	public function __construct(SearchConfig $config) {
		$this->form = new Form();
		$this->config = $config;
		$this->setupFormConfig();
	}
	
	/**
	 * Setup form configuration
	 *
	 * @return void
	 */
	private function setupFormConfig() {
		$this->form->excludeFields = ['password_field'];
		$this->form->hideFields = ['id'];
	}
	
	/**
	 * Setup search fields
	 *
	 * @param array $data Filter data
	 * @return void
	 */
	public function setupSearchFields($data) {
		foreach (array_keys($data) as $dataFields) {
			$this->searchFields[$dataFields] = $dataFields;
		}
	}
	
	/**
	 * Generate form element based on type with validation
	 *
	 * @param string $field Field name
	 * @param string $type Field type
	 * @param mixed $values Field values
	 * @param array $attributes HTML attributes
	 * @return void
	 */
	public function generateFormElement($field, $type, $values, $attributes) {
		// Validate field type
		if (!in_array($type, SearchConfig::VALID_FIELD_TYPES)) {
			Log::warning("Invalid field type: {$type} for field: {$field}, defaulting to text");
			$type = 'text';
		}
		
		switch ($type) {
			case 'selectbox':
				$this->form->selectbox($field, $values, false, $attributes, true, false);
				break;
			case 'date':
				$this->form->date($field, $values, $attributes);
				break;
			case 'datetime':
				$this->form->date($field, $values, $attributes);
				break;
			case 'checkbox':
				if ($this->shouldRenderCheckbox($values)) {
					$this->form->checkbox($field, $values);
				}
				break;
			case 'radiobox':
				if ($this->shouldRenderRadiobox($values)) {
					$this->form->radiobox($field, $values);
				}
				break;
			default:
				if (!empty($values)) {
					$this->form->text($field, $values, ['id' => $field]);
				}
		}
	}
	
	/**
	 * Generate default form element with validation
	 *
	 * @param string $field Field name
	 * @param string $type Field type
	 * @return void
	 */
	public function generateDefaultFormElement($field, $type) {
		$attributes = ['id' => $field];
		
		// Validate field type
		if (!in_array($type, SearchConfig::VALID_FIELD_TYPES)) {
			Log::warning("Invalid default field type: {$type} for field: {$field}, defaulting to text");
			$type = 'text';
		}
		
		switch ($type) {
			case 'string':
			case 'text':
				$this->form->text($field, null, $attributes);
				break;
			case 'smallint':
				$this->form->selectbox($field, [], false, $attributes);
				break;
			case 'date':
				$this->form->date($field, null, $attributes);
				break;
			case 'datetime':
				$this->form->datetime($field, null, $attributes);
				break;
			case 'time':
				$this->form->time($field, null, $attributes);
				break;
			case 'daterange':
				$this->form->daterange($field, null, $attributes);
				break;
			default:
				$this->form->text($field, null, $attributes);
		}
	}
	
	/**
	 * Prepare field values
	 *
	 * @param string $field Field name
	 * @param string $open_field Open field
	 * @param string $tablename Table name
	 * @param QueryBuilder $queryBuilder Query builder instance
	 * @return array|null Field values
	 */
	public function prepareFieldValues($field, $open_field, $tablename, $queryBuilder) {
		$values = null;
		
		if ($open_field === $field) {
			$field_value = [];
			$values = $this->setFirstSelectbox($tablename, $field_value, $field, $queryBuilder);
		}
		
		return $values;
	}
	
	/**
	 * Batch load field values for multiple fields in single query
	 * OPTIMIZATION: Reduces N queries to 1 query
	 *
	 * @param string $tablename Table name
	 * @param array $fields Field names
	 * @param string $open_field Open field
	 * @param QueryBuilder $queryBuilder Query builder instance
	 * @return void
	 */
	public function batchLoadFieldValues($tablename, $fields, $open_field, $queryBuilder) {
		if (empty($fields)) {
			return;
		}
		
		// OPTIMIZATION: Load all fields in single query instead of N queries
		$queryBuilder->batchSelections($tablename, $fields);
		$selections = $queryBuilder->getSelections();
		
		// Cache results for each field
		foreach ($fields as $field) {
			if ($field === $open_field && !empty($selections[$field])) {
				$this->fieldValuesCache[$field] = $selections[$field];
			} else {
				$this->fieldValuesCache[$field] = null;
			}
		}
	}
	
	/**
	 * Get field values from cache
	 *
	 * @param string $field Field name
	 * @param string $open_field Open field
	 * @return array|null Field values
	 */
	public function getFieldValuesFromCache($field, $open_field) {
		if ($field === $open_field && isset($this->fieldValuesCache[$field])) {
			return $this->fieldValuesCache[$field];
		}
		
		return null;
	}
	
	/**
	 * Set first selectbox values
	 *
	 * @param string $name Table name
	 * @param array $field_value Field values
	 * @param string $field Field name
	 * @param QueryBuilder $queryBuilder Query builder instance
	 * @return array|null
	 */
	private function setFirstSelectbox($name, $field_value, $field, $queryBuilder) {
		$values[$field] = null;
		$queryBuilder->selections($name, [$field]);
		$selections = $queryBuilder->getSelections();
		
		if (!empty($selections[$field])) {
			$values[$field] = $selections[$field];
		}
		
		return $values[$field];
	}
	
	/**
	 * Build field attributes with export class
	 *
	 * @param string $field Field name
	 * @param string $info Component info
	 * @param mixed $values Field values
	 * @return array HTML attributes
	 */
	public function buildFieldAttributes($field, $info, $values) {
		$classFieldInfo = str_replace('-', '_', $info) . 'Field';
		$attributes = [
			'id' => $field,
			'class' => "{$field}_{$classFieldInfo} " . SearchConfig::EXPORT_CLASS_PREFIX . "{$classFieldInfo}"
		];
		
		if (empty($values)) {
			$attributes['disabled'] = 'disabled';
		}
		
		return $attributes;
	}
	
	/**
	 * Prepare field options (SECURE - XSS protected)
	 *
	 * @param string $field Field name
	 * @param string $type Field type
	 * @param mixed $values Field values
	 * @param string $field_label Field label (already escaped)
	 * @return array Prepared values
	 */
	public function prepareFieldOptions($field, $type, $values, $field_label) {
		if ('selectbox' === $type) {
			if (null === $values) {
				$values = [null => 'No Data ' . $field_label . ' Found'];
			} else {
				$values[null] = 'Select ' . $field_label;
			}
			ksort($values);
		}
		
		if ('radiobox' === $type) {
			if (null !== $values && count($values) > 1) {
				$values[null] = 'Clear!';
			}
		}
		
		return $values;
	}
	
	/**
	 * Check if checkbox should be rendered
	 *
	 * @param mixed $values
	 * @return bool
	 */
	public function shouldRenderCheckbox($values) {
		if (empty($values)) {
			return false;
		}
		
		return !in_array('', $values) || !in_array(null, $values);
	}
	
	/**
	 * Check if radiobox should be rendered
	 *
	 * @param mixed $values
	 * @return bool
	 */
	public function shouldRenderRadiobox($values) {
		if (empty($values)) {
			return false;
		}
		
		return !in_array('', $values) || !in_array(null, $values);
	}
	
	/**
	 * Get form elements
	 *
	 * @return array
	 */
	public function getFormElements() {
		return $this->form->elements;
	}
	
	/**
	 * Get form object
	 *
	 * @return Form
	 */
	public function getForm() {
		return $this->form;
	}
	
	/**
	 * Get search fields
	 *
	 * @return array
	 */
	public function getSearchFields() {
		return $this->searchFields;
	}
	
	/**
	 * Escape HTML to prevent XSS
	 *
	 * @param string $value
	 * @return string
	 */
	public function escapeHtml($value) {
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}
