<?php
/* Copyright (C) 2006-2010 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2010 Regis Houssin        <regis@dolibarr.fr>
 * Copyright (C) 2010 	   Juanjo Menent        <jmenent@2byte.es>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 */

/**
 *	\file       htdocs/core/class/commonobject.class.php
 *	\ingroup    core
 *	\brief      Fichier de la classe mere des classes metiers (facture, contrat, propal, commande, etc...)
 *	\version    $Id$
 */


/**
 *	\class 		CommonObject
 *	\brief 		Classe mere pour heritage des classes metiers
 */

class CommonObject
{
	// Instantiate hook classe of thirdparty module
	var $hooks=array();

	/**
	 *      \brief      Check if ref is used.
	 * 		\return		int			<0 if KO, 0 if not found, >0 if found
	 */
	function verifyNumRef()
	{
		global $conf;

		$sql = "SELECT rowid";
		$sql.= " FROM ".MAIN_DB_PREFIX.$this->element;
		$sql.= " WHERE ref = '".$this->ref."'";
		$sql.= " AND entity = ".$conf->entity;

		dol_syslog("CommonObject::verifyNumRef sql=".$sql, LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$num = $this->db->num_rows($resql);
			return $num;
		}
		else
		{
			$this->error=$this->db->lasterror();
			dol_syslog("CommonObject::verifyNumRef ".$this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 *      \brief      Add a link between element $this->element and a contact
	 *      \param      fk_socpeople        Id of contact to link
	 *   	\param 		type_contact 		Type of contact (code or id)
	 *      \param      source              external=Contact extern (llx_socpeople), internal=Contact intern (llx_user)
	 *      \return     int                 <0 if KO, >0 if OK
	 */
	function add_contact($fk_socpeople, $type_contact, $source='external',$notrigger=0)
	{
		global $user,$conf,$langs;

		dol_syslog("CommonObject::add_contact $fk_socpeople, $type_contact, $source");

		// Check parameters
		if ($fk_socpeople <= 0)
		{
			$this->error=$langs->trans("ErrorWrongValueForParameter","1");
			dol_syslog("CommonObject::add_contact ".$this->error,LOG_ERR);
			return -1;
		}
		if (! $type_contact)
		{
			$this->error=$langs->trans("ErrorWrongValueForParameter","2");
			dol_syslog("CommonObject::add_contact ".$this->error,LOG_ERR);
			return -2;
		}

		$id_type_contact=0;
		if (is_numeric($type_contact))
		{
			$id_type_contact=$type_contact;
		}
		else
		{
			// On recherche id type_contact
			$sql = "SELECT tc.rowid";
			$sql.= " FROM ".MAIN_DB_PREFIX."c_type_contact as tc";
			$sql.= " WHERE element='".$this->element."'";
			$sql.= " AND source='".$source."'";
			$sql.= " AND code='".$type_contact."' AND active=1";
			$resql=$this->db->query($sql);
			if ($resql)
			{
				$obj = $this->db->fetch_object($resql);
				$id_type_contact=$obj->rowid;
			}
		}

		$datecreate = dol_now();

		// Insertion dans la base
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."element_contact";
		$sql.= " (element_id, fk_socpeople, datecreate, statut, fk_c_type_contact) ";
		$sql.= " VALUES (".$this->id.", ".$fk_socpeople." , " ;
		$sql.= $this->db->idate($datecreate);
		$sql.= ", 4, '". $id_type_contact . "' ";
		$sql.= ")";
		dol_syslog("CommonObject::add_contact sql=".$sql);

		$resql=$this->db->query($sql);
		if ($resql)
		{
			if (! $notrigger)
			{
				// Call triggers
				include_once(DOL_DOCUMENT_ROOT . "/core/class/interfaces.class.php");
				$interface=new Interfaces($this->db);
				$result=$interface->run_triggers(strtoupper($this->element).'_ADD_CONTACT',$this,$user,$langs,$conf);
				if ($result < 0) { $error++; $this->errors=$interface->errors; }
				// End call triggers
			}

			return 1;
		}
		else
		{
			if ($this->db->errno() == 'DB_ERROR_RECORD_ALREADY_EXISTS')
			{
				$this->error=$this->db->errno();
				return -2;
			}
			else
			{
				$this->error=$this->db->error();
				dol_syslog($this->error,LOG_ERR);
				return -1;
			}
		}
	}

	/**
	 *      \brief      Update a link to contact line
	 *      \param      rowid               Id of line contact-element
	 * 		\param		statut	            New status of link
	 *      \param      type_contact_id     Id of contact type
	 *      \return     int                 <0 if KO, >= 0 if OK
	 */
	function update_contact($rowid, $statut, $type_contact_id)
	{
		// Insertion dans la base
		$sql = "UPDATE ".MAIN_DB_PREFIX."element_contact set";
		$sql.= " statut = ".$statut.",";
		$sql.= " fk_c_type_contact = '".$type_contact_id ."'";
		$sql.= " where rowid = ".$rowid;
		// Retour
		if (  $this->db->query($sql) )
		{
			return 0;
		}
		else
		{
			dol_print_error($this->db);
			return -1;
		}
	}

	/**
	 *    \brief      Delete a link to contact line
	 *    \param      rowid			Id of link line to delete
	 *    \return     statur        >0 si ok, <0 si ko
	 */
	function delete_contact($rowid,$notrigger=0)
	{
		global $user,$langs,$conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."element_contact";
		$sql.= " WHERE rowid =".$rowid;

		dol_syslog("CommonObject::delete_contact sql=".$sql);
		if ($this->db->query($sql))
		{
			if (! $notrigger)
			{
				// Call triggers
				include_once(DOL_DOCUMENT_ROOT . "/core/class/interfaces.class.php");
				$interface=new Interfaces($this->db);
				$result=$interface->run_triggers(strtoupper($this->element).'_DELETE_CONTACT',$this,$user,$langs,$conf);
				if ($result < 0) { $error++; $this->errors=$interface->errors; }
				// End call triggers
			}

			return 1;
		}
		else
		{
			$this->error=$this->db->lasterror();
			dol_syslog("CommonObject::delete_contact error=".$this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 *    \brief      Delete all links between an object $this and all its contacts
	 *    \return     int	>0 if OK, <0 if KO
	 */
	function delete_linked_contact()
	{
		$temp = array();
		$typeContact = $this->liste_type_contact('');

		foreach($typeContact as $key => $value)
		{
			array_push($temp,$key);
		}
		$listId = implode(",", $temp);

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."element_contact";
		$sql.= " WHERE element_id =".$this->id;
		$sql.= " AND fk_c_type_contact IN (".$listId.")";

		dol_syslog("CommonObject::delete_linked_contact sql=".$sql);
		if ($this->db->query($sql))
		{
			return 1;
		}
		else
		{
			$this->error=$this->db->lasterror();
			dol_syslog("CommonObject::delete_linked_contact error=".$this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 *    \brief      Get array of all contacts for an object
	 *    \param      statut        Status of lines to get (-1=all)
	 *    \param      source        Source of contact: external or thirdparty (llx_socpeople) or internal (llx_user)
	 *    \return     array			Array of id of contacts
	 */
	function liste_contact($statut=-1,$source='external')
	{
		global $langs;

		$tab=array();

		$sql = "SELECT ec.rowid, ec.statut, ec.fk_socpeople as id";
		if ($source == 'internal') $sql.=", '-1' as socid";
		if ($source == 'external' || $source == 'thirdparty') $sql.=", t.fk_soc as socid";
		$sql.= ", t.name as nom, t.firstname";
		$sql.= ", tc.source, tc.element, tc.code, tc.libelle";
		$sql.= " FROM ".MAIN_DB_PREFIX."c_type_contact tc";
		$sql.= ", ".MAIN_DB_PREFIX."element_contact ec";
		if ($source == 'internal') $sql.=" LEFT JOIN ".MAIN_DB_PREFIX."user t on ec.fk_socpeople = t.rowid";
		if ($source == 'external'|| $source == 'thirdparty') $sql.=" LEFT JOIN ".MAIN_DB_PREFIX."socpeople t on ec.fk_socpeople = t.rowid";
		$sql.= " WHERE ec.element_id =".$this->id;
		$sql.= " AND ec.fk_c_type_contact=tc.rowid";
		$sql.= " AND tc.element='".$this->element."'";
		if ($source == 'internal') $sql.= " AND tc.source = 'internal'";
		if ($source == 'external' || $source == 'thirdparty') $sql.= " AND tc.source = 'external'";
		$sql.= " AND tc.active=1";
		if ($statut >= 0) $sql.= " AND ec.statut = '".$statut."'";
		$sql.=" ORDER BY t.name ASC";

		dol_syslog("CommonObject::liste_contact sql=".$sql);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$num=$this->db->num_rows($resql);
			$i=0;
			while ($i < $num)
			{
				$obj = $this->db->fetch_object($resql);

				$transkey="TypeContact_".$obj->element."_".$obj->source."_".$obj->code;
				$libelle_type=($langs->trans($transkey)!=$transkey ? $langs->trans($transkey) : $obj->libelle);
				$tab[$i]=array('source'=>$obj->source,'socid'=>$obj->socid,'id'=>$obj->id,'nom'=>$obj->nom, 'firstname'=>$obj->firstname,
                               'rowid'=>$obj->rowid,'code'=>$obj->code,'libelle'=>$libelle_type,'status'=>$obj->statut);
				$i++;
			}
			return $tab;
		}
		else
		{
			$this->error=$this->db->error();
			dol_print_error($this->db);
			return -1;
		}
	}

	/**
	 *    \brief      Le detail d'un contact
	 *    \param      rowid      L'identifiant du contact
	 *    \return     object     L'objet construit par DoliDb.fetch_object
	 */
	function detail_contact($rowid)
	{
		$sql = "SELECT ec.datecreate, ec.statut, ec.fk_socpeople, ec.fk_c_type_contact,";
		$sql.= " tc.code, tc.libelle,";
		$sql.= " s.fk_soc";
		$sql.= " FROM (".MAIN_DB_PREFIX."element_contact as ec, ".MAIN_DB_PREFIX."c_type_contact as tc)";
		$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."socpeople as s ON ec.fk_socpeople=s.rowid";	// Si contact de type external, alors il est li� � une societe
		$sql.= " WHERE ec.rowid =".$rowid;
		$sql.= " AND ec.fk_c_type_contact=tc.rowid";
		$sql.= " AND tc.element = '".$this->element."'";

		$resql=$this->db->query($sql);
		if ($resql)
		{
			$obj = $this->db->fetch_object($resql);
			return $obj;
		}
		else
		{
			$this->error=$this->db->error();
			dol_print_error($this->db);
			return null;
		}
	}

	/**
	 *      \brief      La liste des valeurs possibles de type de contacts
	 *      \param      source      internal, external or all if not defined
	 *      \param		order		Sort order by : code or rowid
	 *      \return     array       La liste des natures
	 */
	function liste_type_contact($source='internal', $order='code')
	{
		global $langs;

		$tab = array();

		$sql = "SELECT DISTINCT tc.rowid, tc.code, tc.libelle";
		$sql.= " FROM ".MAIN_DB_PREFIX."c_type_contact as tc";
		$sql.= " WHERE tc.element='".$this->element."'";
		if (! empty($source)) $sql.= " AND tc.source='".$source."'";
		$sql.= " ORDER by tc.".$order;

		$resql=$this->db->query($sql);
		if ($resql)
		{
			$num=$this->db->num_rows($resql);
			$i=0;
			while ($i < $num)
			{
				$obj = $this->db->fetch_object($resql);

				$transkey="TypeContact_".$this->element."_".$source."_".$obj->code;
				$libelle_type=($langs->trans($transkey)!=$transkey ? $langs->trans($transkey) : $obj->libelle);
				$tab[$obj->rowid]=$libelle_type;
				$i++;
			}
			return $tab;
		}
		else
		{
			$this->error=$this->db->error();
			//            dol_print_error($this->db);
			return null;
		}
	}

	/**
	 *      \brief      Retourne id des contacts d'une source et d'un type actif donne
	 *                  Exemple: contact client de facturation ('external', 'BILLING')
	 *                  Exemple: contact client de livraison ('external', 'SHIPPING')
	 *                  Exemple: contact interne suivi paiement ('internal', 'SALESREPFOLL')
	 *		\param		source		'external' or 'internal'
	 *		\param		code		'BILLING', 'SHIPPING', 'SALESREPFOLL', ...
	 **		\param		status		limited to a certain status
	 *      \return     array       Liste des id contacts
	 */
	function getIdContact($source,$code,$status=0)
	{
		$result=array();
		$i=0;

		$sql = "SELECT ec.fk_socpeople";
		$sql.= " FROM ".MAIN_DB_PREFIX."element_contact as ec,";
		$sql.= " ".MAIN_DB_PREFIX."c_type_contact as tc";
		$sql.= " WHERE ec.element_id = ".$this->id;
		$sql.= " AND ec.fk_c_type_contact = tc.rowid";
		$sql.= " AND tc.element = '".$this->element."'";
		$sql.= " AND tc.source = '".$source."'";
		$sql.= " AND tc.code = '".$code."'";
		$sql.= " AND tc.active = 1";
		if ($status) $sql.= " AND ec.statut = ".$status;
		// FIXME Add filter on entity

		dol_syslog("CommonObject::getIdContact sql=".$sql);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			while ($obj = $this->db->fetch_object($resql))
			{
				$result[$i]=$obj->fk_socpeople;
				$i++;
			}
		}
		else
		{
			$this->error=$this->db->error();
			dol_syslog("CommonObject::getIdContact ".$this->error, LOG_ERR);
			return null;
		}

		return $result;
	}

	/**
	 *		\brief      Charge le contact d'id $id dans this->contact
	 *		\param      contactid          Id du contact
	 *		\return		int			<0 if KO, >0 if OK
	 */
	function fetch_contact($contactid)
	{
		require_once(DOL_DOCUMENT_ROOT."/contact/class/contact.class.php");
		$contact = new Contact($this->db);
		$result=$contact->fetch($contactid);
		$this->contact = $contact;
		return $result;
	}

	/**
	 *    	\brief      Load the third party of object from id $this->socid into this->client
	 *		\return		int			<0 if KO, >0 if OK
	 */
	function fetch_thirdparty()
	{
		global $conf;

		if (empty($this->socid)) return 0;

		$client = new Societe($this->db);
		$result=$client->fetch($this->socid);
		$this->client = $client;

		// Use first price level if level not defined for third party
		if ($conf->global->PRODUIT_MULTIPRICES && empty($this->client->price_level)) $this->client->price_level=1;

		return $result;
	}

	/**
	 *		\brief      Charge le projet d'id $this->fk_project dans this->projet
	 *		\return		int			<0 if KO, >=0 if OK
	 */
	function fetch_projet()
	{
		if (empty($this->fk_project)) return 0;

		$project = new Project($this->db);
		$result = $project->fetch($this->fk_project);
		$this->projet = $project;
		return $result;
	}

	/**
	 *		\brief      Charge le user d'id userid dans this->user
	 *		\param      userid 		Id du contact
	 *		\return		int			<0 if KO, >0 if OK
	 */
	function fetch_user($userid)
	{
		$user = new User($this->db);
		$result=$user->fetch($userid);
		$this->user = $user;
		return $result;
	}

	/**
	 *		\brief      Charge l'adresse de livraison d'id $this->fk_delivery_address dans this->deliveryaddress
	 *		\param      userid 		Id du contact
	 *		\return		int			<0 if KO, >0 if OK
	 */
	function fetch_adresse_livraison($deliveryaddressid)
	{
		$address = new Societe($this->db);
		$result=$address->fetch_adresse_livraison($deliveryaddressid);
		$this->deliveryaddress = $address;
		$this->adresse = $address; // TODO obsolete
		$this->address = $address;
		return $result;
	}

    /**
	 *		Read linked origin object
	 */
	function fetch_origin()
	{
		// TODO uniformise code
		if ($this->origin == 'shipping') $this->origin = 'expedition';
		if ($this->origin == 'delivery') $this->origin = 'livraison';
		
		$object = $this->origin;

		$classname = ucfirst($object);
		$this->$object = new $classname($this->db);
		$this->$object->fetch($this->origin_id);
	}


	/**
	 *      \brief      Load properties id_previous and id_next
	 *      \param      filter		Optional filter
	 *	 	\param      fieldid   	Name of field to use for the select MAX and MIN
	 *      \return     int         <0 if KO, >0 if OK
	 */
	function load_previous_next_ref($filter='',$fieldid)
	{
		global $conf, $user;

		if (! $this->table_element)
		{
			dol_print_error("CommonObject::load_previous_next_ref was called on objet with property table_element not defined", LOG_ERR);
			return -1;
		}

 		// this->ismultientitymanaged contains
		// 0=No test on entity, 1=Test with field entity, 2=Test with link by societe
		$alias = 's';
		if ($this->element == 'societe') $alias = 'te';

		$sql = "SELECT MAX(te.".$fieldid.")";
		$sql.= " FROM ".MAIN_DB_PREFIX.$this->table_element." as te";
		if ($this->ismultientitymanaged == 2 || ($this->element != 'societe' && !$this->isnolinkedbythird && !$user->rights->societe->client->voir)) $sql.= ", ".MAIN_DB_PREFIX."societe as s";	// If we need to link to societe to limit select to entity
		if (!$this->isnolinkedbythird && !$user->rights->societe->client->voir) $sql.= " LEFT JOIN ".MAIN_DB_PREFIX."societe_commerciaux as sc ON ".$alias.".rowid = sc.fk_soc";
		$sql.= " WHERE te.".$fieldid." < '".addslashes($this->ref)."'";
		if (!$this->isnolinkedbythird && !$user->rights->societe->client->voir) $sql.= " AND sc.fk_user = " .$user->id;
		if (! empty($filter)) $sql.=" AND ".$filter;
		if ($this->ismultientitymanaged == 2 || ($this->element != 'societe' && !$this->isnolinkedbythird && !$user->rights->societe->client->voir)) $sql.= ' AND te.fk_soc = s.rowid';			// If we need to link to societe to limit select to entity
		if ($this->ismultientitymanaged == 1) $sql.= ' AND te.entity IN (0,'.$conf->entity.')';

		//print $sql."<br>";
		$result = $this->db->query($sql) ;
		if (! $result)
		{
			$this->error=$this->db->error();
			return -1;
		}
		$row = $this->db->fetch_row($result);
		$this->ref_previous = $row[0];


		$sql = "SELECT MIN(te.".$fieldid.")";
		$sql.= " FROM ".MAIN_DB_PREFIX.$this->table_element." as te";
		if ($this->ismultientitymanaged == 2 || ($this->element != 'societe' && !$this->isnolinkedbythird && !$user->rights->societe->client->voir)) $sql.= ", ".MAIN_DB_PREFIX."societe as s";	// If we need to link to societe to limit select to entity
		if (!$this->isnolinkedbythird && !$user->rights->societe->client->voir) $sql.= " LEFT JOIN ".MAIN_DB_PREFIX."societe_commerciaux as sc ON ".$alias.".rowid = sc.fk_soc";
		$sql.= " WHERE te.".$fieldid." > '".addslashes($this->ref)."'";
		if (!$this->isnolinkedbythird && !$user->rights->societe->client->voir) $sql.= " AND sc.fk_user = " .$user->id;
		if (isset($filter)) $sql.=" AND ".$filter;
		if ($this->ismultientitymanaged == 2 || ($this->element != 'societe' && !$this->isnolinkedbythird && !$user->rights->societe->client->voir)) $sql.= ' AND te.fk_soc = s.rowid';			// If we need to link to societe to limit select to entity
		if ($this->ismultientitymanaged == 1) $sql.= ' AND te.entity IN (0,'.$conf->entity.')';
		// Rem: Bug in some mysql version: SELECT MIN(rowid) FROM llx_socpeople WHERE rowid > 1 when one row in database with rowid=1, returns 1 instead of null

		//print $sql."<br>";
		$result = $this->db->query($sql) ;
		if (! $result)
		{
			$this->error=$this->db->error();
			return -2;
		}
		$row = $this->db->fetch_row($result);
		$this->ref_next = $row[0];

		return 1;
	}


	/**
	 *      \brief      Return list of id of contacts of project
	 *      \param      source      Source of contact: external (llx_socpeople) or internal (llx_user) or thirdparty (llx_societe)
	 *      \return     array		Array of id of contacts (if source=external or internal)
	 * 								Array of id of third parties with at least one contact on project (if source=thirdparty)
	 */
	function getListContactId($source='external')
	{
		$contactAlreadySelected = array();
		$tab = $this->liste_contact(-1,$source);
		$num=sizeof($tab);
		$i = 0;
		while ($i < $num)
		{
			if ($source == 'thirdparty') $contactAlreadySelected[$i] = $tab[$i]['socid'];
			else  $contactAlreadySelected[$i] = $tab[$i]['id'];
			$i++;
		}
		return $contactAlreadySelected;
	}


	/**
	 *	\brief     	Link element with a project
	 *	\param     	projid		Project id to link element to
	 *	\return		int			<0 if KO, >0 if OK
	 */
	function setProject($projectid)
	{
		if (! $this->table_element)
		{
			dol_syslog("CommonObject::setProject was called on objet with property table_element not defined",LOG_ERR);
			return -1;
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		if ($projectid) $sql.= ' SET fk_projet = '.$projectid;
		else $sql.= ' SET fk_projet = NULL';
		$sql.= ' WHERE rowid = '.$this->id;

		dol_syslog("CommonObject::setProject sql=".$sql);
		if ($this->db->query($sql))
		{
			$this->fk_project = $projectid;
			return 1;
		}
		else
		{
			dol_print_error($this->db);
			return -1;
		}
	}


	/**
	 *		\brief		Set last model used by doc generator
	 *		\param		user		User object that make change
	 *		\param		modelpdf	Modele name
	 *		\return		int			<0 if KO, >0 if OK
	 */
	function setDocModel($user, $modelpdf)
	{
		if (! $this->table_element)
		{
			dol_syslog("CommonObject::setDocModel was called on objet with property table_element not defined",LOG_ERR);
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
		$sql.= " SET model_pdf = '".$modelpdf."'";
		$sql.= " WHERE rowid = ".$this->id;
		// if ($this->element == 'facture') $sql.= " AND fk_statut < 2";
		// if ($this->element == 'propal')  $sql.= " AND fk_statut = 0";

		dol_syslog("CommonObject::setDocModel sql=".$sql);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$this->modelpdf=$modelpdf;
			return 1;
		}
		else
		{
			dol_print_error($this->db);
			return 0;
		}
	}


	/**
	 *      Stocke un numero de rang pour toutes les lignes de detail d'un element qui n'en ont pas.
	 * 		@param		renum		true to renum all already ordered lines, false to renum only not already ordered lines.
	 */
	function line_order($renum=false)
	{
		if (! $this->table_element_line)
		{
			dol_syslog("CommonObject::line_order was called on objet with property table_element_line not defined",LOG_ERR);
			return -1;
		}
		if (! $this->fk_element)
		{
			dol_syslog("CommonObject::line_order was called on objet with property fk_element not defined",LOG_ERR);
			return -1;
		}

		$sql = 'SELECT count(rowid) FROM '.MAIN_DB_PREFIX.$this->table_element_line;
		$sql.= ' WHERE '.$this->fk_element.'='.$this->id;
		if (! $renum) $sql.= ' AND rang = 0';
		if ($renum) $sql.= ' AND rang <> 0';
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$row = $this->db->fetch_row($resql);
			$nl = $row[0];
		}
		if ($nl > 0)
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element_line;
			$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;
			$sql.= ' ORDER BY rang ASC, rowid ASC';
			$resql = $this->db->query($sql);
			if ($resql)
			{
				$num = $this->db->num_rows($resql);
				$i = 0;
				while ($i < $num)
				{
					$row = $this->db->fetch_row($resql);
					$li[$i] = $row[0];
					$i++;
				}
			}
			for ($i = 0 ; $i < sizeof($li) ; $i++)
			{
				$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang = '.($i+1);
				$sql.= ' WHERE rowid = '.$li[$i];
				if (!$this->db->query($sql) )
				{
					dol_syslog($this->db->error());
				}
			}
		}
	}

	/**
	 * Update a line to have a lower rank
	 * @param $rowid
	 */
	function line_up($rowid)
	{
		$this->line_order();

		// Get rang of line
		$rang = $this->getRangOfLine($rowid);

		// Update position of line
		$this->updateLineUp($rowid, $rang);
	}

	/**
     * Update a line to have a higher rank
	 * @param $rowid
	 */
	function line_down($rowid)
	{
		$this->line_order();

		// Get rang of line
		$rang = $this->getRangOfLine($rowid);

		// Get max value for rang
		$max = $this->line_max();

		// Update position of line
		$this->updateLineDown($rowid, $rang, $max);
	}

	/**
	 * 	   Update position of line (rang)
	 */
	function updateRangOfLine($rowid,$rang)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang  = '.$rang;
		$sql.= ' WHERE rowid = '.$rowid;
		if (! $this->db->query($sql) )
		{
			dol_print_error($this->db);
		}
	}

	/**
	 * 	   Update position of line up (rang)
	 */
	function updateLineUp($rowid,$rang)
	{
		if ($rang > 1 )
		{
			$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang = '.$rang ;
			$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;
			$sql.= ' AND rang = '.($rang - 1);
			if ($this->db->query($sql) )
			{
				$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang  = '.($rang - 1);
				$sql.= ' WHERE rowid = '.$rowid;
				if (! $this->db->query($sql) )
				{
					dol_print_error($this->db);
				}
			}
			else
			{
				dol_print_error($this->db);
			}
		}
	}

	/**
	 * 	   Update position of line down (rang)
	 */
	function updateLineDown($rowid,$rang,$max)
	{
		if ($rang < $max)
		{
			$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang = '.$rang;
			$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;
			$sql.= ' AND rang = '.($rang+1);
			if ($this->db->query($sql) )
			{
				$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element_line.' SET rang = '.($rang+1);
				$sql.= ' WHERE rowid = '.$rowid;
				if (! $this->db->query($sql) )
				{
					dol_print_error($this->db);
				}
			}
			else
			{
				dol_print_error($this->db);
			}
		}
	}

	/**
	 * 	   Get position of line (rang)
	 *     @return     int     Value of rang in table of lines
	 */
	function getRangOfLine($rowid)
	{
		$sql = 'SELECT rang FROM '.MAIN_DB_PREFIX.$this->table_element_line;
		$sql.= ' WHERE rowid ='.$rowid;
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$row = $this->db->fetch_row($resql);
			return $row[0];
		}
	}

	/**
	 * 	   Get rowid of the line relative to its position
	 *     @return     int     Rowid of the line
	 */
	function getIdOfLine($rang)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element_line;
		$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;
		$sql.= ' AND rang = '.$rang;
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$row = $this->db->fetch_row($resql);
			return $row[0];
		}
	}

	/**
	 * 	   Get max value used for position of line (rang)
	 *     @result     int     Max value of rang in table of lines
	 */
	function line_max()
	{
		$sql = 'SELECT max(rang) FROM '.MAIN_DB_PREFIX.$this->table_element_line;
		$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$row = $this->db->fetch_row($resql);
			return $row[0];
		}
	}

	/**
	 *    \brief      Update private note of element
	 *    \param      note			New value for note
	 *    \return     int         	<0 if KO, >0 if OK
	 */
	function update_note($note)
	{
		if (! $this->table_element)
		{
			dol_syslog("CommonObject::update_note was called on objet with property table_element not defined", LOG_ERR);
			return -1;
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		// TODO uniformize fields note_private
		if ($this->table_element == 'fichinter' || $this->table_element == 'projet' || $this->table_element == 'projet_task')
		{
			$sql.= " SET note_private = '".addslashes($note)."'";
		}
		else
		{
			$sql.= " SET note = '".addslashes($note)."'";
		}
		$sql.= " WHERE rowid =". $this->id;

		dol_syslog("CommonObject::update_note sql=".$sql, LOG_DEBUG);
		if ($this->db->query($sql))
		{
			$this->note = $note;
			return 1;
		}
		else
		{
			$this->error=$this->db->error();
			dol_syslog("CommonObject::update_note error=".$this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 *    \brief      Update public note of element
	 *    \param      note_public	New value for note
	 *    \return     int         	<0 if KO, >0 if OK
	 */
	function update_note_public($note_public)
	{
		if (! $this->table_element)
		{
			dol_syslog("CommonObject::update_note_public was called on objet with property table_element not defined",LOG_ERR);
			return -1;
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql.= " SET note_public = '".addslashes($note_public)."'";
		$sql.= " WHERE rowid =". $this->id;

		dol_syslog("CommonObject::update_note_public sql=".$sql);
		if ($this->db->query($sql))
		{
			$this->note_public = $note_public;
			return 1;
		}
		else
		{
			$this->error=$this->db->error();
			return -1;
		}
	}

	/**
	 *	 \brief     	Update total_ht, total_ttc and total_vat for an object (sum of lines)
	 *	 \return		int			<0 si ko, >0 si ok
	 */
	function update_price()
	{
		include_once(DOL_DOCUMENT_ROOT.'/lib/price.lib.php');

		$err=0;

		// List lines to sum
		$fieldtva='total_tva';
		$fieldlocaltax1='total_localtax1';
		$fieldlocaltax2='total_localtax2';

		if ($this->element == 'facture_fourn')
		{
			$fieldtva='tva';
		}

		$sql = 'SELECT qty, total_ht, '.$fieldtva.' as total_tva, '.$fieldlocaltax1.' as total_localtax1, '.$fieldlocaltax2.' as total_localtax2, total_ttc';
		$sql.= ' FROM '.MAIN_DB_PREFIX.$this->table_element_line;
		$sql.= ' WHERE '.$this->fk_element.' = '.$this->id;

		dol_syslog("CommonObject::update_price sql=".$sql);
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$this->total_ht  = 0;
			$this->total_tva = 0;
			$this->total_localtax1 = 0;
			$this->total_localtax2 = 0;
			$this->total_ttc = 0;

			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < $num)
			{
				$obj = $this->db->fetch_object($resql);

				$this->total_ht       += $obj->total_ht;
				$this->total_tva      += $obj->total_tva;
				$this->total_localtax1 += $obj->total_localtax1;
				$this->total_localtax2 += $obj->total_localtax2;
				$this->total_ttc      += $obj->total_ttc;

				$i++;
			}

			$this->db->free($resql);

			// Now update field total_ht, total_ttc and tva
			$fieldht='total_ht';

			$fieldtva='tva';

			$fieldlocaltax1='localtax1';
			$fieldlocaltax2='localtax2';

			$fieldttc='total_ttc';

			if ($this->element == 'facture' || $this->element == 'facturerec')
			{
				$fieldht='total';
			}

			if ($this->element == 'facture_fourn')
			{
				$fieldtva='total_tva';
			}

			if ($this->element == 'propal')
			{
				$fieldttc='total';
			}

			$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element.' SET';
			$sql .= " ".$fieldht."='".price2num($this->total_ht)."',";
			$sql .= " ".$fieldtva."='".price2num($this->total_tva)."',";
			$sql .= " ".$fieldlocaltax1."='".price2num($this->total_localtax1)."',";
			$sql .= " ".$fieldlocaltax2."='".price2num($this->total_localtax2)."',";
			$sql .= " ".$fieldttc."='".price2num($this->total_ttc)."'";
			$sql .= ' WHERE rowid = '.$this->id;

			//print "xx".$sql;
			dol_syslog("CommonObject::update_price sql=".$sql);
			$resql=$this->db->query($sql);
			if ($resql)
			{
				return 1;
			}
			else
			{
				$this->error=$this->db->error();
				dol_syslog("CommonObject::update_price error=".$this->error,LOG_ERR);
				return -1;
			}
		}
		else
		{
			$this->error=$this->db->error();
			dol_syslog("CommonObject::update_price error=".$this->error,LOG_ERR);
			return -1;
		}
	}

	/**
	 * 	Add objects linked in llx_element_element.
	 */
	function add_object_linked()
	{
		$this->db->begin();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."element_element (";
		$sql.= "fk_source";
		$sql.= ", sourcetype";
		$sql.= ", fk_target";
		$sql.= ", targettype";
		$sql.= ") VALUES (";
		$sql.= $this->origin_id;
		$sql.= ", '".$this->origin."'";
		$sql.= ", ".$this->id;
		$sql.= ", '".$this->element."'";
		$sql.= ")";

		if ($this->db->query($sql))
	  	{
	  		$this->db->commit();
	  		return 1;
	  	}
	  	else
	  	{
	  		$this->error=$this->db->lasterror()." - sql=$sql";
	  		$this->db->rollback();
	  		return 0;
	  	}
	}

	/**
	 * 	Load array of objects linked to current object. Links are loaded into this->linked_object array.
	 */
	function load_object_linked($sourceid='',$sourcetype='',$targetid='',$targettype='')
	{
		$this->linked_object=array();

		$sourceid = (!empty($sourceid)?$sourceid:$this->id);
		$targetid = (!empty($targetid)?$targetid:$this->id);
		$sourcetype = (!empty($sourcetype)?$sourcetype:$this->origin);
		$targettype = (!empty($targettype)?$targettype:$this->element);

		// Links beetween objects are stored in this table
		$sql = 'SELECT fk_source, sourcetype, fk_target, targettype';
		$sql.= ' FROM '.MAIN_DB_PREFIX.'element_element';
		$sql.= " WHERE (fk_source = '".$sourceid."' AND sourcetype = '".$sourcetype."')";
		$sql.= " OR    (fk_target = '".$targetid."' AND targettype = '".$targettype."')";

		dol_syslog("CommonObject::load_object_linked sql=".$sql);
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < $num)
			{
				$obj = $this->db->fetch_object($resql);
				if ($obj->fk_source == $sourceid)
				{
					$this->linked_object[$obj->targettype][]=$obj->fk_target;
				}
				if ($obj->fk_target == $targetid)
				{
					$this->linked_object[$obj->sourcetype][]=$obj->fk_source;
				}
				$i++;
			}
		}
		else
		{
			dol_print_error($this->db);
		}
	}

	/**
	 *      \brief      Set statut of an object
	 *      \param		statut			Statut to set
	 *      \param		elementid		Id of element to force (use this->id by default)
	 *      \param		elementtype		Type of element to force (use ->this->element by default)
	 *      \return     int				<0 if ko, >0 if ok
	 */
	function setStatut($statut,$elementId='',$elementType='')
	{
		$elementId = (!empty($elementId)?$elementId:$this->id);
		$elementTable = (!empty($elementType)?$elementType:$this->table_element);

		$sql = "UPDATE ".MAIN_DB_PREFIX.$elementTable;
		$sql.= " SET fk_statut = ".$statut;
		$sql.= " WHERE rowid=".$elementId;

		dol_syslog("CommonObject::setStatut sql=".$sql, LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql)
		{
			$this->error=$this->db->lasterror();
			dol_syslog("CommonObject::setStatut ".$this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * 	\brief	Fetch field list
	 */
	function getFieldList()
	{
		global $conf, $langs;

		$this->field_list = array();

		$sql = "SELECT rowid, name, alias, title, align, sort, search, enabled, rang";
		$sql.= " FROM ".MAIN_DB_PREFIX."c_field_list";
		$sql.= " WHERE element = '".$this->fieldListName."'";
		$sql.= " AND entity = ".$conf->entity;
		$sql.= " ORDER BY rang ASC";

		$resql = $this->db->query($sql);
		if ($resql)
		{
			$num = $this->db->num_rows($resql);

			$i = 0;
			while ($i < $num)
			{
				$fieldlist = array();

				$obj = $this->db->fetch_object($resql);

				$fieldlist["id"]		= $obj->rowid;
				$fieldlist["name"]		= $obj->name;
				$fieldlist["alias"]		= $obj->alias;
				$fieldlist["title"]		= $langs->trans($obj->title);
				$fieldlist["align"]		= $obj->align;
				$fieldlist["sort"]		= $obj->sort;
				$fieldlist["search"]	= $obj->search;
				$fieldlist["enabled"]	= verifCond($obj->enabled);
				$fieldlist["order"]		= $obj->rang;

				array_push($this->field_list,$fieldlist);

				$i++;
			}
			$this->db->free($resql);
		}
		else
		{
			print $sql;
		}
	}

    /**
     *  Load type of canvas of an object
     *  @param      id      element id
     */
    function getCanvas($id)
    {
        global $conf;

        $sql = "SELECT rowid, canvas";
        $sql.= " FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql.= " WHERE entity = ".$conf->entity;
        $sql.= " AND rowid = ".$id;

        $resql = $this->db->query($sql);
        if ($resql)
        {
            $obj = $this->db->fetch_object($resql);

            $this->id       = $obj->rowid;
            $this->canvas   = $obj->canvas;

            return 1;
        }
        else
        {
            dol_print_error($this->db);
            return -1;
        }
    }


	/**
	 *	Instantiate hooks of thirdparty module
	 *	@param	$type	Type of hook
	 */
	function callHooks($type='objectcard')
	{
		global $conf;

		foreach($conf->hooks_modules as $module => $hooks)
		{
			if ($conf->$module->enabled && in_array($type,$hooks))
			{
				// Include class and library of thirdparty module
				if (file_exists(DOL_DOCUMENT_ROOT.'/'.$module.'/class/actions_'.$module.'.class.php') &&
					file_exists(DOL_DOCUMENT_ROOT.'/'.$module.'/class/dao_'.$module.'.class.php'))
				{
					// Include actions class (controller)
					require_once(DOL_DOCUMENT_ROOT.'/'.$module.'/class/actions_'.$module.'.class.php');

					// Include dataservice class (model)
					require_once(DOL_DOCUMENT_ROOT.'/'.$module.'/class/dao_'.$module.'.class.php');

					// Instantiate actions class (controller)
					$controlclassname = 'Actions'.ucfirst($module);
					$objModule = new $controlclassname($this->db);
					$this->hooks[$objModule->module_number] = $objModule;

					// Instantiate dataservice class (model)
					$modelclassname = 'Dao'.ucfirst($module);
					$this->hooks[$objModule->module_number]->object = new $modelclassname($this->db);
				}

				if (file_exists(DOL_DOCUMENT_ROOT.'/'.$module.'/lib/'.$module.'.lib.php'))
				{
					require_once(DOL_DOCUMENT_ROOT.'/'.$module.'/lib/'.$module.'.lib.php');
				}
			}
		}
	}



    // All functions here must be moved into a hhtml class file



	/**
	 *	Show add predefined products/services form
	 *  TODO This should be moved into a html.class file instead of a business class.
	 *  But for the moment we don't know if it'st possible as we keep a method available on overloaded objects.
     *  @param          $dateSelector       1=Show also date range input fields
	 */
	function showAddPredefinedProductForm($dateSelector=0)
	{
		global $conf,$langs;
		global $html,$bc,$var;

		include(DOL_DOCUMENT_ROOT.'/core/tpl/predefinedproductline_create.tpl.php');
	}

	/**
	 *	Show add free products/services form
     *  TODO This should be moved into a html.class file instead of a business class. But for
     *  But for the moment we don't know if it'st possible as we keep a method available on overloaded objects.
     *  @param          $dateSelector       1=Show also date range input fields
     */
	function showAddFreeProductForm($dateSelector=0)
	{
		global $conf,$langs;
		global $html,$bc,$var;

		include(DOL_DOCUMENT_ROOT.'/core/tpl/freeproductline_create.tpl.php');
	}

	/**
	 *	Show linked object block
	 *	@param	$object
	 *	@param	$objectid
	 *	@param	$somethingshown
     *  TODO This must be moved into a html.class file instead of a business class.
     *  But for the moment we don't know if it'st possible as we keep a method available on overloaded objects.
	 */
	function showLinkedObjectBlock($object,$objectid,$somethingshown=0)
	{
		global $langs,$bc;

		$num = sizeof($objectid);
		if ($num)
		{
			$classpath = $object.'/class';
			$tplpath = $object;
			if ($object == 'facture') $tplpath = 'compta/'.$object; $classpath = $tplpath.'/class';  // To work with non standard path
			if ($object == 'propal') $tplpath = 'comm/'.$object; $classpath = $tplpath.'/class';     // To work with non standard path

			$classname = ucfirst($object);
			if(!class_exists($classname)) require(DOL_DOCUMENT_ROOT."/".$classpath."/".$object.".class.php");
			$linkedObjectBlock = new $classname($this->db);
			include(DOL_DOCUMENT_ROOT.'/'.$tplpath.'/tpl/linkedobjectblock.tpl.php');
			return $num;
		}
	}

	/**
	 * 	Return HTML table with title list
     *  TODO This must be moved into a html.class file instead of a business class.
     *  But for the moment we don't know if it'st possible as we keep a method available on overloaded objects.
	 */
	function print_title_list()
	{
		global $conf,$langs;

		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Description').'</td>';
		if ($conf->global->PRODUIT_USE_MARKUP) print '<td align="right" width="80">'.$langs->trans('Markup').'</td>';
		print '<td align="right" width="50">'.$langs->trans('VAT').'</td>';
		print '<td align="right" width="80">'.$langs->trans('PriceUHT').'</td>';
		print '<td align="right" width="50">'.$langs->trans('Qty').'</td>';
		print '<td align="right" width="50">'.$langs->trans('ReductionShort').'</td>';
		print '<td align="right" width="50">'.$langs->trans('TotalHTShort').'</td>';
		print '<td width="48" colspan="3">&nbsp;</td>';
		print "</tr>\n";
	}

	/**
	 * 	Return HTML with object lines list
     *  FIXME This must be moved into a html.class file instead of a business class.
	 */
	function printLinesList($dateSelector=0)
	{
		$num = count($this->lines);
		$var = true;
		$i	 = 0;

		foreach ($this->lines as $line)
		{
			$var=!$var;

			if ($line->product_type == 9 && ! empty($line->special_code))
			{
				$this->hooks[$line->special_code]->printObjectLine($this,$line,$num,$i);
			}
			else
			{
				$this->printLine($line,$var,$num,$i,$dateSelector);
			}

			$i++;
		}
	}

	/**
	 * 	Return HTML with selected object line
	 * 	@param	    $line		       Selected object line to output
	 *  @param      $var               Is it a an odd line
	 *  @param      $num               Number of line
	 *  @param      $i
     *  @param      $dateSelector      1=Show also date range input fields
     *  TODO This must be moved into a html.class file instead of a business class.
     *  But for the moment we don't know if it'st possible as we keep a method available on overloaded objects.
	 */
	function printLine($line,$var=true,$num=0,$i=0,$dateSelector=0)
	{
		global $conf,$langs,$user;
		global $html,$bc;

		$element = $this->element;
		if ($element == 'propal') $element = 'propale';   // To work with non standard path

		// Show product and description
		$type=$line->product_type?$line->product_type:$line->fk_product_type;
		// Try to enhance type detection using date_start and date_end for free lines where type
		// was not saved.
		if (! empty($line->date_start)) $type=1;
		if (! empty($line->date_end)) $type=1;

		// Ligne en mode visu
		// TODO simplifier les templates
		if ($_GET['action'] != 'editline' || $_GET['lineid'] != $line->id)
		{
			// Produit
			if ($line->fk_product > 0)
			{
				$product_static = new Product($db);

				$product_static->type=$line->fk_product_type;
				$product_static->id=$line->fk_product;
				$product_static->ref=$line->ref;
				$product_static->libelle=$line->product_label;
				$text=$product_static->getNomUrl(1);
				$text.= ' - '.$line->product_label;
				$description=($conf->global->PRODUIT_DESC_IN_FORM?'':dol_htmlentitiesbr($line->description));

				include(DOL_DOCUMENT_ROOT.'/core/tpl/predefinedproductline_view.tpl.php');
			}
			else
			{
				include(DOL_DOCUMENT_ROOT.'/core/tpl/freeproductline_view.tpl.php');
			}
		}

		// Ligne en mode update
		if ($this->statut == 0 && $_GET["action"] == 'editline' && $user->rights->propale->creer && $_GET["lineid"] == $line->id)
		{
			if ($line->fk_product > 0)
			{
				include(DOL_DOCUMENT_ROOT.'/core/tpl/predefinedproductline_edit.tpl.php');
			}
			else
			{
				include(DOL_DOCUMENT_ROOT.'/core/tpl/freeproductline_edit.tpl.php');
			}
		}
	}

}

?>