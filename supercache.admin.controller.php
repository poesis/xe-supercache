<?php

/**
 * Super Cache module: admin controller class
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 */
class SuperCacheAdminController extends SuperCache
{
	/**
	 * Save integrated search configuration.
	 */
	public function procSupercacheAdminInsertConfig()
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
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSupercacheAdminConfig'));
	}
}
