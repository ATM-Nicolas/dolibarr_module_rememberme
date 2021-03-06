#!/usr/bin/php
<?php
/*
 * Script envoyant un mail au client chaque jour si besoin 
 * 
 */
 	define('INC_FROM_CRON_SCRIPT', true);
	
	chdir(__DIR__);
	
	require('../config.php');
	dol_include_once('/rememberme/lib/rememberme.lib.php');
	dol_include_once('/comm/action/class/actioncomm.class.php');
	dol_include_once('/societe/class/societe.class.php');
	
	$debug = GETPOST('DEBUG');
	$langs->load('mails');
	if(!empty($debug))echo '<br/><br/>';
	$PDOdb=new TPDOdb;
	$dontsend=false;
	$send=null;

	$sql = "SELECT a.id";
	$sql.=" FROM ".MAIN_DB_PREFIX."actioncomm a";
	$sql.=" LEFT JOIN ".MAIN_DB_PREFIX."c_actioncomm ac ON (a.fk_action = ac.id)";
	$sql.=" WHERE ac.code = 'AC_RMB_EMAIL'";
	$sql.=" AND a.datep <= NOW()";
	$sql.=" AND a.percent = 0;";
	$PDOdb->Execute($sql);
	while($PDOdb->Get_line()) {
		
		$actioncomm = new ActionComm($db);
		$actioncomm->fetch($PDOdb->Get_field('id'));
		$titre = $actioncomm->label;
		$corps = $actioncomm->note;
		$emailto=$error=null;
		if(!empty($actioncomm->societe->id))
		{
			$societe = new Societe($db);
			$societe->fetch($actioncomm->societe->id);
			if(empty($societe->email))
			{
				$error = "La société ".$societe->name." n'a pas d'email définit sur sa fiche";
			}else{
				$emailto = $societe->email;
				
				/*********************************
				 * 
				 * Spécifique céribois, 
				 * Peut évoluer En général
				 * avec un champs pour rememberme
				 * label : Ne pas recevoir les documents par email
				 * type : boolean
				 * name : options_cant_send_doc_by_mail
				 * default : 0 (on reçoit tout)
				 * 
				 * *******************************/
				if($conf->cliceribois->enabled)
				{
					if($societe->array_options['options_cant_send_doc_by_mail'])
					{
						$error = "La société ".$societe->name." a définit qu'elle ne souhaitait pas recevoir d'email";
						$dontsend=true;
					}
				}
			}
		}else{
				$error = "L'évènement ".$actioncomm->ref." (".$actioncomm->label.") n'a pas de société associé";
		}
		
		/* ******************************************
		 * 
		 * CAS erreur on envois le mail à l'auteur
		 * pour qu'il traite l'errreur manuellement
		 * 
		 ********************************************/
		if(!empty($error))
		{
			$emailto = $mysoc->email;
			$excorps = $corps;
			$corps = 'Cet email n\'a pu être envoyé pour la raison suivante :'."<br/>";
			$corps.= $error."<br/><br/>";
			$corps.= '-----------------------------------------------'."<br/><br/>";
			$corps.= $excorps;
		}
		$abricotMail = new TReponseMail($mysoc->email,$emailto,$titre,$corps);
		if(!$dontsend)$send = $abricotMail->send();
		
		if($send)
		{
			$actioncomm->percentage = 100;
			if(empty($actioncomm->datef)) {
				$actioncomm->datef = date('Y-m-d H:i:s');
			}
				
			$actioncomm->update($user);
		}
		
		if(!empty($debug))var_dump($actioncomm, $societe, $mysoc, $abricotMail);
		if(!empty($debug))echo '<br/>br/>';
		echo date('Y-m-d H:i:s').' - Réponse :: '.$send."::\n";
		
	}

	if(empty($actioncomm))
	{
		echo date('Y-m-d H:i:s').' - La requête n\'a fait aucun retour'."\n";
	}
	
	
	// mail interne
	$sql = "SELECT id";
	$sql.=" FROM ".MAIN_DB_PREFIX."actioncomm";
	$sql.=" WHERE code = 'AC_RMB_EI'";
	$sql.=" AND datep <= NOW()";
	$sql.=" AND percent = 0;";
	$PDOdb->Execute($sql);
	while($PDOdb->Get_line()) {
		
		$actioncomm = new ActionComm($db);
		$actioncomm->fetch($PDOdb->Get_field('id'));
		$actioncomm->fetch_userassigned();
		
		$titre = $actioncomm->label;
		$corps = $actioncomm->note;
		
		$dontsend = false;
		
		foreach($actioncomm->userassigned as &$au) {
			
			$u=new User($db);
			if($u->fetch($au['id'])>0 && !empty($u->email)) {
				$error=null;
				if(!empty($u->email)) {
					$emailto = $u->email;	
				}
				else {
					$error="Aucun email définit pour l'utilisateur ".$u->login;
					$dontsend = true;
				}
				
				if(!empty($error))
				{
					$emailto = $mysoc->email;
					$excorps = $corps;
					$corps = 'Cet email n\'a pu être envoyé pour la raison suivante :'."<br/>";
					$corps.= $error."<br/><br/>";
					$corps.= '-----------------------------------------------'."<br/><br/>";
					$corps.= $excorps;
				}
				$abricotMail = new TReponseMail($mysoc->email,$emailto,$titre,$corps);
				if(!$dontsend)$send = $abricotMail->send();
				
			}
			
			
		}
		
		if(!$dontsend)
		{
			$actioncomm->percentage = 100;
			if(empty($actioncomm->datef)) {
				$actioncomm->datef = date('Y-m-d H:i:s');
			}
				
			$actioncomm->update($user);
		}
		
		if(!empty($debug))var_dump($actioncomm, $societe, $mysoc, $abricotMail);
		if(!empty($debug))echo '<br/>br/>';
		echo date('Y-m-d H:i:s').' - Réponse :: '.$send."::\n";
		
		
	}

	if(empty($actioncomm))
	{
		echo date('Y-m-d H:i:s').' - La requête n\'a fait aucun retour'."\n";
	}
	
	
	return 1;
	
	
