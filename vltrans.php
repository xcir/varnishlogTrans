<?php
//error_reporting(E_ALL);
define('experimental' , false);

/////////////////////////////////////////////////////
function main(){
  $para = getParam();
  if(isset($para['-f'])){}
  if(count($_SERVER['argv'])>1){
    $cmd = getCmd() . " -d -f {$para['-f']}";
    if(isset($para['-cc_command']))
      $cmd .= " -p cc_command=\"{$para['-cc_command']}\"";
    $cmd .= ' -C';
    $_vcl=shell_exec($cmd);
    $getvcl = new getVCL();
    if(experimental){
      $getvcl->get($_vcl,true);
    }else{
      $getvcl->get($_vcl);
    }
  }

  $_p = array('backend'  => true,
              'action'   => true,
              'variable' => true,
              'src'      => false,
              );
  
  foreach($_p as $k=>$v){
    if(isset($para['--'.$k]) && $para['--'.$k] == 'off'){
      define('enable_'.$k,false);
    }elseif(isset($para['-'.$k]) && $para['-'.$k] == 'on'){
      define('enable_'.$k,true);
    }else{
      define('enable_'.$k,$v);
    }
  }

  $util = new util();
  
  $fh = fopen('php://stdin','rb'); 
  if(!$fh) {echo "can not open stdin.\n";exit();}
  while ($raw = fgets($fh)){
    $raw = rtrim($raw,"\n"); 
    if($raw == '') continue;

    $rawA = $util->rawDecode($raw);
    if($rawA['trx']=='0'){
      $util->addStatus($rawA);
      
    }else{
      if($util->addTrx($rawA)){
        if(isset($_vcl)){
//          if(experimental){
            $util->echoData(0,$getvcl->VGC_ref,$getvcl->src);
//          }else{
//            $util->echoData(0,$getvcl->VGC_ref);
//          }
        }else{
          $util->echoData(0);
        }
        $util->clearRetData();
      
      }
    }
  }
  fclose($fh); 


}


/////////////////////////////////////////////////////
function getParam(){
  $argv = $_SERVER['argv'];
  array_shift($argv);
  $ret=array();
  $nk=null;
  foreach($argv as $k=>$v){
    if(substr($v,0,1)==='-'){
      if($nk){
        $ret[$nk]=true;
        $nk=null;
      }
      if(strpos($v,'=')!==false){
        $tmp = explode('=',$v,2);
        $ret[$tmp[0]]=$tmp[1];
      }else{
        $nk = $v;
      }
    }elseif($nk!==null){
      $ret[$nk]=$v;
      $nk=null;
    }else{
      $ret[$k]=$v;
    }
  }
  if($nk){
    $ret[$nk]=true;
  }
  return $ret;
}


/////////////////////////////////////////////////////
function getCmd(){
  $cmd = "ps x|grep varnish";
  $spl = explode("\n",shell_exec($cmd));
  foreach($spl as $v){
    if(false === strpos($v , 'grep varnish')){
      $r = preg_replace('@^.* ([^ ]+varnishd ).*@','$1',$v);
      return $r;
    
    }
  }
}



class getVCL{
  public $src;
  public $VGC_ref;
  
/////////////////////////////////////////////////////
  public function get($data , $anasrc = false){
    $_src    =$this->getSrc($data);
    $_srcName  =$this->getSrcName($data);
    $_VGC_ref  =$this->getVGC_ref($data);

    foreach($_VGC_ref as $k=>$v){
      $s=$v['source'];
      $l=$v['line'];
      $p=str_repeat(' ',$v['pos']-1).'^';
      $_VGC_ref[$k]['str'] =$_src[$s][$l];
      $_VGC_ref[$k]['name']=$_srcName[$s];
      $_VGC_ref[$k]['strpos']=$p;
      
    }

    $this->VGC_ref = $_VGC_ref;
    if(experimental && $anasrc)
      $this->__experimental_anasrc($_src,$_srcName,$_VGC_ref);
//    return $_VGC_ref;
  }


/////////////////////////////////////////////////////
  private function getSrc($data){
    $start="\n".'const char *srcbody[2] = {'."\n";
    $end="\n};\n";
    $d=$this->_substr($data,$start,$end);
    $d=substr($d,strlen($start));

    $d=$this->_filter($d);
    //echo $d;
    $de=explode("\n\",\n",$d);
  //    array_shift($de);
  //    array_shift($de);

    $src=array();
    foreach($de as $v){
      $src[]=explode("\n",$v);
    }
    foreach($src as $k=>$v){
//      array_shift($src[$k]);
//      array_shift($src[$k]);
    }
    return $src;
  }

/////////////////////////////////////////////////////
  private function _substr($data,$start,$end){
    $s=strpos($data,$start);
    $e=strpos($data,$end,$s);
    return substr($data,$s,$e-$s);

  }

/////////////////////////////////////////////////////
  private function _filter($d){
    $d=preg_replace("@(\r)?\\\\n\"\n@","\n",$d);
    $d=preg_replace("@\n\s+\"@","\n",$d);
    $d=preg_replace("@\\\\t@","\t",$d);
    $d=preg_replace("@\\\\\"@",'"',$d);
    return $d;
  }

/////////////////////////////////////////////////////
  private function getSrcName($data){
    $start="\n".'const char *srcname[2] = {';
    $end="\n};\n";
    $d=$this->_substr($data,$start,$end);
    $d=$this->_filter($d);
    $d=str_replace('",','',$d);

    $de=explode("\n\",\n",$d);
    $src=array();
    foreach($de as $v){
      $src[]=explode("\n",$v);
    }
    foreach($src as $k=>$v){
      array_shift($src[$k]);
      array_shift($src[$k]);
    }
    return $src[0];
  }

/////////////////////////////////////////////////////
  private function getVGC_ref($data){
    $start="\n".'static struct vrt_ref VGC_ref[VGC_NREFS] = {';
    $end="\n};\n";
    $d=$this->_substr($data,$start,$end);
    preg_match_all('@\[ *([0-9]+)\] *= *{ *([0-9]+), *([0-9]+), *([0-9]+), *([0-9]+), *([0-9]+), *"([^"]+)"@',$d,$m);
    $r=array();
    $max=count($m[0]);
    for($i=0;$i<$max;$i++){
      $r[$m[1][$i]]=array(
        "source"  =>$m[2][$i],
        "offset"  =>$m[3][$i],
        "line"    =>$m[4][$i],
        "pos"    =>$m[5][$i],
        "count"    =>$m[6][$i],
        "token"    =>$m[7][$i],
        "name"    =>'',
        "str"    =>'',
        "strpos"  =>'',
      );
    }
    return $r;
  }

/////////////////////////////////////////////////////
  public function __experimental_anasrc($_src,$_srcName,$_VGC_ref){
    $src=array();
    
    foreach($_src as $k=> $v){
      $src[$k]=array();
      foreach($v as $kk=>$vv){
//      echo "$vv\n";
        $add=null;
        foreach($_VGC_ref as $kkk=>$vvv){
          $s=$vvv['source'];
          $l=(int)$vvv['line'];
          if($kk==$l){
            $add=array(1,$vv);
            break;
          }
        }
        if(!$add) $add=array(0,$vv);
        $src[$k][]=$add;
      }
      
    }

    $t=array();
    foreach($_src as $k=> $v){
      foreach($v as $kk=>$vv){
//        $_src[$k][$kk]=str_replace('\n','\\n',$vv);
        $_src[$k][$kk]=str_replace('__SYSREP__','||SYSREP||',$vv);
      }
      $t[$k]=implode("\n",$_src[$k]);
      $t[$k]=preg_replace('@(sub\s+vcl_)(recv|hash|fetch|hit|miss|pass|pipe|error|deliver|init|fini)(\s*{)@','__SYSREP__$1$2$3__SYSREP__',$t[$k]);
    }
    $tt=array();
    foreach($t as $k=> $v){
      $tt[$k]=explode("\n",$v);
    }

    $src=array();
    foreach($tt as $k=> $v){
      $src[$k]=array();
      $max=count($v)-1;
      $last=-1;
//      for($i=$max;$i>=0;$i--){
      for($i=0;$i<$max;$i++){
        $add=null;
        if(strpos($v[$i],'__SYSREP__')!==false){
          $add=array($last,$v[$i]);
          $last=-1;
        }else{
          foreach($_VGC_ref as $kkk=>$vvv){
            $s=$vvv['source'];
            $l=(int)$vvv['line'];
            if($i==$l && $s==$k){
              $last=$kkk;
              $add=array($kkk,$v[$i]);
              break;
            }
          }
          if(!$add){
            if(preg_match('@(if|elseif|elsif|else)@',$v[$i])){
              $add=array(-1,$v[$i]);
            }else{
              $add=array($last,$v[$i]);
            }
          }
        }
        $src[$k][$i]=$add;
        
      }
      krsort($src[$k]);
      
    }

    foreach($src as $k=>$v){
      $last=-1;
      foreach($v as $kk=>$vv){
        if($vv[0]>-1){
          $last=$vv[0];
        }else{
          $src[$k][$kk][0]=$last;
        }
        $src[$k][$kk][1]=str_replace('__SYSREP__','',$src[$k][$kk][1]);
    //    $src[$k][$kk][1]=str_replace('\\n','\n',$src[$k][$kk][1]);
        $src[$k][$kk][1]=str_replace('||SYSREP||','__SYSREP__',$src[$k][$kk][1]);
        
      }
      ksort($src[$k]);
    }
    
    $this->src=$src;

  }

}

class util{

  protected $tagRep;
  protected $tagVar;
  protected $tmpTrx;
  protected $retData;
  protected $backendHealthy;

/////////////////////////////////////////////////////
  function __construct(){
    $this->retData        = array();
    $this->tmpTrx         = array();
    $this->backendHealthy = array();

    $this->tagRep  = array(
      'Header'   =>'http',
      'Protocol' =>'proto',
      'Request'  =>'request',
      'Response' =>'response',
      'Status'   =>'status',
      'URL'      =>'url',
    );

    $this->tagVar = array(
      'bRx'    =>'beresp',
      'cRx'    =>'req',
      'bTx'    =>'bereq',
      'cTx'    =>'resp',
      'bObj'   =>'obj',
      'cObj'   =>'obj',
    );

  }

/////////////////////////////////////////////////////
  private function _echoline($s = '-'){
    echo str_repeat($s,60)."\n";
  }
/////////////////////////////////////////////////////
  private function _echo($ar,$pad = 1,$pre = 0,$pret = ''){
    $max = 0;
    foreach ($ar as $k => $v){
      if(!is_null($v['v'])){
        $kl = strlen($v['k']);
        if($kl>$max) $max = $kl;
      }
    }
    $padt = str_repeat(' ',$pad).'|'.str_repeat(' ',$pad);
    foreach ($ar as $k => $v){
      $kl = strlen($v['k']);
      echo str_repeat(' ',$pre).$pret.$v['k'];
      if(!is_null($v['v'])){
        echo str_repeat(' ',$max-$kl);
        echo $padt;
        echo $v['v'];
      }
      echo "\n";
    }
    return $max;
  }


/////////////////////////////////////////////////////
  public function _echoRect($ar){
  /*
    ar
     +key =rect-v
     +value(array)
      +k
      +v
  */
    $maxkey=0;
    //一時計算
    foreach($ar as $v){
      $t = strlen($v['key']);
      if($maxkey < $t)
        $maxkey = $t;
      if(isset($v['title'])){
        $t = strlen($v['title']);
        if($maxkey < $t)
          $maxkey = $t;
        
      }
    }
    $ha = (int)$maxkey/2 +2;
    $_pad = str_repeat(' ',$ha);

    //描画Rect
    foreach($ar as $v){
      if(isset($v['title'])){
        $d = (int)($maxkey-strlen($v['title']))/2+1;
        echo str_repeat(' ',$d).$v['title']."\n";
      }
      $this->__echoRect($v['key'],$maxkey);
      echo "{$_pad}|\n";
      $this->_echo($v['value'],1,0,$_pad.'| ');
      echo "{$_pad}|\n";
    }

  }
/////////////////////////////////////////////////////
  private function __echoRect($txt,$len,$pad=1){
    $d = (int)($len-strlen($txt))/2;
    $_padp = str_repeat(' ',$pad+$d);
    $_pads = $_padp;
    $ds = ($len-strlen($txt))%2;
    if($ds>0)
      $_pads .= ' ';
    $line = str_repeat('-',$pad*2+$len+2)."\n";
    echo $line;
    echo "|{$_padp}{$txt}{$_pads}|\n";
    echo $line;
  }


/////////////////////////////////////////////////////
  public function searchHeader($search,$o){
    $search .= ': ';
    foreach($o as $v){
      if(0 === strpos($v,$search)){
        $vv = explode(': ',$v,2);
        return($vv[1]);
        break;
      }
      
    }
    return false;
  }
/////////////////////////////////////////////////////
  private function _trace2array($vv,&$trace,$vcl=null){
    $tmp = array();
    switch($vv['type']){
      case 'trace':
        $trace[]=$vv['vrt_count'];
        if(is_null($vcl)){
          $m = "vrt_count:{$vv['vrt_count']} vcl_line:{$vv['vcl_line']} vcl_pos:{$vv['vcl_pos']}";
          $tmp = array(array(
            'k' => $vv['type'],
            'v' => $m
          ));
        }else{
          $m = $vcl[$vv['vrt_count']]['str'];
          $m2 = $vcl[$vv['vrt_count']]['strpos'].' ('."vrt_count:{$vv['vrt_count']} vcl_line:{$vv['vcl_line']} vcl_pos:{$vv['vcl_pos']} src_name:{$vcl[$vv['vrt_count']]['name']})";
          $tmp=array();
          $tmp[]=array(
            'k' => $vv['type'],
            'v' => $m
          );
          $tmp[]=array(
            'k' => ' ',
            'v' => $m2
          );
        }
        break;
      default:
        $m = $vv['data'];
        $tmp = array(array(
          'k' => $vv['type'],
          'v' => $m
        ));
        break;
    }
    return $tmp;
  }

/////////////////////////////////////////////////////
  public function echoData($no = 0,$vcl=null,$src=null){
    $restart = 0;
    $d = &$this->retData[$no];


    //info
    $tmp = array();

    $tmp[] = array(
      'k' => 'client ip',
      'v' => $d['var'][0]['client']['ip'][0],
    );
    $host = $this->searchHeader('Host',$d['var'][0]['req']['http']);
    if($host)
      $tmp[] = array(
        'k' => 'client request host',
        'v' => $host,
      );
    $tmp[] = array(
      'k' => 'client request url',
      'v' => $d['var'][0]['req']['url'][0],
    );

    $length = 0;
    foreach($d['info']['length'] as $v) $length += $v;
    $tmp[] = array(
      'k' => 'response size',
      'v' => $length.' byte',
    );

    foreach($d['var'] as $v){
      if(isset($v['resp'])){
        $tmp[] = array(
          'k' => 'response status',
          'v' => "{$v['resp']['proto'][0]} {$v['resp']['status'][0]} {$v['resp']['response'][0]}",
        );
        break;
      }
    }
    

    if(isset($d['info']['time.accept']))
      $tmp[] = array(
        'k' => 'Connect time',
        'v' => $d['info']['time.accept'].' sec',
      );
    if(isset($d['info']['time.execute']))
      $tmp[] = array(
        'k' => 'Waiting time',
        'v' => $d['info']['time.execute'].' sec',
      );
    if(isset($d['info']['time.exit']))
      $tmp[] = array(
        'k' => 'Processing time',
        'v' => $d['info']['time.exit'].' sec',
      );
    $tmp[] = array(
      'k' => 'Total time',
      'v' => $d['info']['time.total'].' sec',
    );


    $restart = 0;
    $esi = 0;
    foreach($d['countinfo'] as $v){
      if($v == 'restart'){$restart++;}
      elseif($v == 'esi'){$esi++;}
    }
    $tmp[] = array(
      'k' => 'restart count',
      'v' => $restart,
    );
    $tmp[] = array(
      'k' => 'ESI count',
      'v' => $esi,
    );
    $bname='';
    foreach($d['backend'] as $vz){
      $bname.="{$vz['info']['backend.name']}({$vz['info']['backend.server']}) ";
    }
    $tmp[] = array(
      'k' => 'call backend',
      'v' => count($d['backend']).' '.rtrim($bname),
    );
    $hit = 0;
    $miss = 0;
    foreach($d['call'] as $v){
      if($v['method'] == 'hit'){$hit++;}
      elseif($v['method'] == 'miss'){$miss++;}
    }
    $tmp[] = array(
      'k' => 'Cache',
      'v' => "HIT:{$hit} MISS:{$miss}",
    );

    $vrtcntlist=array();
    $this->_echo($tmp);
    echo "\n";
    if(enable_backend){
      $this->echoBackendHealthy();
      echo "\n";
    }
//////////////////////////
    $this->_echoline('#');
    echo "action sequence\n";
    //calllist
    $this->_echoline();

    if(enable_action){
      $tmp = array();
      foreach($d['call'] as $k => $v){
        $tmpm = array();
        $title=null;
        if($v['method'] == 'recv' && $v['count']>0){
          switch($d['countinfo'][$v['count']]){
            case 'restart':
              $restart++;
              $title="<<restart count={$v['count']}>>";
              break;
            case 'esi':
              $title='<<ESI request>>';
              break;
          }
        }

        
        foreach($v['trace'] as $kk => $vv){
          $tmpret = $this->_trace2array($vv,$vrtcntlist,$vcl);
          foreach($tmpret as $tmpretv){
             $tmpm[] = $tmpretv;
          }
  //        $tmpm[] = $this->_trace2array($vv,$vrtcntlist,$vcl);
        }
  //      $tmpm[] = array('k' => '','v' => '');
        if($v['method'] == 'hash' && isset($d['hash'][$v['count']])){
          $y = '';
          $z = '';
          foreach($d['hash'][$v['count']] as $x){
            $y .= $z.$x;
            $z = ' + ';
          }
          $tmpm[] = array('k' => 'hash','v' => $y);
        }

        $tmpm[] = array('k' => 'return','v' => $v['return']);
        $tmp[]=array('key'=>$v['method'],'value'=>$tmpm,'title'=>$title);
      }
      $this->_echoRect($tmp);
      echo "\n\n";
    }

    //src-trace
    if(enable_src){
    
      if($src){
        $this->_echoline('#');
        echo "vcl trace\n";
        foreach($src as $k=>$v)
          foreach($v as $kk=>$vv){
            if(in_array($vv[0],$vrtcntlist)) echo "[exec]";
            echo "\t$vv[1]\n";
          }
        echo "\n";
      }
    }

//////////////////////////
    //ヘッダリスト
    if(enable_variable){

      foreach($d['var'] as $k => $v){
        $tmp = array();
        $tmp[] = array(
          'k' => 'variable infomation.',
          'v' => null
        );
        $tmp[] = array(
          'k' => ' ',
          'v' => ' '
        );
        $this->_echoline('#');
        if($k>0){
          switch($d['countinfo'][$k]){
            case 'restart':
              $restart++;
              $tmp[] = array(
                'k' => 'restart',
                'v' => "yes(count={$k})"
              );
              break;
            case 'esi':
              $tmp[] = array(
                'k' => 'ESI',
                'v' => "yes"
              );
            
          }
        }
        if(isset($d['hash'][$k])){
          $y = '';
          $z = '';
          foreach($d['hash'][$k] as $x){
            $y .= $z.$x;
            $z =' + ';
          }
          $tmp[] = array(
            'k' => 'hash',
            'v' => $y
          );

        }
        $this->_echo($tmp);
        foreach($v as $kk => $vv){
          $tmp = array();
          foreach($vv as $kkk => $vvv){
            foreach($vvv as $kkkk => $vvvv){

              if($kkk == 'http'){
                $vd = explode(': ',$vvvv);
                if(isset($vd[1])){
                  $tmp[] = array(
                    'k' => $kk.'.'.$kkk.'.'.$vd[0],
                    'v' => $vd[1]
                  );
                }else{
                  $tmp[] = array(
                    'k' => $kk.'.'.$kkk.'.'.$vd[0],
                    'v' => ''
                  );
                }
              }else{
                $tmp[] = array(
                  'k' => $kk.'.'.$kkk,
                  'v' => $vvvv
                );
              }
            }
          }
          $this->_echoline();
          $this->_echo($tmp);
          echo "\n";
          
        }
      }
    }
    $this->_echoline('#');
    $this->_echoline('#');
    $this->_echoline('#');
  }

/////////////////////////////////////////////////////
  public function clearRetData(){
    $this->retData = array();
    $this->tmpTrx  = array();
  }
/////////////////////////////////////////////////////
  public function echoBackendHealthy(){
    $tmp = array(array('k'=>'name','v'=>'status  | past status'));
    foreach($this->backendHealthy as $k=>$v){
      $txt = '';
      foreach($v as $vv){
        if($vv['healthy']){
          $txt.='Y';
        }else{
          $txt.='-';
        }
      }
      $kt='sick    | ';
      if($v[0]['healthy'])
        $kt='healthy | ';
      $tmp[] = array(
            'k' => $k,
            'v' => $kt.$txt
      );
    }
    $this->_echoline('#');
    echo "backend status\n";
    $this->_echoline('-');
    $this->_echo($tmp);
  }
/////////////////////////////////////////////////////
  public function addStatus($raw){
    if(!$raw) return false;
    $n    = $raw['trx'];
    $tag  = $raw['tag'];
    switch($tag){
      case 'Backend_health':
        $t  = explode(' ',$raw['msg']);
//               0      1     2     3     4 5 6    7      8      
//  string(72) "apache Still sick 4--X-R- 0 3 8 0.000562 0.000000 HTTP/1.1 404 Not Found"
        $ta = array(
          'backend'       => $t[0],
          'state'         => $t[1] . ' ' . $t[2],
          'flag'          => $t[3],
          'good'          => $t[4],
          'thr'           => $t[5],
          'window'        => $t[6],
          'resp'          => $t[7],
          'goodrespavg'   => $t[8],
          'status'        => implode(' ' , array_slice($t,9)),
          'healthy'       => false,
        );
        if('Still healthy' == $ta['state'] ||
           'Back healthy'  == $ta['state'])
          $ta['healthy'] = true;
        
        if(!isset($this->backendHealthy[$ta['backend']])) $this->backendHealthy[$ta['backend']] = array();
        $val = &$this->backendHealthy[$ta['backend']];
        array_unshift($val , $ta);
        if(count($val) > $ta['window'] * 4){
          array_pop($val);
        }
        break;
    }
  }
/////////////////////////////////////////////////////
  public function addTrx($raw){
    if(!$raw) return false;
    $n    = $raw['trx'];
    $tag  = $raw['tag'];
    if(!isset($this->tmpTrx[$n]) && ($tag == 'ReqStart' || $tag == 'BackendOpen'))
      $this->tmpTrx[$n] = array();
    if(!isset($this->tmpTrx[$n]))
      return false;
    $sess = &$this->tmpTrx[$n];
    $t = explode(' ',$raw['msg']);

    if(count($sess)>0)
      $req = &$sess[count($sess)-1];
      
    switch($raw['tag']){
      //バックエンド系
      case 'BackendOpen':
        $sess[] = array(
          'count'      =>0,
          'countinfo'  =>array(),
          'info'       =>array(),
          'var'        =>array(),
          'raw'        =>array(),
        );
        $req = &$sess[count($sess)-1];
        $req['info']['backend.name']   =$t[0];
        $req['info']['backend.server'] =$t[3].':'.$t[4];
        break;
      case 'BackendClose':
        break;
      //リクエスト系
      case 'ReqStart':
        $sess[] = array(
          'count'      =>0,
          'restartflg' =>false,
          'recvcount'  =>0,
          'countinfo'  =>array(),
          'info'       =>array(),
          'backend'    =>array(),
          'var'        =>array(),
          'call'       =>array(),
          'raw'        =>array(),
          'hash'       =>array(),
        );
        unset($req);
        $req = &$sess[count($sess)-1];
        $req['var'][0]['client']['ip']   = array($t[0]);
        break;
      case 'Length':
        $req['info']['length'][] = $raw['msg'];
        break;
      case 'ReqEnd':
        //コミット
        if($t[3] != 'nan' && $t[3]>0)
          $req['info']['time.accept']  =$t[3];
        if($t[4] != 'nan' && $t[4]>0)
          $req['info']['time.execute'] =$t[4];
        if($t[5] != 'nan' && $t[5]>0)
          $req['info']['time.exit']    =$t[5];

        $req['info']['time.total']     =$t[2]-$t[1];

        $this->retData[] = $req;
        unset($sess[count($sess)-1]);
        if(count($sess) == 0)
          unset($sess);
        return true;
        break;
      case 'Backend':
        $btmp = array_shift($this->tmpTrx[$t[0]]);
        $req['backend'][] = $btmp;
        foreach($btmp['var'][0] as $k => $v){
          $req['var'][$req['count']][$k] = $v;
        }
        break;
      case 'VCL_call':
        $call_cnt=count($t);
        $ret = $t[$call_cnt - 1];
        $method = $t[0];
        if($method == 'recv'){
          if($req['restartflg']){
            $req['restartflg'] = false;
          }else{
            $req['recvcount']++;
            if($req['recvcount']>1){
              $req['count']++;
              $req['countinfo'][$req['count']] = 'esi';
            }
          }
        }
        $tmp = array();
        $tmp['method']      = $method;
        $tmp['count']       = $req['count'];
        $tmp['trace']       = array();
        if($call_cnt>2){
          //12 VCL_call     c recv 1 8.1 3 16.1 8 41.5 9 42.9 11 46.13 12 49.5 14 59.5 16 63.5 18 67.5 lookup
          $cmax           = $call_cnt-1;
          $vrtcount       = 0;
          $vrtline        = 0;
          $vrtpos         = 0;
          if(!isset($tmp['trace']))
            $tmp['trace'] = array();
          for($x=1 ; $x < $cmax ; $x++){
            if(0!=($x % 2)){
              $vrtcount = $t[$x];
            }else{
              $tt = explode('.',$t[$x]);
              $trace              = array();
              $trace['type']      = 'trace';
              $trace['vrt_count'] = $vrtcount;
              $trace['vcl_line']  = $tt[0];
              $trace['vcl_pos']   = $tt[1];
              $trace['count']     = $req['count'];
              $tmp['trace'][]     = $trace;
            }
          }
/*
          $tt                 = explode('.',$t[2]);
          $tmp['trace'][]     = array();
          $trace              = &$tmp['trace'][0];
          $trace['type']      = 'trace';
          $trace['vrt_count'] = $t[1];
          $trace['vcl_line']  = $tt[0];
          $trace['vcl_pos']   = $tt[1];
          $trace['count']     = $req['count'];
*/
        }

        $tmp['return']  =$ret;
        if($ret == 'restart'){
          $req['count']++;
          $req['restartflg'] = true;
          $req['countinfo'][$req['count']] = 'restart';
        }

        $req['call'][]    =$tmp;
        break;
      case 'VCL_return':
        $req['call'][count($req['call'])-1]['return']  =$t[0];
        break;
      case 'VCL_trace':
        $tt = explode('.',$t[1]);
        $req['call'][count($req['call'])-1]['trace'][] = array(
          'type'    =>'trace',
          'vrt_count' =>$t[0],
          'vcl_line'  =>$tt[0],
          'vcl_pos'   =>$tt[1],
          'count'     =>$req['count'],
        );
        break;
      case 'VCL_Log':
        $req['call'][count($req['call'])-1]['trace'][] = array(
          'type'    =>'log',
          'data'    =>$raw['msg'],
        );
        break;
      case 'Hash':
        $req['hash'][$req['count']][] = $raw['msg'];
        break;
    }


    //var
    if(!is_null($raw['varkey'])){
      $req['var'][$req['count']][$raw['var']][$raw['varkey']][] = $raw['msg'];

    }
  }


  //1行解釈
/////////////////////////////////////////////////////
  public function rawDecode($s){
    //形式チェック
    if(!preg_match('/ +([^ ]+) +([^ ]+) +([^ ]+) +(.*)/',$s,$m)) return false;

    //テンポラリ代入
    $tmp = array();
    $tmp['trx']    =$m[1];
    $tmp['tag']    =$m[2];
    $tmp['tg']     =$m[3];
    $tmp['msg']    =$m[4];
    $tmp['var']    =null;
    $tmp['varkey'] =null;

    if($tmp['trx'] == '0'){
      if($tmp['tag'] == 'Backend_health')
        return $tmp;
      return false;
    }
    //変数の場合特定する
    $rxtx  =substr($tmp['tag'],0,2);
    if($rxtx == 'Tx' || $rxtx == 'Rx' || $rxtx == 'Ob'){
      $tmpk = '';
      foreach($this->tagVar as $k => $v){
        if(false !== strpos($tmp['tg'] . $tmp['tag'],$k)){
          $tmp['var'] = $v;
          $tmpk = substr($tmp['tag'],strlen($k)-1);
          if(array_key_exists($tmpk,$this->tagRep)){
            $tmp['varkey'] = $this->tagRep[$tmpk];
          }else{
            $tmp['varkey'] = $tmpk;
          }
          break;
        }
      }

    }
    return $tmp;
  }
  
  
  
  
}






main();
