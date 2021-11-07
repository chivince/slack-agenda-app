<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Sabre\VObject;

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

class Agenda {
    protected $url;
    protected $username;
    protected $password;
    protected $localcache;
    
    public function __construct($url, $username, $password, Localcache $localcache) {
        $this->log = new Logger('Agenda');
        $this->log->pushHandler(new StreamHandler('access.log', Logger::DEBUG));
        $this->log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->localcache = $localcache;
        $this->update();
    }

    // init cURL request
    protected function init_curl_request($url = NULL) {
        $ch = curl_init();
        
        if(is_null($url)) {
            curl_setopt($ch, CURLOPT_URL, $this->url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        return $ch;
    }

    function getEvents() {
        $events = array();
        foreach($this->localCache->getEvents() as $eventName => $serializedEvent){
              $vcal = \Sabre\VObject\Reader::read($serializedEvent);
              $startDate = $vcal->VEVENT->DTSTART->getDateTime();

              if($startDate < new DateTime('NOW')) {
                  $this->log->debug("Event is in the past, skiping");
                  continue;
              }
              $events[$eventName] = $vcal;
        }
        return $events;
    }
    
    // update agenda
    protected function update() {
        $remote_ctag = $this->getctag();
        
        // check if we need to update events from the server
        $local_ctag = $this->localcache->getctag();
        $this->log->debug("ctags", ["remote" => $remote_ctag, "local" => $local_ctag]);
        if (is_null($local_ctag) || $local_ctag != $remote_ctag){
            $this->log->debug("Agenda update needed");
            $etags = $this->getetags();
            $this->updateInternalState($etags);
            $this->localcache->setctag($remote_ctag);
        }
    }

    // 
    protected function updateInternalState($etags) {
        $url_to_update = [];
        foreach($etags as $url => $remote_etag) {
            $tmp = explode("/", $url);
            $eventName = end($tmp);
            if($this->localcache->eventExists($eventName)) {
                $local_etag = $this->localcache->getEventEtag($eventName);
                $this->log->debug(end($tmp), ["remote_etag"=>$remote_etag, "local_etag" => $local_etag]);
                
                if($local_etag != $remote_etag) {
                    // local and remote etag differs, need update
                    $url_to_update[] = $url;
                }
            } else {
                $url_to_update[] = $url;
            }
        }
        
        if(count($url_to_update) > 0) {
            $this->updateEvents($url_to_update);
        }
        
        $this->removeDeletedEvents($etags);
    }
    
    // delete local events that have been deleted on the server
    protected function removeDeletedEvents($etags) {
        $urls = [];
        foreach($etags as $url => $etag) {
            $urls[] = basename($url);
        }

        foreach($this->localcache->getAllEventsNames() as $eventName){
            if(in_array($eventName, $urls)) {
                $this->log->debug("No need to remove ". $eventName);
            } else {
                $this->log->info("Need to remove ". $eventName);
                $this->localcache->deleteEvent($eventName);
            }
        }
    }

    // url that need to be updated
    protected function updateEvents($urls) {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8")
        );
        
        $str = "";
        foreach($urls as $url) {
            $str .= "<d:href>".$url."</d:href>\n";
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'        
<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>'.$str.'
</c:calendar-multiget>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        $xml = $service->parse($output);

        foreach($xml as $event) {
            if(isset($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
                $eventName = basename($event['value']['href']);
                $this->log->info("Adding event " . $eventName);

                $this->localcache->deleteEvent($eventName);
                
                
                // parse event to get its DTSTART
                $vcal = \Sabre\VObject\Reader::read($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']);
                $startDate = $vcal->VEVENT->DTSTART->getDateTime();
                
                if($startDate < new DateTime('NOW')) {
                    $this->log->debug("Event is in the past, skiping");
                    continue;
                }

                $this->localcache->addEvent($eventName, $event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'], trim($event['value']['propstat']['prop']['getetag'], '"'));
            }
        }
    }
    
    // get event etags from the server
    protected function getetags() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
            
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR" />
    </c:filter>
</c:calendar-query>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        // Array as:
        // [url1] => etag1
        // [url2] => etag2
        // ...
        $data = [];
        foreach($service->parse($output) as $event) {
            $data[$event['value']['href']] = trim($event['value']['propstat']['prop']['getetag'], '"');
            $this->log->debug("etag",[
                "etag" => $event['value']['href'], "url" => $data[$event['value']['href']]]);;
        }
        return $data;
    }
    
    // get the ctag of the calendar on the server
    // @see https://sabre.io/dav/building-a-caldav-client/
    protected function getctag() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:0",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<d:propfind xmlns:d="DAV:"  xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <cs:getctag/>
  </d:prop>
</d:propfind>');
    
        $output = curl_exec($ch);

        curl_close($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];

        $parsed_data = $service->parse($output);
        
        if(isset($parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'])) {
            return $parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'];
        }
        
        return NULL;
    }
    
    //if add is true, then add $usermail to the event, otherwise, remove it.
    function updateAttendee($url, $usermail, $add, $attendee_CN=NULL) {
        $raw = file_get_contents_safe('./data/' . $url);
        $etag = file_get_contents_safe('./data/' . $url . '.etag');
        
        $vcal = \Sabre\VObject\Reader::read($raw);
        
        if($add) {
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(strpos((string)$attendee, $usermail) >= 0) {
                        $this->log->info("Try to add a already registered attendee");
                        return;
                    }
                }
            }
            
            /*$vcal->VEVENT->add(
              'ATTENDEE',
              'mailto:' . $usermail,
              [
              'RSVP' => 'TRUE',
              'CN'   => (is_null($attendee_CN)) ? 'Bénévole' : $attendee_CN, //@TODO
              ]
              );*/
            $vcal->VEVENT->add('ATTENDEE', 'mailto:' . $usermail);
        } else {
            $already_out = true;
            
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(strpos((string)$attendee, $usermail) >= 0) {
                        $vcal->VEVENT->remove($attendee);
                        $already_out = false;
                        break;
                    }
                }
            }
            
            if($already_out) {
                $this->log->info("Try to remove an unregistered email");
                return;
            }
        }
        
        $new_etag = $this->updateEvent($url, $etag, $vcal->serialize());
        
        $this->log->debug($vcal->serialize());
        if(is_null($new_etag)) {
            $this->log->info("The server did not answer a new etag after an event update, need to update the local calendar");
            $this->updateEvents(array($this->url . '/' . $url));
        } else {
            file_put_contents_safe("./data/" . $url, $vcal->serialize());
            file_put_contents_safe("./data/" . $url . ".etag", $new_etag);
        }
    }

    protected function updateEvent($url, $etag, $data) {
        $ch = $this->init_curl_request($this->url . '/' . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");

        curl_setopt($ch, CURLOPT_HEADER  , true);
        curl_setopt($ch, CURLOPT_NOBODY  , false);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: text/calendar; charset=utf-8',
            'If-Match: "' . $etag . '"'
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($httpcode != 204) {
            $this->log->error("Bad response http code", ["code"=>$httpcode, "output"=>$output]);
            return NULL;
        }
        
        $output = rtrim($output);
        $data = explode("\n",$output);
        array_shift($data); //for ... HTTP/1.1 204 No Content
        
        foreach($data as $part) {
            if(strpos($part, "ETag") == false) {
                continue;
            }
            
            $ETag_header = explode(":",$part,2);
            if (isset($ETag_header[1])) {
                return trim($ETag_header[1], ' "');
            } else {
                return NULL;
            }
        }
        return NULL;
    }

    function getEvent($url) {
        return \Sabre\VObject\Reader::read(file_get_contents_safe('./data/' . $url));
    }
    
    protected function clearEvents() {
        throw new NotImplementedException();
    }
}
