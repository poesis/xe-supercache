<?php

/**
 * Super Cache module: admin controller class
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
class SuperCacheAdminController extends SuperCache
{
	/**
	 * Save full page cache settings.
	 */
	public function procSuperCacheAdminInsertBasic()
	{
		// Get current config and user selections.
		$vars = Context::getRequestVars();
		
		// Save the new config.
		$db_info = Context::getDbInfo();
		if ($db_info->use_object_cache !== $vars->sc_core_object_cache)
		{
			if (defined('RX_BASEDIR'))
			{
				return $this->error('msg_supercache_rhymix_no_cache');
			}
			if (!strncasecmp('memcache', $vars->sc_core_object_cache, 8) && !class_exists('Memcache'))
			{
				return $this->error('msg_supercache_memcache_not_supported');
			}
			if ($vars->sc_core_object_cache === 'apc' && !function_exists('apc_store'))
			{
				return $this->error('msg_supercache_apc_not_supported');
			}
			if ($vars->sc_core_object_cache === 'wincache' && !function_exists('wincache_ucache_set'))
			{
				return $this->error('msg_supercache_wincache_not_supported');
			}
			
			$db_info->use_object_cache = $vars->sc_core_object_cache;
			Context::setDbInfo($db_info);
			if (!getController('install')->makeConfigFile())
			{
				return $this->error('msg_supercache_config_save_failed');
			}
			if (function_exists('opcache_invalidate'))
			{
				@opcache_invalidate(_XE_PATH_ . 'files/config/db.config.php', true);
			}
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigBasic'));
	}
	
	/**
	 * Save full page cache settings.
	 */
	public function procSuperCacheAdminInsertFullCache()
	{
		// Get current config and user selections.
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		
		// Fetch the new config.
		if ($vars->sc_full_cache === 'Y')
		{
			$config->full_cache = true;
		}
		elseif ($vars->sc_full_cache === 'robots_only')
		{
			$config->full_cache = 'robots_only';
		}
		else
		{
			$config->full_cache = false;
		}
		
		$config->full_cache_duration = intval($vars->sc_full_cache_duration) ?: 300;
		$config->full_cache_stampede_protection = $vars->sc_full_cache_stampede_protection === 'Y' ? true : false;
		$config->full_cache_use_headers = $vars->sc_full_cache_use_headers === 'Y' ? true : false;
		
		if ($vars->sc_full_cache_type)
		{
			$values = array_fill(0, count($vars->sc_full_cache_type), true);
			$config->full_cache_type = array_combine($vars->sc_full_cache_type, $values);
		}
		else
		{
			$config->full_cache_type = array();
		}
		
		if ($vars->sc_full_cache_exclude_modules)
		{
			$keys = array_map('intval', $vars->sc_full_cache_exclude_modules);
			$values = array_fill(0, count($keys), true);
			$config->full_cache_exclude_modules = array_combine($keys, $values);
		}
		else
		{
			$config->full_cache_exclude_modules = array();
		}
		
		if ($vars->sc_full_cache_exclude_acts)
		{
			$keys = array_map('trim', preg_split('/(,|\s)+/', trim($vars->sc_full_cache_exclude_acts)));
			$keys = array_filter($keys, function($val) { return preg_match('/^[a-zA-Z0-9_]+$/', $val); });
			$values = array_fill(0, count($keys), true);
			$config->full_cache_exclude_acts = array_combine($keys, $values);
		}
		else
		{
			$config->full_cache_exclude_acts = array();
		}
		
		// Save the new config.
		$output = $this->setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigFullCache'));
	}
	
	/**
	 * Save pagination cache settings.
	 */
	public function procSuperCacheAdminInsertPagingCache()
	{
		// Get current config and user selections.
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		
		// Fetch the new config.
		$config->paging_cache = $vars->sc_paging_cache === 'Y' ? true : false;
		$config->paging_cache_use_offset = $vars->sc_paging_cache_use_offset === 'Y' ? true : false;
		$config->paging_cache_threshold = intval($vars->sc_paging_cache_threshold) ?: 1200;
		$config->paging_cache_duration = intval($vars->sc_paging_cache_duration) ?: 3600;
		$config->paging_cache_auto_refresh = intval($vars->sc_paging_cache_auto_refresh) ?: 2400;
		
		if (!getAdminModel('supercache')->isListReplacementSupported())
		{
			return $this->error('msg_supercache_list_replacement_not_supported');
		}
		
		if (!getAdminModel('supercache')->isOffsetQuerySupported())
		{
			return $this->error('msg_supercache_offset_query_not_supported');
		}
		
		if ($vars->sc_paging_cache_exclude_modules)
		{
			$keys = array_map('intval', $vars->sc_paging_cache_exclude_modules);
			$values = array_fill(0, count($keys), true);
			$config->paging_cache_exclude_modules = array_combine($keys, $values);
		}
		else
		{
			$config->paging_cache_exclude_modules = array();
		}
		
		// Save the new config.
		$output = $this->setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigPagingCache'));
	}
	
	/**
	 * Save widget cache settings.
	 */
	public function procSuperCacheAdminInsertWidgetCache()
	{
		// Get current config and user selections.
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		
		// Fetch the new config.
		$config->widget_cache = $vars->sc_widget_cache === 'Y' ? true : false;
		
		// Save the new config.
		$output = $this->setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigWidgetCache'));
	}
	
	/**
	 * Save other settings.
	 */
	public function procSuperCacheAdminInsertOther()
	{
		// Get current config and user selections.
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		
		// Fetch the new config.
		$config->disable_post_search = $vars->sc_disable_post_search === 'Y' ? true : false;
		
		// Save the new config.
		$output = $this->setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigOther'));
	}
}
