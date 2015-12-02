<?php
use Location\Coordinate;
use Location\Distance\Vincenty;

class Coturn {
    /**
     * @SWG\Get(
     *     path="/turn",
     *     summary="Request for stun/turn time limited long term credential",
     *     tags={"rest api"},
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *         description="username fragement, any desired application data",
     *         in="query",
     *         name="ufrag",
     *         required=false,
     *         type="string",
     *         maxLength=25
     *     ),
     *     @SWG\Parameter(
     *         description="realm, the domain of the shared secret, default=lab.vvc.niif.hu",
     *         in="query",
     *         name="realm",
     *         required=false,
     *         type="string",
     *         maxLength=254,
     *     ),
     *     @SWG\Parameter(
     *         description="client browser IPv4/IPv6 Address",
     *         in="query",
     *         name="ip",
     *         required=false,
     *         type="string",
     *         maxLength=45
     *     ),
     *     @SWG\Response(
     *       response="200",
     *       description="STUN time limited credentials",
     *       @SWG\Schema(
     *         ref="#/definitions/ApiResponse"
     *       ),
     *     ),
     *     security={
     *          {
     *       		"api_key":{ }
     *          }
     *     }
     * )
     */
    /**
     * @SWG\Get(
     *     path="/stun",
     *     summary="Request for stun/turn time limited long term credential",
     *     tags={"rest api"},
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *         description="username fragement, any desired application data",
     *         in="query",
     *         name="ufrag",
     *         required=false,
     *         type="string",
     *         maxLength=25
     *     ),
     *     @SWG\Parameter(
     *         description="realm, the domain of the shared secret, default=lab.vvc.niif.hu",
     *         in="query",
     *         name="realm",
     *         required=false,
     *         type="string",
     *         maxLength=254,
     *     ),
     *     @SWG\Parameter(
     *         description="client browser IPv4/IPv6 Address",
     *         in="query",
     *         name="ip",
     *         required=false,
     *         type="string",
     *         maxLength=45
     *     ),
     *     @SWG\Response(
     *       response="200",
     *       description="STUN time limited credentials",
     *       @SWG\Schema(
     *         ref="#/definitions/ApiResponse"
     *       )
     *     ),
     *     security={
     *          {
     *       		"api_key":{ }
     *          }
     *     }
     * )
     */
    public function Get() {

      $app = \Slim\Slim::getInstance();

      //default realm
      $realm="lab.vvc.niif.hu";

      try 
      {
         define("MAXSERVERS", 10);

         //connectdb
         $db = Db::Connection();

         /// TOKEN IS A PARAM VARIABLE
         $token=$app->request->params('api_key');
         /// TOKEN IS A HEADER VARIABLE
         //$app->request->headers('api_key')  
         //TODO validate token:
         $sth = $db->prepare("SELECT count(*) AS count FROM token where token='$token'");
         $sth->execute();
         $result = $sth->fetchAll(PDO::FETCH_ASSOC);
         if ($result[0]["count"]==1){
         // response
         $response=new ApiResponse();
         $response->ttl=86400;
         $response->username=(time() + $response->ttl).":".$app->request->params('ufrag');

         //update not existing lat long in server table
         $sth = $db->prepare("SELECT id,ip FROM servers where latitude IS NULL OR longitude IS NULL");
         $sth->execute();
         $result = $sth->fetchAll(PDO::FETCH_ASSOC);
         foreach ($result as $row => $columns) {
            $location=$this->GetGeoIP($columns['ip']);
            $sth2 = $db->prepare("UPDATE servers SET latitude=$location->latitude, longitude=$location->longitude WHERE id=$columns[id]");
            $sth2->execute();
         }

         $uris=array();
         //check if ip presents
         if ($app->request->params('ip')){ 
             $location=$this->GetGeoIP ($app->request->params('ip'));
             //TODO: geoip code comes here
             $client_coordinate = new Coordinate($location->latitude, $location->longitude); 


             $sth = $db->prepare("SELECT id,latitude,longitude FROM servers");
             $sth->execute();
             $result = $sth->fetchAll(PDO::FETCH_ASSOC);
             foreach ($result as $row => $columns) {
                 $server_coordinate = new Coordinate($columns['latitude'],$columns['longitude']);
                 $servers[$columns['id']]=$client_coordinate->getDistance($server_coordinate, new Vincenty()); 
             }
             asort($servers);
             $i=0;
             foreach ($servers as $id => $distance) {
                 $sth2 = $db->prepare("SELECT * FROM servers WHERE id='".$id."'");
                 $sth2->execute();
                 $result2 = $sth2->fetchAll(PDO::FETCH_ASSOC);
                 foreach ($result2 as $row2 => $columns2) {
                    $uri=$columns2["uri_schema"].':'.$columns2["ip"].':'.$columns2["port"].'?'.'transport='.$columns2["protocol"].'&distance='.$distance;
                    array_push($uris,$uri);
                 }
                 $i++;
 		 if ($i>=MAXSERVERS){
                     break;
                 }
             }
             
         } else {
             $sth = $db->prepare("SELECT distinct ip FROM servers ORDER BY RAND() limit MAXSERVERS");
             $sth->execute();
             $result = $sth->fetchAll(PDO::FETCH_ASSOC);
             foreach ($result as $row => $columns) {
                 //add turnserver
                 $sth2 = $db->prepare("SELECT * FROM servers WHERE ip='".$columns["ip"]."'");
                 $sth2->execute();
                 $result2 = $sth2->fetchAll(PDO::FETCH_ASSOC);
                 foreach ($result2 as $row2 => $columns2) {
                    $uri=$columns2["uri_schema"].':'.$columns2["ip"].':'.$columns2["port"].'?'.'transport='.$columns2["protocol"];
                    array_push($uris,$uri);
                 }
              }
         }

         //implode uris
         $response->uris=$uris;
         //check if realm presents
         if ($app->request->params('realm')){ 
             $realm=$app->request->params('realm');
         }


         $sth = $db->prepare("SELECT value FROM turn_secret where realm='$realm' ORDER BY timestamp DESC limit 1");
         $sth->execute();
         $sharedsecret = $sth->fetchColumn();
         if($sharedsecret) {
            $response->password=base64_encode(hash_hmac("sha1",$response->username,$sharedsecret,true));
            $app->response->setStatus(200);
            $app->response()->headers->set('Content-Type', 'application/json');
            echo json_encode($response);
            $db = null;
         } else {
             throw new PDOException('No records found.');
         }


        } else {
          $app->response->setStatus(403);
          $app->response()->headers->set('Content-Type', 'application/json');
          echo '{"error":{"text": "Invalid api_key" }}';
        }
 
      } catch(PDOException $e) {
          $app->response()->setStatus(500);
          $app->response()->headers->set('Content-Type', 'application/json');
          echo '{"error":{"text": "'. $e->getMessage() .'"}}';
      }
    }



    private function GetGeoIP ($ip) {
      $database = (strpos($ip, ":") === false) ? "GeoLiteCity.dat" : "GeoLiteCityv6.dat";
      $gi = geoip_open("/usr/local/share/GeoIP/$database",GEOIP_STANDARD);
      if((strpos($ip, ":") === false)) {
          //ipv4
          $record = geoip_record_by_addr($gi, $ip);
      }
      else {
          //ipv6
          $record = geoip_record_by_addr_v6($gi, $ip);
      }
      return $record;
    }

}
