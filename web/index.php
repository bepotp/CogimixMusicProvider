<?php
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__.'/../vendor/autoload.php';


$app = new Silex\Application();

$app['dir_to_scan']= array( '/path/to/scan/recursively');

$app['debug']=true;

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
        'db.options' => array(
                'driver'   => 'pdo_sqlite',
                'path'     => __DIR__.'/../db/cogimix.db',
        ),
));

/**
 * You have to call this mehtod the first time and comment
 */
$app->get('/install', function (Silex\Application $app,Request $request)  {
    $createTableTracks="CREATE TABLE `tracks` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL ,
    `title` varchar(255) DEFAULT NULL,
    `artist` varchar(255) DEFAULT NULL,
    `album` varchar(255) DEFAULT NULL,
    `filepath` text
    )";

    $app['db']->exec($createTableTracks);

    crawl($app);
    return 'Success !';
});


$app->before(function (Request $request) {

    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {

        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});




$app->post('/search', function (Silex\Application $app,Request $request)  {

    $searchQuery = $request->request->get('song_query');

    $tracks=$app['db']->fetchAll("SELECT * FROM tracks WHERE title LIKE ? OR artist LIKE ?",array('%'.$searchQuery.'%','%'.$searchQuery.'%'));

    return $app->json($tracks);
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
    crawl($app['dir_to_scan']);
});
/*
$app->get('/scan',function(Silex\Application $app,Request $request){

    crawl($app['dir_to_scan']);
});
*/
function crawl($app){
   set_time_limit(0);
   foreach ($app['dir_to_scan'] as $dir){
   $it = new RecursiveDirectoryIterator($dir);
   $id3= new getID3();
    foreach(new RecursiveIteratorIterator($it) as $file) {
        $title=null;
        $artist=null;
        $album=null;
        try{
           $tag=$id3->analyze($file);

           if( !empty($tag) && !isset($tag['error'])){
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

                        //$hash = md5(serialize($tag));
                        echo $file;
                        echo $album.' '.$title.' '.$artist."<br />";
                    $app['db']->insert('tracks',array('title'=>$title,'album'=>$album,'artist'=>$artist,'filepath'=>$file));
               }
           }
           //getid3_lib::CopyTagsToComments($tag);
        }catch (\Exception $ex){
            echo 'error '.$ex->getMessage();
        }


       //echo var_dump($tag);
    }
   }
}

$app->run();