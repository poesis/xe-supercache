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
	 * Subgroup cache keys are stored here.
	 */
	protected $_subgroup_keys = array();
	
	/**
	 * Get a full page cache entry.
	 * 
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param bool $user_agent_type
	 * @param array $args
	 * @return string|false
	 */
	public function getFullPageCache($module_srl, $document_srl, $user_agent_type, array $args)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check cache.
		$cache_key = $this->_getFullPageCacheKey($module_srl, $document_srl, $user_agent_type, $args);
		$content = $this->getCache($cache_key, $config->full_cache_duration + 60);
		
		// Apply stampede protection.
		if ($config->full_cache_stampede_protection && $content)
		{
			$current_timestamp = time();
			if ($content['expires'] <= $current_timestamp)
			{
				$content['expires'] = $current_timestamp + 60;
				$this->setCache($cache_key, $content, 60);
				return false;
			}
		}
		
		// Return the cached content.
		return $content;
	}
	
	/**
	 * Set a full page cache entry.
	 * 
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param bool $user_agent_type
	 * @param array $args
	 * @param string $content
	 * @param array $extra_data
	 * @param int $http_status_code
	 * @param float $elapsed_time
	 * @return bool
	 */
	public function setFullPageCache($module_srl, $document_srl, $user_agent_type, array $args, $content, array $extra_data, $http_status_code, $elapsed_time)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Organize the content.
		$content = array(
			'content' => strval($content),
			'cached' => time(),
			'expires' => time() + $config->full_cache_duration,
			'extra_data' => $extra_data,
			'status' => intval($http_status_code),
			'elapsed' => number_format($elapsed_time * 1000, 1) . ' ms',
		);
		
		// Save to cache.
		$cache_key = $this->_getFullPageCacheKey($module_srl, $document_srl, $user_agent_type, $args);
		return $this->setCache($cache_key, $content, $config->full_cache_duration + 60);
	}
	
	/**
	 * Delete a full page cache entry.
	 * 
	 * @param int $module_srl
	 * @param int $document_srl
	 * @return bool
	 */
	public function deleteFullPageCache($module_srl = 0, $document_srl = 0)
	{
		// Invalidate the subgroup cache keys for the module and/or document.
		if ($module_srl)
		{
			$this->_invalidateSubgroupCacheKey('module_' . $module_srl);
		}
		if ($document_srl)
		{
			$this->_invalidateSubgroupCacheKey('document_' . $document_srl);
		}
		
		// We don't have any reason to return anything else here.
		return true;
	}
	
	/**
	 * Get a search result cache entry.
	 * 
	 * @param object $args
	 * @return object|false
	 */
	public function getSearchResultCache($args)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check cache.
		$cache_key = $this->_getSearchResultCacheKey($args);
		$content = $this->getCache($cache_key, $config->search_cache_duration);
		if (!$content)
		{
			return false;
		}
		
		// Execute the query to reconstruct the document list.
		$query_args = new stdClass;
		$query_args->document_srl = $content['document_srls'];
		$query_args->list_count = $content['list_count'];
		$query_args->sort_index = $content['sort_index'];
		$query_args->order_type = $content['order_type'];
		$output = executeQuery('supercache.getDocumentList', $query_args);
		if (is_object($output->data))
		{
			$output->data = array($output->data);
		}
		
		// Fill in pagination data to emulate XE search results.
		$this->_fillPaginationData($output, $content['total_count'], $content['list_count'] ?: 20, $args->page_count ?: 10, $args->page ?: 1);
		
		// Return the result.
		return $output;
	}
	
	/**
	 * Set a search result cache entry.
	 * 
	 * @param object $args
	 * @param object $result
	 * @return bool
	 */
	public function setSearchResultCache($args, $result)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Organize the content.
		$content = array(
			'document_srls' => array(),
			'total_count' => $result->total_count,
			'list_count' => intval($args->list_count),
			'sort_index' => trim($args->sort_index) ?: 'list_order',
			'order_type' => trim($args->order_type) ?: 'asc',
			'cached' => time(),
			'expires' => time() + $config->search_cache_duration,
		);
		foreach ($result->data as $document)
		{
			$content['document_srls'][] = $document->document_srl;
		}
		
		// Save to cache.
		$cache_key = $this->_getSearchResultCacheKey($args);
		return $this->setCache($cache_key, $content, $config->search_cache_duration);
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
		
		// Fill in pagination data to emulate XE search results.
		$this->_fillPaginationData($output, $total_count, $args->list_count, $page_count, $page);
		
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
	 * Update the view count of a cached document.
	 * 
	 * @param int $document_srl
	 * @param array $extra_data
	 * @return bool
	 */
	public function updateDocumentViewCount($document_srl, $extra_data)
	{
		$config = $this->getConfig();
		$document_srl = intval($document_srl);
		if (!$document_srl || !$extra_data)
		{
			return;
		}
		
		if ($config->full_cache_incr_view_count_probabilistic)
		{
			$probability = max(1, floor(log($extra_data['view_count'], 1.5)));
			$incr = mt_rand(0, $probability) === 0 ? $probability : 0;
		}
		else
		{
			$incr = 1;
		}
		
		if ($incr)
		{
			$oDB = DB::getInstance();
			$oDB->query_id = 'supercache.updateReadedCount';
			$output = $oDB->_query(sprintf('UPDATE %sdocuments SET readed_count = readed_count + %d WHERE document_srl = %d', $oDB->prefix, $incr, $document_srl));
			return $output ? true : false;
		}
	}
	
	/**
	 * Generate a cache key for the full page cache.
	 * 
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param bool $user_agent_type
	 * @param array $args
	 * @return string
	 */
	protected function _getFullPageCacheKey($module_srl, $document_srl, $user_agent_type, array $args = array())
	{
		// Organize the request parameters.
		$module_srl = intval($module_srl) ?: 0;
		$document_srl = intval($document_srl) ?: 0;
		ksort($args);
		
		// Generate module and document subgroup keys.
		$module_key = $this->_getSubgroupCacheKey('module_' . $module_srl);
		$document_key = $document_srl ? $this->_getSubgroupCacheKey('document_' . $document_srl) : 'document_0';
		
		// Generate the arguments key.
		if (!count($args))
		{
			$argskey = 'p1';
		}
		elseif (count($args) === 1 && isset($args['page']) && (is_int($args['page']) || ctype_digit(strval($args['page']))))
		{
			$argskey = 'p' . $args['page'];
		}
		else
		{
			$argskey = hash('sha256', json_encode($args));
		}
		
		// Generate the cache key.
		return sprintf('fullpage:%s:%s:%s_%s', $module_key, $document_key, $user_agent_type, $argskey);
	}
	
	/**
	 * Generate a cache key for the search result cache.
	 * 
	 * @param object $args
	 * @return string
	 */
	protected function _getSearchResultCacheKey($args)
	{
		// Generate module and category subgroup keys.
		$module_key = $this->_getSubgroupCacheKey('search_module_' . intval($args->module_srl));
		$category_key = 'category_' . intval($args->category_srl);
		
		// Generate the arguments key.
		$search_key = hash('sha256', json_encode(array(
			'search_target' => trim($args->search_target),
			'search_keyword' => trim($args->search_keyword),
			'sort_index' => trim($args->sort_index) ?: 'list_order',
			'order_type' => trim($args->order_type) ?: 'asc',
			'list_count' => intval($args->list_count),
			'page_count' => intval($args->page_count),
			'isExtraVars' => (bool)($args->isExtraVars),
		)));
		
		// Generate the cache key.
		return sprintf('board_search:%s:%s:%s:p%d', $module_key, $category_key, $search_key, max(1, intval($args->page)));
	}
	
	/**
	 * Get subgroup cache key.
	 * 
	 * @param string $cache_key
	 * @param bool $subgroup_portion_only (optional)
	 * @return string
	 */
	protected function _getSubgroupCacheKey($cache_key, $subgroup_portion_only = false)
	{
		if (isset($this->_subgroup_keys[$cache_key]))
		{
			$subgroup_key = $this->_subgroup_keys[$cache_key];
		}
		else
		{
			$subgroup_key = intval($this->getCache($cache_key . '_sgkey'));
			if (!$subgroup_key)
			{
				$subgroup_key = 1;
				$this->setCache($cache_key . '_sgkey', $subgroup_key, 0);
			}
			$this->_subgroup_keys[$cache_key] = $subgroup_key;
		}
		
		if ($subgroup_portion_only)
		{
			return $subgroup_key;
		}
		else
		{
			return $cache_key . '_sg' . $subgroup_key;
		}
	}
	
	/**
	 * Invalidate subgroup cache key.
	 * 
	 * @param string $cache_key
	 * @return bool
	 */
	protected function _invalidateSubgroupCacheKey($cache_key)
	{
		$subgroup_key = $this->_getSubgroupCacheKey($cache_key, true);
		$subgroup_key++;
		
		$this->setCache($cache_key . '_sgkey', $subgroup_key, 0);
		$this->_subgroup_keys[$cache_key] = $subgroup_key;
		return true;
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
	
	/**
	 * Fill in pagination data to a query output.
	 * 
	 * @param object $output
	 * @param int $total_count
	 * @param int $list_count
	 * @param int $page_count
	 * @param int $page
	 * @return void
	 */
	protected function _fillPaginationData(&$output, $total_count, $list_count, $page_count, $page)
	{
		// Fill in virtual numbers.
		$virtual_number = $total_count - (($page - 1) * $list_count);
		$virtual_range = count($output->data) ? range($virtual_number, $virtual_number - count($output->data) + 1, -1) : array();
		$output->data = count($output->data) ? array_combine($virtual_range, $output->data) : array();
		
		// Fill in pagination fields.
		$output->total_count = $total_count;
		$output->total_page = max(1, ceil($total_count / $list_count));
		$output->page = $page;
		$output->page_navigation = new PageHandler($output->total_count, $output->total_page, $page, $page_count);
	}
}
