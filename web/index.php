<?php
/**
 * Trivial server app for CogimixCustomMusicProvider
 */
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__.'/../vendor/autoload.php';
require_once 'config.php';

$app = new Silex\Application();


$app['dirs_to_scan'] = $pathsToMusic;
$app['authType'] = $authType;
$app['users'] = $users;
$app['debug'] = false;
$app['restricted_path']=array('/ping','/search','/install');

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
        'db.options' => array(
                'driver'   => 'pdo_sqlite',
                'path'     => __DIR__.'/../db/cogimix.db',
        ),
));


if($exposeInstallPath === true){
    $app->get('/install', function (Silex\Application $app,Request $request)  {
        $createTableTracks="CREATE TABLE `tracks` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL ,
        `title` varchar(255) DEFAULT NULL,
        `artist` varchar(255) DEFAULT NULL,
        `album` varchar(255) DEFAULT NULL,
        `filepath` text
        )";

        $app['db']->exec($createTableTracks);
        $timeStart=microtime(true);
        $count=crawl($app);
        $timeEnd=microtime(true);
        return 'Success ! '.$count.' in '.($timeEnd-$timeStart);
    });
}

$app->before(function (Request $request) use($app) {

    if(in_array($request->getPathInfo(), $app['restricted_path']) && $app['authType']!=='none'){
        // code from http://chemicaloliver.net/programming/http-basic-auth-in-silex/
        if( $app['authType']==='basic'){
            if (!isset($_SERVER['PHP_AUTH_USER'])){
                header('WWW-Authenticate: Basic realm="Cogimix"');
                return $app->json(array('Message' => 'Not Authorised'), 401);
            }
            else
            {
                if($app['users'][$_SERVER['PHP_AUTH_USER']] !== $_SERVER['PHP_AUTH_PW']){
                    return $app->json(array('Message' => 'Forbidden'), 403);
                }
            }
        }
        if($app['authType']==='digest'){
            $realm = 'Cogimix';
            $nonce = sha1(uniqid());
            $digest = getDigest();
            if (is_null($digest)) requireLogin($realm,$nonce);
            $digestParts = digestParse($digest);

            if(array_key_exists($digestParts['username'],$app['users'])){
                $A1 = md5("{$digestParts['username']}:{$realm}:{$app['users'][$digestParts['username']]}");
                $A2 = md5("{$_SERVER['REQUEST_METHOD']}:{$digestParts['uri']}");
                $validResponse = md5("{$A1}:{$digestParts['nonce']}:{$digestParts['nc']}:{$digestParts['cnonce']}:{$digestParts['qop']}:{$A2}");
                if ($digestParts['response']!=$validResponse) requireLogin($realm,$nonce);
            }else{
                requireLogin($realm,$nonce);
            }
        }
    }

    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {

        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});




$app->post('/search', function (Silex\Application $app,Request $request)  {

    $searchQuery = $request->request->get('song_query');

    $tracks=$app['db']->fetchAll("SELECT * FROM tracks WHERE title LIKE ? OR artist LIKE ? OR album LIKE ?",array('%'.$searchQuery.'%','%'.$searchQuery.'%','%'.$searchQuery.'%'));

    return $app->json($tracks);
});

$app->get('/ping',function(Silex\Application $app,Request $request){
    $count=$app['db']->fetchAssoc("SELECT count(*) as count FROM tracks");

    return $app->json($count);
});

$app->get('/get/{id}',function(Silex\Application $app,Request $request,$id){
    $track= $app['db']->fetchAssoc("SELECT * FROM tracks WHERE id = ?",array($id));
     if($track){
         $file = $track['filepath'];
         if (!file_exists($file)) {
             return $app->abort(404, 'The track was not found.');
         }

         return $app->sendFile($file, 200);
     }

});
/*
$app->get('/scan',function(Silex\Application $app,Request $request){

    crawl($app['dir_to_scan']);
});
*/


function crawl($app){
   set_time_limit(0);
   $count=0;
   foreach ($app['dirs_to_scan'] as $dir){
   $it = new RecursiveDirectoryIterator($dir);
   $id3= new getID3();
   $i=0;
   $app['db']->beginTransaction();
    foreach(new RecursiveIteratorIterator($it) as $file) {
        $title=null;
        $artist=null;
        $album=null;

        try{

           $tag=$id3->analyze($file);

           if( !empty($tag) && !isset($tag['error'])){
               $count++;
               getid3_lib::CopyTagsToComments($tag);

               if(isset($tag['comments'])){
                        if(isset($tag['comments']['album']) && $album ==null){
                            if(is_array($tag['comments']['album']) && !empty($tag['comments']['album'])){
                                $album =$tag['comments']['album'][0];
                            }else{
                                $album =$tag['comments']['album'];
                            }
                        }

                        if(isset($tag['comments']['title']) && $title ==null){
                        if(is_array($tag['comments']['title']) && !empty($tag['comments']['title'])){
                                $title =$tag['comments']['title'][0];
                            }else{
                                $title =$tag['comments']['title'];
                            }
                        }

                        if(isset($tag['comments']['artist']) && $artist ==null){
                            if(is_array($tag['comments']['artist']) && !empty($tag['comments']['artist'])){
                                $artist =$tag['comments']['artist'][0];
                            }else{
                                $artist =$tag['comments']['artist'];
                            }

                        }
                       // var_dump($tag['comments']);die();


                    //we maybe have our information (or not)
                   echo $file.' '.$album.' '.$title.' '.$artist."<br />";
                   $app['db']->insert('tracks',array('title'=>$title,'album'=>$album,'artist'=>$artist,'filepath'=>$file));
               }
           }
           //getid3_lib::CopyTagsToComments($tag);
        }catch (\Exception $ex){
            echo 'error '.$ex->getMessage();
        }
        $i++;
        if($i % 100 == 0 ){
            $app['db']->commit();
            $app['db']->beginTransaction();
        }
    }
    $app['db']->commit();
   }
   return $count;
}

// This function returns the digest string
function getDigest() {
    $digest=null;
    // mod_php
    if (isset($_SERVER['PHP_AUTH_DIGEST'])) {
        $digest = $_SERVER['PHP_AUTH_DIGEST'];
        // most other servers
    } elseif (isset($_SERVER['HTTP_AUTHENTICATION'])) {

        if (strpos(strtolower($_SERVER['HTTP_AUTHENTICATION']),'digest')===0)
            $digest = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
    }

    return $digest;

}

// This function forces a login prompt
function requireLogin($realm,$nonce) {
    header('WWW-Authenticate: Digest realm="' . $realm . '",qop="auth",nonce="' . $nonce . '",opaque="' . md5($realm) . '"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Text to send if user hits Cancel button';
    die();
}

// This function extracts the separate values from the digest string
function digestParse($digest) {
    // protect against missing data
    $needed_parts = array('nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 'username'=>1, 'uri'=>1, 'response'=>1);
    $data = array();

    preg_match_all('@(\w+)=(?:(?:")([^"]+)"|([^\s,$]+))@', $digest, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $data[$m[1]] = $m[2] ? $m[2] : $m[3];
        unset($needed_parts[$m[1]]);
    }

    return $needed_parts ? false : $data;
}
$app->run();
