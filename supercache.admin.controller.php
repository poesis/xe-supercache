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
		$vars->sc_core_object_cache = trim($vars->sc_core_object_cache);
		
		// Save the new config.
		$db_info = Context::getDbInfo();
		if ($db_info->use_object_cache !== $vars->sc_core_object_cache)
		{
			// Don't change Rhymix config.
			if (defined('RX_BASEDIR'))
			{
				return $this->error('msg_supercache_rhymix_no_cache');
			}
			
			// Check extension availability.
			if (!strncasecmp('memcache', $vars->sc_core_object_cache, 8) && !getAdminModel('supercache')->isMemcachedSupported())
			{
				return $this->error('msg_supercache_memcached_not_supported');
			}
			if ($vars->sc_core_object_cache === 'apc' && !function_exists('apc_store'))
			{
				return $this->error('msg_supercache_apc_not_supported');
			}
			if ($vars->sc_core_object_cache === 'wincache' && !function_exists('wincache_ucache_set'))
			{
				return $this->error('msg_supercache_wincache_not_supported');
			}
			
			// Replace default option with an empty string.
			if ($vars->sc_core_object_cache === 'default')
			{
				$vars->sc_core_object_cache = '';
			}
			
			// Update system config.
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
		if ($vars->sc_full_cache)
		{
			$values = array_fill(0, count($vars->sc_full_cache), true);
			$config->full_cache = array_combine($vars->sc_full_cache, $values);
		}
		else
		{
			$config->full_cache = array();
		}
		
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
		
		if ($vars->sc_full_cache_exclude_cookies)
		{
			$keys = array_map('trim', preg_split('/(,|\s)+/', trim($vars->sc_full_cache_exclude_cookies)));
			$keys = array_filter($keys, function($val) { return preg_match('/^[a-zA-Z0-9_]+$/', $val); });
			$values = array_fill(0, count($keys), true);
			$config->full_cache_exclude_cookies = array_combine($keys, $values);
		}
		else
		{
			$config->full_cache_exclude_cookies = array();
		}
		
		if ($vars->sc_full_cache_separate_cookies)
		{
			$keys = array_map('trim', preg_split('/(,|\s)+/', trim($vars->sc_full_cache_separate_cookies)));
			$keys = array_filter($keys, function($val) { return preg_match('/^[a-zA-Z0-9_]+$/', $val); });
			$values = array_fill(0, count($keys), true);
			$config->full_cache_separate_cookies = array_combine($keys, $values);
		}
		else
		{
			$config->full_cache_separate_cookies = array();
		}
		
		if ($vars->sc_full_cache_document_action)
		{
			$values = array_fill(0, count($vars->sc_full_cache_document_action), true);
			$config->full_cache_document_action = array_combine($vars->sc_full_cache_document_action, $values);
		}
		else
		{
			$config->full_cache_document_action = array();
		}
		
		if ($vars->sc_full_cache_comment_action)
		{
			$values = array_fill(0, count($vars->sc_full_cache_comment_action), true);
			$config->full_cache_comment_action = array_combine($vars->sc_full_cache_comment_action, $values);
		}
		else
		{
			$config->full_cache_comment_action = array();
		}
		
		$config->full_cache_duration = intval($vars->sc_full_cache_duration) ?: 300;
		$config->full_cache_delay_trigger = $vars->sc_full_cache_delay_trigger === 'Y' ? true : false;
		$config->full_cache_stampede_protection = $vars->sc_full_cache_stampede_protection === 'Y' ? true : false;
		$config->full_cache_use_headers = $vars->sc_full_cache_use_headers === 'Y' ? true : false;
		$config->full_cache_use_headers_proxy_too = $vars->sc_full_cache_use_headers_proxy_too === 'Y' ? true : false;
		$config->full_cache_incr_view_count = $vars->sc_full_cache_incr_view_count === 'Y' ? true : false;
		$config->full_cache_incr_view_count_probabilistic = $vars->sc_full_cache_incr_view_count_probabilistic === 'Y' ? true : false;
		$config->full_cache_include_404 = $vars->sc_full_cache_include_404 === 'Y' ? true : false;
		
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
	 * Save Board cache settings.
	 */
	public function procSuperCacheAdminInsertBoardCache()
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
		
		if ($config->paging_cache && !getAdminModel('supercache')->isListReplacementSupported())
		{
			return $this->error('msg_supercache_list_replacement_not_supported');
		}
		
		if ($config->paging_cache_use_offset && !getAdminModel('supercache')->isOffsetQuerySupported())
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

		$config->search_cache = $vars->sc_search_cache === 'Y' ? true : false;
		$config->search_cache_duration = intval($vars->sc_search_cache_duration) ?: 3600;
		
		if ($config->search_cache && !getAdminModel('supercache')->isListReplacementSupported())
		{
			return $this->error('msg_supercache_list_replacement_not_supported');
		}
		
		if ($config->search_cache && !$config->paging_cache)
		{
			return $this->error('msg_supercache_search_cache_requires_paging_cache');
		}
		
		if ($vars->sc_search_cache_document_action)
		{
			$values = array_fill(0, count($vars->sc_search_cache_document_action), true);
			$config->search_cache_document_action = array_combine($vars->sc_search_cache_document_action, $values);
		}
		else
		{
			$config->search_cache_document_action = array();
		}
		
		if ($vars->sc_search_cache_comment_action)
		{
			$values = array_fill(0, count($vars->sc_search_cache_comment_action), true);
			$config->search_cache_comment_action = array_combine($vars->sc_search_cache_comment_action, $values);
		}
		else
		{
			$config->search_cache_comment_action = array();
		}
		
		if ($vars->sc_search_cache_exclude_modules)
		{
			$keys = array_map('intval', $vars->sc_search_cache_exclude_modules);
			$values = array_fill(0, count($keys), true);
			$config->search_cache_exclude_modules = array_combine($keys, $values);
		}
		else
		{
			$config->search_cache_exclude_modules = array();
		}

		// Save the new config.
		$output = $this->setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}
		
		// Redirect to the main config page.
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfigBoardCache'));
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
		$config->widget_cache_duration = intval($vars->sc_widget_cache_duration) ?: 300;
		$config->widget_cache_autoinvalidate_document = $vars->sc_widget_cache_autoinvalidate_document === 'Y' ? true : false;
		$config->widget_cache_autoinvalidate_comment = $vars->sc_widget_cache_autoinvalidate_comment === 'Y' ? true : false;
		
		// Organize per-widget config.
		$widgets = array();
		foreach (get_object_vars($vars) as $key => $value)
		{
			if (preg_match('/^sc_widget_cache_([a-zA-Z0-9_]+)_(enabled|group|duration|force)$/', $key, $matches))
			{
				$widget_name = $matches[1];
				if (!isset($widgets[$widget_name]))
				{
					$widgets[$widget_name] = array(
						'enabled' => false,
						'group' => false,
						'duration' => false,
						'force' => false,
					);
				}
				switch ($matches[2])
				{
					case 'enabled': $widgets[$widget_name]['enabled'] = $value === 'Y' ? true : false; break;
					case 'group': $widgets[$widget_name]['group'] = $value === 'Y' ? true : false; break;
					case 'duration': $widgets[$widget_name]['duration'] = intval($value) ?: false;
					case 'force': $widgets[$widget_name]['force'] = $value === 'Y' ? true : false; break;
				}
			}
		}
		$config->widget_config = $widgets;
		
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
		$config->block_css_request = $vars->sc_block_css_request === 'Y' ? true : false;
		$config->block_img_request = $vars->sc_block_img_request === 'Y' ? true : false;
		$config->auto_purge_cache_files = $vars->sc_auto_purge_cache_files === 'Y' ? true : false;
		$config->redirect_to_default_url = $vars->sc_redirect_to_default_url === 'Y' ? true : false;
		$config->use_gzip = trim($vars->sc_use_gzip);
		
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
