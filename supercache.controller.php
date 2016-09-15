<?php

/**
 * Super Cache module: controller class
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
class SuperCacheController extends SuperCache
{
	/**
	 * The maximum supported page.
	 */
	protected $_maxSupportedPage = 1;
	
	/**
	 * Flag to cache the current request.
	 */
	protected $_cacheCurrentRequest = null;
	protected $_cacheStartTimestamp = null;
	
	/**
	 * Trigger called at moduleHandler.init (before)
	 */
	public function triggerBeforeModuleHandlerInit($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check the full page cache.
		if ($config->full_cache)
		{
			$this->checkFullCache($obj, $config);
		}
	}
	
	/**
	 * Trigger called at moduleObject.proc (before)
	 */
	public function triggerBeforeModuleObjectProc($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Fill the page variable for paging cache.
		if ($config->paging_cache)
		{
			$this->fillPageVariable($obj, $config);
		}
	}
	
	/**
	 * Trigger called at document.getDocumentList (before)
	 */
	public function triggerBeforeGetDocumentList($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		if (!$config->paging_cache || (!$obj->mid && !$obj->module_srl))
		{
			return;
		}
		
		// If this is a POST search request (often caused by sketchbook skin), abort to prevent double searching.
		if ($config->disable_post_search && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' && !$_POST['act'] && $obj->search_keyword)
		{
			return $this->terminateRequest('disable_post_search');
		}

		// Abort if this request is for any page greater than 1.
		if ($obj->page > $this->_maxSupportedPage && !$config->paging_cache_use_offset)
		{
			return;
		}
		
		// Abort if there are any search terms other than module_srl and category_srl.
		if ($obj->search_target || $obj->search_keyword || $obj->exclude_module_srl || $obj->start_date || $obj->end_date || $obj->member_srl)
		{
			return;
		}
		
		// Abort if there are any other unusual search options.
		$oDocumentModel = getModel('document');
		$oDocumentModel->_setSearchOption($obj, $args, $query_id, $use_division);
		if ($query_id !== 'document.getDocumentList' || $use_division || (is_array($args->module_srl) && count($args->module_srl) > 1))
		{
			return;
		}
		
		// Abort if the module is excluded by configuration.
		if (isset($config->paging_cache_exclude_modules[$args->module_srl]))
		{
			return;
		}
		
		// Abort if the module/category has fewer documents than the threshold.
		$oModel = getModel('supercache');
		$document_count = $oModel->getDocumentCount($args->module_srl, $args->category_srl);
		if ($document_count < $config->paging_cache_threshold)
		{
			return;
		}
		
		// Add offset to simulate paging.
		if ($config->paging_cache_use_offset && $args->page > 1)
		{
			$args->list_offset = ($args->page - 1) * $args->list_count;
		}
		
		// Get documents and replace the output.
		$obj->use_alternate_output = $oModel->getDocumentList($args, $document_count);
	}
	
	/**
	 * Trigger called at document.insertDocument (after)
	 */
	public function triggerAfterInsertDocument($obj)
	{
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, 1);
	}
	
	/**
	 * Trigger called at document.updateDocument (after)
	 */
	public function triggerAfterUpdateDocument($obj)
	{
		$original = getModel('document')->getDocument($obj->document_srl);
		$original_module_srl = intval($original->get('module_srl'));
		$original_category_srl = intval($original->get('category_srl'));
		$new_module_srl = intval($obj->module_srl) ?: $original_module_srl;
		$new_category_srl = intval($obj->category_srl) ?: $original_category_srl;
		
		if ($original_module_srl !== $new_module_srl || $original_category_srl !== $new_category_srl)
		{
			$oModel = getModel('supercache');
			$oModel->updateDocumentCount($new_module_srl, $new_category_srl, 1);
			if ($original_module_srl)
			{
				$oModel->updateDocumentCount($original_module_srl, $original_category_srl, -1);
			}
		}
	}
	
	/**
	 * Trigger called at document.deleteDocument (after)
	 */
	public function triggerAfterDeleteDocument($obj)
	{
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, -1);
	}
	
	/**
	 * Trigger called at document.copyDocumentModule (after)
	 */
	public function triggerAfterCopyDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentModule (after)
	 */
	public function triggerAfterMoveDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentToTrash (after)
	 */
	public function triggerAfterMoveDocumentToTrash($obj)
	{
		$this->triggerAfterDeleteDocument($obj);
	}
	
	/**
	 * Trigger called at document.restoreTrash (after)
	 */
	public function triggerAfterRestoreDocumentFromTrash($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at display (after)
	 */
	public function triggerAfterDisplay($obj)
	{
		if ($this->_cacheCurrentRequest)
		{
			$elapsed_time = microtime(true) - $this->_cacheStartTimestamp;
			getModel('supercache')->setFullPageCache(
				$obj,
				$elapsed_time,
				$this->_cacheCurrentRequest[0],
				$this->_cacheCurrentRequest[1],
				$this->_cacheCurrentRequest[2]
			);
		}
	}
	
	/**
	 * Check the full page cache for the current request,
	 * and terminate the request with a cached response if available.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @return void
	 */
	public function checkFullCache($obj, $config)
	{
		// Abort if not an HTML GET request.
		if (Context::getRequestMethod() !== 'GET')
		{
			return;
		}
		
		// Abort if logged in.
		$logged_info = Context::get('logged_info');
		if ($logged_info && $logged_info->member_srl)
		{
			return;
		}
		
		// Abort if the current act is excluded.
		if (isset($config->full_cache_exclude_acts[$obj->act]))
		{
			return;
		}
		
		// Abort if the current module is excluded.
		if (!$obj->mid && !$obj->module && !$obj->module_srl)
		{
			$site_module_info = Context::get('site_module_info');
			$module_srl = $site_module_info->module_srl;
		}
		elseif ($obj->module_srl)
		{
			$module_srl = $obj->module_srl;
		}
		elseif ($obj->mid)
		{
			$site_module_info = Context::get('site_module_info');
			$module_info = getModel('module')->getModuleInfoByMid($obj->mid, intval($site_module_info->site_srl) ?: 0);
			$module_srl = $module_info ? $module_info->module_srl : 0;
		}
		else
		{
			$module_srl = 0;
		}
		
		$module_srl = intval($module_srl);
		if (!$module_srl || isset($config->full_cache_exclude_modules[$module_srl]))
		{
			return;
		}
		
		// Determine the page type.
		if ($obj->act)
		{
			$page_type = 'other';
		}
		elseif ($obj->document_srl)
		{
			$page_type = 'document';
		}
		elseif ($module_srl)
		{
			$page_type = 'module';
		}
		else
		{
			return;
		}
		
		// Abort if the current page type is not selected for caching.
		if (!isset($config->full_cache_type[$page_type]))
		{
			return;
		}
		
		// Remove unnecessary request variables.
		$request_vars = Context::getRequestVars();
		if (is_object($request_vars))
		{
			$request_vars = get_object_vars($request_vars);
		}
		unset($request_vars['mid'], $request_vars['module'], $request_vars['module_srl'], $request_vars['document_srl']);
		
		// Check the cache.
		$oModel = getModel('supercache');
		switch ($page_type)
		{
			case 'module':
				$this->_cacheCurrentRequest = array($module_srl, 0, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, 0, $request_vars);
				break;
			case 'document':
				$this->_cacheCurrentRequest = array($module_srl, $obj->document_srl, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, $obj->document_srl, $request_vars);
				break;
			case 'other':
				$this->_cacheCurrentRequest = array(0, 0, $request_vars);
				$cache = $oModel->getFullPageCache(0, 0, $request_vars);
				break;
		}
		
		// If cached content is available, print it and exit.
		if ($cache)
		{
			$expires = max(0, $cache['expires'] - time());
			if ($config->full_cache_use_headers)
			{
				$this->printCacheControlHeaders($page_type, $expires, $config->full_cache_stampede_protection ? 10 : 0);
			}
			else
			{
				header('X-SuperCache: type=' . $page_type . '; expires=' . $expires);
			}
			
			if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $cache['cached'])
			{
				header('HTTP/1.1 304 Not Modified');
			}
			else
			{
				header("Content-Type: text/html; charset=UTF-8");
				echo $cache['content'];
				echo "\n" . '<!--' . "\n";
				echo '    Serving ' . strlen($cache['content']) . ' bytes from full page cache' . "\n";
				echo '    Generated at ' . date('Y-m-d H:i:s P', $cache['cached']) . ' in ' . $cache['elapsed'] . "\n";
				echo '    Cache expires in ' . $expires . ' seconds' . "\n";
				echo '-->' . "\n";
			}
			Context::close();
			exit;
		}
		
		// Otherwise, prepare headers to cache the current request.
		if ($config->full_cache_use_headers)
		{
			$this->printCacheControlHeaders($page_type, $config->full_cache_duration, $config->full_cache_stampede_protection ? 10 : 0);
		}
		else
		{
			header('X-SuperCache: type=' . $page_type . '; expires=' . $config->full_cache_duration);
		}
		$this->_cacheStartTimestamp = microtime(true);
	}
	
	/**
	 * Print cache control headers.
	 * 
	 * @param string $page_type
	 * @param int $expires
	 * @param int $scatter
	 * @return void
	 */
	public function printCacheControlHeaders($page_type, $expires, $scatter)
	{
		$scatter = intval($expires * ($scatter / 100));
		$expires = intval($expires - mt_rand(0, $scatter));
		header('X-SuperCache: type=' . $page_type . '; expires=' . $config->full_cache_duration);
		header('Cache-Control: max-age=' . $expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
		header_remove('Pragma');
	}
	
	/**
	 * If this is a document view request without a page number,
	 * fill in the page number to prevent the getDocumentListPage query.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @return void
	 */
	public function fillPageVariable($obj, $config)
	{
		// Only work if there is a document_srl without a page variable and a suitable referer header.
		if (Context::get('document_srl') && !Context::get('page') && ($referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false))
		{
			// Check the module and act.
			if (preg_match('/^(?:board|bodex|beluxe)\.disp(?:board|bodex|beluxe)content/i', $obj->module_info->module . '.' . $obj->act))
			{
				// Only guess the page number from the same module in the same site.
				if (strpos($referer, '//' . $_SERVER['HTTP_HOST'] . '/') === false)
				{
					return;
				}
				elseif (preg_match('/\/([a-zA-Z0-9_-]+)(?:\?|(?:\/\d+)?$)/', $referer, $matches) && $matches[1] === $obj->mid)
				{
					Context::set('page', 1);
				}
				elseif (preg_match('/\bmid=([a-zA-Z0-9_-]+)\b/', $referer, $matches) && $matches[1] === $obj->mid)
				{
					if (preg_match('/\bpage=(\d+)\b/', $referer, $matches))
					{
						Context::set('page', $matches[1]);
					}
					else
					{
						Context::set('page', 1);
					}
				}
			}
		}
	}
	
	/**
	 * Terminate the current request.
	 * 
	 * @param string $reason
	 * @param array $data (optional)
	 * @return exit
	 */
	public function terminateRequest($reason = '', $data = array())
	{
		$output = new Object;
		$output->add('supercache_terminated', $reason);
		foreach ($data as $key => $value)
		{
			$output->add($key, $value);
		}
		$oDisplayHandler = new DisplayHandler;
		$oDisplayHandler->printContent($output);
		Context::close();
		exit;
	}
}
