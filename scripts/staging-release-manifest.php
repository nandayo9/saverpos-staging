<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging')) {fwrite(STDERR,"Staging only.\n");exit(1);}
$process=proc_open(['git','rev-parse','HEAD'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
fclose($pipes[0]);$commit=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($process)!==0||!preg_match('/^[a-f0-9]{40}$/D',$commit))exit(1);
$files=[];
foreach(['Modules/Recommerce','app/Console/Commands','scripts'] as $folder){
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder),FilesystemIterator::SKIP_DOTS)) as $file){
  if($file->isFile()&&in_array($file->getExtension(),['php','sh'],true))$files[substr($file->getPathname(),strlen(base_path())+1)]=hash_file('sha256',$file->getPathname());
 }
}
ksort($files);$manifest=['commit'=>$commit,'generated_at'=>gmdate('c'),'files'=>$files];
$path=storage_path('app/tradein-release.json');
if(file_put_contents($path.'.tmp',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)exit(1);
chmod($path.'.tmp',0600);rename($path.'.tmp',$path);
echo 'Staging release manifest '.$commit."\n";
