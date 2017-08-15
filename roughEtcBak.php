#!/usr/bin/php
<?php
//Facilitates selectively backing-up and restoring files. Maybe useful for /etc files.
//Basic workflow:
//1. You specify files/directories to backup. This script gathers them up as symlinks under current directory.
//2. You backup them as well as this script, maybe to another host, likely using rsync.
//3. When you need to restore them from backup, whether in DR situation or to build a standby back-up site, run this script with -r.
//   It shows you the differences between the backup files and corresponding current files,
//   and lets you decide whether to add/overwrite each individual file.
//   When overwriting, the current file will first be backed-up, just in case.

if(posix_getuid() != 0) {
  echo 'Without sudo, operation might all or partly fail because of inadequate permission.'.PHP_EOL;
  echo 'Press ENTER to continue, or any other key to abort: ';
  $inputChar = strtoupper(STDINchar(true));
  if($inputChar != "\n") exit(PHP_EOL);
}
$exeName = pathinfo($argv[0], PATHINFO_FILENAME);

//Usage syntax for centralizing files to backup: sudo php roughEtcBak.php
//Making corresponding subdirectories and symlinks under current directory
//according to a file containing list of paths (one in a line) to which to make symlinks.
//The list file defaults to the file having same name as this script but with ".txt" extension name in current directory.
//You can specify list file to use with -l argument.
if(!in_array('-r', $argv)) {
  $listFile =  "{$exeName}.txt";
  $argvListFile = argvOfSpecificOption('-l');
  if($argvListFile !== NULL && is_readable($argvListFile) && !is_dir($argvListFile))
    $listFile = $argvListFile;
  $replaceFrom = '/';
  $argvReplaceFrom = argvOfSpecificOption('-rf');
  if($argvReplaceFrom !== NULL) $replaceFrom = $argvReplaceFrom;
  $replaceTo = './';
  $argvReplaceTo = argvOfSpecificOption('-rt');
  if($argvReplaceTo !== NULL) $replaceTo = $argvReplaceTo;
  
  foreach(file($listFile) as $origPath) {
    $origPath = trim($origPath); if($origPath==='') continue;
    if(strpos($origPath, $replaceFrom) !== 0) { echo "$origPath doesn't start with $replaceFrom therefore ignored.\n"; continue; }
    if(!file_exists($origPath) && !is_link($origPath)) { echo "$origPath doesn't exist therefore ignored.\n"; continue; }
    $newDirPath = dirname(substr_replace($origPath, $replaceTo, 0, strlen($replaceFrom)));
    $newDirPathEsc = escapeshellarg($newDirPath); $origPathEsc = escapeshellarg($origPath);
    system("mkdir -p $newDirPathEsc");
    $lnCmd = "ln -s $origPathEsc $newDirPathEsc";
    echo "COMMAND: $lnCmd\n"; system($lnCmd);
  }
  echo "\nHINT: With necessary symlinks built, \"sudo rsync -a --copy-unsafe-links\" can be used to actually make backup.\n";
} else {
//Usage syntax for restoring from backup: sudo php roughEtcBak.php -r
//Comparing each file under specific subdirectories, which are supposed to be the backup you made from above, to its counterpart in the current filesystem.
//If a pair differ in between, showing the output of "ls -l" and "diff" (current file first, followed by the backup) to help you decide.
//Then copying the backup to overwrite corresponding current file or to add if no such file exists, after asking for confirmation.
//Overwritten files will be backed-up under /tmp.
//By default this only processes "etc" subdirectory of current directory.
//You can specify subdirectorie(s) to process after -r argument. You only need to specify the top level subdirectory.
  $srcDirs = [ 'etc' ];
  $argvSrcDirs = argvOfSpecificOption('-r', false);
  if($argvSrcDirs !== NULL) $srcDirs = $argvSrcDirs;
  $dstBaseDir = '';
  $argvDstBaseDir = argvOfSpecificOption('-b');
  if($argvDstBaseDir !== NULL) $dstBaseDir = $argvDstBaseDir;
  if(substr($dstBaseDir, -1) != '/') $dstBaseDir .= '/';
  $bakDstRoot = sys_get_temp_dir() ."/$exeName/". date('YmdHis');
  
  foreach($srcDirs as $srcDir) {
    if(substr($srcDir, 0, 1)=='/') { echo "$srcDir : Absolute path not supported.\n"; continue; }
    $srcDirEsc = escapeshellarg($srcDir);
    foreach(explode(PHP_EOL, `find {$srcDirEsc} -type f -o -type l | sort`) as $src) {
      $src = trim($src); if($src==='') continue;
      $dst = $dstBaseDir.$src;
      if(is_link($src)) { //If restoration source is a symlink, only copy it if no corresponding destination file. Do nothing in all other cases.
        if(is_link($dst)) {
          if(readlink($src)==readlink($dst)) $result[$src] = doItOrNot($src, $dst, 'Ignoring', 'Identical');
          else $result[$src] = doItOrNot($src, $dst, 'Ignoring', 'Conflict: both are symlinks but refer to different targets.');
        } else {
          if(file_exists($dst)) $result[$src] = doItOrNot($src, $dst, 'Ignoring', 'Conflict: restoration source is symlink but corresponding destination path is not.');
          else $result[$src] = doItOrNot($src, $dst, 'Adding');
        }
      } else { //Restoration source is regular file, not symlink.
        if(!file_exists($dst)) { //Corresponding destination file doesn't exist, or is a symlink to non-existing file.
          $dst = readlinkToEnd($dst);  //In case it's symlink, find out its target.
          $result[$src] = doItOrNot($src, $dst, 'Adding');
        } else {
          $dst = realpath($dst); //Locate destination file for absolute path.
          if(is_file($dst)) {
            if(!filesIdentical($src, $dst)) {
              $srcEsc = escapeshellarg($src); $dstEsc = escapeshellarg($dst);
              echo PHP_EOL;
              system("ls -l $dstEsc"); system("ls -l $srcEsc"); system("diff $dstEsc $srcEsc");
              $result[$src] = doItOrNot($src, $dst, 'Overwriting');
            } else $result[$src] = doItOrNot($src, $dst, 'Ignoring', 'Identical');
          } else $result[$src] = doItOrNot($src, $dst, 'Ignoring', 'Conflict: the corresponding destination path is a directory.');
        }
      }
    }
  }
  if(!empty($result)) {
    $anythingOverwritten = false;
    echo "\nSummary:\n";
    foreach($result as $src => $logItem) {
      if($logItem['ACTION']=='Ignoring' && $logItem['DECISION']=='Identical') continue;
      else if($logItem['ACTION']=='Ignoring') echo "$src => {$logItem['DECISION']}\n";
      else {
        echo "$src => ". ($logItem['DECISION'] ? '' : 'NOT ') . "{$logItem['ACTION']} {$logItem['DESTINATION']} .\n";
        if($logItem['ACTION']=='Overwriting' && $logItem['DECISION']) $anythingOverwritten = true;
      }
    }
    if($anythingOverwritten) echo "There are backup for overwritten files in $bakDstRoot .\n";
  }
}

function doItOrNot($src, $dst, $actDesc, $whyIgnore='') {
  $result = [ 'DESTINATION' => $dst, 'ACTION' => $actDesc ];
  if($actDesc == 'Ignoring') { $result['DECISION'] = $whyIgnore; return $result; }
  
  static $YNAO = '';
  if($YNAO=='A') { $goingToDo = true; }
  else if($YNAO=='O') { $goingToDo = false; }
  else while(true) {
    echo "$actDesc $dst , are you sure? (Yes/No/yes to All/nO to all):";
    $inputChar = strtoupper(STDINchar(true)); echo PHP_EOL;
    if($inputChar=='Y') { $goingToDo = true; break; }
    else if($inputChar=='N') { $goingToDo = false; break; }
    else if($inputChar=='A') { $goingToDo = true; $YNAO = $inputChar; break; }
    else if($inputChar=='O') { $goingToDo = false; $YNAO = $inputChar; break; }
  }
  
  if($goingToDo) {
    $cmd = [ "mkdir -p ". escapeshellarg(dirname($dst)) ];
    if($actDesc=='Overwriting') {
      global $bakDstRoot;
      $bakDstDir = $bakDstRoot . dirname($dst);
      $cmd = [
        "mkdir -p ". escapeshellarg($bakDstDir)
        , "cp -a ". escapeshellarg($dst) ." ". escapeshellarg($bakDstDir)
      ];
    }
    $cmd[] = "cp -a ". escapeshellarg($src) ." ". escapeshellarg($dst);
    foreach($cmd as $singleCmd) system($singleCmd);
  }
  
  $result['DECISION'] = $goingToDo; return $result;
}

function filesIdentical($file1, $file2) {
  if(!file_exists($file1) || !file_exists($file2)) return false;
  $stat1 = stat($file1); $stat2 = stat($file2);
  if(!$stat1 || !$stat2) return false;
  if($stat1['mode'] != $stat2['mode'] || $stat1['uid'] != $stat2['uid'] || $stat1['gid'] != $stat2['gid']) return false;
  $file1Esc = escapeshellarg($file1); $file2Esc = escapeshellarg($file2);
  if(`diff {$file1Esc} {$file2Esc}`) return false;
  return true;
}
function readlinkToEnd($linkFilename) {
  if(!is_link($linkFilename)) return $linkFilename;
  $final = $linkFilename;
  while(true) {
    $target = readlink($final);
    if(substr($target, 0, 1)=='/') $final = $target;
    else $final = dirname($final).'/'.$target;
    if(substr($final, 0, 2)=='./') $final = substr($final, 2);
    if(!is_link($final)) return $final;
  }
}

function argvOfSpecificOption($option, $onlyOne = true) {
  global $argv;
  $iOptionInArgv = array_search($option, $argv);
  if(!$iOptionInArgv) return NULL;
  $searchStart = $iOptionInArgv + 1;
  $values = [];
  for($i=$searchStart; isset($argv[$i]); $i++) {
    $value = trim($argv[$i]);
    if(substr($value, 0, 1)=='-') break;
    if($value==='') continue;
    $values[] = $value;
  }
  if(empty($values)) return NULL;
  return $onlyOne ? $values[0] : $values;
}
function STDINchar($echo = false) { //http://php.net/manual/en/function.fgetc.php#103045
  $echo = $echo ? "" : "-echo";
  $stty_settings = preg_replace("#.*; ?#s", "", stty("--all")); # Get original settings
  stty("cbreak $echo"); # Set new ones
  $c = fgetc(STDIN); # Get character
  stty($stty_settings); # Restore settings
  return $c;
}
function stty($options) {
  exec($cmd = "/bin/stty $options", $output, $el);
  $el AND die("exec($cmd) failed");
  return implode(" ", $output);
}
?>
