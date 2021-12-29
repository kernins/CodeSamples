<?php
namespace proc\logger;
use web\engine\ErrorHandler;


abstract class BaseAbstract implements \proc\ILogger
   {
      const EX_DUMP_PREV      = 0b00000001;
      const EX_DUMP_TRACE     = 0b00000010;

      const LVL_SRC_DEFAULT   = '*';


      private $_lvl = [self::LVL_SRC_DEFAULT => self::LVL_WARN];
      private $_exDumpFlags = NULL;

      private $_collectErrors = [];
      private $_errors = [];

      protected $ctx = NULL;
      protected $ctxFile = NULL;



      /**
       * @param int|array  $lvl           Either int global lvl, or array [src=>lvl] by sources
       * @param int        $exDumpFlags
       */
      public function __construct($lvl=NULL, $exDumpFlags=NULL)
         {
            if($lvl!==NULL)
               {
                  if(is_array($lvl))
                     {
                        if(!isset($lvl[self::LVL_SRC_DEFAULT])) $lvl[self::LVL_SRC_DEFAULT]=self::LVL_NONE;
                        $this->_lvl=$lvl;
                     }
                  else $this->_lvl=[self::LVL_SRC_DEFAULT=>(int)$lvl];
               }
            if($exDumpFlags!==NULL) $this->_exDumpFlags=(int)$exDumpFlags;
         }


      final public function setCtxSched($pid)
         {
            $this->ctx=['SCHED', $pid];
            return $this;
         }

      final public function setCtxWorker($pid, $wrkCls=NULL)
         {
            $this->ctx=[empty($wrkCls)? 'WRK':$wrkCls, $pid];
            return $this;
         }

      final public function setCtxFile($primAppDomainInt, $fileID)
         {
            $this->ctxFile=[$primAppDomainInt, $fileID];
            return $this;
         }

      final public function resetCtxFile()
         {
            $this->ctxFile=NULL;
            return $this;
         }

      protected function getCtxPrefix()
         {
            if(!empty($this->ctxFile))
               {
                  $ctx=[];
                  if(!empty($this->ctxFile[0])) $ctx[]=$this->ctxFile[0]; //appDomain
                  if(!empty($this->ctxFile[1])) $ctx[]='file#'.$this->ctxFile[1];
                  $ctx=(empty($ctx)? '':'['.implode(', ', $ctx).']');
               }
            else $ctx='';

            return empty($this->ctx)? $ctx : $this->ctx[0].'#'.$this->ctx[1].$ctx;
         }



      final public function enableErrorCollection(array $sources)
         {
            if(empty($sources)) throw new \UnexpectedValueException('No sources of interest given');
            $this->_collectErrors=array_fill_keys($sources, true);
            return $this;
         }

      final public function disableErrorCollection()
         {
            $this->_collectErrors=[];
            return $this;
         }

      /**
       * @param string|array  $sources    Get errors originated only from specified sources
       * @param bool          $plain      Whether to return an array of plain strings or tuples [src, error]
       * @return array  An array of either tuples [src, error] or plain strings [error]
       */
      final public function getCollectedErrors($sources=NULL, $plain=false)
         {
            if(!empty($sources))
               {
                  $errors=[];
                  $sources=array_fill_keys((array)$sources, true);
                  foreach($this->_errors as $err) {if(!empty($sources[$err[0]])) $errors[]=$err;}
               }
            else $errors=$this->_errors;

            return $plain? array_map(function(array $err){return $err[1];}, $errors) : $errors;
         }

      final public function flushCollectedErrors()
         {
            $this->_errors=[];
            return $this;
         }



      final public function isLoggable($lvl, $src=NULL)
         {
            if($src===NULL) $srcLvl=max($this->_lvl); //any src
            else $srcLvl=isset($this->_lvl[$src])? $this->_lvl[$src] : $this->_lvl[self::LVL_SRC_DEFAULT];
            return $srcLvl >= $lvl;
         }



      /**
       * @param string  $msg
       * @param int     $lvl
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function log($msg, $lvl, $src=NULL, $noEOL=false)
         {
            if(!is_int($lvl) || ($lvl<=0)) throw new \InvalidArgumentException('LogMsg level must be integer greater than zero, "'.$lvl.'" given');

            //collection is done independently of lvl setting, only errors are collected
            if(($lvl==self::LVL_ERR) && !empty($src) && !empty($this->_collectErrors[$src])) $this->_errors[]=[$src, $msg];

            if($this->isLoggable($lvl, $src===NULL? self::LVL_SRC_DEFAULT:$src)) $this->_log($msg, $lvl, $src, $noEOL);
            return $this;
         }

      abstract protected function _log($msg, $lvl, $src=NULL, $noEOL=false);


      /**
       * @param string|\Exception|array   $msg
       * @param string                    $src
       * @param bool                      $noEOL
       */
      final public function err($msg, $src=NULL, $noEOL=false)
         {
            $message='';
            //not casting to array here, as $msg may be an instance of \Exception
            foreach(is_array($msg)? $msg:[$msg] as $m)
               {
                  if($m instanceof \Exception)
                     {
                        $message.=ErrorHandler::getErrorMessage(
                           $m,
                           true,
                           $this->_exDumpFlags===NULL? NULL : $this->_exDumpFlags&self::EX_DUMP_PREV,
                           $this->_exDumpFlags===NULL? NULL : $this->_exDumpFlags&self::EX_DUMP_TRACE
                        );
                     }
                  else $message.=$m;
               }
            $this->log($message, self::LVL_ERR, $src, $noEOL);
            return $this;
         }

      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function warn($msg, $src=NULL, $noEOL=false)
         {
            $this->log($msg, self::LVL_WARN, $src, $noEOL);
            return $this;
         }

      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function perf($msg, $src=NULL, $noEOL=false)
         {
            $this->log($msg, self::LVL_PERF, $src, $noEOL);
            return $this;
         }

      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function info($msg, $src=NULL, $noEOL=false)
         {
            $this->log($msg, self::LVL_INFO, $src, $noEOL);
            return $this;
         }

      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function dtl($msg, $src=NULL, $noEOL=false)
         {
            $this->log($msg, self::LVL_DTL, $src, $noEOL);
            return $this;
         }

      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      final public function dbg($msg, $src=NULL, $noEOL=false)
         {
            $this->log($msg, self::LVL_DBG, $src, $noEOL);
            return $this;
         }


      final public function memoryStat($lvl=self::LVL_INFO, $src=NULL)
         {
            $this->log(
               'Memory usage:'.PHP_EOL.
               "\tcurr used ".\utils\UtilNumeric::sizeBytesAsString(memory_get_usage(false), 3).PHP_EOL.
               "\tcurr allocd ".\utils\UtilNumeric::sizeBytesAsString(memory_get_usage(true), 3).PHP_EOL.
               "\tpeak used ".\utils\UtilNumeric::sizeBytesAsString(memory_get_peak_usage(false), 3).PHP_EOL.
               "\tpeak allocd ".\utils\UtilNumeric::sizeBytesAsString(memory_get_peak_usage(true), 3),
               $lvl,
               $src
            );
            return $this;
         }
   }
