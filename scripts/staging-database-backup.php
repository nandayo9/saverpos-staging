<?php
/** Called only after the existing staging environment guard has passed. */
function saverposStagingBackup(array $config, string $directory): array
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private backup directory.');
    chmod($directory, 0700);
    $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    $sql=$directory.'/'.$id.'.sql';$archive=$sql.'.gz';$credentials=$directory.'/'.$id.'.cnf';$errorFile=$directory.'/'.$id.'.error';
    $oldMask=umask(0077);
    try {
        if (($config['driver'] ?? '') !== 'mysql') throw new RuntimeException('Staging deployment backup requires MySQL.');
        $binary=null;
        foreach (['/usr/bin/mysqldump','/usr/local/bin/mysqldump','/usr/bin/mariadb-dump'] as $candidate) if (is_executable($candidate)) {$binary=$candidate;break;}
        if (!$binary) throw new RuntimeException('Database backup utility is unavailable. Migration was not started.');
        $escape=static fn($v)=>'"'.str_replace(["\\",'"',"\n","\r"],["\\\\",'\\"','\\n','\\r'],(string)$v).'"';
        $client="[client]\nuser=".$escape($config['username'])."\npassword=".$escape($config['password'])."\nhost=".$escape($config['host'])."\nport=".(int)($config['port']??3306)."\n";
        if (!empty($config['unix_socket'])) $client.='socket='.$escape($config['unix_socket'])."\n";
        if (file_put_contents($credentials,$client)===false) throw new RuntimeException('Cannot prepare private backup connection.');
        $command=[$binary,'--defaults-extra-file='.$credentials,'--single-transaction','--skip-lock-tables','--hex-blob','--routines','--events','--triggers',(string)$config['database']];
        $process=proc_open($command,[0=>['pipe','r'],1=>['file',$sql,'w'],2=>['file',$errorFile,'w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start database backup.');
        fclose($pipes[0]);$status=proc_close($process);
        if ($status!==0 || !is_file($sql) || filesize($sql)<100) throw new RuntimeException('Database backup failed. Migration was not started; private diagnostic retained.');
        $input=fopen($sql,'rb');$gzip=gzopen($archive,'wb6');
        if (!$input||!$gzip) throw new RuntimeException('Cannot open backup archive.');
        while (!feof($input)) { $chunk=fread($input,1048576);if ($chunk===false||gzwrite($gzip,$chunk)!==strlen($chunk)) throw new RuntimeException('Cannot write backup archive.'); }
        fclose($input);gzclose($gzip);
        $hash=hash_file('sha256',$sql);$verify=hash_init('sha256');$gzip=gzopen($archive,'rb');
        while (!gzeof($gzip)) { $chunk=gzread($gzip,1048576);if($chunk===false)throw new RuntimeException('Cannot verify backup archive.');hash_update($verify,$chunk); }
        gzclose($gzip);
        if (!hash_equals($hash,hash_final($verify))) throw new RuntimeException('Backup archive verification failed.');
        $manifest=['backup_id'=>$id,'created_at'=>gmdate('c'),'database'=>$config['database'],'archive'=>basename($archive),'sql_sha256'=>$hash,'archive_sha256'=>hash_file('sha256',$archive),'restore_test'=>'NOT_RUN_ON_HOST'];
        file_put_contents($directory.'/'.$id.'.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        unlink($sql);if(is_file($errorFile)&&filesize($errorFile)===0)unlink($errorFile);
        return $manifest;
    } finally {
        if(is_file($credentials))unlink($credentials);
        umask($oldMask);
    }
}
