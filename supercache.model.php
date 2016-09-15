<?php

/**
 * Super Cache module: model class
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
class SuperCacheModel extends SuperCache
{
	/**
	 * Get a full page cache entry.
	 * 
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param array $args
	 * @return string|false
	 */
	public function getFullPageCache($module_srl, $document_srl, $args = array())
	{
		// Organize the request parameters.
		$config = $this->getConfig();
		$module_srl = intval($module_srl) ?: 0;
		$document_srl = intval($document_srl) ?: 0;
		ksort($args);
		
		// Check cache.
		$cache_key = sprintf('fullpage_cache:%d:%d:%s', $module_srl, $document_srl, $args ? hash('sha256', json_encode($args)) : 'content');
		$content = $this->getCache($cache_key, $config->full_cache_duration + 60);
		
		// Immediately re-cache expired cache entry for stampede protection.
		if ($config->full_cache_stampede_protection && $content['expires'] < time())
		{
			$contents['expires'] = time() + 60;
			$this->setCache($cache_key, $content, 60);
			return false;
		}
		else
		{
			return $content;
		}
	}
	
	/**
	 * Set a full page cache entry.
	 * 
	 * @param string $content
	 * @param float $elapsed_time
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param array $args
	 * @return bool
	 */
	public function setFullPageCache($content, $elapsed_time, $module_srl, $document_srl, $args = array())
	{
		// Organize the request parameters.
		$config = $this->getConfig();
		$module_srl = intval($module_srl) ?: 0;
		$document_srl = intval($document_srl) ?: 0;
		ksort($args);
		
		// Organize the content.
		$content = array(
			'content' => strval($content),
			'elapsed' => number_format($elapsed_time * 1000, 1) . ' ms',
			'cached' => time(),
			'expires' => time() + $config->full_cache_duration,
		);
		
		// Save to cache.
		$cache_key = sprintf('fullpage_cache:%d:%d:%s', $module_srl, $document_srl, $args ? hash('sha256', json_encode($args)) : 'content');
		return $this->setCache($cache_key, $content, $config->full_cache_duration + 60);
	}
	
	/**
	 * Get the number of documents in a module.
	 * 
	 * @param int $module_srl
	 * @param array $category_srl
	 * @return int|false
	 */
	public function getDocumentCount($module_srl, $category_srl)
	{
		// Organize the module and category info.
		$config = $this->getConfig();
		$module_srl = intval($module_srl);
		if (!is_array($category_srl))
		{
			$category_srl = $category_srl ? explode(',', $category_srl) : array();
		}
		
		// Check cache.
		$cache_key = sprintf('document_count:%d:%s', $module_srl, count($category_srl) ? end($category_srl) : 'all');
		if (mt_rand(0, $config->paging_cache_auto_refresh) !== 0)
		{
			$count = $this->getCache($cache_key, $config->paging_cache_duration);
			if ($count !== false)
			{
				return intval($count);
			}
		}
		
		// Get count from DB and store it in cache.
		$args = new stdClass;
		$args->module_srl = $module_srl;
		$args->category_srl = $category_srl;
		$args->statusList = array('PUBLIC', 'SECRET');
		$output = executeQuery('supercache.getDocumentCount', $args);
		if ($output->toBool() && isset($output->data->count))
		{
			$count = intval($output->data->count);
			$this->setCache($cache_key, $count, $config->paging_cache_duration);
			return $count;
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Get a list of documents.
	 * 
	 * @param object $args
	 * @param int $total_count
	 * @return object
	 */
	public function getDocumentList($args, $total_count)
	{
		// Sanitize and remove unnecessary arguments.
		$page_count = intval($args->page_count) ?: 10;
		$page = intval(max(1, $args->page));
		unset($args->page_count);
		unset($args->page);
		
		// Execute the query.
		$output = executeQuery('supercache.getDocumentList', $args);
		if (is_object($output->data))
		{
			$output->data = array($output->data);
		}
		
		// Fill in virtual numbers to emulate XE search results.
		$virtual_number = $total_count - (($page - 1) * $args->list_count);
		$virtual_range = count($output->data) ? range($virtual_number, $virtual_number - count($output->data) + 1, -1) : array();
		$output->data = count($output->data) ? array_combine($virtual_range, $output->data) : array();
		
		// Fill in missing fields to emulate XE search results.
		$output->total_count = $total_count;
		$output->total_page = max(1, ceil($total_count / $args->list_count));
		$output->page = $page;
		$output->page_navigation = new PageHandler($output->total_count, $output->total_page, $page, $page_count);
		
		// Return the result.
		return $output;
	}
	
	/**
	 * Update a cached document count.
	 * 
	 * @param int $module_srl
	 * @param array $category_srl
	 * @param int $diff
	 * @return int|false
	 */
	public function updateDocumentCount($module_srl, $category_srl, $diff)
	{
		$config = $this->getConfig();
		$categories = $this->_getAllParentCategories($module_srl, $category_srl);
		$categories[] = 'all';
		
		foreach ($categories as $category)
		{
			$cache_key = sprintf('document_count:%d:%s', $module_srl, $category);
			$count = $this->getCache($cache_key, $config->paging_cache_duration);
			if ($count !== false)
			{
				$this->setCache($cache_key, $count + $diff, $config->paging_cache_duration);
			}
		}
	}
	
	/**
	 * Get all parent categories of a category_srl.
	 * 
	 * @param int $module_srl
	 * @param int $category_srl
	 * @return array
	 */
	protected function _getAllParentCategories($module_srl, $category_srl)
	{
		// Abort if the category_srl is empty.
		if (!$category_srl)
		{
			return array();
		}
		
		// Abort if the category_srl does not belong to the given module_srl.
		$categories = getModel('document')->getCategoryList($module_srl);
		if (!isset($categories[$category_srl]))
		{
			return array();
		}
		
		// Find all parents.
		$category = $categories[$category_srl];
		$result[] = $category->category_srl;
		while ($category->parent_srl && isset($categories[$category->parent_srl]))
		{
			$category = $categories[$category->parent_srl];
			$result[] = $category->category_srl;
		}
		return $result;
	}
	
	/**
	 * Get all child categories of a category_srl.
	 * 
	 * @param int $module_srl
	 * @param int $category_srl
	 * @return array
	 */
	protected function _getAllChildCategories($module_srl, $category_srl)
	{
		// Abort if the category_srl is empty.
		if (!$category_srl)
		{
			return array();
		}
		
		// Abort if the category_srl does not belong to the given module_srl.
		$categories = getModel('document')->getCategoryList($module_srl);
		if (!isset($categories[$category_srl]))
		{
			return array();
		}
		
		// Find all children.
		$category = $categories[$category_srl];
		$result = $category->childs ?: array();
		$result[] = $category->category_srl;
		return $result;
	}
}
