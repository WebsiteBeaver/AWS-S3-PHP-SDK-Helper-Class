<?php
require 'aws/aws-autoloader.php'; //path to autoloader.php
require '../s3_config.php'; //file to be created outside the root to store aws key + secret
use Aws\S3\S3Client;
class S3 {
  private $bucket;
  private $client;
  public function __construct($bucket, $region) {
    $this->bucket = $bucket;
    $this->client = new S3Client([
      'version'   => 'latest',
      'region'    => $region,
      'http'    => [
          'verify' => false
      ],
      'credentials' => [
        'key'       => AWS_KEY,
        'secret'    => AWS_SECRET
      ]
    ]);
    if(!$this->client->doesBucketExist($bucket)) 
      throw new Exception('Bucket does not exist.');
  }
  public function uploadFile($path, $tmpFile, $contentType) {
    $client = $this->client;
    $bucket = $this->bucket;
    if($client->doesObjectExist($bucket, $path)) { 
      echo "File: '$path' already exists. This is to prevent overiding it.";
      return false;
    }
    //upload file
    $client->putObject(array(
      'Bucket' => $bucket,
      'Key'    => $path, //name in s3
      'Body'   => fopen($tmpFile, 'r+'),
      'ContentType' => $contentType
    ));
    if($client->doesObjectExist($bucket, $path)) return true;
    else { 
      echo "File: '$path' failed to upload";
      return false;
    }
  }
  public function downloadFile($path) {
    $client = $this->client;
    $bucket = $this->bucket;
    $filename = substr(strrchr($path, '/' ), 1);
    if(!$client->doesObjectExist($bucket, $path)) {
      echo "File: '$path' does not exist";
      return false;
    }
    $cmd = $client->getCommand('GetObject', [
      'Bucket' => $bucket,
      'Key'    => $path,
      'ResponseContentDisposition' => "attachment; filename='$filename'"
    ]);
    $request = $client->createPresignedRequest($cmd, '+20 minutes');
    $presignedUrl = (string) $request->getUri();
    header('Location: ' . $presignedUrl);
    return true;
  }
  public function downloadFolder($dir) {
    if($this->downloadBucket($dir)) return true;
    else return false;
  }
  public function downloadBucket($dir = NULL) {
    $client = $this->client;
    $bucket = $this->bucket;
    //check to see directory exists at all; i.e. if at most one key exists
    if(!empty($dir)) {
      $results = $client->getPaginator('ListObjects', array(
        'Bucket' => $bucket,
        'Prefix' => "$dir/",
        'MaxKeys' => 1
      ));
      foreach ($results as $result) {
        if(empty($result['Contents'])) {
          echo "Folder: '$dir/' does not exist.";
          return false;
        }
      }
      //account for subfolders
      if(strstr($dir, '/')) $dirForZip = substr(strrchr($dir, '/' ), 1);
      else $dirForZip = $dir;
    }
    $source = (!empty($dir) ? "s3://$bucket/$dir" : "s3://$bucket"); //full bucket or just a folder to download
    $nameOfZipFile = (!empty($dir) ? $dirForZip : $bucket);
    $dest = sys_get_temp_dir() . "/tmp-folder-s3-files"; //creates folder in tmp directory
    if(!mkdir($dest, 0775)) {
      echo "Error creating temporary folder on server: '$dest'";
      return false;
    }
    $manager = new \Aws\S3\Transfer($client, $source, $dest);
    $manager->transfer();
    //creates a .tar.gz file to download automatically
    $a = new PharData("$dest/$nameOfZipFile.tar");
    $a->buildFromDirectory($dest);
    $a->compress(Phar::GZ);
    //set content stuff in order to download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize("$dest/$nameOfZipFile.tar.gz"));
    header("Content-Disposition: attachment; filename=$nameOfZipFile.tar.gz");
    readfile("$dest/$nameOfZipFile.tar.gz");
    //remove temporary folder and its contents created in the server; BE CAREFUL, COULD BE DANGEROUS OPERATION
    $files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dest, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
      $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
      $todo($fileinfo->getRealPath());
    }
    if(rmdir($dest)) return true;
    else {
      echo "Error removing temporary folder on server: '$dest'";
      return false;
    }
  }
  public function deleteFile($path) {
    $client = $this->client;
    $bucket = $this->bucket;
    if(!$client->doesObjectExist($bucket, $path)) {
      echo "File: '$path' does not exist";
      return false;
    }
    $client->deleteObject(array(
      'Bucket' => $bucket,
      'Key'    => $path
    ));
    if(!$client->doesObjectExist($bucket, $path)) return true;
    else { 
      echo "Error deleting: '$path'";
      return false;
    }
  }
  public function deleteFolder($dir) {
    $client = $this->client;
    $bucket = $this->bucket;
    $allFilesDeletedSuccess = true;
    $results = $client->getPaginator('ListObjects', array(
      'Bucket' => $bucket,
      'Prefix' => "$dir/"
    ));
    foreach ($results as $result) {
      if(empty($result['Contents'])) {
        echo "Folder: '$dir/' does not exist.";
        return false;
      }
      foreach ($result['Contents'] as $object) {
        if(!$this->deleteFile($object['Key'])) $allFilesDeletedSuccess = false;
      }
    }
    if($allFilesDeletedSuccess) return true;
    else return false;
  }
  public function renameFile($oldName, $newName) {
    $client = $this->client;
    $bucket = $this->bucket;
    if(!$client->doesObjectExist($bucket, $oldName)) {
      echo "File: '$oldName' does not exist";
      return false;
    }
    if($oldName == $newName) {
      echo "New name is same as old name on File: '$oldName'";
      return false;
    }
    if($client->doesObjectExist($bucket, $newName)) {
      echo nl2br("File: '$newName' already exists. This is to prevent overiding it. \n");
      return false;
    }
    $client->copyObject(array(
      'Bucket'     => $bucket,
      'Key'        => $newName, //new file name
      'CopySource' => "$bucket/$oldName" //old file name
    ));
    //check if object exists, delete file and confirm that it is successful
    if($client->doesObjectExist($bucket, $newName) && $this->deleteFile($oldName))
      return true;
    else {
      echo "Failed to rename File: '$oldName' to '$newName'";
      return false;
    }
  }
  public function renameFolder($oldName, $newName) {
    $client = $this->client;
    $bucket = $this->bucket;
    $allFilesRenamedSuccess = true;
    if($oldName == $newName) {
      echo "New name is same as old name on File: '$oldName'";
      return false;
    }
    $results = $client->getPaginator('ListObjects', array(
      'Bucket' => $bucket,
      'Prefix' => "$oldName/"
    ));
    foreach ($results as $result) {
      if(empty($result['Contents'])) {
        echo "Folder: '$oldName/' does not exist.";
        return false;
      }
      foreach ($result['Contents'] as $object) {
        $newNameFull = str_replace($oldName, $newName, $object['Key']);
        //rename file, set variable to false if unsuccessful for even one file
        if(!$this->renameFile($object['Key'], $newNameFull)) $allFilesRenamedSuccess = false;
      }
    }
    if($allFilesRenamedSuccess) return true;
    else return false;
  }
}
?>