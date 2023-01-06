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
		if (!is_array($content))
		{
			return false;
		}
		
		// Apply stampede protection.
		$current_timestamp = time();
		if ($config->full_cache_stampede_protection !== false && $content['expires'] <= $current_timestamp)
		{
			$content['expires'] = $current_timestamp + 60;
			$this->setCache($cache_key, $content, 60);
			return false;
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
		$extra_duration = ($config->full_cache_stampede_protection !== false) ? 60 : 0;
		return $this->setCache($cache_key, $content, $config->full_cache_duration + $extra_duration);
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
			$this->_invalidateSubgroupCacheKey('fullpage_module:' . $module_srl);
		}
		if ($document_srl)
		{
			$this->_invalidateSubgroupCacheKey('fullpage_document:' . $document_srl);
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
		if (!is_array($content))
		{
			return false;
		}
		
		// Apply stampede protection.
		$current_timestamp = time();
		if ($content['expires'] <= $current_timestamp)
		{
			$content['expires'] = $current_timestamp + 60;
			$this->setCache($cache_key, $content, 60);
			return false;
		}
		
		// Execute the query to reconstruct the document list.
		$query_args = new stdClass;
		$query_args->document_srl = $content['document_srls'];
		$query_args->list_count = $content['list_count'];
		$query_args->sort_index = $content['sort_index'];
		$query_args->order_type = $content['order_type'];
		$output = executeQueryArray('supercache.getDocumentListWithIndexHint', $query_args);
		if (!$output->data)
		{
			$output->data = array();
		}
		
		// Fill in pagination data to emulate XE search results.
		$this->_fillPaginationData($output, $content['total_count'], $content['list_count'] ?: 20, $args->page_count ?: 10, $args->page ?: 1);
		
		// Fill in division data to emulate XE search results.
		if (isset($content['division']) && $content['division'])
		{
			Context::set('division', $content['division']);
		}
		if (isset($content['last_division']) && $content['last_division'])
		{
			Context::set('last_division', $content['last_division']);
		}
		
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
			'division' => intval(Context::get('division')),
			'last_division' => intval(Context::get('last_division')),
			'cached' => time(),
			'expires' => time() + $config->search_cache_duration,
		);
		foreach ($result->data as $document)
		{
			$content['document_srls'][] = $document->document_srl;
		}
		
		// Save to cache.
		$cache_key = $this->_getSearchResultCacheKey($args);
		return $this->setCache($cache_key, $content, $config->search_cache_duration + 60);
	}
	
	/**
	 * Delete a search result cache entry.
	 * 
	 * @param int $module_srl
	 * @param bool $is_comment
	 * @return bool
	 */
	public function deleteSearchResultCache($module_srl = 0, $is_comment = false)
	{
		// Invalidate the subgroup cache keys for the module.
		if ($module_srl)
		{
			if ($is_comment)
			{
				$this->_invalidateSubgroupCacheKey('module_search:' . intval($module_srl) . '_comment');
			}
			else
			{
				$this->_invalidateSubgroupCacheKey('module_search:' . intval($module_srl));
			}
		}
		
		// We don't have any reason to return anything else here.
		return true;
	}
	
	/**
	 * Get a widget cache entry.
	 * 
	 * @param string $cache_key
	 * @param int $cache_duration
	 * @return string|false
	 */
	public function getWidgetCache($cache_key, $cache_duration)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check cache.
		$content = $this->getCache($cache_key, $cache_duration);
		if (!is_array($content))
		{
			return false;
		}
		
		// Apply stampede protection.
		$current_timestamp = time();
		if ($config->widget_cache_stampede_protection !== false && $content['expires'] <= $current_timestamp)
		{
			$content['expires'] = $current_timestamp + 60;
			$this->setCache($cache_key, $content, 60);
			return false;
		}
		
		// Return the cached content.
		return $content['content'];
	}
	
	/**
	 * Set a widget cache entry.
	 * 
	 * @param string $cache_key
	 * @param int $cache_duration
	 * @param string $content
	 * @param array $target_modules
	 * @return bool
	 */
	public function setWidgetCache($cache_key, $cache_duration, $content, $target_modules)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Organize the content.
		$content = array(
			'content' => strval($content),
			'expires' => time() + $cache_duration,
		);
		
		// Save widget content to cache.
		$extra_duration = ($config->widget_cache_stampede_protection !== false) ? 60 : 0;
		$result = $this->setCache($cache_key, $content, $cache_duration + $extra_duration);
		
		// Save target modules.
		$target_key_base = $this->_getSubgroupCacheKey('widget_target');
		foreach ($target_modules as $target_module_srl)
		{
			if ($target_module_srl)
			{
				$target_key = $target_key_base . ':' . $target_module_srl;
				$target_list = $this->getCache($target_key) ?: array();
				$target_list[$cache_key] = true;
				$this->setCache($target_key, $target_list);
			}
		}
		
		// Return the result.
		return $result;
	}
	
	/**
	 * Invalidate widget cache entries for a target module.
	 * 
	 * @param int $target_module_srl
	 * @return bool
	 */
	public function invalidateWidgetCache($target_module_srl)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Stop if target module_srl is empty.
		if (!$target_module_srl)
		{
			return false;
		}
		
		// Get the list of affected cache keys.
		$target_key = $this->_getSubgroupCacheKey('widget_target') . ':' . $target_module_srl;
		$target_list = $this->getCache($target_key) ?: array();
		$target_count = 0;
		
		// Adjust the expiry date of all affected cache keys.
		foreach ($target_list as $cache_key => $unused)
		{
			if ($config->widget_cache_stampede_protection !== false)
			{
				$content = $this->getCache($cache_key);
				if (is_array($content) && $content['expires'] > time() + 5)
				{
					$content['expires'] = time();
					$this->setCache($cache_key, $content, 30);
					$target_count++;
				}
			}
			else
			{
				$this->deleteCache($cache_key);
			}
		}
		
		// Return true if any keys were invalidated.
		return $target_count ? true : false;
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
		$cache_key = $this->_getDocumentCountCacheKey($module_srl, $category_srl);
		if (mt_rand(0, $config->paging_cache_auto_refresh) !== 0)
		{
			$content = $this->getCache($cache_key, $config->paging_cache_duration);
			if (is_array($content))
			{
				return $content['count'];
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
			$content = array(
				'count' => intval($output->data->count),
				'cached' => time(),
				'expires' => time() + $config->paging_cache_duration,
			);
			$this->setCache($cache_key, $content, $config->paging_cache_duration);
			return $content['count'];
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
		$output = executeQueryArray('supercache.getDocumentList', $args);
		if (!$output->data)
		{
			$output->data = array();
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
			$cache_key = $this->_getDocumentCountCacheKey($module_srl, $category);
			$content = $this->getCache($cache_key, $config->paging_cache_duration);
			if (is_array($content) && $content['expires'] > time())
			{
				$content['count'] += $diff;
				$this->setCache($cache_key, $content, $content['expires'] - time());
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
			if (method_exists($oDB, 'addPrefixes'))
			{
				$querystring = sprintf('UPDATE documents SET readed_count = readed_count + %d WHERE document_srl = %d', $incr, $document_srl);
				$output = $oDB->_query($oDB->addPrefixes($querystring));
			}
			else
			{
				$oDB->query_id = 'supercache.updateReadedCount';
				$querystring = sprintf('UPDATE %sdocuments SET readed_count = readed_count + %d WHERE document_srl = %d', $oDB->prefix, $incr, $document_srl);
				$output = $oDB->_query($querystring);
			}
			return $output ? true : false;
		}
	}
	
	/**
	 * Generate a cache key for the widget cache.
	 * 
	 * @param object $widget_attr
	 * @param object $logged_info
	 * @return string
	 */
	public function getWidgetCacheKey($widget_attrs, $logged_info)
	{
		if (!$logged_info || !$logged_info->member_srl)
		{
			$group_key = 'nogroup';
		}
		elseif ($logged_info->is_admin === 'Y')
		{
			$group_key = 'admin';
		}
		else
		{
			$groups = $logged_info->group_list;
			sort($groups);
			$group_key = sha1(implode('|', $groups));
		}
		
		$subgroup_key = $this->_getSubgroupCacheKey('widget');
		return sprintf('%s:%s:%s:%s', $subgroup_key, $widget_attrs->widget, hash('sha256', serialize($widget_attrs)), $group_key);
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
		$module_key = $this->_getSubgroupCacheKey('fullpage_module:' . $module_srl);
		$document_key = $document_srl ? $this->_getSubgroupCacheKey('fullpage_document:' . $document_srl) : 'module_index';
		
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
		return sprintf('%s:%s:%s_%s', $module_key, $document_key, $user_agent_type, $argskey);
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
		$comment_key = ($args->search_target === 'comment') ? '_comment' : '';
		$module_key = $this->_getSubgroupCacheKey('board_search:' . intval($args->module_srl) . $comment_key);
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
		return sprintf('%s:%s:%s:p%d', $module_key, $category_key, $search_key, max(1, intval($args->page)));
	}
	
	/**
	 * Generate a cache key for the document count cache.
	 * 
	 * @param int $module_srl
	 * @param int $category_srl
	 * @return string
	 */
	protected function _getDocumentCountCacheKey($module_srl, $category_srl)
	{
		// Generate module and category subgroup keys.
		$module_key = $this->_getSubgroupCacheKey('document_count:' . intval($module_srl));
		$category_key = 'category_' . ($category_srl ? ((is_array($category_srl) && count($category_srl)) ? end($category_srl) : $category_srl) : 'all');
		
		// Generate the cache key.
		return sprintf('%s:%s', $module_key, $category_key);
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
			$subgroup_key = intval($this->getCache('subgroups:' . $cache_key));
			if (!$subgroup_key)
			{
				$subgroup_key = 1;
				$this->setCache('subgroups:' . $cache_key, $subgroup_key, 0);
			}
			$this->_subgroup_keys[$cache_key] = $subgroup_key;
		}
		
		if ($subgroup_portion_only)
		{
			return $subgroup_key;
		}
		else
		{
			return $cache_key . ':' . $subgroup_key;
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
		$old_subgroup_key = $this->_getSubgroupCacheKey($cache_key, true);
		$new_subgroup_key = $old_subgroup_key + 1;
		
		$this->setCache('subgroups:' . $cache_key, $new_subgroup_key, 0);
		$this->_subgroup_keys[$cache_key] = $new_subgroup_key;
		
		$config = $this->getConfig();
		if ($config->auto_purge_cache_files)
		{
			if (self::$_cache_handler_cache instanceof SuperCacheFileDriver)
			{
				self::$_cache_handler_cache->invalidateSubgroupKey($cache_key, $old_subgroup_key);
			}
		}
		
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
