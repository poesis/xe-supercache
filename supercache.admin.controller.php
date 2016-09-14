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
	 * Save paging cache settings.
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
		if (!getAdminModel('supercache')->isOffsetQuerySupported())
		{
			$config->paging_cache_use_offset = false;
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
