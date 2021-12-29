<?php
namespace proc;


interface ILogger
   {
      const LVL_NONE    = 0;
      const LVL_ERR     = 1;
      const LVL_WARN    = 2;
      const LVL_PERF    = 3;
      const LVL_INFO    = 4;  //info
      const LVL_DTL     = 5;  //detailed info
      const LVL_DBG     = 6;  //debug

      const SRC_HNDL    = 'HNDL';   //top level handler (i.e. worker or scheduler)
      const SRC_RDR     = 'RDR';    //reader
      const SRC_ADPTR   = 'ADPTR';  //reader adaptor
      const SRC_TRG     = 'TRG';    //import target



      public function setCtxSched($pid);
      public function setCtxWorker($pid, $wrkCls=NULL);
      public function setCtxFile($primAppDomainInt, $fileID);
      public function resetCtxFile();


      public function isLoggable($lvl, $src=NULL);


      /**
       * @param string  $msg
       * @param int     $lvl
       * @param string  $src
       * @param bool    $noEOL
       */
      public function log($msg, $lvl, $src=NULL, $noEOL=false);

      /**
       * @param string|\Exception|array   $msg
       * @param string                    $src
       * @param bool                      $noEOL
       */
      public function err($msg, $src=NULL, $noEOL=false);
      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      public function warn($msg, $src=NULL, $noEOL=false);
      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      public function info($msg, $src=NULL, $noEOL=false);
      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      public function perf($msg, $src=NULL, $noEOL=false);
      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      public function dtl($msg, $src=NULL, $noEOL=false);
      /**
       * @param string  $msg
       * @param string  $src
       * @param bool    $noEOL
       */
      public function dbg($msg, $src=NULL, $noEOL=false);


      public function memoryStat($lvl=self::LVL_INFO, $src=NULL);



      public function enableErrorCollection(array $sources);
      public function disableErrorCollection();

      public function getCollectedErrors($sources=NULL, $plain=false);
      public function flushCollectedErrors();
   }
