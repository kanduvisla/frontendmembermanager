<?php
	
	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	
	class FieldMemberPassword extends Field {
		protected $_driver = null;
		protected $_strengths = array();
		protected $_strength_map = array();
		
	/*-------------------------------------------------------------------------
		Definition:
	-------------------------------------------------------------------------*/
		
		public function __construct(&$parent) {
			parent::__construct($parent);
			
			$this->_name = 'Member Password';
			$this->_driver = $this->_engine->ExtensionManager->create('membermanager');
			$this->_strengths = array(
				array('weak', false, 'Weak'),
				array('good', false, 'Good'),
				array('strong', false, 'Strong')
			);
			$this->_strength_map = array(
				0			=> 1,
				1			=> 1,
				2			=> 2,
				3			=> 3,
				4			=> 3,
				'weak'		=> 1,
				'good'		=> 2,
				'strong'	=> 3
			);
			
			// Set defaults:
			$this->set('show_column', 'yes');
			$this->set('length', '6');
			$this->set('strength', 'good');
		}
		
		public function createTable() {
			$field_id = $this->get('id');
			
			return $this->_engine->Database->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
					`id` int(11) unsigned NOT NULL auto_increment,
					`entry_id` int(11) unsigned NOT NULL,
					`password` text default NULL,
					`strength` enum(
						'weak', 'good', 'strong'
					) NOT NULL,
					`length` tinyint NOT NULL,
					PRIMARY KEY (`id`),
					KEY `entry_id` (`entry_id`),
					FULLTEXT KEY `password` (`password`),
					KEY `strength` (`strength`),
					KEY `length` (`length`)
				)
			");
		}
		
	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/
		
		protected function checkPassword($password) {
			$strength = 0;
			$patterns = array(
				'/[a-z]/', '/[A-Z]/', '/[0-9]/',
				'/[¬!"£$%^&*()`{}\[\]:@~;\'#<>?,.\/\\-=_+\|]/'
			);
			
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, $password, $matches)) {
					$strength++;
				}
			}
			
			return $strength;
		}
		
		protected function compareStrength($a, $b) {
			if ($this->_strength_map[$a] >= $this->_strength_map[$b]) return true;
			
			return false;
		}
		
		protected function encryptPassword($password) {
		    return trim(base64_encode(
				mcrypt_encrypt(
					MCRYPT_RIJNDAEL_256, $this->get('salt'),
					$password, MCRYPT_MODE_ECB,
					mcrypt_create_iv(mcrypt_get_iv_size(
						MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB
					), MCRYPT_RAND)
				)
			));
		}

		protected function decryptPassword($password) {
		    return trim(mcrypt_decrypt(
				MCRYPT_RIJNDAEL_256, $this->get('salt'),
				base64_decode($password), MCRYPT_MODE_ECB,
				mcrypt_create_iv(mcrypt_get_iv_size(
					MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB
				), MCRYPT_RAND)
			));
		}
		
		protected function getStrengthName($strength) {
			$map = array_flip($this->_strength_map);
			
			return $map[$strength];
		}
		
		protected function rememberSalt() {
			$field_id = $this->get('id');
			
			$salt = $this->_engine->Database->fetchVar('salt', 0, "
				SELECT
					f.salt
				FROM
					`tbl_fields_memberpassword` AS f
				WHERE
					f.field_id = '$field_id'
				LIMIT 1
			");
			
			if ($salt and !$this->get('salt')) {
				$this->set('salt', $salt);
			}
		}
		
	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/
		
		public function displaySettingsPanel(&$wrapper, $errors = null) {
			$field_id = $this->get('id');
			$order = $this->get('sortorder');
			
			$wrapper->appendChild(new XMLElement('h4', ucwords($this->name())));
			$wrapper->appendChild(Widget::Input(
				"fields[{$order}][type]", $this->handle(), 'hidden'
			));
			
			if ($field_id) $wrapper->appendChild(Widget::Input(
				"fields[{$order}][id]", $field_id, 'hidden'
			));
			
			$wrapper->appendChild($this->buildSummaryBlock($errors));
			
		// Validator ----------------------------------------------------------
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label('Minimum Length');
			$label->appendChild(Widget::Input(
				"fields[{$order}][length]", $this->get('length')
			));
			
			$group->appendChild($label);
			
		// Strength -----------------------------------------------------------
			
			$values = $this->_strengths;
			
			foreach ($values as &$value) {
				$value[1] = $value[0] == $this->get('strength');
			}
			
			$label = Widget::Label('Minimum Strength');
			$label->appendChild(Widget::Select(
				"fields[{$order}][strength]", $values
			));
			
			$group->appendChild($label);
			$wrapper->appendChild($group);
			
		// Salt ---------------------------------------------------------------
			
			$label = Widget::Label('Password Salt');
			$input = Widget::Input(
				"fields[{$order}][salt]", $this->get('salt')
			);
			
			if ($this->get('salt')) {
				$input->setAttribute('disabled', 'disabled');
			}
			
			$label->appendChild($input);
			
			if (isset($errors['salt'])) {
				$label = Widget::wrapFormElementWithError($label, $errors['salt']);
			}
			
			$wrapper->appendChild($label);
			
			$this->appendShowColumnCheckbox($wrapper);						
		}
		
		public function checkFields(&$errors, $checkForDuplicates = true) {
			parent::checkFields($errors, $checkForDuplicates);
			
			$this->rememberSalt();
			
			if (trim($this->get('salt')) == '') {
				$errors['salt'] = 'This is a required field.';
			}
		}
		
		public function commit() {
			$field_id = $this->get('id');
			
			if (!parent::commit() or $this->get('id') === false) return false;
			
			$this->rememberSalt();
			
			$fields = array(
				'field_id'		=> $this->get('id'),
				'length'		=> $this->get('length'),
				'strength'		=> $this->get('strength'),
				'salt'			=> $this->get('salt')
			);
			
			$this->_engine->Database->query("
				DELETE FROM
					`tbl_fields_memberpassword`
				WHERE
					`field_id` = '$field_id'
				LIMIT 1
			");
			
			return $this->_engine->Database->insert($fields, 'tbl_fields_memberpassword');
		}
		
	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/
		
		public function displayPublishPanel(&$wrapper, $data = null, $error = null, $prefix = null, $postfix = null) {
			$label = Widget::Label($this->get('label'));
			$name = $this->get('element_name');
			
			$input = Widget::Input(
				"fields{$prefix}[$name][password]{$postfix}",
				(!empty($data['password']) ? General::sanitize($this->decryptPassword($data['password'])) : null)
			);
			$input->setAttribute('type', 'password');
			
			$label->appendChild($input);
			
			if ($error != null) {
				$label = Widget::wrapFormElementWithError($label, $error);
			}
			
			$wrapper->appendChild($label);
		}
		
	/*-------------------------------------------------------------------------
		Input:
	-------------------------------------------------------------------------*/
		
		public function checkPostFieldData($data, &$error, $entry_id = null) {
			$label = $this->get('label');
			$error = null;
			
			$password = trim($data['password']);
			
			if (isset($data['confirm'])) {
				$confirm = trim($data['confirm']);
			}
			
			if (strlen($password) == 0) {
				$error = "'{$label}' is a required field.";
				
				return self::__MISSING_FIELDS__;
			}
			
			if (isset($confirm) and $confirm != $password) {
				$error = "'{$label}' passwords do not match.";
				
				return self::__INVALID_FIELDS__;
			}
			
			if (strlen($password) < (integer)$this->get('length')) {
				$error = "'{$label}' is too short.";
				
				return self::__INVALID_FIELDS__;
			}
			
			if (!$this->compareStrength($this->checkPassword($password), $this->get('strength'))) {
				$error = "'{$label}' is not strong enough.";
				
				return self::__INVALID_FIELDS__;
			}
			
			return self::__OK__;
		}
		
		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {
			$status = self::__OK__;
			
			if ($data == '') return array();
			
			$password = trim($data['password']);
			
			if (isset($data['confirm'])) {
				$confirm = trim($data['confirm']);
			}
			
			$result = array(
				'password'			=> $this->encryptPassword($password),
				'strength'			=> $this->checkPassword($password),
				'length'			=> strlen($password)
			);
			
			return $result;
		}
		
	/*-------------------------------------------------------------------------
		Output:
	-------------------------------------------------------------------------*/
		
		public function appendFormattedElement(&$wrapper, $data, $encode = false) {
			$element = new XMLElement($this->get('element_name'));
			$element->setAttribute('strength', $data['strength']);
			$element->setAttribute('length', $data['length']);
			$wrapper->appendChild($element);
		}
		
		public function prepareTableValue($data, XMLElement $link = null) {
			if (empty($data)) return;
			
			return parent::prepareTableValue(
				array(
					'value'		=> ucwords($data['strength']) . " ({$data['length']})"
				), $link
			);
		}
	}
	
?>