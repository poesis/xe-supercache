<?php

/**
 * Super Cache module: admin view class
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 * 
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
class SuperCacheAdminView extends SuperCache
{
	/**
	 * Menu definition.
	 */
	protected static $_menus = array(
		'dispSupercacheAdminConfigPagingCache' => 'cmd_supercache_config_paging_cache',
		'dispSupercacheAdminConfigOther' => 'cmd_supercache_config_other',
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
	 * Paging cache settings page.
	 */
	public function dispSuperCacheAdminConfigPagingCache()
	{
		// Get module configuration.
		Context::set('sc_config', $config = $this->getConfig());
		
		// Get system capabilities.
		$oAdminModel = getAdminModel('supercache');
		Context::set('sc_list_replace', $oAdminModel->isListReplacementSupported());
		Context::set('sc_offset_query', $oAdminModel->isOffsetQuerySupported());
		
		// Display the config page.
		$this->setTemplateFile('paging_cache');
	}
	
	/**
	 * Other settings page.
	 */
	public function dispSuperCacheAdminConfigOther()
	{
		// Get module configuration.
		Context::set('sc_config', $config = $this->getConfig());
		
		// Display the config page.
		$this->setTemplateFile('other');
	}
}
