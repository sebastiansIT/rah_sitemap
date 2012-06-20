<?php

/**
 * Rah_sitemap plugin for Textpattern CMS
 *
 * @author Jukka Svahn
 * @date 2008-
 * @license GNU GPLv2
 * @link http://rahforum.biz/plugins/rah_sitemap
 *
 * Requires Textpattern v4.4.1 or newer.
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		rah_sitemap::install();
		add_privs('plugin_prefs.rah_sitemap', '1,2');
		register_callback(array('rah_sitemap', 'prefs'), 'plugin_prefs.rah_sitemap');
		register_callback(array('rah_sitemap', 'install'), 'plugin_lifecycle.rah_sitemap');
		register_callback(array('rah_sitemap', 'prefs_save'), 'prefs', 'advanced_prefs_save', 1);
	}
	elseif(@txpinterface == 'public') {
		register_callback(array('rah_sitemap', 'page_handler'), 'textpattern');
	}

class rah_sitemap {

	static public $version = '1.2';
	
	/**
	 * @var obj Stores instances
	 */
	
	static public $instance = NULL;
	
	/**
	 * @var array Stores XML urlset
	 */
	
	protected $urlset = array();
	
	/**
	 * @var array Stores allowed article fields
	 */
	
	protected $article_fields = array();

	/**
	 * Installer
	 * @param string $event Admin-side callback event.
	 * @param string $step Admin-side plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_sitemap\_%'"
			);
			
			return;
		}
		
		$current = isset($prefs['rah_sitemap_version']) ?
			(string) $prefs['rah_sitemap_version'] : 'base';
		
		if($current === self::$version) {
			return;
		}
		
		$opt = array(
			'exclude_categories' => '',
			'exclude_sections' => '',
			'exclude_fields' => array(),
			'urls' => '',
			'future_articles' => 0,
			'past_articles' => 1,
			'expired_articles' => 1,
			'exclude_sticky_articles' => 1,
		);
		
		@$rs = 
			safe_rows(
				'name, value',
				'rah_sitemap_prefs',
				'1=1'
			);
		
		if($rs) {
			
			foreach($rs as $a) {
				
				if(trim($a['value']) === '') {
					continue;
				}
			
				if($a['name'] == 'articlecategories') {
					foreach(do_list($a['value']) as $v) {
						$opt['exclude_fields'][] = 'Category1: ' . $v;
						$opt['exclude_fields'][] = 'Category2: ' . $v;
					}
				}
				
				elseif($a['name'] == 'articlesections') {
					foreach(do_list($a['value']) as $v) {
						$opt['exclude_fields'][] = 'Section: ' . $v;
					}
				}
				
				elseif($a['name'] == 'sections') {
					$opt['exclude_sections'] = do_list($a['value']);
				}
				
				elseif($a['name'] == 'categories' && strpos($a['value'], 'article_||_') !== false) {
					foreach(do_list($a['value']) as $k => $v) {
						if(strpos($v, 'article_||_') === 0) {
							$opt['exclude_categories'] = substr($v, 11);
						}
					}
				}
				
				elseif(isset($opt[$a['name']])) {
					$opt[$a['name']] = $a['value'];
				}
			}
			
			@safe_query('DROP TABLE IF EXISTS '.safe_pfx('rah_sitemap_prefs'));	
		}
		
		@$rs = 
			safe_column(
				'url',
				'rah_sitemap',
				'1=1'
			);
		
		if($rs) {
			$opt['urls'] = implode(',', $rs);
			@safe_query('DROP TABLE IF EXISTS '.safe_pfx('rah_sitemap'));
		}
		
		$position = 259;
		
		foreach($opt as $name => $value) {
		
			$n = 'rah_sitemap_' . $name;
			$position++;
			
			if(isset($prefs[$n])) {
				continue;
			}
			
			if(is_array($value)) {
				$value = implode(',', $value);
			}
			
			if($name == 'exclude_categories') {
				$html = 'rah_sitemap_categories';
			}
			
			elseif($name == 'exclude_sections') {
				$html = 'rah_sitemap_sections';
			}
			
			elseif($name == 'exclude_fields' || $name == 'urls') {
				$html = 'rah_sitemap_textarea';
			}
			
			else {
				$html = 'yesnoradio';
			}
			
			safe_insert(
				'txp_prefs',
				"prefs_id=1,
				name='{$n}',
				val='".doSlash($value)."',
				type=1,
				event='rah_sitemap',
				html='{$html}',
				position=".$position
			);

			$prefs[$n] = $value;
		}
		
		set_pref('rah_sitemap_version', self::$version, 'rah_sitemap', 2, '', 0);
		$prefs['rah_sitemap_version'] = self::$version;
	}
	
	/**
	 * Gets an instance of the class
	 * @param bool $new_instance
	 * @return obj
	 */
	
	static public function get($new_instance=false) {
		
		if(self::$instance === NULL || $new_instance) {
			self::$instance = new rah_sitemap();
		}
		
		return self::$instance;
	}
	
	/**
	 * Handles preference saving
	 */
	
	static public function prefs_save() {
	
		if(empty($_POST) || !is_array($_POST)) {
			return;
		}
		
		foreach(array('exclude_sections', 'exclude_categories') as $name) {
		
			$name = 'rah_sitemap_' . $name;
		
			if(isset($_POST[$name]) && is_array($_POST[$name])) {
				$_POST[$name] = implode(', ', $_POST[$name]);
			}
			
			else {
				$_POST[$name] = '';
			}
		}
	}
	
	/**
	 * Handles returning the sitemap
	 */
	
	static public function page_handler() {
		
		global $pretext;
		
		if(!gps('rah_sitemap') && strpos(end(explode('/', $pretext['request_uri'])), 'sitemap.xml') !== 0) {
			return;
		}
		
		return self::get()->populate_article_fields()->get_sitemap();
	}

	/**
	 * Generates and outputs the sitemap
	 */

	protected function get_sitemap() {
		
		global $prefs;
		
		$this->url(hu);
		
		$s = array_merge(array("'default'"), quote_list(do_list($prefs['rah_sitemap_exclude_sections'])));
		
		$rs = 
			safe_rows(
				'name',
				'txp_section',
				'name NOT IN('.implode(',', $s).') ORDER BY name ASC'
			);
		
		foreach($rs as $a) {
			$this->url(pagelinkurl(array('s' => $a['name'])));
		}
		
		$c = array_merge(array("'root'"), quote_list(do_list($prefs['rah_sitemap_exclude_categories'])));
		
		$rs = 
			safe_rows(
				'name',
				'txp_category',
				"name NOT IN(".implode(',', $c).") AND type='article' ORDER BY name asc"
			);
		
		foreach($rs as $a) {
			$this->url(pagelinkurl(array('c' => $a['name'])));
		}
		
		$sql = array('Status >= 4');
		
		foreach(do_list($prefs['rah_sitemap_exclude_fields']) as $field) {
			if($field) {
				$f = explode(':', $field);
				$n = strtolower(trim($f[0]));

				if(isset($this->article_fields[$n])) {
					$sql[] = $this->article_fields[$n]." NOT LIKE '".doSlash(trim(implode(':', array_slice($f, 1))))."'";
				}
			}
		}
		
		if($prefs['rah_sitemap_exclude_sticky_articles']) {
			$sql[] = 'Status != 5';
		}
		
		if(!$prefs['rah_sitemap_future_articles']) {
			$sql[] = 'Posted <= now()';
		}
		
		if(!$prefs['rah_sitemap_past_articles']) {
			$sql[] = 'Posted >= now()';
		}
		
		if(!$prefs['rah_sitemap_expired_articles']) {
			$sql[] = "(Expires = ".NULLDATETIME." or Expires >= now())";
		}
		
		$rs = 
			safe_rows(
				'*, unix_timestamp(Posted) as uPosted, unix_timestamp(LastMod) as uLastMod',
				'textpattern',
				implode(' and ', $sql) . ' ORDER BY Posted DESC'
			);
		
		foreach($rs as $a) {
			$this->url(permlinkurl($a), (int) max($a['uLastMod'], $a['uPosted']));
		}
		
		foreach(do_list($prefs['rah_sitemap_urls']) as $url) {
			if($url) {
				$this->url($url);
			}
		}
		
		callback_event('rah_sitemap.urlset');
		
		$xml = 
			'<?xml version="1.0" encoding="utf-8"?>'.
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
			implode('', $this->urlset).
			'</urlset>';
		
		header('Content-type: application/xml');
		
		if(
			strpos(serverSet('HTTP_ACCEPT_ENCODING'), 'gzip') !== false && 
			@extension_loaded('zlib') && 
			@ini_get('zlib.output_compression') == 0 && 
			@ini_get('output_handler') != 'ob_gzhandler' &&
			!@headers_sent()
		) {
			header('Content-Encoding: gzip');
			$xml = gzencode($xml);
		}
		
		echo $xml;
		exit;
	}
	
	/**
	 * Generates XML sitemap url item
	 * @param string $url
	 * @param int|string $lastmod
	 * @return obj
	 */
	
	public function url($url, $lastmod=NULL) {
	
		if(strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
			$url = hu . ltrim(htmlspecialchars($url), '/');
		}
		
		if($lastmod !== NULL) {
		
			if(is_string($lastmod)) {
				$lastmod = strtotime($lastmod);
			}
			
			if($lastmod !== false) {
				$lastmod = date('c', $lastmod);
			}
		}
		
		$this->urlset[] = 
			'<url>'.
				'<loc>'.$url.'</loc>'.
				($lastmod ? '<lastmod>'.$lastmod.'</lastmod>' : '').
			'</url>';
		
		return $this;
	}

	/**
	 * Populates allowed article fields
	 * @return obj
	 */

	protected function populate_article_fields() {
	
		$columns = (array) @getThings('describe '.safe_pfx('textpattern'));
		
		foreach($columns as $name) {
			$this->article_fields[strtolower($name)] = $name;
		}
		
		foreach(getCustomFields() as $id => $name) {
			$this->article_fields[$name] = 'custom_'.intval($id);
		}
		
		return $this;
	}

	/**
	 * Options page
	 */

	static public function prefs() {
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_sitemap_exclude_categories">'.gTxt('rah_sitemap_view_prefs').'</a><br />'.n.
			'	<a href="'.hu.'?rah_sitemap=sitemap">'.gTxt('rah_sitemap_view_sitemap').'</a>'.
			'</p>';
	}
	
	/**
	 * Returns a multi-select option
	 * @param string $name
	 * @param array $values
	 * @param string|array $selected
	 * @return HTML markup
	 */
	
	static public function multiselect($name, $values, $selected) {
		
		if(!is_array($selected)) {
			$selected = do_list($selected);
		}
		
		$name = htmlspecialchars($name);
		$out = array();
		$i = 0;
		
		foreach($values as $v => $t) {
			$id = $name.($i++);
			$out[] = '<input type="checkbox" id="'.$id.'" name="'.$name.'[]" value="'.htmlspecialchars($v).'"'.(in_array($v, $selected) ? ' checked="checked"' : '').' /> <label for="'.$id.'">'.htmlspecialchars($t).'</label><br />';
		}
		
		return implode(n, $out);
	}
}

/**
 * Lists all available sections
 * @param string $name
 * @param string $value
 * @return string HTML
 */

	function rah_sitemap_sections($name, $value) {
		
		$rs = 
			safe_rows(
				'name, title',
				'txp_section',
				"name != 'default' ORDER BY title asc"
			);
		
		$out = array();
		
		foreach($rs as $a) {
			$out[$a['name']] = $a['title'];
		}
		
		return rah_sitemap::multiselect($name, $out, $value);
	}

/**
 * Lists all available categories
 * @param string $name
 * @param string $value
 * @return string HTML
 */

	function rah_sitemap_categories($name, $value) {
		
		$rs = 
			safe_rows(
				'name, title',
				'txp_category',
				"type = 'article' AND name != 'root' ORDER BY title asc"
			);
		
		$out = array();
		
		foreach($rs as $a) {
			$out[$a['name']] = $a['title'];
		}
		
		return rah_sitemap::multiselect($name, $out, $value);
	}

/**
 * Lists all excluded article fields
 * @param string $name
 * @param string $value
 * @return string HTML textarea
 */

	function rah_sitemap_textarea($name, $value) {
		return text_area($name, 60, 300, $value, $name);
	}

?>