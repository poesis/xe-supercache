<?php

/**
 * Super Cache module: admin view class
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 */
class SuperCacheAdminView extends SuperCache
{
	/**
	 * Menu definition.
	 */
	protected static $_menus = array(
		'dispSupercacheAdminConfig' => 'cmd_supercache_config',
	);
	
	/**
	 * Init method for common tasks.
	 */
	public function init()
	{
		// Set the default template path.
		$this->setTemplatePath($this->module_path . 'tpl');
		
		// Set the admin menu.
		$lang = Context::get('lang');
		foreach (self::$_menus as $key => $value)
		{
			self::$_menus[$key] = $lang->$value;
		}
		Context::set('sc_menus', self::$_menus);
	}
	
	/**
	 * Basic settings page.
	 */
	public function dispSuperCacheAdminConfig()
	{
		// Get module configuration.
		Context::set('sc_config', $config = $this->getConfig());
		
		// Get system capabilities.
		$oAdminModel = getAdminModel('supercache');
		Context::set('sc_list_replace', $oAdminModel->isListReplacementSupported());
		Context::set('sc_offset_query', $oAdminModel->isOffsetQuerySupported());
		
		// Display the config page.
		$this->setTemplateFile('config');
	}
}
