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
	 * Trigger called at moduleObject.proc (before)
	 */
	public function triggerBeforeModuleObjectProc($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		if (!$config->paging_cache || !($document_srl = Context::get('document_srl')) || Context::get('page'))
		{
			return;
		}
		
		// If this is a document view request without a page number, fill in the page number to prevent the getDocumentListPage query.
		if (preg_match('/^(?:board|bodex|beluxe)\.disp(?:board|bodex|beluxe)content/i', $obj->module_info->module . '.' . $obj->act))
		{
			// Use the referer to figure out which page the visitor came from.
			if ($referer = $_SERVER['HTTP_REFERER'])
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
	 * Terminate the current request.
	 */
	public function terminateRequest($type = '')
	{
		$output = new Object;
		$output->add('supercache_terminated', $type);
		$oDisplayHandler = new DisplayHandler;
		$oDisplayHandler->printContent($output);
		Context::close();
		exit;
	}
}
