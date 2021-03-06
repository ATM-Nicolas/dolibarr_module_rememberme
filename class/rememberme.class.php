<?php

require_once DOL_DOCUMENT_ROOT .'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT .'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT .'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';


class TRememberMe extends TObjetStd {
	
    function __construct() { /* declaration */
        global $langs,$db;
        parent::set_table(MAIN_DB_PREFIX.'rememberme');
        parent::add_champs('fk_societe,fk_user',array('index'=>true, 'type'=>'int'));
        parent::add_champs('nb_day_after,fk_object,fk_parent',array('type'=>'int'));
        
        parent::add_champs('trigger_code,type,type_event,type_object', array('index'=>true, 'type'=>'string', 'length'=>50));
        
        parent::add_champs('titre,message,message_condition,message_code', array('type'=>'text'));
        
        parent::_init_vars('type_msg');
        parent::start();
		
		$this->titre = 'RememberMe - titre';
		$this->message = 'Bonjour [societe_nom]'."\n".'
Code client [societe_code_client]'."\n".'
Propale ref client [ref]
Propale date [date]';
        
        $this->type='MSG';
        $this->TType=array(
            'MSG'=>'Message écran'
            ,'EVENT'=>'Evènement agenda'
            ,'EMAIL'=>'Envoi email'
            ,'EMAIL_INTERNE'=>'Envoi email interne'
            ,'EVAL'=>'Evaluation du code php (attention !)'
        );
        
        $this->type_msg = 'mesgs';
        $this->TTypeMessage=array(
            'mesgs'=>'Information'
            ,'warnings'=>'Alerte'
            ,'errors'=>'Erreur'
        );
		$this->db = $db;
        
    }
    
    static function getAll(&$PDOdb, $type='') {
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."rememberme WHERE 1 ";
        
        if(!empty($type)) $sql.=" AND  type = '".$type."' "; 
        
        $sql.=" ORDER BY date_cre ";
        
        $Tab = $PDOdb->ExecuteAsArray($sql);
        
        $TRes = array();
        foreach($Tab as $row) {
            
            $r=new TRememberMe;
            $r->load($PDOdb, $row->rowid);
            
            $TRes[] = $r;
        }
        
        return $TRes ;
    }
	
	static function getArrayModify(&$PDOdb, $Tab=array(), $object)
	{
		$TRes=array();
	    foreach($Tab as $row) {
	        $r=new TRememberMe;
			$r->rowid = $row->rowid;
	        $r->load($PDOdb, $row->rowid);
	        
			if($r->fk_parent == 0)
	        	$TRes[$r->getId()] = $r;
			else if($object->id == $r->fk_object)
	        	$TRes[$r->fk_parent] = $r;
	    }
		return $TRes;
	}
	
    /**
     *  Fetch all triggers for current object
     *
     *  @param	CommonObject	$object			Object used for search
     *  @param	TPDOdb			$PDOdb			Abricot db object
     *  @return int         					empty array if KO, array if OK
     */
    static function fetchAllForObject(&$PDOdb, $object) {
    	$TRes = array();
        if(isset($object))
		{
			$type_object=$object->element;
			
	        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."rememberme";
	        $sql.= " WHERE trigger_code LIKE '%".$type_object."%'";
	        $sql.= " ORDER BY rowid ASC";
	        
	        $Tab = $PDOdb->ExecuteAsArray($sql);
	        $TRes = self::getArrayModify($PDOdb, $Tab, $object);
		}
		return $TRes;
    }
    
    static function message($action, &$object, $type='') {
        global $user, $db, $conf, $langs;
        
        $PDOdb = new TPDOdb;
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."rememberme 
                WHERE trigger_code='".$action."'";
        if(!empty($type)) $sql.=" AND  type = '".$type."' "; 
        
        $Tab = $PDOdb->ExecuteAsArray($sql);
		
		//echo $action.'<br>';
        foreach($Tab as $row) {
        	// Switch pour gérer des spécificité en fonction des triggers
	        switch($action)
			{
				case preg_match('/VALIDATE/', strtoupper($action)) ? true : false : //TODO AA cette écriture est à chier (intelligent mais illisible, là où un if apporte une solution aussi efficace et surtout lisible !)
					
					// Requete pour récuperer les actioncomm futurs
					$TRemembermeElement = TRememberMeElement::getAll($PDOdb, $row->rowid,$object->id,'propal',null,'actioncomm');

					// On parcours tout et on test pour delete
					foreach($TRemembermeElement as $remembermeElement) {
						$actioncomm=new ActionComm($db);
						$actioncomm->fetch($remembermeElement->fk_target);
						if(!empty($actioncomm->id))
						{
							$actiondate = strtotime(date('Y-m-d', $actioncomm->datep)); // Date de l event en affichage Y-m-d
							$dateactuel = strtotime(date('Y-m-d')); // Date du jour affichée Y-m-d
							if($actiondate >= $dateactuel && $actioncomm->percentage != 100)
							{
								$remembermeElement->delete($PDOdb);
								$actioncomm->delete();
							}
						}else{
							// Un actioncomm a été delete manuellement on vide element
							$remembermeElement->delete($PDOdb);
						}
					}
					break;
				default:
					break;
			}
            //var_dump($row);
            if($row->fk_societe>0 && ($object->fk_soc!=$row->fk_societe && $object->socid!=$row->fk_societe ) ) continue; // pas pour lui ce message
            if($row->fk_user>0 && $row->fk_user!=$user->id)continue; // non plus
            
            if(empty($row->type_msg))$row->type_msg='warnings';
            
            if(!empty($row->message_condition)) {
		if(strpos($row->message_condition,'return ') === false) {
			$row->message_condition = 'return ('.$row->message_condition.');';
		}
                if(!eval($row->message_condition)) continue; //ne répond pas au test 
            }
            
            if($row->type == 'MSG') setEventMessage($row->message, $row->type_msg);
            else if($row->type == 'EVENT') {
                    
				$actioncomm=new ActionComm($db);    
				$actioncomm->datep = strtotime('+'.$row->nb_day_after.'day');
				//$a->datef = $t_end;
				
				$actioncomm->userownerid = $user->id;
				$actioncomm->type_code='AC_RMB_OTHER';
				$actioncomm->label = $row->titre ;
				
				$actioncomm->elementtype=$object->element;
				$actioncomm->fk_element = $object->id;
				$actioncomm->fk_project = $object->fk_project;
				
				$actioncomm->progress = 0;
				
				$actioncomm->durationp = 0;
				// Utile pour le suivi de trigger
				//$actioncomm->location = 'rememberme|'.$row->rowid;
				
				$actioncomm->socid = !empty($object->socid) ? $object->socid : $object->fk_soc;
				$actioncomm->note = $row->message;
				
				$actioncomm->label = TRememberMe::changeTags($object, $row->titre); //TODO sérieusement là ?
				$actioncomm->note = TRememberMe::changeTags($object, $row->message);
				
				$actioncomm->add($user);
				
				$rememberme_element=new TRememberMeElement;
				$rememberme_element->targettype='actioncomm';
				$rememberme_element->sourcetype=$object->table_element;
				$rememberme_element->fk_rememberme=$row->rowid;
				$rememberme_element->fk_target=$actioncomm->id;
				$rememberme_element->fk_source=$object->id;
				$rememberme_element->save($PDOdb);
				
                
            }
            else if (($row->type == 'EMAIL' || $row->type == 'EMAIL_INTERNE')
				&& ($object->type_code!='AC_RMB_EMAIL' && $object->type_code!='AC_RMB_EI')) {
            	//getAll(&$PDOdb,$fk_rememberme=0, $fk_source=null, $sourcetype='', $fk_target=null, $targettype='')
            	//$PDOdb->debug=true;
				$TAction = TRememberMeElement::getAll($PDOdb,0,$object->id,$object->table_element,0,'actioncomm');
				
				if(empty($TAction)) {
					
					$actioncomm=new ActionComm($db);
					$actioncomm->societe = $object->societe;
					$actioncomm->socid = !empty($object->socid) ? $object->socid : $object->fk_soc;
					$actioncomm->datep = strtotime('+'.$row->nb_day_after.'day');
					
					$actioncomm->userownerid = $user->id;
					$actioncomm->userassigned = array('id'=>$user->id, 'transparency'=>0);
					
					$actioncomm->type_code= $row->type == 'EMAIL' ? 'AC_RMB_EMAIL' : 'AC_RMB_EI';
					
					$actioncomm->elementtype=$object->element;
					$actioncomm->fk_element = $object->id;
					
					$actioncomm->progress = 0;
					
					$actioncomm->durationp = 0;
					// Utile pour le suivi de trigger
					//$actioncomm->location = 'rememberme|'.$row->rowid;
					
					$actioncomm->label = TRememberMe::changeTags($object, $row->titre);
					$actioncomm->note = TRememberMe::changeTags($object, $row->message);
			
					$res = $actioncomm->add($user,1);

					if($res>0) {
						$rememberme_element=new TRememberMeElement;
						$rememberme_element->targettype='actioncomm';
						$rememberme_element->sourcetype=$object->table_element;
						$rememberme_element->fk_rememberme=$row->rowid;
						$rememberme_element->fk_target=$actioncomm->id;
						$rememberme_element->fk_source=$object->id;
						$rememberme_element->save($PDOdb);
					}

				}
				else {
					
					foreach($TAction as &$rmbel) {
						
						$actioncomm=new ActionComm($db);
						if($actioncomm->fetch($rmbel->fk_target)>0) {
							if (method_exists($actioncomm, 'fetch_userassigned')) $actioncomm->fetch_userassigned();
							
							if(!empty($row->message_code)) {
					
								   $eval = $row->message_code;
									
					               $res = eval($eval);
									//var_dump($actioncomm->userassigned , $object->userassigned);exit;	
					        }
							
							
						}
						else {
							$rmbel->delete($PDOdb);
						}
						
						$nomoreeval =true;
					}
					
				}
				
				

            }

			if(!empty($row->message_code) && empty($nomoreeval)) {
				
					$eval = $row->message_code;
				
	                eval($eval);
	        }

            
            
            
        }        
        $PDOdb->close();        
      
    }

	static function changeTags($object, $val)
	{
		global $db;//TODO add user tag
		$societe = new Societe($db);
		$socid = !empty($object->socid) ? $object->socid : $object->fk_soc;
		$societe->fetch($socid);
		$date = date("Y-m-d", $object->date);
		$TNewval = array("{$societe->name}", "{$societe->code_client}", "{$object->newref}", "{$object->ref_client}", "{$date}");
		$TTags = array('[societe_nom]','[societe_code_client]','[ref]','[ref_client]','[date]');
		return str_replace($TTags, $TNewval, $val);
	}
    
}


/*******************************************
 * 
 * 		   CLASS TRemembermeElement
 * 
 * *****************************************/
 
class TRememberMeElement extends TObjetStd {
	
    function __construct() { /* declaration */
        global $langs,$db;
        parent::set_table(MAIN_DB_PREFIX.'rememberme_element');
        parent::add_champs('fk_source,fk_target,fk_rememberme',array('index'=>true, 'type'=>'int'));
        parent::add_champs('sourcetype,targettype', array('index'=>true, 'type'=>'string', 'length'=>50)); 
		
        parent::start();
		
		$this->db = $db;
        
	}
    
    static function getAll(&$PDOdb,$fk_rememberme=0, $fk_source=null, $sourcetype='', $fk_target=null, $targettype='') {
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."rememberme_element WHERE 1 ";
        
        if($fk_rememberme>0) $sql.= " AND fk_rememberme = ".$fk_rememberme;

        if(!empty($sourcetype)) $sql.=" AND  sourcetype = '".$sourcetype."' "; 
        if(!empty($fk_source)) $sql.=" AND  fk_source = ".$fk_source; 
        if(!empty($targettype)) $sql.=" AND  targettype = '".$targettype."' "; 
        if(!empty($fk_target)) $sql.=" AND  fk_target = ".$fk_target; 
		
        $Tab = $PDOdb->ExecuteAsArray($sql);
        
        $TRes = array();
        foreach($Tab as $row) {
            $r=new TRememberMeElement;
            $r->load($PDOdb, $row->rowid);
            
            $TRes[] = $r;
        }
        
        return $TRes ;
    }
    
}
