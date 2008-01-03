<?php
	/**************************************************************************\
	* eGroupWare - Setup                                                       *
	* http://www.eGroupWare.org                                                *
	* Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de *
	* --------------------------------------------                             *
	* This program is free software; you can redistribute it and/or modify it  *
	* under the terms of the GNU General Public License as published by the    *
	* Free Software Foundation; either version 2 of the License, or (at your   *
	* option) any later version.                                               *
	\**************************************************************************/
	
	/* $Id: class.db_tools.inc.php,v 1.32 2005/11/10 12:27:04 ralfbecker Exp $ */


	$phpgw_baseline = array(
		'egw_psp_admin_links' => array(
			'fd' => array(
				'id' => array('type' => 'auto'),
				'name' => array('type' => 'varchar','precision' => '100'),
				'description' => array('type' => 'text'),
				'url' => array('type' => 'varchar','precision' => '250')
			),
			'pk' => array('id'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		),
		'egw_psp_admin_frontpage' => array(
			'fd' => array(
				'id' => array('type' => 'auto'),
				'title' => array('type' => 'varchar','precision' => '255'),
				'pagecontent' => array('type' => 'longtext')
			),
			'pk' => array('id'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		),
		'egw_psp_admin_homepage' => array(
			'fd' => array(
				'id' => array('type' => 'auto'),
				'title' => array('type' => 'varchar','precision' => '254'),
				'content' => array('type' => 'longtext')
			),
			'pk' => array('id'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		),
		'egw_psp_admin_contacts' => array(
			'fd' => array(
				'id' => array('type' => 'auto'),
				'name' => array('type' => 'varchar','precision' => '100'),
				'email' => array('type' => 'varchar','precision' => '100')
			),
			'pk' => array('id'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		)
	);
