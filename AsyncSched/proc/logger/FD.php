<?php
namespace proc\logger;


class FD extends BaseAbstract
   {
      const TRG_LVL_DEFAULT = 0;

      private $_targets = [
         self::LVL_ERR           => STDERR,
         self::LVL_WARN          => STDERR,
         self::TRG_LVL_DEFAULT   => STDOUT
      ];



      /**
       * @param array   $lvlTargets    Log targets by levels [logLvl => FD], FD must be opened for wr. Defaults to STDERR/STDOUT
       * @param int     $lvl           Log level
       * @param int     $exDumpFlags   Exception dumper flags
       */
      public function __construct(array $lvlTargets=NULL, $lvl=NULL, $exDumpFlags=NULL)
         {
            parent::__construct($lvl, $exDumpFlags);
            if(!empty($lvlTargets)) $this->_targets=$lvlTargets;
         }



      protected function _log($msg, $lvl, $src=NULL, $noEOL=false)
         {
            $lvlStr='';
            switch($lvl)
               {
                  case self::LVL_ERR:
                     $lvlStr='ERROR';
                     break;
                  case self::LVL_WARN:
                     $lvlStr='WARN';
                     break;
                  case self::LVL_PERF:
                     $lvlStr='PERF';
                     break;
                  case self::LVL_INFO:
                     $lvlStr='INFO';
                     break;
                  case self::LVL_DTL:
                     $lvlStr='INFO-DTL';
                     break;
                  case self::LVL_DBG:
                     $lvlStr='DEBUG';
                     break;
               }

            fwrite(
               isset($this->_targets[$lvl])? $this->_targets[$lvl] : $this->_targets[self::TRG_LVL_DEFAULT],
               (empty($ctx=$this->getCtxPrefix())? '':$ctx.': ').(empty($src)? '':$src.': ').(empty($lvlStr)? '':$lvlStr.': ').$msg.($noEOL? '':PHP_EOL)
            );
         }
   }
