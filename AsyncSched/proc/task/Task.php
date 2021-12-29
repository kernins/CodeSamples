<?php
namespace proc\task;
use web\app\model\import, utils\UtilJSON;


/**
 * Represents file to be handled
 * Also used as interproccess data-exchange entity
 */
final class Task implements import\source\IStateEnumerator, \JsonSerializable
   {
      /** @var int File ID */
      private $_id=NULL;
      /** @var int File owner ID */
      private $_ownerID=NULL;

      /** @var string File state, one of import\source\IStateEnumerator::STATE_* constants */
      private $_state=NULL;

      /** @var SharedResUsageDeclaration SharedResource usage declaration instance (not available prior file analysis) */
      private $_sharedResUsageDecl=NULL;



      public function __construct($id, $ownerID, $state, SharedResUsageDeclaration $sru=NULL)
         {
            if(($this->_id=(int)$id) <= 0) throw new \UnexpectedValueException('None or invalid file ID given');
            if(($this->_ownerID=(int)$ownerID) <= 0) throw new \UnexpectedValueException('None or invalid file ownerID given');
            if(!strlen($this->_state=trim($state))) throw new \UnexpectedValueException('No file state given');

            if(!empty($sru)) $this->declareSharedResUsage($sru);
         }

      public function declareSharedResUsage(SharedResUsageDeclaration $sru)
         {
            $this->_sharedResUsageDecl=$sru;
            return $this;
         }



      public function getID()
         {
            return $this->_id;
         }

      public function getOwnerID()
         {
            return $this->_ownerID;
         }

      public function getState()
         {
            return $this->_state;
         }

      /** @return SharedResUsageDeclaration */
      public function getSharedResUsageDeclaration()
         {
            return $this->_sharedResUsageDecl;
         }



      /**
       * @param string|array  $data
       * @return Task
       */
      public static function newInstanceFromJSON($data)
         {
            if(is_string($data)) $data=UtilJSON::decode($data);
            //checking in both cases, including json-decoded data
            if(!is_array($data)) throw new \UnexpectedValueException('Invalid data given: expecting plain or JSON-encoded array, '.gettype($data).' given');
            if(!isset($data['id'], $data['ownerID'], $data['state'])) throw new \UnexpectedValueException('Invalid data given: one or more essential entries are missing');

            return new self($data['id'], $data['ownerID'], $data['state'], empty($data['sru'])? NULL:SharedResUsageDeclaration::newInstanceFromJSON($data['sru']));
         }

      public function jsonSerialize()
         {
            return ['id'=>$this->_id, 'ownerID'=>$this->_ownerID, 'state'=>$this->_state, 'sru'=>$this->_sharedResUsageDecl];
         }
   }
