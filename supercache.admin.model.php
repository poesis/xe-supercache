<?php

/**
 * Super Cache module: admin model class
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
class SuperCacheAdminModel extends SuperCache
{
	/**
	 * Check if the current version of XE supports board list replacement.
	 * 
	 * @return int
	 */
	public function isListReplacementSupported()
	{
		if (version_compare(__XE_VERSION__, '1.8.25', '>='))
		{
			return 1;
		}
		
		$document_model_filename = _XE_PATH_ . 'modules/document/document.model.php';
		$document_model_checkstr = '$obj->use_alternate_output';
		if (file_exists($document_model_filename) && strpos(file_get_contents($document_model_filename), $document_model_checkstr) !== false)
		{
			return 2;
		}
		
		return 0;
	}
	
	/**
	 * Check if the current version of XE supports offset queries.
	 * 
	 * @return int
	 */
	public function isOffsetQuerySupported()
	{
		if (version_compare(__XE_VERSION__, '1.8.42', '>='))
		{
			return 1;
		}
		
		if (defined('RX_VERSION') && version_compare(RX_VERSION, '1.8.25', '>='))
		{
			return 1;
		}
		
		$limit_tag_filename = _XE_PATH_ . 'classes/db/queryparts/limit/Limit.class.php';
		$limit_tag_checkstr = '$offset';
		if (file_exists($limit_tag_filename) && strpos(file_get_contents($limit_tag_filename), $limit_tag_checkstr) !== false)
		{
			return 2;
		}
		
		return 0;
	}
	
	/**
	 * Check if the current environment supports Memcached.
	 * 
	 * @return int
	 */
	public function isMemcachedSupported()
	{
		if (class_exists('Memcache'))
		{
			return 1;
		}
		
		if (class_exists('Memcached'))
		{
			if (defined('RX_VERSION'))
			{
				return 1;
			}
			
			$memcached_filename = _XE_PATH_ . 'classes/cache/CacheMemcache.class.php';
			$memcached_checkstr = 'new Memcached';
			if (file_exists($memcached_filename) && strpos(file_get_contents($memcached_filename), $memcached_checkstr) !== false)
			{
				return 2;
			}
		}
		
		return 0;
	}
}
