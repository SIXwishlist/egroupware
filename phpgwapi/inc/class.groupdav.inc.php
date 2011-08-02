<?php
/**
 * EGroupware: CalDAV/CardDAV/GroupDAV access
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package api
 * @subpackage groupdav
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright (c) 2007-10 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

require_once('HTTP/WebDAV/Server.php');

/**
 * EGroupware: GroupDAV access
 *
 * Using a modified PEAR HTTP/WebDAV/Server class from egw-pear!
 *
 * One can use the following url's releative (!) to http://domain.com/egroupware/groupdav.php
 *
 * - /addressbook/ all addressbooks current user has rights to
 * - /calendar/    calendar of current user
 * - /infolog/     infologs of current user
 * - /             base of the above, only certain clients (KDE, Apple) can autodetect folders from there
 * - /<username>/addressbook/ addressbook of user or group <username> given the user has rights to view it
 * - /<username>/calendar/    calendar of user <username> given the user has rights to view it
 * - /<username>/infolog/     InfoLog's of user <username> given the user has rights to view it
 * - /<username>/             base of the above, only certain clients (KDE, Apple) can autodetect folders from there
 *
 * Calling one of the above collections with a GET request / regular browser generates an automatic index
 * from the data of a allprop PROPFIND, allow to browse CalDAV/CardDAV/GroupDAV tree with a regular browser.
 *
 * @todo All principal urls should either contain no account_lid (eg. base64 of it) or use urlencode($account_lid)
 * @link http://www.groupdav.org GroupDAV spec
 */
class groupdav extends HTTP_WebDAV_Server
{
	/**
	 * DAV namespace
	 */
	const DAV = 'DAV:';
	/**
	 * GroupDAV namespace
	 */
	const GROUPDAV = 'http://groupdav.org/';
	/**
	 * CalDAV namespace
	 */
	const CALDAV = 'urn:ietf:params:xml:ns:caldav';
	/**
	 * CardDAV namespace
	 */
	const CARDDAV = 'urn:ietf:params:xml:ns:carddav';
	/**
	 * Calendarserver namespace (eg. for ctag)
	 */
	const CALENDARSERVER = 'http://calendarserver.org/ns/';
	/**
	 * Apple iCal namespace (eg. for calendar color)
	 */
	const ICAL = 'http://apple.com/ns/ical/';
	/**
	 * Realm and powered by string
	 */
	const REALM = 'EGroupware CalDAV/CardDAV/GroupDAV server';

	var $dav_powered_by = self::REALM;
	var $http_auth_realm = self::REALM;

	var $root = array(
		'calendar' => array(
			'resourcetype' => array(self::GROUPDAV => 'vevent-collection', self::CALDAV => 'calendar'),
			'component-set' => array(self::GROUPDAV => 'VEVENT'),
		),
		'addressbook' => array(
			'resourcetype' => array(self::GROUPDAV => 'vcard-collection', self::CARDDAV => 'addressbook'),
			'component-set' => array(self::GROUPDAV => 'VCARD'),
		),
		'infolog' => array(
			'resourcetype' => array(self::GROUPDAV => 'vtodo-collection', self::CALDAV => 'calendar'),
			'component-set' => array(self::GROUPDAV => 'VTODO'),
		),
		'principals' => array(
			'resourcetype' => array(self::DAV => 'principal'),
		)
	);
	/**
	 * Debug level: 0 = nothing, 1 = function calls, 2 = more info, 3 = complete $_SERVER array
	 *
	 * Can now be enabled on a per user basis in GroupDAV prefs, if it is set here to 0!
	 *
	 * The debug messages are send to the apache error_log
	 *
	 * @var integer
	 */
	var $debug = 0;

	/**
	 * eGW's charset
	 *
	 * @var string
	 */
	var $egw_charset;
	/**
	 * Instance of our application specific handler
	 *
	 * @var groupdav_handler
	 */
	var $handler;
	/**
	 * principal URL
	 *
	 * @var string
	 */
	var $principalURL;
	/**
	 * Reference to the accounts class
	 *
	 * @var accounts
	 */
	var $accounts;


	function __construct()
	{
		if (!$this->debug) $this->debug = (int)$GLOBALS['egw_info']['user']['preferences']['groupdav']['debug_level'];

		if ($this->debug > 2) error_log('groupdav: $_SERVER='.array2string($_SERVER));

		// identify clients, which do NOT support path AND full url in <D:href> of PROPFIND request
		switch(groupdav_handler::get_agent())
		{
			case 'kde':	// KAddressbook (at least in 3.5 can NOT subscribe / does NOT find addressbook)
				$this->client_require_href_as_url = true;
				$this->cnrnd = true; // Akonadi seems to require redundant namespaces, see KDE bug #265096 https://bugs.kde.org/show_bug.cgi?id=265096
				break;
			case 'cfnetwork':	// Apple addressbook app
			case 'dataaccess':	// iPhone addressbook
				$this->client_require_href_as_url = false;
				$this->cnrnd = true;
				break;
			case 'davkit':	// iCal app in OS X 10.6 created wrong request, if full url given
			case 'coredav':	// iCal app in OS X 10.7
				$this->client_require_href_as_url = false;
				$this->cnrnd = true;
				break;
			case 'cfnetwork_old':
				$this->crrnd = true; // Older Apple Addressbook.app does not cope with namespace redundancy
				break;
			case 'neon':
				$this->cnrnd = true; // neon clients like cadaver
				break;
		}
		// adding EGroupware version to X-Dav-Powered-By header eg. "EGroupware 1.8.001 CalDAV/CardDAV/GroupDAV server"
		$this->dav_powered_by = str_replace('EGroupware','EGroupware '.$GLOBALS['egw_info']['server']['versions']['phpgwapi'],
			$this->dav_powered_by);

		parent::HTTP_WebDAV_Server();

		$this->egw_charset = translation::charset();
		if (strpos($this->base_uri, 'http') === 0)
		{
			$this->principalURL = $this->_slashify($this->base_uri);
		}
		else
		{
			$this->principalURL = (@$_SERVER["HTTPS"] === "on" ? "https:" : "http:") .
				'//' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '/';
		}
		$this->principalURL .= 'principals/users/'.$GLOBALS['egw_info']['user']['account_lid'].'/';

		// if client requires pathes instead of URLs
		if ($this->client_require_href_as_url === false)
		{
			$this->principalURL = parse_url($this->principalURL,PHP_URL_PATH);
		}
		$this->accounts = $GLOBALS['egw']->accounts;
	}

	/**
	 * get the handler for $app
	 *
	 * @param string $app
	 * @return groupdav_handler
	 */
	function app_handler($app)
	{
		return groupdav_handler::app_handler($app,$this->debug,$this->base_uri,$this->principalURL);
	}

	/**
	 * OPTIONS request, allow to modify the standard responses from the pear-class
	 *
	 * @param string $path
	 * @param array &$dav
	 * @param array &$allow
	 */
	function OPTIONS($path, &$dav, &$allow)
	{
		list(,$app) = explode('/',$path);
		switch($app)
		{
			case 'calendar':
				if (!in_array(2,$dav)) $dav[] = 2;
				$dav[] = 'access-control';
				$dav[] = 'calendar-access';
				//$dav[] = 'calendar-schedule';
				//$dav[] = 'calendar-proxy';
				//$dav[] = 'calendar-avialibility';
				//$dav[] = 'calendarserver-private-events';
				break;
			case 'addressbook':
				if (!in_array(2,$dav)) $dav[] = 2;
				//$dav[] = 3;	// revision aka versioning support not implemented
				$dav[] = 'access-control';
				$dav[] = 'addressbook';	// CardDAV uses "addressbook" NOT "addressbook-access"
				break;
			default:
				if (!in_array(2,$dav)) $dav[] = 2;
				$dav[] = 'access-control';
				$dav[] = 'calendar-access';
				$dav[] = 'addressbook';
		}
		// not yet implemented: $dav[] = 'access-control';
	}

	/**
	 * PROPFIND and REPORT method handler
	 *
	 * @param  array  general parameter passing array
	 * @param  array  return array for file properties
	 * @return bool   true on success
	 */
	function PROPFIND(&$options, &$files, $method='PROPFIND')
	{
		if ($this->debug) error_log(__CLASS__."::$method(".array2string($options,true).')');

		// parse path in form [/account_lid]/app[/more]
		if (!self::_parse_path($options['path'],$id,$app,$user,$user_prefix) && $app && !$user)
		{
			if ($this->debug > 1) error_log(__CLASS__."::$method: user='$user', app='$app', id='$id': 404 not found!");
			return '404 Not Found';
		}
		if ($this->debug > 1) error_log(__CLASS__."::$method: user='$user', app='$app', id='$id'");

		if ($user)
		{
			$account_lid = $this->accounts->id2name($user);
		}
		else
		{
			$account_lid = $GLOBALS['egw_info']['user']['account_lid'];
		}
		$account = $this->accounts->read($account_lid);
		$files = array('files' => array());
		$path = $user_prefix = $this->_slashify($user_prefix);

		if (!$app)	// user root folder containing apps
		{
			if (empty($user_prefix))
			{
				$user_prefix = '/'; //.$GLOBALS['egw_info']['user']['account_lid'].'/';
			}
			$calendar_user_address_set = array(
				self::mkprop('href','urn:uuid:'.$account['account_lid']),
			);
			if ($user < 0)
			{
				$principalType = 'groups';
				$displayname = lang('Group').' '.$account['account_lid'];
			}
			else
			{
				$principalType = 'users';
				$displayname = $account['account_fullname'];
				$calendar_user_address_set[] = self::mkprop('href','MAILTO:'.$account['account_email']);
			}
			$calendar_user_address_set[] = self::mkprop('href',$this->base_uri.'/principals/'.$principalType.'/'.$account['account_lid'].'/');

			if ($options['depth'] && $user_prefix == '/')
			{
				$displayname = 'EGroupware (Cal|Card|Group)DAV server';
			}

			$displayname = translation::convert($displayname, translation::charset(),'utf-8');
			// self url
			$props = array(
					self::mkprop('displayname',$displayname),
					self::mkprop('owner',array(self::mkprop('href',$this->base_uri.'/principals/'.$principalType.'/'.$account_lid.'/'))),
					self::mkprop('resourcetype',array(self::mkprop('collection',''))),
					// adding the calendar extra property (calendar-home-set, etc.) here, allows apple iCal to "autodetect" the URL
					self::mkprop(groupdav::CALDAV,'calendar-home-set',array(
						self::mkprop('href',$this->base_uri.$user_prefix))),
					self::mkprop(groupdav::CARDDAV,'addressbook-home-set',array(
						self::mkprop('href',$this->base_uri.$user_prefix))),
					self::mkprop('current-user-principal',array(self::mkprop('href',$this->principalURL))),
					self::mkprop(groupdav::CALDAV,'calendar-user-address-set',$calendar_user_address_set),
					self::mkprop(groupdav::CALENDARSERVER,'email-address-set',array(
						self::mkprop(groupdav::CALENDARSERVER,'email-address',$GLOBALS['egw_info']['user']['email']))),
					//self::mkprop('principal-URL',array(self::mkprop('href',$this->principalURL))),
					self::mkprop('principal-collection-set',array(self::mkprop('href',$this->base_uri.'/principals/'))),
					// OUTBOX URLs of the current user
					self::mkprop(groupdav::CALDAV,'schedule-outbox-URL',array(
						self::mkprop(groupdav::DAV,'href',$this->base_uri.'/calendar/'))),
			);
			//$props = self::current_user_privilege_set($props);
			$files['files'][] = array(
				'path'  => $path,
				'props' => $props,
			);
			if ($options['depth'])
			{
				if (strlen($path) == 1) // GroupDAV Root
				{
					// principals collection
					$files['files'][] = array(
		            	'path'  => '/principals/',
		            	'props' => array(
			            	self::mkprop('displayname',lang('Accounts')),
							self::mkprop('resourcetype',array(self::mkprop('principals',''))),
							self::mkprop('current-user-principal',array(self::mkprop('href',$this->principalURL))),
							//self::mkprop(groupdav::CALDAV,'calendar-home-set',array(
							//	self::mkprop('href',$this->base_uri.$user_prefix))),
							//self::mkprop(groupdav::CARDDAV,'addressbook-home-set',array(
							//	self::mkprop('href',$this->base_uri.$user_prefix))),
							//self::mkprop('principal-URL',array(self::mkprop('href',$this->principalURL))),
		            	),
					);
				}
				foreach($this->root as $app => $data)
				{
					if (!$GLOBALS['egw_info']['user']['apps'][$app]) continue;	// no rights for the given app
					$props = $this->_properties($app,false,$user,$path);
					// add ctag if handler implements it
					if (($handler = self::app_handler($app)) && method_exists($handler,'getctag'))
					{
						$props[] = self::mkprop(
							groupdav::CALENDARSERVER,'getctag',$handler->getctag($options['path'],$user));
					}
					$props[] = self::mkprop('getetag','EGw-'.$app.'-wGE');
					$files['files'][] = array(
		            	'path'  => $path.$app.'/',
		            	'props' => $props,
		            );
				}
			}
			return true;
		}
		if ($app != 'principals' && !$GLOBALS['egw_info']['user']['apps'][$app])
		{
			if ($this->debug) error_log(__CLASS__."::$method(path=$options[path]) 403 Forbidden: no app rights for '$app'");
			return "403 Forbidden: no app rights for '$app'";	// no rights for the given app
		}
		if (($handler = self::app_handler($app)))
		{
			if ($method != 'REPORT' && !$id)	// no self URL for REPORT requests (only PROPFIND) or propfinds on an id
			{
				$files['files'][0] = array(
		        	'path'  => $path.$app.'/',
					// KAddressbook doubles the folder, if the self URL contains the GroupDAV/CalDAV resourcetypes
		        	'props' => $this->_properties($app,$app=='addressbook'&&$handler->get_agent()=='kde',$user,$path),
		        );
				// add ctag if handler implements it (only for depth 0)
				if (method_exists($handler,'getctag'))
				{
					$files['files'][0]['props'][] = HTTP_WebDAV_Server::mkprop(
						groupdav::CALENDARSERVER,'getctag',$handler->getctag($options['path'],$user));
				}
				if (!$options['depth']) return true;	// depth 0 --> show only the self url
			}
			return $handler->propfind($this->_slashify($options['path']),$options,$files,$user,$id);
		}
		return '501 Not Implemented';
	}

	/**
	 * Get the properties of a collection
	 *
	 * @param string $app
	 * @param boolean $no_extra_types=false should the GroupDAV and CalDAV types be added (KAddressbook has problems with it in self URL)
	 * @param int $user=null owner of the collection, default current user
	 * @param string $path='/'
	 * @return array of DAV properties
	 */
	function _properties($app,$no_extra_types=false,$user=null,$path='/')
	{
		if ($this->debug) error_log(__METHOD__."(app='$app', no_extra_types=$no_extra_types, user='$user', path='$path')");
		$user_preferences = $GLOBALS['egw_info']['user']['preferences'];
		if ($user)
		{
			$account_lid = $this->accounts->id2name($user);
			if ($user >= 0 && $GLOBALS['egw']->preferences->account_id != $user)
			{
				$GLOBALS['egw']->preferences->__construct($user);
				$user_preferences = $GLOBALS['egw']->preferences->read_repository();
				$GLOBALS['egw']->preferences->__construct($GLOBALS['egw_info']['user']['account_lid']);
			}
		}
		else
		{
			$account_lid = $GLOBALS['egw_info']['user']['account_lid'];
		}

		if (strlen($user_preferences['calendar']['display_color']) == 9 &&
			$user_preferences['calendar']['display_color'][0] == '#')
		{
			$display_color = $user_preferences['calendar']['display_color'];
		}
		else
		{
			$display_color = '#0040A0FF';
		}

		$account = $this->accounts->read($account_lid);
		$displayname = $GLOBALS['egw']->translation->convert($account['account_fullname'],
				$GLOBALS['egw']->translation->charset(),'utf-8');
				
		if ($user < 0)
		{
			$principalType = 'groups';
		}
		else
		{
			$principalType = 'users';
		}

		$props = array(
			self::mkprop('current-user-principal',array(self::mkprop('href',$this->principalURL))),
			self::mkprop('owner',array(self::mkprop('href',$this->base_uri.'/principals/'.$principalType.'/'.$account_lid.'/'))),
			//self::mkprop('principal-URL',array(self::mkprop('href',$this->principalURL))),
			self::mkprop('alternate-URI-set',array(
				self::mkprop('href','MAILTO:'.$GLOBALS['egw_info']['user']['email']))),
			self::mkprop('principal-collection-set',array(
				self::mkprop('href',$this->base_uri.'/principals/'),
			)),
			self::mkprop('principal-URL',array(self::mkprop('href',$this->principalURL))),
			self::mkprop(groupdav::CALDAV,'calendar-user-address-set',array(
				self::mkprop('href','MAILTO:'.$GLOBALS['egw_info']['user']['email']),
				self::mkprop('href',$this->base_uri.'/principals/'.$principalType.'/'.$GLOBALS['egw_info']['user']['account_lid'].'/'),
				self::mkprop('href','urn:uuid:'.$GLOBALS['egw_info']['user']['account_lid']))),
			self::mkprop(groupdav::CALENDARSERVER,'email-address-set',array(
				self::mkprop(groupdav::CALENDARSERVER,'email-address',$GLOBALS['egw_info']['user']['email']))),
			self::mkprop('getetag','EGw-no-etag-wGE'),	// iPhone addressbook requires an etag here!
		);

		switch ($app)
		{
			case 'calendar':
				$props[] = self::mkprop(groupdav::CALDAV,'calendar-home-set',array(
					self::mkprop('href',$this->base_uri.$path.'calendar/')));
				// OUTBOX URLs of the current user
				$props[] =	self::mkprop(groupdav::CALDAV,'schedule-outbox-URL',
					array(self::mkprop(groupdav::DAV,'href',$this->base_uri.'/calendar/')));
				$props[] = self::mkprop(groupdav::ICAL,'calendar-color',$display_color);
				break;
			case 'infolog':
				$props[] = self::mkprop(groupdav::CALDAV,'calendar-home-set',array(
					self::mkprop('href',$this->base_uri.$path.'infolog/')));
				$displayname = translation::convert(lang($app).' '.
					common::grab_owner_name($user),$this->egw_charset,'utf-8');
				break;
			default:
				$props[] = self::mkprop(groupdav::CALDAV,'calendar-home-set',array(
					self::mkprop('href',$this->base_uri.$path)));
				$displayname = translation::convert(lang($app).' '.
				common::grab_owner_name($user),$this->egw_charset,'utf-8');
		}
		$props[] = self::mkprop(groupdav::CARDDAV,'addressbook-home-set',array(
				self::mkprop('href',$this->base_uri.$path)));
		$props[] = self::mkprop('displayname',$displayname);

		foreach((array)$this->root[$app] as $prop => $values)
		{
			if ($prop == 'resourcetype')
			{
				$resourcetype = array(
					self::mkprop('collection',''),
				);
				if (!$no_extra_types)
				{
					foreach($this->root[$app]['resourcetype'] as $ns => $type)
					{
						$resourcetype[] = self::mkprop($ns,$type,'');
					}
				}
				$props[] = self::mkprop('resourcetype',$resourcetype);
			}
			else
			{
				foreach($values as $ns => $value)
				{
					$props[] = self::mkprop($ns,$prop,$value);
				}
			}
		}
		if (method_exists($app.'_groupdav','extra_properties'))
		{
			$displayname = translation::convert(
				$account['account_id'] > 0 ? $account['account_fullname'] : lang('Group').' '.$account['account_lid'],
				translation::charset(),'utf-8');
			$props = ExecMethod2($app.'_groupdav::extra_properties',$props,$displayname,$this->base_uri);
		}
		return $props;
	}

	/**
	 * CalDAV/CardDAV REPORT method handler
	 *
	 * just calls PROPFIND()
	 *
	 * @param  array  general parameter passing array
	 * @param  array  return array for file properties
	 * @return bool   true on success
	 */
	function REPORT(&$options, &$files)
	{
		if ($this->debug > 1) error_log(__METHOD__.'('.array2string($options).')');

		return $this->PROPFIND($options,$files,'REPORT');
	}

	/**
	 * CalDAV/CardDAV REPORT method handler to get HTTP_WebDAV_Server to process REPORT requests
	 *
	 * Just calls http_PROPFIND()
	 */
	function http_REPORT()
	{
		parent::http_PROPFIND('REPORT');
	}

	/**
	 * GET method handler
	 *
	 * @param  array $options parameter passing array
	 * @return bool   true on success
	 */
	function GET(&$options)
	{
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		if (!$this->_parse_path($options['path'],$id,$app,$user) || $app == 'principals')
		{
			return $this->autoindex($options);

			error_log(__METHOD__."(".array2string($options).") 404 Not Found");
			return '404 Not Found';
		}
		if (($handler = self::app_handler($app)))
		{
			return $handler->get($options,$id,$user);
		}
		error_log(__METHOD__."(".array2string($options).") 501 Not Implemented");
		return '501 Not Implemented';
	}

	/**
	 * Display an automatic index (listing and properties) for a collection
	 *
	 * @param array $options parameter passing array, index "path" contains requested path
	 */
	protected function autoindex($options)
	{
		$propfind_options = array(
			'path'  => $options['path'],
			'depth' => 1,
		);
		$files = array();
		if (($ret = $this->PROPFIND($propfind_options,$files)) !== true)
		{
			return $ret;	// no collection
		}
		header('Content-type: text/html; charset='.$GLOBALS['egw']->translation->charset());
		echo "<html>\n<head>\n\t<title>".'EGroupware (Cal|Card|Group)DAV server '.htmlspecialchars($options['path'])."</title>\n";
		echo "\t<meta http-equiv='content-type' content='text/html; charset=utf-8' />\n";
		echo "\t<style type='text/css'>\n.th { background-color: #e0e0e0; }\n.row_on { background-color: #F1F1F1; }\n".
			".row_off { background-color: #ffffff; }\ntd { padding-left: 5px; }\nth { padding-left: 5px; text-align: left; }\n\t</style>\n";
		echo "</head>\n<body>\n";

		echo '<h1>(Cal|Card|Group)DAV ';
		$path = '/groupdav.php';
		foreach(explode('/',$this->_unslashify($options['path'])) as $n => $name)
		{
			$path .= ($n != 1 ? '/' : '').$name;
			echo html::a_href(htmlspecialchars($name.'/'),$path);
		}
		echo "</h1>\n";

		$n = 0;
		foreach($files['files'] as $file)
		{
			if (!isset($collection_props))
			{
				$collection_props = $this->props2array($file['props']);
				echo '<h3>'.lang('Collection listing').': '.htmlspecialchars($collection_props['DAV:displayname'])."</h3>\n";
				continue;	// own entry --> displaying properies later
			}
			if(!$n++)
			{
				echo "<table>\n\t<tr class='th'><th>#</th><th>".lang('Name')."</th><th>".lang('Size')."</th><th>".lang('Last modified')."</th><th>".
					lang('ETag')."</th><th>".lang('Content type')."</th><th>".lang('Resource type')."</th></tr>\n";
			}
			$props = $this->props2array($file['props']);
			//echo $file['path']; _debug_array($props);
			$class = $class == 'row_on' ? 'row_off' : 'row_on';

			if (substr($file['path'],-1) == '/')
			{
				$name = basename(substr($file['path'],0,-1)).'/';
			}
			else
			{
				$name = basename($file['path']);
			}

			echo "\t<tr class='$class'>\n\t\t<td>$n</td>\n\t\t<td>".html::a_href(htmlspecialchars($name),'/groupdav.php'.$file['path'])."</td>\n";
			echo "\t\t<td>".$props['DAV:getcontentlength']."</td>\n";
			echo "\t\t<td>".(!empty($props['DAV:getlastmodified']) ? date('Y-m-d H:i:s',$props['DAV:getlastmodified']) : '')."</td>\n";
			echo "\t\t<td>".$props['DAV:getetag']."</td>\n";
			echo "\t\t<td>".htmlspecialchars($props['DAV:getcontenttype'])."</td>\n";
			echo "\t\t<td>".self::prop_value($props['DAV:resourcetype'])."</td>\n\t</tr>\n";
		}
		if (!$n)
		{
			echo '<p>'.lang('Collection empty.')."</p>\n";
		}
		else
		{
			echo "</table>\n";
		}
		echo '<h3>'.lang('Properties')."</h3>\n";
		echo "<table>\n\t<tr class='th'><th>".lang('Namespace')."</th><th>".lang('Name')."</th><th>".lang('Value')."</th></tr>\n";
		foreach($collection_props as $name => $value)
		{
			$class = $class == 'row_on' ? 'row_off' : 'row_on';
			$ns = explode(':',$name);
			$name = array_pop($ns);
			$ns = implode(':',$ns);
			echo "\t<tr class='$class'>\n\t\t<td>".htmlspecialchars($ns)."</td><td>".htmlspecialchars($name)."</td>\n";
			echo "\t\t<td>".self::prop_value($value)."</td>\n\t</tr>\n";
		}
		echo "</table>\n";

		echo "</body>\n</html>\n";

		common::egw_exit();
	}

	/**
	 * Format a property value for output
	 *
	 * @param mixed $value
	 * @return string
	 */
	protected function prop_value($value)
	{
		if (is_array($value))
		{
			if (isset($value[0]['ns']))
			{
				$value = $this->_hierarchical_prop_encode($value);
			}
			$value = htmlspecialchars(array2string($value));
		}
		elseif (preg_match('/\<(D:)?href\>[^<]+\<\/(D:)?href\>/i',$value))
		{
			$value = preg_replace('/\<(D:)?href\>([^<]+)\<\/(D:)?href\>/i','&lt;\\1href&gt;<a href="\\2">\\2</a>&lt;/\\3href&gt;<br />',$value);
		}
		else
		{
			$value = htmlspecialchars($value);
		}
		return $value;
	}

	/**
	 * Return numeric indexed array with values for keys 'ns', 'name' and 'val' as array 'ns:name' => 'val'
	 *
	 * @param array $props
	 * @return array
	 */
	protected function props2array(array $props)
	{
		$arr = array();
		foreach($props as $prop)
		{
			switch($prop['ns'])
			{
				case 'DAV:';
					$ns = 'DAV';
					break;
				case self::CALDAV:
					$ns = 'CalDAV';
					break;
				case self::CARDDAV:
					$ns = 'CardDAV';
					break;
				case self::GROUPDAV:
					$ns = 'GroupDAV';
					break;
				default:
					$ns = $prop['ns'];
			}
			$ns_defs = '';
			$ns_hash = array($prop['ns'] => $ns, 'DAV:' => 'D');
			$arr[$ns.':'.$prop['name']] = is_array($prop['val']) ?
				$this->_hierarchical_prop_encode($prop['val'], $prop['ns'], $ns_defs, $ns_hash) : $prop['val'];
		}
		return $arr;
	}

	/**
	 * POST method handler
	 *
	 * @param  array  parameter passing array
	 * @return bool   true on success
	 */
	function POST(&$options)
	{
		// read the content in a string, if a stream is given
		if (isset($options['stream']))
		{
			$options['content'] = '';
			while(!feof($options['stream']))
			{
				$options['content'] .= fread($options['stream'],8192);
			}
		}
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		$this->_parse_path($options['path'],$id,$app,$user);

		if (($handler = self::app_handler($app)) &&	method_exists($handler, 'post'))
		{
			return $handler->post($options,$id,$user);
		}
		return '501 Not Implemented';
	}

	/**
	 * PUT method handler
	 *
	 * @param  array  parameter passing array
	 * @return bool   true on success
	 */
	function PUT(&$options)
	{
		// read the content in a string, if a stream is given
		if (isset($options['stream']))
		{
			$options['content'] = '';
			while(!feof($options['stream']))
			{
				$options['content'] .= fread($options['stream'],8192);
			}
		}

		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		if (!$this->_parse_path($options['path'],$id,$app,$user,$prefix))
		{
			return '404 Not Found';
		}
		if (($handler = self::app_handler($app)))
		{
			$status = $handler->put($options,$id,$user,$prefix);
			// set default stati: true --> 204 No Content, false --> should be already handled
			if (is_bool($status)) $status = $status ? '204 No Content' : '400 Something went wrong';
			return $status;
		}
		return '501 Not Implemented';
	}

	/**
	 * DELETE method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function DELETE($options)
	{
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		if (!$this->_parse_path($options['path'],$id,$app,$user))
		{
			return '404 Not Found';
		}
		if (($handler = self::app_handler($app)))
		{
			$status = $handler->delete($options,$id);
			// set default stati: true --> 204 No Content, false --> should be already handled
			if (is_bool($status)) $status = $status ? '204 No Content' : '400 Something went wrong';
			return $status;
		}
		return '501 Not Implemented';
	}

	/**
	 * MKCOL method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function MKCOL($options)
	{
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		return '501 Not Implemented';
	}

	/**
	 * MOVE method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function MOVE($options)
	{
		if ($this->debug) error_log(__METHOD__.'('.array2string($options).')');

		return '501 Not Implemented';
	}

	/**
	 * COPY method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function COPY($options, $del=false)
	{
		if ($this->debug) error_log('groupdav::'.($del ? 'MOVE' : 'COPY').'('.array2string($options).')');

		return '501 Not Implemented';
	}

	/**
	 * LOCK method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function LOCK(&$options)
	{
		self::_parse_path($options['path'],$id,$app,$user);
		$path = egw_vfs::app_entry_lock_path($app,$id);

		if ($this->debug) error_log(__METHOD__.'('.array2string($options).") path=$path");

		// get the app handler, to check if the user has edit access to the entry (required to make locks)
		$handler = self::app_handler($app);

		// TODO recursive locks on directories not supported yet
		if (!$id || !empty($options['depth']) || !$handler->check_access(EGW_ACL_EDIT,$id))
		{
			return '409 Conflict';
		}
		$options['timeout'] = time()+300; // 5min. hardcoded

		// dont know why, but HTTP_WebDAV_Server passes the owner in D:href tags, which get's passed unchanged to checkLock/PROPFIND
		// that's wrong according to the standard and cadaver does not show it on discover --> strip_tags removes eventual tags
		if (($ret = egw_vfs::lock($path,$options['locktoken'],$options['timeout'],strip_tags($options['owner']),
			$options['scope'],$options['type'],isset($options['update']),false)) && !isset($options['update']))		// false = no ACL check
		{
			return $ret ? '200 OK' : '409 Conflict';
		}
		return $ret;
	}

	/**
	 * UNLOCK method handler
	 *
	 * @param  array  general parameter passing array
	 * @return bool   true on success
	 */
	function UNLOCK(&$options)
	{
		self::_parse_path($options['path'],$id,$app,$user);
		$path = egw_vfs::app_entry_lock_path($app,$id);

		if ($this->debug) error_log(__METHOD__.'('.array2string($options).") path=$path");
		return egw_vfs::unlock($path,$options['token']) ? '204 No Content' : '409 Conflict';
	}

	/**
	 * checkLock() helper
	 *
	 * @param  string resource path to check for locks
	 * @return bool   true on success
	 */
	function checkLock($path)
	{
		self::_parse_path($path,$id,$app,$user);
		$path = egw_vfs::app_entry_lock_path($app,$id);

		return egw_vfs::checkLock($path);
	}

	/**
	 * ACL method handler
	 *
	 * @param  array  general parameter passing array
	 * @return string HTTP status
	 */
	function ACL(&$options)
	{
		self::_parse_path($options['path'],$id,$app,$user);

		if ($this->debug) error_log(__METHOD__.'('.array2string($options).") path=$path");

		$options['errors'] = array();
		switch ($app)
		{
			case 'calendar':
			case 'addressbook':
			case 'infolog':
				$status = '200 OK'; // grant all
				break;
			default:
				$options['errors'][] = 'no-inherited-ace-conflict';
				$status = '403 Forbidden';
		}

		return $status;
	}

	/**
	 * Parse a path into it's id, app and user parts
	 *
	 * @param string $path
	 * @param int &$id
	 * @param string &$app addressbook, calendar, infolog (=infolog)
	 * @param int &$user
	 * @param string &$user_prefix=null
	 * @return boolean true on success, false on error
	 */
	function _parse_path($path,&$id,&$app,&$user,&$user_prefix=null)
	{
		if ($this->debug)
		{
			error_log(__METHOD__." called with ('$path') id=$id, app='$app', user=$user");
		}
		if ($path[0] == '/')
		{
            $path = substr($path, 1);
		}
		$parts = explode('/', $this->_unslashify($path));

		if (($account_id = $this->accounts->name2id($parts[0], 'account_lid')) ||
			($account_id = $this->accounts->name2id($parts[0]=urldecode($parts[0]))))
		{
			// /$user/$app/...
			$user = array_shift($parts);
		}

		$app = array_shift($parts);

		if ($user)
		{
			$user_prefix = '/'.$user;
			$user = $account_id;
		}
		else
		{
			$user_prefix = '';
			$user = $GLOBALS['egw_info']['user']['account_id'];
		}

		if (($id = array_pop($parts)))
		{
			list($id) = explode('.',$id);		// remove evtl. .ics extension
		}

		$ok = $id && $user && in_array($app,array('addressbook','calendar','infolog','principals'));
		if ($this->debug)
		{
			error_log(__METHOD__."('$path') returning " . ($ok ? 'true' : 'false') . ": id='$id', app='$app', user='$user', user_prefix='$user_prefix'");
		}
		return $ok;
	}
	/**
	 * Add the privileges of the current user
	 *
	 * @param array $props=array() regular props by the groupdav handler
	 * @return array
	 */
	static function current_user_privilege_set(array $props=array())
	{
		$props[] = HTTP_WebDAV_Server::mkprop('current-user-privilege-set',
			array(HTTP_WebDAV_Server::mkprop('privilege',
				array(//HTTP_WebDAV_Server::mkprop('all',''),
					HTTP_WebDAV_Server::mkprop('read',''),
					HTTP_WebDAV_Server::mkprop('read-free-busy',''),
					//HTTP_WebDAV_Server::mkprop('read-current-user-privilege-set',''),
					HTTP_WebDAV_Server::mkprop('bind',''),
					HTTP_WebDAV_Server::mkprop('unbind',''),
					HTTP_WebDAV_Server::mkprop('schedule-post',''),
					HTTP_WebDAV_Server::mkprop('schedule-post-vevent',''),
					HTTP_WebDAV_Server::mkprop('schedule-respond',''),
					HTTP_WebDAV_Server::mkprop('schedule-respond-vevent',''),
					HTTP_WebDAV_Server::mkprop('schedule-deliver',''),
					HTTP_WebDAV_Server::mkprop('schedule-deliver-vevent',''),
					HTTP_WebDAV_Server::mkprop('write',''),
					HTTP_WebDAV_Server::mkprop('write-properties',''),
					HTTP_WebDAV_Server::mkprop('write-content',''),
				))));
		return $props;
	}
}
